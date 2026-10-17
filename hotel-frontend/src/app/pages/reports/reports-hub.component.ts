import { Component } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { ReportCatalogItem, ReportCatalogService, ReportCategory } from '../../services/report-catalog.service';

@Component({
    selector: 'app-reports-hub',
    templateUrl: './reports-hub.component.html',
    styleUrls: ['./reports-hub.component.css'],
    standalone: false
})
export class ReportsHubComponent {
  search = '';
  activeCategory: ReportCategory | 'all' = 'all';

  readonly categories: { key: ReportCategory | 'all'; labelKey: string; icon: string }[] = [
    { key: 'all', labelKey: 'REPORTS.CAT_ALL', icon: 'bi-grid-3x3-gap' },
    { key: 'overview', labelKey: 'REPORTS.CAT_OVERVIEW', icon: 'bi-speedometer2' },
    { key: 'operational', labelKey: 'REPORTS.CAT_OPERATIONAL', icon: 'bi-building' },
    { key: 'financial', labelKey: 'REPORTS.CAT_FINANCIAL', icon: 'bi-cash-stack' },
    { key: 'accounting', labelKey: 'REPORTS.CAT_ACCOUNTING', icon: 'bi-journal-text' }
  ];

  /** High-traffic shortcuts shown when still visible under RBAC. */
  private readonly popularSlugs = [
    'overview',
    'cash-box',
    'accrual-revenue',
    'occupancy',
    'reservations-list',
    'journal-entries',
  ];

  constructor(
    public catalog: ReportCatalogService,
    private translate: TranslateService,
  ) {}

  get filteredReports(): ReportCatalogItem[] {
    const term = this.search.trim().toLowerCase();
    return this.catalog.getVisible().filter(item => {
      if (this.activeCategory !== 'all' && item.category !== this.activeCategory) {
        return false;
      }
      if (!term) {
        return true;
      }
      const title = this.translate.instant(item.titleKey).toLowerCase();
      const desc = this.translate.instant(item.descKey).toLowerCase();
      return item.slug.includes(term)
        || title.includes(term)
        || desc.includes(term)
        || item.titleKey.toLowerCase().includes(term);
    });
  }

  get popularReports(): ReportCatalogItem[] {
    const visible = this.catalog.getVisible();
    return this.popularSlugs
      .map(slug => visible.find(item => item.slug === slug))
      .filter((item): item is ReportCatalogItem => !!item);
  }

  get totalVisibleCount(): number {
    return this.catalog.getVisible().length;
  }

  get resultCount(): number {
    return this.filteredReports.length;
  }

  get showPopular(): boolean {
    return !this.search.trim() && this.activeCategory === 'all' && this.popularReports.length > 0;
  }

  categoryLabelKey(category: ReportCategory): string {
    return this.categories.find((c) => c.key === category)?.labelKey || 'REPORTS.CAT_ALL';
  }

  categoryIcon(category: ReportCategory): string {
    return this.categories.find((c) => c.key === category)?.icon || 'bi-file-earmark-bar-graph';
  }

  clearFilters(): void {
    this.search = '';
    this.activeCategory = 'all';
  }

  setCategory(cat: ReportCategory | 'all'): void {
    this.activeCategory = cat;
  }
}
