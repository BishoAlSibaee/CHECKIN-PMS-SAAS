import { Injectable } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';

@Injectable({ providedIn: 'root' })
export class ReportI18nService {
  private readonly summaryKeyByLabel: Record<string, string> = {
    'Arrivals': 'ARRIVALS',
    'Departures': 'DEPARTURES',
    'Stayovers': 'STAYOVERS',
    'In-house': 'IN_HOUSE',
    'Total movements': 'TOTAL_MOVEMENTS',
    'Reservations': 'RESERVATIONS',
    'Confirmed': 'CONFIRMED',
    'Total value': 'TOTAL_VALUE',
    'Available rooms': 'AVAILABLE_ROOMS',
    'Total rooms': 'TOTAL_ROOMS',
    'Days in period': 'DAYS_IN_PERIOD',
    'Days with room nights': 'DAYS_WITH_ROOM_NIGHTS',
    'Days shown': 'DAYS_SHOWN',
    'Total room nights sold': 'TOTAL_ROOM_NIGHTS',
    'Average occupancy rate': 'AVG_OCCUPANCY',
    'Compare room nights': 'COMPARE_ROOM_NIGHTS',
    'Compare occupancy rate': 'COMPARE_OCCUPANCY',
    'Total base': 'TOTAL_BASE',
    'Total tax': 'TOTAL_TAX',
    'Total revenue': 'TOTAL_REVENUE',
    'Room nights': 'ROOM_NIGHTS',
    'Room nights (booked)': 'ROOM_NIGHTS_BOOKED',
    'Room nights (earned)': 'ROOM_NIGHTS_EARNED',
    'Active bookings (total)': 'ACTIVE_BOOKINGS_TOTAL',
    'Compare revenue': 'COMPARE_REVENUE',
    'Accrual revenue': 'ACCRUAL_REVENUE',
    'Cash net': 'CASH_NET',
    'Difference': 'DIFFERENCE',
    'Status': 'STATUS',
    'Compare accrual': 'COMPARE_ACCRUAL',
    'Compare cash net': 'COMPARE_CASH_NET',
    'Total AR': 'TOTAL_AR',
    '0-30 days': 'BUCKET_0_30',
    '31-60 days': 'BUCKET_31_60',
    '61-90 days': 'BUCKET_61_90',
    '90+ days': 'BUCKET_90_PLUS',
    'Total discount': 'TOTAL_DISCOUNT',
    'Total classification discount': 'TOTAL_CLASSIFICATION_DISCOUNT',
    'Total manual discount': 'TOTAL_MANUAL_DISCOUNT',
    'Total extras': 'TOTAL_EXTRAS',
    'Total penalties': 'TOTAL_PENALTIES',
    'Total subtotal': 'TOTAL_SUBTOTAL',
    'Tax rate': 'TAX_RATE',
    'Compare tax': 'COMPARE_TAX',
    'Revenue (incl. tax)': 'REVENUE_INCL_TAX',
    'ADR (subtotal / room nights)': 'ADR',
    'RevPAR (revenue / available room nights)': 'REVPAR',
    'Compare RevPAR': 'COMPARE_REVPAR',
    'Dimensions': 'DIMENSIONS',
    'Peak revenue': 'PEAK_REVENUE',
    'Non-peak revenue': 'NON_PEAK_REVENUE',
    'Standard revenue': 'STANDARD_REVENUE',
    'Peak share': 'PEAK_SHARE',
    'Total inflow': 'TOTAL_INFLOW',
    'Total outflow': 'TOTAL_OUTFLOW',
    'Net change': 'NET_CHANGE',
    'Net cash': 'NET_CASH',
    'Total events': 'TOTAL_EVENTS',
    'Total debit': 'TOTAL_DEBIT',
    'Total credit': 'TOTAL_CREDIT',
    'Net income': 'NET_INCOME',
    'Total assets': 'TOTAL_ASSETS',
    'Total liabilities': 'TOTAL_LIABILITIES',
    'Total equity': 'TOTAL_EQUITY',
    'A/R balance': 'AR_BALANCE',
    'AR outstanding': 'AR_OUTSTANDING',
    'Tax': 'TAX',
    'Accrual − cash': 'ACCRUAL_MINUS_CASH',
    'Compare net cash': 'COMPARE_NET_CASH',
    'Operating net': 'OPERATING_NET',
    'Active accounts': 'ACTIVE_ACCOUNTS',
    'Total balance': 'TOTAL_BALANCE',
    'Account type': 'ACCOUNT_TYPE',
    'Account': 'ACCOUNT',
    'Search': 'SEARCH',
    'Hide zero': 'HIDE_ZERO',
    'Min amount': 'MIN_AMOUNT',
    'Max amount': 'MAX_AMOUNT',
    'Source': 'SOURCE',
    'Operation': 'OPERATION',
    'Reservation #': 'RESERVATION_ID',
    'Payments': 'AUDIT_PAYMENTS',
    'Refunds': 'AUDIT_REFUNDS',
    'Accruals': 'AUDIT_ACCRUALS',
    'Manual journals': 'AUDIT_MANUAL_JOURNALS',
    'Period closes': 'AUDIT_PERIOD_CLOSES',
    'Snapshot date': 'SNAPSHOT_DATE',
    'Occupancy rate': 'OCCUPANCY_RATE',
    'Transactions': 'TRANSACTIONS',
    'Cash in': 'CASH_IN',
    'Cash out (refunds)': 'CASH_OUT_REFUNDS',
    'Net': 'NET',
  };

  private readonly metricKeyByLabel: Record<string, string> = {
    'Accrual revenue (incl. tax)': 'ACCRUAL_INCL_TAX',
    'Accrual subtotal (pre-tax)': 'ACCRUAL_SUBTOTAL_PRETAX',
    'Tax (accrual)': 'TAX_ACCRUAL',
    'Room nights (accrual)': 'ROOM_NIGHTS_ACCRUAL',
    'Cash in': 'CASH_IN',
    'Cash out (refunds)': 'CASH_OUT_REFUNDS',
    'Net cash': 'NET_CASH',
    'Cash net': 'NET_CASH',
    'Accrual vs cash difference': 'ACCRUAL_VS_CASH_DIFF',
    'Accrual − cash net': 'ACCRUAL_MINUS_CASH_NET',
    'Accounts receivable (as of end)': 'AR_AS_OF_END',
    'Accounts receivable (open balances)': 'AR_OPEN_BALANCES',
  };

  private readonly metaKeyByExactLine: Record<string, string> = {
    'Financial overview for the selected period.': 'OVERVIEW_INTRO',
    'Accrual revenue counts only stay nights through today (earned revenue). Future nights in the selected period are excluded.': 'EARNED_ACCRUAL_NOTE',
    'Only confirmed reservations (status 1) are included. Compare line revenue (incl. tax) to the reservation total only after checkout and when the report period covers the full stay.': 'ACCRUAL_RECONCILIATION_NOTE',
    'Detailed accrual revenue from reservation_daily_charges.': 'ACCRUAL_DETAIL_INTRO',
    'One row per calendar day in the period. Guest lists confirmed stays with earned revenue that night.': 'ACCRUAL_DAILY_GUEST_NOTE',
    'One row per calendar day in the period. Guest lists every non-cancelled stay overlapping that day.': 'ACCRUAL_DAILY_GUEST_OVERLAP_NOTE',
    'Accrual revenue counts only confirmed (or fully paid) stays through today (earned revenue). Future nights in the selected period are excluded.': 'ACCRUAL_EARNED_CONFIRMED_NOTE',
    'Only confirmed reservations (status 1), or pending stays paid in full, contribute to revenue columns.': 'ACCRUAL_REVENUE_CONTRIB_NOTE',
    'Pending partial-payment stays appear in active bookings and guest names but not in revenue until confirmed or fully paid.': 'ACCRUAL_PENDING_PARTIAL_NOTE',
    'Filter active: days with zero room nights are hidden.': 'ACCRUAL_HIDE_EMPTY_ACTIVE',
    'Filter active: days with no active bookings and no room nights are hidden.': 'ACCRUAL_HIDE_EMPTY_ACTIVE_V2',
    'No reservations in the database. Run: php artisan db:seed --class=ReservationTestDataSeeder': 'NO_RESERVATIONS_SEED',
    'Cash in/out includes only payments and refunds recorded through today within the selected period.': 'EARNED_CASH_NOTE',
    'Cash box report: payments and refunds in the selected period.': 'CASH_BOX_INTRO',
    'Filtered by reservation_pay.created_at; includes all real cash movements (confirmed, pending, and cancelled reservations) to match the ledger.': 'PAYMENTS_REFUNDS_CASH_SCOPE_NOTE',
    'Date range: one row per arrival, departure, or in-house stay within the period (same reservations as the bookings list).': 'ARRIVALS_RANGE_NOTE',
    'One row per calendar day in the period. Active bookings matches the reservations list when filtering by that day. Room nights and revenue are from confirmed reservations only (status 1). In house shows occupied rooms on that day (any status).': 'REVENUE_SUMMARY_DAILY_NOTE',
    'Active bookings: count of non-cancelled reservations overlapping that day (same rule as the reservations list/calendar when filtering by date).': 'REVENUE_SUMMARY_ACTIVE_NOTE',
    'Room nights (booked): confirmed stays (status 1) with a nightly charge on that date — includes future dates in the selected period.': 'REVENUE_SUMMARY_BOOKED_NOTE',
    'Room nights (earned) and revenue: only nights through today (accrual). Future nights in an active stay show booked nights but revenue 0 until the night occurs.': 'REVENUE_SUMMARY_EARNED_NOTE',
    'Accrual: reservation_status = 1, revenue by charge_date (stay night).': 'RECON_ACCRUAL_RULE',
    'Cash: reservation_status in (1, 2), payments/refunds by reservation_pay.created_at.': 'RECON_CASH_RULE',
    'Difference is expected when stay dates differ from payment dates (prepay, deposits, refunds).': 'RECON_DIFFERENCE_EXPECTED',
    'Pending reservations (status 2) affect cash but not accrual until confirmed.': 'RECON_PENDING_NOTE',
    'Available rooms = total sellable rooms minus booked room nights for that date. Occupancy % uses the same booked nights.': 'OCCUPANCY_AVAILABLE_NOTE',
    'Closing package combines accrual, cash, AR, and tax for period-end review.': 'CLOSING_PACKAGE_NOTE',
    'Chart of accounts with balances for the selected period.': 'CHART_OF_ACCOUNTS_NOTE',
    'Cash flow from journal entries on cash/bank asset accounts.': 'CASH_FLOW_JOURNAL_NOTE',
    'Financial audit trail includes manual audit events and posted journal activity (payments, accruals, manual entries) for the selected period.': 'FINANCIAL_AUDIT_LOG_NOTE',
    'Each row is one accounting event: guest payment, room-night accrual, manual journal, or period close.': 'FINANCIAL_AUDIT_LOG_ROW_NOTE',
    'Balance uses payments/refunds recorded on or before the as-of date.': 'AR_AGING_BALANCE_AS_OF_NOTE',
    'Confirmed and pending-payment reservations (not cancelled) with balance_due > 0.': 'AR_AGING_SCOPE_NOTE',
    'Days overdue measured from check-out date for past stays; current/future stays in 0-30 bucket.': 'AR_AGING_DAYS_OVERDUE_NOTE',
    'Adjustments for reservations with daily charges in the selected period.': 'ADJUSTMENTS_PERIOD_NOTE',
    'Revenue grouped by reservation source and stay reason (accrual).': 'BY_DIMENSION_NOTE',
    'Reservation count includes only stays with earned room-night charges through today within the selected period (not every booking overlapping the period).': 'BY_DIMENSION_RESERVATION_SCOPE_NOTE',
    'Peak classification from reservation_daily_charges.is_peak_day.': 'PEAK_CLASSIFICATION_NOTE',
    'Peak day classification uses current peak-day settings applied to each charge date in the selected period (includes all booked nights in the range, not only nights through today).': 'PEAK_ANALYSIS_PERIOD_NOTE',
  };

  private readonly occupancyStatusKeyByValue: Record<string, string> = {
    vacant: 'VACANT',
    in_house: 'IN_HOUSE',
    reserved: 'RESERVED',
    check_in_today: 'CHECK_IN_TODAY',
    check_out_today: 'CHECK_OUT_TODAY',
    pending: 'PENDING',
    preparation: 'PREPARATION',
    out_of_service: 'OUT_OF_SERVICE',
  };

  private readonly operationalStatusKeyByValue: Record<string, string> = {
    available: 'AVAILABLE',
    occupied: 'OCCUPIED',
    preparation: 'PREPARATION',
    out_of_service: 'OUT_OF_SERVICE',
    unknown: 'UNKNOWN',
  };

  private readonly reservationStatusKeyByValue: Record<string, string> = {
    Confirmed: 'CONFIRMED',
    'Pending payment': 'PENDING_PAYMENT',
    Cancelled: 'CANCELLED',
  };

  private readonly auditOperationKeyByValue: Record<string, string> = {
    payment_posted: 'PAYMENT_POSTED',
    refund_posted: 'REFUND_POSTED',
    accrual_posted: 'ACCRUAL_POSTED',
    journal_entry_created: 'JOURNAL_ENTRY_CREATED',
    period_closed: 'PERIOD_CLOSED',
    journal_posted: 'JOURNAL_POSTED',
  };

  private readonly cashTransactionTypeKeyByValue: Record<string, string> = {
    Payment: 'PAYMENT',
    Refund: 'REFUND',
  };

  private readonly columnLabelByApiLabel: Record<string, string> = {
    'Date/time': 'date_time',
    'Reservation status': 'reservation_status',
    'Revenue incl. tax': 'revenue_incl_tax',
    'Room #': 'number',
    'Building': 'building',
    'Floor': 'floor',
    'Room type': 'room_type',
    'Occupancy': 'occupancy_status',
    'Operational status': 'operational_status',
    'Check-in': 'check_in',
    'Check-out': 'check_out',
    'Reservation #': 'reservation_id',
    'Guest': 'guest',
    'Room': 'room',
    'Days overdue': 'days_overdue',
    'Bucket': 'bucket',
    'Paid (net)': 'paid_net',
    'Balance due': 'balance_due',
    'Total': 'total',
    'Classification discount': 'classification_discount',
    'Manual discount': 'manual_discount',
    'Total discount': 'total_discount',
    'Extras': 'extras',
    'Penalties': 'penalties',
    'Net adjustment': 'net_adjustment',
    'Source': 'source_name',
    'Stay reason': 'stay_reason_name',
    'Reservations': 'reservation_count',
    'Room nights': 'room_nights',
    'Room nights sold': 'room_nights',
    'Base': 'base_amount',
    'Revenue': 'revenue',
    'Segment': 'segment',
    'Share %': 'share_pct',
    'Cash account': 'cash_account',
    'Inflow (debit)': 'inflow_debit',
    'Outflow (credit)': 'outflow_credit',
    'Net change': 'net_change',
  };

  private readonly arBucketKeyByValue: Record<string, string> = {
    '0-30': 'BUCKET_0_30',
    '31-60': 'BUCKET_31_60',
    '61-90': 'BUCKET_61_90',
    '90+': 'BUCKET_90_PLUS',
  };

  private readonly peakSegmentKeyByValue: Record<string, string> = {
    'Peak days': 'PEAK_DAYS',
    'Non-peak days': 'NON_PEAK_DAYS',
  };

  private readonly reconStatusKeyByValue: Record<string, string> = {
    'Balanced': 'BALANCED',
    'Accrual exceeds cash': 'ACCRUAL_EXCEEDS_CASH',
    'Cash exceeds accrual': 'CASH_EXCEEDS_ACCRUAL',
  };

  private readonly movementTypeKeyByLabel: Record<string, string> = {
    'Arrival': 'ARRIVAL',
    'Departure': 'DEPARTURE',
    'Stayover': 'STAYOVER',
    'In-house': 'IN_HOUSE',
    'Arrival & Departure': 'ARRIVAL_AND_DEPARTURE',
  };

  private readonly accountTypeKeyByValue: Record<string, string> = {
    asset: 'ASSET',
    liability: 'LIABILITY',
    equity: 'EQUITY',
    revenue: 'REVENUE',
    expense: 'EXPENSE',
    Asset: 'ASSET',
    Liability: 'LIABILITY',
    Equity: 'EQUITY',
    Revenue: 'REVENUE',
    Expense: 'EXPENSE',
  };

  private readonly accountNameKeyByCode: Record<string, string> = {
    '1010': 'CASH_BANK',
    '1100': 'ACCOUNTS_RECEIVABLE',
    '1200': 'PREPAID_EXPENSES',
    '2100': 'UNEARNED_REVENUE',
    '2150': 'VAT_PAYABLE',
    '3000': 'RETAINED_EARNINGS',
    '4010': 'ROOM_REVENUE',
    '4020': 'EXTRAS_REVENUE',
    '5010': 'DISCOUNTS',
    '5020': 'REFUNDS',
  };

  constructor(private translate: TranslateService) {}

  translateColumn(key: string, fallback: string): string {
    const labelSlug = this.columnLabelByApiLabel[fallback];
    if (labelSlug) {
      const labelPath = `REPORTS.COLUMNS.${labelSlug}`;
      const byLabel = this.translate.instant(labelPath);
      if (byLabel !== labelPath) {
        return byLabel;
      }
    }

    const path = `REPORTS.COLUMNS.${key}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : fallback;
  }

  translateSummary(label: string): string {
    const slug = this.summaryKeyByLabel[label];
    if (!slug) {
      return label;
    }
    const path = `REPORTS.SUMMARY.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : label;
  }

  translateMetric(label: string): string {
    const slug = this.metricKeyByLabel[label];
    if (!slug) {
      return label;
    }
    const path = `REPORTS.METRICS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : label;
  }

  translateMetaLine(line: string): string {
    const exactKey = this.metaKeyByExactLine[line];
    if (exactKey) {
      const path = `REPORTS.META.${exactKey}`;
      const translated = this.translate.instant(path);
      if (translated !== path) {
        return translated;
      }
    }

    const periodMatch = line.match(/^Period: (\d{4}-\d{2}-\d{2}) → (\d{4}-\d{2}-\d{2})$/);
    if (periodMatch) {
      return this.translate.instant('REPORTS.META.PERIOD', {
        start: periodMatch[1],
        end: periodMatch[2],
      });
    }

    const compareMatch = line.match(/^Compare: (\d{4}-\d{2}-\d{2}) → (\d{4}-\d{2}-\d{2})$/);
    if (compareMatch) {
      return this.translate.instant('REPORTS.META.COMPARE', {
        start: compareMatch[1],
        end: compareMatch[2],
      });
    }

    const asOfMatch = line.match(/^As of: (\d{4}-\d{2}-\d{2})$/);
    if (asOfMatch) {
      return this.translate.instant('REPORTS.META.AS_OF', { date: asOfMatch[1] });
    }

    const monthScopeMatch = line.match(/^Month scope: stays overlapping (\d{4}-\d{2}-\d{2}) → (\d{4}-\d{2}-\d{2})\.$/);
    if (monthScopeMatch) {
      return this.translate.instant('REPORTS.META.AR_AGING_MONTH_SCOPE', {
        start: monthScopeMatch[1],
        end: monthScopeMatch[2],
      });
    }

    const balanceFormulaMatch = line.match(
      /^Balance = reservation\.total − \(payments − refunds\) as of the selected date; total includes tax at (\d+(?:\.\d+)?)%\.$/
    );
    if (balanceFormulaMatch) {
      return this.translate.instant('REPORTS.META.AR_AGING_BALANCE_FORMULA', {
        rate: balanceFormulaMatch[1],
      });
    }

    const spanMatch = line.match(/^Reservation stays in database: (\d{4}-\d{2}-\d{2}) → (\d{4}-\d{2}-\d{2})\./);
    if (spanMatch) {
      return this.translate.instant('REPORTS.META.RESERVATION_DATE_SPAN', {
        start: spanMatch[1],
        end: spanMatch[2],
      });
    }

    const taxAllocMatch = line.match(/^Tax allocated proportionally across daily charges at (\d+(?:\.\d+)?)%\.$/);
    if (taxAllocMatch) {
      return this.translate.instant('REPORTS.META.TAX_ALLOCATED_NOTE', {
        rate: taxAllocMatch[1],
      });
    }

    const roomBoardMatch = line.match(/^Room board snapshot for (\d{4}-\d{2}-\d{2}) \(uses end date as snapshot date\)\.$/);
    if (roomBoardMatch) {
      return this.translate.instant('REPORTS.META.ROOM_BOARD_SNAPSHOT', {
        date: roomBoardMatch[1],
      });
    }

    return line;
  }

  localizeRows(
    columns: { key: string; label?: string }[],
    rows: Record<string, unknown>[]
  ): Record<string, unknown>[] {
    const metricColumn = columns.some(c => c.key === 'metric');
    const movementColumn = columns.some(c => c.key === 'movement_type');
    const statusColumn = columns.some(c => c.key === 'status');
    const reservationStatusColumn = columns.some(c => c.key === 'reservation_status');
    const chartAccountColumns = columns.some(c => c.key === 'code') &&
      (columns.some(c => c.key === 'name') || columns.some(c => c.key === 'name_en') || columns.some(c => c.key === 'name_ar'));
    const transactionTypeColumn = columns.some(c => c.key === 'type') && !chartAccountColumns;
    const accountTypeColumn = columns.some(c => c.key === 'type') && chartAccountColumns;
    const operationColumn = columns.some(c => c.key === 'operation');
    const sourceTypeColumn = columns.some(c => c.key === 'source_type');
    const descriptionColumn = columns.some(c => c.key === 'description');
    const occupancyStatusColumn = columns.some(c => c.key === 'occupancy_status');
    const operationalStatusColumn = columns.some(c => c.key === 'operational_status');
    const bucketColumn = columns.some(c => c.key === 'bucket');
    const sourceNameColumn = columns.some(c => c.key === 'source_name');
    const stayReasonColumn = columns.some(c => c.key === 'stay_reason_name');
    const segmentColumn = columns.some(c => c.key === 'segment');
    const floorColumn = columns.some(c => c.key === 'floor');

    return rows.map(row => {
      let next = row;

      if (metricColumn && typeof row['metric'] === 'string') {
        next = {
          ...next,
          metric: this.translateMetric(row['metric'] as string),
        };
      }

      if (movementColumn && typeof row['movement_type'] === 'string') {
        next = {
          ...next,
          movement_type: this.translateMovementType(row['movement_type'] as string),
        };
      }

      if (statusColumn && typeof row['status'] === 'string') {
        const statusValue = row['status'] as string;
        next = {
          ...next,
          status: this.translateReconciliationStatus(statusValue) !== statusValue
            ? this.translateReconciliationStatus(statusValue)
            : this.translateReservationStatus(statusValue),
        };
      }

      if (reservationStatusColumn && typeof row['reservation_status'] === 'string') {
        next = {
          ...next,
          reservation_status: this.translateReservationStatus(row['reservation_status'] as string),
        };
      }

      if (transactionTypeColumn && typeof row['type'] === 'string') {
        next = {
          ...next,
          type: this.translateCashTransactionType(row['type'] as string),
        };
      }

      if (occupancyStatusColumn && typeof row['occupancy_status'] === 'string') {
        next = {
          ...next,
          occupancy_status: this.translateOccupancyStatus(row['occupancy_status'] as string),
        };
      }

      if (operationalStatusColumn && typeof row['operational_status'] === 'string') {
        next = {
          ...next,
          operational_status: this.translateOperationalStatus(row['operational_status'] as string),
        };
      }

      if (bucketColumn && typeof row['bucket'] === 'string') {
        next = {
          ...next,
          bucket: this.translateArBucket(row['bucket'] as string),
        };
      }

      if (sourceNameColumn) {
        next = {
          ...next,
          source_name: this.pickLocalizedName(
            row['source_name_ar'],
            row['source_name_en'],
            row['source_name']
          ),
        };
      }

      if (stayReasonColumn) {
        next = {
          ...next,
          stay_reason_name: this.pickLocalizedName(
            row['stay_reason_name_ar'],
            row['stay_reason_name_en'],
            row['stay_reason_name']
          ),
        };
      }

      if (segmentColumn && typeof row['segment'] === 'string') {
        next = {
          ...next,
          segment: this.translatePeakSegment(row['segment'] as string),
        };
      }

      if (floorColumn && typeof row['floor'] === 'string') {
        next = {
          ...next,
          floor: this.translateFloorLabel(row['floor'] as string),
        };
      }

      if (accountTypeColumn && typeof row['type'] === 'string') {
        next = {
          ...next,
          type: this.translateAccountType(row['type'] as string),
        };
      }

      if (chartAccountColumns && typeof row['code'] === 'string') {
        const accountName = this.translateAccountName(row['code'] as string);
        if (accountName) {
          next = {
            ...next,
            name: accountName,
            name_en: accountName,
            name_ar: accountName,
          };
        }
      }

      if (columns.some(c => c.key === 'account_code') && typeof row['account_code'] === 'string') {
        const code = row['account_code'] as string;
        const localizedName = this.formatAccountNameOnly(code);
        next = {
          ...next,
          account_code: this.formatAccountDisplay(code),
          account_name: localizedName !== code ? localizedName : next['account_name'],
        };
      }

      if (operationColumn && typeof row['operation'] === 'string') {
        next = {
          ...next,
          operation: this.translateAuditOperation(row['operation'] as string),
        };
      }

      if (sourceTypeColumn && typeof row['source_type'] === 'string') {
        next = {
          ...next,
          source_type: this.translateJournalSourceType(row['source_type'] as string),
        };
      }

      if ((operationColumn || descriptionColumn) && typeof row['description'] === 'string') {
        next = {
          ...next,
          description: this.translateAuditDescription(row['description'] as string),
        };
      }

      if (typeof row['performed_by'] === 'string') {
        next = {
          ...next,
          performed_by: this.translateAuditPerformer(row['performed_by'] as string),
        };
      }

      return next;
    });
  }

  translateJournalSourceType(value: string): string {
    const slugByValue: Record<string, string> = {
      reservation_pay: 'PAYMENT',
      daily_charge: 'ACCRUAL',
      manual: 'MANUAL',
    };
    const slug = slugByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.SOURCE_TYPES.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  translateAccountType(value: string): string {
    const slug = this.accountTypeKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.ACCOUNT_TYPES.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  /** Map API or localized account-type label back to the English slug expected by the backend. */
  resolveAccountTypeApiValue(value: string | null | undefined): string | null {
    if (!value?.trim()) {
      return null;
    }

    const trimmed = value.trim();
    const lower = trimmed.toLowerCase();
    const valid = ['asset', 'liability', 'equity', 'revenue', 'expense'] as const;
    if ((valid as readonly string[]).includes(lower)) {
      return lower;
    }

    for (const apiValue of valid) {
      const slug = this.accountTypeKeyByValue[apiValue];
      if (!slug) {
        continue;
      }
      const translated = this.translate.instant(`REPORTS.ACCOUNT_TYPES.${slug}`);
      if (translated === trimmed) {
        return apiValue;
      }
    }

    return null;
  }

  translateAccountName(code: string): string | null {
    const slug = this.accountNameKeyByCode[code];
    if (!slug) {
      return null;
    }
    const path = `REPORTS.ACCOUNTS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : null;
  }

  /** Readable account label for ledger/journal rows, e.g. "ذمم مدينة (1100)". */
  formatAccountDisplay(code: string): string {
    if (!code || code === '—') {
      return code;
    }

    const name = this.translateAccountName(code);
    return name ? `${name} (${code})` : code;
  }

  formatAccountNameOnly(code: string): string {
    if (!code || code === '—') {
      return code;
    }

    return this.translateAccountName(code) ?? code;
  }

  translateAuditOperation(value: string): string {
    const slug = this.auditOperationKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.AUDIT_ACTIONS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  translateAuditDescription(value: string): string {
    const paymentMatch = /^Payment posted for reservation #(\d+)$/.exec(value);
    if (paymentMatch) {
      return this.translate.instant('REPORTS.AUDIT_DESC.PAYMENT_RESERVATION', { id: paymentMatch[1] });
    }

    const refundMatch = /^Refund posted for reservation #(\d+)$/.exec(value);
    if (refundMatch) {
      return this.translate.instant('REPORTS.AUDIT_DESC.REFUND_RESERVATION', { id: refundMatch[1] });
    }

    const accrualMatch = /^Room night revenue accrued for reservation #(\d+)$/.exec(value);
    if (accrualMatch) {
      return this.translate.instant('REPORTS.AUDIT_DESC.ACCRUAL_RESERVATION', { id: accrualMatch[1] });
    }

    const periodMatch = /^Accounting period closed: (\d{4}-\d{2})$/.exec(value);
    if (periodMatch) {
      return this.translate.instant('REPORTS.AUDIT_DESC.PERIOD_CLOSED', { period: periodMatch[1] });
    }

    const exactDescriptions: Record<string, string> = {
      'Guest payment': 'REPORTS.JOURNAL_DESC.GUEST_PAYMENT',
      'Guest refund': 'REPORTS.JOURNAL_DESC.GUEST_REFUND',
      'Guest payment posted to ledger': 'REPORTS.AUDIT_DESC.PAYMENT_GENERIC',
      'Guest refund posted to ledger': 'REPORTS.AUDIT_DESC.REFUND_GENERIC',
      'Room night revenue accrued': 'REPORTS.AUDIT_DESC.ACCRUAL_GENERIC',
      'Accounting period closed': 'REPORTS.AUDIT_DESC.PERIOD_CLOSED_GENERIC',
      'Manual journal entry posted': 'REPORTS.AUDIT_DESC.MANUAL_JOURNAL',
      'Financial event recorded': 'REPORTS.AUDIT_DESC.GENERIC',
    };

    const path = exactDescriptions[value];
    if (path) {
      const translated = this.translate.instant(path);
      return translated !== path ? translated : value;
    }

    const journalAccrualMatch = /^Accrual revenue — reservation #(\d+)$/.exec(value);
    if (journalAccrualMatch) {
      return this.translate.instant('REPORTS.JOURNAL_DESC.ACCRUAL_RESERVATION', { id: journalAccrualMatch[1] });
    }

    return value;
  }

  translateAuditPerformer(value: string): string {
    if (value === 'System') {
      const path = 'REPORTS.AUDIT_PERFORMER.SYSTEM';
      const translated = this.translate.instant(path);
      return translated !== path ? translated : value;
    }

    return value;
  }

  translateMovementType(label: string): string {
    const slug = this.movementTypeKeyByLabel[label];
    if (!slug) {
      return label;
    }
    const path = `REPORTS.MOVEMENTS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : label;
  }

  translateArBucket(value: string): string {
    const slug = this.arBucketKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.SUMMARY.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  translatePeakSegment(value: string): string {
    const slug = this.peakSegmentKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.PEAK_SEGMENTS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  private pickLocalizedName(
    nameAr: unknown,
    nameEn: unknown,
    fallback: unknown
  ): string {
    const ar = typeof nameAr === 'string' && nameAr.trim() !== '' ? nameAr : null;
    const en = typeof nameEn === 'string' && nameEn.trim() !== '' ? nameEn : null;
    const base = typeof fallback === 'string' && fallback.trim() !== '' ? fallback : null;

    if (this.translate.currentLang === 'ar') {
      return ar ?? en ?? base ?? '—';
    }

    return en ?? ar ?? base ?? '—';
  }

  translateReconciliationStatus(value: string): string {
    const slug = this.reconStatusKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.RECON_STATUS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  translateOccupancyStatus(value: string): string {
    const slug = this.occupancyStatusKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.OCCUPANCY_STATUS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  translateOperationalStatus(value: string): string {
    const slug = this.operationalStatusKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.OPERATIONAL_STATUS.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  translateReservationStatus(value: string): string {
    const slug = this.reservationStatusKeyByValue[value];
    if (slug) {
      const path = `REPORTS.RESERVATION_STATUS.${slug}`;
      const translated = this.translate.instant(path);
      return translated !== path ? translated : value;
    }

    const statusMatch = /^Status (\d+)$/.exec(value);
    if (statusMatch) {
      const path = 'REPORTS.RESERVATION_STATUS.UNKNOWN_CODE';
      const translated = this.translate.instant(path, { code: statusMatch[1] });
      return translated !== path ? translated : value;
    }

    return value;
  }

  translateCashTransactionType(value: string): string {
    const slug = this.cashTransactionTypeKeyByValue[value];
    if (!slug) {
      return value;
    }
    const path = `REPORTS.CASH_TX_TYPES.${slug}`;
    const translated = this.translate.instant(path);
    return translated !== path ? translated : value;
  }

  translateFloorLabel(value: string): string {
    const match = /^Floor (.+)$/.exec(value);
    if (!match) {
      return value;
    }

    return this.translate.instant('REPORTS.FLOOR_LABEL', { number: match[1] });
  }

  localizeMetaLines(lines: string[]): string[] {
    return lines.map(line => this.translateMetaLine(line));
  }

  localizeColumns(columns: { key: string; label?: string }[]): { key: string; label: string }[] {
    return columns.map(c => ({
      key: c.key,
      label: this.translateColumn(c.key, c.label || c.key)
    }));
  }

  localizeSummary(summary: { label: string; value: string | number }[]): { label: string; value: string | number }[] {
    return summary.map(s => {
      const label = this.translateSummary(s.label);
      let value: string | number = s.value;

      if (this.summaryKeyByLabel[s.label] === 'STATUS' && typeof s.value === 'string') {
        value = this.translateReconciliationStatus(s.value);
      }

      if (this.summaryKeyByLabel[s.label] === 'ACCOUNT_TYPE' && typeof s.value === 'string') {
        value = this.translateAccountType(s.value);
      }

      if (this.summaryKeyByLabel[s.label] === 'ACCOUNT' && typeof s.value === 'string') {
        value = this.formatAccountFilterLabelFromValue(s.value);
      }

      return { label, value };
    });
  }

  /**
   * Localize "1010 — Cash / Bank" (or em/en dash variants) for GL summary / filter banners.
   */
  formatAccountFilterLabelFromValue(value: string): string {
    const trimmed = value.trim();
    if (!trimmed || trimmed === '—') {
      return trimmed;
    }

    const match = /^(\d{3,6})\s*[—–-]\s*(.+)$/.exec(trimmed);
    if (match) {
      return this.formatAccountFilterLabel(match[1], match[2]);
    }

    if (/^\d{3,6}$/.test(trimmed)) {
      return this.formatAccountFilterLabel(trimmed);
    }

    return trimmed;
  }

  formatAccountFilterLabel(code: string, nameFallback = ''): string {
    const normalizedCode = (code || '').trim();
    if (!normalizedCode) {
      return (nameFallback || '').trim();
    }

    const name = this.translateAccountName(normalizedCode) ?? (nameFallback || '').trim();
    return name ? `${normalizedCode} — ${name}` : normalizedCode;
  }
}
