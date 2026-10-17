import { TestBed } from '@angular/core/testing';
import { FormsModule } from '@angular/forms';
import { RouterTestingModule } from '@angular/router/testing';
import { TranslateModule, TranslateService } from '@ngx-translate/core';
import { ReportsHubComponent } from './reports-hub.component';
import { ReportCatalogService } from '../../services/report-catalog.service';
import { AuthPermissionService } from '../../services/auth-permission.service';

describe('ReportsHubComponent', () => {
  let component: ReportsHubComponent;
  let translate: TranslateService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportsHubComponent],
      imports: [FormsModule, TranslateModule.forRoot(), RouterTestingModule],
      providers: [
        ReportCatalogService,
        { provide: AuthPermissionService, useValue: { hasAny: () => true } },
      ],
    }).compileComponents();

    translate = TestBed.inject(TranslateService);
    translate.setTranslation('en', {
      REPORTS: {
        ITEMS: {
          OVERVIEW: 'Daily overview',
          OVERVIEW_DESC: 'Snapshot of hotel performance',
          CASH_BOX: 'Cash box',
          CASH_BOX_DESC: 'Payments and refunds',
        },
      },
    });
    translate.use('en');

    const fixture = TestBed.createComponent(ReportsHubComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('filters by translated title text', () => {
    component.search = 'overview';
    expect(component.filteredReports.some((r) => r.slug === 'overview')).toBeTrue();
  });

  it('exposes popular shortcuts from visible catalog', () => {
    expect(component.popularReports.length).toBeGreaterThan(0);
    expect(component.showPopular).toBeTrue();
    expect(component.popularReports.every((r) => !!r.route)).toBeTrue();
  });

  it('resets filters', () => {
    component.search = 'cash';
    component.activeCategory = 'financial';
    component.clearFilters();
    expect(component.search).toBe('');
    expect(component.activeCategory).toBe('all');
  });
});
