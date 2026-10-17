import { Component, OnInit } from '@angular/core';
import { CompanyService } from '../../../services/company.service';
import { CompanyLedgerRow, CompanyStatement } from '../../../models/company.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

@Component({
    selector: 'app-company-ledger',
    templateUrl: './company-ledger.component.html',
    styleUrls: ['./company-ledger.component.css'],
    standalone: false
})
export class CompanyLedgerComponent implements OnInit {
  companies: CompanyLedgerRow[] = [];
  isLoading = false;

  selectedCompany: CompanyLedgerRow | null = null;
  statement: CompanyStatement | null = null;
  isLoadingStatement = false;

  settleAmount: number | null = null;
  isSettling = false;

  constructor(
    private companyService: CompanyService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadCompanies();
  }

  loadCompanies(): void {
    this.isLoading = true;
    this.companyService.fetchLedger().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.companies = res.data?.companies ?? [];
        } else {
          this.dataService.showToast(res.message || this.translate.instant('COMPANY_LEDGER.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('COMPANY_LEDGER.LOAD_ERROR'), 'error');
      }
    });
  }

  openStatement(company: CompanyLedgerRow): void {
    this.selectedCompany = company;
    this.isLoadingStatement = true;
    this.statement = null;
    this.settleAmount = null;
    this.companyService.fetchStatement(company.company_id).subscribe({
      next: (res) => {
        this.isLoadingStatement = false;
        if (res.success) {
          this.statement = res.data ?? null;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('COMPANY_LEDGER.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoadingStatement = false;
        this.dataService.showToast(this.translate.instant('COMPANY_LEDGER.LOAD_ERROR'), 'error');
      }
    });
  }

  closeStatement(): void {
    this.selectedCompany = null;
    this.statement = null;
  }

  recordSettlement(): void {
    if (!this.selectedCompany || !this.settleAmount || this.settleAmount <= 0 || this.isSettling) return;

    this.isSettling = true;
    this.companyService.settle(this.selectedCompany.company_id, this.settleAmount).subscribe({
      next: (res) => {
        this.isSettling = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('COMPANY_LEDGER.SETTLEMENT_SUCCESS'), 'success');
          this.settleAmount = null;
          this.openStatement(this.selectedCompany!);
          this.loadCompanies();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('COMPANY_LEDGER.SETTLEMENT_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSettling = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('COMPANY_LEDGER.SETTLEMENT_ERROR'), 'error');
      }
    });
  }
}
