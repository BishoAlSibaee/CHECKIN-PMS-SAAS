import { test, expect } from '@playwright/test';
import { isCalendarDayInStay } from '../src/app/utils/reservation-calendar-dates';

/**
 * Calendar/list alignment: checkout day in table must match last day on month calendar.
 * Uses same logic as reservations.component (Abdulrouf 15→18 case).
 */
test.describe('Reservations calendar date alignment', () => {
  test('checkout day 18 is last calendar day, not 19', () => {
    const start = '2026-06-15';
    const checkout = '2026-06-18';

    expect(isCalendarDayInStay(new Date(2026, 5, 18), start, checkout)).toBe(true);
    expect(isCalendarDayInStay(new Date(2026, 5, 19), start, checkout)).toBe(false);
  });

  test('checkout day 4 July is last calendar day, not 5', () => {
    const start = '2026-07-01';
    const checkout = '2026-07-04';

    expect(isCalendarDayInStay(new Date(2026, 6, 4), start, checkout)).toBe(true);
    expect(isCalendarDayInStay(new Date(2026, 6, 5), start, checkout)).toBe(false);
  });

  test('reservations page loads calendar and list', async ({ page }) => {
    await page.goto('/#/booking/reservations');
    await expect(page.locator('app-reservations')).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('mwl-calendar-month-view, .calendar-wrap').first()).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 20_000 });
  });
});
