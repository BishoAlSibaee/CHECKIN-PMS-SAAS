import { Injectable, OnDestroy } from '@angular/core';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { DataService } from './Api/data.service';
import { AuthPermissionService } from './auth-permission.service';
import { environment } from '../../environments/environment';
import { HotelLiveHandler, HotelLivePayload, HotelLiveScope } from '../models/realtime.models';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<'reverb'>;
  }
}

@Injectable({
  providedIn: 'root',
})
export class RealtimeService implements OnDestroy {
  private echo: Echo<'reverb'> | null = null;
  private channel: ReturnType<Echo<'reverb'>['private']> | null = null;
  private scopeHandlers = new Map<HotelLiveScope, Set<HotelLiveHandler>>();
  private debounceTimer: ReturnType<typeof setTimeout> | null = null;
  private pendingBatch: HotelLivePayload[] = [];
  private lastBoardVersion = 0;
  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private fallbackProbeTimer: ReturnType<typeof setTimeout> | null = null;
  private socketConnected = false;
  private channelSubscribed = false;

  constructor(
    private dataService: DataService,
    private authPerm: AuthPermissionService,
  ) {}

  connect(): void {
    if (!environment.reverb?.key || this.echo) {
      return;
    }

    const token = this.dataService.userToken$;
    if (!token || !this.canSubscribeToLiveChannel()) {
      return;
    }

    window.Pusher = Pusher;

    this.echo = new Echo({
      broadcaster: 'reverb',
      key: environment.reverb.key,
      wsHost: environment.reverb.host,
      wsPort: environment.reverb.port,
      wssPort: environment.reverb.port,
      forceTLS: environment.reverb.scheme !== 'http',
      enabledTransports: ['ws', 'wss'],
      authorizer: this.buildChannelAuthorizer(),
    });

    this.bindConnectionLifecycle();

    this.channel = this.echo.private('hotel.operations');
    this.channel.subscribed(() => {
      this.channelSubscribed = true;
      this.stopFallbackPolling();
    });
    this.channel.error(() => {
      this.channelSubscribed = false;
      this.scheduleDisconnectedFallback();
    });
    this.channel.listen('.hotel.live.updated', (payload: HotelLivePayload) => {
      this.handleIncoming(payload);
    });

    this.scheduleDisconnectedFallback();
  }

  /** Reconnect after login/session refresh so channel auth uses a fresh Bearer token. */
  reconnect(): void {
    this.disconnect();
    this.connect();
  }

  disconnect(): void {
    this.clearFallbackProbe();
    this.stopFallbackPolling();
    this.socketConnected = false;
    this.channelSubscribed = false;

    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
      this.debounceTimer = null;
    }
    this.pendingBatch = [];

    if (this.channel) {
      this.channel.stopListening('.hotel.live.updated');
      this.channel = null;
    }

    if (this.echo) {
      this.echo.disconnect();
      this.echo = null;
    }

    this.lastBoardVersion = 0;
  }

  onScope(scope: HotelLiveScope, handler: HotelLiveHandler): () => void {
    if (!this.scopeHandlers.has(scope)) {
      this.scopeHandlers.set(scope, new Set());
    }
    this.scopeHandlers.get(scope)!.add(handler);

    return () => {
      this.scopeHandlers.get(scope)?.delete(handler);
    };
  }

  getLastBoardVersion(): number {
    return this.lastBoardVersion;
  }

  isLiveConnected(): boolean {
    return this.socketConnected && this.channelSubscribed;
  }

  ngOnDestroy(): void {
    this.disconnect();
  }

  private bindConnectionLifecycle(): void {
    const pusher = this.echo?.connector?.pusher;
    if (!pusher) {
      return;
    }

    pusher.connection.bind('connected', () => {
      this.socketConnected = true;
      this.clearFallbackProbe();
      this.stopFallbackPolling();
    });

    pusher.connection.bind('disconnected', () => {
      this.socketConnected = false;
      this.channelSubscribed = false;
      this.scheduleDisconnectedFallback();
    });

    pusher.connection.bind('failed', () => {
      this.socketConnected = false;
      this.channelSubscribed = false;
      this.scheduleDisconnectedFallback();
    });
  }

  private scheduleDisconnectedFallback(): void {
    const fallbackMs = environment.reverb?.fallbackPollMs ?? 0;
    if (!fallbackMs || this.pollTimer || this.fallbackProbeTimer) {
      return;
    }

    this.clearFallbackProbe();
    this.fallbackProbeTimer = setTimeout(() => {
      this.fallbackProbeTimer = null;
      if (!this.isLiveConnected()) {
        this.startFallbackPolling();
      }
    }, 10_000);
  }

  private clearFallbackProbe(): void {
    if (this.fallbackProbeTimer) {
      clearTimeout(this.fallbackProbeTimer);
      this.fallbackProbeTimer = null;
    }
  }

  /** board_version dedup applies only to occupancy board churn, not payments/reservations. */
  private isStaleBoardPayload(payload: HotelLivePayload): boolean {
    return (
      payload.action !== 'fallback_poll'
      && payload.action === 'board_changed'
      && payload.board_version <= this.lastBoardVersion
    );
  }

  /** Merge rapid-fire events so a trailing board_changed cannot drop reservations scope. */
  private mergeBatch(batch: HotelLivePayload[]): HotelLivePayload {
    if (batch.length === 1) {
      return batch[0];
    }

    const scopes = new Set<HotelLiveScope>();
    let boardVersion = 0;
    let action: string = 'board_changed';
    let entity: HotelLivePayload['entity'];
    let occurredAt: string | undefined;

    for (const payload of batch) {
      payload.scopes.forEach((scope) => scopes.add(scope));
      boardVersion = Math.max(boardVersion, payload.board_version);
      if (payload.action !== 'board_changed' && payload.action !== 'fallback_poll') {
        action = payload.action;
        entity = payload.entity ?? entity;
      }
      occurredAt = payload.occurred_at ?? occurredAt;
    }

    return {
      board_version: boardVersion,
      scopes: Array.from(scopes),
      action,
      entity,
      occurred_at: occurredAt,
    };
  }

  private handleIncoming(payload: HotelLivePayload): void {
    if (!payload?.scopes?.length) {
      return;
    }

    if (this.isStaleBoardPayload(payload)) {
      return;
    }

    this.pendingBatch.push(payload);
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }

    this.debounceTimer = setTimeout(() => {
      const batch = this.pendingBatch;
      this.pendingBatch = [];
      this.debounceTimer = null;
      if (batch.length === 0) {
        return;
      }

      const next = this.mergeBatch(batch);

      if (next.action === 'board_changed' && next.board_version > this.lastBoardVersion) {
        this.lastBoardVersion = next.board_version;
      }

      for (const scope of next.scopes) {
        const handlers = this.scopeHandlers.get(scope);
        if (!handlers) {
          continue;
        }
        handlers.forEach((handler) => handler(next));
      }
    }, environment.reverb?.debounceMs ?? 400);
  }

  /** Emergency refresh only when WebSocket/channel is down (disabled when fallbackPollMs = 0). */
  private startFallbackPolling(): void {
    const fallbackMs = environment.reverb?.fallbackPollMs ?? 0;
    if (!fallbackMs || this.pollTimer || this.isLiveConnected()) {
      return;
    }

    this.pollTimer = setInterval(() => {
      if (this.isLiveConnected()) {
        this.stopFallbackPolling();
        return;
      }

      for (const [scope, handlers] of this.scopeHandlers.entries()) {
        handlers.forEach((handler) =>
          handler({
            board_version: this.lastBoardVersion,
            scopes: [scope],
            action: 'fallback_poll',
          })
        );
      }
    }, fallbackMs);
  }

  private stopFallbackPolling(): void {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private canSubscribeToLiveChannel(): boolean {
    const livePermissions = [
      'view reservations',
      'create reservations',
      'update reservations',
      'view payments',
      'view revenue',
      'view earnings',
      'view reports',
      'view financial reports',
      'view accounting reports',
      'view buildings',
      'view floors',
      'view suites',
      'view rooms',
      'manage rooms',
      'view clients',
      'view users',
      'manage airbnb requests',
    ];

    return this.authPerm.hasAny(...livePermissions);
  }

  private buildChannelAuthorizer(): (channel: { name: string }) => {
    authorize: (
      socketId: string,
      callback: (error: Error | null, data: { auth: string } | null) => void,
    ) => void;
  } {
    return (channel) => ({
      authorize: (socketId, callback) => {
        void this.authorizeChannel(channel.name, socketId)
          .then((data) => callback(null, data))
          .catch((err) => callback(err instanceof Error ? err : new Error('Broadcast auth failed'), null));
      },
    });
  }

  private async authorizeChannel(channelName: string, socketId: string): Promise<{ auth: string }> {
    const body = new URLSearchParams({
      socket_id: socketId,
      channel_name: channelName,
    });

    const response = await fetch(environment.reverb.authEndpoint, {
      method: 'POST',
      headers: {
        ...this.dataService.getHeaders(),
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: body.toString(),
    });

    if (!response.ok) {
      throw new Error(`Broadcast auth failed (${response.status})`);
    }

    return response.json();
  }
}
