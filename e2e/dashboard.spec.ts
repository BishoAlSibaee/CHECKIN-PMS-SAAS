import { test, expect, Page } from '@playwright/test';

const API_BASE = 'http://127.0.0.1:8001/api';

function parseMoney(text: string): number {
  const normalized = text.replace(/[^\d.,-]/g, '').replace(/,/g, '');
  return Number(normalized) || 0;
}

function parsePercent(text: string): number {
  return Number(text.replace(/[^\d.-]/g, '')) || 0;
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

async function fetchDashboardSummary(page: Page): Promise<Record<string, number>> {
  const token = await apiToken(page);
  const res = await page.request.get(`${API_BASE}/users/dashboard/summary`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body.success).toBe(true);
  const d = body.data ?? {};
  const occ = d.occupancy ?? {};

  return {
    occupancy_rate: Number(occ.occupancy_rate ?? 0),
    total_rooms: Number(occ.total ?? 0),
    in_house: Number(occ.in_house ?? 0),
    month_accrual: Number(d.month_accrual_revenue ?? 0),
    month_cash_net: Number(d.month_cash_net ?? 0),
    check_ins_today: Number(d.check_ins_today ?? 0),
    check_outs_today: Number(d.check_outs_today ?? 0),
    avg_daily_rate: Math.round(Number(d.avg_daily_rate ?? 0)),
  };
}

async function loadDashboard(page: Page): Promise<void> {
  await page.goto('/#/dashboard');
  await expect(page.locator('app-dashboard')).toBeVisible({ timeout: 25_000 });
  await expect
    .poll(async () => page.locator('app-dashboard .skeleton').count(), { timeout: 30_000 })
    .toBe(0);
}

test.describe('Dashboard KPIs vs API', () => {
  test('stat cards match dashboard/summary', async ({ page }) => {
    await loadDashboard(page);
    const api = await fetchDashboardSummary(page);

    const statValues = await page.locator('app-dashboard .stat-card .h3').allTextContents();
    expect(statValues.length).toBeGreaterThanOrEqual(4);

    const occupancyUi = parsePercent(statValues[0]);
    const accrualUi = parseMoney(statValues[1]);
    const inHouseUi = parseMoney(statValues[2]);
    const adrUi = parseMoney(statValues[3]);

    expect(occupancyUi).toBe(api.occupancy_rate);
    expect(accrualUi).toBeCloseTo(api.month_accrual, 0);
    expect(inHouseUi).toBe(api.in_house);
    expect(adrUi).toBeCloseTo(api.avg_daily_rate, 0);
  });

  test('no error banner on dashboard', async ({ page }) => {
    await loadDashboard(page);
    await expect(page.locator('app-dashboard .alert-danger')).toHaveCount(0);
  });
});
