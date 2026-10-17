import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { OtaAllocationRow, OtaRatePlan } from '../../../models/channex.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';

/**
 * Releasing rooms to OTA channels and pricing them are one workflow, not
 * two — a room type allocated with no rate plan shows on the OTA as
 * "available" with no price ever attached to actually sell it at
 * (ChannexAriPushService::pushRates() silently skips it). This page used to
 * be split from the separate "OTA Rate Plans" page; merged here so a rate
 * plan is right where staff are already looking when they release rooms.
 */
@Component({
    selector: 'app-ota-allocations',
    templateUrl: './ota-allocations.component.html',
    styleUrls: ['./ota-allocations.component.css'],
    standalone: false
})
export class OtaAllocationsComponent implements OnInit {
  rows: OtaAllocationRow[] = [];
  isLoading = false;
  savingRoomTypeId: number | null = null;
  draftAllocation: Record<number, number> = {};

  showPlanModal = false;
  isEditMode = false;
  currentPlan: Partial<OtaRatePlan> = {};
  isSavingPlan = false;

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
    private confirmDialog: ConfirmDialogService,
  ) {}

  ngOnInit(): void {
    this.loadRows();
  }

  loadRows(): void {
    this.isLoading = true;
    this.hotelService.fetchOtaAllocations().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.rows = res.data?.room_types ?? [];
          this.draftAllocation = {};
          this.rows.forEach((r) => (this.draftAllocation[r.room_type_id] = r.allocated_rooms));
        } else {
          this.dataService.showToast(res.message || this.translate.instant('OTA_ALLOCATIONS.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('OTA_ALLOCATIONS.LOAD_ERROR'), 'error');
      }
    });
  }

  save(row: OtaAllocationRow): void {
    const value = this.draftAllocation[row.room_type_id];
    if (value == null || value < 0) return;

    this.savingRoomTypeId = row.room_type_id;
    this.hotelService.updateOtaAllocation(row.room_type_id, value).subscribe({
      next: (res) => {
        this.savingRoomTypeId = null;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('OTA_ALLOCATIONS.SAVE_SUCCESS'), 'success');
          this.loadRows();
        } else {
          // e.g. "add a rate plan before releasing rooms" — the backend's
          // own validation message, shown verbatim since it already names
          // exactly what to do next.
          this.dataService.showToast(res.message || this.translate.instant('OTA_ALLOCATIONS.SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.savingRoomTypeId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('OTA_ALLOCATIONS.SAVE_ERROR'), 'error');
      }
    });
  }

  isDirty(row: OtaAllocationRow): boolean {
    return this.draftAllocation[row.room_type_id] !== row.allocated_rooms;
  }

  remainingHeadroom(row: OtaAllocationRow): number {
    return Math.max(0, row.allocated_rooms - row.ota_booked);
  }

  /** Existing bad state from before this validation existed — flagged so staff can fix it, not just future saves. */
  needsRatePlanWarning(row: OtaAllocationRow): boolean {
    return row.allocated_rooms > 0 && !row.has_active_rate_plan;
  }

  openAddPlanModal(row: OtaAllocationRow): void {
    this.isEditMode = false;
    this.currentPlan = {
      room_type_id: row.room_type_id,
      name_ar: '',
      name_en: '',
      price_adjustment_percent: 0,
      is_refundable: true,
    };
    this.showPlanModal = true;
  }

  openEditPlanModal(plan: OtaRatePlan): void {
    this.isEditMode = true;
    this.currentPlan = { ...plan };
    this.showPlanModal = true;
  }

  closePlanModal(): void {
    this.showPlanModal = false;
    this.currentPlan = {};
  }

  savePlan(): void {
    if (this.isSavingPlan) return;
    this.isSavingPlan = true;

    if (this.isEditMode && this.currentPlan.id) {
      this.hotelService.updateOtaRatePlan(this.currentPlan.id, {
        name_ar: this.currentPlan.name_ar,
        name_en: this.currentPlan.name_en,
        price_adjustment_percent: this.currentPlan.price_adjustment_percent,
        is_refundable: this.currentPlan.is_refundable,
      }).subscribe({
        next: (res) => this.handlePlanSaveResult(res),
        error: (err) => this.handlePlanSaveError(err),
      });
      return;
    }

    this.hotelService.createOtaRatePlan({
      room_type_id: this.currentPlan.room_type_id!,
      name_ar: this.currentPlan.name_ar!,
      name_en: this.currentPlan.name_en!,
      price_adjustment_percent: this.currentPlan.price_adjustment_percent ?? 0,
      is_refundable: !!this.currentPlan.is_refundable,
    }).subscribe({
      next: (res) => this.handlePlanSaveResult(res),
      error: (err) => this.handlePlanSaveError(err),
    });
  }

  private handlePlanSaveResult(res: any): void {
    this.isSavingPlan = false;
    if (res.success) {
      this.dataService.showToast(
        this.translate.instant(this.isEditMode ? 'OTA_RATE_PLANS.UPDATE_SUCCESS' : 'OTA_RATE_PLANS.CREATE_SUCCESS'),
        'success'
      );
      this.closePlanModal();
      this.loadRows();
    } else {
      this.dataService.showToast(res.message || this.translate.instant('OTA_RATE_PLANS.SAVE_ERROR'), 'error');
    }
  }

  private handlePlanSaveError(err: any): void {
    this.isSavingPlan = false;
    this.dataService.showToast(err.error?.message || this.translate.instant('OTA_RATE_PLANS.SAVE_ERROR'), 'error');
  }

  togglePlanActive(plan: OtaRatePlan): void {
    this.hotelService.updateOtaRatePlan(plan.id, { is_active: !plan.is_active }).subscribe({
      next: (res) => {
        if (res.success) {
          this.loadRows();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('OTA_RATE_PLANS.SAVE_ERROR'), 'error');
        }
      },
      error: (err) => this.dataService.showToast(err.error?.message || this.translate.instant('OTA_RATE_PLANS.SAVE_ERROR'), 'error'),
    });
  }

  async deleteCurrentPlan(): Promise<void> {
    if (!this.currentPlan.id) return;
    const plan = this.currentPlan as OtaRatePlan;
    this.closePlanModal();
    await this.deletePlan(plan);
  }

  async deletePlan(plan: OtaRatePlan): Promise<void> {
    const ok = await this.confirmDialog.confirm(
      this.translate.instant('OTA_RATE_PLANS.CONFIRM_DELETE', { name: plan.name_en }),
      {
        title: this.translate.instant('COMMON.CONFIRM_DELETE_TITLE'),
        confirmText: this.translate.instant('COMMON.DELETE'),
        cancelText: this.translate.instant('COMMON.CANCEL'),
        variant: 'danger',
      }
    );
    if (!ok) return;

    this.hotelService.deleteOtaRatePlan(plan.id).subscribe({
      next: (res) => {
        if (res.success) {
          this.dataService.showToast(this.translate.instant('OTA_RATE_PLANS.DELETE_SUCCESS'), 'success');
          this.loadRows();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('OTA_RATE_PLANS.DELETE_ERROR'), 'error');
        }
      },
      error: (err) => this.dataService.showToast(err.error?.message || this.translate.instant('OTA_RATE_PLANS.DELETE_ERROR'), 'error'),
    });
  }
}
