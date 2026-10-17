import { Component, OnInit, OnDestroy } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { JobTitle } from '../../../models/job-title.interface';
import { Department } from '../../../models/department.interface';
import { Subscription, combineLatest, forkJoin, of } from 'rxjs';
import { catchError, finalize } from 'rxjs/operators';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';

@Component({
    selector: 'app-job-titles',
    templateUrl: './job-titles.component.html',
    styleUrls: ['./job-titles.component.css'],
    standalone: false
})
export class JobTitlesComponent implements OnInit, OnDestroy {
  jobTitles: JobTitle[] = [];
  departments: Department[] = [];
  isLoading = false;
  showModal = false;
  isEditMode = false;
  isSaving = false;
  searchTerm = '';
  departmentFilter: string | 'all' = 'all';
  private subscription = new Subscription();

  currentJobTitle: JobTitle = {
    id: '',
    department_id: '',
    name_ar: '',
    name_en: '',
    description: ''
  };

  get currentLang(): string {
    return this.translate.currentLang || 'en';
  }

  get departmentsWithJobs(): number {
    const ids = new Set(this.jobTitles.map((j) => String(j.department_id)).filter(Boolean));
    return ids.size;
  }

  get filteredJobTitles(): JobTitle[] {
    const q = this.searchTerm.trim().toLowerCase();
    return this.jobTitles.filter((job) => {
      const deptOk =
        this.departmentFilter === 'all' ||
        String(job.department_id) === String(this.departmentFilter);
      if (!deptOk) {
        return false;
      }
      if (!q) {
        return true;
      }
      return (
        (job.name_en || '').toLowerCase().includes(q) ||
        (job.name_ar || '').toLowerCase().includes(q) ||
        (job.description || '').toLowerCase().includes(q) ||
        this.getDepartmentName(job.department_id).toLowerCase().includes(q)
      );
    });
  }

  clearFilters(): void {
    this.searchTerm = '';
    this.departmentFilter = 'all';
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
      combineLatest([
        this.hotelService.jobTitles$,
        this.hotelService.departments$
      ]).subscribe(([jobs, depts]) => {
        this.jobTitles = jobs;
        this.departments = depts;

        if (!this.isLoading) {
          if (!this.hotelService.isJobTitlesLoaded() || !this.hotelService.isDepartmentsLoaded()) {
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
      this.hotelService.isJobTitlesLoaded()
        ? of(null)
        : this.hotelService.fetchJobTitles().pipe(catchError(() => of(null))),
      this.hotelService.isDepartmentsLoaded()
        ? of(null)
        : this.hotelService.fetchDepartments().pipe(catchError(() => of(null))),
    ]).pipe(
      finalize(() => {
        this.isLoading = false;
      })
    ).subscribe();
  }

  refresh(): void {
    this.isLoading = true;
    forkJoin([
      this.hotelService.fetchJobTitles().pipe(catchError(() => of(null))),
      this.hotelService.fetchDepartments().pipe(catchError(() => of(null))),
    ]).pipe(
      finalize(() => {
        this.isLoading = false;
      })
    ).subscribe();
  }

  openAddModal() {
    this.isEditMode = false;
    this.currentJobTitle = {
      id: '',
      department_id: this.departments.length > 0 ? this.departments[0].id : '',
      name_ar: '',
      name_en: '',
      description: ''
    };
    this.showModal = true;
  }

  openEditModal(job: JobTitle) {
    this.isEditMode = true;
    this.currentJobTitle = { ...job };
    this.showModal = true;
  }

  saveJobTitle() {
    this.isSaving = true;
    const request = this.isEditMode
      ? this.hotelService.updateJobTitle(this.currentJobTitle)
      : this.hotelService.addJobTitle(this.currentJobTitle);

    request.subscribe({
      next: (res) => {
        if (res.success) {
          this.showModal = false;
        } else {
          this.dataService.showToast(res.message, 'error');
        }
        this.isSaving = false;
      },
      error: () => {
        const msg = this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.JOB_TITLE') });
        this.dataService.showToast(msg, 'error');
        this.isSaving = false;
      }
    });
  }

  async deleteJobTitle(id: number | string): Promise<void> {
    const item = this.translate.instant('MESSAGES.ITEM.JOB_TITLE');
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

    this.hotelService.deleteJobTitle(id.toString()).subscribe({
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

  getDepartmentName(id: any): string {
    const dept = this.departments.find(d => String(d.id) === String(id));
    if (!dept) {
      return '—';
    }
    if (this.currentLang === 'ar') {
      return dept.name_ar || dept.name_en || '—';
    }
    return dept.name_en || dept.name_ar || '—';
  }
}
