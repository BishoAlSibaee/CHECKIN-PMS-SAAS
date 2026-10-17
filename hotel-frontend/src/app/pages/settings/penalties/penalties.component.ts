import { Component, OnInit, OnDestroy } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { Penalty } from '../../../models/penalty.interface';
import { Subscription } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';

@Component({
    selector: 'app-penalties',
    templateUrl: './penalties.component.html',
    styleUrls: ['./penalties.component.css'],
    standalone: false
})
export class PenaltiesComponent implements OnInit, OnDestroy {
  penalties: Penalty[] = [];
  isLoading = false;
  showModal = false;
  isEditMode = false;
  isSaving = false;
  searchTerm = '';
  typeFilter: 'all' | 'fixed' | 'percent' = 'all';
  readonly Number = Number;
  private subscription = new Subscription();

  currentPenalty: Penalty = {
    id: '',
    name_ar: '',
    name_en: '',
    amount: 0,
    description: '',
    type: 1, // 1 = Fixed, 0 = Percentage
    value: 0
  };

  get fixedCount(): number {
    return this.penalties.filter((p) => this.isFixed(p)).length;
  }

  get percentCount(): number {
    return this.penalties.filter((p) => !this.isFixed(p)).length;
  }

  get filteredPenalties(): Penalty[] {
    const q = this.searchTerm.trim().toLowerCase();
    return this.penalties.filter((p) => {
      const typeOk =
        this.typeFilter === 'all' ||
        (this.typeFilter === 'fixed' && this.isFixed(p)) ||
        (this.typeFilter === 'percent' && !this.isFixed(p));
      if (!typeOk) {
        return false;
      }
      if (!q) {
        return true;
      }
      return (
        (p.name_en || '').toLowerCase().includes(q) ||
        (p.name_ar || '').toLowerCase().includes(q) ||
        (p.description || '').toLowerCase().includes(q)
      );
    });
  }

  isFixed(penalty: Penalty): boolean {
    return Number(penalty.type) === 1;
  }

  formatValue(penalty: Penalty): string {
    const sym = this.translate.instant('COMMON.CURRENCY_SYMBOL');
    const value = penalty.value ?? penalty.amount ?? 0;
    return this.isFixed(penalty) ? `${sym}${value}` : `${value}%`;
  }

  closeModal(): void {
    if (this.isSaving) {
      return;
    }
    this.showModal = false;
  }

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
    private confirmDialog: ConfirmDialogService
  ) { }

  ngOnInit(): void {
    this.subscription.add(
      this.hotelService.penalties$.subscribe(data => {
        this.penalties = data;
        // Fetch only if not already loaded to support "show data directly" even if empty
        if (!this.hotelService.isPenaltiesLoaded() && !this.isLoading) {
          this.loadPenalties();
        }
      })
    );
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  loadPenalties() {
    this.isLoading = true;
    this.hotelService.fetchPenalties().subscribe({
      next: () => {
        this.isLoading = false;
      },
      error: () => {
        this.isLoading = false;
      }
    });
  }

  openAddModal() {
    this.isEditMode = false;
    this.currentPenalty = { id: '', name_ar: '', name_en: '', amount: 0, description: '', type: 1, value: 0 };
    this.showModal = true;
  }

  openEditModal(penalty: Penalty) {
    this.isEditMode = true;
    this.currentPenalty = { ...penalty, amount: penalty.value }; // Initialize amount from value
    this.showModal = true;
  }

  savePenalty() {
    this.isSaving = true;

    // Ensure amount matches value for the backend if needed
    if (this.currentPenalty.amount !== undefined) {
      this.currentPenalty.value = this.currentPenalty.amount;
    }

    const request = this.isEditMode
      ? this.hotelService.updatePenalty(this.currentPenalty)
      : this.hotelService.addPenalty(this.currentPenalty);

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
        const msg = this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.PENALTY') });
        this.dataService.showToast(msg, 'error');
        this.isSaving = false;
      }
    });
  }

  async deletePenalty(id: number | string): Promise<void> {
    const item = this.translate.instant('MESSAGES.ITEM.PENALTY');
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

    this.hotelService.deletePenalty(id.toString()).subscribe({
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
      error: () => {
        this.dataService.showToast(
          this.translate.instant('MESSAGES.DELETE_ERROR', { item }),
          'error'
        );
      }
    });
  }
}
