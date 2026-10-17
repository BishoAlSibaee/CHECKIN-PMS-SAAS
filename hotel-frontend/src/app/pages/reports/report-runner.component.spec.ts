import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { of } from 'rxjs';
import { ReportRunnerComponent } from './report-runner.component';
import { ReportCatalogService } from '../../services/report-catalog.service';
import { ReportService } from '../../services/report.service';
import { ReportExportService } from '../../services/report-export.service';
import { ReportI18nService } from '../../services/report-i18n.service';
import { DataService } from '../../services/Api/data.service';
import { RealtimeService } from '../../services/realtime.service';
import { HotelLiveScope } from '../../models/realtime.models';

describe('ReportRunnerComponent realtime', () => {
  let component: ReportRunnerComponent;
  let fixture: ComponentFixture<ReportRunnerComponent>;
  const scopeHandlers = new Map<HotelLiveScope, () => void>();
  let loadReportSpy: jasmine.Spy;

  beforeEach(async () => {
    scopeHandlers.clear();

    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'revenue-summary' }),
          },
        },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'revenue-summary',
              titleKey: 'REPORTS.REVENUE_SUMMARY',
              descKey: 'REPORTS.REVENUE_SUMMARY_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        { provide: ReportI18nService, useValue: { translateColumns: (c: unknown[]) => c } },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: (scope: HotelLiveScope, handler: () => void) => {
              scopeHandlers.set(scope, handler);
              return () => scopeHandlers.delete(scope);
            },
          },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    loadReportSpy = spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('subscribes to collections and reservations scopes', () => {
    expect(scopeHandlers.has('collections')).toBeTrue();
    expect(scopeHandlers.has('reservations')).toBeTrue();
    expect(scopeHandlers.has('occupancy_board')).toBeTrue();
    expect(scopeHandlers.has('dashboard')).toBeTrue();
  });

  it('soft reloads financial report on collections event', () => {
    scopeHandlers.get('collections')!();
    expect(loadReportSpy).toHaveBeenCalledWith(component.currentPage, { soft: true });
  });

  it('passes hide_zero when revenue-summary zero-row filter is enabled', () => {
    component.hideZeroRows = true;

    const params = (component as unknown as { extraReportParams: () => Record<string, unknown> }).extraReportParams();

    expect(params).toEqual({ hide_zero: 1 });
  });

  it('marks revenue-summary rows with charge_date as clickable', () => {
    expect(component.isReportRowClickable({ charge_date: '2026-07-20' })).toBeTrue();
    expect(component.isReportRowClickable({ charge_date: '' })).toBeFalse();
  });

  it('navigates to reservations filtered by charge_date when a revenue-summary row is clicked', () => {
    const router = TestBed.inject(Router);

    component.onReportRowClick({ charge_date: '2026-07-20' });

    expect(router.navigate).toHaveBeenCalledWith(['/booking/reservations'], {
      queryParams: { date_from: '2026-07-20', date_to: '2026-07-20' },
    });
  });

  it('does not reload occupancy-only scopes for financial slug', () => {
    loadReportSpy.calls.reset();
    scopeHandlers.get('occupancy_board')!();
    expect(loadReportSpy).not.toHaveBeenCalled();
  });
});

describe('ReportRunnerComponent reservations-list navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'reservations-list' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'reservations-list',
              titleKey: 'REPORTS.RESERVATIONS',
              descKey: 'REPORTS.RESERVATIONS_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('navigates to reservation detail when a reservations-list row is clicked', () => {
    component.openReservationDetail({ reservation_id: 17652 });

    expect(router.navigate).toHaveBeenCalledWith(['/booking/reservations', 17652]);
  });
});

describe('ReportRunnerComponent general-ledger navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'general-ledger' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'general-ledger',
              titleKey: 'REPORTS.GL',
              descKey: 'REPORTS.GL_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('marks general-ledger rows with reservation_id as clickable', () => {
    const row = { reservation_id: 17652 };

    expect(component.isReportRowClickable(row)).toBeTrue();
  });

  it('navigates to reservation detail when a general-ledger row is clicked', () => {
    component.onReportRowClick({ reservation_id: 17652 });

    expect(router.navigate).toHaveBeenCalledWith(['/booking/reservations', 17652]);
  });
});

describe('ReportRunnerComponent journal-entries navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'journal-entries' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'journal-entries',
              titleKey: 'REPORTS.JOURNAL_ENTRIES',
              descKey: 'REPORTS.JOURNAL_ENTRIES_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('marks journal-entries rows with reservation_id as clickable', () => {
    expect(component.isReportRowClickable({ reservation_id: 17652 })).toBeTrue();
    expect(component.isReportRowClickable({ reservation_id: '' })).toBeFalse();
  });

  it('navigates to reservation detail when a journal-entries row is clicked', () => {
    component.onReportRowClick({ reservation_id: 17652 });

    expect(router.navigate).toHaveBeenCalledWith(['/booking/reservations', 17652]);
  });
});

describe('ReportRunnerComponent financial-audit-log row navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'financial-audit-log' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'financial-audit-log',
              titleKey: 'REPORTS.AUDIT_LOG',
              descKey: 'REPORTS.AUDIT_LOG_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('marks financial-audit-log rows with reservation_id as clickable', () => {
    expect(component.isReportRowClickable({ reservation_id: 17663 })).toBeTrue();
  });

  it('navigates to reservation detail when an audit-log row with reservation_id is clicked', () => {
    component.onReportRowClick({ reservation_id: 17663 });

    expect(router.navigate).toHaveBeenCalledWith(['/booking/reservations', 17663]);
  });

  it('opens journal-entries report when audit-log row has detail_slug only', () => {
    component.onReportRowClick({ detail_slug: 'journal-entries', reference: 'JE-42' });

    expect(router.navigate).toHaveBeenCalledWith(
      ['/reports/run', 'journal-entries'],
      jasmine.objectContaining({
        queryParams: jasmine.objectContaining({ search: 'JE-42' }),
      }),
    );
  });
});

describe('ReportRunnerComponent closing-package navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'closing-package' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'closing-package',
              titleKey: 'REPORTS.CLOSING_PACKAGE',
              descKey: 'REPORTS.CLOSING_PACKAGE_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('marks closing-package rows with detail_slug as clickable', () => {
    expect(component.isReportRowClickable({ detail_slug: 'cash-box' })).toBeTrue();
    expect(component.isReportRowClickable({ metric: 'Cash in' })).toBeFalse();
  });

  it('opens the linked detail report when a closing-package row is clicked', () => {
    component.startDate = '2026-08-01';
    component.endDate = '2026-08-31';
    component.onReportRowClick({
      metric: 'Cash in',
      amount: 100,
      detail_slug: 'cash-box',
    });

    expect(router.navigate).toHaveBeenCalledWith(
      ['/reports/run', 'cash-box'],
      jasmine.objectContaining({
        queryParams: jasmine.objectContaining({
          start_date: '2026-08-01',
          end_date: '2026-08-31',
        }),
      }),
    );
  });
});

describe('ReportRunnerComponent cash-flow navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'cash-flow' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'cash-flow',
              titleKey: 'REPORTS.ITEMS.CASH_FLOW',
              descKey: 'REPORTS.ITEMS.CASH_FLOW_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
            resolveAccountTypeApiValue: (value: string) => value,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
    component.startDate = '2026-01-01';
    component.endDate = '2026-01-31';
  });

  it('hides account picker filters for cash-flow', () => {
    expect(component.showAccountingFilter('account_type')).toBeFalse();
    expect(component.showAccountingFilter('account_id')).toBeFalse();
  });

  it('opens general ledger when a cash-flow account row is clicked', () => {
    component.onReportRowClick({
      account_id: 5,
      code: '1010',
      name: 'Cash and Bank',
    });

    expect(router.navigate).toHaveBeenCalledWith(
      ['/reports/run/general-ledger'],
      jasmine.objectContaining({
        queryParams: jasmine.objectContaining({
          account_type: 'asset',
          account_id: 5,
          account_code: '1010',
          start_date: '2026-01-01',
          end_date: '2026-01-31',
        }),
      }),
    );
  });
});

describe('ReportRunnerComponent trial-balance navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'trial-balance' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'trial-balance',
              titleKey: 'REPORTS.ITEMS.TRIAL_BALANCE',
              descKey: 'REPORTS.ITEMS.TRIAL_BALANCE_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
            resolveAccountTypeApiValue: (value: string) => value,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
    component.startDate = '2026-01-01';
    component.endDate = '2026-01-31';
  });

  it('opens general ledger when a trial-balance row is clicked', () => {
    component.onReportRowClick({
      account_id: 3,
      code: '4010',
      type: 'revenue',
      name: 'Room Revenue',
    });

    expect(router.navigate).toHaveBeenCalledWith(
      ['/reports/run/general-ledger'],
      jasmine.objectContaining({
        queryParams: jasmine.objectContaining({
          account_type: 'revenue',
          account_id: 3,
          account_code: '4010',
          start_date: '2026-01-01',
          end_date: '2026-01-31',
        }),
      }),
    );
  });

  it('resolves account id by code when account_id is missing', () => {
    component.chartAccounts = [{
      id: 8,
      code: '1010',
      name_en: 'Cash',
      name_ar: 'نقد',
      type: 'asset',
    }];

    component.onReportRowClick({
      code: '1010',
      type: 'asset',
      name: 'Cash',
    });

    expect(router.navigate).toHaveBeenCalledWith(
      ['/reports/run/general-ledger'],
      jasmine.objectContaining({
        queryParams: jasmine.objectContaining({
          account_type: 'asset',
          account_id: 8,
        }),
      }),
    );
  });
});

describe('ReportRunnerComponent cash-box navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'cash-box' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'cash-box',
              titleKey: 'REPORTS.ITEMS.CASH_BOX',
              descKey: 'REPORTS.ITEMS.CASH_BOX_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('marks cash-box rows with reservation_id as clickable', () => {
    expect(component.isReportRowClickable({ reservation_id: 17660 })).toBeTrue();
    expect(component.isReportRowClickable({ reservation_id: '' })).toBeFalse();
  });

  it('navigates to reservation detail when a cash-box row is clicked', () => {
    component.onReportRowClick({ reservation_id: 17660 });

    expect(router.navigate).toHaveBeenCalledWith(['/booking/reservations', 17660]);
  });
});

describe('ReportRunnerComponent arrivals-departures navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'arrivals-departures' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'arrivals-departures',
              titleKey: 'REPORTS.ITEMS.ARRIVALS_DEPARTURES',
              descKey: 'REPORTS.ITEMS.ARRIVALS_DEPARTURES_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('marks arrivals-departures rows with reservation_id as clickable', () => {
    expect(component.isReportRowClickable({ reservation_id: 17652 })).toBeTrue();
    expect(component.isReportRowClickable({ reservation_id: '' })).toBeFalse();
  });

  it('navigates to reservation detail when an arrivals-departures row is clicked', () => {
    component.onReportRowClick({ reservation_id: 17652 });

    expect(router.navigate).toHaveBeenCalledWith(['/booking/reservations', 17652]);
  });
});

describe('ReportRunnerComponent room-board navigation', () => {
  let component: ReportRunnerComponent;
  let router: jasmine.SpyObj<Router>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of({ get: () => 'room-board' }),
            snapshot: { queryParamMap: { get: () => null } },
          },
        },
        { provide: Router, useValue: jasmine.createSpyObj('Router', ['navigate']) },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'room-board',
              titleKey: 'REPORTS.ITEMS.ROOM_BOARD',
              descKey: 'REPORTS.ITEMS.ROOM_BOARD_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({
              success: true,
              data: {
                columns: [],
                summary: [],
                rows: [],
                total_rows: 0,
                per_page: 50,
                current_page: 1,
                last_page: 1,
              },
            }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: {} },
        {
          provide: ReportI18nService,
          useValue: {
            localizeColumns: (c: unknown[]) => c,
            localizeSummary: (s: unknown[]) => s,
            localizeRows: (_c: unknown[], rows: unknown[]) => rows,
            localizeMetaLines: (lines: string[]) => lines,
          },
        },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('marks room-board rows with room number as clickable', () => {
    expect(component.isReportRowClickable({ number: '102' })).toBeTrue();
    expect(component.isReportRowClickable({ number: '—' })).toBeFalse();
  });

  it('navigates to room history when a room-board row is clicked', () => {
    component.onReportRowClick({ number: '102' });

    expect(router.navigate).toHaveBeenCalledWith(['/admin/property/room', '102', 'history']);
  });
});

describe('ReportRunnerComponent direct export policy', () => {
  let component: ReportRunnerComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReportRunnerComponent],
      imports: [TranslateModule.forRoot(), HttpClientTestingModule, FormsModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: { paramMap: of({ get: () => 'reservations-list' }) },
        },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        {
          provide: ReportCatalogService,
          useValue: {
            getBySlug: () => ({
              slug: 'reservations-list',
              titleKey: 'REPORTS.RESERVATIONS_LIST',
              descKey: 'REPORTS.RESERVATIONS_LIST_DESC',
            }),
          },
        },
        {
          provide: ReportService,
          useValue: {
            runReport: () => of({ success: true, data: { rows: [], columns: [], total_rows: 0 } }),
            requestEmailExport: () => of({ success: true }),
            getEmailExports: () => of({ success: true, data: { exports: [] } }),
          },
        },
        { provide: ReportExportService, useValue: { exportExcel: jasmine.createSpy('exportExcel') } },
        { provide: ReportI18nService, useValue: { translateColumns: (c: unknown[]) => c } },
        {
          provide: DataService,
          useValue: {
            Url: 'http://test/api/',
            user$: { value: { email: 'user@test.com' } },
            getHeaders: () => ({}),
          },
        },
        { provide: RealtimeService, useValue: { onScope: () => () => undefined } },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(ReportRunnerComponent);
    component = fixture.componentInstance;
    spyOn(component, 'loadReport').and.stub();
    fixture.detectChanges();
  });

  it('disables direct export when the API marks the result as too large', () => {
    component.rows = [{ id: 1 }];
    component.totalRows = 600;
    component.exportLimit = 40;
    component.directDownloadAllowed = false;
    component.requiresEmailOrFilters = true;
    component.emailExportEnabled = true;

    expect(component.canDirectExport).toBeFalse();
    expect(component.directExportBlockedTooltip).toContain('REPORTS.EXPORT_BTN_TOOLTIP_BLOCKED');
    component.exportExcel();
    expect(component.errorMessage).toContain('REPORTS.EXPORT_TOO_LARGE_EMAIL');
    spyOn(document, 'getElementById').and.returnValue({
      scrollIntoView: jasmine.createSpy('scrollIntoView'),
      querySelector: () => null,
    } as unknown as HTMLElement);
    component.focusEmailExport();
    expect(document.getElementById).toHaveBeenCalledWith('report-email-export');
  });

  it('allows direct export when under the row limit', () => {
    component.rows = [{ id: 1 }];
    component.columns = [{ key: 'id', label: 'ID' }];
    component.totalRows = 10;
    component.exportLimit = 40;
    component.directDownloadAllowed = true;
    component.requiresEmailOrFilters = false;

    expect(component.canDirectExport).toBeTrue();
  });

  it('uses ellipsis pagination for large page counts', () => {
    component.totalPages = 20;
    component.currentPage = 10;
    expect(component.pages).toEqual([1, 'ellipsis', 9, 10, 11, 'ellipsis', 20]);
  });

  it('keeps email export available when the result is too large for direct download', () => {
    component.emailExportEnabled = true;
    component.requiresEmailOrFilters = true;
    component.isLoading = false;
    expect(component.showSmtpOffExportCard).toBeFalse();
    expect(component.emailExportEnabled).toBeTrue();
  });

  it('does not disable email export when system health is unavailable', () => {
    component.emailExportEnabled = false;
    (component as unknown as { loadMailConfig: () => void }).loadMailConfig();
    expect(component.emailExportEnabled).toBeTrue();
  });

  it('formats numeric cells and marks numeric columns', () => {
    const col = { key: 'balance', label: 'Balance' };
    expect(component.isNumericColumn(col)).toBeTrue();
    expect(component.cellClass(col)).toBe('report-cell--numeric num');
    expect(component.formatCellValue(col, { balance: 1234.5 })).toContain('1');
  });

  it('styles summary KPI cards by label and value', () => {
    expect(component.summaryIcon('Accrual revenue')).toBe('bi-graph-up-arrow');
    expect(component.formatSummaryValue(1644.51)).toContain('1');
    expect(component.summaryToneClass({ label: 'Net cash', value: -10 })).toBe('report-kpi-card--negative');
  });

  it('maps trial-balance summary labels to existing bootstrap icons', () => {
    expect(component.summaryIcon('Total debit')).toBe('bi-journal-arrow-up');
    expect(component.summaryIcon('Total credit')).toBe('bi-journal-arrow-down');
    expect(component.summaryIcon('Balanced')).toBe('bi-check2-circle');
    expect(component.summaryIcon('إجمالي المدين')).toBe('bi-journal-arrow-up');
    expect(component.summaryIcon('إجمالي الدائن')).toBe('bi-journal-arrow-down');
    expect(component.summaryIcon('متوازن')).toBe('bi-check2-circle');
  });

  it('splits meta lines into period chip and notes', () => {
    const raw = [
      'Period: 2026-01-01 → 2026-12-31',
      'Financial overview for the selected period.',
      'Accrual revenue counts only stay nights through today (earned revenue). Future nights in the selected period are excluded.',
    ];
    (component as unknown as { rawMetaLines: string[] }).rawMetaLines = raw;
    component.metaLines = [...raw];
    expect(component.metaPeriodRange).toEqual({ start: '2026-01-01', end: '2026-12-31' });
    expect(component.metaIntroLine).toContain('Financial overview');
    expect(component.metaDetailNotes.length).toBe(1);
  });
});
