import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src', 'app', 'pages');
const results = { ok: [], warn: [], fail: [] };

function walk(dir, files = []) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name);
    if (ent.isDirectory()) walk(p, files);
    else if (ent.name.endsWith('.component.html')) files.push(p);
  }
  return files;
}

function componentTs(htmlPath) {
  return htmlPath.replace('.html', '.ts');
}

for (const html of walk(root)) {
  const tsPath = componentTs(html);
  const rel = path.relative(path.join(root, '..'), html).replace(/\\/g, '/');
  if (!fs.existsSync(tsPath)) {
    results.fail.push({ page: rel, issue: 'Missing component TS file' });
    continue;
  }
  const htmlContent = fs.readFileSync(html, 'utf8');
  const tsContent = fs.readFileSync(tsPath, 'utf8');
  const clicks = [...htmlContent.matchAll(/\(click\)="([^"]+)"/g)].map(m => m[1].split('(')[0].trim());
  const submits = [...htmlContent.matchAll(/\(ngSubmit\)="([^"]+)"/g)].map(m => m[1].split('(')[0].trim());
  const handlers = [...new Set([...clicks, ...submits])];

  for (const h of handlers) {
    if (h.startsWith('$event') || h.includes('.')) continue;
    const methodRe = new RegExp(`\\b${h}\\s*\\(`);
    if (!methodRe.test(tsContent)) {
      results.fail.push({ page: rel, issue: `Handler "${h}" not found in TS` });
    } else {
      results.ok.push({ page: rel, handler: h });
    }
  }

  const deadLinks = [...htmlContent.matchAll(/routerLink="([^"]+)"/g)].map(m => m[1]);
  for (const link of deadLinks) {
    if (link.includes('{{')) continue;
    results.ok.push({ page: rel, route: link });
  }
}

console.log(JSON.stringify({
  summary: {
    pages: walk(root).length,
    handlers_ok: results.ok.filter(x => x.handler).length,
    handlers_fail: results.fail.length,
    routes: results.ok.filter(x => x.route).length,
  },
  failures: results.fail,
}, null, 2));
