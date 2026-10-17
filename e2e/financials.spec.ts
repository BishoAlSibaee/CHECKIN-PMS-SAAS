import { test, expect, Page } from '@playwright/test';

const API_BASE = 'http://127.0.0.1:8001/api';
const PERIOD_START = '2026-08-01';
const PERIOD_END = '2026-08-31';
const DEMO_MONTH = '2026-08';

function parseMoney(text: string): number {
  const normalized = text.replace(/[^\d.,-]/g, '').replace(/,/g, '');
  return Number(normalized) || 0;
}

async function apiToken(page: Page): Promise<string> {
  const token = await page.evaluate(() => {
    try {
      const raw = localStorage.getItem('user');
      if (!raw) return '';
      const json = raw.startsWith('{') ? raw : atob(raw);
      return (JSON.parse(json).token as string) || '';
    } catch {
      return '';
    }
  });
  expect(token, 'auth token in localStorage').toBeTruthy();
  return token;
}

async function fetchDashboard(page: Page): Promise<Record<string, number>> {
  const token = await apiToken(page);
  const res = await page.request.get(
    `${API_BASE}/users/financials/dashboard?start_date=${PERIOD_START}&end_date=${PERIOD_END}`,
    { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
  );
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body.success).toBe(true);
  const kpis = body.data?.kpis ?? {};
  return {
    accrual_total: Number(kpis.accrual_total ?? 0),
    cash_in: Number(kpis.cash_in ?? 0),
    cash_out: Number(kpis.cash_out ?? 0),
    cash_net: Number(kpis.cash_net ?? 0),
    ar_balance: Number(kpis.ar_balance ?? 0),
    room_nights: Number(kpis.room_nights ?? 0),
    reservation_count: Number(kpis.reservation_count ?? 0),
    tax: Number(kpis.tax ?? 0),
  };
}

async function fetchRevenueTotal(page: Page): Promise<number> {
  const token = await apiToken(page);
  const res = await page.request.get(
    `${API_BASE}/users/revenue/total?start_date=${PERIOD_START}&end_date=${PERIOD_END}&include_details=0`,
    { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
  );
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body.success).toBe(true);
  return Number(body.data?.revenue?.current?.total ?? 0);
}

async function loadAugustFinancials(page: Page): Promise<void> {
  await page.goto('/#/financials');
  await expect(page.locator('app-financials')).toBeVisible({ timeout: 25_000 });

  const monthBtn = page.getByRole('button', { name: /month|شهر/i });
  if (await monthBtn.isVisible()) {
    await monthBtn.click();
  }

  const monthInput = page.locator('input[type="month"]');
  await expect(monthInput).toBeVisible({ timeout: 10_000 });
  await monthInput.fill(DEMO_MONTH);
  await monthInput.blur();

  await expect
    .poll(async () => page.locator('.financials-page .skeleton').count(), { timeout: 30_000 })
    .toBe(0);

  await expect(page.locator('.financials-page .alert-primary strong')).toContainText(/2026|Aug/i, {
    timeout: 15_000,
  });
  await expect(page.locator('.financials-page .stat-card h3').first()).toBeVisible();
}

function uiMatchesApi(uiValue: number, apiValue: number): void {
  // UI uses formatNum with maximumFractionDigits: 0
  expect(uiValue).toBeCloseTo(Math.round(apiValue), 0);
}

async function readStatValues(page: Page): Promise<number[]> {
  const texts = await page.locator('.financials-page .stat-card h3').allTextContents();
  return texts.map(parseMoney);
}

test.describe('Financials dashboard vs API (Aug 2026)', () => {
  test('KPI cards match financials/dashboard and revenue/total', async ({ page }) => {
    await loadAugustFinancials(page);

    const api = await fetchDashboard(page);
    const revenueTotal = await fetchRevenueTotal(page);
    const ui = await readStatValues(page);

    expect(ui.length).toBeGreaterThanOrEqual(7);

    uiMatchesApi(ui[0], api.accrual_total);
    uiMatchesApi(ui[0], revenueTotal);
    uiMatchesApi(ui[1], api.cash_in);
    uiMatchesApi(ui[2], api.cash_out);
    uiMatchesApi(ui[3], api.cash_net);
    expect(ui[4]).toBe(api.room_nights);
    expect(ui[5]).toBe(api.reservation_count);
    uiMatchesApi(ui[6], api.ar_balance);
  });

  test('tax KPI matches tax report API', async ({ page }) => {
    await loadAugustFinancials(page);
    const api = await fetchDashboard(page);
    const token = await apiToken(page);
    const taxRes = await page.request.get(
      `${API_BASE}/users/reports/tax?start_date=${PERIOD_START}&end_date=${PERIOD_END}`,
      { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
    );
    expect(taxRes.ok()).toBeTruthy();
    const taxBody = await taxRes.json();
    const reportTax = Number(
      (taxBody.data?.summary ?? []).find((s: { label: string }) => s.label === 'Total tax')?.value ?? 0
    );
    expect(api.tax).toBeCloseTo(reportTax, 1);
  });

  test('no API error banner on financials page', async ({ page }) => {
    await loadAugustFinancials(page);
    await expect(page.locator('.financials-page .alert-danger')).toHaveCount(0);
  });
});
