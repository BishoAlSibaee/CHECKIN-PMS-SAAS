import { Injectable, OnDestroy } from '@angular/core';
import { BehaviorSubject } from 'rxjs';
import { HotelService } from './hotel.service';
import { RealtimeService } from './realtime.service';
import { DataService } from './Api/data.service';
import { AuthPermissionService } from './auth-permission.service';
import { TranslateService } from '@ngx-translate/core';

/**
 * App-wide (not page-scoped) live notification for pending Airbnb "Request
 * to Book" items — the whole point of manual_review is that staff must
 * notice an unanswered request even if they aren't currently looking at the
 * Airbnb Requests page. Drives both a global toast (via DataService's
 * existing root-level toast bus) and a sidebar badge count, for any user
 * who holds the "manage airbnb requests" permission.
 */
@Injectable({ providedIn: 'root' })
export class AirbnbNotificationService implements OnDestroy {
  private pendingCountSubject = new BehaviorSubject<number>(0);
  pendingCount$ = this.pendingCountSubject.asObservable();

  private realtimeOff?: () => void;
  private started = false;

  constructor(
    private hotelService: HotelService,
    private realtime: RealtimeService,
    private dataService: DataService,
    private authPerm: AuthPermissionService,
    private translate: TranslateService,
  ) {
    this.dataService.user$.subscribe((user) => {
      if (user?.token && this.authPerm.hasAny('manage airbnb requests')) {
        this.start();
      } else {
        this.stop();
      }
    });
  }

  ngOnDestroy(): void {
    this.stop();
  }

  private start(): void {
    if (this.started) return;
    this.started = true;
    this.refresh(false);
    this.realtimeOff = this.realtime.onScope('airbnb_requests', () => this.refresh(true));
  }

  private stop(): void {
    this.started = false;
    this.realtimeOff?.();
    this.realtimeOff = undefined;
    this.pendingCountSubject.next(0);
  }

  /** @param notify Show a toast for this refresh — false for the initial silent load. */
  private refresh(notify: boolean): void {
    this.hotelService.fetchAirbnbRequests(true).subscribe({
      next: (res) => {
        if (!res.success) return;
        const count: number = res.data?.requests?.length ?? 0;
        if (notify && count > 0) {
          this.dataService.showToast(this.translate.instant('AIRBNB_REQUESTS.NEW_REQUEST_TOAST'), 'warning');
        }
        this.pendingCountSubject.next(count);
      },
      error: () => {
        // A failed background refresh must not spam the user with error toasts.
      },
    });
  }
}
