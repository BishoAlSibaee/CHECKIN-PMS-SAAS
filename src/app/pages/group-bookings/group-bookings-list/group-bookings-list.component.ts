import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { GroupBookingService } from '../../../services/group-booking.service';
import { GroupBooking } from '../../../models/group-booking.interface';

@Component({
    selector: 'app-group-bookings-list',
    templateUrl: './group-bookings-list.component.html',
    styleUrls: ['./group-bookings-list.component.css'],
    standalone: false
})
export class GroupBookingsListComponent implements OnInit {
  groups: GroupBooking[] = [];
  isLoading = false;
  loadError: string | null = null;
  page = 1;
  lastPage = 1;

  readonly STATUS_ACTIVE = 1;
  readonly STATUS_CANCELLED = 2;

  constructor(private groupBookingService: GroupBookingService, private router: Router) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.isLoading = true;
    this.loadError = null;
    this.groupBookingService.list(this.page).subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.groups = res.data ?? [];
          this.lastPage = res.last_page ?? 1;
        } else {
          this.loadError = res.message || 'GROUP_BOOKINGS.LOAD_ERROR';
        }
      },
      error: (err) => {
        this.isLoading = false;
        this.loadError = err.error?.message || 'GROUP_BOOKINGS.LOAD_ERROR';
      }
    });
  }

  nextPage(): void {
    if (this.page >= this.lastPage) return;
    this.page++;
    this.load();
  }

  previousPage(): void {
    if (this.page <= 1) return;
    this.page--;
    this.load();
  }

  goCreate(): void {
    this.router.navigate(['/booking/group-bookings/create']);
  }

  goDetail(id: number): void {
    this.router.navigate(['/booking/group-bookings', id]);
  }

  statusClass(status: number): string {
    if (status === this.STATUS_CANCELLED) return 'badge-cancelled';
    if (status === this.STATUS_ACTIVE) return 'badge-active';
    return 'badge-draft';
  }
}
