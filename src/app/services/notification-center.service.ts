import { Injectable, OnDestroy } from '@angular/core';
import { BehaviorSubject } from 'rxjs';
import { HotelService } from './hotel.service';
import { RealtimeService } from './realtime.service';
import { DataService } from './Api/data.service';
import { AppNotification } from '../models/notification.interface';

/**
 * The header bell's data source — a general, cross-feature notification feed
 * (new OTA bookings, pending Airbnb requests, pricing changes, ...), not
 * tied to any one page. The backend already filters the list per user (by
 * hotel and by whichever permission each notification's event requires), so
 * this service just fetches, tracks unread count, and re-fetches live.
 */
@Injectable({ providedIn: 'root' })
export class NotificationCenterService implements OnDestroy {
  private notificationsSubject = new BehaviorSubject<AppNotification[]>([]);
  notifications$ = this.notificationsSubject.asObservable();

  private unreadCountSubject = new BehaviorSubject<number>(0);
  unreadCount$ = this.unreadCountSubject.asObservable();

  private realtimeOff?: () => void;
  private started = false;

  constructor(
    private hotelService: HotelService,
    private realtime: RealtimeService,
    private dataService: DataService,
  ) {
    this.dataService.user$.subscribe((user) => {
      if (user?.token) {
        this.start();
      } else {
        this.stop();
      }
    });
  }

  ngOnDestroy(): void {
    this.stop();
  }

  refresh(): void {
    this.hotelService.fetchNotifications().subscribe({
      next: (res) => {
        if (!res.success) return;
        this.notificationsSubject.next(res.data?.notifications ?? []);
        this.unreadCountSubject.next(res.data?.unread_count ?? 0);
      },
      error: () => {
        // A failed background refresh must not spam the user with error toasts.
      },
    });
  }

  /** Opening the bell "reads" everything currently in the list. */
  markSeen(): void {
    if (this.unreadCountSubject.value === 0) return;
    this.hotelService.markNotificationsSeen().subscribe({
      next: () => this.unreadCountSubject.next(0),
      error: () => {},
    });
  }

  private start(): void {
    if (this.started) return;
    this.started = true;
    this.refresh();
    this.realtimeOff = this.realtime.onScope('notifications', () => this.refresh());
  }

  private stop(): void {
    this.started = false;
    this.realtimeOff?.();
    this.realtimeOff = undefined;
    this.notificationsSubject.next([]);
    this.unreadCountSubject.next(0);
  }
}
