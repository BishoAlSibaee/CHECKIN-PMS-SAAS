import { test, expect } from '@playwright/test';

async function settleReport(page: import('@playwright/test').Page): Promise<void> {
  await expect.poll(async () => page.locator('.skeleton').count(), { timeout: 30_000 }).toBe(0);
  // Allow soft-refresh / layout settle before capturing.
  await page.waitForTimeout(400);
}

test.describe('reports visual regression', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('hub EN layout anchors', async ({ page }) => {
    await page.goto('/#/reports');
    await expect(page.locator('app-reports-hub')).toBeVisible({ timeout: 25_000 });
    await expect(page.locator('.reports-hub__hero')).toBeVisible();
    await expect(page.locator('.reports-hub__finance-link')).toBeVisible();
    await expect(page.locator('.reports-hub__popular')).toBeVisible();
    await expect(page.locator('.report-card').first()).toBeVisible();
    await expect(page.locator('.reports-hub__hero')).toHaveScreenshot('reports-hub-header-en.png', {
      maxDiffPixelRatio: 0.04,
    });
  });

  test('hub AR RTL layout anchors', async ({ page }) => {
    await page.goto('/#/reports');
    await expect(page.locator('app-reports-hub')).toBeVisible({ timeout: 25_000 });
    await page.evaluate(() => {
      localStorage.setItem('language', 'ar');
      document.documentElement.lang = 'ar';
      document.documentElement.dir = 'rtl';
    });
    await page.reload();
    await expect(page.locator('app-reports-hub')).toBeVisible({ timeout: 25_000 });
    await expect(page.locator('html')).toHaveAttribute('dir', /rtl/i);
    await expect(page.locator('.reports-hub__popular')).toBeVisible();
    await expect(page.locator('.reports-hub__hero')).toHaveScreenshot('reports-hub-header-ar.png', {
      maxDiffPixelRatio: 0.04,
    });
  });

  test('runner financial + accounting shells', async ({ page }) => {
    await page.goto('/#/reports/run/cash-box');
    await expect(page.locator('app-report-runner')).toBeVisible({ timeout: 25_000 });
    await settleReport(page);
    await expect(page.locator('.report-controls')).toBeVisible();
    await expect(page.locator('.report-guide')).toBeVisible();
    await expect(page.locator('.report-controls')).toHaveScreenshot('report-controls-cash-box.png', {
      maxDiffPixelRatio: 0.04,
    });

    await page.goto('/#/reports/run/journal-entries');
    await expect(page.locator('app-report-runner')).toBeVisible({ timeout: 25_000 });
    await settleReport(page);
    await expect(page.locator('.report-filters-panel')).toBeVisible();
    await expect(page.locator('.report-filters-panel')).toHaveScreenshot('report-filters-journal.png', {
      maxDiffPixelRatio: 0.04,
    });
  });
});
