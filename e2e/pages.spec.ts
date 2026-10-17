import { test, expect, Page } from '@playwright/test';

interface PageCase {
  name: string;
  hash: string;
  selector: string;
  maxSkeletons?: number;
}

const PAGES: PageCase[] = [
  { name: 'Dashboard', hash: '/#/dashboard', selector: 'app-dashboard' },
  { name: 'Room board', hash: '/#/dashboard/room-board', selector: 'app-room-board' },
  { name: 'Reservations', hash: '/#/booking/reservations', selector: 'app-reservations' },
  { name: 'Create reservation', hash: '/#/booking/reservations/create', selector: 'app-create-reservation' },
  { name: 'Reservation detail', hash: '/#/booking/reservations/8', selector: 'app-reservation-detail' },
  { name: 'Peak periods', hash: '/#/booking/peak-periods', selector: 'app-peak-periods' },
  { name: 'Financials', hash: '/#/financials', selector: 'app-financials' },
  { name: 'Reports hub', hash: '/#/reports', selector: 'app-reports-hub' },
  { name: 'Report overview', hash: '/#/reports/run/overview', selector: 'app-report-runner' },
  { name: 'Property', hash: '/#/admin/property', selector: 'app-property' },
  { name: 'Pricing', hash: '/#/admin/pricing', selector: 'app-pricing' },
  { name: 'Users', hash: '/#/admin/users', selector: 'app-users' },
  { name: 'Clients', hash: '/#/admin/clients', selector: 'app-clients' },
  { name: 'Client details', hash: '/#/admin/clients/1', selector: 'app-client-details' },
  { name: 'Departments', hash: '/#/admin/departments', selector: 'app-departments' },
  { name: 'Job titles', hash: '/#/admin/job-titles', selector: 'app-job-titles' },
  { name: 'Penalties', hash: '/#/admin/penalties', selector: 'app-penalties' },
  { name: 'Refund policies', hash: '/#/admin/refund-policies', selector: 'app-refund-policies' },
  { name: 'Discounts', hash: '/#/admin/discounts', selector: 'app-discounts' },
  { name: 'Guests', hash: '/#/admin/guests', selector: 'app-guests' },
];

async function assertPageLoaded(page: Page, spec: PageCase): Promise<void> {
  await page.goto(spec.hash);
  await expect(page.locator(spec.selector).first()).toBeVisible({ timeout: 25_000 });
  await expect
    .poll(async () => page.locator('.skeleton').count(), { timeout: 20_000 })
    .toBe(spec.maxSkeletons ?? 0);
}

test.describe('All SPA pages load data (no stuck skeleton)', () => {
  for (const spec of PAGES) {
    test(spec.name, async ({ page }) => {
      await assertPageLoaded(page, spec);
    });
  }
});
