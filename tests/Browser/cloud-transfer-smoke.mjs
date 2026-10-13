import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

// Explicitly authorized cloud acceptance: preserve transferred records and add one synthetic CV/application.
assert.equal(process.argv[2], '--supabase');
const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const app = root + 'PROJECT/04_Code/laravel/';
const envText = await readFile(app + '.env', 'utf8');
assert.match(envText, /^SUPABASE_DATA_ENABLED=true$/m);
assert.match(envText, /^SUPABASE_CV_DISK=supabase$/m);
assert.match(envText, /^DEMO_MODE=false$/m);
const input = JSON.parse(await readFile(app + 'storage/app/private/cloud-transfer-smoke-input.json', 'utf8'));
const output = root + 'PROJECT/06_Testing/cloud-transfer/';
await mkdir(output, { recursive: true });
const result = { status: 'running', startedAt: new Date().toISOString(), auth: [], checks: [], errors: [] };
const save = () => writeFile(output + 'browser.json', JSON.stringify(result, null, 2));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--no-first-run'] });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 1000 });
page.on('pageerror', error => result.errors.push(error.name));
const base = 'http://127.0.0.1:3001';
const go = async path => {
  const r = await page.goto(base + path, { waitUntil: 'networkidle0', timeout: 60000 });
  assert.equal(r.status(), 200, `${path}: HTTP ${r.status()}`);
};
const submit = async selector => {
  const [r] = await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 }), page.click(selector + ' button:not([type]), ' + selector + ' button[type="submit"]')]);
  assert.equal(r.status(), 200);
  assert.equal(await page.$('.alert-danger'), null, 'Form has an error');
};
const login = async user => {
  if (await page.$('form[action$="/logout"]')) await submit('form[action$="/logout"]');
  await go('/login');
  await page.type('[name="email"]', user.email);
  await page.type('[name="password"]', user.password);
  await submit('form[action$="/login"]');
  assert.equal(new URL(page.url()).pathname, '/' + user.role);
  result.auth.push({ id: user.id, role: user.role, passed: true });
  await save();
};
const hash = async cvId => page.evaluate(async id => {
  const r = await fetch('/cvs/' + id);
  const bytes = await r.arrayBuffer();
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return { status: r.status, type: r.headers.get('content-type'), hash: Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('') };
}, cvId);
try {
  await save(); await go('/');
  assert.equal(await page.$('form[action*="/demo/"]'), null);
  await page.screenshot({ path: output + 'home-cloud.png', fullPage: true });
  const pdf = root + 'output/pdf/diverse/minh-khoi.pdf';
  const expectedHash = createHash('sha256').update(await readFile(pdf)).digest('hex');
  await login(input.candidate);
  await go('/candidate/cvs');
  assert.ok(await page.$('a[href$="/cvs/9"]'));
  const old = await hash(9); assert.equal(old.status, 200); assert.equal(old.hash, expectedHash);
  result.checks.push('Transferred CV can be downloaded after real Supabase sign-in');
  const testName = 'Kiểm thử Supabase - Nguyễn Minh Khôi';
  let newCvId = await page.$$eval('article.panel', (cards, name) => {
    const card = cards.find(el => el.querySelector('h2')?.textContent === name);
    return card?.querySelector('a[href*="/cvs/"]')?.href.match(/cvs\/(\d+)/)?.[1];
  }, testName);
  if (!newCvId) {
    await page.type('[name="display_name"]', testName);
    await (await page.$('[name="cv"]')).uploadFile(pdf);
    await submit('form[action$="/candidate/cvs"]');
    newCvId = await page.$$eval('article.panel', (cards, name) => cards.find(el => el.querySelector('h2')?.textContent === name)?.querySelector('a[href*="/cvs/"]')?.href.match(/cvs\/(\d+)/)?.[1], testName);
  }
  assert.ok(newCvId);
  const uploaded = await hash(newCvId); assert.equal(uploaded.status, 200); assert.equal(uploaded.hash, expectedHash);
  result.cv = { id: Number(newCvId), sha256: expectedHash, candidate_id: input.candidate.id };
  await save();
  await go('/jobs/14/apply');
  if (!new URL(page.url()).pathname.startsWith('/applications/')) {
    await page.select('[name="cv_id"]', String(newCvId));
    await page.click('[name="consent"]');
    await submit('form[action$="/jobs/14/apply"]');
  }
  const applicationId = new URL(page.url()).pathname.match(/applications\/(\d+)/)?.[1];
  assert.ok(applicationId);
  result.application = { id: Number(applicationId), job_id: 14, cv_id: Number(newCvId), candidate_id: input.candidate.id };
  await page.screenshot({ path: output + 'candidate-application-cloud.png', fullPage: true }); await save();
  await login(input.otherCandidate);
  const denied = await hash(newCvId); assert.equal(denied.status, 404);
  result.checks.push('Another candidate cannot read the CV (404)');
  await login(input.employer);
  await go('/applications/' + applicationId);
  const employerPdf = await hash(newCvId); assert.equal(employerPdf.status, 200); assert.equal(employerPdf.hash, expectedHash);
  const review = await page.$('select[name="status"]');
  if (review) {
    const values = await page.$$eval('select[name="status"] option', els => els.map(el => el.value));
    if (values.includes('REVIEWING')) {
      await page.select('select[name="status"]', 'REVIEWING');
      await submit(`form[action$="/applications/${applicationId}"]`);
    }
  }
  result.checks.push('Owning employer reads matching PDF and updates application');
  await page.screenshot({ path: output + 'employer-application-cloud.png', fullPage: true });
  await login(input.admin);
  await go('/admin/users');
  await page.screenshot({ path: output + 'admin-users-cloud.png', fullPage: true });
  const adminDenied = await hash(newCvId); assert.equal(adminDenied.status, 404);
  result.checks.push('Admin sees users but cannot read private CV');
  assert.deepEqual(result.errors, []);
  result.status = 'passed'; result.completedAt = new Date().toISOString(); await save();
  console.log(JSON.stringify({ status: result.status, realSignIns: result.auth.length, cvId: result.cv.id, applicationId: result.application.id }));
} catch (error) {
  result.status = 'failed'; result.failure = error.message; await save();
  await page.screenshot({ path: output + 'failure.png', fullPage: true });
  console.error(JSON.stringify({ status: 'failed', message: error.message, path: new URL(page.url()).pathname }));
  process.exitCode = 1;
} finally { await browser.close(); }
