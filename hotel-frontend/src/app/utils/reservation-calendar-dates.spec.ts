import { formatLocalDate, parseApiDateOnly, isCalendarDayInStay, toCalendarEventRange, addCalendarDays, addMonthsNoOverflow, monthlyExpireAfterLastDayCheckIn } from './reservation-calendar-dates';

describe('reservation-calendar-dates', () => {
  const start = '2026-06-15';
  const expire = '2026-06-18';

  it('parses API date-only strings in local time', () => {
    const d = parseApiDateOnly('2026-06-18');
    expect(d.getFullYear()).toBe(2026);
    expect(d.getMonth()).toBe(5);
    expect(d.getDate()).toBe(18);
  });

  it('maps checkout day as last visible calendar day (not the day after)', () => {
    const range = toCalendarEventRange(start, expire);
    expect(range.start.getDate()).toBe(15);
    expect(range.end.getDate()).toBe(18);
    expect(range.end.getHours()).toBe(0);
    expect(range.end.getMinutes()).toBe(0);
  });

  it('includes checkout day 18 but not day 19 for Abdulrouf-style stay', () => {
    expect(isCalendarDayInStay(new Date(2026, 5, 15), start, expire)).toBeTrue();
    expect(isCalendarDayInStay(new Date(2026, 5, 18), start, expire)).toBeTrue();
    expect(isCalendarDayInStay(new Date(2026, 5, 19), start, expire)).toBeFalse();
  });

  it('formats local calendar date without UTC shift', () => {
    const local = new Date(2026, 5, 27, 23, 30);
    expect(formatLocalDate(local)).toBe('2026-06-27');
    expect(formatLocalDate(local).startsWith('2026-06-2')).toBeTrue();
  });

  it('adds calendar days in local time', () => {
    expect(addCalendarDays('2026-06-27', 1)).toBe('2026-06-28');
    expect(addCalendarDays('2026-06-30', 1)).toBe('2026-07-01');
  });

  it('adds months without overflowing past month-end', () => {
    expect(addMonthsNoOverflow('2026-08-31', 1)).toBe('2026-09-30');
    expect(addMonthsNoOverflow('2026-01-31', 1)).toBe('2026-02-28');
    expect(addMonthsNoOverflow('2024-01-31', 1)).toBe('2024-02-29');
    expect(addMonthsNoOverflow('2026-08-15', 1)).toBe('2026-09-15');
  });

  it('defaults monthly checkout to last day of next month after last-day check-in', () => {
    expect(monthlyExpireAfterLastDayCheckIn('2026-09-30')).toBe('2026-10-31');
    expect(monthlyExpireAfterLastDayCheckIn('2026-07-31')).toBe('2026-08-31');
    expect(monthlyExpireAfterLastDayCheckIn('2026-08-15')).toBe('2026-09-15');
  });
});
