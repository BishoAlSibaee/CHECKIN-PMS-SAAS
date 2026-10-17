import { Component, OnInit, OnDestroy } from '@angular/core';
import { HotelService } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { RoomType } from '../../models/room-type.interface';
import { PricingPlan } from '../../models/pricing-plan.interface';
import { DynamicPricingRule, DynamicPricingMetric } from '../../models/dynamic-pricing-rule.interface';
import { Subscription, combineLatest, forkJoin, of } from 'rxjs';
import { catchError, finalize } from 'rxjs/operators';
import { TranslateService } from '@ngx-translate/core';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';

type PricingTab = 'plans' | 'room-types';
type PlanStatus = 'active' | 'draft' | 'expired' | 'inactive';

@Component({
    selector: 'app-pricing',
    templateUrl: './pricing.component.html',
    styleUrls: ['./pricing.component.css'],
    standalone: false
})
export class PricingComponent implements OnInit, OnDestroy {
  activeTab: PricingTab = 'plans';
  search = '';
  statusFilter: 'all' | PlanStatus = 'all';
  selectedPlan: PricingPlan | null = null;
  selectedType: RoomType | null = null;
  isLoading = false;
  currentLang: string;
  private subscription = new Subscription();

  roomTypes: RoomType[] = [];
  pricingPlans: PricingPlan[] = [];

  dynamicPricingRules: DynamicPricingRule[] = [];
  isLoadingDynamicRules = false;
  isSavingDynamicRule = false;
  newDynamicRule: { metric: DynamicPricingMetric; range_from: number | null; range_to: number | null; adjustment_percent: number | null } = {
    metric: 'occupancy',
    range_from: null,
    range_to: null,
    adjustment_percent: null
  };

  // Modals
  showRoomTypeModal = false;
  showPricingPlanModal = false;
  isSaving = false;
  isEditMode = false;

  roomTypeSubmitted = false;
  pricingPlanSubmitted = false;
  roomTypeErrors: Record<string, string> = {};
  pricingPlanErrors: Record<string, string> = {};

  newRoomType: any = {
    id: '',
    name_ar: '',
    name_en: '',
    description: '',
    Min_daily_price: 0,
    Max_daily_price: 0,
    Min_monthly_price: 0,
    Max_monthly_price: 0,
    active_type: 0,
    dynamic_pricing_enabled: false
  };

  newPricingPlan: any = {
    id: '',
    pricingplan_id: '',
    NameAr: '',
    NameEn: '',
    StartDate: '',
    EndDate: '',
    ActiveType: 1,
    roomtype_id: '',
    DailyPrice: 0,
    MonthlyPrice: 0
  };

  constructor(
    private hotelService: HotelService,
    private dataService: DataService,
    private translate: TranslateService,
    private confirmDialog: ConfirmDialogService,
    public authPerm: AuthPermissionService
  ) {
    this.currentLang = this.translate.currentLang || 'en';
    this.subscription.add(
      this.translate.onLangChange.subscribe(event => {
        this.currentLang = event.lang;
      })
    );
  }

  ngOnInit(): void {
    this.subscription.add(
      combineLatest([
        this.hotelService.roomTypes$,
        this.hotelService.pricingPlans$
      ]).subscribe(([types, plans]) => {
        this.roomTypes = types;
        this.pricingPlans = plans;

        if (!this.isLoading) {
          if (!this.hotelService.isRoomTypesLoaded() || !this.hotelService.isPricingPlansLoaded()) {
            this.loadData();
          }
        }
      })
    );
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  loadData(): void {
    this.isLoading = true;
    forkJoin([
      this.hotelService.isRoomTypesLoaded()
        ? of(null)
        : this.hotelService.fetchRoomTypes().pipe(catchError(() => of(null))),
      this.hotelService.isPricingPlansLoaded()
        ? of(null)
        : this.hotelService.fetchPricingPlans().pipe(catchError(() => of(null))),
    ]).pipe(
      finalize(() => {
        this.isLoading = false;
      })
    ).subscribe();
  }

  refresh(): void {
    this.isLoading = true;
    forkJoin([
      this.hotelService.fetchRoomTypes().pipe(catchError(() => of(null))),
      this.hotelService.fetchPricingPlans().pipe(catchError(() => of(null))),
    ]).pipe(
      finalize(() => {
        this.isLoading = false;
      })
    ).subscribe();
  }

  get canManageRoomTypes(): boolean {
    return this.authPerm.has('manage room types');
  }

  get canManagePricingPlans(): boolean {
    return this.authPerm.has('manage pricing plans');
  }

  get canCreateOnActiveTab(): boolean {
    return this.activeTab === 'room-types' ? this.canManageRoomTypes : this.canManagePricingPlans;
  }

  get primaryActionLabel(): string {
    return this.activeTab === 'room-types' ? 'PRICING.NEW_ROOM_TYPE' : 'PRICING.NEW_PRICING_PLAN';
  }

  get draftPlansCount(): number {
    return this.pricingPlans.filter((p) => p.status === 'draft').length;
  }

  get expiredPlansCount(): number {
    return this.pricingPlans.filter((p) => p.status === 'expired' || this.isPlanExpired(p)).length;
  }

  clearFilters(): void {
    this.search = '';
    this.statusFilter = 'all';
  }

  statusLabelKey(status: string | null | undefined): string {
    switch (status) {
      case 'active':
        return 'PRICING.FILTERS.ACTIVE';
      case 'draft':
        return 'PRICING.FILTERS.DRAFT';
      case 'expired':
        return 'PRICING.FILTERS.EXPIRED';
      case 'inactive':
        return 'PRICING.FILTERS.INACTIVE';
      default:
        return 'COMMON.UNKNOWN';
    }
  }

  displayPlanName(plan: PricingPlan): string {
    return this.currentLang === 'ar'
      ? (plan.name_ar || plan.name || '')
      : (plan.name || plan.name_ar || '');
  }

  displayTypeName(type: RoomType | null | undefined): string {
    if (!type) {
      return '';
    }
    return this.currentLang === 'ar'
      ? (type.name_ar || type.name || '')
      : (type.name || type.name_ar || '');
  }

  openAddModal() {
    this.isEditMode = false;
    if (this.activeTab === 'room-types') {
      this.resetRoomTypeFormState();
      this.showRoomTypeModal = true;
      this.newRoomType = {
        id: '',
        name_ar: '',
        name_en: '',
        description: '',
        Min_daily_price: 0,
        Max_daily_price: 0,
        Min_monthly_price: 0,
        Max_monthly_price: 0,
        active_type: 0,
        dynamic_pricing_enabled: false
      };
    } else {
      this.resetPricingPlanFormState();
      this.showPricingPlanModal = true;
      this.newPricingPlan = {
        id: '',
        pricingplan_id: '',
        NameAr: '',
        NameEn: '',
        StartDate: '',
        EndDate: '',
        ActiveType: 1,
        roomtype_id: this.roomTypes.length > 0 ? this.roomTypes[0].id : '',
        DailyPrice: 0,
        MonthlyPrice: 0
      };
    }
  }

  selectRoomType(type: RoomType): void {
    this.selectedType = type;
    this.selectedPlan = null;
    this.dynamicPricingRules = [];
    this.resetDynamicRuleForm();
    if (type.dynamic_pricing_enabled) {
      this.loadDynamicPricingRules(type.id);
    }
  }

  loadDynamicPricingRules(roomTypeId: string): void {
    this.isLoadingDynamicRules = true;
    this.hotelService.fetchDynamicPricingRules(roomTypeId).subscribe({
      next: (res) => {
        this.isLoadingDynamicRules = false;
        this.dynamicPricingRules = res.success ? (res.data ?? []) : [];
      },
      error: () => {
        this.isLoadingDynamicRules = false;
        this.dynamicPricingRules = [];
      }
    });
  }

  rulesForMetric(metric: DynamicPricingMetric): DynamicPricingRule[] {
    return this.dynamicPricingRules.filter(r => r.metric === metric);
  }

  resetDynamicRuleForm(): void {
    this.newDynamicRule = { metric: 'occupancy', range_from: null, range_to: null, adjustment_percent: null };
  }

  addDynamicRule(): void {
    if (!this.selectedType || this.newDynamicRule.range_from === null || this.newDynamicRule.adjustment_percent === null || this.isSavingDynamicRule) {
      return;
    }

    this.isSavingDynamicRule = true;
    this.hotelService.addDynamicPricingRule(this.selectedType.id, this.newDynamicRule).subscribe({
      next: (res) => {
        this.isSavingDynamicRule = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('PRICING.DYNAMIC.RULE_SAVED'), 'success');
          this.resetDynamicRuleForm();
          this.loadDynamicPricingRules(this.selectedType!.id);
        } else {
          this.dataService.showToast(res.message || this.translate.instant('PRICING.DYNAMIC.RULE_SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSavingDynamicRule = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('PRICING.DYNAMIC.RULE_SAVE_ERROR'), 'error');
      }
    });
  }

  deleteDynamicRule(rule: DynamicPricingRule): void {
    this.hotelService.deleteDynamicPricingRule(rule.id).subscribe({
      next: (res) => {
        if (res.success) {
          this.dynamicPricingRules = this.dynamicPricingRules.filter(r => r.id !== rule.id);
        } else {
          this.dataService.showToast(res.message || this.translate.instant('PRICING.DYNAMIC.RULE_DELETE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.dataService.showToast(err.error?.message || this.translate.instant('PRICING.DYNAMIC.RULE_DELETE_ERROR'), 'error');
      }
    });
  }

  openEditRoomType(type: RoomType) {
    this.isEditMode = true;
    this.resetRoomTypeFormState();
    this.showRoomTypeModal = true;
    this.newRoomType = {
      id: type.id,
      name_ar: type.name_ar || '',
      name_en: type.name_en || type.name,
      description: type.description || '',
      Min_daily_price: type.minDailyRate,
      Max_daily_price: type.maxDailyRate,
      Min_monthly_price: type.minMonthlyRate,
      Max_monthly_price: type.maxMonthlyRate,
      active_type: Number(type.active_type ?? 1),
      dynamic_pricing_enabled: !!type.dynamic_pricing_enabled
    };
  }

  openEditPricingPlan(plan: PricingPlan) {
    if (this.isPlanExpired(plan)) {
      this.dataService.showToast(this.translate.instant('PRICING.MESSAGES.PLAN_EXPIRED_READONLY'), 'warning');
      this.selectedPlan = plan;
      this.selectedType = null;
      return;
    }
    this.isEditMode = true;
    this.resetPricingPlanFormState();
    this.showPricingPlanModal = true;
    this.newPricingPlan = {
      id: plan.id,
      pricingplan_id: plan.pricingplan_id || '',
      NameAr: plan.name_ar || '',
      NameEn: plan.name_en || plan.name,
      StartDate: plan.startDate,
      EndDate: plan.endDate,
      ActiveType: Number(plan.activeType ?? 1),
      roomtype_id: plan.roomtype_id || (plan.linkedRoomTypes?.length ? plan.linkedRoomTypes[0] : ''),
      DailyPrice: plan.dailyPrice || 0,
      MonthlyPrice: plan.monthlyPrice || 0
    };
  }

  saveRoomType() {
    this.roomTypeSubmitted = true;
    if (!this.validateRoomType()) {
      this.dataService.showToast(this.translate.instant('PRICING.VALIDATION.FIX_FIELDS'), 'warning');
      return;
    }

    this.isSaving = true;
    const request = this.isEditMode
      ? this.hotelService.updateRoomType(this.newRoomType)
      : this.hotelService.addRoomType(this.newRoomType);

    request.subscribe({
      next: (res) => {
        if (res.success || res.result === 'success') {
          this.showRoomTypeModal = false;
          this.resetRoomTypeFormState();
          this.dataService.showToast(this.translate.instant('PRICING.VALIDATION.SAVE_SUCCESS_TYPE'), 'success');
        } else {
          this.dataService.showToast(
            res.message || this.translate.instant('MESSAGES.SAVE_ERROR_WITH_MSG', {
              item: this.translate.instant('MESSAGES.ITEM.ROOM_TYPE'),
              message: res.message
            }),
            'error'
          );
        }
        this.isSaving = false;
      },
      error: (err) => {
        this.dataService.showToast(
          err?.error?.message || this.translate.instant('MESSAGES.SAVE_ERROR', {
            item: this.translate.instant('MESSAGES.ITEM.ROOM_TYPE')
          }),
          'error'
        );
        this.isSaving = false;
      }
    });
  }

  savePricingPlan() {
    this.pricingPlanSubmitted = true;
    if (!this.validatePricingPlan()) {
      this.dataService.showToast(this.translate.instant('PRICING.VALIDATION.FIX_FIELDS'), 'warning');
      return;
    }

    if (this.isEditMode && this.isPlanExpiredByDates(this.newPricingPlan.EndDate)) {
      this.dataService.showToast(this.translate.instant('PRICING.MESSAGES.PLAN_EXPIRED_READONLY'), 'warning');
      return;
    }

    this.isSaving = true;
    const request = this.isEditMode
      ? this.hotelService.updateRoomTypePricing(this.newPricingPlan)
      : this.hotelService.addRoomTypePricing(this.newPricingPlan);

    request.subscribe({
      next: (res) => {
        if (res.success || res.result === 'success') {
          this.showPricingPlanModal = false;
          this.resetPricingPlanFormState();
          this.dataService.showToast(this.translate.instant('PRICING.VALIDATION.SAVE_SUCCESS_PLAN'), 'success');
        } else {
          this.dataService.showToast(
            res.message || this.translate.instant('MESSAGES.SAVE_ERROR_WITH_MSG', {
              item: this.translate.instant('MESSAGES.ITEM.PRICING_PLAN'),
              message: res.message
            }),
            'error'
          );
        }
        this.isSaving = false;
      },
      error: (err) => {
        const message = err?.error?.message || this.translate.instant('MESSAGES.SAVE_ERROR', {
          item: this.translate.instant('MESSAGES.ITEM.PRICING_PLAN')
        });
        this.dataService.showToast(message, 'error');
        this.isSaving = false;
      }
    });
  }

  async deleteRoomType(id: string): Promise<void> {
    const ok = await this.confirmDialog.confirm(this.translate.instant('PRICING.MESSAGES.DELETE_ROOM_TYPE_CONFIRM'));
    if (ok) {
      this.hotelService.deleteRoomType(id).subscribe(res => {
        if (res.success) {
          this.selectedType = null;
        } else {
          this.dataService.showToast(this.translate.instant('MESSAGES.DELETE_ERROR_WITH_MSG', {
            item: this.translate.instant('MESSAGES.ITEM.ROOM_TYPE'),
            message: res.message
          }), 'error');
        }
      });
    }
  }

  async deletePricingPlan(id: string): Promise<void> {
    const plan = this.pricingPlans.find(p => p.id === id);
    if (plan && this.isPlanExpired(plan)) {
      this.dataService.showToast(this.translate.instant('PRICING.MESSAGES.PLAN_EXPIRED_READONLY'), 'warning');
      return;
    }
    const ok = await this.confirmDialog.confirm(this.translate.instant('PRICING.MESSAGES.DELETE_PRICING_PLAN_CONFIRM'));
    if (ok) {
      this.hotelService.deleteRoomTypePricing(id).subscribe(res => {
        if (res.success) {
          this.selectedPlan = null;
        } else {
          this.dataService.showToast(this.translate.instant('MESSAGES.DELETE_ERROR_WITH_MSG', {
            item: this.translate.instant('MESSAGES.ITEM.PRICING_PLAN'),
            message: res.message
          }), 'error');
        }
      });
    }
  }

  setTab(tab: PricingTab): void {
    this.activeTab = tab;
    this.clearFilters();
    this.selectedPlan = null;
    this.selectedType = null;
  }

  get activePlansCount(): number {
    return this.pricingPlans.filter((p) => p.status === 'active').length;
  }

  get bestDiscount(): number {
    const activeDiscounts = this.pricingPlans.filter((p) => p.status === 'active').map((p) => p.discount);
    return activeDiscounts.length > 0 ? Math.max(0, ...activeDiscounts) : 0;
  }

  get avgBaseRate(): number {
    if (this.roomTypes.length === 0) return 0;
    const total = this.roomTypes.reduce((sum, room) => sum + room.baseRate, 0);
    return Math.round(total / this.roomTypes.length);
  }

  get filteredPlans(): PricingPlan[] {
    const q = this.search.trim().toLowerCase();
    const plans = this.pricingPlans.filter((plan) => {
      const inStatus = this.statusFilter === 'all' || plan.status === this.statusFilter;
      if (!inStatus) {
        return false;
      }
      if (!q) {
        return true;
      }
      return (
        (plan.name || '').toLowerCase().includes(q) ||
        (plan.name_ar || '').toLowerCase().includes(q) ||
        (plan.description || '').toLowerCase().includes(q)
      );
    });

    return [...plans].sort((a, b) => this.comparePricingPlans(a, b));
  }

  /** active → draft → expired; within each group, closest dates first. */
  private comparePricingPlans(a: PricingPlan, b: PricingPlan): number {
    const statusDiff = this.planStatusSortOrder(a.status) - this.planStatusSortOrder(b.status);
    if (statusDiff !== 0) {
      return statusDiff;
    }

    if (a.status === 'active') {
      return this.isoDate(a.endDate).localeCompare(this.isoDate(b.endDate));
    }
    if (a.status === 'draft') {
      return this.isoDate(a.startDate).localeCompare(this.isoDate(b.startDate));
    }
    if (a.status === 'expired') {
      return this.isoDate(b.endDate).localeCompare(this.isoDate(a.endDate));
    }

    return (a.name || '').localeCompare(b.name || '', undefined, { numeric: true });
  }

  private planStatusSortOrder(status: PlanStatus): number {
    switch (status) {
      case 'active':
        return 0;
      case 'draft':
        return 1;
      case 'expired':
        return 2;
      default:
        return 3;
    }
  }

  private isoDate(value?: string): string {
    return (value ?? '').slice(0, 10);
  }

  get filteredTypes(): RoomType[] {
    const q = this.search.trim().toLowerCase();
    if (!q) {
      return this.roomTypes;
    }
    return this.roomTypes.filter((type) =>
      (type.name || '').toLowerCase().includes(q) ||
      (type.name_ar || '').toLowerCase().includes(q) ||
      (type.name_en || '').toLowerCase().includes(q) ||
      (type.description || '').toLowerCase().includes(q)
    );
  }

  getStatusBadgeClass(status: PlanStatus): string {
    if (status === 'active') return 'status-active';
    if (status === 'draft') return 'status-draft';
    if (status === 'inactive') return 'status-expired';
    return 'status-expired';
  }

  isPlanExpired(plan: PricingPlan): boolean {
    return plan.status === 'expired' || this.isPlanExpiredByDates(plan.endDate);
  }

  isPlanExpiredByDates(endDate?: string): boolean {
    if (!endDate) return false;
    const end = endDate.slice(0, 10);
    const today = new Date().toISOString().split('T')[0];
    return end < today;
  }

  getEffectiveRate(typeId: string): { base: number; best: number; planName?: string } {
    const type = this.roomTypes.find((t) => t.id === typeId);
    if (!type) return { base: 0, best: 0 };

    let best = type.baseRate;
    let planName: string | undefined;

    this.pricingPlans
      .filter((p) => p.status === 'active' && p.linkedRoomTypes.includes(typeId))
      .forEach((plan) => {
        const rate = Math.round(type.baseRate * (1 - plan.discount / 100));
        if (rate < best) {
          best = rate;
          planName = this.currentLang === 'ar' ? (plan.name_ar || plan.name) : (plan.name || plan.name_ar);
        }
      });

    return { base: type.baseRate, best, planName };
  }

  private findOverlappingPricingPlan(excludeId?: string): PricingPlan | null {
    const roomTypeId = String(this.newPricingPlan.roomtype_id ?? '');
    const startDate = String(this.newPricingPlan.StartDate ?? '').slice(0, 10);
    const endDate = String(this.newPricingPlan.EndDate ?? '').slice(0, 10);

    if (!roomTypeId || !startDate || !endDate) {
      return null;
    }

    return this.pricingPlans.find((plan) => {
      if (excludeId && plan.id === excludeId) {
        return false;
      }
      if (String(plan.roomtype_id) !== roomTypeId) {
        return false;
      }
      return this.pricingDatesOverlap(startDate, endDate, plan.startDate, plan.endDate);
    }) ?? null;
  }

  private pricingDatesOverlap(startA: string, endA: string, startB: string, endB: string): boolean {
    const aStart = startA.slice(0, 10);
    const aEnd = endA.slice(0, 10);
    const bStart = startB.slice(0, 10);
    const bEnd = endB.slice(0, 10);
    return aStart <= bEnd && aEnd >= bStart;
  }

  getRoomTypeById(id: string): RoomType | undefined {
    return this.roomTypes.find((type) => type.id === id);
  }

  roomTypeFieldInvalid(field: string): boolean {
    return this.roomTypeSubmitted && !!this.roomTypeErrors[field];
  }

  pricingPlanFieldInvalid(field: string): boolean {
    if (field === 'StartDate' || field === 'EndDate') {
      return !!this.pricingPlanErrors[field];
    }
    return this.pricingPlanSubmitted && !!this.pricingPlanErrors[field];
  }

  get pricingPlanEndDateMin(): string {
    return String(this.newPricingPlan.StartDate ?? '').slice(0, 10);
  }

  get pricingPlanStartDateMax(): string {
    return String(this.newPricingPlan.EndDate ?? '').slice(0, 10);
  }

  onPricingPlanDateChange(): void {
    this.clearPricingPlanError('_form');
    const startDate = String(this.newPricingPlan.StartDate ?? '').slice(0, 10);
    const endDate = String(this.newPricingPlan.EndDate ?? '').slice(0, 10);
    const rangeErrors = this.buildPricingPlanDateRangeErrors(startDate, endDate);

    if (rangeErrors) {
      this.pricingPlanErrors = { ...this.pricingPlanErrors, ...rangeErrors };
      return;
    }

    this.clearPricingPlanError('StartDate');
    this.clearPricingPlanError('EndDate');
  }

  private buildPricingPlanDateRangeErrors(startDate: string, endDate: string): Record<string, string> | null {
    if (!startDate || !endDate || endDate >= startDate) {
      return null;
    }

    return {
      StartDate: this.translate.instant('PRICING.VALIDATION.START_AFTER_END'),
      EndDate: this.translate.instant('PRICING.VALIDATION.END_BEFORE_START'),
    };
  }

  clearRoomTypeError(field: string): void {
    if (this.roomTypeErrors[field]) {
      const next = { ...this.roomTypeErrors };
      delete next[field];
      this.roomTypeErrors = next;
    }
  }

  clearPricingPlanError(field: string): void {
    if (this.pricingPlanErrors[field]) {
      const next = { ...this.pricingPlanErrors };
      delete next[field];
      this.pricingPlanErrors = next;
    }
  }

  closeRoomTypeModal(): void {
    this.showRoomTypeModal = false;
    this.resetRoomTypeFormState();
  }

  closePricingPlanModal(): void {
    this.showPricingPlanModal = false;
    this.resetPricingPlanFormState();
  }

  private resetRoomTypeFormState(): void {
    this.roomTypeSubmitted = false;
    this.roomTypeErrors = {};
  }

  private resetPricingPlanFormState(): void {
    this.pricingPlanSubmitted = false;
    this.pricingPlanErrors = {};
  }

  private validateRoomType(): boolean {
    const errors: Record<string, string> = {};
    const required = this.translate.instant('PRICING.VALIDATION.REQUIRED');
    const minPrice = this.translate.instant('PRICING.VALIDATION.MIN_PRICE');
    const rangeError = this.translate.instant('PRICING.VALIDATION.MAX_LESS_THAN_MIN');

    if (!String(this.newRoomType.name_en ?? '').trim()) {
      errors['name_en'] = required;
    }
    if (!String(this.newRoomType.name_ar ?? '').trim()) {
      errors['name_ar'] = required;
    }
    if (!String(this.newRoomType.description ?? '').trim()) {
      errors['description'] = required;
    }

    const activeType = Number(this.newRoomType.active_type);
    const minDaily = Number(this.newRoomType.Min_daily_price);
    const maxDaily = Number(this.newRoomType.Max_daily_price);
    const minMonthly = Number(this.newRoomType.Min_monthly_price);
    const maxMonthly = Number(this.newRoomType.Max_monthly_price);

    if (activeType === 0) {
      if (!Number.isFinite(minDaily) || minDaily < 1) {
        errors['Min_daily_price'] = minPrice;
      }
      if (!Number.isFinite(minMonthly) || minMonthly < 1) {
        errors['Min_monthly_price'] = minPrice;
      }
    } else {
      if (!Number.isFinite(minDaily) || minDaily < 1) {
        errors['Min_daily_price'] = minPrice;
      }
      if (!Number.isFinite(maxDaily) || maxDaily < 1) {
        errors['Max_daily_price'] = minPrice;
      }
      if (!Number.isFinite(minMonthly) || minMonthly < 1) {
        errors['Min_monthly_price'] = minPrice;
      }
      if (!Number.isFinite(maxMonthly) || maxMonthly < 1) {
        errors['Max_monthly_price'] = minPrice;
      }
      if (!errors['Max_daily_price'] && minDaily > maxDaily) {
        errors['Max_daily_price'] = rangeError;
      }
      if (!errors['Max_monthly_price'] && minMonthly > maxMonthly) {
        errors['Max_monthly_price'] = rangeError;
      }
    }

    this.roomTypeErrors = errors;
    return Object.keys(errors).length === 0;
  }

  private validatePricingPlan(): boolean {
    const errors: Record<string, string> = {};
    const required = this.translate.instant('PRICING.VALIDATION.REQUIRED');
    const minZero = this.translate.instant('PRICING.VALIDATION.MIN_ZERO');

    if (!String(this.newPricingPlan.NameEn ?? '').trim()) {
      errors['NameEn'] = required;
    }
    if (!String(this.newPricingPlan.NameAr ?? '').trim()) {
      errors['NameAr'] = required;
    }
    if (!this.newPricingPlan.roomtype_id) {
      errors['roomtype_id'] = required;
    }

    const startDate = String(this.newPricingPlan.StartDate ?? '').slice(0, 10);
    const endDate = String(this.newPricingPlan.EndDate ?? '').slice(0, 10);

    if (!startDate) {
      errors['StartDate'] = required;
    }
    if (!endDate) {
      errors['EndDate'] = required;
    }

    const rangeErrors = this.buildPricingPlanDateRangeErrors(startDate, endDate);
    if (rangeErrors) {
      Object.assign(errors, rangeErrors);
    }

    const daily = Number(this.newPricingPlan.DailyPrice);
    const monthly = Number(this.newPricingPlan.MonthlyPrice);
    if (!Number.isFinite(daily) || daily < 0) {
      errors['DailyPrice'] = minZero;
    }
    if (!Number.isFinite(monthly) || monthly < 0) {
      errors['MonthlyPrice'] = minZero;
    }

    if (this.isEditMode && !this.newPricingPlan.pricingplan_id) {
      errors['_form'] = this.translate.instant('PRICING.VALIDATION.MISSING_PLAN_LINK');
    }

    const overlap = !rangeErrors
      ? this.findOverlappingPricingPlan(this.isEditMode ? String(this.newPricingPlan.id) : undefined)
      : null;
    if (overlap) {
      const overlapMsg = this.translate.instant('PRICING.MESSAGES.PLAN_OVERLAP', { name: overlap.name });
      errors['StartDate'] = overlapMsg;
      errors['EndDate'] = overlapMsg;
      errors['_form'] = overlapMsg;
    }

    this.pricingPlanErrors = errors;
    return Object.keys(errors).length === 0;
  }
}
