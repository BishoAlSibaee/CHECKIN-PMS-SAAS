import { Component, OnDestroy, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom, Subscription } from 'rxjs';
import { HotelService } from '../../../services/hotel.service';
import { DataService } from '../../../services/Api/data.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';
import { AuthPermissionService } from '../../../services/auth-permission.service';
import { Booking, BookingStatus } from '../../../models/booking.interface';
import { Client } from '../../../models/client.interface';
import { RoomType } from '../../../models/room-type.interface';
import { TranslateService } from '@ngx-translate/core';
import {
  aggregateDailyCharges,
  buildBreakdownFromDailyLines,
  breakdownNightlyRate,
  breakdownRowAmount,
  breakdownRowExpandable,
  dailyLinesForPeriod,
  DailyPriceLine,
  PriceBreakdownLine,
} from '../../../utils/reservation-price-breakdown';
import {
  InvoicePrintLine,
  openReservationInvoicePrint,
} from '../../../utils/reservation-invoice-print';
import { RealtimeService } from '../../../services/realtime.service';
import { HotelLivePayload } from '../../../models/realtime.models';
import { PosChargeService } from '../../../services/pos-charge.service';
import { PosCharge, POS_CHARGE_STATUS_ACTIVE, POS_PAYMENT_STATUS_CHARGED_TO_ROOM } from '../../../models/pos-charge.interface';
import QRCode from 'qrcode';

export interface ReservationChangeLogEntry {
  id: number;
  action: string;
  user: { id: number; name: string; job_number: string } | null;
  old_values: Record<string, unknown>;
  new_values: Record<string, unknown>;
  changes: Record<string, { old: unknown; new: unknown }>;
  created_at: string;
}

@Component({
    selector: 'app-reservation-detail',
    templateUrl: './reservation-detail.component.html',
    styleUrls: ['./reservation-detail.component.css'],
    standalone: false
})
export class ReservationDetailComponent implements OnInit, OnDestroy {
  booking: Booking | undefined;
  client: Client | undefined;
  roomType: RoomType | undefined;
  isLoading = true;
  isSaving = false;

  rawReservation: any = null;
  dailyCharges: any[] = [];
  dailyPriceLines: DailyPriceLine[] = [];
  posCharges: PosCharge[] = [];
  readonly POS_CHARGE_STATUS_ACTIVE = POS_CHARGE_STATUS_ACTIVE;
  readonly POS_PAYMENT_STATUS_CHARGED_TO_ROOM = POS_PAYMENT_STATUS_CHARGED_TO_ROOM;
  priceBreakdownLines: PriceBreakdownLine[] = [];
  assignedRooms: { resRoomId?: number; number: string; typeName: string; building?: string; floor?: string; roomStatus: number; needsCleaning: boolean; isCancelled: boolean }[] = [];
  roomsDisplay = '—';
  paidAmount = 0;
  balanceDue = 0;
  reservationStatusNum = 1;
  logedin = 0;
  loginTime = '';
  roomStatuses: number[] = [];
  roomNumbersNeedingCleaning: string[] = [];

  paymentAmount: number | null = null;
  showPaymentModal = false;
  showRefundModal = false;
  redeemPoints: number | null = null;
  showRedeemModal = false;
  showMoreActions = false;
  showDateChangeModal = false;
  refundPreview: any = null;
  dateChangePreview: any = null;
  refundPreviewLoading = false;
  dateChangePreviewLoading = false;
  extendExpireDate = '';
  shortenExpireDate = '';
  partialRefundPreview: any = null;
  partialPreviewLoading = false;
  editStartDate = '';
  editExpireDate = '';
  changeLogs: ReservationChangeLogEntry[] = [];
  /** ZATCA simplified e-invoice QR payload (base64 TLV), when reported. */
  zatcaQrCode: string | null = null;
  zatcaInvoiceStatus: string | null = null;
  zatcaTaxInvoiceNumber: string | null = null;
  zatcaCreditNotes: { invoice_number: string; total: number; issued_at: string | null }[] = [];

  private realtimeOffs: (() => void)[] = [];
  private langSub?: Subscription;
  private reservationId = '';
  private lastApiResponse: any = null;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private hotelService: HotelService,
    private dataService: DataService,
    private translate: TranslateService,
    private confirm: ConfirmDialogService,
    public authPerm: AuthPermissionService,
    private realtime: RealtimeService,
    private posChargeService: PosChargeService
  ) {}

  get currentLang(): string {
    return this.translate.currentLang || 'en';
  }

  get isRtl(): boolean {
    return this.currentLang === 'ar';
  }

  get isMonthlyRent(): boolean {
    return Number(this.rawReservation?.rent_type) === 1;
  }

  get accommodationSubtotal(): number {
    if (this.dailyPriceLines.length) {
      return this.dailyPriceLines.reduce((sum, line) => sum + line.amount, 0);
    }
    return Number(this.booking?.roomPrice ?? 0);
  }

  get canUpdate(): boolean {
    return this.authPerm.has('update reservations');
  }

  get today(): string {
    return new Date().toISOString().split('T')[0];
  }

  get isCancelled(): boolean {
    return this.reservationStatusNum === 3;
  }

  /** Scheduled departure date has passed (guest may still be in-house until manual check-out). */
  get isStayEnded(): boolean {
    const expire = this.editExpireDate || this.booking?.checkOut;
    return !!expire && expire < this.today;
  }

  get isOverdueInHouse(): boolean {
    return this.isStayEnded && this.logedin === 1 && !this.isCancelled;
  }

  get bookingAlerts(): Array<{
    variant: string;
    titleKey?: string;
    messageKey: string;
    params?: Record<string, string | number>;
    action?: 'roomBoard';
  }> {
    if (!this.booking || this.isLoading) {
      return [];
    }
    const alerts: Array<{
      variant: string;
      titleKey?: string;
      messageKey: string;
      params?: Record<string, string | number>;
      action?: 'roomBoard';
    }> = [];
    if (this.logedin === 1 && this.balanceDue > 0) {
      alerts.push({
        variant: 'warning',
        titleKey: 'RESERVATIONS.ALERT_PAYMENT_TITLE',
        messageKey: 'RESERVATIONS.CHECKOUT_REQUIRES_PAYMENT',
        params: { amount: this.balanceDue.toFixed(2) },
      });
    }
    if (this.isOverdueInHouse) {
      alerts.push({
        variant: 'warning',
        titleKey: 'RESERVATIONS.ALERT_OVERDUE_TITLE',
        messageKey: 'RESERVATIONS.STAY_OVERDUE_NOTICE',
      });
    }
    if (this.roomsNeedCleaning && this.logedin === 0 && !this.isStayEnded && !this.isCancelled) {
      alerts.push({
        variant: 'warning',
        titleKey: 'RESERVATIONS.ALERT_CLEANING_TITLE',
        messageKey: 'RESERVATIONS.CHECK_IN_NEEDS_CLEANING_ROOMS',
        params: { rooms: this.roomsNeedCleaningLabel },
        action: 'roomBoard',
      });
    }
    if (this.isStayEnded && this.logedin === 0 && !this.isCancelled) {
      alerts.push({
        variant: 'secondary',
        messageKey: 'RESERVATIONS.STAY_ENDED_NOTICE',
      });
    }
    return alerts;
  }

  get isBeforeCheckIn(): boolean {
    const start = this.editStartDate || this.booking?.checkIn;
    return !!start && start > this.today;
  }

  get canCheckIn(): boolean {
    return (
      this.canUpdate &&
      !this.isCancelled &&
      !this.hasCheckedOut &&
      !this.isStayEnded &&
      !this.isBeforeCheckIn &&
      this.logedin === 0 &&
      !this.roomsNeedCleaning
    );
  }

  get roomsNeedCleaning(): boolean {
    return this.roomNumbersNeedingCleaning.length > 0;
  }

  get roomsNeedCleaningLabel(): string {
    return this.roomNumbersNeedingCleaning.join(', ');
  }

  get isFullyPaid(): boolean {
    return this.balanceDue <= 0.005;
  }

  /** Check-out when guest is in-house and departure date is today or overdue. */
  get canCheckOut(): boolean {
    if (!this.authPerm.has('update reservations') || this.isCancelled || this.logedin !== 1) {
      return false;
    }
    const expire = this.editExpireDate || this.booking?.checkOut || '';
    return !!expire && expire <= this.today;
  }

  get canCheckOutNow(): boolean {
    return this.canCheckOut && this.isFullyPaid;
  }

  get canRefund(): boolean {
    return (
      this.authPerm.has('manage refunds') &&
      !this.isCancelled &&
      !this.hasCheckedOut &&
      !this.isStayEnded &&
      this.paidAmount > 0.005
    );
  }

  /** Dates editable only before check-in and while stay is still active. */
  get canEditDates(): boolean {
    return this.canUpdate && !this.isCancelled && !this.hasCheckedOut && !this.isStayEnded && this.logedin === 0;
  }

  /** Cancel only before guest checks in / checks out, and before stay ends. */
  get canCancel(): boolean {
    return (
      this.authPerm.has('cancel reservations') &&
      !this.isCancelled &&
      !this.hasCheckedOut &&
      !this.isStayEnded &&
      this.logedin === 0
    );
  }

  get hasCheckedOut(): boolean {
    if (this.logedin !== 0 || !this.loginTime) {
      return false;
    }
    if (this.changeLogs.some((entry) => entry.action === 'checked_in')) {
      return true;
    }
    return this.isStayEnded || this.booking?.status === 'checked-out';
  }

  get canExtend(): boolean {
    return this.canUpdate && !this.isCancelled && !this.hasCheckedOut;
  }

  get canPartialCancel(): boolean {
    return this.canRefund && !!this.editExpireDate && this.editExpireDate > this.addDays(this.today, 1);
  }

  get minShortenDate(): string {
    const tomorrow = this.addDays(this.today, 1);
    const afterStart = this.addDays(this.editStartDate || this.today, 1);
    return tomorrow > afterStart ? tomorrow : afterStart;
  }

  get maxShortenDate(): string {
    return this.addDays(this.editExpireDate || this.today, -1);
  }

  get canAddPayment(): boolean {
    return !this.isCancelled && this.balanceDue > 0.005;
  }

  get canRedeemPoints(): boolean {
    return !this.isCancelled && this.balanceDue > 0.005 && (this.client?.loyaltyPoints ?? 0) > 0;
  }

  get maxRedeemablePoints(): number {
    return Math.min(this.client?.loyaltyPoints ?? 0, Math.floor(this.balanceDue));
  }

  get redeemPointsExceedsLimit(): boolean {
    const points = Number(this.redeemPoints);
    return Number.isFinite(points) && points > 0 && points > this.maxRedeemablePoints;
  }

  get minExpireDate(): string {
    return this.addDays(this.editStartDate || this.today, 1) > this.today
      ? this.addDays(this.editStartDate || this.today, 1)
      : this.today;
  }

  get minExtendDate(): string {
    const afterCurrent = this.addDays(this.editExpireDate || this.today, 1);
    return afterCurrent > this.today ? afterCurrent : this.today;
  }

  get bookingStatusLabel(): string {
    if (!this.booking?.status) {
      return '';
    }
    const key = 'RESERVATIONS.' + this.booking.status.toUpperCase().replace(/-/g, '_');
    const label = this.translate.instant(key);
    return label !== key ? label : this.booking.status;
  }

  get clientDisplayName(): string {
    if (!this.client) return '';
    const named = (this.client as any).name;
    if (named) return named;
    return `${this.client.first_name ?? ''} ${this.client.last_name ?? ''}`.trim();
  }

  get bookedByDisplay(): string {
    const user = this.rawReservation?.user;
    if (!user) {
      return this.translate.instant('COMMON.UNKNOWN');
    }
    const name = String(user.name ?? '').trim();
    const jobNumber = String(user.job_number ?? '').trim();
    if (name && jobNumber) {
      return `${jobNumber} — ${name}`;
    }
    return name || jobNumber || this.translate.instant('COMMON.UNKNOWN');
  }

  get bookedAt(): string | null {
    const createdAt = this.rawReservation?.created_at;
    return createdAt ? String(createdAt) : null;
  }

  changeLogUserLabel(entry: ReservationChangeLogEntry): string {
    const user = entry.user;
    if (!user) {
      return this.translate.instant('COMMON.UNKNOWN');
    }
    const name = String(user.name ?? '').trim();
    const jobNumber = String(user.job_number ?? '').trim();
    if (name && jobNumber) {
      return `${jobNumber} — ${name}`;
    }
    return name || jobNumber || this.translate.instant('COMMON.UNKNOWN');
  }

  changeLogActionLabel(action: string): string {
    const key = `RESERVATIONS.CHANGE_ACTION_${action}`;
    const translated = this.translate.instant(key);
    return translated !== key ? translated : action;
  }

  changeFieldLabel(field: string): string {
    const key = `RESERVATIONS.CHANGE_FIELD_${field}`;
    const translated = this.translate.instant(key);
    return translated !== key ? translated : field;
  }

  changeFieldKeys(entry: ReservationChangeLogEntry): string[] {
    return Object.keys(entry.changes ?? {});
  }

  formatChangeValue(field: string, value: unknown): string {
    if (value == null || value === '') {
      return '—';
    }
    if (field === 'logedin') {
      return Number(value) === 1
        ? this.translate.instant('RESERVATIONS.CHANGE_VALUE_YES')
        : this.translate.instant('RESERVATIONS.CHANGE_VALUE_NO');
    }
    if (Array.isArray(value)) {
      return value.map((item) => String(item)).join(', ');
    }
    if (typeof value === 'number') {
      return value.toFixed(2);
    }
    return String(value);
  }

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (id) {
      this.reservationId = id;
      this.loadBooking(id);
      this.realtimeOffs = [
        this.realtime.onScope('reservation_detail', (payload: HotelLivePayload) => {
          if (payload.entity?.type === 'reservation' && String(payload.entity.id) === this.reservationId) {
            const delayMs = ['checked_in', 'checked_out', 'reservation_created', 'reservation_updated'].includes(payload.action)
              ? 400
              : 0;
            window.setTimeout(() => this.loadBooking(this.reservationId, { softReload: true }), delayMs);
          }
        }),
        this.realtime.onScope('occupancy_board', () => {
          window.setTimeout(() => this.loadBooking(this.reservationId, { softReload: true }), 200);
        }),
        this.realtime.onScope('property', () => {
          window.setTimeout(() => this.loadBooking(this.reservationId, { softReload: true }), 200);
        }),
      ];
    } else {
      this.router.navigate(['/booking/reservations']);
    }

    this.langSub = this.translate.onLangChange.subscribe(() => {
      if (this.lastApiResponse) {
        this.applyReservationPayload(this.lastApiResponse);
      }
    });
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
    this.realtimeOffs.forEach((off) => off());
  }

  loadBooking(id: string, options?: { softReload?: boolean }): void {
    const hadBooking = !!this.booking;
    const softReload = options?.softReload === true && hadBooking;
    if (!softReload) {
      this.isLoading = true;
    }

    this.hotelService.getReservation(id).subscribe({
      next: (res) => {
        if (!this.applyReservationPayload(res)) {
          if (softReload) {
            this.dataService.showToast(this.translate.instant('RESERVATIONS.RELOAD_FAILED'), 'warning');
          } else {
            this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.NOT_FOUND'), 'error');
          }
          this.isLoading = false;
          return;
        }

        this.isLoading = false;
        this.loadRefundPreviewIfEligible();
        if (this.route.snapshot.queryParamMap.get('collect') === '1' && this.canAddPayment) {
          this.openPaymentModal();
          void this.router.navigate([], {
            relativeTo: this.route,
            queryParams: { collect: null },
            queryParamsHandling: 'merge',
            replaceUrl: true,
          });
        }
      },
      error: (err) => {
        const code = Number(err.error?.code ?? err.status ?? 0);
        const rawMessage = String(err.error?.message ?? '');
        const lowerMessage = rawMessage.toLowerCase();
        const isNotFound = code === 404
          || rawMessage.includes('No query results for model')
          || (lowerMessage.includes('reservation not found')
            && !lowerMessage.includes('table')
            && !lowerMessage.includes('base table'));

        if (softReload) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.RELOAD_FAILED'), 'warning');
          this.isLoading = false;
          return;
        }

        this.dataService.showToast(
          isNotFound
            ? this.translate.instant('RESERVATIONS.NOT_FOUND')
            : (rawMessage || this.translate.instant('RESERVATIONS.LOAD_FAILED')),
          'error'
        );
        this.isLoading = false;
        if (isNotFound && !hadBooking && !softReload) {
          void this.router.navigate(['/booking/reservations']);
        }
      }
    });
  }

  /** @returns false when payload is missing or invalid */
  private applyReservationPayload(res: any): boolean {
    if (!res?.success || !res?.data?.reservation) {
      return false;
    }

    this.lastApiResponse = res;
    this.changeLogs = Array.isArray(res.data.change_logs) ? res.data.change_logs : [];
    const zatca = res.data.zatca_invoice;
    this.zatcaQrCode = typeof zatca?.qr_code === 'string' && zatca.qr_code.trim() !== ''
      ? zatca.qr_code.trim()
      : null;
    this.zatcaInvoiceStatus = typeof zatca?.status === 'string' ? zatca.status : null;
    this.zatcaTaxInvoiceNumber = typeof zatca?.invoice?.invoice_number === 'string' && zatca.invoice.invoice_number.trim() !== ''
      ? zatca.invoice.invoice_number.trim()
      : null;
    this.zatcaCreditNotes = Array.isArray(zatca?.credit_notes) ? zatca.credit_notes : [];

    const r = res.data.reservation;
    this.rawReservation = r;
    this.loadPosCharges(r.id);
    const roomRows = this.hotelService.extractReservationRooms(r);
    this.roomsDisplay = res.data.room_numbers_label
      || this.hotelService.formatRoomNumbers(r);
    this.assignedRooms = roomRows.map((row: any) => {
      const rm = row?.room;
      const rt = rm?.room_type;
      const roomStatus = Number(rm?.roomStatus ?? 1);
      return {
        resRoomId: row?.id,
        number: rm?.number != null ? String(rm.number) : '—',
        typeName: this.hotelService.localizeRoomTypeName(rt, this.currentLang),
        building: rm?.building?.name,
        floor: rm?.floor?.name || (rm?.floor?.number != null ? String(rm.floor.number) : undefined),
        roomStatus,
        needsCleaning: roomStatus === 3,
        isCancelled: Number(row?.status ?? 0) === 1,
      };
    });
    this.dailyCharges = (res.data.daily_charges ?? []).map((line: any) => ({
      ...line,
      charge_date: this.normalizeDate(line.charge_date)
    }));
    this.dailyPriceLines = aggregateDailyCharges(this.dailyCharges);
    this.priceBreakdownLines = buildBreakdownFromDailyLines(
      this.dailyPriceLines,
      Number(r.rent_type) === 1
    );
    this.paidAmount = Number(res.data.paid_amount ?? 0);
    this.balanceDue = Number(res.data.balance_due ?? 0);
    this.reservationStatusNum = Number(r.reservation_status);
    this.logedin = Number(r.logedin);
    this.loginTime = r.login_time ? this.normalizeDate(String(r.login_time)) : '';
    this.roomStatuses = roomRows
      .map((rr: any) => Number(rr.room?.roomStatus ?? 1));
    this.roomNumbersNeedingCleaning = roomRows
      .filter((rr: any) => Number(rr.room?.roomStatus ?? 1) === 3)
      .map((rr: any) => (rr.room?.number != null ? String(rr.room.number) : `#${rr.room_id ?? '?'}`));

    const room = roomRows[0]?.room;
    this.booking = {
      id: String(r.id),
      guest: r.client ? `${r.client.first_name ?? ''} ${r.client.last_name ?? ''}`.trim() : this.translate.instant('COMMON.UNKNOWN'),
      room: this.roomsDisplay,
      checkIn: r.start_date,
      checkOut: r.expire_date,
      status: this.mapStatus(r.reservation_status, r.logedin, r.expire_date),
      amount: Number(r.total),
      phone: r.client?.mobile ?? '',
      email: r.client?.email ?? '',
      startDate: new Date(r.start_date),
      endDate: new Date(r.expire_date),
      clientId: String(r.client_id),
      roomTypeId: room?.room_type_id ? String(room.room_type_id) : '',
      paymentStatus: this.mapPaymentStatus(Number(r.total), this.paidAmount),
      paymentMethod: 'RESERVATIONS.PAYMENT_OTHER',
      roomPrice: Number(r.base_price),
      tax: Number(r.taxes),
      discount: Number(r.discount),
      classificationDiscount: Number(r.classification_discount ?? 0),
      totalDays: Number(r.nights),
      payments: (r.payments ?? []).map((p: any) => ({
        id: String(p.id),
        amount: Number(p.pay),
        date: p.created_at,
        method: Number(p.type) === 1 ? 'FINANCIALS.REFUND' : 'FINANCIALS.CASH',
        isRefund: Number(p.type) === 1,
        note: p.note ?? '',
        voucherNumber: p.voucher?.voucher_number ?? null
      }))
    };

    if (r.client) {
      this.client = {
        id: String(r.client.id),
        first_name: r.client.first_name,
        last_name: r.client.last_name,
        name: `${r.client.first_name ?? ''} ${r.client.last_name ?? ''}`.trim(),
        email: r.client.email,
        mobile: r.client.mobile,
        IdNumber: r.client.IdNumber ?? '',
        nationality: r.client.nationality ?? '',
        loyaltyPoints: Number(r.client.loyalty_points_balance ?? 0)
      } as Client;
    }

    if (room?.room_type) {
      const rt = room.room_type;
      this.roomType = {
        ...rt,
        id: String(rt.id),
        name: this.hotelService.localizeRoomTypeName(rt, this.currentLang),
        description: rt.description ?? '',
        baseRate: Number(rt.Min_daily_price ?? 0),
        maxOccupancy: 2,
        amenities: [],
        color: '',
        minDailyRate: Number(rt.Min_daily_price ?? 0),
        maxDailyRate: Number(rt.Max_daily_price ?? 0),
        minMonthlyRate: Number(rt.Min_monthly_price ?? 0),
        maxMonthlyRate: Number(rt.Max_monthly_price ?? 0),
      };
    } else {
      this.roomType = undefined;
    }

    this.extendExpireDate = this.normalizeDate(r.expire_date);
    this.shortenExpireDate = '';
    this.partialRefundPreview = null;
    this.editStartDate = this.normalizeDate(r.start_date);
    this.editExpireDate = this.normalizeDate(r.expire_date);
    this.lastApiResponse = res;
    return true;
  }

  private loadPosCharges(reservationId: number): void {
    if (!this.authPerm.hasAny('view pos charges')) {
      return;
    }
    this.posChargeService.list(1, { reservation_id: reservationId }).subscribe({
      next: (res) => {
        if (res.success) {
          this.posCharges = res.data ?? [];
        }
      },
      error: () => {}
    });
  }

  checkIn(): void {
    if (!this.booking) return;
    if (this.roomsNeedCleaning) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.CHECK_IN_NEEDS_CLEANING_ROOMS', { rooms: this.roomsNeedCleaningLabel }),
        'warning'
      );
      return;
    }
    if (!this.canCheckIn) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.CHECK_IN_NOT_ALLOWED'), 'warning');
      return;
    }
    this.patchReservation({ logedin: 1, login_time: this.today });
  }

  async checkOut(): Promise<void> {
    if (!this.booking || !this.canCheckOut) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.CHECK_OUT_NOT_ALLOWED'), 'warning');
      return;
    }
    if (!this.isFullyPaid) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.CHECKOUT_REQUIRES_PAYMENT', { amount: this.balanceDue.toFixed(2) }),
        'warning'
      );
      return;
    }

    const roomLabel = this.roomsDisplay !== '—' ? this.roomsDisplay : this.booking.room;
    const ok = await this.confirm.confirm(
      this.translate.instant('DASHBOARD.CHECK_OUT_CONFIRM', {
        guest: this.booking.guest,
        room: roomLabel
      })
    );
    if (!ok) {
      return;
    }

    this.isSaving = true;
    this.hotelService.updateReservation(this.booking.id, { logedin: 0 }).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.applyMutationResponse(res, false);
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('COMMON.ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('COMMON.ERROR'), 'error');
      }
    });
  }

  saveReservationDates(): void {
    if (!this.booking || !this.canEditDates) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.DATES_EDIT_NOT_ALLOWED'), 'warning');
      return;
    }
    if (!this.editStartDate || !this.editExpireDate) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.DATE_RANGE_ERROR'), 'error');
      return;
    }
    if (this.editExpireDate < this.today) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.EXPIRE_BEFORE_TODAY'), 'error');
      return;
    }
    if (this.editExpireDate <= this.editStartDate) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.DATE_RANGE_ERROR'), 'error');
      return;
    }

    const datesUnchanged =
      this.editStartDate === this.booking.checkIn &&
      this.editExpireDate === this.booking.checkOut;
    if (datesUnchanged) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.DATE_RANGE_ERROR'), 'warning');
      return;
    }

    this.dateChangePreviewLoading = true;
    this.showDateChangeModal = true;
    this.dateChangePreview = null;
    this.hotelService.previewDateChange(this.booking.id, this.editStartDate, this.editExpireDate).subscribe({
      next: (res) => {
        this.dateChangePreviewLoading = false;
        if (res?.success && res?.data) {
          this.dateChangePreview = res.data;
        } else {
          this.showDateChangeModal = false;
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.DATE_CHANGE_PREVIEW_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.dateChangePreviewLoading = false;
        this.showDateChangeModal = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.DATE_CHANGE_PREVIEW_FAILED'), 'error');
      }
    });
  }

  closeDateChangeModal(): void {
    if (this.isSaving) {
      return;
    }
    this.showDateChangeModal = false;
    this.dateChangePreview = null;
  }

  confirmDateChange(): void {
    if (!this.booking || !this.dateChangePreview) {
      return;
    }

    this.isSaving = true;
    this.hotelService.updateReservation(this.booking.id, {
      start_date: this.editStartDate,
      expire_date: this.editExpireDate
    }).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.closeDateChangeModal();
          this.applyMutationResponse(res, false);
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.UPDATE_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.UPDATE_FAILED'), 'error');
      }
    });
  }

  dateChangeActionLabel(): string {
    const action = this.dateChangePreview?.financial_action;
    switch (action) {
      case 'refund':
        return this.translate.instant('RESERVATIONS.DATE_CHANGE_ACTION_REFUND');
      case 'additional_charge':
        return this.translate.instant('RESERVATIONS.DATE_CHANGE_ACTION_CHARGE');
      case 'balance_reduced':
        return this.translate.instant('RESERVATIONS.DATE_CHANGE_ACTION_BALANCE_REDUCED');
      default:
        return this.translate.instant('RESERVATIONS.DATE_CHANGE_ACTION_NO_CHANGE');
    }
  }

  openRefundModal(): void {
    if (!this.booking || !this.canRefund) {
      if (this.isStayEnded) {
        this.dataService.showToast(this.translate.instant('RESERVATIONS.REFUND_NOT_ALLOWED_COMPLETED'), 'warning');
      }
      return;
    }
    this.refundPreviewLoading = true;
    this.showRefundModal = true;
    this.hotelService.previewRefund(this.booking.id).subscribe({
      next: (res) => {
        this.refundPreviewLoading = false;
        if (res?.success && res?.data) {
          this.refundPreview = res.data;
        } else {
          this.showRefundModal = false;
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.REFUND_PREVIEW_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.refundPreviewLoading = false;
        this.showRefundModal = false;
        const hint = err.error?.data?.hint;
        const msg = hint || err.error?.message || this.translate.instant('RESERVATIONS.REFUND_PREVIEW_FAILED');
        this.dataService.showToast(msg, 'error');
      }
    });
  }

  closeRefundModal(): void {
    if (this.isSaving) return;
    this.showRefundModal = false;
    this.refundPreview = null;
  }

  confirmRefund(): void {
    if (!this.booking || !this.refundPreview) return;
    this.isSaving = true;
    this.hotelService.processRefund(this.booking.id).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.REFUND_OK'), 'success');
          this.closeRefundModal();
          if (this.applyReservationPayload(res)) {
            this.loadRefundPreviewIfEligible();
          } else {
            this.loadBooking(this.booking!.id, { softReload: true });
          }
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.REFUND_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.REFUND_FAILED'), 'error');
      }
    });
  }

  private loadRefundPreviewIfEligible(): void {
    this.refundPreview = null;
    if (!this.canRefund || !this.booking) return;
    this.hotelService.previewRefund(this.booking.id).subscribe({
      next: (res) => {
        if (res?.success) this.refundPreview = res.data;
      },
      error: () => { /* button still opens modal with fresh preview */ }
    });
  }

  /** Cancelling one room only makes sense with the same guards as a full cancel, plus more than one active room left. */
  canCancelRoom(rm: { isCancelled: boolean }): boolean {
    if (!this.canCancel || rm.isCancelled) return false;
    return this.assignedRooms.filter((r) => !r.isCancelled).length > 1;
  }

  async cancelRoom(rm: { resRoomId?: number; number: string }): Promise<void> {
    if (!this.booking || !rm.resRoomId) return;
    const ok = await this.confirm.confirm(
      this.translate.instant('RESERVATIONS.CANCEL_ROOM_CONFIRM', { room: rm.number })
    );
    if (!ok) return;

    this.isSaving = true;
    this.hotelService.cancelReservationRoom(this.booking.id, rm.resRoomId).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.ROOM_CANCELLED_OK'), 'success');
          this.loadBooking(this.booking!.id);
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.ROOM_CANCEL_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.ROOM_CANCEL_FAILED'), 'error');
      }
    });
  }

  async cancelReservation(): Promise<void> {
    if (!this.booking) return;
    if (!this.canCancel) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.CANCEL_NOT_ALLOWED'), 'warning');
      return;
    }
    const ok = await this.confirm.confirm(this.translate.instant('RESERVATIONS.CANCEL_CONFIRM'));
    if (!ok) return;

    this.isSaving = true;
    this.hotelService.cancelReservation(this.booking.id).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.CANCELLED_OK'), 'success');
          this.loadBooking(this.booking!.id);
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.CANCEL_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.CANCEL_FAILED'), 'error');
      }
    });
  }

  extendStay(): void {
    if (!this.booking || !this.extendExpireDate || !this.canExtend) return;
    if (this.extendExpireDate < this.today) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.EXPIRE_BEFORE_TODAY'), 'error');
      return;
    }
    if (this.extendExpireDate <= this.editStartDate) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.DATE_RANGE_ERROR'), 'error');
      return;
    }
    if (this.extendExpireDate <= this.editExpireDate) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.EXTEND_AFTER_CHECKOUT'), 'warning');
      return;
    }
    this.isSaving = true;
    this.hotelService.extendReservation(this.booking.id, this.extendExpireDate).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.EXTEND_OK'), 'success');
          this.loadBooking(this.booking!.id);
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.EXTEND_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.EXTEND_FAILED'), 'error');
      }
    });
  }

  onShortenDateChange(): void {
    this.partialRefundPreview = null;
  }

  previewPartialRefund(): void {
    if (!this.booking || !this.shortenExpireDate || !this.canPartialCancel) return;
    this.partialPreviewLoading = true;
    this.partialRefundPreview = null;
    this.hotelService.previewRefund(this.booking.id, this.shortenExpireDate).subscribe({
      next: (res) => {
        this.partialPreviewLoading = false;
        if (res?.success && res?.data) {
          this.partialRefundPreview = res.data;
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.REFUND_PREVIEW_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.partialPreviewLoading = false;
        const hint = err.error?.data?.hint;
        this.dataService.showToast(hint || err.error?.message || this.translate.instant('RESERVATIONS.REFUND_PREVIEW_FAILED'), 'error');
      }
    });
  }

  confirmPartialRefund(): void {
    if (!this.booking || !this.shortenExpireDate || !this.partialRefundPreview) return;
    this.isSaving = true;
    this.hotelService.processRefund(this.booking.id, this.shortenExpireDate).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.PARTIAL_REFUND_OK'), 'success');
          this.shortenExpireDate = '';
          this.partialRefundPreview = null;
          if (this.applyReservationPayload(res)) {
            this.loadRefundPreviewIfEligible();
          } else {
            this.loadBooking(this.booking!.id, { softReload: true });
          }
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.REFUND_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.REFUND_FAILED'), 'error');
      }
    });
  }

  async printInvoice(): Promise<void> {
    if (!this.booking) return;

    // Refresh detail so post-checkout ZATCA QR is available before print.
    if (!this.zatcaQrCode && this.booking.id) {
      try {
        const res = await firstValueFrom(this.hotelService.getReservation(this.booking.id));
        if (res) {
          this.applyReservationPayload(res);
        }
      } catch {
        // Continue with whatever we already have.
      }
    }

    let taxQrDataUrl: string | null = null;
    if (this.zatcaQrCode) {
      try {
        taxQrDataUrl = await QRCode.toDataURL(this.zatcaQrCode, {
          errorCorrectionLevel: 'M',
          margin: 1,
          width: 180,
          color: { dark: '#000000', light: '#ffffff' },
        });
      } catch {
        taxQrDataUrl = null;
      }
    }

    if (!taxQrDataUrl && this.logedin === 0 && !!this.loginTime) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.INVOICE_TAX_QR_MISSING'), 'warning');
    }

    const t = (key: string, params?: Record<string, unknown>) => this.translate.instant(key, params);
    const currency = t('COMMON.CURRENCY_SYMBOL');
    const lineItems = this.buildInvoiceLineItems();
    const statusKey = `RESERVATIONS.${this.booking.status.toUpperCase().replace('-', '_')}`;
    const statusLabel = this.translate.instant(statusKey);

    const opened = openReservationInvoicePrint({
      dir: this.isRtl ? 'rtl' : 'ltr',
      lang: this.currentLang,
      labels: {
        title: t('RESERVATIONS.INVOICE_TITLE', { id: this.booking.id }),
        issuedOn: t('RESERVATIONS.INVOICE_ISSUED_ON'),
        billTo: t('RESERVATIONS.INVOICE_BILL_TO'),
        guest: t('RESERVATIONS.INVOICE_GUEST'),
        phone: t('RESERVATIONS.PHONE'),
        email: t('RESERVATIONS.EMAIL'),
        room: t('RESERVATIONS.INVOICE_ROOM'),
        stay: t('RESERVATIONS.INVOICE_STAY'),
        nights: t('RESERVATIONS.NIGHTS_COUNT', { count: this.booking.totalDays || 0 }),
        status: t('RESERVATIONS.STATUS'),
        lineItems: t('RESERVATIONS.INVOICE_LINE_ITEMS'),
        description: t('RESERVATIONS.INVOICE_DESCRIPTION'),
        period: t('RESERVATIONS.INVOICE_PERIOD'),
        nightsCol: t('RESERVATIONS.INVOICE_NIGHTS_COL'),
        unitRate: t('RESERVATIONS.INVOICE_UNIT_RATE'),
        amount: t('RESERVATIONS.INVOICE_LINE_AMOUNT'),
        dailyDetail: t('RESERVATIONS.INVOICE_DAILY_DETAIL'),
        date: t('RESERVATIONS.INVOICE_DATE'),
        extraCharges: t('RESERVATIONS.INVOICE_EXTRA_CHARGES'),
        reference: t('RESERVATIONS.INVOICE_REFERENCE'),
        paymentsReceived: t('RESERVATIONS.INVOICE_PAYMENTS_RECEIVED'),
        method: t('RESERVATIONS.INVOICE_METHOD'),
        accommodation: t('RESERVATIONS.INVOICE_ACCOMMODATION'),
        tax: t('RESERVATIONS.TAX_FEES'),
        classificationDiscount: t('RESERVATIONS.CLASSIFICATION_DISCOUNT'),
        additionalDiscount: t('RESERVATIONS.ADDITIONAL_DISCOUNT'),
        totalDiscount: t('RESERVATIONS.TOTAL_DISCOUNT'),
        discount: t('RESERVATIONS.DISCOUNT'),
        total: t('RESERVATIONS.INVOICE_TOTAL'),
        paid: t('RESERVATIONS.INVOICE_PAID'),
        balance: t('RESERVATIONS.INVOICE_BALANCE'),
        thankYou: t('RESERVATIONS.INVOICE_THANK_YOU'),
        footer: t('RESERVATIONS.INVOICE_PRINT_FOOTER'),
        currency,
        taxQr: t('RESERVATIONS.INVOICE_TAX_QR'),
        invoiceNumber: t('RESERVATIONS.INVOICE_NUMBER'),
        voucherNumber: t('RESERVATIONS.INVOICE_VOUCHER_NUMBER'),
        creditNotes: t('RESERVATIONS.INVOICE_CREDIT_NOTES'),
      },
      invoiceId: this.booking.id,
      issuedOn: this.formatDailyDate(this.today),
      guestName: this.booking.guest,
      guestPhone: this.booking.phone || '',
      guestEmail: this.booking.email || '',
      roomLabel: this.roomsDisplay !== '—' ? this.roomsDisplay : this.booking.room,
      checkIn: this.formatDailyDate(this.booking.checkIn),
      checkOut: this.formatDailyDate(this.booking.checkOut),
      nightsCount: Number(this.booking.totalDays) || 0,
      statusLabel: statusLabel !== statusKey ? statusLabel : this.booking.status,
      lineItems,
      dailyLines: this.dailyPriceLines.length > 1
        ? this.dailyPriceLines.map((line) => ({
            date: this.formatDailyDate(line.date),
            amount: line.amount,
          }))
        : [],
      extraCharges: this.posCharges
        .filter((c) => c.status === this.POS_CHARGE_STATUS_ACTIVE)
        .map((c) => ({
          date: this.formatDailyDate(c.charge_date),
          description: c.category?.name_en
            ? `${c.category.name_en}${c.description ? ' — ' + c.description : ''}`
            : (c.description || ''),
          reference: c.invoice_reference ?? null,
          amount: Number(c.amount) + Number(c.tax_amount),
        })),
      payments: (this.booking.payments ?? []).map((p) => ({
        date: p.date ? this.formatDailyDate(String(p.date).slice(0, 10)) : '—',
        method: this.translate.instant(p.method),
        amount: p.amount,
        voucherNumber: p.voucherNumber ?? null,
      })),
      accommodationSubtotal: this.accommodationSubtotal,
      tax: Number(this.booking.tax) || 0,
      classificationDiscount: Number(this.booking.classificationDiscount) || 0,
      additionalDiscount: Number(this.booking.discount) || 0,
      discount: (Number(this.booking.classificationDiscount) || 0) + (Number(this.booking.discount) || 0),
      total: Number(this.booking.amount) || 0,
      paid: this.getTotalPaid(),
      balance: this.balanceDue,
      taxQrDataUrl,
      taxInvoiceNumber: this.zatcaTaxInvoiceNumber,
      creditNotes: this.zatcaCreditNotes.map((cn) => ({
        invoiceNumber: cn.invoice_number,
        total: cn.total,
        issuedAt: cn.issued_at,
      })),
    });

    if (!opened) {
      this.dataService.showToast(this.translate.instant('COMMON.POPUP_BLOCKED'), 'warning');
    }
  }

  private buildInvoiceLineItems(): InvoicePrintLine[] {
    if (this.priceBreakdownLines.length) {
      return this.priceBreakdownLines.map((row) => ({
        description: this.breakdownKindLabel(row),
        period: this.formatBreakdownPeriod(row),
        nights: row.nights,
        unitRate: breakdownNightlyRate(row, this.dailyPriceLines),
        amount: row.amount,
      }));
    }

    if (!this.booking) {
      return [];
    }

    return [{
      description: this.translate.instant('RESERVATIONS.INVOICE_ACCOMMODATION'),
      period: `${this.formatDailyDate(this.booking.checkIn)} → ${this.formatDailyDate(this.booking.checkOut)}`,
      nights: Number(this.booking.totalDays) || 1,
      unitRate: this.accommodationSubtotal / Math.max(1, Number(this.booking.totalDays) || 1),
      amount: this.accommodationSubtotal,
    }];
  }

  private formatBreakdownPeriod(row: PriceBreakdownLine): string {
    const from = this.formatDailyDate(row.from);
    const to = this.formatDailyDate(row.to);
    return row.from === row.to ? from : `${from} → ${to}`;
  }

  openPaymentModal(): void {
    if (!this.booking) return;
    if (this.isCancelled) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.PAYMENT_NOT_ALLOWED_CANCELLED'), 'warning');
      return;
    }
    if (this.balanceDue <= 0.005) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.PAYMENT_NOT_ALLOWED_PAID'), 'warning');
      return;
    }
    this.paymentAmount = null;
    this.showPaymentModal = true;
  }

  closePaymentModal(): void {
    if (this.isSaving) return;
    this.showPaymentModal = false;
    this.paymentAmount = null;
  }

  setFullPaymentAmount(): void {
    this.paymentAmount = this.roundMoney(this.balanceDue);
  }

  get paymentAmountExceedsBalance(): boolean {
    const amount = Number(this.paymentAmount);
    return Number.isFinite(amount) && amount > 0 && amount > this.balanceDue + 0.005;
  }

  onPaymentAmountChange(): void {
    // Triggers change detection for inline validation message.
  }

  submitPayment(): void {
    if (!this.booking) return;
    if (!this.canAddPayment) {
      this.closePaymentModal();
      if (this.isCancelled) {
        this.dataService.showToast(this.translate.instant('RESERVATIONS.PAYMENT_NOT_ALLOWED_CANCELLED'), 'warning');
      } else {
        this.dataService.showToast(this.translate.instant('RESERVATIONS.PAYMENT_NOT_ALLOWED_PAID'), 'warning');
      }
      return;
    }
    const amount = Number(this.paymentAmount);
    if (!Number.isFinite(amount) || amount <= 0) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.PAYMENT_AMOUNT_INVALID'), 'error');
      return;
    }
    if (amount > this.balanceDue + 0.005) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.PAYMENT_AMOUNT_EXCEEDS_BALANCE', {
          max: this.balanceDue.toFixed(2)
        }),
        'error'
      );
      return;
    }
    this.recordPayment(this.roundMoney(amount));
  }

  private recordPayment(amount: number): void {
    if (!this.booking || amount <= 0) return;
    this.isSaving = true;
    this.hotelService.addReservationPayment(this.booking.id, amount, 0).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.PAYMENT_RECORDED'), 'success');
          this.closePaymentModal();
          this.loadBooking(this.booking!.id);
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.PAYMENT_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.PAYMENT_FAILED'), 'error');
      }
    });
  }

  openRedeemModal(): void {
    if (!this.booking) return;
    if (!this.canRedeemPoints) {
      return;
    }
    this.redeemPoints = null;
    this.showRedeemModal = true;
  }

  closeRedeemModal(): void {
    if (this.isSaving) return;
    this.showRedeemModal = false;
    this.redeemPoints = null;
  }

  setMaxRedeemPoints(): void {
    this.redeemPoints = this.maxRedeemablePoints;
  }

  onRedeemPointsChange(): void {
    // Triggers change detection for inline validation message.
  }

  submitRedeem(): void {
    if (!this.booking) return;
    const points = Number(this.redeemPoints);
    if (!Number.isInteger(points) || points <= 0) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.REDEEM_POINTS_INVALID'), 'error');
      return;
    }
    if (points > this.maxRedeemablePoints) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.REDEEM_POINTS_EXCEEDS_LIMIT', { max: this.maxRedeemablePoints }),
        'error'
      );
      return;
    }
    this.isSaving = true;
    this.hotelService.redeemLoyaltyPoints(this.booking.id, points).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.REDEEM_POINTS_SUCCESS'), 'success');
          this.closeRedeemModal();
          this.loadBooking(this.booking!.id);
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('RESERVATIONS.REDEEM_POINTS_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('RESERVATIONS.REDEEM_POINTS_FAILED'), 'error');
      }
    });
  }

  private roundMoney(value: number): number {
    return Math.round(value * 100) / 100;
  }

  private applyMutationResponse(res: any, fallbackReload = true): void {
    if (this.applyReservationPayload(res)) {
      this.dataService.showToast(this.translate.instant('RESERVATIONS.UPDATED_OK'), 'success');
      return;
    }
    if (fallbackReload && this.booking?.id) {
      this.loadBooking(this.booking.id, { softReload: true });
    }
  }

  private patchReservation(data: Record<string, unknown>): void {
    if (!this.booking) return;
    this.isSaving = true;
    this.hotelService.updateReservation(this.booking.id, data).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.applyMutationResponse(res);
        } else {
          this.showUpdateError(res?.message);
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.showUpdateError(err.error?.message);
      }
    });
  }

  private showUpdateError(message?: string): void {
    if (message && /outstanding balance/i.test(message)) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.CHECKOUT_REQUIRES_PAYMENT', {
          amount: this.balanceDue.toFixed(2)
        }),
        'warning'
      );
      return;
    }
    this.dataService.showToast(message || this.translate.instant('RESERVATIONS.UPDATE_FAILED'), 'error');
  }

  private mapPaymentStatus(total: number, paidNet: number): 'pending' | 'paid' | 'partially-paid' {
    const balance = Math.max(0, total - paidNet);
    if (balance <= 0.005) return 'paid';
    if (paidNet > 0) return 'partially-paid';
    return 'pending';
  }

  private mapStatus(status: number, logedin: number, expireDate: string): BookingStatus {
    if (status === 3) return 'cancelled';
    if (status === 1 && logedin === 1) return 'checked-in';
    if (status === 1 && logedin === 0) {
      const expire = this.normalizeDate(expireDate);
      if (expire && expire <= this.today) return 'checked-out';
      return 'confirmed';
    }
    return 'confirmed';
  }

  getStatusBadgeClass(status: string): string {
    switch (status) {
      case 'checked-in': return 'bg-success-subtle text-success';
      case 'confirmed': return 'bg-primary-subtle text-primary';
      case 'checked-out': return 'bg-secondary-subtle text-secondary';
      default: return 'bg-warning-subtle text-warning';
    }
  }

  formatDailyDate(value: string): string {
    if (!value) return '';
    const day = value.length >= 10 ? value.slice(0, 10) : value;
    return new Date(day + 'T12:00:00').toLocaleDateString(
      this.isRtl ? 'ar-SA' : 'en-GB',
      { year: 'numeric', month: 'short', day: 'numeric' }
    );
  }

  isBreakdownRowExpandable(row: PriceBreakdownLine): boolean {
    return breakdownRowExpandable(this.dailyPriceLines, row);
  }

  dailyLinesForBreakdownRow(row: PriceBreakdownLine): DailyPriceLine[] {
    return dailyLinesForPeriod(this.dailyPriceLines, row);
  }

  breakdownRowDisplayAmount(row: PriceBreakdownLine): number {
    return breakdownRowAmount(row, this.dailyPriceLines);
  }

  formatBreakdownLabel(row: PriceBreakdownLine): string {
    const from = this.formatDailyDate(row.from);
    const to = this.formatDailyDate(row.to);
    const kindLabel = this.breakdownKindLabel(row);
    const nightlyRate = breakdownNightlyRate(row, this.dailyPriceLines);
    const rateLabel = this.translate.instant('RESERVATIONS.BREAKDOWN_NIGHTLY_RATE', {
      rate: nightlyRate,
      currency: this.translate.instant('COMMON.CURRENCY_SYMBOL'),
    });

    if (row.from === row.to) {
      return `${from} (${kindLabel}) ${rateLabel}`;
    }
    const period = this.translate.instant('RESERVATIONS.BREAKDOWN_PERIOD', { from, to, kind: kindLabel });
    return `${period} ${rateLabel}`;
  }

  private breakdownKindLabel(row: PriceBreakdownLine): string {
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

  goBack(): void {
    this.router.navigate(['/booking/reservations']);
  }

  openMoreActions(): void {
    this.showMoreActions = true;
  }

  closeMoreActions(): void {
    this.showMoreActions = false;
  }

  runMoreAction(action: 'roomBoard' | 'payment' | 'refund' | 'print'): void {
    this.showMoreActions = false;
    if (action === 'roomBoard') {
      this.openRoomBoard();
      return;
    }
    if (action === 'payment') {
      this.openPaymentModal();
      return;
    }
    if (action === 'refund') {
      this.openRefundModal();
      return;
    }
    void this.printInvoice();
  }

  openRoomBoard(): void {
    const queryParams: Record<string, string> = {};
    if (this.roomNumbersNeedingCleaning.length > 0) {
      queryParams['status'] = 'preparation';
      queryParams['search'] = this.roomNumbersNeedingCleaning[0];
    }
    this.router.navigate(['/dashboard/room-board'], { queryParams });
  }

  openRoomBoardForRoom(roomNumber: string): void {
    this.router.navigate(['/dashboard/room-board'], {
      queryParams: {
        status: 'preparation',
        search: roomNumber,
      },
    });
  }

  getRoomStatusLabel(status: number): string {
    switch (status) {
      case 2:
        return this.translate.instant('RESERVATIONS.ROOM_STATUS_OCCUPIED');
      case 3:
        return this.translate.instant('RESERVATIONS.ROOM_STATUS_PREPARATION');
      case 4:
        return this.translate.instant('RESERVATIONS.ROOM_STATUS_OUT_OF_SERVICE');
      default:
        return this.translate.instant('RESERVATIONS.ROOM_STATUS_AVAILABLE');
    }
  }

  getRoomStatusBadgeClass(status: number): string {
    switch (status) {
      case 2:
        return 'bg-primary-subtle text-primary';
      case 3:
        return 'bg-warning-subtle text-warning-emphasis';
      case 4:
        return 'bg-secondary-subtle text-secondary';
      default:
        return 'bg-success-subtle text-success';
    }
  }

  getTotalPaid(): number {
    if (Number.isFinite(this.paidAmount)) {
      return this.paidAmount;
    }

    return this.booking?.payments?.reduce((sum, payment) => {
      const amount = Number(payment.amount) || 0;
      return sum + (payment.isRefund ? -amount : amount);
    }, 0) ?? 0;
  }

  getPaymentStatusBadgeClass(status: string | undefined): string {
    if (!status) return 'bg-secondary-subtle text-secondary';
    switch (status) {
      case 'paid': return 'bg-success-subtle text-success';
      case 'partial': return 'bg-warning-subtle text-warning';
      default: return 'bg-danger-subtle text-danger';
    }
  }

  getPaymentStatusLabel(status: string | undefined): string {
    if (!status) return '';
    if (status === 'paid') return this.translate.instant('RESERVATIONS.PAY_STATUS_PAID');
    if (status === 'partially-paid') return this.translate.instant('RESERVATIONS.PAY_STATUS_PARTIAL');
    return this.translate.instant('RESERVATIONS.PAY_STATUS_PENDING');
  }

  private normalizeDate(value: string | null | undefined): string {
    if (!value) return '';
    return value.length >= 10 ? value.slice(0, 10) : value;
  }

  /** Parse charge_date for display (avoids timezone shift on date-only values). */
  toChargeDate(value: string | null | undefined): Date | null {
    const day = this.normalizeDate(value);
    if (!day) return null;
    return new Date(day + 'T12:00:00');
  }

  private addDays(isoDate: string, days: number): string {
    const d = new Date(isoDate + 'T12:00:00');
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
  }
}
