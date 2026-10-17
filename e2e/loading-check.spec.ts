import { test, expect } from '@playwright/test';

test('dashboard exits loading state and shows stats', async ({ page }) => {
  await page.goto('/#/dashboard');
  await page.waitForTimeout(3000);
  const skeletonCount = await page.locator('.skeleton').count();
  const occupancyText = await page.getByText(/occupancy|إشغال/i).first().isVisible().catch(() => false);
  const statNumbers = await page.locator('.stat-card').first().innerText();
  console.log('skeletons:', skeletonCount, 'occupancy:', occupancyText, 'stat:', statNumbers.slice(0, 80));
  expect(skeletonCount).toBe(0);
});

test('clients table exits loading state', async ({ page }) => {
  await page.goto('/#/admin/clients');
  await page.waitForTimeout(5000);
  const skeletonRows = await page.locator('tbody .skeleton').count();
  const dataRows = await page.locator('tbody tr.row-hover').count();
  console.log('client skeleton rows:', skeletonRows, 'data rows:', dataRows);
  expect(skeletonRows).toBe(0);
  expect(dataRows).toBeGreaterThan(0);
});
