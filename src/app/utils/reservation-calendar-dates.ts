import { startOfDay } from 'date-fns';

/** Local calendar date as YYYY-MM-DD (hotel stay dates, not UTC). */
export function formatLocalDate(date: Date = new Date()): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export function addCalendarDays(isoDate: string, days: number): string {
  const next = parseApiDateOnly(isoDate);
  next.setDate(next.getDate() + days);
  return formatLocalDate(next);
}

/** Same as Carbon addMonthNoOverflow: advance by whole months without rolling past month-end. */
export function addMonthsNoOverflow(isoDate: string, months: number): string {
  const date = parseApiDateOnly(isoDate);
  let monthIndex = date.getMonth() + months;
  let year = date.getFullYear();

  while (monthIndex > 11) {
    monthIndex -= 12;
    year += 1;
  }
  while (monthIndex < 0) {
    monthIndex += 12;
    year -= 1;
  }

  const day = Math.min(date.getDate(), new Date(year, monthIndex + 1, 0).getDate());

  return formatLocalDate(new Date(year, monthIndex, day));
}

/** Monthly checkout default when check-in is the last calendar day of a month. */
export function monthlyExpireAfterLastDayCheckIn(startIso: string): string {
  const start = parseApiDateOnly(startIso);
  const daysInMonth = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
  if (start.getDate() !== daysInMonth) {
    return addMonthsNoOverflow(startIso, 1);
  }

  const nextMonth = addMonthsNoOverflow(startIso, 1);
  const anchor = parseApiDateOnly(nextMonth);

  return formatLocalDate(new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0));
}

/**
 * Parse API date-only strings (YYYY-MM-DD) in local time — avoids UTC shift from `new Date('YYYY-MM-DD')`.
 */
export function parseApiDateOnly(value: string | Date): Date {
  if (value instanceof Date) {
    return startOfDay(value);
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value).trim());
  if (!match) {
    return startOfDay(new Date(value));
  }

  const year = Number(match[1]);
  const month = Number(match[2]) - 1;
  const day = Number(match[3]);

  return new Date(year, month, day);
}

/**
 * Map reservation stay to angular-calendar all-day event bounds.
 *
 * expire_date is the checkout day (inclusive), same as the list "check-out" column.
 * angular-calendar applies endOfDay(event.end) + 1 day when painting spans, so event.end
 * must be start-of-checkout-day — NOT endOfDay and NOT the day after checkout.
 */
export function toCalendarEventRange(
  startDate: string | Date,
  expireDate: string | Date
): { start: Date; end: Date } {
  return {
    start: parseApiDateOnly(startDate),
    end: parseApiDateOnly(expireDate),
  };
}

/** Whether a calendar day should show the stay bar (inclusive start & checkout). */
export function isCalendarDayInStay(
  day: Date,
  startDate: string | Date,
  expireDate: string | Date
): boolean {
  const { start, end } = toCalendarEventRange(startDate, expireDate);
  const d = parseApiDateOnly(day);
  return d.getTime() >= start.getTime() && d.getTime() <= end.getTime();
}
