import { Injectable } from '@angular/core';
import { AuthPermissionService } from './auth-permission.service';

export type ReportCategory = 'operational' | 'financial' | 'accounting' | 'overview';

export interface ReportCatalogItem {
  slug: string;
  route: string;
  category: ReportCategory;
  icon: string;
  titleKey: string;
  descKey: string;
  permissions?: string[];
  implemented: boolean;
}

type CatalogSeed = Omit<ReportCatalogItem, 'route'>;

@Injectable({ providedIn: 'root' })
export class ReportCatalogService {
  private readonly catalog: ReportCatalogItem[];

  private readonly seeds: CatalogSeed[] = [
    { slug: 'overview', category: 'overview', icon: 'bi-speedometer2', titleKey: 'REPORTS.ITEMS.OVERVIEW', descKey: 'REPORTS.ITEMS.OVERVIEW_DESC', permissions: ['view revenue', 'view earnings'], implemented: true },
    { slug: 'room-board', category: 'operational', icon: 'bi-grid-3x3-gap', titleKey: 'REPORTS.ITEMS.ROOM_BOARD', descKey: 'REPORTS.ITEMS.ROOM_BOARD_DESC', permissions: ['view reservations'], implemented: true },
    { slug: 'arrivals-departures', category: 'operational', icon: 'bi-door-open', titleKey: 'REPORTS.ITEMS.ARRIVALS', descKey: 'REPORTS.ITEMS.ARRIVALS_DESC', permissions: ['view reservations'], implemented: true },
    { slug: 'reservations-list', category: 'operational', icon: 'bi-calendar-check', titleKey: 'REPORTS.ITEMS.RESERVATIONS', descKey: 'REPORTS.ITEMS.RESERVATIONS_DESC', permissions: ['view reservations'], implemented: true },
    { slug: 'occupancy', category: 'operational', icon: 'bi-pie-chart', titleKey: 'REPORTS.ITEMS.OCCUPANCY', descKey: 'REPORTS.ITEMS.OCCUPANCY_DESC', permissions: ['view reports', 'view reservations'], implemented: true },
    { slug: 'accrual-revenue', category: 'financial', icon: 'bi-graph-up', titleKey: 'REPORTS.ITEMS.ACCRUAL', descKey: 'REPORTS.ITEMS.ACCRUAL_DESC', permissions: ['view revenue', 'view financial reports'], implemented: true },
    { slug: 'cash-box', category: 'financial', icon: 'bi-safe', titleKey: 'REPORTS.ITEMS.CASH_BOX', descKey: 'REPORTS.ITEMS.CASH_BOX_DESC', permissions: ['view earnings', 'view financial reports'], implemented: true },
    { slug: 'revenue-summary', category: 'financial', icon: 'bi-bar-chart-line', titleKey: 'REPORTS.ITEMS.REVENUE_SUMMARY', descKey: 'REPORTS.ITEMS.REVENUE_SUMMARY_DESC', permissions: ['view revenue', 'view financial reports'], implemented: true },
    { slug: 'accrual-cash-reconciliation', category: 'financial', icon: 'bi-arrow-left-right', titleKey: 'REPORTS.ITEMS.RECONCILIATION', descKey: 'REPORTS.ITEMS.RECONCILIATION_DESC', permissions: ['view revenue', 'view earnings', 'view financial reports'], implemented: true },
    { slug: 'ar-aging', category: 'financial', icon: 'bi-hourglass-split', titleKey: 'REPORTS.ITEMS.AR_AGING', descKey: 'REPORTS.ITEMS.AR_AGING_DESC', permissions: ['view financial reports'], implemented: true },
    { slug: 'adjustments', category: 'financial', icon: 'bi-sliders', titleKey: 'REPORTS.ITEMS.ADJUSTMENTS', descKey: 'REPORTS.ITEMS.ADJUSTMENTS_DESC', permissions: ['view financial reports'], implemented: true },
    { slug: 'tax', category: 'financial', icon: 'bi-receipt', titleKey: 'REPORTS.ITEMS.TAX', descKey: 'REPORTS.ITEMS.TAX_DESC', permissions: ['view financial reports'], implemented: true },
    { slug: 'revpar', category: 'financial', icon: 'bi-cash-stack', titleKey: 'REPORTS.ITEMS.REVPAR', descKey: 'REPORTS.ITEMS.REVPAR_DESC', permissions: ['view financial reports'], implemented: true },
    { slug: 'by-dimension', category: 'financial', icon: 'bi-diagram-2', titleKey: 'REPORTS.ITEMS.BY_DIMENSION', descKey: 'REPORTS.ITEMS.BY_DIMENSION_DESC', permissions: ['view financial reports'], implemented: true },
    { slug: 'peak-analysis', category: 'financial', icon: 'bi-lightning', titleKey: 'REPORTS.ITEMS.PEAK', descKey: 'REPORTS.ITEMS.PEAK_DESC', permissions: ['view revenue', 'view financial reports'], implemented: true },
    { slug: 'payments-refunds', category: 'financial', icon: 'bi-wallet2', titleKey: 'REPORTS.ITEMS.PAYMENTS', descKey: 'REPORTS.ITEMS.PAYMENTS_DESC', permissions: ['view earnings', 'view financial reports'], implemented: true },
    { slug: 'closing-package', category: 'financial', icon: 'bi-archive', titleKey: 'REPORTS.ITEMS.CLOSING', descKey: 'REPORTS.ITEMS.CLOSING_DESC', permissions: ['view financial reports'], implemented: true },
    { slug: 'chart-of-accounts', category: 'accounting', icon: 'bi-journal-text', titleKey: 'REPORTS.ITEMS.COA', descKey: 'REPORTS.ITEMS.COA_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'journal-entries', category: 'accounting', icon: 'bi-journal-plus', titleKey: 'REPORTS.ITEMS.JOURNAL', descKey: 'REPORTS.ITEMS.JOURNAL_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'general-ledger', category: 'accounting', icon: 'bi-book', titleKey: 'REPORTS.ITEMS.GL', descKey: 'REPORTS.ITEMS.GL_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'trial-balance', category: 'accounting', icon: 'bi-list-columns', titleKey: 'REPORTS.ITEMS.TRIAL_BALANCE', descKey: 'REPORTS.ITEMS.TRIAL_BALANCE_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'cash-flow', category: 'accounting', icon: 'bi-water', titleKey: 'REPORTS.ITEMS.CASH_FLOW', descKey: 'REPORTS.ITEMS.CASH_FLOW_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'financial-audit-log', category: 'accounting', icon: 'bi-shield-check', titleKey: 'REPORTS.ITEMS.AUDIT_LOG', descKey: 'REPORTS.ITEMS.AUDIT_LOG_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'accounting-export-qoyod', category: 'accounting', icon: 'bi-file-earmark-spreadsheet', titleKey: 'REPORTS.ITEMS.EXPORT_QOYOD', descKey: 'REPORTS.ITEMS.EXPORT_QOYOD_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'accounting-export-daftra', category: 'accounting', icon: 'bi-file-earmark-spreadsheet', titleKey: 'REPORTS.ITEMS.EXPORT_DAFTRA', descKey: 'REPORTS.ITEMS.EXPORT_DAFTRA_DESC', permissions: ['view accounting reports'], implemented: true },
    { slug: 'accounting-export-generic', category: 'accounting', icon: 'bi-file-earmark-spreadsheet', titleKey: 'REPORTS.ITEMS.EXPORT_GENERIC', descKey: 'REPORTS.ITEMS.EXPORT_GENERIC_DESC', permissions: ['view accounting reports'], implemented: true }
  ];

  constructor(private authPerm: AuthPermissionService) {
    this.catalog = this.seeds.map(item => ({
      ...item,
      route: `/reports/run/${item.slug}`
    }));
  }

  getAll(): ReportCatalogItem[] {
    return this.catalog;
  }

  getVisible(): ReportCatalogItem[] {
    return this.catalog.filter(item => {
      if (!item.permissions?.length) {
        return true;
      }
      return this.authPerm.hasAny(...item.permissions);
    });
  }

  getBySlug(slug: string): ReportCatalogItem | undefined {
    return this.catalog.find(c => c.slug === slug);
  }

  getByCategory(category: ReportCategory): ReportCatalogItem[] {
    return this.getVisible().filter(c => c.category === category);
  }
}
