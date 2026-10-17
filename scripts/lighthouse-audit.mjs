/**
 * Authenticated Lighthouse + axe audit for Hotel SPA (hash routes).
 * Usage: node scripts/lighthouse-audit.mjs
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { chromium } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import lighthouse from 'lighthouse';
import * as chromeLauncher from 'chrome-launcher';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'e2e-results', 'lighthouse');
const BASE = 'http://127.0.0.1:4300';
const ONLY_AXE = process.argv.includes('--axe-only');

const PAGES = [
  { name: 'auth', hash: '/#/auth', authed: false },
  { name: 'dashboard', hash: '/#/dashboard', authed: true },
  { name: 'room-board', hash: '/#/dashboard/room-board', authed: true },
  { name: 'reservations', hash: '/#/booking/reservations', authed: true },
  { name: 'financials', hash: '/#/financials', authed: true },
  { name: 'reports', hash: '/#/reports', authed: true },
  { name: 'clients', hash: '/#/admin/clients', authed: true },
  { name: 'users', hash: '/#/admin/users', authed: true },
  { name: 'property', hash: '/#/admin/property', authed: true },
];

function scorePct(v) {
  if (v == null || Number.isNaN(v)) return null;
  return Math.round(v * 100);
}

async function login(page) {
  await page.goto(`${BASE}/#/auth`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[formcontrolname="job_number"], input[name="job_number"]').first().fill('001');
  await page.locator('input[formcontrolname="password"], input[type="password"]').first().fill('admin123');
  await page.locator('button.login-submit, button[type="submit"]').first().click();
  await page.waitForURL(/#\/(dashboard|booking|admin)/, { timeout: 45_000 });
  await page.locator('app-sidebar').first().waitFor({ state: 'visible', timeout: 20_000 });
}

async function runAxe(page, name) {
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
      targets: v.nodes.slice(0, 8).map((n) => n.target.join(' ')),
      failureSummary: v.nodes[0]?.failureSummary?.slice(0, 400) || null,
    };
  }
  return {
    name,
    violations: results.violations.length,
    passes: results.passes.length,
    incomplete: results.incomplete.length,
    byId,
  };
}

async function runLighthouse(url, cookieHeader) {
  const userDataDir = path.join(OUT_DIR, `.chrome-${Date.now()}`);
  fs.mkdirSync(userDataDir, { recursive: true });
  const chrome = await chromeLauncher.launch({
    chromeFlags: ['--headless=new', '--no-sandbox', '--disable-gpu', `--user-data-dir=${userDataDir}`],
  });
  try {
    const result = await lighthouse(url, {
      port: chrome.port,
      output: 'json',
      onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'],
      formFactor: 'desktop',
      screenEmulation: { disabled: true },
      extraHeaders: cookieHeader ? { Cookie: cookieHeader } : undefined,
    });
    const cats = result.lhr.categories;
    const audits = result.lhr.audits;
    const failed = Object.values(audits)
      .filter(
        (a) =>
          a.score !== null &&
          a.score < 1 &&
          !['informative', 'manual', 'notApplicable'].includes(a.scoreDisplayMode)
      )
      .map((a) => ({
        id: a.id,
        title: a.title,
        score: scorePct(a.score),
        displayValue: a.displayValue || null,
      }))
      .sort((a, b) => (a.score ?? 0) - (b.score ?? 0))
      .slice(0, 20);

    return {
      scores: {
        performance: scorePct(cats.performance?.score),
        accessibility: scorePct(cats.accessibility?.score),
        bestPractices: scorePct(cats['best-practices']?.score),
        seo: scorePct(cats.seo?.score),
      },
      failedAudits: failed,
      raw: result.lhr,
    };
  } finally {
    try {
      await chrome.kill();
    } catch {
      /* Windows EPERM on temp cleanup — ignore */
    }
    try {
      fs.rmSync(userDataDir, { recursive: true, force: true });
    } catch {
      /* ignore */
    }
  }
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch({
    channel: 'chrome',
    headless: true,
  });
  const context = await browser.newContext();
  const page = await context.newPage();

  console.log('Logging in…');
  await login(page);
  const cookies = await context.cookies();
  const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');
  const storage = path.join(ROOT, 'e2e', '.auth', 'user.json');
  fs.mkdirSync(path.dirname(storage), { recursive: true });
  await context.storageState({ path: storage });
  await context.close();

  const summary = [];
  const axeAgg = {};

  for (const spec of PAGES) {
    const url = `${BASE}${spec.hash}`;
    console.log(`\n=== ${spec.name} ===`);

    const ctx = await browser.newContext({ storageState: storage });
    const p = await ctx.newPage();
    await p.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await p.waitForTimeout(2500);
    try {
      await p.waitForFunction(() => document.querySelectorAll('.skeleton').length === 0, null, {
        timeout: 15_000,
      });
    } catch {
      /* ignore */
    }

    const axe = await runAxe(p, spec.name);
    console.log(`axe violations: ${axe.violations}`);
    for (const [id, info] of Object.entries(axe.byId)) {
      console.log(`  - ${id} (${info.impact}) x${info.nodes}: ${info.help}`);
      axeAgg[id] ??= { impact: info.impact, help: info.help, pages: [], totalNodes: 0 };
      axeAgg[id].pages.push(spec.name);
      axeAgg[id].totalNodes += info.nodes;
    }
    await ctx.close();

    let lh = {
      scores: { performance: null, accessibility: null, bestPractices: null, seo: null },
      failedAudits: [],
      raw: null,
    };
    if (!ONLY_AXE) {
      try {
        lh = await runLighthouse(url, spec.authed ? cookieHeader : undefined);
        console.log('Lighthouse:', lh.scores);
        fs.writeFileSync(
          path.join(OUT_DIR, `${spec.name}.lighthouse.json`),
          JSON.stringify(lh.raw, null, 2)
        );
      } catch (err) {
        console.warn(`Lighthouse failed for ${spec.name}: ${err.message}`);
      }
    }

    fs.writeFileSync(path.join(OUT_DIR, `${spec.name}.axe.json`), JSON.stringify(axe, null, 2));

    summary.push({
      page: spec.name,
      url,
      lighthouse: lh.scores,
      topFailed: lh.failedAudits.slice(0, 8),
      axeViolations: axe.violations,
      axe: axe.byId,
    });
  }

  await browser.close();

  const report = {
    generatedAt: new Date().toISOString(),
    base: BASE,
    axeOnly: ONLY_AXE,
    pages: summary,
    axeByRule: axeAgg,
  };
  fs.writeFileSync(path.join(OUT_DIR, 'summary.json'), JSON.stringify(report, null, 2));

  const lines = [
    '# Lighthouse / axe audit summary',
    '',
    `| Page | Perf | A11y | BP | SEO | axe |`,
    `|---|---:|---:|---:|---:|---:|`,
  ];
  for (const row of summary) {
    const s = row.lighthouse;
    lines.push(
      `| ${row.page} | ${s.performance ?? '—'} | ${s.accessibility ?? '—'} | ${s.bestPractices ?? '—'} | ${s.seo ?? '—'} | ${row.axeViolations} |`
    );
  }
  lines.push('', '## Top axe rules (across pages)', '');
  const sorted = Object.entries(axeAgg).sort((a, b) => b[1].totalNodes - a[1].totalNodes);
  for (const [id, info] of sorted) {
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
