import { test, expect } from '@playwright/test';

test.describe('Hotel SPA smoke', () => {
  test('dashboard loads', async ({ page }) => {
    await page.goto('/#/dashboard');
    await expect(page.locator('app-dashboard, .dashboard-page, h1, h2').first()).toBeVisible({ timeout: 20_000 });
  });

  test('reservations list loads', async ({ page }) => {
    await page.goto('/#/booking/reservations');
    await expect(page.locator('app-reservations, .reservations-page, table').first()).toBeVisible({ timeout: 20_000 });
  });

  test('property admin loads', async ({ page }) => {
    await page.goto('/#/admin/property');
    await expect(page.locator('app-property, .property-page').first()).toBeVisible({ timeout: 20_000 });
  });

  test('financials loads', async ({ page }) => {
    await page.goto('/#/financials');
    await expect(page.locator('app-financials, .financials-page').first()).toBeVisible({ timeout: 20_000 });
  });

  test('reports hub loads', async ({ page }) => {
    await page.goto('/#/reports');
    await expect(page.locator('app-reports-hub, .reports-hub').first()).toBeVisible({ timeout: 20_000 });
  });
});
