import { Component, OnDestroy, OnInit } from '@angular/core';
import { CalendarEvent } from 'angular-calendar';
import { addMonths, startOfDay, subMonths } from 'date-fns';
import { TranslateService } from '@ngx-translate/core';
import { Subscription } from 'rxjs';
import { HotelService } from '../../services/hotel.service';
import { Booking, BookingStatus, CalendarReservationState } from '../../models/booking.interface';
import { Router, ActivatedRoute } from '@angular/router';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { RealtimeService } from '../../services/realtime.service';
import { toCalendarEventRange } from '../../utils/reservation-calendar-dates';

@Component({
    selector: 'app-reservations',
    templateUrl: './reservations.component.html',
    styleUrls: ['./reservations.component.css'],
    standalone: false
})
export class ReservationsComponent implements OnInit, OnDestroy {
  isLoadingList = true;
  isLoadingCalendar = true;
  listError = '';
  viewDate = startOfDay(new Date());
  activeDay = startOfDay(new Date());
  search = '';
  statusFilter: 'all' | BookingStatus = 'all';
  statusFilterApi: '' | '1' | '2' | '3' = '';
  paymentFilter: 'all' | 'balance_due' | 'paid_full' = 'all';
  dateFrom = '';
  dateTo = '';
  pendingCheckoutFilter = false;
  clientIdFilter: string | null = null;
  selectedBooking: Booking | null = null;
  bookings: Booking[] = [];

  /** Expanded by default on desktop; collapsed on phones so the list is reachable. */
  calendarExpanded = true;
  showFiltersSheet = false;
  draftDateFrom = '';
  draftDateTo = '';
  draftStatusFilterApi: '' | '1' | '2' | '3' = '';
  draftPaymentFilter: 'all' | 'balance_due' | 'paid_full' = 'all';

  // Pagination
  currentPage = 1;
  pageSize = 10;
  Math = Math;

  totalPages = 1;
  totalItems = 0;

  private searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;
  private realtimeOff?: () => void;
  private langSub?: Subscription;

  constructor(
    public translate: TranslateService,
    private hotelService: HotelService,
    private router: Router,
    private route: ActivatedRoute,
    public authPerm: AuthPermissionService,
    private realtime: RealtimeService
  ) {}

  readonly stayTabs: { id: 'all' | BookingStatus; labelKey: string }[] = [
    { id: 'all', labelKey: 'RESERVATIONS.ALL_STATUS' },
    { id: 'confirmed', labelKey: 'RESERVATIONS.CONFIRMED' },
    { id: 'checked-in', labelKey: 'RESERVATIONS.CHECKED_IN' },
    { id: 'checked-out', labelKey: 'RESERVATIONS.CHECKED_OUT' },
    { id: 'cancelled', labelKey: 'RESERVATIONS.CANCELLED' },
  ];

  get canCreateReservation(): boolean {
    return this.authPerm.has('create reservations');
  }

  get canCollectPayment(): boolean {
    return this.authPerm.has('update reservations');
  }

  get isBusy(): boolean {
    return this.isLoadingList || this.isLoadingCalendar;
  }

  /** Advanced filters shown in the mobile sheet badge (excludes stay tabs / search). */
  get sheetFilterCount(): number {
    let count = 0;
    if (this.statusFilterApi) {
      count += 1;
    }
    if (this.paymentFilter !== 'all') {
      count += 1;
    }
    if (this.hasDayFilter) {
      count += 1;
    } else if (this.dateFrom && this.dateTo && !this.hasMonthRangeFilter) {
      count += 1;
    }
    return count;
  }

  get pageCheckedInCount(): number {
    return this.bookings.filter((b) => b.status === 'checked-in').length;
  }

  get pageBalanceDueCount(): number {
    return this.bookings.filter((b) => (b.balanceDue ?? 0) > 0.005).length;
  }

  get pagePaidFullCount(): number {
    return this.bookings.filter((b) => (b.balanceDue ?? 0) <= 0.005).length;
  }

  openNewBooking(): void {
    this.router.navigate(['/booking/reservations/create']);
  }

  collectPayment(id: string, event?: Event): void {
    event?.stopPropagation();
    this.router.navigate(['/booking/reservations', id], {
      queryParams: { collect: '1' },
    });
  }

  refresh(): void {
    this.loadData();
  }

  toggleCalendarExpanded(): void {
    this.calendarExpanded = !this.calendarExpanded;
  }

  openFiltersSheet(): void {
    this.draftDateFrom = this.dateFrom;
    this.draftDateTo = this.dateTo;
    this.draftStatusFilterApi = this.statusFilterApi;
    this.draftPaymentFilter = this.paymentFilter;
    this.showFiltersSheet = true;
  }

  closeFiltersSheet(): void {
    this.showFiltersSheet = false;
  }

  applyFiltersSheet(): void {
    this.dateFrom = this.draftDateFrom;
    this.dateTo = this.draftDateTo;
    this.statusFilterApi = this.draftStatusFilterApi;
    this.paymentFilter = this.draftPaymentFilter;
    if (this.dateFrom && this.dateFrom === this.dateTo) {
      const parsed = new Date(`${this.dateFrom}T00:00:00`);
      if (!Number.isNaN(parsed.getTime())) {
        this.activeDay = startOfDay(parsed);
      }
    }
    this.showFiltersSheet = false;
    this.currentPage = 1;
    this.loadData();
  }

  setTodayFilter(): void {
    const today = startOfDay(new Date());
    const d = this.formatLocalDate(today);
    this.viewDate = today;
    this.activeDay = today;
    this.dateFrom = d;
    this.dateTo = d;
    this.currentPage = 1;
    this.loadData();
  }

  guestInitials(name: string): string {
    return (name || '?')
      .split(' ')
      .filter(Boolean)
      .map((part) => part[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  }

  clearSearch(): void {
    this.onSearchChange('');
  }

  setStayFilter(filter: 'all' | BookingStatus): void {
    this.statusFilter = this.statusFilter === filter && filter !== 'all' ? 'all' : filter;
  }

  setPaymentQuickFilter(filter: 'balance_due' | 'paid_full'): void {
    this.paymentFilter = this.paymentFilter === filter ? 'all' : filter;
    this.onPaymentFilterChange();
  }

  ngOnDestroy(): void {
    if (this.searchDebounceTimer) {
      clearTimeout(this.searchDebounceTimer);
    }
    this.langSub?.unsubscribe();
    this.realtimeOff?.();
  }

  ngOnInit(): void {
    if (typeof window !== 'undefined' && window.matchMedia('(max-width: 991.98px)').matches) {
      this.calendarExpanded = false;
    }
    this.syncListDatesToViewMonth();
    this.realtimeOff = this.realtime.onScope('reservations', (payload) => {
      const delayMs = payload?.action === 'reservation_created' ? 200 : 0;
      window.setTimeout(() => this.loadData({ soft: true }), delayMs);
    });
    this.langSub = this.translate.onLangChange.subscribe(() => {
      this.loadCalendar(true);
    });
    this.route.queryParams.subscribe(params => {
      this.clientIdFilter = params['clientId'] || null;
      this.pendingCheckoutFilter = params['pending_checkout'] === '1' || params['pending_checkout'] === 'true';
      if (params['balance_due'] === '1') {
        this.paymentFilter = 'balance_due';
      }
      this.applyDateFiltersFromQuery(params);
      this.currentPage = 1;
      this.loadData();
    });
  }

  /** Apply date_from/date_to from route (e.g. deep link from revenue-summary). */
  applyDateFiltersFromQuery(params: Record<string, string | undefined>): void {
    const dateFrom = (params['date_from'] || '').trim();
    const dateTo = (params['date_to'] || '').trim();
    if (!dateFrom || !dateTo) {
      return;
    }

    this.dateFrom = dateFrom;
    this.dateTo = dateTo;

    const parsed = new Date(`${dateFrom}T00:00:00`);
    if (!Number.isNaN(parsed.getTime())) {
      this.viewDate = startOfDay(parsed);
      this.activeDay = startOfDay(parsed);
    }
  }

  applyApiFilters(): void {
    this.currentPage = 1;
    this.loadData();
  }

  loadCalendar(soft = false): void {
    const start = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth(), 1);
    const end = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 0);
    const from = this.formatLocalDate(start);
    const to = this.formatLocalDate(end);

    if (!soft) {
      this.isLoadingCalendar = true;
    }
    this.hotelService.fetchReservationCalendar(from, to).subscribe({
      next: (res) => {
        if (res?.success && res?.data?.events) {
          this.calendarEventsFromApi = res.data.events.map((e: any) => {
            const calendarState = (e.calendar_state as CalendarReservationState)
              || this.inferCalendarState(e.status, e.logedin, e.balance_due, e.paid_amount);
            const range = toCalendarEventRange(e.start, e.end);
            return {
              start: range.start,
              end: range.end,
              allDay: true,
              title: e.title,
              color: this.getCalendarColor(calendarState),
              meta: {
                id: String(e.id),
                guest: e.guest,
                room: e.room,
                status: this.mapApiStatus(e.status, e.logedin),
                calendarState
              }
            };
          });
        } else {
          this.calendarEventsFromApi = [];
        }
        this.isLoadingCalendar = false;
      },
      error: () => {
        this.calendarEventsFromApi = [];
        this.isLoadingCalendar = false;
      }
    });
  }

  calendarEventsFromApi: CalendarEvent[] = [];

  readonly calendarLegend: { state: CalendarReservationState; color: string }[] = [
    { state: 'confirmed', color: '#2563eb' },
    { state: 'checked-in', color: '#059669' },
    { state: 'partial-pay', color: '#d97706' },
    { state: 'needs-pay', color: '#ea580c' },
    { state: 'pending', color: '#7c3aed' },
    { state: 'cancelled', color: '#dc2626' }
  ];

  private mapApiStatus(status: number, logedin: number): BookingStatus {
    if (status === 3) return 'cancelled';
    if (status === 1 && logedin === 1) return 'checked-in';
    return 'confirmed';
  }

  loadData(options?: { soft?: boolean }): void {
    if (!options?.soft) {
      this.isLoadingList = true;
    }
    this.listError = '';
    this.loadCalendar(options?.soft);
    const searchTerm = this.search.trim();
    const isIdSearch = /^\d+$/.test(searchTerm);

    const params: any = {
      page: this.currentPage,
      perPage: this.pageSize,
      pending_checkout: this.pendingCheckoutFilter ? true : undefined,
      date_from: !isIdSearch && !this.pendingCheckoutFilter && this.dateFrom ? this.dateFrom : undefined,
      date_to: !isIdSearch && !this.pendingCheckoutFilter && this.dateTo ? this.dateTo : undefined,
      reservation_status: this.statusFilterApi !== '' ? Number(this.statusFilterApi) : undefined,
      search: searchTerm || undefined,
      has_balance_due: this.paymentFilter === 'balance_due' ? true : undefined,
      sort: this.paymentFilter === 'balance_due' ? 'balance_due' : undefined,
    };

    const obs = this.clientIdFilter
      ? this.hotelService.fetchBookings(this.currentPage, this.pageSize, this.clientIdFilter)
      : this.hotelService.fetchReservationsFiltered(params);

    obs.subscribe({
      next: (res) => {
        if (res?.data) {
          this.bookings = res.data;
          this.totalPages = res.lastPage ?? 1;
          this.totalItems = res.total ?? res.data.length;
        } else if (Array.isArray(res)) {
          this.bookings = res;
          this.totalPages = 1;
          this.totalItems = res.length;
        } else {
          this.bookings = [];
          this.totalPages = 1;
          this.totalItems = 0;
        }
        this.isLoadingList = false;
      },
      error: () => {
        this.bookings = [];
        this.totalPages = 1;
        this.totalItems = 0;
        this.listError = this.translate.instant('RESERVATIONS.ERR_LOAD');
        this.isLoadingList = false;
      }
    });
  }

  onDayClicked(event: { day: { date: Date } }): void {
    this.activeDay = startOfDay(event.day.date);
    const d = this.formatLocalDate(this.activeDay);
    this.dateFrom = d;
    this.dateTo = d;
    this.currentPage = 1;
    this.loadData();
  }

  clearPendingCheckoutFilter(): void {
    this.pendingCheckoutFilter = false;
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { pending_checkout: null },
      queryParamsHandling: 'merge',
    });
  }

  clearDayFilter(): void {
    this.syncListDatesToViewMonth();
    this.activeDay = startOfDay(this.viewDate);
    this.currentPage = 1;
    this.loadData();
  }

  get hasActiveFilters(): boolean {
    return !!(
      this.clientIdFilter
      || this.pendingCheckoutFilter
      || this.hasDayFilter
      || this.search.trim()
      || this.statusFilterApi
      || this.statusFilter !== 'all'
      || this.paymentFilter !== 'all'
    );
  }

  clearAllFilters(): void {
    this.search = '';
    this.statusFilter = 'all';
    this.statusFilterApi = '';
    this.paymentFilter = 'all';
    this.pendingCheckoutFilter = false;
    this.clientIdFilter = null;
    this.syncListDatesToViewMonth();
    this.activeDay = startOfDay(this.viewDate);
    this.currentPage = 1;
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: {
        clientId: null,
        pending_checkout: null,
        balance_due: null,
      },
      queryParamsHandling: 'merge',
    });
    this.loadData();
  }

  get hasDayFilter(): boolean {
    return !!this.dateFrom && this.dateFrom === this.dateTo;
  }

  get hasMonthRangeFilter(): boolean {
    if (!this.dateFrom || !this.dateTo || this.hasDayFilter) {
      return false;
    }
    const start = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth(), 1);
    const end = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 0);
    return this.dateFrom === this.formatLocalDate(start) && this.dateTo === this.formatLocalDate(end);
  }

  private syncListDatesToViewMonth(): void {
    const start = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth(), 1);
    const end = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 0);
    this.dateFrom = this.formatLocalDate(start);
    this.dateTo = this.formatLocalDate(end);
  }

  get currentLang(): string {
    return this.translate.currentLang || 'en';
  }

  get weekStartsOn(): number {
    return this.currentLang === 'ar' ? 6 : 0;
  }

  viewBookingDetail(id: string, event?: Event): void {
    event?.stopPropagation();
    this.selectedBooking = null;
    this.router.navigate(['/booking/reservations', id]);
  }

  onSearchChange(value: string): void {
    this.search = value;
    if (this.searchDebounceTimer) {
      clearTimeout(this.searchDebounceTimer);
    }
    this.searchDebounceTimer = setTimeout(() => {
      this.currentPage = 1;
      this.loadData();
    }, 400);
  }

  get filteredBookings(): Booking[] {
    let rows = this.bookings;
    if (this.statusFilter !== 'all') {
      rows = rows.filter((booking) => booking.status === this.statusFilter);
    }
    if (this.paymentFilter === 'paid_full') {
      rows = rows.filter((booking) => (booking.balanceDue ?? 0) <= 0.005);
    }
    return rows;
  }

  onPaymentFilterChange(): void {
    this.currentPage = 1;
    this.loadData();
  }

  get paginatedBookings(): Booking[] {
    // If backend is paginating, just return the current bookings
    return this.filteredBookings;
  }

  get visiblePages(): Array<number | 'ellipsis'> {
    return this.buildVisiblePages(this.currentPage, this.totalPages);
  }

  private buildVisiblePages(current: number, total: number): Array<number | 'ellipsis'> {
    if (total <= 1) {
      return [];
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

  isNumber(value: number | 'ellipsis'): value is number {
    return value !== 'ellipsis';
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

  setPage(page: number): void {
    if (page >= 1 && page <= this.totalPages) {
      this.currentPage = page;
      this.loadData();
    }
  }

  clearClientFilter(): void {
    this.router.navigate([], {
      queryParams: { clientId: null },
      queryParamsHandling: 'merge'
    });
  }

  get calendarEvents(): CalendarEvent[] {
    if (this.calendarEventsFromApi.length) {
      return this.calendarEventsFromApi;
    }
    return this.filteredBookings.map((booking) => {
      const calendarState = this.inferCalendarStateFromBooking(booking);
      const range = toCalendarEventRange(booking.checkIn, booking.checkOut);
      return {
        start: range.start,
        end: range.end,
        allDay: true,
        title: `${booking.room} • ${booking.guest}`,
        color: this.getCalendarColor(calendarState),
        meta: { ...booking, calendarState }
      };
    });
  }

  onEventClicked(event: CalendarEvent): void {
    const meta = event.meta as any;
    if (meta?.id) {
      this.viewBookingDetail(meta.id);
      return;
    }
    this.selectedBooking = event.meta as Booking;
  }

  previousMonth(): void {
    this.viewDate = subMonths(this.viewDate, 1);
    this.activeDay = startOfDay(this.viewDate);
    this.syncListDatesToViewMonth();
    this.currentPage = 1;
    this.loadData();
  }

  nextMonth(): void {
    this.viewDate = addMonths(this.viewDate, 1);
    this.activeDay = startOfDay(this.viewDate);
    this.syncListDatesToViewMonth();
    this.currentPage = 1;
    this.loadData();
  }

  getStatusBadgeClass(status: BookingStatus): string {
    if (status === 'confirmed') return 'status-confirmed';
    if (status === 'checked-in') return 'status-checked-in';
    if (status === 'checked-out') return 'status-checked-out';
    return 'status-cancelled';
  }

  getBookingStatusBadgeClass(booking: Booking): string {
    if (booking.status !== 'cancelled' && (booking.balanceDue ?? 0) > 0.005) {
      return 'status-balance-due';
    }

    return this.getStatusBadgeClass(booking.status);
  }

  private formatLocalDate(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  private getCalendarColor(state: CalendarReservationState): { primary: string; secondary: string } {
    const palette: Record<CalendarReservationState, { primary: string; secondary: string }> = {
      confirmed: { primary: '#2563eb', secondary: '#dbeafe' },
      'checked-in': { primary: '#059669', secondary: '#d1fae5' },
      'partial-pay': { primary: '#d97706', secondary: '#fef3c7' },
      'needs-pay': { primary: '#ea580c', secondary: '#ffedd5' },
      pending: { primary: '#7c3aed', secondary: '#ede9fe' },
      cancelled: { primary: '#dc2626', secondary: '#fee2e2' }
    };
    return palette[state] ?? palette.confirmed;
  }

  calendarLegendKey(state: CalendarReservationState): string {
    return `RESERVATIONS.CALENDAR_LEGEND.${state.toUpperCase().replace('-', '_')}`;
  }

  private inferCalendarState(
    status: number,
    logedin: number,
    balanceDue?: number,
    paidAmount?: number
  ): CalendarReservationState {
    const paid = Number(paidAmount ?? 0);
    const balance = Number(balanceDue ?? 0);

    if (status === 3) {
      return 'cancelled';
    }
    if (status === 2) {
      return paid > 0 ? 'partial-pay' : 'needs-pay';
    }
    if (balance > 0.01) {
      return paid > 0 ? 'partial-pay' : 'needs-pay';
    }
    if (status === 1 && logedin === 1) {
      return 'checked-in';
    }
    return 'confirmed';
  }

  private inferCalendarStateFromBooking(booking: Booking): CalendarReservationState {
    const paid = Number(booking.paidAmount ?? (booking.payments ?? []).reduce((sum, p) => sum + p.amount, 0));
    const balance = Number(booking.balanceDue ?? Math.max(0, booking.amount - paid));

    if (booking.status === 'cancelled') {
      return paid > 0 ? 'cancelled' : 'pending';
    }
    if (balance > 0.01) {
      return paid > 0 || booking.paymentStatus === 'partially-paid' ? 'partial-pay' : 'needs-pay';
    }
    if (booking.status === 'checked-in') {
      return 'checked-in';
    }
    return 'confirmed';
  }
}
