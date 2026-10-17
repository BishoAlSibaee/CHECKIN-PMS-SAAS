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

async function firstRoomNumber(page: Page): Promise<string> {
  const token = await apiToken(page);
  const buildings = await page.request.get(`${API_BASE}/users/buildings`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(buildings.ok()).toBeTruthy();
  const buildingId = (await buildings.json()).data?.[0]?.id;
  expect(buildingId).toBeTruthy();

  const rooms = await page.request.get(`${API_BASE}/users/rooms?building_id=${buildingId}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(rooms.ok()).toBeTruthy();
  const number = (await rooms.json()).data?.[0]?.number;
  expect(number).toBeTruthy();
  return String(number);
}

test.describe('Dashboard operations', () => {
  test('arrivals and departures panels render', async ({ page }) => {
    await page.goto('/#/dashboard');
    await expect(page.locator('app-dashboard')).toBeVisible({ timeout: 25_000 });
    await expect.poll(async () => page.locator('app-dashboard .skeleton').count(), { timeout: 30_000 }).toBe(0);
    await expect(page.getByRole('heading', { name: /arrivals today|الوصول اليوم/i })).toBeVisible();
    await expect(page.getByRole('heading', { name: /pending check-out|مغادرة|check-out/i })).toBeVisible();
  });
});

test.describe('Room board operations', () => {
  test('board loads with status filters', async ({ page }) => {
    await page.goto('/#/dashboard/room-board');
    await expect(page.locator('app-room-board')).toBeVisible({ timeout: 25_000 });
    await expect.poll(async () => page.locator('app-room-board .skeleton').count(), { timeout: 30_000 }).toBe(0);
    await page.getByRole('button', { name: /check-out today|مغادرة اليوم/i }).click();
    await expect(page.locator('app-room-board .room-card, app-room-board .list-row').first()).toBeVisible({ timeout: 15_000 });
  });
});

test.describe('Property room history', () => {
  test('room history page loads for first room', async ({ page }) => {
    const roomNumber = await firstRoomNumber(page);
    await page.goto(`/#/admin/property/room/${encodeURIComponent(roomNumber)}/history`);
    await expect(page.locator('app-room-history')).toBeVisible({ timeout: 25_000 });
    await expect.poll(async () => page.locator('app-room-history .skeleton').count(), { timeout: 20_000 }).toBe(0);
    await expect(page.locator('app-room-history h4')).toContainText(roomNumber);
  });
});

test.describe('Pricing', () => {
  test('pricing tabs and plans load', async ({ page }) => {
    await page.goto('/#/admin/pricing');
    await expect(page.locator('app-pricing')).toBeVisible({ timeout: 25_000 });
    await expect.poll(async () => page.locator('app-pricing .skeleton').count(), { timeout: 25_000 }).toBe(0);
    await expect(page.locator('app-pricing .stat-card, app-pricing table, app-pricing .pricing-plan-card').first()).toBeVisible();
  });
});
