import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CompanyService } from '../../../services/company.service';
import { HotelService } from '../../../services/hotel.service';
import { DataService } from '../../../services/Api/data.service';
import { TranslateService } from '@ngx-translate/core';
import { Company } from '../../../models/company.interface';

@Component({
    selector: 'app-companies-list',
    templateUrl: './companies-list.component.html',
    styleUrls: ['./companies-list.component.css'],
    standalone: false
})
export class CompaniesListComponent implements OnInit {
  companies: Company[] = [];
  isLoading = false;
  page = 1;
  lastPage = 1;

  showForm = false;
  editingCompany: Company | null = null;
  discounts: any[] = [];

  form = { name: '', contact_name: '', contact_mobile: '', contact_email: '', discount_id: null as number | null, credit_limit: null as number | null, notes: '' };
  isSaving = false;

  readonly STATUS_ACTIVE = 1;

  constructor(
    private companyService: CompanyService,
    private hotelService: HotelService,
    private dataService: DataService,
    private translate: TranslateService,
    private router: Router,
  ) {}

  ngOnInit(): void {
    this.load();
    this.hotelService.fetchDiscounts(1, 100).subscribe((res: any) => {
      this.discounts = res?.data?.data || res?.data || [];
    });
  }

  load(): void {
    this.isLoading = true;
    this.companyService.list(this.page).subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.companies = res.data ?? [];
          this.lastPage = res.last_page ?? 1;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('COMPANIES.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('COMPANIES.LOAD_ERROR'), 'error');
      }
    });
  }

  nextPage(): void { if (this.page < this.lastPage) { this.page++; this.load(); } }
  previousPage(): void { if (this.page > 1) { this.page--; this.load(); } }

  openCreate(): void {
    this.editingCompany = null;
    this.form = { name: '', contact_name: '', contact_mobile: '', contact_email: '', discount_id: null, credit_limit: null, notes: '' };
    this.showForm = true;
  }

  openEdit(company: Company): void {
    this.editingCompany = company;
    this.form = {
      name: company.name,
      contact_name: company.contact_name || '',
      contact_mobile: company.contact_mobile || '',
      contact_email: company.contact_email || '',
      discount_id: company.discount_id,
      credit_limit: company.credit_limit,
      notes: company.notes || '',
    };
    this.showForm = true;
  }

  closeForm(): void {
    this.showForm = false;
  }

  save(): void {
    if (!this.form.name.trim() || this.isSaving) return;
    this.isSaving = true;

    const payload = { ...this.form };
    const obs = this.editingCompany
      ? this.companyService.update(this.editingCompany.id, payload)
      : this.companyService.create(payload);

    obs.subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('COMPANIES.SAVE_SUCCESS'), 'success');
          this.showForm = false;
          this.load();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('COMPANIES.SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('COMPANIES.SAVE_ERROR'), 'error');
      }
    });
  }

  goLedger(): void {
    this.router.navigate(['/admin/companies-ledger']);
  }
}
