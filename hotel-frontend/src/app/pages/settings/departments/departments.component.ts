import { Component, OnInit, OnDestroy } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { Department } from '../../../models/department.interface';
import { Subscription } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';

@Component({
    selector: 'app-departments',
    templateUrl: './departments.component.html',
    styleUrls: ['./departments.component.css'],
    standalone: false
})
export class DepartmentsComponent implements OnInit, OnDestroy {
  departments: Department[] = [];
  isLoading = false;
  showModal = false;
  isEditMode = false;
  isSaving = false;
  searchTerm = '';
  private subscription = new Subscription();

  currentDepartment: Department = {
    id: '',
    name_ar: '',
    name_en: '',
    description: ''
  };

  get withDescriptionCount(): number {
    return this.departments.filter((d) => !!(d.description || '').trim()).length;
  }

  get filteredDepartments(): Department[] {
    const q = this.searchTerm.trim().toLowerCase();
    if (!q) {
      return this.departments;
    }
    return this.departments.filter((d) =>
      (d.name_en || '').toLowerCase().includes(q) ||
      (d.name_ar || '').toLowerCase().includes(q) ||
      (d.description || '').toLowerCase().includes(q)
    );
  }

  clearFilters(): void {
    this.searchTerm = '';
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
      this.hotelService.departments$.subscribe(data => {
        this.departments = data;
        if (!this.hotelService.isDepartmentsLoaded() && !this.isLoading) {
          this.loadDepartments();
        }
      })
    );
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  loadDepartments() {
    this.isLoading = true;
    this.hotelService.fetchDepartments().subscribe({
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
    this.currentDepartment = { id: '', name_ar: '', name_en: '', description: '' };
    this.showModal = true;
  }

  openEditModal(dept: Department) {
    this.isEditMode = true;
    this.currentDepartment = { ...dept };
    this.showModal = true;
  }

  saveDepartment() {
    this.isSaving = true;
    const request = this.isEditMode
      ? this.hotelService.updateDepartment(this.currentDepartment)
      : this.hotelService.addDepartment(this.currentDepartment);

    request.subscribe({
      next: (res) => {
        if (res.success) {
          this.showModal = false;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.DEPARTMENT') }), 'error');
        }
        this.isSaving = false;
      },
      error: () => {
        const msg = this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.DEPARTMENT') });
        this.dataService.showToast(msg, 'error');
        this.isSaving = false;
      }
    });
  }

  async deleteDepartment(id: number | string): Promise<void> {
    const item = this.translate.instant('MESSAGES.ITEM.DEPARTMENT');
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

    this.hotelService.deleteDepartment(id.toString()).subscribe({
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
