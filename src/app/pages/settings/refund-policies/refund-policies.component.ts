import { Component, OnInit, OnDestroy, ChangeDetectorRef, HostListener } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { RefundPaymentStatus, RefundPolicy, RefundThresholdMode } from '../../../models/refund-policy.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';
import { Subscription } from 'rxjs';

@Component({
    selector: 'app-refund-policies',
    templateUrl: './refund-policies.component.html',
    styleUrls: ['./refund-policies.component.css'],
    standalone: false
})
export class RefundPoliciesComponent implements OnInit, OnDestroy {
  policies: RefundPolicy[] = [];
  isLoading = false;
  showModal = false;
  isEditMode = false;
  isSaving = false;
  searchTerm = '';
  timingFilter: 'all' | 'before_start' | 'after_start' = 'all';
  private langSub?: Subscription;

  readonly paymentStatusOptions: RefundPaymentStatus[] = ['partial', 'full', 'paid'];

  currentPolicy: RefundPolicy = this.emptyPolicy();

  get beforeCount(): number {
    return this.policies.filter((p) => (p.timing ?? 'before_start') !== 'after_start').length;
  }

  get duringCount(): number {
    return this.policies.filter((p) => p.timing === 'after_start').length;
  }

  get filteredPolicies(): RefundPolicy[] {
    const q = this.searchTerm.trim().toLowerCase();
    return this.policies.filter((p) => {
      const timing = p.timing ?? 'before_start';
      const timingOk =
        this.timingFilter === 'all' ||
        (this.timingFilter === 'before_start' && timing !== 'after_start') ||
        (this.timingFilter === 'after_start' && timing === 'after_start');
      if (!timingOk) {
        return false;
      }
      if (!q) {
        return true;
      }
      return (
        (p.name || '').toLowerCase().includes(q) ||
        this.rentTypeLabel(p.rent_type).toLowerCase().includes(q) ||
        this.basisLabel(p.refund_basis).toLowerCase().includes(q) ||
        this.paymentLabels(p).toLowerCase().includes(q)
      );
    });
  }

  clearFilters(): void {
    this.searchTerm = '';
    this.timingFilter = 'all';
  }

  isBeforeStart(policy: RefundPolicy): boolean {
    return (policy.timing ?? 'before_start') !== 'after_start';
  }

  isFixedThreshold(policy: RefundPolicy): boolean {
    return this.normalizeThresholdMode(policy) === 'fixed_days';
  }

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
    private confirmDialog: ConfirmDialogService,
    private cdr: ChangeDetectorRef
  ) {}

  ngOnInit(): void {
    this.loadPolicies();
    this.langSub = this.translate.onLangChange.subscribe(() => this.cdr.markForCheck());
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
    this.lockBodyScroll(false);
  }

  loadPolicies(): void {
    this.isLoading = true;
    this.hotelService.fetchRefundPolicies().subscribe({
      next: (res) => {
        const payload = res?.data;
        this.policies = (Array.isArray(payload) ? payload : (payload?.data ?? []))
          .map((policy: RefundPolicy) => this.normalizePolicy(policy));
        this.isLoading = false;
        this.cdr.markForCheck();
      },
      error: () => {
        this.isLoading = false;
        this.cdr.markForCheck();
      }
    });
  }

  openAddModal(): void {
    this.isEditMode = false;
    this.currentPolicy = this.emptyPolicy();
    this.showModal = true;
    this.lockBodyScroll(true);
    this.cdr.markForCheck();
  }

  openEditModal(policy: RefundPolicy): void {
    this.isEditMode = true;
    this.currentPolicy = this.normalizePolicy(policy);
    this.showModal = true;
    this.lockBodyScroll(true);
    this.cdr.markForCheck();
  }

  get isPercentThresholdMode(): boolean {
    return this.normalizeThresholdMode(this.currentPolicy) === 'percent_of_stay';
  }

  setTiePriority(priority: 0 | 1): void {
    this.currentPolicy.priority = priority;
    this.cdr.markForCheck();
  }

  closeModal(): void {
    if (this.isSaving) {
      return;
    }
    this.showModal = false;
    this.lockBodyScroll(false);
  }

  @HostListener('document:keydown.escape')
  onEscapeKey(): void {
    if (this.showModal) {
      this.closeModal();
    }
  }

  get canSave(): boolean {
    const name = String(this.currentPolicy.name ?? '').trim();
    if (!name) {
      return false;
    }
    const refundPct = Number(this.currentPolicy.refund_percent);
    if (!Number.isFinite(refundPct) || refundPct < 0 || refundPct > 100) {
      return false;
    }
    const mode = this.currentPolicy.threshold_mode ?? 'fixed_days';
    if (mode === 'fixed_days') {
      const days = Number(this.currentPolicy.days_threshold);
      return Number.isFinite(days) && days >= 0;
    }
    const stayPct = Number(this.currentPolicy.threshold_percent);
    return Number.isFinite(stayPct) && stayPct >= 0 && stayPct <= 100;
  }

  get policyPreviewLines(): string[] {
    const name = String(this.currentPolicy.name ?? '').trim();
    if (!name) {
      return [];
    }

    const lines = [
      this.translate.instant('REFUND_POLICIES.PREVIEW_NAME', { name }),
      this.translate.instant('REFUND_POLICIES.PREVIEW_RENT', { type: this.rentTypeLabel(this.currentPolicy.rent_type) }),
      this.translate.instant('REFUND_POLICIES.PREVIEW_TIMING', { timing: this.timingLabel(this.currentPolicy.timing) }),
      this.translate.instant('REFUND_POLICIES.PREVIEW_THRESHOLD', { threshold: this.thresholdLabel(this.currentPolicy) }),
      this.translate.instant('REFUND_POLICIES.PREVIEW_REFUND', {
        percent: this.currentPolicy.refund_percent,
        basis: this.basisLabel(this.currentPolicy.refund_basis),
      }),
      this.translate.instant('REFUND_POLICIES.PREVIEW_PAYMENT', {
        status: this.paymentLabels(this.currentPolicy),
      }),
    ];

    return lines;
  }

  setThresholdMode(mode: RefundThresholdMode): void {
    this.currentPolicy.threshold_mode = mode;
    if (mode === 'fixed_days') {
      this.currentPolicy.priority = 0;
      if (this.currentPolicy.days_threshold == null || this.currentPolicy.days_threshold < 0) {
        this.currentPolicy.days_threshold = 7;
      }
    }
    if (mode === 'percent_of_stay' && (this.currentPolicy.threshold_percent == null || this.currentPolicy.threshold_percent < 0)) {
      this.currentPolicy.threshold_percent = 50;
    }
    if (mode === 'percent_of_stay' && this.currentPolicy.priority == null) {
      this.currentPolicy.priority = 0;
    }
    this.cdr.markForCheck();
  }

  hasTiePriority(policy: RefundPolicy): boolean {
    return this.normalizeThresholdMode(policy) === 'percent_of_stay' && Number(policy.priority ?? 0) > 0;
  }

  savePolicy(): void {
    if (!this.canSave || this.isSaving) {
      return;
    }
    this.isSaving = true;
    const payload: RefundPolicy = {
      ...this.currentPolicy,
      payment_statuses: this.resolvePaymentStatuses(this.currentPolicy),
      payment_status: this.deriveLegacyPaymentStatus(this.resolvePaymentStatuses(this.currentPolicy)),
      priority: this.isPercentThresholdMode ? Number(this.currentPolicy.priority ?? 0) : 0,
    };

    const request = this.isEditMode
      ? this.hotelService.updateRefundPolicy(payload)
      : this.hotelService.createRefundPolicy(payload);

    request.subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success) {
          this.closeModal();
          this.loadPolicies();
          this.dataService.showToast(
            this.translate.instant(this.isEditMode ? 'REFUND_POLICIES.UPDATED' : 'REFUND_POLICIES.CREATED'),
            'success'
          );
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: '' }), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: '' }), 'error');
      }
    });
  }

  async deletePolicy(id: number | string): Promise<void> {
    const ok = await this.confirmDialog.confirm(
      this.translate.instant('MESSAGES.CONFIRM_DELETE', { item: this.translate.instant('REFUND_POLICIES.ITEM') }),
      {
        title: this.translate.instant('COMMON.CONFIRM_DELETE_TITLE'),
        confirmText: this.translate.instant('COMMON.DELETE'),
        cancelText: this.translate.instant('COMMON.CANCEL'),
        variant: 'danger',
      }
    );
    if (!ok) return;

    this.hotelService.deleteRefundPolicy(id).subscribe({
      next: (res) => {
        if (res?.success) {
          this.loadPolicies();
          this.dataService.showToast(this.translate.instant('MESSAGES.DELETE_SUCCESS', { item: this.translate.instant('REFUND_POLICIES.ITEM') }), 'success');
        } else {
          this.dataService.showToast(res?.message || '', 'error');
        }
      }
    });
  }

  isPaymentStatusSelected(status: RefundPaymentStatus): boolean {
    return this.resolvePaymentStatuses(this.currentPolicy).includes(status);
  }

  togglePaymentStatus(status: RefundPaymentStatus): void {
    const current = this.resolvePaymentStatuses(this.currentPolicy);
    const idx = current.indexOf(status);
    if (idx >= 0) {
      current.splice(idx, 1);
    } else {
      current.push(status);
    }

    this.currentPolicy.payment_statuses = current.length ? [...current] : null;
    this.currentPolicy.payment_status = this.deriveLegacyPaymentStatus(current);
  }

  resolvePaymentStatuses(policy: RefundPolicy): RefundPaymentStatus[] {
    const allowed = new Set<RefundPaymentStatus>(this.paymentStatusOptions);

    if (policy.payment_statuses?.length) {
      return policy.payment_statuses.filter((status) => allowed.has(status));
    }
    if (policy.payment_status === 1) return ['partial'];
    if (policy.payment_status === 2) return ['full'];
    return [];
  }

  paymentLabels(policy: RefundPolicy): string {
    const statuses = this.resolvePaymentStatuses(policy);
    if (!statuses.length) {
      return this.translate.instant('REFUND_POLICIES.PAY_ANY');
    }
    return statuses.map((status) => this.paymentStatusLabel(status)).join(' · ');
  }

  paymentStatusLabel(status: RefundPaymentStatus): string {
    const keyMap: Record<RefundPaymentStatus, string> = {
      partial: 'REFUND_POLICIES.PAY_PARTIAL',
      full: 'REFUND_POLICIES.PAY_FULL',
      paid: 'REFUND_POLICIES.PAY_PAID',
    };
    return this.translate.instant(keyMap[status]);
  }

  rentTypeLabel(rt: number | null): string {
    if (rt === 0) return this.translate.instant('REFUND_POLICIES.RENT_DAILY');
    if (rt === 1) return this.translate.instant('REFUND_POLICIES.RENT_MONTHLY');
    if (rt === 2) return this.translate.instant('REFUND_POLICIES.RENT_ANNUAL');
    return this.translate.instant('REFUND_POLICIES.RENT_ALL');
  }

  timingLabel(t: string): string {
    return t === 'after_start'
      ? this.translate.instant('REFUND_POLICIES.AFTER_START')
      : this.translate.instant('REFUND_POLICIES.BEFORE_START');
  }

  thresholdLabel(policy: RefundPolicy): string {
    const mode = this.normalizeThresholdMode(policy);
    if (mode === 'percent_of_stay') {
      const pct = policy.threshold_percent ?? 0;
      return this.translate.instant('REFUND_POLICIES.THRESHOLD_PERCENT_VALUE', { percent: pct });
    }
    return String(policy.days_threshold ?? 0);
  }

  basisLabel(b: string): string {
    const key = `REFUND_POLICIES.BASIS_${b.toUpperCase()}`;
    const t = this.translate.instant(key);
    return t !== key ? t : b;
  }

  private deriveLegacyPaymentStatus(statuses: RefundPaymentStatus[]): 1 | 2 | null {
    if (statuses.length !== 1) {
      return null;
    }
    if (statuses[0] === 'partial') return 1;
    if (statuses[0] === 'full') return 2;
    return null;
  }

  private normalizePolicy(policy: RefundPolicy): RefundPolicy {
    const threshold_mode = this.normalizeThresholdMode(policy);
    const timing = policy.timing
      ?? (Number(policy.during_stay) === 1 ? 'after_start' : 'before_start');

    return {
      ...policy,
      threshold_mode,
      timing,
      priority: Number(policy.priority ?? 0),
      payment_statuses: this.resolvePaymentStatuses(policy),
    };
  }

  private normalizeThresholdMode(policy: RefundPolicy): RefundThresholdMode {
    if (policy.threshold_mode === 'percent_of_stay' || policy.threshold_mode === 'fixed_days') {
      return policy.threshold_mode;
    }

    return Number(policy.threshold_percent) > 0 ? 'percent_of_stay' : 'fixed_days';
  }

  private emptyPolicy(): RefundPolicy {
    return {
      id: '',
      name: '',
      rent_type: null,
      timing: 'before_start',
      threshold_mode: 'fixed_days',
      threshold_percent: 50,
      days_threshold: 7,
      refund_percent: 50,
      refund_basis: 'total',
      payment_status: null,
      payment_statuses: null,
      priority: 0,
    };
  }

  private lockBodyScroll(lock: boolean): void {
    if (typeof document === 'undefined') {
      return;
    }
    document.body.style.overflow = lock ? 'hidden' : '';
  }
}
