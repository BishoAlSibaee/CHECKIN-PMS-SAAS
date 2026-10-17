import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { GroupBookingService } from '../../../services/group-booking.service';
import { DataService } from '../../../services/Api/data.service';
import { GroupBooking, GroupBookingFinancials, GroupCancelResult } from '../../../models/group-booking.interface';

@Component({
    selector: 'app-group-booking-detail',
    templateUrl: './group-booking-detail.component.html',
    styleUrls: ['./group-booking-detail.component.css'],
    standalone: false
})
export class GroupBookingDetailComponent implements OnInit {
  groupId!: number;
  group: GroupBooking | null = null;
  financials: GroupBookingFinancials | null = null;
  isLoading = false;
  loadError: string | null = null;

  isCancelling = false;
  cancelResult: GroupCancelResult | null = null;

  readonly STATUS_CANCELLED = 2;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private groupBookingService: GroupBookingService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.groupId = Number(this.route.snapshot.paramMap.get('id'));
    this.load();
  }

  load(): void {
    this.isLoading = true;
    this.loadError = null;
    this.groupBookingService.get(this.groupId).subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.group = res.data.group;
          this.financials = res.data.financials;
        } else {
          this.loadError = res.message;
        }
      },
      error: (err) => {
        this.isLoading = false;
        this.loadError = err.error?.message || 'GROUP_BOOKINGS.LOAD_ERROR';
      }
    });
  }

  goToReservation(reservationId: number): void {
    this.router.navigate(['/booking/reservations', reservationId]);
  }

  cancelGroup(): void {
    if (this.isCancelling || !this.group) return;
    this.isCancelling = true;
    this.cancelResult = null;

    this.groupBookingService.cancel(this.groupId).subscribe({
      next: (res) => {
        this.isCancelling = false;
        if (res.success) {
          this.cancelResult = res.data;
          this.dataService.showToast(
            res.data.fully_cancelled
              ? this.translate.instant('GROUP_BOOKINGS.CANCEL_FULL_SUCCESS')
              : this.translate.instant('GROUP_BOOKINGS.CANCEL_PARTIAL'),
            res.data.fully_cancelled ? 'success' : 'warning'
          );
          this.load();
        } else {
          this.dataService.showToast(res.message, 'error');
        }
      },
      error: (err) => {
        this.isCancelling = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('GROUP_BOOKINGS.CANCEL_ERROR'), 'error');
      }
    });
  }
}
