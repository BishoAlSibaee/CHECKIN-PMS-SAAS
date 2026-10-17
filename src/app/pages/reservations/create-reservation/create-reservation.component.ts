import { ChangeDetectorRef, Component, HostListener, OnDestroy, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { forkJoin, Observable, OperatorFunction, of, Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged, switchMap, tap, catchError, map, finalize } from 'rxjs/operators';
import { HotelService } from '../../../services/hotel.service';
import { CompanyService } from '../../../services/company.service';
import { DataService } from '../../../services/Api/data.service';
import { Client } from '../../../models/client.interface';
import { Company } from '../../../models/company.interface';
import { Discount } from '../../../models/discount.interface';
import { Location } from '@angular/common';
import { RealtimeService } from '../../../services/realtime.service';
import { HotelLivePayload } from '../../../models/realtime.models';
import { LanguageService } from '../../../services/language.service';
import { formatLocalDate, addCalendarDays, addMonthsNoOverflow, monthlyExpireAfterLastDayCheckIn } from '../../../utils/reservation-calendar-dates';
import { breakdownNightlyRate, breakdownRowAmount, buildBreakdownFromDailyLines } from '../../../utils/reservation-price-breakdown';

interface SelectedRoom {
  id: number;
  number: string;
  room_type_id: number;
}

export type RoomAvailabilityFilter = 'all' | 'available' | 'unavailable';

export interface BookingRoomRow {
  id: number;
  number: string;
  room_type_id: number;
  floor_id?: number;
  suite_id?: number | null;
  suite?: { id: number; number?: string | null } | null;
  roomStatus: number;
  room_type?: { id?: number; name_ar?: string; name_en?: string };
  floor?: { id?: number; number?: number; name?: string };
  available_for_period: boolean;
  unavailable_reason?: 'booked' | 'occupied' | 'still_checked_in' | 'preparation' | 'out_of_service' | null;
  needs_cleaning_before_checkin?: boolean;
  conflict?: {
    reservation_id: number;
    start_date: string;
    expire_date: string;
    client_name?: string | null;
  } | null;
}

export interface BookingSuiteGroup {
  suiteId: number;
  suiteName: string;
  rooms: BookingRoomRow[];
}

export interface BookingFloorLayout {
  floorId: number | null;
  label: string;
  suites: BookingSuiteGroup[];
  standaloneRooms: BookingRoomRow[];
}

interface PriceCalcResult {
  total: number;
  dailyLines: { date: string; amount: number; price_source?: string }[];
  breakdown: { from: string; to: string; kind: 'monthly' | 'daily'; amount: number; nights: number; billing_source?: string }[];
  appliedPlanHint: string | null;
  error: string | null;
}

@Component({
    selector: 'app-create-reservation',
    templateUrl: './create-reservation.component.html',
    styleUrls: ['./create-reservation.component.css'],
    standalone: false
})
export class CreateReservationComponent implements OnInit, OnDestroy {
  clientId: string | null = null;
  client: Client | null = null;
  clientSearchText = '';
  isSearchingClients = false;
  isLoading = false;
  isSaving = false;
  dateRangeError = false;
  monthlyStayError = false;
  startBeforeTodayError = false;

  stayReasons: any[] = [];
  reservationSources: any[] = [];
  bookingRooms: BookingRoomRow[] = [];
  roomAvailabilitySummary = { total: 0, available: 0, unavailable: 0 };
  isLoadingRooms = false;
  roomTypes: any[] = [];
  selectedRooms: SelectedRoom[] = [];
  selectedSuiteIds: number[] = [];
  roomAvailabilityFilter: RoomAvailabilityFilter = 'all';
  roomTypeFilter: number | null = null;
  floorFilter: number | null = null;
  roomSearch = '';
  floorsForBuilding: { id: number; label: string }[] = [];
  roomPickerExpanded = true;
  wizardStep = 0;
  readonly wizardSteps = [
    { labelKey: 'RESERVATIONS.WIZARD_STEP_GUEST' },
    { labelKey: 'RESERVATIONS.WIZARD_STEP_DATES' },
    { labelKey: 'RESERVATIONS.WIZARD_STEP_OPTIONS' },
    { labelKey: 'RESERVATIONS.WIZARD_STEP_PAYMENT' },
  ] as const;

  reservation: any = {
    client_id: '',
    start_date: '',
    expire_date: '',
    reservation_type: 0,
    reservation_status: 1,
    stay_reason_id: '',
    reservation_source_id: '',
    rent_type: 0,
    price_calculation_mode: 0,
    extras: 0,
    penalties: 0,
    pay_amount: 0,
    pay_type: 0,
    logedin: 0,
    rooms: []
  };

  calculatedPrice = 0;
  private static readonly TAX_RATE = 0.15;
  isPriceCalculating = false;
  priceCalcError = '';
  appliedPlanHint: string | null = null;
  /** User-entered discount: fixed amount (SAR) or percentage (0–100). */
  discountType: 'fixed' | 'percent' = 'fixed';
  discountValue = 0;
  discountPresetId: number | null = null;
  activeDiscounts: Discount[] = [];
  guestClassificationLabel = '';
  classificationDiscountRule: Discount | null = null;
  /** Deferred corporate billing — the guest pays nothing, the company's account is billed instead. */
  billToCompany = false;
  companies: Company[] = [];
  selectedCompanyId: number | null = null;
  suggestedCompanyId: number | null = null;
  dailyPriceLines: { date: string; amount: number }[] = [];
  priceBreakdownLines: {
    from: string;
    to: string;
    kind: 'monthly' | 'daily';
    amount: number;
    nights: number;
    billing_source?: string;
  }[] = [];
  private readonly priceCalcTrigger = new Subject<void>();
  private priceCalcSub?: Subscription;
  private applyingDiscountPreset = false;
  buildings: any[] = [];
  selectedBuildingId: number | null = null;
  private pendingRoomId: string | null = null;
  private queryBuildingId: number | null = null;
  private queryRoomTypeId: number | null = null;
  private realtimeOffs: (() => void)[] = [];
  private roomRefreshTimer: ReturnType<typeof setTimeout> | null = null;
  private langSub?: Subscription;
  langEpoch = 0;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private hotelService: HotelService,
    private dataService: DataService,
    private location: Location,
    public translate: TranslateService,
    private cdr: ChangeDetectorRef,
    private realtime: RealtimeService,
    private language: LanguageService,
    private companyService: CompanyService,
  ) {}

  get manualDiscountRaw(): number {
    if (this.discountPresetId != null) {
      const preset = this.activeDiscounts.find(d => Number(d.id) === this.discountPresetId);
      return this.discountFromPreset(preset, false);
    }
    const base = Number(this.calculatedPrice) || 0;
    const value = Number(this.discountValue) || 0;
    if (this.discountType === 'percent') {
      const pct = Math.min(100, Math.max(0, value));
      return Math.round(base * pct / 100 * 100) / 100;
    }
    return Math.max(0, value);
  }

  /** @deprecated use manualDiscountAmount */
  get discountAmount(): number {
    return this.manualDiscountAmount;
  }

  get hasClassificationDiscount(): boolean {
    return this.classificationDiscountRule != null;
  }

  get classificationDiscountRaw(): number {
    return this.computeAmountFromDiscountRule(
      this.classificationDiscountRule,
      Number(this.calculatedPrice) || 0
    );
  }

  get classificationDiscountAmount(): number {
    return this.applyDiscountCap(
      this.classificationDiscountRaw,
      0,
      Number(this.calculatedPrice) || 0
    ).classification;
  }

  get manualDiscountAmount(): number {
    return this.applyDiscountCap(
      this.classificationDiscountRaw,
      this.manualDiscountRaw,
      Number(this.calculatedPrice) || 0
    ).manual;
  }

  get totalDiscountAmount(): number {
    return Math.round((this.classificationDiscountAmount + this.manualDiscountAmount) * 100) / 100;
  }

  get isDiscountInputDisabled(): boolean {
    return this.discountPresetId != null;
  }

  get subtotalAmount(): number {
    const base = Number(this.calculatedPrice) || 0;
    const extras = Number(this.reservation.extras) || 0;
    const penalties = Number(this.reservation.penalties) || 0;
    return Math.max(0, base - this.totalDiscountAmount + extras + penalties);
  }

  get taxAmount(): number {
    return Math.round(this.subtotalAmount * CreateReservationComponent.TAX_RATE * 100) / 100;
  }

  get grandTotal(): number {
    return Math.round((this.subtotalAmount + this.taxAmount) * 100) / 100;
  }

  get maxPayAmount(): number {
    return this.grandTotal;
  }

  get displayTotal(): number {
    return this.grandTotal;
  }

  get currentLang(): string {
    return this.translate.currentLang || 'en';
  }

  get isRtl(): boolean {
    return this.currentLang === 'ar';
  }

  localizedName(item: { name_ar?: string; name_en?: string; name?: string } | null | undefined): string {
    if (!item) return '';
    if (this.isRtl) {
      return item.name_ar || item.name_en || item.name || '';
    }
    return item.name_en || item.name_ar || item.name || '';
  }

  floorLabel(floorId: number | null): string {
    if (floorId == null) {
      return this.translate.instant('RESERVATIONS.NO_FLOOR');
    }
    const found = this.floorsForBuilding.find(f => f.id === floorId);
    return found?.label ?? `${this.translate.instant('PROPERTY.FLOOR')} ${floorId}`;
  }

  roomCardClass(room: BookingRoomRow): string {
    if (this.isRoomSelected(room.id)) {
      return 'room-card-selected';
    }
    if (room.available_for_period) {
      return this.roomNeedsCleaning(room) ? 'room-card-needs-cleaning' : 'room-card-available';
    }
    switch (room.unavailable_reason) {
      case 'booked':
        return 'room-card-booked';
      case 'preparation':
        return 'room-card-preparation';
      case 'out_of_service':
        return 'room-card-out-of-service';
      case 'occupied':
      case 'still_checked_in':
      default:
        return 'room-card-unavailable';
    }
  }

  roomStatusPillClass(room: BookingRoomRow): string {
    if (this.isRoomSelected(room.id)) {
      return 'room-status-pill--selected';
    }
    if (room.available_for_period) {
      return this.roomNeedsCleaning(room) ? 'room-status-pill--needs-cleaning' : 'room-status-pill--available';
    }
    switch (room.unavailable_reason) {
      case 'booked':
        return 'room-status-pill--booked';
      case 'preparation':
      case 'occupied':
      case 'still_checked_in':
        return 'room-status-pill--blocked';
      case 'out_of_service':
        return 'room-status-pill--out-of-service';
      default:
        return 'room-status-pill--unavailable';
    }
  }

  roomStatusLabel(room: BookingRoomRow): string {
    if (this.isRoomSelected(room.id)) {
      return 'RESERVATIONS.ROOM_SELECTED';
    }
    if (room.available_for_period) {
      return this.roomNeedsCleaning(room)
        ? 'RESERVATIONS.ROOM_BOOKABLE_NEEDS_CLEANING'
        : 'RESERVATIONS.ROOM_AVAILABLE_FOR_DATES';
    }
    switch (room.unavailable_reason) {
      case 'booked':
        return 'RESERVATIONS.ROOM_BOOKED';
      case 'preparation':
        return 'PROPERTY.STATUS.PREPARATION';
      case 'out_of_service':
        return 'PROPERTY.STATUS.OUT_OF_SERVICE';
      case 'still_checked_in':
        return 'RESERVATIONS.ROOM_STILL_CHECKED_IN';
      case 'occupied':
        return 'PROPERTY.STATUS.OCCUPIED';
      default:
        return 'RESERVATIONS.ROOM_NOT_AVAILABLE';
    }
  }

  roomNeedsCleaning(room: BookingRoomRow): boolean {
    return !!(room.needs_cleaning_before_checkin || room.roomStatus === 3);
  }

  get selectedRoomsNeedCleaning(): boolean {
    return this.selectedRooms.some((selected) => {
      const row = this.bookingRooms.find((r) => r.id === selected.id);
      return !!row && this.roomNeedsCleaning(row);
    });
  }

  isSelectedRoomUnavailable(roomId: number): boolean {
    if (!this.datesValid || this.isLoadingRooms) {
      return false;
    }
    const row = this.bookingRooms.find((r) => r.id === roomId);
    return !row || !row.available_for_period;
  }

  get selectedRoomsBlockedByAvailability(): boolean {
    if (!this.selectedRooms.length || !this.datesValid || this.isLoadingRooms) {
      return false;
    }
    return this.selectedRooms.some((room) => this.isSelectedRoomUnavailable(room.id));
  }

  get unavailableSelectedRoomsHint(): string {
    const blocked = this.selectedRooms.filter((room) => this.isSelectedRoomUnavailable(room.id));
    if (!blocked.length) {
      return '';
    }
    const roomLabels = blocked.map((room) => `#${room.number}`).join(', ');
    const firstRow = this.bookingRooms.find((r) => r.id === blocked[0].id);
    const conflict = firstRow?.conflict;
    if (firstRow?.unavailable_reason === 'still_checked_in' || firstRow?.unavailable_reason === 'occupied') {
      return this.translate.instant('RESERVATIONS.SELECTED_ROOMS_STILL_CHECKED_IN', {
        rooms: roomLabels,
        reservationId: conflict?.reservation_id ?? '—',
        guest: conflict?.client_name || '—',
      });
    }
    if (conflict?.start_date && conflict?.expire_date) {
      return this.translate.instant('RESERVATIONS.SELECTED_ROOMS_UNAVAILABLE_CONFLICT', {
        rooms: roomLabels,
        from: conflict.start_date,
        to: conflict.expire_date
      });
    }
    return this.translate.instant('RESERVATIONS.SELECTED_ROOMS_UNAVAILABLE_HINT', {
      rooms: roomLabels
    });
  }

  breakdownRowDisplayAmount(row: { amount: number; from: string; to: string }): number {
    return breakdownRowAmount(row, this.dailyPriceLines);
  }

  formatBreakdownLabel(row: {
    from: string;
    to: string;
    kind: 'monthly' | 'daily';
    amount: number;
    nights: number;
    billing_source?: string;
  }): string {
    const from = this.formatDailyDate(row.from);
    const to = this.formatDailyDate(row.to);
    const kindLabel = this.breakdownKindLabel(row);
    const nightlyRate = breakdownNightlyRate(row, this.dailyPriceLines);
    const rateLabel = this.translate.instant('RESERVATIONS.BREAKDOWN_NIGHTLY_RATE', {
      rate: nightlyRate,
      currency: this.translate.instant('COMMON.CURRENCY_SYMBOL')
    });

    if (row.from === row.to) {
      return `${from} (${kindLabel}) ${rateLabel}`;
    }
    const period = this.translate.instant('RESERVATIONS.BREAKDOWN_PERIOD', { from, to, kind: kindLabel });
    return `${period} ${rateLabel}`;
  }

  private breakdownKindLabel(row: { kind: 'monthly' | 'daily'; billing_source?: string }): string {
    if (!this.isMonthlyRent || row.kind === 'daily') {
      const src = row.billing_source ?? '';
      if (src === 'plan') {
        return this.translate.instant('RESERVATIONS.BREAKDOWN_DAILY_PLAN');
      }
      if (src === 'min' || src === 'max') {
        return this.translate.instant('RESERVATIONS.BREAKDOWN_DAILY_ROOM');
      }
      return this.translate.instant('RESERVATIONS.BREAKDOWN_DAILY');
    }
    const src = row.billing_source ?? '';
    if (src === 'plan') {
      return this.translate.instant('RESERVATIONS.BREAKDOWN_MONTHLY_PLAN');
    }
    if (src === 'min' || src === 'max' || src === 'monthly') {
      return this.translate.instant('RESERVATIONS.BREAKDOWN_MONTHLY_ROOM');
    }
    return this.translate.instant('RESERVATIONS.BREAKDOWN_MONTHLY');
  }

  formatDailyDate(value: string): string {
    if (!value) return '';
    const day = value.length >= 10 ? value.slice(0, 10) : value;
    return new Date(day + 'T12:00:00').toLocaleDateString(
      this.isRtl ? 'ar-SA' : 'en-GB',
      { year: 'numeric', month: 'short', day: 'numeric' }
    );
  }

  ngOnInit(): void {
    this.normalizeDefaultDates();

    this.companyService.list(1).subscribe({
      next: (res) => { if (res.success) { this.companies = res.data ?? []; } },
      error: () => {},
    });

    this.route.queryParams.subscribe(params => {
      this.clientId = params['clientId'] ?? null;
      if (this.clientId) {
        this.reservation.client_id = this.clientId;
      }

      this.pendingRoomId = params['roomId'] ? String(params['roomId']) : null;
      this.queryBuildingId = params['buildingId'] ? Number(params['buildingId']) : null;
      this.queryRoomTypeId = params['roomTypeId'] ? Number(params['roomTypeId']) : null;

      if (params['startDate']) {
        const start = String(params['startDate']).slice(0, 10);
        this.reservation.start_date = start < this.today ? this.today : start;
      }
      if (params['endDate']) {
        const end = String(params['endDate']).slice(0, 10);
        if (end > this.reservation.start_date) {
          this.reservation.expire_date = end;
        }
      } else if (params['startDate']) {
        this.reservation.expire_date = this.minExpireDate;
      }
      this.normalizeDefaultDates();
    });

    if (this.clientId) {
      this.hotelService.fetchClientById(this.clientId).subscribe(c => {
        if (c) {
          this.setSelectedClient(c);
        }
      });
    }

    this.loadMetadata();

    this.priceCalcSub = this.priceCalcTrigger.pipe(
      debounceTime(200),
      tap(() => {
        this.isPriceCalculating = true;
        this.priceCalcError = '';
        this.appliedPlanHint = null;
      }),
      switchMap(() =>
        this.runPriceCalculation().pipe(
          finalize(() => {
            this.isPriceCalculating = false;
            this.cdr.markForCheck();
          })
        )
      )
    ).subscribe(result => {
      this.applyPriceResult(result);
      this.cdr.markForCheck();
    });

    this.bindRealtimeRoomRefresh();

    this.langSub = this.language.changes$.subscribe(() => {
      this.langEpoch++;
      this.refreshLocalizedLabels();
    });
  }

  @HostListener('document:visibilitychange')
  onDocumentVisibilityChange(): void {
    if (typeof document !== 'undefined' && document.visibilityState === 'visible') {
      this.scheduleRoomAvailabilityRefresh(0);
    }
  }

  private bindRealtimeRoomRefresh(): void {
    const handler = (payload?: HotelLivePayload) => {
      const delayMs = payload?.action === 'reservation_created' ? 450 : 200;
      this.scheduleRoomAvailabilityRefresh(delayMs);
    };

    this.realtimeOffs = [
      this.realtime.onScope('occupancy_board', handler),
      this.realtime.onScope('reservations', handler),
      this.realtime.onScope('property', handler),
    ];
  }

  private scheduleRoomAvailabilityRefresh(delayMs = 200): void {
    if (this.roomRefreshTimer) {
      clearTimeout(this.roomRefreshTimer);
    }
    this.roomRefreshTimer = setTimeout(() => {
      this.roomRefreshTimer = null;
      if (this.selectedBuildingId && this.datesValid) {
        this.loadBookingRooms({ soft: true });
      }
      this.cdr.markForCheck();
    }, delayMs);
  }

  private refreshLocalizedLabels(): void {
    if (this.floorsForBuilding.length) {
      const bid = this.selectedBuildingId ? String(this.selectedBuildingId) : '';
      const floors = bid ? this.hotelService.getFloorsSync(bid) || [] : [];
      if (floors.length) {
        this.floorsForBuilding = floors.map((f: any) => ({
          id: Number(f.id),
          label: f.name || `${this.translate.instant('PROPERTY.FLOOR')} ${f.number ?? f.id}`,
        }));
      } else {
        this.floorsForBuilding = this.floorsForBuilding.map((f) => ({
          ...f,
          label: `${this.translate.instant('PROPERTY.FLOOR')} ${f.id}`,
        }));
      }
    }
    if (this.selectedRooms.length && this.datesValid) {
      this.calculatePrice();
    }
    this.cdr.markForCheck();
  }

  ngOnDestroy(): void {
    if (this.roomRefreshTimer) {
      clearTimeout(this.roomRefreshTimer);
      this.roomRefreshTimer = null;
    }
    this.langSub?.unsubscribe();
    this.priceCalcSub?.unsubscribe();
    this.priceCalcTrigger.complete();
    this.realtimeOffs.forEach((off) => off());
  }

  private resolveRoomTypeId(room: SelectedRoom): number {
    if (room.room_type_id > 0) {
      return room.room_type_id;
    }
    const row = this.bookingRooms.find(r => r.id === room.id);
    return Number(row?.room_type_id ?? row?.room_type?.id ?? 0);
  }

  get isMonthlyRent(): boolean {
    return Number(this.reservation.rent_type) === 1;
  }

  get today(): string {
    return formatLocalDate(new Date());
  }

  /** Check-in now is only allowed when arrival date is today. */
  get checkInTodayAllowed(): boolean {
    return !!this.reservation.start_date && this.reservation.start_date === this.today;
  }

  get minExpireDate(): string {
    return this.minExpireDateForStay;
  }

  /** Minimum check-out: next day (daily) or +1 calendar month (monthly). */
  get minExpireDateForStay(): string {
    return this.getMinExpireDateForRentType(Number(this.reservation.rent_type));
  }

  get monthlyMinExpireLabel(): string {
    return this.minExpireDateForStay;
  }

  get filteredBookingRooms(): BookingRoomRow[] {
    let list = [...this.bookingRooms];
    if (this.roomAvailabilityFilter === 'available') {
      list = list.filter(r => r.available_for_period);
    } else if (this.roomAvailabilityFilter === 'unavailable') {
      list = list.filter(r => !r.available_for_period);
    }
    if (this.roomTypeFilter != null) {
      list = list.filter(r => Number(r.room_type_id) === this.roomTypeFilter);
    }
    if (this.floorFilter != null) {
      list = list.filter(r => Number(r.floor_id) === this.floorFilter);
    }
    const q = this.roomSearch.trim().toLowerCase();
    if (q) {
      list = list.filter(r => String(r.number).toLowerCase().includes(q));
    }
    return list;
  }

  get bookingRoomsByFloor(): BookingFloorLayout[] {
    const floorGroups = new Map<number | null, BookingRoomRow[]>();
    for (const room of this.filteredBookingRooms) {
      const fid = room.floor_id ?? null;
      if (!floorGroups.has(fid)) {
        floorGroups.set(fid, []);
      }
      floorGroups.get(fid)!.push(room);
    }

    return Array.from(floorGroups.entries())
      .map(([floorId, rooms]) => {
        const suiteMap = new Map<number, BookingRoomRow[]>();
        const standaloneRooms: BookingRoomRow[] = [];

        for (const room of rooms) {
          const suiteId = room.suite_id ?? room.suite?.id ?? null;
          if (suiteId) {
            if (!suiteMap.has(suiteId)) {
              suiteMap.set(suiteId, []);
            }
            suiteMap.get(suiteId)!.push(room);
          } else {
            standaloneRooms.push(room);
          }
        }

        const suites = Array.from(suiteMap.entries())
          .map(([suiteId, suiteRooms]) => ({
            suiteId,
            suiteName: this.suiteLabel(suiteId, suiteRooms[0]?.suite?.number),
            rooms: suiteRooms.sort((a, b) => this.compareRoomNumbers(a.number, b.number))
          }))
          .sort((a, b) => a.suiteName.localeCompare(b.suiteName, undefined, { numeric: true }));

        return {
          floorId,
          label: this.floorLabel(floorId),
          suites,
          standaloneRooms: standaloneRooms.sort((a, b) => this.compareRoomNumbers(a.number, b.number))
        };
      })
      .sort((a, b) => {
        if (a.floorId == null) return 1;
        if (b.floorId == null) return -1;
        return a.floorId - b.floorId;
      });
  }

  suiteLabel(suiteId: number, suiteNumber?: string | null): string {
    const prefix = this.translate.instant('PROPERTY.SUITE');
    if (suiteNumber) {
      return `${prefix} ${suiteNumber}`;
    }
    return `${prefix} #${suiteId}`;
  }

  isSuiteSelected(suite: BookingSuiteGroup): boolean {
    return suite.rooms.length > 0 && suite.rooms.every(r => this.isRoomSelected(r.id));
  }

  isSuiteAvailable(suite: BookingSuiteGroup): boolean {
    return suite.rooms.length > 0 && suite.rooms.every(r => r.available_for_period);
  }

  suiteCardClass(suite: BookingSuiteGroup): string {
    if (this.isSuiteSelected(suite)) {
      return 'suite-picker-card--selected';
    }
    if (this.isSuiteAvailable(suite)) {
      return 'suite-picker-card--available';
    }
    return 'suite-picker-card--unavailable';
  }

  toggleSuiteSelection(suite: BookingSuiteGroup): void {
    if (this.isSuiteSelected(suite)) {
      suite.rooms.forEach(r => this.removeRoom(r.id, false));
      this.selectedSuiteIds = this.selectedSuiteIds.filter(id => id !== suite.suiteId);
      this.syncReservationRooms();
      this.calculatePrice();
      return;
    }
    if (!this.isSuiteAvailable(suite)) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.SUITE_NOT_AVAILABLE'), 'warning');
      return;
    }
    if (!this.selectedSuiteIds.includes(suite.suiteId)) {
      this.selectedSuiteIds.push(suite.suiteId);
    }
    suite.rooms.forEach(r => this.addRoomToSelection(r, false));
    this.syncReservationRooms();
    this.calculatePrice();
  }

  onSuiteRoomChipClick(event: Event, room: BookingRoomRow): void {
    event.stopPropagation();
    this.toggleRoomSelection(room);
  }

  private compareRoomNumbers(a: string, b: string): number {
    return String(a).localeCompare(String(b), undefined, { numeric: true });
  }

  isRoomSelected(roomId: number): boolean {
    return this.selectedRooms.some(r => r.id === roomId);
  }

  toggleRoomPicker(): void {
    this.roomPickerExpanded = !this.roomPickerExpanded;
  }

  get selectedRoomsLabel(): string {
    const n = this.selectedRooms.length;
    if (n === 0) {
      return '';
    }
    return this.translate.instant('RESERVATIONS.SELECTED_ROOMS_COUNT', { count: n });
  }

  get datesValid(): boolean {
    return this.ensureValidDates();
  }

  get canSubmit(): boolean {
    return !!this.reservation.client_id
      && this.selectedRooms.length > 0
      && this.ensureValidDates()
      && !this.selectedRoomsBlockedByAvailability
      && !this.payAmountError
      && !this.isSaving;
  }

  get payAmountError(): boolean {
    const amount = Number(this.reservation.pay_amount) || 0;
    return amount > 0 && amount > this.grandTotal + 0.005;
  }

  setFullPayAmount(): void {
    if (this.grandTotal <= 0) {
      return;
    }
    this.reservation.pay_amount = this.roundMoney(this.grandTotal);
  }

  onPayAmountChange(): void {
    // noop — keeps ngModelChange hook for future side effects
  }

  validatePayAmount(): boolean {
    return !this.payAmountError;
  }

  private roundMoney(value: number): number {
    return Math.round(value * 100) / 100;
  }

  searchClients: OperatorFunction<string, readonly Client[]> = (text$: Observable<string>) =>
    text$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      tap(() => (this.isSearchingClients = true)),
      switchMap(term => {
        const q = (term || '').trim();
        if (q.length < 2) {
          return of([]);
        }
        return this.hotelService.fetchClients(q, '20', false).pipe(catchError(() => of([])));
      }),
      tap(() => (this.isSearchingClients = false))
    );

  clientFormatter = (client: Client | string): string => {
    if (!client || typeof client !== 'object') {
      return typeof client === 'string' ? client : '';
    }
    return this.formatClientLabel(client);
  };

  clientResultFormatter = (client: Client): string => this.formatClientLabel(client);

  private formatClientLabel(client: Client): string {
    const name = `${client.first_name || ''} ${client.last_name || ''}`.trim();
    const contact = client.mobile || client.email || client.IdNumber || '';
    const idPart = client.id ? `#${client.id}` : '';
    return [name, contact, idPart].filter(Boolean).join(' — ');
  }

  onClientSelect(event: { item: Client }): void {
    this.setSelectedClient(event.item);
  }

  clearSelectedClient(): void {
    this.client = null;
    this.reservation.client_id = '';
    this.clientSearchText = '';
    this.guestClassificationLabel = '';
    this.classificationDiscountRule = null;
    this.suggestedCompanyId = null;
  }

  private setSelectedClient(client: Client): void {
    this.client = client;
    this.reservation.client_id = String(client.id);
    this.clientSearchText = this.formatClientLabel(client);
    this.loadClientClassificationDiscount(client.id);

    // Suggested, never forced — staff can still book this guest personally
    // even if their employer has a corporate account on file.
    this.suggestedCompanyId = client.company_id ?? null;
    if (this.suggestedCompanyId && !this.billToCompany) {
      this.billToCompany = true;
      this.selectedCompanyId = this.suggestedCompanyId;
    }
  }

  private loadClientClassificationDiscount(clientId: number | string): void {
    this.guestClassificationLabel = '';
    this.classificationDiscountRule = null;

    this.hotelService.fetchClientClassification(clientId).subscribe({
      next: (data) => {
        const classification = data?.classification;
        if (!classification) {
          return;
        }

        const rule = this.resolveClassificationDiscountRule(classification);
        if (!rule) {
          return;
        }

        this.guestClassificationLabel = this.localizedName(classification);
        this.classificationDiscountRule = rule;
        this.cdr.markForCheck();
      },
    });
  }

  private resolveClassificationDiscountRule(classification: {
    discount_id?: number | null;
    discount?: Discount | null;
  }): Discount | null {
    const nested = classification.discount;
    if (nested && this.isDiscountActive(nested)) {
      return nested;
    }

    const discountId = classification.discount_id ?? nested?.id;
    if (discountId == null) {
      return null;
    }

    const preset = this.activeDiscounts.find(d => Number(d.id) === Number(discountId));
    if (preset && this.isDiscountActive(preset)) {
      return preset;
    }

    if (nested && this.isDiscountActive(nested)) {
      return nested;
    }

    return null;
  }

  private isDiscountActive(discount: Discount): boolean {
    const active = discount.is_active;
    return active === true || active === 1;
  }

  classificationDiscountRuleLabel(): string {
    if (!this.classificationDiscountRule) {
      return '';
    }
    return this.discountPresetLabel(this.classificationDiscountRule);
  }

  private applyDiscountCap(
    classificationAmount: number,
    manualAmount: number,
    basePrice: number
  ): { classification: number; manual: number } {
    const classification = Math.min(basePrice, Math.max(0, classificationAmount));
    const remaining = Math.max(0, basePrice - classification);
    const manual = Math.min(remaining, Math.max(0, manualAmount));

    return {
      classification: Math.round(classification * 100) / 100,
      manual: Math.round(manual * 100) / 100,
    };
  }

  private computeAmountFromDiscountRule(rule: Discount | null, basePrice: number): number {
    if (!rule || basePrice <= 0) {
      return 0;
    }

    let amount = 0;
    if (rule.is_percentage && rule.percent != null) {
      amount += basePrice * Number(rule.percent) / 100;
    }
    if (rule.is_fixed && rule.fixed_amount != null) {
      amount += Number(rule.fixed_amount);
    }

    return Math.min(basePrice, Math.round(amount * 100) / 100);
  }

  loadMetadata(): void {
    this.isLoading = true;

    this.hotelService.getStayReasons().subscribe(res => {
      this.stayReasons = res.data?.data || res.data || res;
      if (this.stayReasons.length > 0) {
        this.reservation.stay_reason_id = this.stayReasons[0].id;
      }
    });

    this.hotelService.getReservationSources().subscribe(res => {
      this.reservationSources = res.data?.data || res.data || res;
      if (this.reservationSources.length > 0) {
        this.reservation.reservation_source_id = this.reservationSources[0].id;
      }
    });

    this.hotelService.fetchRoomTypes().subscribe(types => {
      this.roomTypes = types;
      if (this.selectedBuildingId) {
        this.loadBookingRooms();
      }
    });

    this.hotelService.fetchDiscounts().subscribe(() => {
      this.activeDiscounts = (this.hotelService.getDiscountsSync() || []).filter(
        (d: Discount) => d.is_active === true || d.is_active === 1
      );
      if (this.client?.id) {
        this.loadClientClassificationDiscount(this.client.id);
      }
    });

    this.hotelService.fetchBuildings().subscribe(() => {
      this.buildings = this.hotelService.getBuildingsSync();
      if (this.queryBuildingId) {
        this.selectedBuildingId = this.queryBuildingId;
      } else if (this.buildings.length > 0) {
        this.selectedBuildingId = Number(this.buildings[0].id);
      }

      if (this.selectedBuildingId) {
        this.loadFloorsForBuilding(this.selectedBuildingId);
        this.loadBookingRooms();
      }
    });

    this.isLoading = false;
  }

  onStartDateChange(): void {
    this.clampStartDateToToday();
    this.adjustExpireDateForRentType();
    this.syncCheckInNowForStartDate();
    if (this.reservation.logedin === 1) {
      this.reservation.login_time = this.reservation.start_date || this.today;
    }
    this.onDatesChange();
  }

  onExpireDateChange(): void {
    if (!this.ensureValidDates()) {
      this.reservation.expire_date = this.minExpireDateForStay;
    }
    this.onDatesChange();
  }

  onRentTypeChange(rentType: number | string): void {
    this.reservation.rent_type = Number(rentType);
    this.adjustExpireDateForRentType(this.reservation.rent_type);
    this.ensureValidDates();
    this.onDatesChange();
  }

  onRentTypeSwitch(event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    this.onRentTypeChange(checked ? 1 : 0);
  }

  onCheckInNowSwitch(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (!this.checkInTodayAllowed) {
      input.checked = false;
      this.reservation.logedin = 0;
      return;
    }
    const checked = input.checked;
    if (checked && this.selectedRoomsNeedCleaning) {
      input.checked = false;
      this.reservation.logedin = 0;
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.CHECK_IN_NEEDS_CLEANING'),
        'warning'
      );
      return;
    }
    this.reservation.logedin = checked ? 1 : 0;
    if (checked) {
      this.reservation.login_time = this.reservation.start_date || this.today;
    }
  }

  private syncCheckInNowForStartDate(): void {
    if (!this.checkInTodayAllowed && Number(this.reservation.logedin) === 1) {
      this.reservation.logedin = 0;
    }
  }

  setPriceCalculationMode(mode: number): void {
    if (this.reservation.price_calculation_mode === mode) {
      return;
    }
    this.reservation.price_calculation_mode = mode;
    this.calculatePrice();
  }

  private adjustExpireDateForRentType(rentType?: number): void {
    const min = this.getMinExpireDateForRentType(rentType ?? Number(this.reservation.rent_type));
    if (!min) {
      return;
    }
    if (!this.reservation.expire_date || this.reservation.expire_date < min) {
      this.reservation.expire_date = min;
    }
  }

  private getMinExpireDateForRentType(rentType: number): string {
    if (!this.reservation.start_date) {
      return '';
    }
    if (rentType === 1) {
      return monthlyExpireAfterLastDayCheckIn(this.reservation.start_date);
    }
    return addCalendarDays(this.reservation.start_date, 1);
  }

  /** Ensure check-in/out defaults use local calendar days and satisfy min stay rules. */
  private normalizeDefaultDates(): void {
    if (!this.reservation.start_date) {
      this.reservation.start_date = this.today;
    }
    this.clampStartDateToToday();
    this.adjustExpireDateForRentType();
    const minExpire = this.getMinExpireDateForRentType(Number(this.reservation.rent_type));
    if (!this.reservation.expire_date || this.reservation.expire_date <= this.reservation.start_date) {
      this.reservation.expire_date = minExpire;
    }
    if (this.isMonthlyRent && minExpire && this.reservation.expire_date < minExpire) {
      this.reservation.expire_date = minExpire;
    }
    this.ensureValidDates();
    this.syncCheckInNowForStartDate();
  }

  onDatesChange(): void {
    this.ensureValidDates();
    this.loadBookingRooms();
    this.calculatePrice();
  }

  onBuildingChange(): void {
    this.floorFilter = null;
    if (this.selectedBuildingId) {
      this.loadFloorsForBuilding(this.selectedBuildingId);
    } else {
      this.floorsForBuilding = [];
    }
    this.onDatesChange();
  }

  onRoomFiltersChange(): void {
    // client-side only
  }

  private loadFloorsForBuilding(buildingId: number): void {
    const key = String(buildingId);
    this.hotelService.fetchFloors(key).subscribe(() => {
      const floors = this.hotelService.getFloorsSync(key) || [];
      this.floorsForBuilding = floors.map((f: any) => ({
        id: Number(f.id),
        label: f.name || `${this.translate.instant('PROPERTY.FLOOR')} ${f.number ?? f.id}`
      }));
    });
  }

  ensureValidDates(): boolean {
    if (!this.reservation.start_date || !this.reservation.expire_date) {
      this.dateRangeError = false;
      this.monthlyStayError = false;
      this.startBeforeTodayError = false;
      return false;
    }
    this.startBeforeTodayError = this.reservation.start_date < this.today;
    this.dateRangeError = this.reservation.expire_date <= this.reservation.start_date;
    const minMonthlyEnd = this.getMinExpireDateForRentType(1);
    this.monthlyStayError = this.isMonthlyRent
      && !!minMonthlyEnd
      && this.reservation.expire_date < minMonthlyEnd;
    return !this.dateRangeError && !this.monthlyStayError && !this.startBeforeTodayError;
  }

  private clampStartDateToToday(): void {
    if (this.reservation.start_date && this.reservation.start_date < this.today) {
      this.reservation.start_date = this.today;
    }
  }

  loadBookingRooms(options?: { soft?: boolean }): void {
    if (!this.reservation.start_date || !this.reservation.expire_date || !this.selectedBuildingId) {
      this.bookingRooms = [];
      this.roomAvailabilitySummary = { total: 0, available: 0, unavailable: 0 };
      return;
    }
    if (!this.ensureValidDates()) {
      this.bookingRooms = [];
      return;
    }

    if (!options?.soft) {
      this.isLoadingRooms = true;
    }
    this.hotelService.getBookingRoomAvailability({
      building_id: this.selectedBuildingId,
      start_date: this.reservation.start_date,
      expire_date: this.reservation.expire_date
    }).subscribe({
      next: (res) => {
        const data = res.data ?? res;
        this.bookingRooms = Array.isArray(data?.rooms) ? data.rooms : [];
        this.roomAvailabilitySummary = data?.summary ?? {
          total: this.bookingRooms.length,
          available: this.bookingRooms.filter(r => r.available_for_period).length,
          unavailable: this.bookingRooms.filter(r => !r.available_for_period).length
        };
        this.isLoadingRooms = false;
        this.applyPendingRoomSelection();
      },
      error: () => {
        this.bookingRooms = [];
        this.isLoadingRooms = false;
        this.dataService.showToast(this.translate.instant('RESERVATIONS.ROOMS_LOAD_ERROR'), 'error');
      }
    });
  }

  private applyPendingRoomSelection(): void {
    if (!this.pendingRoomId) {
      return;
    }

    const room = this.bookingRooms.find(r => String(r.id) === this.pendingRoomId);
    if (!room) {
      return;
    }

    if (!this.isRoomSelected(room.id)) {
      this.addRoomToSelection(room, false);
    }

    this.roomPickerExpanded = true;
    this.pendingRoomId = null;
  }

  toggleRoomSelection(room: BookingRoomRow): void {
    if (this.isRoomSelected(room.id)) {
      this.removeRoom(room.id);
      return;
    }
    if (!room.available_for_period) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.ROOM_NOT_AVAILABLE_FOR_DATES'), 'warning');
      return;
    }
    const suiteId = room.suite_id ?? room.suite?.id ?? null;
    if (suiteId) {
      this.selectedSuiteIds = this.selectedSuiteIds.filter(id => id !== suiteId);
    }
    this.addRoomToSelection(room);
  }

  addRoomToSelection(room: any, showDuplicateWarning = true): void {
    const id = Number(room.id);
    if (this.selectedRooms.some(r => r.id === id)) {
      if (showDuplicateWarning) {
        this.dataService.showToast(this.translate.instant('RESERVATIONS.DUPLICATE_ROOM'), 'warning');
      }
      return;
    }

    this.selectedRooms.push({
      id,
      number: String(room.number ?? room.id),
      room_type_id: Number(room.room_type_id ?? room.room_type?.id ?? this.queryRoomTypeId ?? 0)
    });

    const suiteId = room.suite_id ?? room.suite?.id ?? null;
    if (suiteId) {
      const suiteRooms = this.bookingRooms.filter(r => (r.suite_id ?? r.suite?.id) === suiteId);
      if (suiteRooms.length && suiteRooms.every(r => this.isRoomSelected(r.id))) {
        if (!this.selectedSuiteIds.includes(suiteId)) {
          this.selectedSuiteIds.push(suiteId);
        }
      }
    }

    this.syncReservationRooms();
    this.calculatePrice();
  }

  removeRoom(roomId: number, recalculate = true): void {
    const room = this.bookingRooms.find(r => r.id === roomId);
    const suiteId = room?.suite_id ?? room?.suite?.id ?? null;
    if (suiteId) {
      this.selectedSuiteIds = this.selectedSuiteIds.filter(id => id !== suiteId);
    }
    this.selectedRooms = this.selectedRooms.filter(r => r.id !== roomId);
    this.syncReservationRooms();
    if (recalculate) {
      this.calculatePrice();
    }
  }

  private syncReservationRooms(): void {
    const coveredBySuite = new Set<number>();
    const entries: { room_id?: number; suite_id?: number }[] = [];

    for (const suiteId of this.selectedSuiteIds) {
      entries.push({ suite_id: suiteId });
      this.bookingRooms
        .filter(r => (r.suite_id ?? r.suite?.id) === suiteId)
        .forEach(r => coveredBySuite.add(r.id));
    }

    for (const room of this.selectedRooms) {
      if (!coveredBySuite.has(room.id)) {
        entries.push({ room_id: room.id });
      }
    }

    this.reservation.rooms = entries;
  }

  calculatePrice(): void {
    this.priceCalcTrigger.next();
  }

  private runPriceCalculation(): Observable<PriceCalcResult> {
    this.ensureValidDates();

    if (!this.selectedRooms.length || !this.reservation.start_date || !this.reservation.expire_date) {
      return of({ total: 0, dailyLines: [], breakdown: [], appliedPlanHint: null, error: null });
    }

    if (this.dateRangeError || this.monthlyStayError) {
      return of({ total: 0, dailyLines: [], breakdown: [], appliedPlanHint: null, error: null });
    }

    const rentType = Number(this.reservation.rent_type);
    const roomsWithType = this.selectedRooms
      .map(room => ({ room, roomTypeId: this.resolveRoomTypeId(room) }))
      .filter(entry => entry.roomTypeId > 0);

    if (!roomsWithType.length) {
      return of({
        total: 0,
        dailyLines: [],
        breakdown: [],
        appliedPlanHint: null,
        error: this.translate.instant('RESERVATIONS.PRICE_CALC_ERROR')
      });
    }

    const typeCounts = new Map<number, number>();
    for (const { roomTypeId } of roomsWithType) {
      typeCounts.set(roomTypeId, (typeCounts.get(roomTypeId) ?? 0) + 1);
    }

    const requests = Array.from(typeCounts.entries()).map(([roomTypeId, qty]) =>
      this.hotelService.getRoomPriceCalculation({
        roomTypeId,
        startDate: this.reservation.start_date,
        endDate: this.reservation.expire_date,
        typeReservation: rentType,
        price_calculation_mode: Number(this.reservation.price_calculation_mode)
      }).pipe(
        map(res => ({ ...res, qty })),
        catchError(err => of({
          success: false,
          qty,
          message: err?.error?.message || err?.message || 'Price calculation failed'
        }))
      )
    );

    return forkJoin(requests).pipe(
      map(results => {
        let total = 0;
        let hadSuccess = false;
        let lastError = '';
        let appliedPlanMeta: Record<string, unknown> | null = null;
        const lineMap = new Map<string, { amount: number; price_source?: string }>();

        results.forEach(res => {
          const qty = Number((res as { qty?: number }).qty ?? 1);
          if (!res?.success) {
            lastError = res?.message || lastError;
            return;
          }
          hadSuccess = true;
          if (!appliedPlanMeta && res.data?.applied_plan) {
            appliedPlanMeta = res.data.applied_plan;
          }
          total += Number(res.data?.totalPrice ?? 0) * qty;

          const mergeLine = (date: string, amount: number, priceSource?: string) => {
            const add = Number(amount) * qty;
            const existing = lineMap.get(date);
            if (existing) {
              existing.amount += add;
              if (!existing.price_source && priceSource) {
                existing.price_source = priceSource;
              }
            } else {
              lineMap.set(date, { amount: add, price_source: priceSource });
            }
          };

          const apiDailyLines: { date: string; amount: number; price_source?: string }[] = res.data?.daily_lines ?? [];
          if (apiDailyLines.length) {
            apiDailyLines.forEach((line) => mergeLine(line.date, line.amount, line.price_source));
          } else {
            const days = res.data?.days ?? {};
            Object.keys(days).forEach((date) => mergeLine(date, days[date]));
          }
        });

        const dailyLines = Array.from(lineMap.entries())
          .sort((a, b) => a[0].localeCompare(b[0]))
          .map(([date, row]) => ({
            date,
            amount: row.amount,
            price_source: row.price_source,
          }));

        const breakdown = buildBreakdownFromDailyLines(dailyLines, rentType === 1);

        const appliedPlanHint = this.buildAppliedPlanHint(
          appliedPlanMeta,
          Number(this.reservation.price_calculation_mode)
        );

        if (!hadSuccess) {
          return {
            total: 0,
            dailyLines: [],
            breakdown: [],
            appliedPlanHint: null,
            error: lastError || this.translate.instant('RESERVATIONS.PRICE_CALC_ERROR')
          };
        }

        return { total, dailyLines, breakdown, appliedPlanHint, error: null };
      }),
      catchError(() => of({
        total: 0,
        dailyLines: [],
        breakdown: [],
        appliedPlanHint: null,
        error: this.translate.instant('RESERVATIONS.PRICE_CALC_ERROR')
      }))
    );
  }

  onDiscountPresetChange(raw: string | number | null): void {
    const parsed = raw === '' || raw == null ? Number.NaN : Number(raw);
    this.discountPresetId = Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    if (this.discountPresetId == null) {
      return;
    }
    const preset = this.activeDiscounts.find(d => Number(d.id) === this.discountPresetId);
    if (!preset) {
      this.discountPresetId = null;
      return;
    }
    this.applyingDiscountPreset = true;
    if (preset.is_percentage && preset.percent != null) {
      this.discountType = 'percent';
      this.discountValue = Number(preset.percent);
    } else if (preset.is_fixed && preset.fixed_amount != null) {
      this.discountType = 'fixed';
      this.discountValue = Number(preset.fixed_amount);
    }
    this.applyingDiscountPreset = false;
  }

  onDiscountTypeChange(): void {
    if (this.applyingDiscountPreset) {
      return;
    }
    this.discountPresetId = null;
    if (this.discountType === 'percent' && this.discountValue > 100) {
      this.discountValue = 100;
    }
  }

  discountPresetLabel(preset: Discount): string {
    const parts: string[] = [preset.name];
    if (preset.is_percentage && preset.percent != null) {
      parts.push(`${preset.percent}%`);
    }
    if (preset.is_fixed && preset.fixed_amount != null) {
      parts.push(
        this.translate.instant('RESERVATIONS.DISCOUNT_FIXED_SHORT', { amount: preset.fixed_amount })
      );
    }
    return parts.join(' — ');
  }

  discountSummaryHint(): string {
    if (this.discountPresetId != null) {
      return '';
    }
    if (this.discountType === 'percent' && this.discountValue > 0) {
      return this.translate.instant('RESERVATIONS.DISCOUNT_PERCENT_APPLIED', {
        percent: this.discountValue,
        amount: this.manualDiscountAmount
      });
    }
    return '';
  }

  private discountFromPreset(preset?: Discount | null, capAtBase = true): number {
    if (!preset) {
      return 0;
    }
    const base = Number(this.calculatedPrice) || 0;
    let amount = 0;
    if (preset.is_percentage && preset.percent != null) {
      amount += base * Number(preset.percent) / 100;
    }
    if (preset.is_fixed && preset.fixed_amount != null) {
      amount += Number(preset.fixed_amount);
    }
    amount = Math.round(amount * 100) / 100;

    return capAtBase ? Math.min(base, amount) : amount;
  }

  private applyPriceResult(result: PriceCalcResult): void {
    this.calculatedPrice = result.total;
    this.dailyPriceLines = result.dailyLines;
    this.priceBreakdownLines = result.breakdown;
    this.appliedPlanHint = result.appliedPlanHint;
    this.priceCalcError = result.error ?? '';

    if (result.error) {
      this.dataService.showToast(result.error, 'error');
    }
  }

  private buildAppliedPlanHint(plan: Record<string, unknown> | null, mode: number): string | null {
    if (mode !== 0) {
      return null;
    }
    if (!plan) {
      return this.translate.instant('RESERVATIONS.NO_APPLIED_PLAN_HINT');
    }
    const name = this.isRtl
      ? String(plan['name_ar'] ?? plan['name_en'] ?? '')
      : String(plan['name_en'] ?? plan['name_ar'] ?? '');
    return this.translate.instant('RESERVATIONS.APPLIED_PLAN_HINT', {
      name,
      from: plan['start_date'],
      to: plan['end_date'],
      daily: plan['daily_price'],
      monthly: plan['monthly_price']
    });
  }

  goToWizardStep(step: number): void {
    if (step < 0 || step >= this.wizardSteps.length) {
      return;
    }
    this.wizardStep = step;
  }

  nextWizardStep(): void {
    if (this.wizardStep === 0 && !this.reservation.client_id) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.NO_CLIENT'), 'warning');
      return;
    }
    if (this.wizardStep === 1) {
      if (!this.ensureValidDates()) {
        this.dataService.showToast(this.translate.instant('RESERVATIONS.DATE_RANGE_ERROR'), 'warning');
        return;
      }
      if (!this.selectedRooms.length) {
        this.dataService.showToast(this.translate.instant('RESERVATIONS.NO_ROOM'), 'warning');
        return;
      }
    }
    if (this.wizardStep < this.wizardSteps.length - 1) {
      this.wizardStep += 1;
    }
  }

  prevWizardStep(): void {
    if (this.wizardStep > 0) {
      this.wizardStep -= 1;
    }
  }

  onSubmit(): void {
    if (!this.reservation.client_id) {
      this.wizardStep = 0;
      this.dataService.showToast(this.translate.instant('RESERVATIONS.NO_CLIENT'), 'warning');
      return;
    }
    if (!this.selectedRooms.length) {
      this.wizardStep = 1;
      this.dataService.showToast(this.translate.instant('RESERVATIONS.NO_ROOM'), 'warning');
      return;
    }
    if (!this.ensureValidDates()) {
      const key = this.startBeforeTodayError
        ? 'RESERVATIONS.START_BEFORE_TODAY'
        : this.monthlyStayError
          ? 'RESERVATIONS.MONTHLY_MIN_STAY_ERROR'
          : 'RESERVATIONS.DATE_RANGE_ERROR';
      this.dataService.showToast(this.translate.instant(key, { date: this.monthlyMinExpireLabel }), 'warning');
      return;
    }
    if (this.reservation.logedin === 1 && this.selectedRoomsNeedCleaning) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.CHECK_IN_NEEDS_CLEANING'),
        'warning'
      );
      return;
    }
    if (this.selectedRoomsBlockedByAvailability) {
      this.dataService.showToast(
        this.unavailableSelectedRoomsHint || this.translate.instant('RESERVATIONS.SELECTED_ROOMS_UNAVAILABLE_HINT', { rooms: '' }),
        'warning'
      );
      return;
    }
    if (this.billToCompany && !this.selectedCompanyId) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.NO_COMPANY_SELECTED'), 'warning');
      return;
    }
    if (!this.billToCompany && !this.validatePayAmount()) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.PAYMENT_AMOUNT_EXCEEDS_TOTAL', {
          max: this.grandTotal.toFixed(2)
        }),
        'error'
      );
      return;
    }

    this.isSaving = true;
    this.syncReservationRooms();

    const payload = {
      ...this.reservation,
      discount: this.manualDiscountRaw
    };

    const submit$ = this.billToCompany
      ? this.companyService.createCompanyBilledReservation({ ...payload, company_id: this.selectedCompanyId })
      : this.hotelService.makeReservation(payload);

    submit$.subscribe({
      next: (res) => {
        if (res.success) {
          const id = this.hotelService.extractCreatedReservationId(res);
          if (!id) {
            this.dataService.showToast(
              this.translate.instant('RESERVATIONS.CREATE_SAVED_BUT_MISSING_ID'),
              'error'
            );
            this.isSaving = false;
            return;
          }
          this.dataService.showToast(
            this.translate.instant('RESERVATIONS.CREATE_SUCCESS'),
            'success'
          );
          void this.router.navigate(['/booking/reservations', id]);
        } else {
          this.dataService.showToast(res.message || 'Error creating reservation', 'error');
        }
        this.isSaving = false;
      },
      error: (err) => {
        this.dataService.showToast(err.error?.message || err.message || 'Error creating reservation', 'error');
        this.isSaving = false;
      }
    });
  }

  goBack(): void {
    this.location.back();
  }
}
