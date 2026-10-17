import { Component, OnInit, OnDestroy } from '@angular/core';
import { ChartConfiguration, ChartOptions } from 'chart.js';
import { HotelService } from '../../services/hotel.service';
import { Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { Subscription } from 'rxjs';
import { ReportExportService, ExportColumn } from '../../services/report-export.service';
import { DataService } from '../../services/Api/data.service';
import {
  buildYearOptions,
  FinancialPeriodBounds,
  FinancialPeriodPreset,
  resolveFinancialPeriod,
  ResolvedFinancialPeriod
} from '../../core/financial-period';

interface StatCard {
  labelKey: string;
  value: string;
  change: string;
  changeClass: string;
  icon: string;
  iconClass: string;
  reportSlug: string;
  group: 'primary' | 'secondary';
  tone: 'neutral' | 'positive' | 'negative' | 'warning';
}

interface TransactionRow {
  id: string;
  description: string;
  category: string;
  type: 'income' | 'expense';
  amount: number;
  time: string;
  method: string;
}

@Component({
    selector: 'app-financials',
    templateUrl: './financials.component.html',
    styleUrls: ['./financials.component.css'],
    standalone: false
})
export class FinancialsComponent implements OnInit, OnDestroy {
  isLoading = true;
  errorMessage = '';

  preset: FinancialPeriodPreset = 'month';
  monthValue = '';
  yearValue = new Date().getFullYear();
  yearOptions = buildYearOptions(10);

  resolvedPeriod: ResolvedFinancialPeriod | null = null;
  periodScopeText = '';
  compareNote = '';

  private bounds: FinancialPeriodBounds | null = null;
  private langSub?: Subscription;

  stats: StatCard[] = [
    { labelKey: 'FINANCIALS.STAT_ACCRUAL', value: '0', change: '', changeClass: 'text-muted', icon: 'bi-currency-dollar', iconClass: 'icon-emerald', reportSlug: 'accrual-revenue', group: 'primary', tone: 'positive' },
    { labelKey: 'FINANCIALS.STAT_CASH_IN', value: '0', change: '', changeClass: 'text-muted', icon: 'bi-graph-up-arrow', iconClass: 'icon-blue', reportSlug: 'cash-box', group: 'primary', tone: 'positive' },
    { labelKey: 'FINANCIALS.STAT_CASH_OUT', value: '0', change: '', changeClass: 'text-muted', icon: 'bi-graph-down', iconClass: 'icon-red', reportSlug: 'payments-refunds', group: 'primary', tone: 'negative' },
    { labelKey: 'FINANCIALS.STAT_NET_CASH', value: '0', change: '', changeClass: 'text-muted', icon: 'bi-arrow-left-right', iconClass: 'icon-purple', reportSlug: 'cash-box', group: 'primary', tone: 'neutral' },
    { labelKey: 'FINANCIALS.STAT_ROOM_NIGHTS', value: '0', change: '', changeClass: 'text-muted', icon: 'bi-moon-stars', iconClass: 'icon-slate', reportSlug: 'accrual-revenue', group: 'secondary', tone: 'neutral' },
    { labelKey: 'FINANCIALS.STAT_RESERVATIONS', value: '0', change: '', changeClass: 'text-muted', icon: 'bi-calendar-check', iconClass: 'icon-slate', reportSlug: 'reservations-list', group: 'secondary', tone: 'neutral' },
    { labelKey: 'FINANCIALS.STAT_AR', value: '0', change: '', changeClass: 'text-muted', icon: 'bi-receipt', iconClass: 'icon-amber', reportSlug: 'ar-aging', group: 'secondary', tone: 'warning' }
  ];

  get primaryStats(): StatCard[] {
    return this.stats.filter((s) => s.group === 'primary');
  }

  get secondaryStats(): StatCard[] {
    return this.stats.filter((s) => s.group === 'secondary');
  }

  transactions: TransactionRow[] = [];
  periodIncome = 0;
  periodExpenses = 0;
  periodNet = 0;
  seriesGranularity = 'day';
  isExporting = false;
  printGeneratedAt = '';
  periodNoteExpanded = false;

  private seriesSnapshot: Array<{
    bucket: string;
    accrual: number;
    cash_in: number;
    cash_out: number;
    cash_net: number;
  }> = [];

  get hasSeriesData(): boolean {
    return this.seriesSnapshot.some(
      (s) => s.accrual !== 0 || s.cash_in !== 0 || s.cash_out !== 0 || s.cash_net !== 0
    );
  }

  constructor(
    private hotelService: HotelService,
    private router: Router,
    private translate: TranslateService,
    private exportService: ReportExportService,
    private dataService: DataService
  ) {}

  ngOnInit(): void {
    const now = new Date();
    this.monthValue = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    this.langSub = this.translate.onLangChange.subscribe(() => {
      this.updatePeriodLabels();
      if (this.resolvedPeriod) {
        this.loadData();
      }
    });
    this.hotelService.getFinancialBounds().subscribe({
      next: (res) => {
        if (res?.success && res.data) {
          this.bounds = res.data;
        }
        this.applyPreset(this.preset);
      },
      error: () => {
        this.errorMessage = this.translate.instant('FINANCIALS.ERR_LOAD');
        this.isLoading = false;
      }
    });
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
  }

  setPreset(p: FinancialPeriodPreset): void {
    this.preset = p;
    this.applyPreset(p);
  }

  onMonthChange(): void {
    if (this.preset === 'month') {
      this.applyPreset('month');
    }
  }

  onYearChange(): void {
    if (this.preset === 'year') {
      this.applyPreset('year');
    }
  }

  private applyPreset(p: FinancialPeriodPreset): void {
    this.resolvedPeriod = resolveFinancialPeriod(p, {
      monthValue: this.monthValue,
      yearValue: this.yearValue,
      bounds: this.bounds
    });
    this.updatePeriodLabels();
    this.loadData();
  }

  private updatePeriodLabels(): void {
    if (!this.resolvedPeriod) {
      return;
    }
    const presetLabel = this.translate.instant(
      this.resolvedPeriod.labelKey,
      this.resolvedPeriod.labelParams ?? {}
    );
    this.periodScopeText = this.translate.instant('FINANCIALS.PERIOD_SCOPE', {
      start: this.resolvedPeriod.start,
      end: this.resolvedPeriod.end,
      preset: presetLabel
    });
  }

  loadData(): void {
    if (!this.resolvedPeriod) {
      return;
    }
    this.isLoading = true;
    this.errorMessage = '';

    this.hotelService
      .getFinancialDashboard(this.resolvedPeriod.start, this.resolvedPeriod.end)
      .subscribe({
        next: (res) => {
          if (!res?.success || !res.data) {
            this.errorMessage = res?.message || this.translate.instant('FINANCIALS.ERR_LOAD');
            this.isLoading = false;
            return;
          }
          this.bindDashboard(res.data);
          this.isLoading = false;
        },
        error: () => {
          this.errorMessage = this.translate.instant('FINANCIALS.ERR_LOAD');
          this.isLoading = false;
        }
      });
  }

  private bindDashboard(data: any): void {
    const kpis = data.kpis ?? {};
    const comparison = data.comparison ?? {};
    const sym = this.translate.instant('COMMON.CURRENCY_SYMBOL');

    this.stats[0].value = sym + this.formatNum(kpis.accrual_total);
    this.stats[0].change = this.formatPct(comparison.pct_accrual);
    this.stats[0].changeClass = this.pctClass(comparison.pct_accrual);
    this.stats[0].tone = this.toneFromPct(comparison.pct_accrual, 'positive');

    this.stats[1].value = sym + this.formatNum(kpis.cash_in);
    this.stats[1].change = '';
    this.stats[1].changeClass = 'text-muted';
    this.stats[1].tone = 'positive';

    this.stats[2].value = sym + this.formatNum(kpis.cash_out);
    this.stats[2].change = '';
    this.stats[2].changeClass = 'text-muted';
    this.stats[2].tone = 'negative';

    this.stats[3].value = sym + this.formatNum(kpis.cash_net);
    this.stats[3].change = this.formatPct(comparison.pct_cash_net);
    this.stats[3].changeClass = this.pctClass(comparison.pct_cash_net);
    this.stats[3].tone = this.toneFromPct(comparison.pct_cash_net, 'neutral');

    this.stats[4].value = String(kpis.room_nights ?? 0);
    this.stats[4].change = '';
    this.stats[5].value = String(kpis.reservation_count ?? 0);
    this.stats[5].change = '';
    this.stats[6].value = sym + this.formatNum(kpis.ar_balance);
    this.stats[6].change = '';
    this.stats[6].tone = Number(kpis.ar_balance ?? 0) > 0 ? 'warning' : 'neutral';

    if (comparison.compare_start && comparison.compare_end) {
      this.compareNote = this.translate.instant('FINANCIALS.COMPARE_RANGE', {
        start: comparison.compare_start,
        end: comparison.compare_end
      });
    }

    this.seriesGranularity = data.series_granularity ?? 'day';
    this.seriesSnapshot = (data.series ?? []).map((s: any) => ({
      bucket: s.bucket,
      accrual: Number(s.accrual ?? 0),
      cash_in: Number(s.cash_in ?? 0),
      cash_out: Number(s.cash_out ?? 0),
      cash_net: Number(s.cash_net ?? 0)
    }));
    this.updateTimeSeriesChart(this.seriesSnapshot);
    this.updateBreakdownCharts(kpis);

    const rows = data.recent_transactions ?? [];
    this.transactions = rows.map((row: any) => ({
      id: String(row.id),
      description: `${this.translate.instant('FINANCIALS.RESERVATION')} #${row.reservation_id}`,
      category: row.type === 0
        ? this.translate.instant('FINANCIALS.PAYMENT')
        : this.translate.instant('FINANCIALS.REFUND'),
      type: row.type === 0 ? 'income' : 'expense',
      amount: Number(row.pay),
      time: (row.created_at || '').split('T')[0],
      method: row.type === 0
        ? this.translate.instant('FINANCIALS.CASH')
        : this.translate.instant('FINANCIALS.REFUND')
    }));

    this.periodIncome = this.transactions
      .filter(t => t.type === 'income')
      .reduce((s, t) => s + t.amount, 0);
    this.periodExpenses = this.transactions
      .filter(t => t.type === 'expense')
      .reduce((s, t) => s + t.amount, 0);
    this.periodNet = Number(kpis.cash_net ?? 0);
  }

  private formatPct(pct: number | null | undefined): string {
    if (pct === null || pct === undefined) {
      return '—';
    }
    const sign = pct > 0 ? '+' : '';
    return `${sign}${pct}%`;
  }

  private pctClass(pct: number | null | undefined): string {
    if (pct === null || pct === undefined || pct === 0) {
      return 'text-muted';
    }
    return pct > 0 ? 'text-success' : 'text-danger';
  }

  private toneFromPct(
    pct: number | null | undefined,
    fallback: StatCard['tone']
  ): StatCard['tone'] {
    if (pct === null || pct === undefined || pct === 0) {
      return fallback;
    }
    return pct > 0 ? 'positive' : 'negative';
  }

  changeBadgeClass(stat: StatCard): string {
    if (stat.changeClass.includes('success')) {
      return 'fin-kpi-card__delta--up';
    }
    if (stat.changeClass.includes('danger')) {
      return 'fin-kpi-card__delta--down';
    }
    return 'fin-kpi-card__delta--flat';
  }

  private formatNum(n: number): string {
    return Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  openReports(): void {
    this.router.navigate(['/reports/run/accrual-revenue']);
  }

  openStatReport(stat: StatCard): void {
    if (!stat.reportSlug || !this.resolvedPeriod) {
      return;
    }
    void this.router.navigate(['/reports/run', stat.reportSlug], {
      queryParams: {
        start_date: this.resolvedPeriod.start,
        end_date: this.resolvedPeriod.end
      }
    });
  }

  openCashBox(): void {
    this.router.navigate(['/reports/run/cash-box']);
  }

  private triggerPrint(): void {
    this.printGeneratedAt = new Date().toLocaleString(
      this.translate.currentLang === 'ar' ? 'ar' : 'en'
    );
    document.body.classList.add('financials-print-active');
    const cleanup = (): void => {
      document.body.classList.remove('financials-print-active');
      window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);
    requestAnimationFrame(() => {
      requestAnimationFrame(() => window.print());
    });
  }

  exportDashboard(format: 'excel' | 'pdf' | 'csv' | 'print'): void {
    if (!this.resolvedPeriod || this.isLoading) {
      return;
    }
    if (format === 'print') {
      this.triggerPrint();
      return;
    }

    this.isExporting = true;
    const { start, end } = this.resolvedPeriod;
    this.hotelService.getEarningsList(start, end, 'all', 1, 500).subscribe({
      next: (listRes) => {
        const rows = Array.isArray(listRes?.data) ? listRes.data : [];
        void this.finishExport(format, this.mapExportTransactions(rows));
      },
      error: () => {
        void this.finishExport(format, this.mapExportTransactionsFromView());
      }
    });
  }

  private async finishExport(
    format: 'excel' | 'pdf' | 'csv',
    transactionRows: Record<string, unknown>[]
  ): Promise<void> {
    const exportLimit = 40;
    if (transactionRows.length > exportLimit) {
      this.isExporting = false;
      this.dataService.showToast(
        this.translate.instant('REPORTS.EXPORT_TOO_LARGE_EMAIL', {
          total: transactionRows.length,
          exportLimit,
        }),
        'warning'
      );
      void this.router.navigate(['/reports/run/cash-box'], {
        queryParams: this.resolvedPeriod
          ? { start_date: this.resolvedPeriod.start, end_date: this.resolvedPeriod.end }
          : undefined,
      });
      return;
    }

    try {
      await this.runExport(format, transactionRows);
      this.dataService.showToast(
        this.translate.instant('FINANCIALS.EXPORT_SUCCESS'),
        'success'
      );
    } catch {
      this.dataService.showToast(
        this.translate.instant('FINANCIALS.ERR_EXPORT'),
        'error'
      );
    } finally {
      this.isExporting = false;
    }
  }

  private mapExportTransactionsFromView(): Record<string, unknown>[] {
    return this.transactions.map(t => ({
      time: t.time,
      description: t.description,
      category: t.category,
      method: t.method,
      amount: (t.type === 'income' ? '+' : '-') + t.amount
    }));
  }

  private mapExportTransactions(rows: any[]): Record<string, unknown>[] {
    const sym = this.translate.instant('COMMON.CURRENCY_SYMBOL');
    return rows.map((row: any) => {
      const isPayment = row.type === 0;
      return {
        time: (row.created_at || '').split('T')[0],
        description: `${this.translate.instant('FINANCIALS.RESERVATION')} #${row.reservation_id}`,
        category: isPayment
          ? this.translate.instant('FINANCIALS.PAYMENT')
          : this.translate.instant('FINANCIALS.REFUND'),
        method: isPayment
          ? this.translate.instant('FINANCIALS.CASH')
          : this.translate.instant('FINANCIALS.REFUND'),
        amount: (isPayment ? '+' : '-') + sym + Number(row.pay ?? 0)
      };
    });
  }

  private async runExport(format: 'excel' | 'pdf' | 'csv', transactionRows: Record<string, unknown>[]): Promise<void> {
    const filename = `financials-${this.resolvedPeriod!.start}-${this.resolvedPeriod!.end}`;
    const transactionColumns: ExportColumn[] = [
      { key: 'time', label: this.translate.instant('FINANCIALS.TIME') },
      { key: 'description', label: this.translate.instant('FINANCIALS.DESCRIPTION') },
      { key: 'category', label: this.translate.instant('FINANCIALS.CATEGORY') },
      { key: 'method', label: this.translate.instant('FINANCIALS.METHOD') },
      { key: 'amount', label: this.translate.instant('FINANCIALS.AMOUNT') }
    ];
    const seriesColumns: ExportColumn[] = [
      { key: 'bucket', label: this.translate.instant('FINANCIALS.DATE') },
      { key: 'accrual', label: this.translate.instant('FINANCIALS.STAT_ACCRUAL') },
      { key: 'cash_in', label: this.translate.instant('FINANCIALS.STAT_CASH_IN') },
      { key: 'cash_out', label: this.translate.instant('FINANCIALS.STAT_CASH_OUT') },
      { key: 'cash_net', label: this.translate.instant('FINANCIALS.STAT_NET_CASH') }
    ];
    const seriesRows = this.seriesSnapshot.map(s => ({ ...s }));
    const summaryRows = this.stats.map(s => ({
      metric: this.translate.instant(s.labelKey),
      value: s.value + (s.change && s.change !== '—' ? ` (${s.change})` : '')
    }));

    if (format === 'excel') {
      this.exportService.exportFinancialDashboardExcel({
        filename,
        summaryColumns: [
          { key: 'metric', label: this.translate.instant('FINANCIALS.EXPORT_COL_METRIC') },
          { key: 'value', label: this.translate.instant('FINANCIALS.EXPORT_COL_VALUE') }
        ],
        summaryRows,
        seriesColumns,
        seriesRows,
        transactionColumns,
        transactionRows,
        sheetLabels: {
          summary: this.translate.instant('FINANCIALS.EXPORT_SHEET_SUMMARY'),
          series: this.translate.instant('FINANCIALS.EXPORT_SHEET_SERIES'),
          transactions: this.translate.instant('FINANCIALS.EXPORT_SHEET_TRANSACTIONS')
        }
      });
      return;
    }

    if (format === 'pdf') {
      await this.exportService.exportFinancialDashboardPdf({
        title: this.translate.instant('FINANCIALS.TITLE'),
        periodLine: this.periodScopeText,
        compareLine: this.compareNote || undefined,
        generatedAt: new Date().toLocaleString(this.translate.currentLang === 'ar' ? 'ar' : 'en'),
        kpis: this.stats.map(s => ({
          label: this.translate.instant(s.labelKey),
          value: s.value + (s.change && s.change !== '—' ? ` (${s.change})` : '')
        })),
        seriesColumns,
        seriesRows,
        transactionColumns,
        transactionRows,
        filename
      });
      return;
    }

    this.exportService.exportCsv(transactionColumns, transactionRows, filename);
  }

  revenueVsExpensesData: ChartConfiguration<'line'>['data'] = {
    labels: [],
    datasets: []
  };

  revenueVsExpensesOptions: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: true } },
    scales: { y: { beginAtZero: true } }
  };

  revenueBreakdownData: ChartConfiguration<'bar'>['data'] = {
    labels: [],
    datasets: [{ label: '', data: [], backgroundColor: ['#0f4c4c', '#6366f1'] }]
  };

  revenueBreakdownOptions: ChartOptions<'bar'> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true } }
  };

  cashSplitData: ChartConfiguration<'doughnut'>['data'] = {
    labels: [],
    datasets: [{ data: [], backgroundColor: ['#10b981', '#ef4444'] }]
  };

  cashSplitOptions: ChartOptions<'doughnut'> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } }
  };

  private updateTimeSeriesChart(series: Array<{ bucket: string; accrual: number; cash_in: number; cash_out: number }>): void {
    const labels = series.map(s => s.bucket);
    const accrualLabel = this.translate.instant('FINANCIALS.CHART_ACCRUAL');
    const cashInLabel = this.translate.instant('FINANCIALS.CHART_CASH_IN');
    const cashOutLabel = this.translate.instant('FINANCIALS.CHART_CASH_OUT');

    this.revenueVsExpensesData = {
      labels,
      datasets: [
        {
          label: accrualLabel,
          data: series.map(s => s.accrual),
          borderColor: '#0f4c4c',
          backgroundColor: 'rgba(15, 76, 76,0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: labels.length > 40 ? 0 : 3
        },
        {
          label: cashInLabel,
          data: series.map(s => s.cash_in),
          borderColor: '#10b981',
          backgroundColor: 'rgba(16,185,129,0.08)',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 40 ? 0 : 2
        },
        {
          label: cashOutLabel,
          data: series.map(s => s.cash_out),
          borderColor: '#ef4444',
          backgroundColor: 'rgba(239,68,68,0.08)',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 40 ? 0 : 2
        }
      ]
    };
  }

  private updateBreakdownCharts(kpis: any): void {
    const sym = this.translate.instant('COMMON.CURRENCY_SYMBOL');
    const nightlyLabel = this.translate.instant('FINANCIALS.NIGHTLY_REVENUE');
    const monthlyLabel = this.translate.instant('FINANCIALS.MONTHLY_REVENUE');
    const cashInLabel = this.translate.instant('FINANCIALS.STAT_CASH_IN');
    const cashOutLabel = this.translate.instant('FINANCIALS.STAT_CASH_OUT');

    this.revenueBreakdownData = {
      labels: [nightlyLabel, monthlyLabel],
      datasets: [{
        label: this.periodScopeText,
        data: [Number(kpis.nightly ?? 0), Number(kpis.monthly ?? 0)],
        backgroundColor: ['#0f4c4c', '#6366f1']
      }]
    };

    this.cashSplitData = {
      labels: [cashInLabel, cashOutLabel],
      datasets: [{
        data: [Number(kpis.cash_in ?? 0), Number(kpis.cash_out ?? 0)],
        backgroundColor: ['#10b981', '#ef4444']
      }]
    };
  }
}
