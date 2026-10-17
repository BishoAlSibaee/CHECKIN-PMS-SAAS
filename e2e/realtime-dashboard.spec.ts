import { test, expect, Page } from '@playwright/test';

const API_BASE = 'http://127.0.0.1:8001/api';
const REVERB_PORT = 8080;

async function reverbReachable(): Promise<boolean> {
  try {
    const res = await fetch(`http://127.0.0.1:${REVERB_PORT}`, { method: 'GET' });
    return res.status < 500;
  } catch {
    return false;
  }
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
  expect(token).toBeTruthy();
  return token;
}

test.describe('Realtime dashboard KPI sync', () => {
  test.setTimeout(90_000);

  test('dashboard summary reflects API after realtime refresh', async ({ page }) => {
    test.skip(!(await reverbReachable()), 'Reverb server not running on port 8080');

    await page.goto('/#/dashboard');
    await expect(page.locator('app-dashboard')).toBeVisible({ timeout: 25_000 });

    const token = await apiToken(page);
    const res = await page.request.get(`${API_BASE}/users/dashboard/summary`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    const apiInHouse = Number(body.data?.occupancy?.in_house ?? 0);

    await expect
      .poll(async () => {
        const text = await page.locator('app-dashboard').innerText();
        return text.includes(String(apiInHouse));
      }, { timeout: 15_000 })
      .toBe(true);
  });
});
