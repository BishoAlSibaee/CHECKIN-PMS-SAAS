import { test, expect } from '@playwright/test';

const DEMO_START = '2026-08-01';
const DEMO_END = '2026-08-31';

const EXPORT_SLUGS = [
  { slug: 'accrual-revenue', format: 'excel' },
  { slug: 'cash-box', format: 'excel' },
  { slug: 'trial-balance', format: 'excel' },
  { slug: 'revenue-summary', format: 'pdf' },
];

async function runReportAndExport(page: import('@playwright/test').Page, slug: string, ext: string): Promise<void> {
  await page.goto(`/#/reports/run/${slug}`);
  await expect(page.locator('app-report-runner')).toBeVisible({ timeout: 25_000 });

  const dateInputs = page.locator('input[type="date"]');
  const count = await dateInputs.count();
  if (count >= 2) {
    await dateInputs.nth(0).fill(DEMO_START);
    await dateInputs.nth(1).fill(DEMO_END);
  } else if (count === 1) {
    await dateInputs.nth(0).fill(DEMO_END);
  }

  await page.getByRole('button', { name: /^Generate$/i }).click();
  await expect
    .poll(async () => page.locator('table tbody tr').count(), { timeout: 30_000 })
    .toBeGreaterThan(0);

  const btnClass = ext === 'pdf' ? 'btn-outline-danger' : 'btn-outline-success';
  const exportBtn = page.locator(`button.${btnClass}`).first();
  await expect(exportBtn).toBeEnabled({ timeout: 10_000 });

  const downloadPromise = page.waitForEvent('download', { timeout: 30_000 });
  await exportBtn.click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(new RegExp(`\\.${ext === 'pdf' ? 'pdf' : 'xlsx'}$`, 'i'));
}

test.describe.configure({ mode: 'serial' });

for (const { slug, format } of EXPORT_SLUGS) {
  test(`export ${slug} as ${format}`, async ({ page }) => {
    await runReportAndExport(page, slug, format);
  });
}
