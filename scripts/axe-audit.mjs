/**
 * Authenticated axe-core audit for Hotel SPA.
 * Usage: node scripts/axe-audit.mjs
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { chromium } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'e2e-results', 'lighthouse');
const BASE = 'http://127.0.0.1:4300';

const PAGES = [
  { name: 'auth', hash: '/#/auth' },
  { name: 'dashboard', hash: '/#/dashboard' },
  { name: 'room-board', hash: '/#/dashboard/room-board' },
  { name: 'reservations', hash: '/#/booking/reservations' },
  { name: 'financials', hash: '/#/financials' },
  { name: 'reports', hash: '/#/reports' },
  { name: 'clients', hash: '/#/admin/clients' },
  { name: 'users', hash: '/#/admin/users' },
  { name: 'property', hash: '/#/admin/property' },
];

async function login(page) {
  await page.goto(`${BASE}/#/auth`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[formcontrolname="job_number"], input[name="job_number"]').first().fill('001');
  await page.locator('input[formcontrolname="password"], input[type="password"]').first().fill('admin123');
  await page.locator('button.login-submit, button[type="submit"]').first().click();
  await page.waitForURL(/#\/(dashboard|booking|admin)/, { timeout: 45_000 });
  await page.locator('app-sidebar').first().waitFor({ state: 'visible', timeout: 20_000 });
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const boot = await browser.newContext();
  const bootPage = await boot.newPage();
  console.log('Logging in…');
  await login(bootPage);
  const storage = path.join(ROOT, 'e2e', '.auth', 'user.json');
  fs.mkdirSync(path.dirname(storage), { recursive: true });
  await boot.storageState({ path: storage });
  await boot.close();

  const summary = [];
  const axeAgg = {};

  for (const spec of PAGES) {
    const url = `${BASE}${spec.hash}`;
    console.log(`\n=== ${spec.name} ===`);
    const ctx = await browser.newContext({ storageState: storage });
    const page = await ctx.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await page.waitForTimeout(2500);
    try {
      await page.waitForFunction(() => document.querySelectorAll('.skeleton').length === 0, null, {
        timeout: 15_000,
      });
    } catch {
      /* ignore */
    }

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'best-practice'])
      .analyze();

    const byId = {};
    for (const v of results.violations) {
      byId[v.id] = {
        impact: v.impact,
        help: v.help,
        description: v.description,
        nodes: v.nodes.length,
        targets: v.nodes.slice(0, 10).map((n) => n.target.join(' ')),
        html: v.nodes.slice(0, 3).map((n) => n.html?.slice(0, 180)),
        failureSummary: v.nodes[0]?.failureSummary?.slice(0, 500) || null,
      };
      console.log(`  - ${v.id} (${v.impact}) x${v.nodes.length}: ${v.help}`);
      axeAgg[v.id] ??= { impact: v.impact, help: v.help, pages: [], totalNodes: 0 };
      axeAgg[v.id].pages.push(spec.name);
      axeAgg[v.id].totalNodes += v.nodes.length;
    }
    console.log(`axe violations: ${results.violations.length}`);

    const row = {
      page: spec.name,
      url,
      axeViolations: results.violations.length,
      axe: byId,
    };
    summary.push(row);
    fs.writeFileSync(path.join(OUT_DIR, `${spec.name}.axe.json`), JSON.stringify(row, null, 2));
    await ctx.close();
  }

  await browser.close();

  const report = { generatedAt: new Date().toISOString(), base: BASE, pages: summary, axeByRule: axeAgg };
  fs.writeFileSync(path.join(OUT_DIR, 'summary.json'), JSON.stringify(report, null, 2));

  const lines = ['# axe audit summary', '', '| Page | axe violations |', '|---|---:|'];
  for (const row of summary) {
    lines.push(`| ${row.page} | ${row.axeViolations} |`);
  }
  lines.push('', '## Rules across pages', '');
  for (const [id, info] of Object.entries(axeAgg).sort((a, b) => b[1].totalNodes - a[1].totalNodes)) {
    lines.push(
      `- **${id}** (${info.impact}): ${info.help} — ${info.totalNodes} nodes on ${[...new Set(info.pages)].join(', ')}`
    );
  }
  fs.writeFileSync(path.join(OUT_DIR, 'SUMMARY.md'), lines.join('\n'));
  console.log(`\nWrote ${path.join(OUT_DIR, 'SUMMARY.md')}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
