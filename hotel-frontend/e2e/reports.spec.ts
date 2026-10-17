import { test, expect, Page } from '@playwright/test';

const API_BASE = 'http://127.0.0.1:8001/api';
const DEMO_START = '2026-08-01';
const DEMO_END = '2026-08-31';

const FINANCIAL_SLUGS = [
  'overview',
  'accrual-revenue',
  'cash-box',
  'revenue-summary',
  'accrual-cash-reconciliation',
  'tax',
  'revpar',
  'closing-package',
];

const AS_OF_SLUGS = new Set(['room-board', 'ar-aging']);

const ACCOUNTING_SLUGS = new Set([
  'chart-of-accounts',
  'journal-entries',
  'general-ledger',
  'trial-balance',
  'cash-flow',
  'financial-audit-log',
]);

const REPORT_SLUGS = [
  'overview',
  'room-board',
  'arrivals-departures',
  'reservations-list',
  'occupancy',
  'accrual-revenue',
  'cash-box',
  'revenue-summary',
  'accrual-cash-reconciliation',
  'ar-aging',
  'adjustments',
  'tax',
  'revpar',
  'by-dimension',
  'peak-analysis',
  'payments-refunds',
  'closing-package',
  'chart-of-accounts',
  'journal-entries',
  'general-ledger',
  'trial-balance',
  'cash-flow',
  'financial-audit-log',
];

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
  expect(token).toBeTruthy();
  return token;
}

async function fetchReportSummary(page: Page, slug: string, label: string): Promise<number | null> {
  const token = await apiToken(page);
  const params = new URLSearchParams({ start_date: DEMO_START, end_date: DEMO_END });
  const res = await page.request.get(`${API_BASE}/users/reports/${slug}?${params}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  const summary = body.data?.summary ?? [];
  const item = summary.find((s: { label: string }) => s.label === label);
  return item && typeof item.value === 'number' ? item.value : null;
}

async function waitForReportData(page: Page): Promise<void> {
  await expect
    .poll(async () => page.locator('.skeleton').count(), { timeout: 30_000 })
    .toBe(0);

  const err = page.locator('.report-runner .alert-danger');
  if ((await err.count()) > 0) {
    throw new Error(`Report API error: ${await err.textContent()}`);
  }

  await expect(
    page.locator('.report-runner .report-summary-bar strong, .report-runner table tbody tr, .report-empty-state').first()
  ).toBeVisible({ timeout: 30_000 });
}

async function loadReport(page: Page, slug: string): Promise<void> {
  await page.goto(`/#/reports/run/${slug}`);
  await expect(page.locator('app-report-runner')).toBeVisible({ timeout: 25_000 });

  const dateInputs = page.locator('.report-runner input[type="date"]');
  const count = await dateInputs.count();

  if (AS_OF_SLUGS.has(slug) && count >= 1) {
    await dateInputs.nth(count === 1 ? 0 : 1).fill(DEMO_END);
  } else if (count >= 2) {
    await dateInputs.nth(0).fill(DEMO_START);
    await dateInputs.nth(1).fill(DEMO_END);
  } else if (count === 1) {
    await dateInputs.nth(0).fill(DEMO_END);
  }

  if (slug === 'general-ledger') {
    await page.locator('.report-runner select').first().selectOption({ index: 1 });
  }

  if (ACCOUNTING_SLUGS.has(slug)) {
    const apply = page.getByRole('button', { name: /Apply filters|تطبيق الفلاتر/i });
    if (await apply.isVisible()) {
      await apply.click();
    }
    await waitForReportData(page);
    return;
  }

  await waitForReportData(page);

  const generate = page.getByRole('button', { name: /^Generate$/i });
  if (await generate.isVisible()) {
    await generate.click();
    await waitForReportData(page);
  }
}

test.describe.configure({ mode: 'serial' });

test.describe('Accounting reports load with filters (Aug 2026)', () => {
  for (const slug of ACCOUNTING_SLUGS) {
    test(slug, async ({ page }) => {
      await loadReport(page, slug);
      await expect(page.locator('.report-runner .alert-danger')).toHaveCount(0);
      const rows = page.locator('.report-runner table tbody tr');
      const summary = page.locator('.report-runner .row.g-2.mb-3 strong');
      await expect.poll(async () => (await rows.count()) + (await summary.count()), { timeout: 20_000 }).toBeGreaterThan(0);
    });
  }
});

test.describe('All report slugs load (demo period Aug 2026)', () => {
  for (const slug of REPORT_SLUGS) {
    test(slug, async ({ page }) => {
      await loadReport(page, slug);
      const rows = page.locator('.report-runner table tbody tr');
      if (!AS_OF_SLUGS.has(slug) && slug !== 'room-board') {
        await expect.poll(async () => rows.count(), { timeout: 15_000 }).toBeGreaterThan(0);
      }
    });
  }
});

test.describe('Financial report API parity', () => {
  test('overview accrual matches accrual-revenue report', async ({ page }) => {
    await page.goto('/#/auth');
    await page.goto('/#/reports/run/overview');
    await expect(page.locator('app-report-runner')).toBeVisible({ timeout: 25_000 });

    const overviewAccrual = await fetchReportSummary(page, 'overview', 'Accrual revenue');
    const accrualTotal = await fetchReportSummary(page, 'accrual-revenue', 'Total revenue');

    expect(overviewAccrual).not.toBeNull();
    expect(accrualTotal).not.toBeNull();
    expect(overviewAccrual!).toBeCloseTo(accrualTotal!, 1);
  });

  for (const slug of FINANCIAL_SLUGS) {
    test(`${slug} API returns success`, async ({ page }) => {
      await page.goto('/#/auth');
      const token = await apiToken(page);
      const res = await page.request.get(
        `${API_BASE}/users/reports/${slug}?start_date=${DEMO_START}&end_date=${DEMO_END}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      expect(res.ok()).toBeTruthy();
      const body = await res.json();
      expect(body.success).toBe(true);
      expect(Array.isArray(body.data?.columns)).toBe(true);
      expect(Array.isArray(body.data?.rows)).toBe(true);
    });
  }
});
