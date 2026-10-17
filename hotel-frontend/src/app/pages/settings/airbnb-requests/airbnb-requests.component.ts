import { Component, OnDestroy, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { RealtimeService } from '../../../services/realtime.service';
import { AirbnbDeclineReason, AirbnbRequest } from '../../../models/airbnb.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

@Component({
    selector: 'app-airbnb-requests',
    templateUrl: './airbnb-requests.component.html',
    styleUrls: ['./airbnb-requests.component.css'],
    standalone: false
})
export class AirbnbRequestsComponent implements OnInit, OnDestroy {
  requests: AirbnbRequest[] = [];
  isLoading = false;
  showResolved = false;

  processingId: number | null = null;

  showDeclineModal = false;
  declineTarget: AirbnbRequest | null = null;
  declineReason: AirbnbDeclineReason = 'not_comfortable';
  readonly declineReasons: AirbnbDeclineReason[] = [
    'dates_not_available',
    'not_a_good_fit',
    'waiting_for_better_reservation',
    'not_comfortable',
  ];

  private realtimeOff?: () => void;

  constructor(
    private hotelService: HotelService,
    private realtime: RealtimeService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadRequests();
    this.realtimeOff = this.realtime.onScope('airbnb_requests', () => this.loadRequests());
  }

  ngOnDestroy(): void {
    this.realtimeOff?.();
  }

  loadRequests(): void {
    this.isLoading = true;
    this.hotelService.fetchAirbnbRequests(!this.showResolved).subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.requests = res.data?.requests ?? [];
        } else {
          this.dataService.showToast(res.message || this.translate.instant('AIRBNB_REQUESTS.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('AIRBNB_REQUESTS.LOAD_ERROR'), 'error');
      }
    });
  }

  toggleShowResolved(): void {
    this.showResolved = !this.showResolved;
    this.loadRequests();
  }

  /** Airbnb's own 24h response deadline — never exposed as a field, computed from received_at. */
  deadline(request: AirbnbRequest): Date {
    return new Date(new Date(request.received_at).getTime() + 24 * 60 * 60 * 1000);
  }

  isNearDeadline(request: AirbnbRequest): boolean {
    if (request.status !== 'pending') return false;
    return this.deadline(request).getTime() - Date.now() < 2 * 60 * 60 * 1000;
  }

  reasonLabelKey(reason: string | null): string {
    return reason ? 'AIRBNB_SETTINGS.REASON_' + reason.toUpperCase() : '';
  }

  resolvedByKey(request: AirbnbRequest): string {
    return request.resolved_by ? 'AIRBNB_REQUESTS.RESOLVED_BY_' + request.resolved_by.toUpperCase() : '';
  }

  accept(request: AirbnbRequest): void {
    if (this.processingId) return;
    this.processingId = request.id;
    this.hotelService.acceptAirbnbRequest(request.id).subscribe({
      next: (res) => {
        this.processingId = null;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('AIRBNB_REQUESTS.ACCEPT_SUCCESS'), 'success');
          this.loadRequests();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('AIRBNB_REQUESTS.ACTION_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.processingId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('AIRBNB_REQUESTS.ACTION_ERROR'), 'error');
      }
    });
  }

  openDeclineModal(request: AirbnbRequest): void {
    this.declineTarget = request;
    this.declineReason = 'not_comfortable';
    this.showDeclineModal = true;
  }

  closeDeclineModal(): void {
    this.showDeclineModal = false;
    this.declineTarget = null;
  }

  confirmDecline(): void {
    if (!this.declineTarget || this.processingId) return;
    const request = this.declineTarget;
    this.processingId = request.id;
    this.hotelService.declineAirbnbRequest(request.id, this.declineReason).subscribe({
      next: (res) => {
        this.processingId = null;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('AIRBNB_REQUESTS.DECLINE_SUCCESS'), 'success');
          this.closeDeclineModal();
          this.loadRequests();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('AIRBNB_REQUESTS.ACTION_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.processingId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('AIRBNB_REQUESTS.ACTION_ERROR'), 'error');
      }
    });
  }
}
