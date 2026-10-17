import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { RealtimeService } from './realtime.service';
import { DataService } from './Api/data.service';
import { AuthPermissionService } from './auth-permission.service';
import { environment } from '../../environments/environment';
import { HotelLivePayload } from '../models/realtime.models';

describe('RealtimeService', () => {
  let service: RealtimeService;
  let dataService: DataService;
  const originalReverb = { ...environment.reverb };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        RealtimeService,
        DataService,
        {
          provide: AuthPermissionService,
          useValue: { hasAny: () => true },
        },
      ],
    });
    service = TestBed.inject(RealtimeService);
    dataService = TestBed.inject(DataService);
    dataService.userToken$ = 'test-token';
    environment.reverb = {
      ...originalReverb,
      key: '',
      debounceMs: 50,
      fallbackPollMs: 0,
    };
  });

  afterEach(() => {
    service.disconnect();
    environment.reverb = originalReverb;
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('connect does nothing without reverb key', () => {
    service.connect();
    expect(service.getLastBoardVersion()).toBe(0);
  });

  it('onScope routes debounced payloads to matching handlers', fakeAsync(() => {
    const dashboardHandler = jasmine.createSpy('dashboardHandler');
    const off = service.onScope('dashboard', dashboardHandler);

    (service as unknown as { handleIncoming: (p: HotelLivePayload) => void }).handleIncoming({
      board_version: 1,
      scopes: ['dashboard', 'occupancy_board'],
      action: 'board_changed',
    });

    tick(49);
    expect(dashboardHandler).not.toHaveBeenCalled();

    tick(2);
    expect(dashboardHandler).toHaveBeenCalledTimes(1);
    expect(service.getLastBoardVersion()).toBe(1);

    off();
  }));

  it('ignores stale board_version', fakeAsync(() => {
    const handler = jasmine.createSpy('handler');
    service.onScope('dashboard', handler);

    const incoming = (service as unknown as { handleIncoming: (p: HotelLivePayload) => void }).handleIncoming.bind(service);

    incoming({ board_version: 5, scopes: ['dashboard'], action: 'board_changed' });
    tick(60);
    expect(handler).toHaveBeenCalledTimes(1);

    incoming({ board_version: 4, scopes: ['dashboard'], action: 'board_changed' });
    tick(60);
    expect(handler).toHaveBeenCalledTimes(1);
  }));

  it('disconnect clears board version', fakeAsync(() => {
    const handler = jasmine.createSpy('handler');
    service.onScope('dashboard', handler);

    (service as unknown as { handleIncoming: (p: HotelLivePayload) => void }).handleIncoming({
      board_version: 2,
      scopes: ['dashboard'],
      action: 'board_changed',
    });
    tick(60);
    expect(service.getLastBoardVersion()).toBe(2);

    service.disconnect();
    expect(service.getLastBoardVersion()).toBe(0);
  }));

  it('processes payment_recorded at same board_version after board_changed', fakeAsync(() => {
    const handler = jasmine.createSpy('handler');
    service.onScope('reservations', handler);

    const incoming = (service as unknown as { handleIncoming: (p: HotelLivePayload) => void }).handleIncoming.bind(service);

    incoming({ board_version: 5, scopes: ['reservations'], action: 'board_changed' });
    tick(60);
    expect(handler).toHaveBeenCalledTimes(1);

    incoming({
      board_version: 5,
      scopes: ['reservations'],
      action: 'payment_recorded',
      entity: { type: 'reservation', id: 12 },
    });
    tick(60);
    expect(handler).toHaveBeenCalledTimes(2);
  }));

  it('merges scopes when board_changed follows reservation_created', fakeAsync(() => {
    const reservationsHandler = jasmine.createSpy('reservationsHandler');
    const boardHandler = jasmine.createSpy('boardHandler');
    service.onScope('reservations', reservationsHandler);
    service.onScope('occupancy_board', boardHandler);

    const incoming = (service as unknown as { handleIncoming: (p: HotelLivePayload) => void }).handleIncoming.bind(service);

    incoming({
      board_version: 5,
      scopes: ['reservations', 'occupancy_board', 'dashboard', 'property'],
      action: 'reservation_created',
      entity: { type: 'reservation', id: 42 },
    });
    incoming({
      board_version: 6,
      scopes: ['occupancy_board', 'dashboard', 'property'],
      action: 'board_changed',
    });
    tick(60);

    expect(reservationsHandler).toHaveBeenCalledTimes(1);
    expect(boardHandler).toHaveBeenCalledTimes(1);
    const payload = reservationsHandler.calls.mostRecent().args[0] as HotelLivePayload;
    expect(payload.action).toBe('reservation_created');
    expect(payload.scopes).toContain('reservations');
  }));

  it('allows fallback_poll at same board_version', fakeAsync(() => {
    const handler = jasmine.createSpy('handler');
    service.onScope('dashboard', handler);

    const incoming = (service as unknown as { handleIncoming: (p: HotelLivePayload) => void }).handleIncoming.bind(service);

    incoming({ board_version: 3, scopes: ['dashboard'], action: 'board_changed' });
    tick(60);
    expect(handler).toHaveBeenCalledTimes(1);

    incoming({ board_version: 3, scopes: ['dashboard'], action: 'fallback_poll' });
    tick(60);
    expect(handler).toHaveBeenCalledTimes(2);
    expect(service.getLastBoardVersion()).toBe(3);
  }));
});
