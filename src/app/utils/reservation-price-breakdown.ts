export interface DailyPriceLine {
  date: string;
  amount: number;
  price_source?: string;
}

export interface PriceBreakdownLine {
  from: string;
  to: string;
  kind: 'monthly' | 'daily';
  amount: number;
  nights: number;
  billing_source?: string;
}

export function normalizeChargeDate(value: string | Date | null | undefined): string {
  if (!value) {
    return '';
  }
  if (value instanceof Date) {
    return value.toISOString().slice(0, 10);
  }
  const s = String(value);
  return s.length >= 10 ? s.slice(0, 10) : s;
}

/** Sum nightly charges per calendar date (multi-room reservations). */
export function aggregateDailyCharges(
  charges: { charge_date?: string | Date; base_amount?: number | string; price_source?: string }[]
): DailyPriceLine[] {
  const byDate = new Map<string, { amount: number; price_source?: string }>();

  for (const charge of charges) {
    const date = normalizeChargeDate(charge.charge_date);
    if (!date) {
      continue;
    }
    const amount = Number(charge.base_amount ?? 0);
    const existing = byDate.get(date);
    if (existing) {
      existing.amount += amount;
      if (!existing.price_source && charge.price_source) {
        existing.price_source = charge.price_source;
      }
    } else {
      byDate.set(date, { amount, price_source: charge.price_source });
    }
  }

  return [...byDate.entries()]
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([date, row]) => ({
      date,
      amount: row.amount,
      price_source: row.price_source,
    }));
}

function nextDay(date: string): string {
  const x = new Date(date + 'T12:00:00');
  x.setDate(x.getDate() + 1);
  return x.toISOString().slice(0, 10);
}

export function breakdownRowAmount(
  row: Pick<PriceBreakdownLine, 'amount' | 'from' | 'to'>,
  dailyLines?: DailyPriceLine[],
): number {
  if (dailyLines?.length) {
    const periodLines = dailyLinesForPeriod(dailyLines, row);
    if (periodLines.length) {
      return roundMoney(periodLines.reduce((sum, line) => sum + Number(line.amount), 0));
    }
  }

  return roundMoney(Number(row.amount) || 0);
}

/** Average nightly rate for a breakdown row; prefers daily lines when available. */
export function breakdownNightlyRate(
  row: Pick<PriceBreakdownLine, 'amount' | 'nights' | 'from' | 'to'>,
  dailyLines?: DailyPriceLine[],
): number {
  const periodLines = dailyLines?.length ? dailyLinesForPeriod(dailyLines, row) : [];
  if (periodLines.length) {
    const amounts = periodLines.map((line) => Number(line.amount));
    if (amounts.every((amount) => Math.abs(amount - amounts[0]) < 0.01)) {
      return roundRate(amounts[0]);
    }
    const total = amounts.reduce((sum, amount) => sum + amount, 0);
    return roundRate(total / amounts.length);
  }

  const amount = Number(row.amount) || 0;
  const nights = Math.max(1, Number(row.nights) || 1);
  return roundRate(amount / nights);
}

function roundMoney(value: number): number {
  return Math.round(value * 100) / 100;
}

function roundRate(value: number): number {
  return Math.round(value * 10) / 10;
}

/** Group consecutive nights with the same nightly rate into invoice lines. */
export function buildBreakdownFromDailyLines(
  lines: DailyPriceLine[],
  isMonthlyRent: boolean
): PriceBreakdownLine[] {
  const sorted = [...lines].sort((a, b) => a.date.localeCompare(b.date));
  const segments: {
    from: string;
    to: string;
    amount: number;
    nights: number;
    rate: number;
    billing_source?: string;
    amounts: number[];
  }[] = [];
  let cur: {
    from: string;
    to: string;
    amount: number;
    nights: number;
    rate: number;
    billing_source?: string;
    amounts: number[];
  } | null = null;

  for (const line of sorted) {
    const lineSource = line.price_source ?? '';
    const rateTolerance = isMonthlyRent ? 0.011 : 0.01;
    const sameRate = cur !== null && Math.abs(cur.rate - line.amount) < rateTolerance;
    const sameSource = cur !== null && (cur.billing_source ?? '') === lineSource;
    const consecutive = cur !== null && nextDay(cur.to) === line.date;
    if (cur && sameRate && sameSource && consecutive) {
      cur.to = line.date;
      cur.amount += line.amount;
      cur.nights++;
      cur.amounts.push(line.amount);
    } else {
      if (cur) {
        segments.push(cur);
      }
      cur = {
        from: line.date,
        to: line.date,
        amount: line.amount,
        nights: 1,
        rate: line.amount,
        billing_source: line.price_source,
        amounts: [line.amount],
      };
    }
  }
  if (cur) {
    segments.push(cur);
  }

  return segments.map((row) => ({
    from: row.from,
    to: row.to,
    kind: inferBreakdownKind(isMonthlyRent, row.nights, row.amounts),
    amount: row.amount,
    nights: row.nights,
    billing_source: row.billing_source,
  }));
}

function inferBreakdownKind(
  isMonthlyRent: boolean,
  _nights: number,
  amounts: number[],
): 'monthly' | 'daily' {
  if (!isMonthlyRent) {
    return 'daily';
  }

  const unique = [...new Set(amounts.map((amount) => Math.round(amount * 100) / 100))];
  // After a full monthly period, extra nights bill at the flat daily tariff (e.g. $75).
  if (unique.length === 1 && isMonthlyExtraDailyRate(unique[0])) {
    return 'daily';
  }

  return 'monthly';
}

/** Whole-dollar nightly rate — monthly proration yields fractional cents (e.g. 116.67). */
function isMonthlyExtraDailyRate(amount: number): boolean {
  return Math.abs(amount - Math.round(amount)) < 0.001;
}

/** Nightly lines that fall within a grouped breakdown period. */
export function dailyLinesForPeriod(
  lines: DailyPriceLine[],
  row: Pick<PriceBreakdownLine, 'from' | 'to'>,
): DailyPriceLine[] {
  return lines.filter((line) => line.date >= row.from && line.date <= row.to);
}

export function breakdownRowExpandable(
  lines: DailyPriceLine[],
  row: Pick<PriceBreakdownLine, 'from' | 'to' | 'nights'>,
): boolean {
  return dailyLinesForPeriod(lines, row).length > 1 || row.nights > 1;
}
