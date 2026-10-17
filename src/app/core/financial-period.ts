export type FinancialPeriodPreset = 'all' | 'last_week' | 'month' | 'last_year' | 'year';

export interface FinancialPeriodBounds {
  earliest_charge_date?: string | null;
  earliest_payment_date?: string | null;
}

export interface ResolvedFinancialPeriod {
  preset: FinancialPeriodPreset;
  start: string;
  end: string;
  labelKey: string;
  labelParams?: Record<string, string | number>;
}

function formatLocalDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function today(): Date {
  return new Date();
}

function endOfMonth(year: number, monthIndex: number): Date {
  return new Date(year, monthIndex + 1, 0);
}

export function resolveFinancialPeriod(
  preset: FinancialPeriodPreset,
  options: {
    monthValue?: string;
    yearValue?: number;
    bounds?: FinancialPeriodBounds | null;
  } = {}
): ResolvedFinancialPeriod {
  const now = today();
  const end = formatLocalDate(now);

  if (preset === 'last_week') {
    const start = new Date(now);
    start.setDate(now.getDate() - 6);
    return {
      preset,
      start: formatLocalDate(start),
      end,
      labelKey: 'FINANCIALS.PRESET_LAST_WEEK'
    };
  }

  if (preset === 'month') {
    const monthValue = options.monthValue || `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    const [y, m] = monthValue.split('-').map(Number);
    const start = formatLocalDate(new Date(y, m - 1, 1));
    const endDate = formatLocalDate(endOfMonth(y, m - 1));
    return {
      preset,
      start,
      end: endDate,
      labelKey: 'FINANCIALS.PRESET_MONTH',
      labelParams: { month: monthValue }
    };
  }

  if (preset === 'last_year') {
    const year = now.getFullYear() - 1;
    return {
      preset,
      start: `${year}-01-01`,
      end: `${year}-12-31`,
      labelKey: 'FINANCIALS.PRESET_LAST_YEAR',
      labelParams: { year }
    };
  }

  if (preset === 'year') {
    const year = options.yearValue ?? now.getFullYear();
    return {
      preset,
      start: `${year}-01-01`,
      end: `${year}-12-31`,
      labelKey: 'FINANCIALS.PRESET_YEAR',
      labelParams: { year }
    };
  }

  const bounds = options.bounds;
  const candidates = [
    bounds?.earliest_charge_date,
    bounds?.earliest_payment_date
  ].filter((d): d is string => !!d);

  let start: string;
  if (candidates.length > 0) {
    start = candidates.sort()[0];
  } else {
    const fallback = new Date(now);
    fallback.setFullYear(fallback.getFullYear() - 5);
    start = formatLocalDate(fallback);
  }

  return {
    preset: 'all',
    start,
    end,
    labelKey: 'FINANCIALS.PRESET_ALL'
  };
}

export function buildYearOptions(count = 8): number[] {
  const current = new Date().getFullYear();
  return Array.from({ length: count }, (_, i) => current - i);
}
