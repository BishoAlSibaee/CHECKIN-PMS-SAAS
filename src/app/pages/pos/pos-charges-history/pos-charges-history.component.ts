import { Component, OnInit } from '@angular/core';
import { PosChargeService } from '../../../services/pos-charge.service';
import { DataService } from '../../../services/Api/data.service';
import { AuthPermissionService } from '../../../services/auth-permission.service';
import { TranslateService } from '@ngx-translate/core';
import {
  PosCategory,
  PosCharge,
  POS_CHARGE_STATUS_ACTIVE,
  POS_PAYMENT_STATUS_CHARGED_TO_ROOM,
} from '../../../models/pos-charge.interface';

@Component({
    selector: 'app-pos-charges-history',
    templateUrl: './pos-charges-history.component.html',
    styleUrls: ['./pos-charges-history.component.css'],
    standalone: false
})
export class PosChargesHistoryComponent implements OnInit {
  readonly STATUS_ACTIVE = POS_CHARGE_STATUS_ACTIVE;
  readonly CHARGED_TO_ROOM = POS_PAYMENT_STATUS_CHARGED_TO_ROOM;

  charges: PosCharge[] = [];
  categories: PosCategory[] = [];
  isLoading = false;
  page = 1;
  lastPage = 1;

  filters = { pos_category_id: null as number | null, status: null as number | null };

  canVoid = false;
  voidingId: number | null = null;

  constructor(
    private posChargeService: PosChargeService,
    private dataService: DataService,
    private authPermissionService: AuthPermissionService,
    private translate: TranslateService,
  ) {
    this.canVoid = this.authPermissionService.hasAny('void pos charges');
  }

  ngOnInit(): void {
    this.posChargeService.listCategories().subscribe((res: any) => {
      this.categories = res?.data ?? [];
    });
    this.load();
  }

  load(): void {
    this.isLoading = true;
    const filters: any = {};
    if (this.filters.pos_category_id) filters.pos_category_id = this.filters.pos_category_id;
    if (this.filters.status !== null) filters.status = this.filters.status;

    this.posChargeService.list(this.page, filters).subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.charges = res.data ?? [];
          this.lastPage = res.last_page ?? 1;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('POS.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('POS.LOAD_ERROR'), 'error');
      }
    });
  }

  applyFilters(): void {
    this.page = 1;
    this.load();
  }

  nextPage(): void { if (this.page < this.lastPage) { this.page++; this.load(); } }
  previousPage(): void { if (this.page > 1) { this.page--; this.load(); } }

  guestLabel(charge: PosCharge): string {
    if (charge.reservation?.client) {
      return `${charge.reservation.client.first_name} ${charge.reservation.client.last_name}`;
    }
    return charge.walkin_reference || '—';
  }

  voidCharge(charge: PosCharge): void {
    if (this.voidingId) return;
    this.voidingId = charge.id;

    this.posChargeService.void(charge.id).subscribe({
      next: (res) => {
        this.voidingId = null;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('POS.VOID_SUCCESS'), 'success');
          this.load();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('POS.VOID_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.voidingId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('POS.VOID_ERROR'), 'error');
      }
    });
  }
}
