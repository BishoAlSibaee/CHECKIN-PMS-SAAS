import { Component, OnInit } from '@angular/core';
import { PosChargeService } from '../../../services/pos-charge.service';
import { DataService } from '../../../services/Api/data.service';
import { TranslateService } from '@ngx-translate/core';
import { PosCategory } from '../../../models/pos-charge.interface';

@Component({
    selector: 'app-pos-categories',
    templateUrl: './pos-categories.component.html',
    styleUrls: ['./pos-categories.component.css'],
    standalone: false
})
export class PosCategoriesComponent implements OnInit {
  categories: PosCategory[] = [];
  accounts: any[] = [];
  isLoading = false;

  showForm = false;
  editingCategory: PosCategory | null = null;
  form = { name_en: '', name_ar: '', account_id: null as number | null };
  isSaving = false;

  constructor(
    private posChargeService: PosChargeService,
    private dataService: DataService,
    private translate: TranslateService,
  ) {}

  ngOnInit(): void {
    this.load();
    this.posChargeService.listRevenueAccounts().subscribe((res: any) => {
      this.accounts = res?.data ?? [];
    });
  }

  load(): void {
    this.isLoading = true;
    this.posChargeService.listCategories().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.categories = res.data ?? [];
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

  openCreate(): void {
    this.editingCategory = null;
    this.form = { name_en: '', name_ar: '', account_id: null };
    this.showForm = true;
  }

  openEdit(category: PosCategory): void {
    this.editingCategory = category;
    this.form = { name_en: category.name_en, name_ar: category.name_ar, account_id: category.account_id };
    this.showForm = true;
  }

  closeForm(): void {
    this.showForm = false;
  }

  save(): void {
    if (!this.form.name_en.trim() || !this.form.name_ar.trim() || !this.form.account_id || this.isSaving) return;
    this.isSaving = true;

    const payload = { ...this.form };
    const obs = this.editingCategory
      ? this.posChargeService.updateCategory(this.editingCategory.id, payload)
      : this.posChargeService.createCategory(payload);

    obs.subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('POS.CATEGORY_SAVE_SUCCESS'), 'success');
          this.showForm = false;
          this.load();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('POS.CATEGORY_SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('POS.CATEGORY_SAVE_ERROR'), 'error');
      }
    });
  }
}
