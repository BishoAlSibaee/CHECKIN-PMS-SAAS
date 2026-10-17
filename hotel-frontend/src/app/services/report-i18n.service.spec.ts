import { TestBed } from '@angular/core/testing';
import { TranslateService } from '@ngx-translate/core';
import { ReportI18nService } from './report-i18n.service';

describe('ReportI18nService', () => {
  let service: ReportI18nService;

  const translations: Record<string, string> = {
    'REPORTS.META.RECON_ACCRUAL_RULE': 'الاستحقاق: حجوزات مؤكدة فقط (حالة 1).',
    'REPORTS.META.AR_AGING_BALANCE_FORMULA': 'الرصيد = إجمالي الحجز − (الدفعات − المرتجعات) حتى التاريخ المختار؛ الإجمالي شامل ضريبة {{rate}}%.',
    'REPORTS.META.AR_AGING_DAYS_OVERDUE_NOTE': 'أيام التأخير تُحسب من تاريخ المغادرة للإقامات المنتهية؛ الإقامات الجارية أو المستقبلية ضمن فئة 0–30 يوم.',
    'REPORTS.META.AR_AGING_MONTH_SCOPE': 'نطاق الشهر: إقامات تتداخل مع {{start}} → {{end}}.',
    'REPORTS.META.AR_AGING_BALANCE_AS_OF_NOTE': 'الرصيد يحسب من الدفعات والمرتجعات المسجّلة في أو قبل تاريخ «حتى».',
    'REPORTS.META.AR_AGING_SCOPE_NOTE': 'حجوزات مؤكدة أو بانتظار الدفع (غير ملغاة) برصيد مستحق أكبر من صفر.',
    'REPORTS.META.AS_OF': 'حتى تاريخ: {{date}}',
    'REPORTS.SUMMARY.TOTAL_AR': 'إجمالي الذمم',
    'REPORTS.SUMMARY.BUCKET_0_30': '0-30 يوم',
    'REPORTS.COLUMNS.days_overdue': 'أيام التأخير',
    'REPORTS.COLUMNS.bucket': 'الفئة',
    'REPORTS.COLUMNS.paid_net': 'المدفوع (صافي)',
    'REPORTS.COLUMNS.balance_due': 'الرصيد المستحق',
    'REPORTS.COLUMNS.guest': 'الضيف',
    'REPORTS.COLUMNS.classification_discount': 'خصم التصنيف',
    'REPORTS.COLUMNS.manual_discount': 'خصم يدوي',
    'REPORTS.COLUMNS.total_discount': 'إجمالي الخصم',
    'REPORTS.COLUMNS.net_adjustment': 'صافي التعديل',
    'REPORTS.SUMMARY.TOTAL_CLASSIFICATION_DISCOUNT': 'إجمالي خصم التصنيف',
    'REPORTS.SUMMARY.TOTAL_MANUAL_DISCOUNT': 'إجمالي الخصم اليدوي',
    'REPORTS.SUMMARY.TOTAL_EXTRAS': 'إجمالي الإضافات',
    'REPORTS.META.ADJUSTMENTS_PERIOD_NOTE': 'تعديلات الحجوزات التي لها رسوم يومية ضمن الفترة المحددة.',
    'REPORTS.META.BY_DIMENSION_NOTE': 'إيراد الاستحقاق مجمّع حسب مصدر الحجز وسبب الإقامة.',
    'REPORTS.COLUMNS.source_name': 'المصدر',
    'REPORTS.COLUMNS.stay_reason_name': 'سبب الإقامة',
    'REPORTS.COLUMNS.reservation_count': 'الحجوزات',
    'REPORTS.COLUMNS.room_nights': 'ليالي محجوزة (مؤكدة)',
    'REPORTS.COLUMNS.base_amount': 'السعر الأساسي',
    'REPORTS.COLUMNS.revenue': 'الإجمالي',
    'REPORTS.SUMMARY.TOTAL_REVENUE': 'إجمالي الإيراد',
    'REPORTS.SUMMARY.DIMENSIONS': 'مجموعات (مصدر + سبب)',
    'REPORTS.META.PEAK_CLASSIFICATION_NOTE': 'تصنيف الذروة مأخوذ من حقل is_peak_day في الرسوم اليومية (حسب إعدادات فترات الذروة).',
    'REPORTS.META.PEAK_ANALYSIS_PERIOD_NOTE': 'تصنيف أيام الذروة يعتمد على الإعدادات الحالية لكل تاريخ رسوم ضمن الفترة المختارة (يشمل كل الليالي المحجوزة وليس فقط الليالي المكتسبة حتى اليوم).',
    'REPORTS.PEAK_SEGMENTS.PEAK_DAYS': 'أيام الذروة',
    'REPORTS.PEAK_SEGMENTS.NON_PEAK_DAYS': 'أيام عادية',
    'REPORTS.SUMMARY.NON_PEAK_REVENUE': 'إيراد الأيام العادية',
    'REPORTS.SUMMARY.PEAK_REVENUE': 'إيراد الذروة',
    'REPORTS.COLUMNS.segment': 'الشريحة',
    'REPORTS.COLUMNS.share_pct': 'الحصة %',
    'REPORTS.META.CLOSING_PACKAGE_NOTE': 'باقة الإقفال تجمع الاستحقاق والصندوق والذمم والضريبة لدعم مراجعة إقفال الفترة.',
    'REPORTS.META.TAX_ALLOCATED_NOTE': 'توزّع الضريبة تناسبياً على الرسوم اليومية بنسبة {{rate}}%.',
    'REPORTS.COLUMNS.revenue_incl_tax': 'الإيراد (شامل الضريبة)',
    'REPORTS.RECON_STATUS.BALANCED': 'متوازن',
    'REPORTS.RECON_STATUS.ACCRUAL_EXCEEDS_CASH': 'الاستحقاق أعلى من الصندوق',
    'REPORTS.SUMMARY.STATUS': 'الحالة',
    'REPORTS.SUMMARY.ACCOUNT': 'الحساب',
    'REPORTS.SUMMARY.AR_OUTSTANDING': 'ذmم مستحقة',
    'REPORTS.SUMMARY.ACTIVE_ACCOUNTS': 'الحسابات الفعالة',
    'REPORTS.SUMMARY.TOTAL_BALANCE': 'إجمالي الأرصدة',
    'REPORTS.META.CHART_OF_ACCOUNTS_NOTE': 'دليل الحسابات مع أرصدة الحركة للفترة المحددة.',
    'REPORTS.ACCOUNT_TYPES.ASSET': 'أصل',
    'REPORTS.ACCOUNTS.CASH_BANK': 'النقدية / البنك',
    'REPORTS.ACCOUNTS.ACCOUNTS_RECEIVABLE': 'ذmم مدينة',
    'REPORTS.ACCOUNTS.ROOM_REVENUE': 'إيراد الغرف',
    'REPORTS.ACCOUNTS.VAT_PAYABLE': 'ضريبة مستحقة',
    'REPORTS.AUDIT_ACTIONS.ACCRUAL_POSTED': 'استحقاق ليلة',
    'REPORTS.AUDIT_DESC.ACCRUAL_RESERVATION': 'استحقاق إيراد ليلة للحجز رقم {{id}}',
    'REPORTS.AUDIT_PERFORMER.SYSTEM': 'النظام (تلقائي)',
    'REPORTS.COLUMNS.number': 'رقم الغرفة',
    'REPORTS.COLUMNS.occupancy_status': 'حالة الإشغال',
    'REPORTS.SUMMARY.SNAPSHOT_DATE': 'تاريخ اللقطة',
    'REPORTS.SUMMARY.OCCUPANCY_RATE': 'نسبة الإشغال',
    'REPORTS.META.ROOM_BOARD_SNAPSHOT': 'لقطة لوحة الغرف بتاريخ {{date}}.',
    'REPORTS.OCCUPANCY_STATUS.IN_HOUSE': 'مقيمة',
    'REPORTS.OPERATIONAL_STATUS.AVAILABLE': 'جاهزة',
    'REPORTS.FLOOR_LABEL': 'الطابق {{number}}',
    'REPORTS.SUMMARY.TRANSACTIONS': 'المعاملات',
    'REPORTS.SUMMARY.CASH_IN': 'داخل الصندوق',
    'REPORTS.SUMMARY.CASH_OUT_REFUNDS': 'خارج الصندوق (مرتجعات)',
    'REPORTS.SUMMARY.NET': 'الصافي',
    'REPORTS.META.CASH_BOX_INTRO': 'تقرير الصندوق: الدفعات والمرتجعات في الفترة المحددة.',
    'REPORTS.META.EARNED_CASH_NOTE': 'داخل/خارج الصندوق يشمل فقط الدفعات والمرتجعات المسجّلة حتى اليوم ضمن الفترة المحددة.',
    'REPORTS.META.PAYMENTS_REFUNDS_CASH_SCOPE_NOTE': 'التصفية حسب تاريخ التسجيل؛ تشمل كل الحركات النقدية الفعلية (الحجوزات المؤكدة والمعلّقة والملغاة) لتطابق دفتر الأستاذ.',
    'REPORTS.COLUMNS.date_time': 'التاريخ/الوقت',
    'REPORTS.COLUMNS.created_at': 'التاريخ/الوقت',
    'REPORTS.COLUMNS.type': 'النوع',
    'REPORTS.COLUMNS.amount': 'المبلغ',
    'REPORTS.COLUMNS.reservation_status': 'حالة الحجز',
    'REPORTS.CASH_TX_TYPES.PAYMENT': 'دفعة',
    'REPORTS.CASH_TX_TYPES.REFUND': 'مرتجع',
    'REPORTS.RESERVATION_STATUS.CONFIRMED': 'مؤكد',
    'REPORTS.RESERVATION_STATUS.PENDING_PAYMENT': 'بانتظار الدفع',
    'REPORTS.RESERVATION_STATUS.CANCELLED': 'ملغى',
    'REPORTS.SOURCE_TYPES.PAYMENT': 'دفعة / استرداد',
    'REPORTS.SOURCE_TYPES.ACCRUAL': 'استحقاق يومي',
    'REPORTS.SOURCE_TYPES.MANUAL': 'قيد يدوي',
    'REPORTS.JOURNAL_DESC.GUEST_PAYMENT': 'دفعة ضيف',
    'REPORTS.JOURNAL_DESC.GUEST_REFUND': 'استرداد ضيف',
    'REPORTS.JOURNAL_DESC.ACCRUAL_RESERVATION': 'استحقاق إيراد — الحجز رقم {{id}}',
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        ReportI18nService,
        {
          provide: TranslateService,
          useValue: {
            currentLang: 'ar',
            instant: (key: string, params?: Record<string, string>) => {
              if (key === 'REPORTS.META.TAX_ALLOCATED_NOTE' && params?.['rate']) {
                return `توزّع الضريبة تناسبياً على الرسوم اليومية بنسبة ${params['rate']}%.`;
              }
              let value = translations[key] ?? key;
              if (params) {
                Object.entries(params).forEach(([paramKey, paramValue]) => {
                  value = value.replace(`{{${paramKey}}}`, paramValue);
                });
              }
              return value;
            },
          },
        },
      ],
    });
    service = TestBed.inject(ReportI18nService);
  });

  it('translates accrual-cash reconciliation meta lines', () => {
    const line = 'Accrual: reservation_status = 1, revenue by charge_date (stay night).';
    expect(service.translateMetaLine(line)).toBe('الاستحقاق: حجوزات مؤكدة فقط (حالة 1).');
  });

  it('translates reconciliation status in rows', () => {
    const columns = [{ key: 'status', label: 'Status' }];
    const rows = [{ status: 'Balanced' }];
    const localized = service.localizeRows(columns, rows);
    expect(localized[0]['status']).toBe('متوازن');
  });

  it('translates reconciliation status in summary cards', () => {
    const summary = [{ label: 'Status', value: 'Accrual exceeds cash' }];
    const localized = service.localizeSummary(summary);
    expect(localized[0].label).toBe('الحالة');
    expect(localized[0].value).toBe('الاستحقاق أعلى من الصندوق');
  });

  it('translates general ledger account summary value', () => {
    const summary = [{ label: 'Account', value: '1010 — Cash / Bank' }];
    const localized = service.localizeSummary(summary);
    expect(localized[0].label).toBe('الحساب');
    expect(localized[0].value).toBe('1010 — النقدية / البنك');
    expect(service.formatAccountFilterLabel('1010', 'Cash / Bank')).toBe('1010 — النقدية / البنك');
  });

  it('localizes room board report columns, summary, meta, and row statuses', () => {
    expect(service.translateColumn('number', 'Room #')).toBe('رقم الغرفة');
    expect(service.translateSummary('Snapshot date')).toBe('تاريخ اللقطة');
    expect(service.translateSummary('Occupancy rate')).toBe('نسبة الإشغال');
    expect(service.translateMetaLine('Room board snapshot for 2026-07-05 (uses end date as snapshot date).'))
      .toBe('لقطة لوحة الغرف بتاريخ 2026-07-05.');

    const columns = [
      { key: 'number', label: 'Room #' },
      { key: 'floor', label: 'Floor' },
      { key: 'occupancy_status', label: 'Occupancy' },
      { key: 'operational_status', label: 'Operational status' },
    ];
    const rows = [{
      number: '103',
      floor: 'Floor 1',
      occupancy_status: 'in_house',
      operational_status: 'available',
    }];
    const localized = service.localizeRows(columns, rows);

    expect(localized[0]['occupancy_status']).toBe('مقيمة');
    expect(localized[0]['operational_status']).toBe('جاهزة');
    expect(localized[0]['floor']).toBe('الطابق 1');
  });

  it('translates tax report meta line with dynamic rate', () => {
    const line = 'Tax allocated proportionally across daily charges at 15%.';
    expect(service.translateMetaLine(line)).toBe('توزّع الضريبة تناسبياً على الرسوم اليومية بنسبة 15%.');
  });

  it('translates revenue incl tax column label', () => {
    expect(service.translateColumn('revenue', 'Revenue incl. tax')).toBe('الإيراد (شامل الضريبة)');
  });

  it('translates closing package labels and note', () => {
    expect(service.translateSummary('AR outstanding')).toBe('ذmم مستحقة');
    expect(service.translateMetaLine('Closing package combines accrual, cash, AR, and tax for period-end review.'))
      .toBe(translations['REPORTS.META.CLOSING_PACKAGE_NOTE']);
  });

  it('translates chart of accounts labels, account names, and account types', () => {
    expect(service.translateSummary('Active accounts')).toBe('الحسابات الفعالة');
    expect(service.translateSummary('Total balance')).toBe('إجمالي الأرصدة');
    expect(service.translateMetaLine('Chart of accounts with balances for the selected period.'))
      .toBe('دليل الحسابات مع أرصدة الحركة للفترة المحددة.');

    const columns = [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Account name' },
      { key: 'type', label: 'Type' },
    ];
    const rows = [{ code: '1010', name: 'Cash / Bank', type: 'asset' }];
    const localized = service.localizeRows(columns, rows);

    expect(localized[0]['code']).toBe('1010');
    expect(localized[0]['name']).toBe('النقدية / البنك');
    expect(localized[0]['type']).toBe('أصل');
  });

  it('localizes journal entry account_code and account_name with readable labels', () => {
    const columns = [
      { key: 'entry_date', label: 'Date' },
      { key: 'account_code', label: 'Account code' },
      { key: 'account_name', label: 'Account name' },
      { key: 'debit', label: 'Debit' },
    ];
    const rows = [
      {
        entry_date: '2026-07-13',
        account_code: '1100',
        account_name: 'Accounts Receivable',
        debit: 368,
      },
      {
        entry_date: '2026-07-13',
        account_code: '4010',
        account_name: 'Room Revenue',
        debit: 0,
      },
    ];

    const localized = service.localizeRows(columns, rows);

    expect(localized[0]['account_code']).toBe('ذmم مدينة (1100)');
    expect(localized[0]['account_name']).toBe('ذmم مدينة');
    expect(localized[1]['account_code']).toBe('إيراد الغرف (4010)');
    expect(localized[1]['account_name']).toBe('إيراد الغرف');
  });

  it('localizes journal entry source_type and description', () => {
    const columns = [
      { key: 'description', label: 'Description' },
      { key: 'source_type', label: 'Source' },
    ];
    const rows = [
      { description: 'Guest payment', source_type: 'reservation_pay' },
      { description: 'Guest refund', source_type: 'reservation_pay' },
      { description: 'Accrual revenue — reservation #42', source_type: 'daily_charge' },
      { description: 'Opening balance', source_type: 'manual' },
    ];

    const localized = service.localizeRows(columns, rows);

    expect(localized[0]['description']).toBe('دفعة ضيف');
    expect(localized[0]['source_type']).toBe('دفعة / استرداد');
    expect(localized[1]['description']).toBe('استرداد ضيف');
    expect(localized[2]['description']).toBe('استحقاق إيراد — الحجز رقم 42');
    expect(localized[2]['source_type']).toBe('استحقاق يومي');
    expect(localized[3]['source_type']).toBe('قيد يدوي');
    expect(localized[3]['description']).toBe('Opening balance');
  });

  it('localizes financial audit log rows', () => {
    const columns = [
      { key: 'event_date', label: 'Date' },
      { key: 'operation', label: 'Operation' },
      { key: 'description', label: 'Description' },
      { key: 'reference', label: 'Reference' },
      { key: 'performed_by', label: 'By' },
    ];
    const rows = [{
      event_date: '2026-07-13',
      operation: 'accrual_posted',
      description: 'Room night revenue accrued for reservation #17652',
      reference: 'ACCRUAL-103-2026-07-13',
      performed_by: 'System',
    }];

    const localized = service.localizeRows(columns, rows);

    expect(localized[0]['operation']).toBe('استحقاق ليلة');
    expect(localized[0]['description']).toBe('استحقاق إيراد ليلة للحجز رقم 17652');
    expect(localized[0]['performed_by']).toBe('النظام (تلقائي)');
  });

  it('resolves English account type slugs for API calls', () => {
    expect(service.resolveAccountTypeApiValue('asset')).toBe('asset');
    expect(service.resolveAccountTypeApiValue('EXPENSE')).toBe('expense');
  });

  it('resolves localized account type labels back to API slugs', () => {
    expect(service.resolveAccountTypeApiValue('أصل')).toBe('asset');
  });

  it('localizes ar-aging columns, summary, meta lines, and bucket values', () => {
    expect(service.translateColumn('guest', 'Guest')).toBe('الضيف');
    expect(service.translateColumn('days_overdue', 'Days overdue')).toBe('أيام التأخير');
    expect(service.translateColumn('bucket', 'Bucket')).toBe('الفئة');
    expect(service.translateColumn('paid_net', 'Paid (net)')).toBe('المدفوع (صافي)');
    expect(service.translateColumn('balance_due', 'Balance due')).toBe('الرصيد المستحق');
    expect(service.translateSummary('Total AR')).toBe('إجمالي الذمم');
    expect(service.translateSummary('0-30 days')).toBe('0-30 يوم');
    expect(service.translateMetaLine('As of: 2026-07-09')).toBe('حتى تاريخ: 2026-07-09');
    expect(service.translateMetaLine('Month scope: stays overlapping 2026-07-01 → 2026-07-31.'))
      .toBe('نطاق الشهر: إقامات تتداخل مع 2026-07-01 → 2026-07-31.');
    expect(service.translateMetaLine(
      'Balance = reservation.total − (payments − refunds) as of the selected date; total includes tax at 15%.'
    )).toBe('الرصيد = إجمالي الحجز − (الدفعات − المرتجعات) حتى التاريخ المختار؛ الإجمالي شامل ضريبة 15%.');
    expect(service.translateMetaLine(
      'Days overdue measured from check-out date for past stays; current/future stays in 0-30 bucket.'
    )).toBe('أيام التأخير تُحسب من تاريخ المغادرة للإقامات المنتهية؛ الإقامات الجارية أو المستقبلية ضمن فئة 0–30 يوم.');

    const columns = [
      { key: 'guest', label: 'Guest' },
      { key: 'bucket', label: 'Bucket' },
      { key: 'balance_due', label: 'Balance due' },
    ];
    const rows = [{ guest: 'Ahmed', bucket: '0-30', balance_due: 500 }];
    const localized = service.localizeRows(columns, rows);
    expect(localized[0]['bucket']).toBe('0-30 يوم');
  });

  it('localizes adjustments report columns, summary, and meta line', () => {
    expect(service.translateColumn('classification_discount', 'Classification discount')).toBe('خصم التصنيف');
    expect(service.translateColumn('manual_discount', 'Manual discount')).toBe('خصم يدوي');
    expect(service.translateColumn('total_discount', 'Total discount')).toBe('إجمالي الخصم');
    expect(service.translateColumn('net_adjustment', 'Net adjustment')).toBe('صافي التعديل');
    expect(service.translateSummary('Total classification discount')).toBe('إجمالي خصم التصنيف');
    expect(service.translateSummary('Total manual discount')).toBe('إجمالي الخصم اليدوي');
    expect(service.translateSummary('Total extras')).toBe('إجمالي الإضافات');
    expect(service.translateMetaLine('Adjustments for reservations with daily charges in the selected period.'))
      .toBe('تعديلات الحجوزات التي لها رسوم يومية ضمن الفترة المحددة.');
  });

  it('localizes by-dimension report columns, summary, meta, and bilingual row names', () => {
    expect(service.translateColumn('source_name', 'Source')).toBe('المصدر');
    expect(service.translateColumn('stay_reason_name', 'Stay reason')).toBe('سبب الإقامة');
    expect(service.translateColumn('reservation_count', 'Reservations')).toBe('الحجوزات');
    expect(service.translateColumn('room_nights', 'Room nights')).toBe('ليالي محجوزة (مؤكدة)');
    expect(service.translateColumn('base_amount', 'Base')).toBe('السعر الأساسي');
    expect(service.translateColumn('revenue', 'Revenue')).toBe('الإجمالي');
    expect(service.translateSummary('Total revenue')).toBe('إجمالي الإيراد');
    expect(service.translateSummary('Dimensions')).toBe('مجموعات (مصدر + سبب)');
    expect(service.translateMetaLine('Revenue grouped by reservation source and stay reason (accrual).'))
      .toBe('إيراد الاستحقاق مجمّع حسب مصدر الحجز وسبب الإقامة.');

    const columns = [
      { key: 'source_name', label: 'Source' },
      { key: 'stay_reason_name', label: 'Stay reason' },
    ];
    const rows = [{
      source_name: 'Walk-in',
      source_name_ar: 'مباشر',
      source_name_en: 'Walk-in',
      stay_reason_name: 'Tourism',
      stay_reason_name_ar: 'سياحة',
      stay_reason_name_en: 'Tourism',
    }];
    const localized = service.localizeRows(columns, rows);
    expect(localized[0]['source_name']).toBe('مباشر');
    expect(localized[0]['stay_reason_name']).toBe('سياحة');
  });

  it('localizes peak-analysis report columns, summary, meta, and segment rows', () => {
    expect(service.translateColumn('segment', 'Segment')).toBe('الشريحة');
    expect(service.translateColumn('share_pct', 'Share %')).toBe('الحصة %');
    expect(service.translateSummary('Peak revenue')).toBe('إيراد الذروة');
    expect(service.translateSummary('Non-peak revenue')).toBe('إيراد الأيام العادية');
    expect(service.translateMetaLine('Peak day classification uses current peak-day settings applied to each charge date in the selected period (includes all booked nights in the range, not only nights through today).'))
      .toBe('تصنيف أيام الذروة يعتمد على الإعدادات الحالية لكل تاريخ رسوم ضمن الفترة المختارة (يشمل كل الليالي المحجوزة وليس فقط الليالي المكتسبة حتى اليوم).');

    const columns = [{ key: 'segment', label: 'Segment' }];
    const rows = [{ segment: 'Peak days' }, { segment: 'Non-peak days' }];
    const localized = service.localizeRows(columns, rows);
    expect(localized[0]['segment']).toBe('أيام الذروة');
    expect(localized[1]['segment']).toBe('أيام عادية');
  });

  it('localizes cash-box report summary, meta, columns, and row values', () => {
    expect(service.translateSummary('Transactions')).toBe('المعاملات');
    expect(service.translateSummary('Cash in')).toBe('داخل الصندوق');
    expect(service.translateSummary('Cash out (refunds)')).toBe('خارج الصندوق (مرتجعات)');
    expect(service.translateSummary('Net')).toBe('الصافي');
    expect(service.translateMetaLine('Cash box report: payments and refunds in the selected period.'))
      .toBe('تقرير الصندوق: الدفعات والمرتجعات في الفترة المحددة.');
    expect(service.translateMetaLine('Cash in/out includes only payments and refunds recorded through today within the selected period.'))
      .toBe('داخل/خارج الصندوق يشمل فقط الدفعات والمرتجعات المسجّلة حتى اليوم ضمن الفترة المحددة.');
    expect(service.translateColumn('created_at', 'Date/time')).toBe('التاريخ/الوقت');
    expect(service.translateColumn('type', 'Type')).toBe('النوع');
    expect(service.translateColumn('reservation_status', 'Reservation status')).toBe('حالة الحجز');

    const columns = [
      { key: 'created_at', label: 'Date/time' },
      { key: 'type', label: 'Type' },
      { key: 'reservation_status', label: 'Reservation status' },
    ];
    const rows = [{
      type: 'Payment',
      reservation_status: 'Confirmed',
    }, {
      type: 'Refund',
      reservation_status: 'Pending payment',
    }];
    const localized = service.localizeRows(columns, rows);
    expect(localized[0]['type']).toBe('دفعة');
    expect(localized[0]['reservation_status']).toBe('مؤكد');
    expect(localized[1]['type']).toBe('مرتجع');
    expect(localized[1]['reservation_status']).toBe('بانتظار الدفع');
  });
});
