import { Component, OnInit, OnDestroy } from '@angular/core';
import { ChartConfiguration, ChartOptions } from 'chart.js';
import { Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { Subscription } from 'rxjs';
import { HotelService, CollectionsSummary } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { RealtimeService } from '../../services/realtime.service';

interface DashboardDeparture {
  reservation_id: number;
  guest: string;
  room: string;
  start_date: string;
  expire_date: string;
  logedin: number;
  balance_due?: number;
  is_overdue?: boolean;
}

type DashboardArrival = DashboardDeparture;

@Component({
    selector: 'app-dashboard',
    templateUrl: './dashboard.component.html',
    styleUrls: ['./dashboard.component.css'],
    standalone: false
})
export class DashboardComponent implements OnInit, OnDestroy {
  readonly movementPreviewLimit = 5;

  isLoading = true;
  errorMessage = '';
  lastUpdatedAt: Date | null = null;
  private langSub?: Subscription;
  private realtimeOff?: () => void;
  private weeklyDateLabels: string[] = [];

  occupancyRate = 0;
  monthAccrualRevenue = 0;
  accrualPeriodStart = '';
  accrualPeriodEnd = '';
  inHouseGuests = 0;
  avgDailyRate = 0;
  checkInsToday = 0;
  checkOutsToday = 0;
  overdueCheckouts = 0;
  pendingCheckouts = 0;
  monthCashNet = 0;
  totalRooms = 0;
  collectionsSummary: CollectionsSummary | null = null;

  weeklyRevenueData: ChartConfiguration<'bar'>['data'] = {
    labels: [],
    datasets: [
      {
        label: '',
        data: [0, 0, 0, 0, 0, 0, 0],
        backgroundColor: '#0f4c4c',
        borderRadius: 6,
        maxBarThickness: 36
      }
    ]
  };

  weeklyRevenueOptions: ChartOptions<'bar'> = {};

  roomStatusData: ChartConfiguration<'doughnut'>['data'] = {
    labels: [],
    datasets: [
      {
        data: [0, 0, 0, 0, 0],
        backgroundColor: ['#22c55e', '#3b82f6', '#0f4c4c', '#f97316', '#94a3b8'],
        borderWidth: 0
      }
    ]
  };

  roomStatusOptions: ChartOptions<'doughnut'> = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '65%',
    plugins: { legend: { display: false } }
  };

  roomStatusLegend = [
    { label: 'DASHBOARD.VACANT', value: 0, color: '#22c55e' },
    { label: 'DASHBOARD.RESERVED', value: 0, color: '#3b82f6' },
    { label: 'DASHBOARD.IN_HOUSE', value: 0, color: '#0f4c4c' },
    { label: 'DASHBOARD.PENDING', value: 0, color: '#f97316' },
    { label: 'DASHBOARD.MAINTENANCE', value: 0, color: '#94a3b8' }
  ];

  arrivalsToday: DashboardArrival[] = [];
  departuresToday: DashboardDeparture[] = [];
  checkingOutId: number | null = null;
  checkingInId: number | null = null;

  recentActivity: {
    guest: string;
    action: string;
    room: string;
    time: string;
    initials: string;
    avatar: string;
    reservationId?: number;
  }[] = [];

  constructor(
    private hotelService: HotelService,
    private router: Router,
    private translate: TranslateService,
    private dataService: DataService,
    public authPerm: AuthPermissionService,
    private confirm: ConfirmDialogService,
    private realtime: RealtimeService
  ) {}

  ngOnInit(): void {
    this.applyChartTranslations();
    this.langSub = this.translate.onLangChange.subscribe(() => {
      this.applyChartTranslations();
      this.applyWeeklyChartLabels();
    });
    this.loadSummary();
    this.realtimeOff = this.realtime.onScope('dashboard', () => this.loadSummary({ soft: true }));
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
    this.realtimeOff?.();
  }

  private applyChartTranslations(): void {
    const sym = this.translate.instant('COMMON.CURRENCY_SYMBOL');
    const dayKeys = ['DAY_SUN', 'DAY_MON', 'DAY_TUE', 'DAY_WED', 'DAY_THU', 'DAY_FRI', 'DAY_SAT'];
    const defaultLabels = dayKeys.map(k => this.translate.instant(`DASHBOARD.${k}`));

    this.weeklyRevenueData = {
      ...this.weeklyRevenueData,
      labels: this.weeklyRevenueData.labels?.length ? this.weeklyRevenueData.labels : defaultLabels,
      datasets: [{
        ...this.weeklyRevenueData.datasets[0],
        label: this.translate.instant('DASHBOARD.CHART_REVENUE')
      }]
    };

    this.weeklyRevenueOptions = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (context) => `${sym}${Number(context.raw).toLocaleString()}`
          }
        }
      },
      scales: {
        x: {
          grid: { color: '#e2e8f0' },
          ticks: {
            color: '#64748b',
            font: { size: 11 },
            autoSkip: false,
            maxRotation: 0,
            minRotation: 0
          }
        },
        y: {
          grid: { color: '#e2e8f0' },
          ticks: {
            color: '#64748b',
            font: { size: 12 },
            callback: (value) => `${sym}${Number(value).toLocaleString()}`
          }
        }
      }
    };

    this.roomStatusData = {
      ...this.roomStatusData,
      labels: [
        this.translate.instant('DASHBOARD.VACANT'),
        this.translate.instant('DASHBOARD.RESERVED'),
        this.translate.instant('DASHBOARD.IN_HOUSE'),
        this.translate.instant('DASHBOARD.PENDING'),
        this.translate.instant('DASHBOARD.MAINTENANCE')
      ]
    };
  }

  loadSummary(options?: { soft?: boolean }): void {
    if (!options?.soft) {
      this.isLoading = true;
    }
    this.errorMessage = '';

    this.hotelService.getDashboardSummary().subscribe({
      next: (res) => {
        if (!res?.success || !res?.data) {
          this.errorMessage = res?.message || this.translate.instant('DASHBOARD.ERR_LOAD');
          this.isLoading = false;
          return;
        }

        const d = res.data;
        const occ = d.occupancy ?? {};

        this.totalRooms = occ.total ?? 0;
        this.occupancyRate = occ.occupancy_rate ?? 0;
        this.inHouseGuests = occ.in_house ?? 0;
        this.monthAccrualRevenue = d.month_accrual_revenue ?? 0;
        this.accrualPeriodStart = d.accrual_period_start ?? '';
        this.accrualPeriodEnd = d.accrual_period_end ?? '';
        this.monthCashNet = d.month_cash_net ?? 0;
        this.checkInsToday = d.check_ins_today ?? 0;
        this.checkOutsToday = d.check_outs_today ?? 0;
        this.overdueCheckouts = d.overdue_checkouts ?? 0;
        this.pendingCheckouts = d.pending_checkouts ?? 0;
        this.arrivalsToday = d.arrivals_today ?? [];
        this.departuresToday = d.departures_today ?? [];
        this.collectionsSummary = d.collections ?? null;

        this.avgDailyRate = Math.round(Number(d.avg_daily_rate ?? 0));

        const weekly = d.weekly_accrual ?? { labels: [], values: [] };
        this.weeklyDateLabels = weekly.labels ?? [];
        this.applyWeeklyChartLabels(weekly.values ?? []);

        const vacant = occ.vacant ?? 0;
        const reserved = (occ.reserved ?? 0) + (occ.check_in_today ?? 0) + (occ.check_out_today ?? 0);
        const inHouse = occ.in_house ?? 0;
        const pending = occ.pending ?? 0;
        const maintenance = (occ.out_of_service ?? 0) + (occ.preparation ?? 0);

        this.roomStatusData = {
          ...this.roomStatusData,
          datasets: [{
            ...this.roomStatusData.datasets[0],
            data: [vacant, reserved, inHouse, pending, maintenance]
          }]
        };

        this.roomStatusLegend[0].value = vacant;
        this.roomStatusLegend[1].value = reserved;
        this.roomStatusLegend[2].value = inHouse;
        this.roomStatusLegend[3].value = pending;
        this.roomStatusLegend[4].value = maintenance;

        this.recentActivity = (d.recent_reservations ?? []).map((r: any) => ({
          guest: r.guest,
          action: this.actionKeyFromStatus(r.status),
          room: r.room,
          time: this.relativeTime(r.created_at),
          initials: this.getInitials(r.guest),
          avatar: this.getColorForId(r.id),
          reservationId: r.id
        }));

        this.lastUpdatedAt = new Date();
        this.isLoading = false;
      },
      error: () => {
        this.errorMessage = this.translate.instant('DASHBOARD.ERR_LOAD');
        this.isLoading = false;
      }
    });
  }

  get occupancyMeterWidth(): number {
    return Math.max(0, Math.min(100, Number(this.occupancyRate) || 0));
  }

  get lastUpdatedLabel(): string {
    if (!this.lastUpdatedAt) {
      return '';
    }
    return this.lastUpdatedAt.toLocaleTimeString(this.dateLocale, {
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  get visibleArrivals(): DashboardArrival[] {
    return this.arrivalsToday.slice(0, this.movementPreviewLimit);
  }

  get visibleDepartures(): DashboardDeparture[] {
    return this.departuresToday.slice(0, this.movementPreviewLimit);
  }

  get hasMoreArrivals(): boolean {
    return this.arrivalsToday.length > this.movementPreviewLimit;
  }

  get hasMoreDepartures(): boolean {
    return this.departuresToday.length > this.movementPreviewLimit;
  }

  get canCreateReservation(): boolean {
    return this.authPerm.has('create reservations');
  }

  get canViewReservations(): boolean {
    return this.authPerm.has('view reservations');
  }

  get canViewFinancials(): boolean {
    return this.authPerm.has('view revenue') && this.authPerm.has('view earnings');
  }

  get canViewReports(): boolean {
    return this.authPerm.hasAny(
      'view reports',
      'view revenue',
      'view earnings',
      'view financial reports',
      'view accounting reports'
    );
  }

  private get dateLocale(): string {
    return this.translate.currentLang?.startsWith('ar') ? 'ar' : 'en';
  }

  refresh(): void {
    this.loadSummary();
  }

  guestInitials(name: string): string {
    return this.getInitials(name);
  }

  openRoomBoard(): void {
    this.router.navigate(['/dashboard/room-board']);
  }

  openNewBooking(): void {
    this.router.navigate(['/booking/reservations/create']);
  }

  openReservations(): void {
    this.router.navigate(['/booking/reservations']);
  }

  openReports(): void {
    this.router.navigate(['/reports']);
  }

  openFinancials(): void {
    this.router.navigate(['/financials']);
  }

  openReservation(id?: number): void {
    if (id) {
      this.router.navigate(['/booking/reservations', id]);
    }
  }

  openPendingCheckouts(): void {
    this.router.navigate(['/booking/reservations'], { queryParams: { pending_checkout: '1' } });
  }

  openArrivalsList(): void {
    this.router.navigate(['/booking/reservations']);
  }

  openOccupancyKpi(): void {
    this.openRoomBoard();
  }

  openRevenueKpi(): void {
    if (this.canViewFinancials) {
      this.openFinancials();
      return;
    }
    if (this.canViewReports) {
      this.openReports();
    }
  }

  openGuestsKpi(): void {
    if (this.canViewReservations) {
      this.openReservations();
    }
  }

  openAdrKpi(): void {
    this.openRevenueKpi();
  }

  async checkInGuest(arrival: DashboardArrival, event: Event): Promise<void> {
    event.stopPropagation();

    if (!this.authPerm.has('update reservations')) {
      this.dataService.showToast(this.translate.instant('AUTH.FORBIDDEN'), 'warning');
      return;
    }

    if (Number(arrival.logedin) === 1) {
      return;
    }

    const ok = await this.confirm.confirm(
      this.translate.instant('DASHBOARD.CHECK_IN_CONFIRM', {
        guest: arrival.guest,
        room: arrival.room
      })
    );
    if (!ok) {
      return;
    }

    this.checkingInId = arrival.reservation_id;
    this.hotelService.updateReservation(arrival.reservation_id, {
      logedin: 1,
      login_time: new Date().toISOString().split('T')[0]
    }).subscribe({
      next: (res) => {
        this.checkingInId = null;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.UPDATED_OK'), 'success');
          this.loadSummary();
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('COMMON.ERROR'), 'error');
        }
      },
      error: (err) => {
        this.checkingInId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('COMMON.ERROR'), 'error');
      }
    });
  }

  async checkOutGuest(departure: DashboardDeparture, event: Event): Promise<void> {
    event.stopPropagation();

    if (!this.authPerm.has('update reservations')) {
      this.dataService.showToast(this.translate.instant('AUTH.FORBIDDEN'), 'warning');
      return;
    }

    if (Number(departure.logedin) !== 1) {
      // Guest was never checked in — there is nothing to check out.
      return;
    }

    const balance = Number(departure.balance_due ?? 0);
    if (balance > 0.005) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.CHECKOUT_REQUIRES_PAYMENT', { amount: balance.toFixed(2) }),
        'warning'
      );
      this.openReservation(departure.reservation_id);
      return;
    }

    const ok = await this.confirm.confirm(
      this.translate.instant('DASHBOARD.CHECK_OUT_CONFIRM', {
        guest: departure.guest,
        room: departure.room
      })
    );
    if (!ok) {
      return;
    }

    this.checkingOutId = departure.reservation_id;
    this.hotelService.updateReservation(departure.reservation_id, { logedin: 0 }).subscribe({
      next: (res) => {
        this.checkingOutId = null;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.UPDATED_OK'), 'success');
          this.loadSummary();
        } else if (res?.message && /outstanding balance/i.test(res.message)) {
          this.dataService.showToast(
            this.translate.instant('RESERVATIONS.CHECKOUT_REQUIRES_PAYMENT', { amount: balance.toFixed(2) }),
            'warning'
          );
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.UPDATE_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.checkingOutId = null;
        const message = err.error?.message as string | undefined;
        if (message && /outstanding balance/i.test(message)) {
          this.dataService.showToast(
            this.translate.instant('RESERVATIONS.CHECKOUT_REQUIRES_PAYMENT', { amount: balance.toFixed(2) }),
            'warning'
          );
        } else {
          this.dataService.showToast(message || this.translate.instant('RESERVATIONS.UPDATE_FAILED'), 'error');
        }
      }
    });
  }

  get canViewCollections(): boolean {
    return this.authPerm.has('view payments');
  }

  openCollections(tab: string = 'checkout_today'): void {
    this.router.navigate(['/booking/collections'], { queryParams: { tab } });
  }

  formatMoney(n: number): string {
    return Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  private actionKeyFromStatus(status: number): string {
    switch (status) {
      case 1: return 'DASHBOARD.CONFIRMED';
      case 2: return 'DASHBOARD.PENDING';
      default: return 'DASHBOARD.NEW_BOOKING';
    }
  }

  private relativeTime(iso?: string): string {
    if (!iso) return '—';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 48) return `${hrs}h`;
    return `${Math.floor(hrs / 24)}d`;
  }

  private getInitials(name: string): string {
    return (name || '?').split(' ').filter(Boolean).map(n => n[0]).join('').toUpperCase().slice(0, 2);
  }

  private getColorForId(id: number): string {
    // Darker palette so white initials meet WCAG AA on avatar chips
    const colors = ['#0f4c4c', '#047857', '#1d4ed8', '#6d28d9', '#b45309'];
    return colors[Number(id) % colors.length] || colors[0];
  }

  /** API weekly labels are ISO dates; map to localized weekday names for the chart. */
  private formatWeekdayLabel(label: string): string {
    const iso = label.slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
      return label;
    }
    const dayKeys = ['DAY_SUN', 'DAY_MON', 'DAY_TUE', 'DAY_WED', 'DAY_THU', 'DAY_FRI', 'DAY_SAT'];
    const weekday = new Date(`${iso}T12:00:00`).getDay();
    return this.translate.instant(`DASHBOARD.${dayKeys[weekday]}`);
  }

  private applyWeeklyChartLabels(values: number[] = this.weeklyRevenueData.datasets[0]?.data as number[] ?? []): void {
    const weeklyLabels = this.weeklyDateLabels.map((label) => this.formatWeekdayLabel(label));
    this.weeklyRevenueData = {
      labels: weeklyLabels.length ? weeklyLabels : this.weeklyRevenueData.labels,
      datasets: [{
        ...this.weeklyRevenueData.datasets[0],
        label: this.translate.instant('DASHBOARD.CHART_REVENUE'),
        data: values
      }]
    };
  }
}
