import { test, expect, BrowserContext, Page } from '@playwright/test';
import * as path from 'path';
import {
  ensureLoggedIn,
  fetchCollectionsTotalViaApi,
  openReservationDetailWithBalance,
  provisionReservationWithBalance,
  recordReservationPaymentViaApi,
  reverbReachable,
} from './helpers/realtime.helpers';

const authFile = path.join(__dirname, '.auth/user.json');

test.describe('Realtime collections (A/B)', () => {
  test.setTimeout(180_000);

  let contextB: BrowserContext;
  let pageB: Page;

  test.beforeAll(async ({ browser }) => {
    test.skip(!(await reverbReachable()), 'Reverb server not running on port 8080');

    contextB = await browser.newContext({ storageState: authFile });
    pageB = await contextB.newPage();
    await ensureLoggedIn(pageB);
  });

  test.afterAll(async () => {
    await contextB?.close();
  });

  test('payment in reservation detail updates collections balance in tab B', async ({ page }) => {
    const reservationId = await provisionReservationWithBalance(page);

    await pageB.goto('/#/booking/collections');
    await expect(pageB.locator('app-collections')).toBeVisible({ timeout: 30_000 });
    const summaryBefore = await fetchCollectionsTotalViaApi(pageB);
    expect(summaryBefore, 'collections total should be loaded').toBeGreaterThan(0);

    await openReservationDetailWithBalance(page, reservationId);
    await recordReservationPaymentViaApi(page, reservationId);

    await expect
      .poll(async () => fetchCollectionsTotalViaApi(pageB), { timeout: 35_000 })
      .toBeLessThan(summaryBefore);
  });
});
