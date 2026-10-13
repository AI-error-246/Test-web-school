import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const mode = process.argv[2];
assert.ok(['--local', '--supabase'].includes(mode), 'Choose --local or --supabase explicitly.');
const cloud = mode === '--supabase';
const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const app = root + 'PROJECT/04_Code/laravel/';
const env = Object.fromEntries((await readFile(app + '.env', 'utf8')).split(/\r?\n/).filter(line => /^[A-Z_]+=/.test(line)).map(line => [line.slice(0, line.indexOf('=')), line.slice(line.indexOf('=') + 1).replace(/^"|"$/g, '')]));
assert.equal(env.SUPABASE_DATA_ENABLED === 'true', cloud, 'Application destination differs from test mode.');
if (cloud) assert.equal(env.SUPABASE_CV_DISK, 'supabase');
const dataset = JSON.parse(await readFile(root + 'PROJECT/06_Testing/diverse-samples/dataset.json', 'utf8'));
const manifest = JSON.parse(await readFile(app + `storage/app/private/recruitment-samples-${cloud ? 'supabase' : 'local'}.json`, 'utf8'));
const credentials = cloud ? JSON.parse(await readFile(app + 'storage/app/private/sample-credentials.json', 'utf8')) : {};
const output = root + 'PROJECT/06_Testing/diverse-samples/';
await mkdir(output, { recursive: true });
const result = { status: 'running', mode: cloud ? 'supabase' : 'local', startedAt: new Date().toISOString(), people: [], applications: [], errors: [] };
const save = () => writeFile(output + `browser-${result.mode}.json`, JSON.stringify(result, null, 2));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--no-first-run'] });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 1000 });
page.on('pageerror', error => result.errors.push(error.message));
const base = 'http://127.0.0.1:3001';
const go = async path => { const r = await page.goto(base + path, { waitUntil: 'networkidle0' }); assert.equal(r.status(), 200, `${path}: ${r.status()}`); };
const submit = async selector => {
  const [r] = await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector + ' button:not([type]), ' + selector + ' button[type="submit"]')]);
  assert.equal(r.status(), 200, 'Form response must succeed.');
  assert.deepEqual(await page.$$eval('.alert-danger', els => els.map(el => el.textContent)), []);
};
const loginTimes = [];
const login = async key => {
  const user = manifest.users[key]; assert.ok(user);
  if (await page.$('form[action$="/logout"]')) await submit('form[action$="/logout"]');
  if (cloud) {
    // Respect the normal 8/minute login rate limit, without disabling middleware.
    if (loginTimes.length >= 7) {
      const wait = loginTimes.at(-7) + 62000 - Date.now();
      if (wait > 0) await new Promise(resolve => setTimeout(resolve, wait));
    }
    await go('/login');
    await page.type('[name="email"]', user.email);
    await page.type('[name="password"]', credentials[user.email].password);
    await submit('form[action$="/login"]'); loginTimes.push(Date.now());
  } else {
    await go('/');
    await page.select(`form[action$="/demo/${user.role}"] select[name="user_id"]`, user.id);
    await submit(`form[action$="/demo/${user.role}"]`);
  }
  assert.equal(new URL(page.url()).pathname, '/' + user.role);
};
const downloadHash = async cvId => page.evaluate(async id => {
  const r = await fetch('/cvs/' + id); if (r.status !== 200) return 'HTTP ' + r.status;
  const hash = await crypto.subtle.digest('SHA-256', await r.arrayBuffer());
  return Array.from(new Uint8Array(hash), value => value.toString(16).padStart(2, '0')).join('');
}, cvId);
try {
  await save(); await go('/'); await page.screenshot({ path: output + 'home-diverse.png', fullPage: true });
  for (const candidate of dataset.candidates) {
    await login(candidate.key);
    const cv = manifest.cvs[candidate.key];
    const hash = createHash('sha256').update(await readFile(root + candidate.pdf)).digest('hex');
    assert.equal(await downloadHash(cv.id), hash);
    for (const jobKey of candidate.preferred_job_keys) {
      const job = manifest.jobs[jobKey]; await go(`/jobs/${job.id}/apply`);
      if (!new URL(page.url()).pathname.startsWith('/applications/')) {
        await page.select('[name="cv_id"]', String(cv.id)); await page.click('[name="consent"]');
        await submit(`form[action$="/jobs/${job.id}/apply"]`);
      }
      const applicationId = new URL(page.url()).pathname.match(/applications\/(\d+)/)?.[1];
      assert.ok(applicationId); assert.equal(await downloadHash(cv.id), hash);
      result.applications.push({ candidateKey: candidate.key, name: candidate.name, applicationId, cvId: cv.id, jobId: job.id, jobKey, employerId: job.employer_id, expectedHash: hash });
    }
    result.people.push({ key: candidate.key, name: candidate.name, id: manifest.users[candidate.key].id, cvId: cv.id, disk: cv.disk });
    await page.screenshot({ path: output + `candidate-${candidate.key}.png`, fullPage: true });
    await save(); console.log(`Verified candidate ${candidate.name}`);
  }
  for (const company of dataset.companies) {
    await login(company.key);
    const owned = result.applications.filter(application => application.employerId === manifest.users[company.key].id);
    for (const application of owned) {
      await go('/applications/' + application.applicationId);
      assert.equal(await downloadHash(application.cvId), application.expectedHash);
      const index = result.applications.indexOf(application);
      const target = ['SUBMITTED', 'REVIEWING', 'SELECTED', 'REJECTED'][index % 4];
      const select = await page.$('select[name="status"]');
      if (target !== 'SUBMITTED' && select) {
        const values = await page.$$eval('select[name="status"] option', els => els.map(el => el.value));
        if (values.includes('REVIEWING')) {
          await page.select('select[name="status"]', 'REVIEWING');
          await submit(`form[action$="/applications/${application.applicationId}"]`);
        }
        if (['SELECTED', 'REJECTED'].includes(target) && await page.$('select[name="status"]')) {
          await page.select('select[name="status"]', target);
          await submit(`form[action$="/applications/${application.applicationId}"]`);
        }
      }
      application.testStatus = target; application.employerHashMatches = true;
    }
    await go('/employer/jobs'); await page.screenshot({ path: output + `employer-${company.key}.png`, fullPage: true }); await save();
  }
  assert.equal(result.people.length, 8); assert.equal(result.applications.length, 13); assert.deepEqual(result.errors, []);
  result.status = 'passed'; result.completedAt = new Date().toISOString(); await save();
  console.log(JSON.stringify({ status: result.status, mode: result.mode, people: result.people.length, applications: result.applications.length }));
} catch (error) {
  result.status = 'failed'; result.failure = error.message; await save();
  await page.screenshot({ path: output + 'failure.png', fullPage: true });
  console.error(JSON.stringify({ status: 'failed', message: error.message, url: page.url() })); process.exitCode = 1;
} finally { await browser.close(); }
