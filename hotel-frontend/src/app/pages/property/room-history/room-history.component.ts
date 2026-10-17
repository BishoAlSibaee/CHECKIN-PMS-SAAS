import { Component, OnDestroy, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { HotelService } from '../../../services/hotel.service';
import { RealtimeService } from '../../../services/realtime.service';
import { Booking } from '../../../models/booking.interface';
import { Location } from '@angular/common';

@Component({
    selector: 'app-room-history',
    templateUrl: './room-history.component.html',
    styleUrls: ['./room-history.component.css'],
    standalone: false
})
export class RoomHistoryComponent implements OnInit, OnDestroy {
  roomNumber: string = '';
  bookings: Booking[] = [];
  summaryBookings: Booking[] = [];
  isLoading = true;
  isLoadingSummary = true;
  loadError = '';
  printGeneratedAt = '';
  private realtimeOff?: () => void;

  currentPage = 1;
  pageSize = 10;
  totalItems = 0;
  totalPages = 1;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private hotelService: HotelService,
    private location: Location,
    private translate: TranslateService,
    private realtime: RealtimeService
  ) {}

  ngOnInit(): void {
    this.route.params.subscribe(params => {
      this.roomNumber = params['number'];
      if (this.roomNumber) {
        this.loadRoomHistory();
      } else {
        this.goBack();
      }
    });
    this.realtimeOff = this.realtime.onScope('reservations', () => {
      if (this.roomNumber) {
        this.loadRoomHistory();
      }
    });
  }

  ngOnDestroy(): void {
    this.realtimeOff?.();
  }

  loadRoomHistory(): void {
    this.loadError = '';
    this.currentPage = 1;
    this.loadSummaryStats();
    this.loadTablePage(1);
  }

  private loadSummaryStats(): void {
    this.isLoadingSummary = true;
    this.summaryBookings = [];
    this.loadSummaryPage(1, []);
  }

  private loadSummaryPage(page: number, accumulated: Booking[]): void {
    this.hotelService.fetchRoomHistory(this.roomNumber, page, 100).subscribe({
      next: (res) => {
        const batch = res.data ?? [];
        const merged = accumulated.concat(batch);
        const lastPage = res.lastPage ?? 1;

        if (page < lastPage) {
          this.loadSummaryPage(page + 1, merged);
          return;
        }

        this.summaryBookings = merged.sort((a, b) => (b.checkIn || '').localeCompare(a.checkIn || ''));
        this.isLoadingSummary = false;
      },
      error: () => {
        this.summaryBookings = accumulated;
        this.isLoadingSummary = false;
      }
    });
  }

  loadTablePage(page: number): void {
    this.isLoading = true;
    this.hotelService.fetchRoomHistory(this.roomNumber, page, this.pageSize).subscribe({
      next: (res) => {
        this.bookings = res.data ?? [];
        this.totalItems = res.total ?? this.bookings.length;
        this.totalPages = Math.max(1, res.lastPage ?? 1);
        this.currentPage = res.currentPage ?? page;
        this.isLoading = false;
      },
      error: () => {
        this.loadError = this.translate.instant('ROOM_HISTORY.ERR_LOAD');
        this.bookings = [];
        this.totalItems = 0;
        this.totalPages = 1;
        this.isLoading = false;
      }
    });
  }

  get totalRevenue(): number {
    return this.summaryBookings.reduce((sum, b) => sum + this.getBookingTotalPaid(b), 0);
  }

  get totalExpectedRevenue(): number {
    return this.summaryBookings.reduce((sum, b) => sum + b.amount, 0);
  }

  get totalRemaining(): number {
    return this.totalExpectedRevenue - this.totalRevenue;
  }

  get occupancyMetrics(): {
    average: number;
    bookedNights: number;
    spanDays: number;
    spanFrom: string | null;
    spanTo: string | null;
  } {
    if (!this.summaryBookings.length) {
      return {
        average: 0,
        bookedNights: 0,
        spanDays: 0,
        spanFrom: null,
        spanTo: null,
      };
    }

    let totalNights = 0;
    let minMs = Number.POSITIVE_INFINITY;
    let maxMs = 0;
    let spanFrom: string | null = null;
    let spanTo: string | null = null;

    for (const b of this.summaryBookings) {
      if (!b.checkIn || !b.checkOut) {
        continue;
      }
      const start = new Date(b.checkIn).getTime();
      const end = new Date(b.checkOut).getTime();
      if (Number.isNaN(start) || Number.isNaN(end) || end <= start) {
        continue;
      }
      const nights = Math.max(1, Math.round((end - start) / 86_400_000));
      totalNights += nights;
      if (start < minMs) {
        minMs = start;
        spanFrom = b.checkIn;
      }
      if (end > maxMs) {
        maxMs = end;
        spanTo = b.checkOut;
      }
    }

    if (totalNights === 0 || !Number.isFinite(minMs) || !spanFrom || !spanTo) {
      return {
        average: 0,
        bookedNights: 0,
        spanDays: 0,
        spanFrom: null,
        spanTo: null,
      };
    }

    const spanDays = Math.max(1, Math.round((maxMs - minMs) / 86_400_000));
    return {
      average: Math.min(100, Math.round((totalNights / spanDays) * 100)),
      bookedNights: totalNights,
      spanDays,
      spanFrom,
      spanTo,
    };
  }

  get occupancyAverage(): number {
    return this.occupancyMetrics.average;
  }

  get currentLang(): string {
    return this.translate.currentLang || 'en';
  }

  get paginationFrom(): number {
    if (this.totalItems === 0) {
      return 0;
    }
    return (this.currentPage - 1) * this.pageSize + 1;
  }

  get paginationTo(): number {
    return Math.min(this.currentPage * this.pageSize, this.totalItems);
  }

  get visiblePages(): Array<number | 'ellipsis'> {
    return this.buildVisiblePages(this.currentPage, this.totalPages);
  }

  bookingStatusLabel(status: Booking['status']): string {
    const keyMap: Record<Booking['status'], string> = {
      confirmed: 'RESERVATIONS.CONFIRMED',
      'checked-in': 'RESERVATIONS.CHECKED_IN',
      'checked-out': 'RESERVATIONS.CHECKED_OUT',
      cancelled: 'RESERVATIONS.CANCELLED',
      unconfirmed: 'RESERVATIONS.CALENDAR_LEGEND.PENDING',
      'pending-payment': 'RESERVATIONS.PENDING_PAYMENT',
    };
    return this.translate.instant(keyMap[status] ?? 'RESERVATIONS.CONFIRMED');
  }

  bookingStatusClass(status: Booking['status']): string {
    switch (status) {
      case 'checked-in':
        return 'status-checked-in';
      case 'checked-out':
        return 'status-checked-out';
      case 'cancelled':
        return 'status-cancelled';
      case 'unconfirmed':
        return 'status-unconfirmed';
      case 'pending-payment':
        return 'status-pending-payment';
      default:
        return 'status-confirmed';
    }
  }

  getBookingStatusClass(booking: Booking): string {
    if (booking.status !== 'cancelled' && this.getBookingRemaining(booking) > 0.005) {
      return 'status-balance-due';
    }
    return this.bookingStatusClass(booking.status);
  }

  getStayNights(booking: Booking): number {
    if (!booking.checkIn || !booking.checkOut) {
      return 0;
    }
    const start = new Date(booking.checkIn).getTime();
    const end = new Date(booking.checkOut).getTime();
    if (Number.isNaN(start) || Number.isNaN(end) || end <= start) {
      return 0;
    }
    return Math.max(1, Math.round((end - start) / 86_400_000));
  }

  getBookingTotalPaid(booking: Booking): number {
    return (booking.payments || []).reduce((sum, p) => sum + p.amount, 0);
  }

  getBookingRemaining(booking: Booking): number {
    return booking.amount - this.getBookingTotalPaid(booking);
  }

  setPage(page: number): void {
    if (page >= 1 && page <= this.totalPages && page !== this.currentPage) {
      this.loadTablePage(page);
    }
  }

  isNumber(value: number | 'ellipsis'): value is number {
    return value !== 'ellipsis';
  }

  printCurrentPage(): void {
    if (this.isLoading || !this.bookings.length) {
      return;
    }

    this.printGeneratedAt = new Date().toLocaleString(this.currentLang === 'ar' ? 'ar-SA' : undefined);
    document.body.classList.add('room-history-print-active');
    const cleanup = () => {
      document.body.classList.remove('room-history-print-active');
      window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);
    requestAnimationFrame(() => window.print());
  }

  viewBooking(id: string): void {
    this.router.navigate(['/booking/reservations', id]);
  }

  goBack(): void {
    this.location.back();
  }

  private buildVisiblePages(current: number, total: number): Array<number | 'ellipsis'> {
    if (total <= 1) {
      return [1];
    }
    if (total <= 7) {
      return Array.from({ length: total }, (_, i) => i + 1);
    }

    const pages: Array<number | 'ellipsis'> = [1];
    const windowStart = Math.max(2, current - 1);
    const windowEnd = Math.min(total - 1, current + 1);

    if (windowStart > 2) {
      pages.push('ellipsis');
    }

    for (let page = windowStart; page <= windowEnd; page++) {
      pages.push(page);
    }

    if (windowEnd < total - 1) {
      pages.push('ellipsis');
    }

    pages.push(total);
    return pages;
  }
}
