import { test, expect, Page } from '@playwright/test';

/** Fixed far-future dates — avoids flaky room availability vs random offsets. */
const BOOKING_START = '2027-03-15';
const BOOKING_END = '2027-03-18';

async function selectClient(page: Page, clientId = '1'): Promise<void> {
  await page.goto(`/#/booking/reservations/create?clientId=${clientId}`);
  await expect(page.locator('app-create-reservation')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('.client-preview').first()).toBeVisible({ timeout: 15_000 });
}

async function pickAvailableRoom(page: Page): Promise<void> {
  const available = page.locator('.room-availability-card:not([disabled])').first();
  await expect(available).toBeVisible({ timeout: 45_000 });
  await available.click();
  await expect(page.locator('button[type="submit"]')).toBeEnabled({ timeout: 30_000 });
}

test.describe.serial('Booking lifecycle E2E', () => {
  test.setTimeout(90_000);
  let reservationDetailUrl = '';

  test('create reservation with client and room', async ({ page }) => {
    await selectClient(page);

    await page.locator('input[name="start_date"]').fill(BOOKING_START);
    await page.locator('input[name="expire_date"]').fill(BOOKING_END);
    await page.locator('input[name="expire_date"]').blur();

    await pickAvailableRoom(page);
    await page.locator('button[type="submit"]').click();

    await page.waitForURL(/#\/booking\/reservations\/\d+/, { timeout: 45_000 });
    reservationDetailUrl = page.url();
    await expect(page.locator('app-reservation-detail')).toBeVisible();
  });

  test('record full payment on new reservation', async ({ page }) => {
    test.skip(!reservationDetailUrl, 'depends on create reservation');
    await page.goto(reservationDetailUrl);

    const addPayment = page.getByRole('button', { name: /add payment|إضافة دفع/i }).first();
    await expect(addPayment).toBeVisible({ timeout: 20_000 });
    await addPayment.click();

    const payModal = page.locator('.payment-modal.card').last();
    await expect(payModal).toBeVisible();
    await payModal.locator('button.btn-outline-primary').click();
    await payModal.getByRole('button', { name: /process payment|معالجة الدفع/i }).click();

    await expect(payModal).toBeHidden({ timeout: 30_000 });
    await expect(page.locator('app-reservation-detail').getByRole('button', { name: /add payment|إضافة دفع/i })).toHaveCount(0);
  });

  test('refund and cancel paid reservation', async ({ page }) => {
    test.skip(!reservationDetailUrl, 'depends on create reservation');
    await page.goto(reservationDetailUrl);

    const refundBtn = page.getByRole('button', { name: /refund|استرداد/i }).first();
    await expect(refundBtn).toBeVisible({ timeout: 20_000 });
    await refundBtn.click();

    await expect(page.locator('.payment-modal-backdrop')).toBeVisible();
    const refundModal = page.locator('.payment-modal.card').last();
    await expect(refundModal.getByRole('button', { name: /confirm refund|تأكيد الاسترداد/i })).toBeEnabled({ timeout: 20_000 });
    await refundModal.getByRole('button', { name: /confirm refund|تأكيد الاسترداد/i }).click();

    await expect(refundModal).toBeHidden({ timeout: 30_000 });
  });
});

test.describe('Report export E2E', () => {
  test('overview report exports Excel', async ({ page }) => {
    await page.goto('/#/reports/run/overview');
    await expect(page.locator('app-report-runner')).toBeVisible({ timeout: 20_000 });

    const dateInputs = page.locator('input[type="date"]');
    await dateInputs.nth(0).fill('2026-08-01');
    await dateInputs.nth(1).fill('2026-08-31');
    await page.getByRole('button', { name: /^Generate$/i }).click();

    await expect
      .poll(async () => page.locator('table tbody tr').count(), { timeout: 30_000 })
      .toBeGreaterThan(0);

    const exportBtn = page.locator('button.btn-outline-success').first();
    await expect(exportBtn).toBeEnabled({ timeout: 10_000 });

    const downloadPromise = page.waitForEvent('download', { timeout: 20_000 });
    await exportBtn.click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/i);
  });
});
