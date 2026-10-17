import { test as setup, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const authFile = path.join(__dirname, '.auth/user.json');

setup('authenticate as admin', async ({ page }) => {
  fs.mkdirSync(path.dirname(authFile), { recursive: true });

  await page.goto('/#/auth');
  await page.locator('input[formcontrolname="job_number"], input[name="job_number"]').first().fill('001');
  await page.locator('input[formcontrolname="password"], input[type="password"]').first().fill('admin123');
  await page.locator('button.login-submit, button[type="submit"]').first().click();

  await page.waitForURL(/#\/(dashboard|booking|admin)/, { timeout: 30_000 });
  await expect(page.locator('app-sidebar').first()).toBeVisible({ timeout: 15_000 });

  await page.context().storageState({ path: authFile });
});
