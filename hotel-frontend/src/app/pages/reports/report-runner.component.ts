import { Component, OnDestroy, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { Subscription, of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ReportCatalogService } from '../../services/report-catalog.service';
import { ReportService } from '../../services/report.service';
import { ReportExportService, ExportColumn } from '../../services/report-export.service';
import { ReportI18nService } from '../../services/report-i18n.service';
import { DataService } from '../../services/Api/data.service';
import { RealtimeService } from '../../services/realtime.service';
import { HotelLiveScope } from '../../models/realtime.models';
import {
  isReportDateRangeInvalid,
  syncReportEndDate,
  syncReportStartDate
} from '../../core/report-date-range';

@Component({
    selector: 'app-report-runner',
    templateUrl: './report-runner.component.html',
    styleUrls: ['./report-runner.component.css'],
    standalone: false
})
export class ReportRunnerComponent implements OnInit, OnDestroy {
  private static readonly COMPARE_SLUGS = new Set([
    'overview',
    'accrual-revenue',
    'occupancy',
    'revenue-summary',
    'accrual-cash-reconciliation',
    'tax',
    'revpar'
  ]);

  private static readonly AS_OF_SLUGS = new Set([
    'room-board',
    'ar-aging',
  ]);

  private static readonly EARNED_ACCRUAL_SLUGS = new Set([
    'overview',
    'accrual-revenue',
    'revenue-summary',
    'accrual-cash-reconciliation',
    'tax',
    'revpar',
    'by-dimension',
    'closing-package',
  ]);

  private static readonly FULL_YEAR_SLUGS = new Set([
    'arrivals-departures',
    'reservations-list',
    'overview',
    'revenue-summary',
    'cash-box',
    'accrual-cash-reconciliation',
    'adjustments',
    'tax',
    'revpar',
    'by-dimension',
    'peak-analysis',
    'payments-refunds',
    'closing-package'
  ]);

  private static readonly BOARD_SLUGS = new Set([
    'room-board',
    'occupancy',
  ]);

  private static readonly RESERVATION_SLUGS = new Set([
    'arrivals-departures',
    'reservations-list',
  ]);

  private static readonly ACCOUNTING_SLUGS = new Set([
    'chart-of-accounts',
    'journal-entries',
    'general-ledger',
    'trial-balance',
    'cash-flow',
    'financial-audit-log',
  ]);

  private static readonly FINANCIAL_REFRESH_SLUGS = new Set([
    'overview',
    'accrual-revenue',
    'cash-box',
    'revenue-summary',
    'accrual-cash-reconciliation',
    'ar-aging',
    'adjustments',
    'tax',
    'revpar',
    'by-dimension',
    'peak-analysis',
    'payments-refunds',
    'closing-package',
    'chart-of-accounts',
    'journal-entries',
    'general-ledger',
    'trial-balance',
    'cash-flow',
    'financial-audit-log',
    'accounting-export-generic',
    'accounting-export-qoyod',
    'accounting-export-daftra',
  ]);

  slug = '';
  titleKey = '';
  descKey = '';
  isLoading = false;
  isSoftRefreshing = false;
  errorMessage = '';
  startDate = '';
  endDate = '';
  compareStartDate = '';
  compareEndDate = '';
  showComparison = false;
  activeComparePreset: 'previous' | 'last_year' | 'custom' | null = null;
  guideCollapsed = false;
  emailExports: Array<{
    id: number;
    slug: string;
    format: string;
    email: string;
    status: string;
    download_url: string | null;
    error_message: string | null;
    created_at: string | null;
  }> = [];
  isLoadingEmailExports = false;
  readonly maxPendingEmailExports = 3;
  private emailExportsPollTimer: ReturnType<typeof setInterval> | null = null;
  private static readonly GUIDE_STORAGE_KEY = 'report-guide-collapsed';

  get supportsComparison(): boolean {
    return ReportRunnerComponent.COMPARE_SLUGS.has(this.slug);
  }

  get isAsOfReport(): boolean {
    return ReportRunnerComponent.AS_OF_SLUGS.has(this.slug);
  }

  get endDateLabelKey(): string {
    if (!this.isAsOfReport) {
      return 'REPORTS.END_DATE';
    }
    return this.slug === 'room-board' ? 'REPORTS.SNAPSHOT_DATE' : 'REPORTS.AS_OF_DATE';
  }

  get asOfHintKey(): string {
    if (this.slug === 'room-board') {
      return 'REPORTS.ROOM_BOARD_HINT';
    }
    if (this.slug === 'ar-aging') {
      return 'REPORTS.AR_AGING_HINT';
    }
    return '';
  }

  get earnedAccrualHintKey(): string {
    if (this.slug === 'peak-analysis') {
      return 'REPORTS.PEAK_ANALYSIS_HINT';
    }
    return ReportRunnerComponent.EARNED_ACCRUAL_SLUGS.has(this.slug) ? 'REPORTS.EARNED_ACCRUAL_HINT' : '';
  }

  summary: { label: string; value: string | number }[] = [];
  columns: ExportColumn[] = [];
  rows: Record<string, unknown>[] = [];
  metaLines: string[] = [];
  totalRows = 0;
  previewLimit = 50;
  isTruncated = false;
  isExporting = false;
  exportLimit = 40;
  directDownloadAllowed = true;
  requiresEmailOrFilters = false;
  currentPage = 1;
  totalPages = 1;
  pageSize = 50;
  Math = Math;

  recipientEmail = '';
  emailFormat: 'excel' | 'pdf' = 'excel';
  isRequestingEmail = false;
  emailRequestMessage = '';
  emailRequestError = '';
  /** Always on — large reports only block direct download, not email. */
  emailExportEnabled = true;
  selectedAccountId: number | null = null;
  selectedAccountLabel = '';
  selectedAccountType: string | null = null;
  readonly generalLedgerAccountTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'] as const;
  chartAccounts: { id: number; code: string; name_en: string; name_ar: string; type: string }[] = [];
  isLoadingChartAccounts = false;
  accountingSearch = '';
  accountingHideZero = false;
  accountingReservationId = '';
  accountingSourceType = '';
  accountingOperation = '';
  accountingMinAmount = '';
  accountingMaxAmount = '';
  hideEmptyNights = false;
  hideZeroRows = false;

  private langSub?: Subscription;
  private rawColumns: { key: string; label?: string }[] = [];
  private rawSummary: { label: string; value: string | number }[] = [];
  private rawRows: Record<string, unknown>[] = [];
  private rawMetaLines: string[] = [];
  private reportRefreshTimer: ReturnType<typeof setTimeout> | null = null;
  private pendingDateRefresh = false;
  private realtimeOffs: (() => void)[] = [];

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private catalog: ReportCatalogService,
    private reportService: ReportService,
    private exportService: ReportExportService,
    private reportI18n: ReportI18nService,
    private translate: TranslateService,
    private dataService: DataService,
    private http: HttpClient,
    private realtime: RealtimeService
  ) {}

  ngOnInit(): void {
    this.guideCollapsed = this.readGuideCollapsed();
    this.prefillEmail();
    this.loadMailConfig();
    this.langSub = this.translate.onLangChange.subscribe(() => this.applyTranslations());
    this.route.paramMap.subscribe(params => {
      this.slug = params.get('slug') || '';
      const item = this.catalog.getBySlug(this.slug);
      if (!item) {
        this.router.navigate(['/reports']);
        return;
      }
      this.titleKey = item.titleKey;
      this.descKey = item.descKey;
      this.showComparison = false;
      this.compareStartDate = '';
      this.compareEndDate = '';
      this.activeComparePreset = null;
      this.hideEmptyNights = false;
      this.hideZeroRows = false;
      this.emailRequestMessage = '';
      this.emailRequestError = '';
      this.currentPage = 1;
      this.initDates();
      this.applyRouteQueryParams();
      if (ReportRunnerComponent.ACCOUNTING_SLUGS.has(this.slug)) {
        this.loadChartAccounts();
        if (this.isGeneralLedgerReport && !this.selectedAccountType) {
          this.clearReportData();
          return;
        }
        this.loadReport();
        return;
      }
      this.loadReport();
    });
    this.bindRealtimeRefresh();
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
    if (this.reportRefreshTimer) {
      clearTimeout(this.reportRefreshTimer);
    }
    this.stopEmailExportsPoll();
    this.realtimeOffs.forEach((off) => off());
  }

  private readGuideCollapsed(): boolean {
    try {
      return localStorage.getItem(ReportRunnerComponent.GUIDE_STORAGE_KEY) === '1';
    } catch {
      return false;
    }
  }

  toggleGuide(): void {
    this.guideCollapsed = !this.guideCollapsed;
    try {
      localStorage.setItem(
        ReportRunnerComponent.GUIDE_STORAGE_KEY,
        this.guideCollapsed ? '1' : '0'
      );
    } catch {
      /* ignore quota / private mode */
    }
  }

  private bindRealtimeRefresh(): void {
    const scopes: HotelLiveScope[] = [
      'collections',
      'reservations',
      'occupancy_board',
      'dashboard',
    ];
    for (const scope of scopes) {
      this.realtimeOffs.push(
        this.realtime.onScope(scope, () => this.onRealtimeRefresh(scope))
      );
    }
  }

  private onRealtimeRefresh(scope: HotelLiveScope): void {
    if (!this.slug || !this.canRunReport) {
      return;
    }
    if (!this.shouldRefreshReportForScope(scope)) {
      return;
    }
    if (this.isLoading) {
      this.pendingDateRefresh = true;
      return;
    }
    this.loadReport(this.currentPage, { soft: true });
  }

  private shouldRefreshReportForScope(scope: HotelLiveScope): boolean {
    if (scope === 'collections') {
      return ReportRunnerComponent.FINANCIAL_REFRESH_SLUGS.has(this.slug);
    }
    if (scope === 'reservations') {
      return ReportRunnerComponent.FINANCIAL_REFRESH_SLUGS.has(this.slug)
        || ReportRunnerComponent.RESERVATION_SLUGS.has(this.slug);
    }
    if (scope === 'occupancy_board' || scope === 'dashboard') {
      return ReportRunnerComponent.BOARD_SLUGS.has(this.slug);
    }
    return false;
  }

  private prefillEmail(): void {
    const user = this.dataService.user$.value;
    if (user?.email) {
      this.recipientEmail = user.email;
    }
  }

  private loadMailConfig(): void {
    // Email export must stay available even when system/health fails (e.g. missing
    // "view users") or SMTP detection is inconclusive. Direct download is gated
    // separately via requiresEmailOrFilters / directDownloadAllowed.
    this.emailExportEnabled = true;
    this.refreshEmailExports();
  }

  refreshEmailExports(): void {
    if (!this.emailExportEnabled) {
      return;
    }
    this.isLoadingEmailExports = true;
    this.reportService.getEmailExports().subscribe({
      next: (res) => {
        this.isLoadingEmailExports = false;
        const list = res?.data?.exports;
        this.emailExports = Array.isArray(list) ? list : [];
        this.syncEmailExportsPoll();
      },
      error: () => {
        this.isLoadingEmailExports = false;
      }
    });
  }

  private syncEmailExportsPoll(): void {
    const hasActive = this.emailExports.some(
      (item) => item.status === 'pending' || item.status === 'processing'
    );
    if (hasActive && !this.emailExportsPollTimer) {
      this.emailExportsPollTimer = setInterval(() => this.refreshEmailExports(), 8000);
    } else if (!hasActive) {
      this.stopEmailExportsPoll();
    }
  }

  private stopEmailExportsPoll(): void {
    if (this.emailExportsPollTimer) {
      clearInterval(this.emailExportsPollTimer);
      this.emailExportsPollTimer = null;
    }
  }

  get pendingEmailExportCount(): number {
    return this.emailExports.filter(
      (item) => item.status === 'pending' || item.status === 'processing'
    ).length;
  }

  get emailExportsNearLimit(): boolean {
    return this.pendingEmailExportCount >= this.maxPendingEmailExports;
  }

  get recentEmailExports(): typeof this.emailExports {
    return this.emailExports.slice(0, 5);
  }

  emailExportStatusKey(status: string): string {
    const normalized = (status || '').toLowerCase();
    if (normalized === 'ready') {
      return 'REPORTS.EMAIL_QUEUE_STATUS_READY';
    }
    if (normalized === 'failed') {
      return 'REPORTS.EMAIL_QUEUE_STATUS_FAILED';
    }
    if (normalized === 'processing') {
      return 'REPORTS.EMAIL_QUEUE_STATUS_PROCESSING';
    }
    return 'REPORTS.EMAIL_QUEUE_STATUS_PENDING';
  }

  private initDates(): void {
    const now = new Date();
    const year = now.getFullYear();
    const useFullYear = ReportRunnerComponent.FULL_YEAR_SLUGS.has(this.slug);
    const asOfReport = ReportRunnerComponent.AS_OF_SLUGS.has(this.slug);
    const first = useFullYear
      ? new Date(year, 0, 1)
      : new Date(year, now.getMonth(), 1);
    const last = useFullYear
      ? new Date(year, 11, 31)
      : asOfReport
        ? now
        : new Date(year, now.getMonth() + 1, 0);
    this.startDate = this.formatLocalDate(first);
    this.endDate = this.formatLocalDate(last);
  }

  private applyRouteQueryParams(): void {
    const qp = this.route.snapshot.queryParamMap;
    const start = qp.get('start_date');
    const end = qp.get('end_date');
    if (start) {
      this.startDate = start;
    }
    if (end) {
      this.endDate = end;
    }

    if (ReportRunnerComponent.ACCOUNTING_SLUGS.has(this.slug)) {
      const accountType = qp.get('account_type');
      this.selectedAccountType = accountType
        ? this.reportI18n.resolveAccountTypeApiValue(accountType)
        : null;
      const accountId = qp.get('account_id');
      this.selectedAccountId = accountId ? Number(accountId) : null;
      const code = qp.get('account_code') || '';
      const name = qp.get('account_name') || '';
      this.selectedAccountLabel = this.reportI18n.formatAccountFilterLabel(code, name);
      this.accountingSearch = qp.get('search') || '';
      this.accountingHideZero = qp.get('hide_zero') === 'true' || qp.get('hide_zero') === '1';
      this.accountingReservationId = qp.get('reservation_id') || '';
      this.accountingSourceType = qp.get('source_type') || '';
      this.accountingOperation = qp.get('operation') || '';
      this.accountingMinAmount = qp.get('min_amount') || '';
      this.accountingMaxAmount = qp.get('max_amount') || '';
      return;
    }

    this.resetAccountingFilters();
  }

  private resetAccountingFilters(): void {
    this.selectedAccountType = null;
    this.selectedAccountId = null;
    this.selectedAccountLabel = '';
    this.accountingSearch = '';
    this.accountingHideZero = false;
    this.accountingReservationId = '';
    this.accountingSourceType = '';
    this.accountingOperation = '';
    this.accountingMinAmount = '';
    this.accountingMaxAmount = '';
  }

  get isAccountingReport(): boolean {
    return ReportRunnerComponent.ACCOUNTING_SLUGS.has(this.slug);
  }

  showAccountingFilter(field: string): boolean {
    switch (field) {
      case 'account_type':
        return ['chart-of-accounts', 'journal-entries', 'general-ledger', 'trial-balance'].includes(this.slug);
      case 'account_id':
        return ['journal-entries', 'general-ledger'].includes(this.slug);
      case 'search':
        return this.isAccountingReport;
      case 'hide_zero':
        return ['chart-of-accounts', 'trial-balance', 'cash-flow'].includes(this.slug);
      case 'reservation_id':
        return ['journal-entries', 'general-ledger', 'financial-audit-log'].includes(this.slug);
      case 'source_type':
        return this.slug === 'journal-entries';
      case 'operation':
        return this.slug === 'financial-audit-log';
      case 'amount_range':
        return ['journal-entries', 'general-ledger', 'trial-balance', 'cash-flow'].includes(this.slug);
      default:
        return false;
    }
  }

  get filteredAccountOptions(): { id: number; code: string; name_en: string; name_ar: string; type: string }[] {
    if (this.slug === 'cash-flow') {
      return this.chartAccounts.filter(account => account.type === 'asset' && account.code.startsWith('10'));
    }
    if (!this.selectedAccountType) {
      return this.chartAccounts;
    }
    return this.chartAccounts.filter(account => account.type === this.selectedAccountType);
  }

  onAccountingAccountTypeChange(): void {
    this.selectedAccountId = null;
    this.selectedAccountLabel = '';
    if (this.isGeneralLedgerReport && !this.selectedAccountType) {
      this.syncAccountingRoute(false);
      this.clearReportData();
      return;
    }
    this.onAccountingFilterChange();
  }

  onAccountingAccountChange(): void {
    const account = this.filteredAccountOptions.find(a => a.id === this.selectedAccountId) ?? null;
    this.selectedAccountLabel = account
      ? this.reportI18n.formatAccountFilterLabel(account.code, this.glAccountName(account))
      : '';
    this.onAccountingFilterChange();
  }

  onAccountingFilterChange(reload = true): void {
    this.syncAccountingRoute(false);
    this.currentPage = 1;
    if (!reload) {
      return;
    }
    if (this.isGeneralLedgerReport && !this.selectedAccountType) {
      this.clearReportData();
      return;
    }
    if (this.canRunReport) {
      this.loadReport(1);
    }
  }

  clearAccountingFilters(): void {
    this.resetAccountingFilters();
    this.syncAccountingRoute(true);
  }

  clearGeneralLedgerAccountFilter(): void {
    this.selectedAccountId = null;
    this.selectedAccountLabel = '';
    this.onAccountingFilterChange();
  }

  private accountingFilterParams(): Record<string, string | number | boolean | undefined> {
    if (!this.isAccountingReport) {
      return {};
    }

    return {
      account_type: this.selectedAccountType ?? undefined,
      account_id: this.selectedAccountId ?? undefined,
      search: this.accountingFilterText(this.accountingSearch) || undefined,
      hide_zero: this.accountingHideZero || undefined,
      reservation_id: this.accountingFilterText(this.accountingReservationId) || undefined,
      source_type: this.accountingSourceType || undefined,
      operation: this.accountingOperation || undefined,
      min_amount: this.accountingFilterText(this.accountingMinAmount) || undefined,
      max_amount: this.accountingFilterText(this.accountingMaxAmount) || undefined,
    };
  }

  private accountingFilterText(value: string | number | null | undefined): string {
    if (value === null || value === undefined) {
      return '';
    }

    return String(value).trim();
  }

  get isAccrualRevenueReport(): boolean {
    return this.slug === 'accrual-revenue';
  }

  get isRevenueSummaryReport(): boolean {
    return this.slug === 'revenue-summary';
  }

  get hiddenDaysCount(): number {
    const daysInPeriod = this.rawSummaryValue('Days in period');
    const daysShown = this.rawSummaryValue('Days shown');
    if (daysInPeriod === null || daysShown === null) {
      return 0;
    }
    return Math.max(0, daysInPeriod - daysShown);
  }

  private rawSummaryValue(label: string): number | null {
    const item = this.rawSummary.find(entry => entry.label === label);
    if (!item) {
      return null;
    }
    const value = Number(item.value);
    return Number.isFinite(value) ? value : null;
  }

  clearAccrualFilters(): void {
    if (!this.hideEmptyNights) {
      return;
    }
    this.hideEmptyNights = false;
    this.onAccrualFilterChange();
  }

  clearRevenueSummaryZeroFilter(): void {
    if (!this.hideZeroRows) {
      return;
    }
    this.hideZeroRows = false;
    this.onRevenueSummaryFilterChange();
  }

  private extraReportParams(): Record<string, string | number | boolean | undefined> {
    if (this.isAccrualRevenueReport && this.hideEmptyNights) {
      return { hide_empty_nights: 1 };
    }

    if (this.isRevenueSummaryReport && this.hideZeroRows) {
      return { hide_zero: 1 };
    }

    return {};
  }

  onAccrualFilterChange(): void {
    this.currentPage = 1;
    if (this.canRunReport) {
      this.loadReport(1);
    }
  }

  onRevenueSummaryFilterChange(): void {
    this.currentPage = 1;
    if (this.canRunReport) {
      this.loadReport(1);
    }
  }

  private syncAccountingRoute(reload: boolean): void {
    const queryParams: Record<string, string | number | null> = {
      start_date: this.startDate,
      end_date: this.endDate,
      account_type: this.selectedAccountType,
      account_id: this.selectedAccountId,
      account_code: this.selectedAccountId
        ? this.filteredAccountOptions.find(a => a.id === this.selectedAccountId)?.code ?? null
        : null,
      account_name: this.selectedAccountId
        ? this.glAccountName(this.filteredAccountOptions.find(a => a.id === this.selectedAccountId) ?? null)
        : null,
      search: this.accountingFilterText(this.accountingSearch) || null,
      hide_zero: this.accountingHideZero ? 'true' : null,
      reservation_id: this.accountingFilterText(this.accountingReservationId) || null,
      source_type: this.accountingSourceType || null,
      operation: this.accountingOperation || null,
      min_amount: this.accountingFilterText(this.accountingMinAmount) || null,
      max_amount: this.accountingFilterText(this.accountingMaxAmount) || null,
    };

    void this.router.navigate(['/reports/run', this.slug], { queryParams }).then(() => {
      if (reload && this.canRunReport) {
        this.applyRouteQueryParams();
        this.currentPage = 1;
        this.loadReport(1);
      }
    });
  }

  get isGeneralLedgerReport(): boolean {
    return this.slug === 'general-ledger';
  }

  get isJournalEntriesReport(): boolean {
    return this.slug === 'journal-entries';
  }

  get isChartOfAccountsReport(): boolean {
    return this.slug === 'chart-of-accounts';
  }

  get isReservationsListReport(): boolean {
    return this.slug === 'reservations-list';
  }

  get isOverviewReport(): boolean {
    return this.slug === 'overview';
  }

  get isClosingPackageReport(): boolean {
    return this.slug === 'closing-package';
  }

  get isFinancialAuditLogReport(): boolean {
    return this.slug === 'financial-audit-log';
  }

  get isCashFlowReport(): boolean {
    return this.slug === 'cash-flow';
  }

  get isTrialBalanceReport(): boolean {
    return this.slug === 'trial-balance';
  }

  get isCashBoxReport(): boolean {
    return this.slug === 'cash-box' || this.slug === 'payments-refunds';
  }

  get isArrivalsDeparturesReport(): boolean {
    return this.slug === 'arrivals-departures';
  }

  get isRoomBoardReport(): boolean {
    return this.slug === 'room-board';
  }

  get isAccountLedgerReport(): boolean {
    return this.isChartOfAccountsReport || this.isCashFlowReport || this.isTrialBalanceReport;
  }

  isReportRowClickable(row: Record<string, unknown>): boolean {
    if (this.isAccountLedgerReport) {
      return this.hasAccountLedgerLink(row);
    }

    if (this.isReservationsListReport) {
      return true;
    }

    if (this.isOverviewReport || this.isClosingPackageReport) {
      return this.hasDetailReportLink(row);
    }

    if (this.isFinancialAuditLogReport) {
      return this.hasReservationLink(row) || this.hasDetailReportLink(row);
    }

    if (this.isCashBoxReport) {
      return this.hasReservationLink(row);
    }

    if (this.isArrivalsDeparturesReport) {
      return this.hasReservationLink(row);
    }

    if (this.isRoomBoardReport) {
      return this.hasRoomHistoryLink(row);
    }

    if (this.isJournalEntriesReport) {
      return this.hasReservationLink(row);
    }

    if (this.isRevenueSummaryReport) {
      return this.hasChargeDateLink(row);
    }

    return this.isGeneralLedgerReport && this.hasReservationLink(row);
  }

  hasReservationLink(row: Record<string, unknown>): boolean {
    const reservationId = row['reservation_id'];
    if (reservationId === null || reservationId === undefined || reservationId === '') {
      return false;
    }
    const normalized = String(reservationId).trim();
    return normalized !== '' && normalized !== '—' && normalized !== '-' && Number(normalized) > 0;
  }

  hasChargeDateLink(row: Record<string, unknown>): boolean {
    const chargeDate = row['charge_date'];
    return typeof chargeDate === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(chargeDate);
  }

  hasRoomHistoryLink(row: Record<string, unknown>): boolean {
    const number = row['number'];
    if (number === null || number === undefined || number === '') {
      return false;
    }

    const normalized = String(number).trim();
    return normalized !== '' && normalized !== '—' && normalized !== '-';
  }

  hasDetailReportLink(row: Record<string, unknown>): boolean {
    const slug = row['detail_slug'];
    return typeof slug === 'string' && slug !== '';
  }

  get hasGeneralLedgerReservationLinks(): boolean {
    return this.isGeneralLedgerReport && this.rows.some((row) => this.hasReservationLink(row));
  }

  get hasJournalEntriesReservationLinks(): boolean {
    return this.isJournalEntriesReport && this.rows.some((row) => this.hasReservationLink(row));
  }

  get hasRevenueSummaryDateLinks(): boolean {
    return this.isRevenueSummaryReport && this.rows.some((row) => this.hasChargeDateLink(row));
  }

  get hasCashBoxReservationLinks(): boolean {
    return this.isCashBoxReport && this.rows.some((row) => this.hasReservationLink(row));
  }

  get hasArrivalsDeparturesReservationLinks(): boolean {
    return this.isArrivalsDeparturesReport && this.rows.some((row) => this.hasReservationLink(row));
  }

  get hasRoomBoardHistoryLinks(): boolean {
    return this.isRoomBoardReport && this.rows.some((row) => this.hasRoomHistoryLink(row));
  }

  get hasOverviewDetailLinks(): boolean {
    return this.isOverviewReport && this.rows.some((row) => this.hasDetailReportLink(row));
  }

  get hasClosingPackageDetailLinks(): boolean {
    return this.isClosingPackageReport && this.rows.some((row) => this.hasDetailReportLink(row));
  }

  get hasFinancialAuditLogLinks(): boolean {
    return this.isFinancialAuditLogReport && this.rows.some(
      (row) => this.hasReservationLink(row) || this.hasDetailReportLink(row)
    );
  }

  get hasAccountLedgerLinks(): boolean {
    return this.isAccountLedgerReport && this.rows.some((row) => this.isReportRowClickable(row));
  }

  get accountLedgerOpenHintKey(): string {
    if (this.isCashFlowReport) {
      return 'REPORTS.CASH_FLOW_OPEN_LEDGER';
    }
    if (this.isTrialBalanceReport) {
      return 'REPORTS.TRIAL_BALANCE_OPEN_LEDGER';
    }
    return 'REPORTS.COA_OPEN_LEDGER';
  }

  hasAccountLedgerLink(row: Record<string, unknown>): boolean {
    return this.resolveAccountIdFromRow(row) !== null;
  }

  onReportRowClick(row: Record<string, unknown>, event?: Event): void {
    if (!this.isReportRowClickable(row)) {
      return;
    }

    if (this.isAccountLedgerReport) {
      this.openGeneralLedgerForAccount(row, event);
      return;
    }

    if (this.isOverviewReport || this.isClosingPackageReport || this.isFinancialAuditLogReport) {
      if (this.hasReservationLink(row)) {
        this.openReservationDetail(row, event);
        return;
      }
      this.openDetailReport(row, event);
      return;
    }

    if (this.isRoomBoardReport) {
      this.openRoomHistory(row, event);
      return;
    }

    if (this.isRevenueSummaryReport) {
      this.openReservationsForChargeDate(row, event);
      return;
    }

    this.openReservationDetail(row, event);
  }

  openReservationsForChargeDate(row: Record<string, unknown>, event?: Event): void {
    event?.preventDefault();

    if (!this.hasChargeDateLink(row)) {
      return;
    }

    const chargeDate = String(row['charge_date']);
    void this.router.navigate(['/booking/reservations'], {
      queryParams: {
        date_from: chargeDate,
        date_to: chargeDate,
      },
    });
  }

  openDetailReport(row: Record<string, unknown>, event?: Event): void {
    event?.preventDefault();

    const slug = row['detail_slug'];
    if (typeof slug !== 'string' || slug === '') {
      return;
    }

    const queryParams: Record<string, string | number> = {
      start_date: this.startDate,
      end_date: this.endDate,
    };

    const reference = row['reference'];
    if (typeof reference === 'string' && reference !== '' && reference !== '—') {
      queryParams['search'] = reference;
    }

    const reservationId = row['reservation_id'];
    if (reservationId !== null && reservationId !== undefined && reservationId !== '') {
      queryParams['reservation_id'] = String(reservationId);
    }

    void this.router.navigate(['/reports/run', slug], { queryParams });
  }

  openGeneralLedgerForAccount(row: Record<string, unknown>, event?: Event): void {
    event?.preventDefault();
    if (!this.isAccountLedgerReport) {
      return;
    }

    const accountId = this.resolveAccountIdFromRow(row);
    if (accountId === null) {
      return;
    }

    const rawRow = this.rawRows.find(entry => String(entry['account_id'] ?? '') === String(accountId))
      ?? this.rawRows.find(entry => String(entry['code'] ?? '') === String(row['code'] ?? ''));
    const rawType = rawRow && typeof rawRow['type'] === 'string' ? rawRow['type'] : row['type'];
    const accountType = this.isCashFlowReport
      ? 'asset'
      : this.reportI18n.resolveAccountTypeApiValue(
        typeof rawType === 'string' ? rawType : String(rawType ?? '')
      );
    if (!accountType) {
      return;
    }

    this.router.navigate(['/reports/run/general-ledger'], {
      queryParams: {
        account_type: accountType,
        account_id: accountId,
        account_code: row['code'],
        account_name: row['name'],
        start_date: this.startDate,
        end_date: this.endDate,
      },
    });
  }

  private resolveAccountIdFromRow(row: Record<string, unknown>): string | number | null {
    const accountId = row['account_id'];
    if (accountId !== null && accountId !== undefined && accountId !== '') {
      return accountId as string | number;
    }

    const code = row['code'];
    if (typeof code !== 'string' || code === '') {
      return null;
    }

    const account = this.chartAccounts.find(entry => entry.code === code);
    return account?.id ?? null;
  }

  openReservationDetail(row: Record<string, unknown>, event?: Event): void {
    event?.preventDefault();

    const reservationId = row['reservation_id'];
    if (reservationId === null || reservationId === undefined || reservationId === '') {
      return;
    }

    void this.router.navigate(['/booking/reservations', reservationId]);
  }

  openRoomHistory(row: Record<string, unknown>, event?: Event): void {
    event?.preventDefault();

    if (!this.hasRoomHistoryLink(row)) {
      return;
    }

    const roomNumber = String(row['number']).trim();
    void this.router.navigate(['/admin/property/room', roomNumber, 'history']);
  }

  private loadChartAccounts(): void {
    if (!this.isAccountingReport || this.isLoadingChartAccounts) {
      return;
    }

    this.isLoadingChartAccounts = true;
    this.reportService.getChartOfAccounts(this.startDate, this.endDate).subscribe({
      next: (res) => {
        this.isLoadingChartAccounts = false;
        this.chartAccounts = (res?.data?.accounts ?? []) as typeof this.chartAccounts;
      },
      error: () => {
        this.isLoadingChartAccounts = false;
        this.chartAccounts = [];
      }
    });
  }

  glAccountName(account: { code?: string; name_en: string; name_ar: string } | null | undefined): string {
    if (!account) {
      return '';
    }
    if (account.code) {
      const localized = this.reportI18n.translateAccountName(account.code);
      if (localized) {
        return localized;
      }
    }
    return this.translate.currentLang === 'ar' ? (account.name_ar || account.name_en) : account.name_en;
  }

  private clearReportData(): void {
    this.rawColumns = [];
    this.rawSummary = [];
    this.rawRows = [];
    this.rawMetaLines = [];
    this.columns = [];
    this.summary = [];
    this.rows = [];
    this.metaLines = [];
    this.totalRows = 0;
    this.totalPages = 1;
    this.currentPage = 1;
    this.isTruncated = false;
    this.errorMessage = '';
  }

  private formatLocalDate(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  get showNoDataHint(): boolean {
    if (this.isLoading || this.errorMessage) {
      return false;
    }
    if (['room-board', 'ar-aging'].includes(this.slug)) {
      return false;
    }

    if (this.rawRows.length > 0) {
      return false;
    }

    const skipLabels = ['A/R balance', 'Total AR', 'Balanced', 'Dimensions', 'Active accounts', 'Snapshot date', 'Total rooms'];
    const periodMetrics = this.rawSummary.filter(s => !skipLabels.includes(s.label));
    if (periodMetrics.length === 0) {
      return this.rows.length === 0;
    }

    return periodMetrics.every(s => {
      const n = Number(String(s.value).replace('%', ''));
      return !Number.isFinite(n) || n === 0;
    });
  }

  private applyTranslations(): void {
    if (this.rawColumns.length) {
      this.columns = this.reportI18n.localizeColumns(this.rawColumns);
    }
    if (this.rawSummary.length) {
      this.summary = this.reportI18n.localizeSummary(this.rawSummary);
    }
    if (this.rawRows.length) {
      this.rows = this.reportI18n.localizeRows(this.rawColumns, this.rawRows);
    }
    if (this.rawMetaLines.length) {
      this.metaLines = this.reportI18n.localizeMetaLines(this.rawMetaLines);
    }
  }

  get dateRangeError(): boolean {
    if (this.isAsOfReport) {
      return !this.endDate;
    }
    return isReportDateRangeInvalid(this.startDate, this.endDate);
  }

  get compareDateRangeError(): boolean {
    if (!this.showComparison) {
      return false;
    }
    return isReportDateRangeInvalid(this.compareStartDate, this.compareEndDate);
  }

  get canRunReport(): boolean {
    if (this.isGeneralLedgerReport && !this.selectedAccountType) {
      return false;
    }
    return !this.dateRangeError && !this.compareDateRangeError;
  }

  onStartDateChange(): void {
    this.endDate = syncReportEndDate(this.startDate, this.endDate);
    if (this.isAccountingReport) {
      this.loadChartAccounts();
    }
    this.scheduleReportRefresh();
  }

  onEndDateChange(): void {
    if (isReportDateRangeInvalid(this.startDate, this.endDate)) {
      this.endDate = this.startDate;
    }
    this.scheduleReportRefresh();
  }

  private scheduleReportRefresh(): void {
    if (!this.slug) {
      return;
    }
    if (this.isLoading) {
      this.pendingDateRefresh = true;
      return;
    }
    if (this.reportRefreshTimer) {
      clearTimeout(this.reportRefreshTimer);
    }
    this.reportRefreshTimer = setTimeout(() => {
      if (this.canRunReport) {
        this.generateReport();
      }
    }, 450);
  }

  onCompareStartDateChange(): void {
    this.compareEndDate = syncReportEndDate(this.compareStartDate, this.compareEndDate);
    this.activeComparePreset = 'custom';
  }

  onCompareEndDateChange(): void {
    if (isReportDateRangeInvalid(this.compareStartDate, this.compareEndDate)) {
      this.compareEndDate = this.compareStartDate;
    }
    this.activeComparePreset = 'custom';
  }

  onToggleComparison(): void {
    if (this.showComparison) {
      if (!this.compareStartDate || !this.compareEndDate) {
        this.applyComparePreset('previous', false);
      }
    } else {
      this.activeComparePreset = null;
    }
    if (this.canRunReport) {
      this.generateReport();
    }
  }

  applyComparePreset(preset: 'previous' | 'last_year', run = true): void {
    const start = this.parseLocalDate(this.startDate);
    const end = this.parseLocalDate(this.endDate);
    if (!start || !end) {
      return;
    }

    let compareStart: Date;
    let compareEnd: Date;
    if (preset === 'last_year') {
      compareStart = new Date(start.getFullYear() - 1, start.getMonth(), start.getDate());
      compareEnd = new Date(end.getFullYear() - 1, end.getMonth(), end.getDate());
    } else {
      const msPerDay = 86_400_000;
      const spanDays = Math.round((end.getTime() - start.getTime()) / msPerDay);
      compareEnd = new Date(start.getFullYear(), start.getMonth(), start.getDate() - 1);
      compareStart = new Date(compareEnd.getFullYear(), compareEnd.getMonth(), compareEnd.getDate() - spanDays);
    }

    this.compareStartDate = this.formatLocalDate(compareStart);
    this.compareEndDate = this.formatLocalDate(compareEnd);
    this.activeComparePreset = preset;

    if (run && this.showComparison && this.canRunReport) {
      this.generateReport();
    }
  }

  private parseLocalDate(value: string): Date | null {
    if (!value) {
      return null;
    }
    const [y, m, d] = value.split('-').map(Number);
    if (!y || !m || !d) {
      return null;
    }
    return new Date(y, m - 1, d);
  }

  get comparisonActive(): boolean {
    return this.showComparison
      && this.supportsComparison
      && !!this.compareStartDate
      && !!this.compareEndDate
      && !this.compareDateRangeError;
  }

  private isCompareSummaryIndex(index: number): boolean {
    const raw = this.rawSummary[index];
    return !!raw && /^compare/i.test(raw.label);
  }

  get primarySummary(): { label: string; value: string | number }[] {
    return this.summary.filter((_, i) => !this.isCompareSummaryIndex(i));
  }

  get compareSummaryCards(): { label: string; value: string | number }[] {
    return this.summary.filter((_, i) => this.isCompareSummaryIndex(i));
  }

  get hasCompareSummary(): boolean {
    return this.comparisonActive && this.compareSummaryCards.length > 0;
  }

  get metaPeriodRange(): { start: string; end: string } | null {
    for (const line of this.rawMetaLines) {
      const match = line.match(/^Period: (\d{4}-\d{2}-\d{2}) → (\d{4}-\d{2}-\d{2})$/);
      if (match) {
        return { start: match[1], end: match[2] };
      }
    }
    return null;
  }

  get metaCompareRange(): { start: string; end: string } | null {
    for (const line of this.rawMetaLines) {
      const match = line.match(/^Compare: (\d{4}-\d{2}-\d{2}) → (\d{4}-\d{2}-\d{2})$/);
      if (match) {
        return { start: match[1], end: match[2] };
      }
    }
    return null;
  }

  get metaAsOfDate(): string | null {
    for (const line of this.rawMetaLines) {
      const match = line.match(/^As of: (\d{4}-\d{2}-\d{2})$/);
      if (match) {
        return match[1];
      }
    }
    return null;
  }

  /** Localized meta notes excluding period/compare/as-of header lines. */
  get metaNoteLines(): string[] {
    return this.metaLines.filter((_, index) => {
      const raw = this.rawMetaLines[index] || '';
      return !/^Period:|^Compare:|^As of:/.test(raw);
    });
  }

  get metaIntroLine(): string {
    const notes = this.metaNoteLines;
    if (!notes.length) {
      return '';
    }
    // Short lead sentence (intro) — keep longer explanatory notes in the list below.
    return notes[0].length <= 140 ? notes[0] : '';
  }

  get metaDetailNotes(): string[] {
    const intro = this.metaIntroLine;
    return this.metaNoteLines.filter((note) => note !== intro);
  }

  summaryIcon(label: string): string {
    const text = (label || '').toLowerCase();
    if (/occupancy|إشغال|نسبة/.test(text)) {
      return 'bi-pie-chart-fill';
    }
    if (/cash|نقد|صندوق|payment|دفع|refund|استرداد/.test(text)) {
      return 'bi-safe';
    }
    if (/(^|\s)ar(\s|$)|ذمم|aging|مستحق/.test(text)) {
      return 'bi-hourglass-split';
    }
    if (/tax|ضريب|vat/.test(text)) {
      return 'bi-receipt';
    }
    if (/revpar|adr|متوسط/.test(text)) {
      return 'bi-cash-stack';
    }
    if (/room|غرف|ليلة|nights/.test(text)) {
      return 'bi-door-open-fill';
    }
    if (/reserv|حجز|booking/.test(text)) {
      return 'bi-calendar-check-fill';
    }
    if (/debit|مدين/.test(text)) {
      return 'bi-journal-arrow-up';
    }
    if (/credit|دائن/.test(text)) {
      return 'bi-journal-arrow-down';
    }
    if (/balanced|متوازن/.test(text)) {
      return 'bi-check2-circle';
    }
    if (/balanc|رصيد/.test(text)) {
      return 'bi-calculator';
    }
    if (/reconcil|تسوية|−|-|فرق|gap|diff/.test(text)) {
      return 'bi-arrow-left-right';
    }
    if (/accrual|استحقاق|revenue|إيراد|earned/.test(text)) {
      return 'bi-graph-up-arrow';
    }
    return 'bi-bar-chart-fill';
  }

  summaryToneClass(item: { label: string; value: string | number }): string {
    const numeric = this.parseSummaryNumber(item.value);
    if (numeric === null) {
      return '';
    }
    if (numeric < 0) {
      return 'report-kpi-card--negative';
    }
    const label = (item.label || '').toLowerCase();
    if (/gap|diff|−|-|تسوية|فرق|reconcil/.test(label) && numeric > 0) {
      return 'report-kpi-card--warning';
    }
    if (numeric > 0 && /cash|نقد|revenue|إيراد|accrual|استحقاق|occupancy|إشغال/.test(label)) {
      return 'report-kpi-card--positive';
    }
    return '';
  }

  formatSummaryValue(value: string | number): string {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    if (typeof value === 'number') {
      return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    const asNumber = this.parseSummaryNumber(value);
    if (asNumber !== null && /^-?\d+(\.\d+)?$/.test(String(value).trim().replace(/,/g, ''))) {
      return asNumber.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    return String(value);
  }

  private parseSummaryNumber(value: string | number): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
      return value;
    }
    const raw = String(value ?? '')
      .replace(/%/g, '')
      .replace(/,/g, '')
      .trim();
    if (!raw || !/^-?\d+(\.\d+)?$/.test(raw)) {
      return null;
    }
    const n = Number(raw);
    return Number.isFinite(n) ? n : null;
  }

  loadReport(page = this.currentPage, options?: { soft?: boolean }): void {
    if (!this.slug) {
      return;
    }
    if (this.isGeneralLedgerReport && !this.selectedAccountType) {
      this.clearReportData();
      return;
    }
    if (!this.canRunReport) {
      if (!(this.isGeneralLedgerReport && !this.selectedAccountType)) {
        this.errorMessage = this.translate.instant('REPORTS.DATE_RANGE_ERROR');
      }
      return;
    }
    this.currentPage = page;
    if (options?.soft) {
      this.isSoftRefreshing = true;
    } else {
      this.isLoading = true;
    }
    this.errorMessage = '';

    this.reportService.runReport(this.slug, {
      start_date: this.isAsOfReport ? undefined : this.startDate,
      end_date: this.endDate,
      compare_start_date: this.showComparison && this.supportsComparison ? this.compareStartDate : undefined,
      compare_end_date: this.showComparison && this.supportsComparison ? this.compareEndDate : undefined,
      ...this.accountingFilterParams(),
      ...this.extraReportParams(),
      page: this.currentPage
    }).subscribe({
      next: (res) => {
        if (!res?.success) {
          this.errorMessage = res?.message || this.translate.instant('REPORTS.ERR_LOAD');
          this.isLoading = false;
          this.isSoftRefreshing = false;
          return;
        }
        const data = res.data || {};
        this.rawColumns = data.columns || [];
        this.rawSummary = data.summary || [];
        this.rawRows = data.rows || [];
        this.rows = this.rawRows;
        this.rawMetaLines = data.meta_lines || [];
        this.metaLines = this.rawMetaLines;
        this.totalRows = data.total_rows ?? this.rows.length;
        this.pageSize = data.per_page ?? 50;
        this.previewLimit = data.preview_limit ?? this.pageSize;
        this.exportLimit = data.max_direct_download_rows ?? data.export_limit ?? this.exportLimit;
        this.directDownloadAllowed = data.direct_download_allowed !== false
          && this.totalRows <= this.exportLimit;
        this.requiresEmailOrFilters = data.requires_email_or_filters === true
          || this.totalRows > this.exportLimit;
        this.currentPage = data.current_page ?? this.currentPage;
        this.totalPages = data.last_page ?? 1;
        this.isTruncated = !!data.is_truncated;
        this.applyTranslations();
        this.isLoading = false;
        this.isSoftRefreshing = false;
        if (this.pendingDateRefresh) {
          this.pendingDateRefresh = false;
          this.scheduleReportRefresh();
        }
      },
      error: (err) => {
        this.errorMessage = err.error?.message || err.message || this.translate.instant('REPORTS.ERR_LOAD');
        this.isLoading = false;
        this.isSoftRefreshing = false;
        if (this.pendingDateRefresh) {
          this.pendingDateRefresh = false;
          this.scheduleReportRefresh();
        }
      }
    });
  }

  generateReport(): void {
    if (!this.canRunReport) {
      this.errorMessage = this.translate.instant(
        this.isGeneralLedgerReport && !this.selectedAccountType
          ? 'REPORTS.GL_SELECT_TYPE_HINT'
          : 'REPORTS.DATE_RANGE_ERROR'
      );
      return;
    }
    this.currentPage = 1;
    this.loadReport(1);
  }

  get pages(): Array<number | 'ellipsis'> {
    const total = this.totalPages;
    const current = this.currentPage;
    if (total <= 7) {
      return Array.from({ length: total }, (_, i) => i + 1);
    }
    const items: Array<number | 'ellipsis'> = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);
    if (start > 2) {
      items.push('ellipsis');
    }
    for (let i = start; i <= end; i++) {
      items.push(i);
    }
    if (end < total - 1) {
      items.push('ellipsis');
    }
    items.push(total);
    return items;
  }

  setPage(page: number | 'ellipsis'): void {
    if (page === 'ellipsis') {
      return;
    }
    if (page >= 1 && page <= this.totalPages && page !== this.currentPage) {
      this.loadReport(page);
    }
  }

  get drillDownHintKey(): string {
    if (this.isLoading || !this.rows.length) {
      return '';
    }
    if (this.isChartOfAccountsReport) {
      return 'REPORTS.COA_OPEN_LEDGER';
    }
    if (this.hasAccountLedgerLinks && !this.isChartOfAccountsReport) {
      return this.accountLedgerOpenHintKey;
    }
    if (this.isReservationsListReport) {
      return 'REPORTS.RESERVATIONS_LIST_OPEN_DETAIL';
    }
    if (this.hasGeneralLedgerReservationLinks) {
      return 'REPORTS.GL_OPEN_RESERVATION_DETAIL';
    }
    if (this.hasJournalEntriesReservationLinks) {
      return 'REPORTS.JOURNAL_ENTRIES_OPEN_RESERVATION_DETAIL';
    }
    if (this.hasRevenueSummaryDateLinks) {
      return 'REPORTS.REVENUE_SUMMARY_OPEN_RESERVATIONS';
    }
    if (this.hasCashBoxReservationLinks) {
      return 'REPORTS.CASH_BOX_OPEN_RESERVATION_DETAIL';
    }
    if (this.hasArrivalsDeparturesReservationLinks) {
      return 'REPORTS.ARRIVALS_DEPARTURES_OPEN_RESERVATION_DETAIL';
    }
    if (this.hasRoomBoardHistoryLinks) {
      return 'REPORTS.ROOM_BOARD_OPEN_HISTORY';
    }
    if (this.hasOverviewDetailLinks) {
      return 'REPORTS.OVERVIEW_OPEN_DETAIL';
    }
    if (this.hasClosingPackageDetailLinks) {
      return 'REPORTS.CLOSING_PACKAGE_OPEN_DETAIL';
    }
    if (this.hasFinancialAuditLogLinks) {
      return 'REPORTS.AUDIT_LOG_OPEN_DETAIL';
    }
    return '';
  }

  get emptyStateKey(): string {
    if (this.isLoading || this.errorMessage) {
      return '';
    }
    if (this.isGeneralLedgerReport && !this.selectedAccountType) {
      return 'REPORTS.EMPTY_STATE_GL_TYPE';
    }
    if (this.showNoDataHint) {
      return 'REPORTS.NO_DATA';
    }
    if (this.rows.length === 0 && this.canRunReport) {
      return 'REPORTS.EMPTY_STATE_NO_ROWS';
    }
    return '';
  }

  get showSmtpOffExportCard(): boolean {
    // Kept for template compatibility; email export is never gated off in the UI.
    return false;
  }

  isNumericColumn(col: ExportColumn): boolean {
    const key = (col.key || '').toLowerCase();
    return /amount|balance|debit|credit|total|revenue|cash|tax|adr|revpar|price|net|paid|rate|percent|occupancy|pct|qty|count|nights/.test(key);
  }

  cellClass(col: ExportColumn): string {
    return this.isNumericColumn(col) ? 'report-cell--numeric num' : '';
  }

  headerCellClass(col: ExportColumn, index: number): string {
    return [this.cellClass(col), index === 0 ? 'report-cell--sticky' : ''].filter(Boolean).join(' ');
  }

  bodyCellClass(col: ExportColumn, index: number): string {
    return this.headerCellClass(col, index);
  }

  get hasPageNumericTotals(): boolean {
    return this.columns.some((col, index) => index > 0 && this.isNumericColumn(col) && this.pageTotalFor(col) !== null);
  }

  pageTotalFor(col: ExportColumn): number | null {
    if (!this.isNumericColumn(col) || !this.rows.length) {
      return null;
    }
    let sum = 0;
    let count = 0;
    for (const row of this.rows) {
      const raw = row[col.key];
      const num = typeof raw === 'number' ? raw : Number(raw);
      if (Number.isFinite(num)) {
        sum += num;
        count += 1;
      }
    }
    return count > 0 ? sum : null;
  }

  formatPageTotal(col: ExportColumn): string {
    const total = this.pageTotalFor(col);
    if (total === null) {
      return '';
    }
    return total.toLocaleString(undefined, { maximumFractionDigits: 2 });
  }

  formatCellValue(col: ExportColumn, row: Record<string, unknown>): string {
    const raw = row[col.key];
    if (raw === null || raw === undefined || raw === '') {
      return '—';
    }
    if (typeof raw === 'number' && this.isNumericColumn(col)) {
      return raw.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    return String(raw);
  }

  get paginationFrom(): number {
    if (this.totalRows === 0) {
      return 0;
    }
    return (this.currentPage - 1) * this.pageSize + 1;
  }

  get paginationTo(): number {
    return Math.min(this.currentPage * this.pageSize, this.totalRows);
  }

  get guideKey(): string {
    if (!this.slug) {
      return 'REPORTS.GUIDE.DEFAULT';
    }
    return `REPORTS.GUIDE.${this.slug.replace(/-/g, '_').toUpperCase()}`;
  }

  requestEmailExport(): void {
    const email = this.recipientEmail.trim();
    if (!email) {
      this.emailRequestError = this.translate.instant('REPORTS.EMAIL_REQUIRED');
      return;
    }
    if (!this.canRunReport) {
      this.emailRequestError = this.translate.instant('REPORTS.DATE_RANGE_ERROR');
      return;
    }

    this.isRequestingEmail = true;
    this.emailRequestError = '';
    this.emailRequestMessage = '';

    const payload: {
      email: string;
      format: 'excel' | 'pdf';
      start_date?: string;
      end_date?: string;
      compare_start_date?: string;
      compare_end_date?: string;
    } = {
      email,
      format: this.emailFormat,
      end_date: this.endDate
    };

    if (!this.isAsOfReport) {
      payload.start_date = this.startDate;
    }
    if (this.showComparison && this.supportsComparison && this.compareStartDate && this.compareEndDate) {
      payload.compare_start_date = this.compareStartDate;
      payload.compare_end_date = this.compareEndDate;
    }

    this.reportService.requestEmailExport(this.slug, payload).subscribe({
      next: (res) => {
        this.isRequestingEmail = false;
        if (!res?.success) {
          this.emailRequestError = res?.message || this.translate.instant('REPORTS.EMAIL_REQUEST_ERR');
          return;
        }
        this.emailRequestMessage = this.translate.instant('REPORTS.EMAIL_REQUEST_OK', {
          email,
          format: this.translate.instant(this.emailFormat === 'pdf' ? 'REPORTS.EMAIL_FORMAT_PDF' : 'REPORTS.EMAIL_FORMAT_EXCEL')
        });
        this.refreshEmailExports();
      },
      error: (err) => {
        this.isRequestingEmail = false;
        this.emailRequestError = err.error?.message || err.message || this.translate.instant('REPORTS.EMAIL_REQUEST_ERR');
        if (err?.status === 429) {
          this.refreshEmailExports();
        }
      }
    });
  }

  get canDirectExport(): boolean {
    return this.rows.length > 0 && this.directDownloadAllowed && !this.requiresEmailOrFilters;
  }

  /** Hover text on disabled export buttons when the row limit blocks direct download. */
  get directExportBlockedTooltip(): string {
    if (!this.requiresEmailOrFilters || this.isLoading) {
      return '';
    }
    if (this.emailExportEnabled) {
      return this.translate.instant('REPORTS.EXPORT_BTN_TOOLTIP_BLOCKED');
    }
    return this.translate.instant('REPORTS.EXPORT_TOO_LARGE_FILTERS', {
      total: this.totalRows,
      exportLimit: this.exportLimit,
    });
  }

  focusEmailExport(): void {
    const el = document.getElementById('report-email-export');
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (el?.classList) {
      el.classList.remove('report-email-card--pulse');
      // restart CSS pulse attention when navigating from the banner/CTA
      void el.offsetWidth;
      el.classList.add('report-email-card--pulse');
    }
    const input = document.getElementById('reportEmailRecipient') as HTMLInputElement | null
      ?? el?.querySelector?.<HTMLInputElement>('input[type="email"]')
      ?? null;
    window.setTimeout(() => input?.focus(), 280);
  }

  exportExcel(): void {
    if (!this.guardDirectExport()) {
      return;
    }
    this.runExport(rows => {
      this.exportService.exportExcel(this.columns, rows, `${this.slug}-${this.startDate}`, this.slug);
    });
  }

  exportPdf(): void {
    if (!this.guardDirectExport()) {
      return;
    }
    this.runExport(rows => {
      const title = this.translate.instant(this.titleKey);
      const summary = [
        `${this.translate.instant('REPORTS.PERIOD')}: ${this.startDate} → ${this.endDate}`,
        ...this.metaLines
      ];
      this.exportService.exportPdf(title, this.columns, rows, `${this.slug}-${this.startDate}`, summary);
    });
  }

  exportCsv(): void {
    if (!this.guardDirectExport()) {
      return;
    }
    this.runExport(rows => {
      this.exportService.exportCsv(this.columns, rows, `${this.slug}-${this.startDate}`);
    });
  }

  private guardDirectExport(): boolean {
    if (this.canDirectExport) {
      return true;
    }
    this.errorMessage = this.translate.instant(
      this.emailExportEnabled ? 'REPORTS.EXPORT_TOO_LARGE_EMAIL' : 'REPORTS.EXPORT_TOO_LARGE_FILTERS',
      { total: this.totalRows, exportLimit: this.exportLimit }
    );
    return false;
  }

  private runExport(execute: (rows: Record<string, unknown>[]) => void): void {
    if (!this.columns.length || !this.rows.length || this.isExporting || !this.canDirectExport) {
      return;
    }

    this.isExporting = true;
    this.resolveExportRows().subscribe({
      next: rows => {
        this.isExporting = false;
        if (!rows.length) {
          return;
        }
        execute(rows);
      },
      error: () => {
        this.isExporting = false;
      }
    });
  }

  private resolveExportRows() {
    if (this.totalRows <= this.rawRows.length) {
      return of(this.rawRows.length ? this.rawRows : this.rows);
    }

    return this.reportService.runReport(this.slug, {
      start_date: this.isAsOfReport ? undefined : this.startDate,
      end_date: this.endDate,
      compare_start_date: this.showComparison && this.supportsComparison ? this.compareStartDate : undefined,
      compare_end_date: this.showComparison && this.supportsComparison ? this.compareEndDate : undefined,
      ...this.accountingFilterParams(),
      ...this.extraReportParams(),
      for_export: true
    }).pipe(
      map(res => (res?.success ? (res.data?.rows as Record<string, unknown>[]) : []) ?? []),
      catchError(() => of([]))
    );
  }

  goBack(): void {
    this.router.navigate(['/reports']);
  }
}
