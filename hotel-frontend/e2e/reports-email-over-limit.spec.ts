import { test, expect, Page } from '@playwright/test';

const API_BASE = 'http://127.0.0.1:8001/api';

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

test.describe('report email export over limit', () => {
  test('shows email path when direct download is blocked', async ({ page }) => {
    // Find a wide year window that exceeds the direct-download cap when possible.
    await page.goto('/#/reports/run/reservations-list');
    await expect(page.locator('app-report-runner')).toBeVisible({ timeout: 25_000 });
    const token = await apiToken(page);

    const dateInputs = page.locator('.report-runner input[type="date"]');
    if ((await dateInputs.count()) >= 2) {
      await dateInputs.nth(0).fill('2026-01-01');
      await dateInputs.nth(1).fill('2026-12-31');
    }

    await page.getByRole('button', { name: /Generate|توليد|إنشاء/i }).first().click();
    await expect.poll(async () => page.locator('.skeleton').count(), { timeout: 30_000 }).toBe(0);

    const banner = page.locator('.export-limit-banner');
    const emailCard = page.locator('#report-email-export');
    const smtpOff = page.locator('.report-smtp-off-card');
    const excelBtn = page.locator('button.btn-outline-success').first();

    const health = await page.request.get(`${API_BASE}/users/system/health`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const healthBody = await health.json();
    const mailConfigured = healthBody?.data?.mail_configured === true;

    // If the dataset is under the limit, the policy UI is not required for this run.
    if (!(await banner.isVisible().catch(() => false)) && (await excelBtn.isEnabled())) {
      test.info().annotations.push({
        type: 'note',
        description: 'Dataset under direct-download limit; over-limit UI not exercised.',
      });
      return;
    }

    await expect(excelBtn).toBeDisabled();

    if (mailConfigured) {
      await expect(emailCard).toBeVisible();
      const cta = page.getByRole('button', { name: /Go to email export|اذهب|بريد/i }).first();
      if (await cta.isVisible()) {
        await cta.click();
      }
      await expect(emailCard).toBeInViewport();
      await expect(page.locator('#reportEmailRecipient')).toBeVisible();
      await expect(page.locator('.report-email-queue')).toBeVisible();
    } else {
      await expect(smtpOff).toBeVisible();
      await expect(emailCard).toHaveCount(0);
    }
  });
});
