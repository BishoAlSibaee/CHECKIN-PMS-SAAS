import { Component, OnInit, OnDestroy } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { Discount } from '../../../models/discount.interface';
import { Subscription } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';

@Component({
    selector: 'app-discounts',
    templateUrl: './discounts.component.html',
    styleUrls: ['./discounts.component.css'],
    standalone: false
})
export class DiscountsComponent implements OnInit, OnDestroy {
  discounts: Discount[] = [];
  isLoading = false;
  showModal = false;
  isEditMode = false;
  isSaving = false;
  searchTerm = '';
  typeFilter: 'all' | 'percent' | 'fixed' = 'all';
  statusFilter: 'all' | 'active' | 'inactive' = 'all';
  pagination = {
    current: 1,
    last: 1,
    total: 0,
    perPage: 10,
  };
  private subscription = new Subscription();

  currentDiscount: Discount = {
    id: '',
    name: '',
    is_percentage: 0,
    percent: 0,
    is_fixed: 0,
    fixed_amount: 0,
    is_active: 1
  };

  get activeCount(): number {
    return this.discounts.filter((d) => this.isActive(d)).length;
  }

  get percentCount(): number {
    return this.discounts.filter((d) => this.isPercentage(d)).length;
  }

  get filteredDiscounts(): Discount[] {
    const q = this.searchTerm.trim().toLowerCase();
    return this.discounts.filter((d) => {
      const typeOk =
        this.typeFilter === 'all' ||
        (this.typeFilter === 'percent' && this.isPercentage(d)) ||
        (this.typeFilter === 'fixed' && this.isFixed(d));
      if (!typeOk) {
        return false;
      }
      const statusOk =
        this.statusFilter === 'all' ||
        (this.statusFilter === 'active' && this.isActive(d)) ||
        (this.statusFilter === 'inactive' && !this.isActive(d));
      if (!statusOk) {
        return false;
      }
      if (!q) {
        return true;
      }
      return (d.name || '').toLowerCase().includes(q);
    });
  }

  clearFilters(): void {
    this.searchTerm = '';
    this.typeFilter = 'all';
    this.statusFilter = 'all';
  }

  isActive(discount: Discount): boolean {
    return !!discount.is_active;
  }

  isPercentage(discount: Discount): boolean {
    return !!discount.is_percentage;
  }

  isFixed(discount: Discount): boolean {
    return !!discount.is_fixed;
  }

  closeModal(): void {
    if (this.isSaving) {
      return;
    }
    this.showModal = false;
  }

  setDiscountType(type: 'percent' | 'fixed'): void {
    if (type === 'percent') {
      this.currentDiscount.is_percentage = 1;
      this.currentDiscount.is_fixed = 0;
    } else {
      this.currentDiscount.is_percentage = 0;
      this.currentDiscount.is_fixed = 1;
    }
  }

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
    private confirmDialog: ConfirmDialogService
  ) { }

  ngOnInit(): void {
    this.subscription.add(
      this.hotelService.discounts$.subscribe(data => {
        this.discounts = data;
        if (!this.hotelService.isDiscountsLoaded() && !this.isLoading) {
          this.loadDiscounts();
        }
      })
    );
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  loadDiscounts(page: number = this.pagination.current) {
    this.isLoading = true;
    this.hotelService.fetchDiscounts(page, this.pagination.perPage).subscribe({
      next: (res) => {
        if (res?.success) {
          this.pagination.current = Number(res.current_page ?? page);
          this.pagination.last = Number(res.last_page ?? 1);
          this.pagination.total = Number(res.total ?? this.discounts.length);
          this.pagination.perPage = Number(res.per_page ?? this.pagination.perPage);
        }
        this.isLoading = false;
      },
      error: () => {
        this.isLoading = false;
      }
    });
  }

  onPageChange(page: number): void {
    if (page < 1 || page > this.pagination.last || page === this.pagination.current) {
      return;
    }

    this.loadDiscounts(page);
  }

  onPerPageChange(): void {
    this.pagination.current = 1;
    this.loadDiscounts(1);
  }

  getPages(): number[] {
    const total = Math.max(1, this.pagination.last);
    const current = Math.min(this.pagination.current, total);
    const start = Math.max(1, current - 2);
    const end = Math.min(total, start + 4);
    const adjustedStart = Math.max(1, end - 4);

    return Array.from(
      { length: end - adjustedStart + 1 },
      (_, i) => adjustedStart + i
    );
  }

  get paginationFrom(): number {
    if (this.pagination.total === 0) {
      return 0;
    }

    return (this.pagination.current - 1) * this.pagination.perPage + 1;
  }

  get paginationTo(): number {
    return Math.min(this.pagination.current * this.pagination.perPage, this.pagination.total);
  }

  openAddModal() {
    this.isEditMode = false;
    this.currentDiscount = {
      id: '',
      name: '',
      is_percentage: 1,
      percent: 0,
      is_fixed: 0,
      fixed_amount: 0,
      is_active: 1
    };
    this.showModal = true;
  }

  openEditModal(discount: Discount) {
    this.isEditMode = true;
    this.currentDiscount = { ...discount };
    this.showModal = true;
  }

  saveDiscount() {
    this.isSaving = true;

    const payload = {
      ...this.currentDiscount,
      is_percentage: this.currentDiscount.is_percentage ? 1 : 0,
      is_fixed: this.currentDiscount.is_fixed ? 1 : 0,
      is_active: this.currentDiscount.is_active ? 1 : 0,
      percent: Number(this.currentDiscount.percent),
      fixed_amount: Number(this.currentDiscount.fixed_amount)
    };

    const request = this.isEditMode
      ? this.hotelService.updateDiscount(payload, this.pagination.current, this.pagination.perPage)
      : this.hotelService.addDiscount(payload, this.pagination.current, this.pagination.perPage);

    request.subscribe({
      next: (res) => {
        if (res.success) {
          this.showModal = false;
        } else {
          this.dataService.showToast(res.message, 'error');
        }
        this.isSaving = false;
      },
      error: (err) => {
        const msg = err.error?.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.DISCOUNT') });
        this.dataService.showToast(msg, 'error');
        this.isSaving = false;
      }
    });
  }

  async deleteDiscount(id: number | string): Promise<void> {
    const item = this.translate.instant('MESSAGES.ITEM.DISCOUNT');
    const ok = await this.confirmDialog.confirm(
      this.translate.instant('MESSAGES.CONFIRM_DELETE', { item }),
      {
        title: this.translate.instant('COMMON.CONFIRM_DELETE_TITLE'),
        confirmText: this.translate.instant('COMMON.DELETE'),
        cancelText: this.translate.instant('COMMON.CANCEL'),
        variant: 'danger',
      }
    );

    if (!ok) {
      return;
    }

    this.hotelService.deleteDiscount(id.toString(), this.pagination.current, this.pagination.perPage).subscribe({
      next: (res) => {
        if (res.success) {
          this.dataService.showToast(
            this.translate.instant('MESSAGES.DELETE_SUCCESS', { item }),
            'success'
          );
        } else {
          this.dataService.showToast(
            res.message || this.translate.instant('MESSAGES.DELETE_ERROR', { item }),
            'error'
          );
        }
      },
      error: (err) => {
        const msg = err.error?.message || this.translate.instant('MESSAGES.DELETE_ERROR', { item });
        this.dataService.showToast(msg, 'error');
      }
    });
  }

  toggleActive(discount: Discount) {
    const updated = { ...discount, is_active: discount.is_active ? 0 : 1 };
    this.hotelService.updateDiscount(updated, this.pagination.current, this.pagination.perPage).subscribe({
      next: (res) => {
        if (!res.success) {
          const msg = res.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.STATUS') });
          this.dataService.showToast(msg, 'error');
        }
      }
    });
  }
}
