import { test, expect, BrowserContext, Page } from '@playwright/test';
import * as path from 'path';
import {
  checkInReservationForRealtime,
  ensureLoggedIn,
  fetchDashboardInHouse,
  openRoomBoard,
  requireCheckInCandidate,
  reverbReachable,
  waitForRoomInHouseOnBoard,
} from './helpers/realtime.helpers';

const authFile = path.join(__dirname, '.auth/user.json');

test.describe('Realtime room board (A/B)', () => {
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

  test('check-in in tab A updates room board in tab B without refresh', async ({ page }) => {
    const candidate = await requireCheckInCandidate(page);

    await openRoomBoard(pageB, candidate.date);
    const boardRefresh = pageB.waitForResponse((response) => response.url().includes('/occupancy-board'), {
      timeout: 35_000,
    });
    await checkInReservationForRealtime(page, candidate);
    await boardRefresh;
    await waitForRoomInHouseOnBoard(pageB, candidate.roomNumber, 35_000);
  });
});

test.describe('Realtime dashboard (A/B)', () => {
  test.setTimeout(180_000);

  let contextB: BrowserContext;
  let pageB: Page;

  test.beforeAll(async ({ browser }) => {
    test.skip(!(await reverbReachable()), 'Reverb server not running on port 8080');

    contextB = await browser.newContext({ storageState: authFile });
    pageB = await contextB.newPage();
    await pageB.goto('/#/dashboard');
    await expect(pageB.locator('app-dashboard')).toBeVisible({ timeout: 30_000 });
  });

  test.afterAll(async () => {
    await contextB?.close();
  });

  test('check-in in room board updates dashboard KPIs in tab B', async ({ page }) => {
    const candidate = await requireCheckInCandidate(page);
    const beforeInHouse = await fetchDashboardInHouse(pageB);

    const dashboardRefresh = pageB.waitForResponse((response) => response.url().includes('/dashboard/summary'), {
      timeout: 35_000,
    });
    await checkInReservationForRealtime(page, candidate);
    await dashboardRefresh;

    await expect
      .poll(async () => fetchDashboardInHouse(pageB), { timeout: 35_000 })
      .toBeGreaterThan(beforeInHouse);
  });
});
