import assert from 'node:assert/strict';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

// User-authorized local demo: six PDFs, six new jobs and applications, no remote writes.
const base = 'http://127.0.0.1:3001';
const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const output = root + 'PROJECT/06_Testing/cv-samples/';
await mkdir(output, { recursive: true });
const run = new Date().toISOString().replace(/[:.]/g, '-');
const samples = [
  ['01-cv-tong-quan.pdf', 'CV tổng quan', 'Thực tập sinh công nghệ thông tin'],
  ['02-cv-backend-laravel.pdf', 'CV Backend Laravel', 'Thực tập sinh Backend Laravel'],
  ['03-cv-frontend.pdf', 'CV Frontend', 'Thực tập sinh Frontend'],
  ['04-cv-qa.pdf', 'CV QA', 'Thực tập sinh Kiểm thử phần mềm'],
  ['05-cv-data-analyst.pdf', 'CV Data Analyst', 'Thực tập sinh Phân tích dữ liệu'],
  ['06-cv-ui-ux.pdf', 'CV UI/UX', 'Thực tập sinh Thiết kế UI/UX'],
].map(([filename, name, position]) => ({ filename, name, position }));
for (const sample of samples) {
  const bytes = await readFile(root + 'output/pdf/' + sample.filename);
  assert.equal(bytes.subarray(0, 5).toString(), '%PDF-');
  assert.ok(bytes.length < 5 * 1024 * 1024);
  sample.sha256 = createHash('sha256').update(bytes).digest('hex');
  sample.sizeBytes = bytes.length;
}
const result = { status: 'running', startedAt: new Date().toISOString(), destination: 'Local PostgreSQL internship_dev + private local files', supabaseWrites: false, archivedCvIds: [], samples, browserErrors: [] };
const save = () => writeFile(output + 'result.json', JSON.stringify(result, null, 2));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--no-first-run'] });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 1050 });
page.on('dialog', dialog => dialog.accept());
page.on('pageerror', error => result.browserErrors.push(error.message));
const go = async path => {
  const response = await page.goto(base + path, { waitUntil: 'networkidle0' });
  assert.equal(response.status(), 200, `${path}: HTTP ${response.status()}`);
};
const fill = async (name, value) => page.$eval(`[name="${name}"]`, (el, value) => {
  el.value = value; el.dispatchEvent(new Event('input', { bubbles: true }));
}, value);
const submit = async (selector, expectError = false) => {
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector + ' button:not([type]), ' + selector + ' button[type="submit"]')]);
  const errors = await page.$$eval('.alert-danger', els => els.map(el => el.textContent.trim()));
  if (!expectError) assert.deepEqual(errors, [], 'Unexpected validation error');
  return errors;
};
const role = async name => {
  if (await page.$('form[action$="/logout"]')) await submit('form[action$="/logout"]');
  await go('/'); await submit(`form[action$="/demo/${name}"]`);
  assert.equal(new URL(page.url()).pathname, '/' + name);
};
const pdfHash = async cvId => {
  const response = await page.evaluate(async id => {
    const r = await fetch('/cvs/' + id);
    const bytes = await r.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return { status: r.status, type: r.headers.get('content-type'), hash: Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('') };
  }, cvId);
  assert.equal(response.status, 200); assert.ok(response.type.includes('application/pdf'));
  return response.hash;
};
try {
  await save();
  await role('employer');
  for (const sample of samples) {
    await go('/employer/jobs/create');
    await fill('title', `${sample.position} [CV mẫu ${run}]`);
    for (const name of ['category_id', 'location_id']) {
      const value = await page.$eval(`[name="${name}"] option:nth-child(2)`, el => el.value);
      await page.select(`[name="${name}"]`, value);
    }
    await page.select('[name="work_mode"]', 'HYBRID');
    await fill('deadline_at', new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 16));
    await fill('description', 'Tin mẫu cục bộ để kiểm thử tải PDF và liên kết hồ sơ ứng tuyển. Không phải đợt tuyển dụng thật.');
    await fill('requirements', 'Hồ sơ và CV đúng vị trí thực tập; dữ liệu sử dụng trong bài kiểm thử là giả lập.');
    await fill('benefits', 'Thực hành quy trình ứng tuyển và kiểm tra nhà tuyển dụng nhận đúng tệp CV.');
    await submit('form[action$="/employer/jobs"]');
    sample.jobId = new URL(page.url()).pathname.match(/jobs\/(\d+)\/edit/)?.[1];
    assert.ok(sample.jobId);
    await submit(`form[action$="/jobs/${sample.jobId}/submit"]`);
    await save();
  }
  console.log('Six local demo jobs submitted for review.');
  await role('admin');
  for (const sample of samples) {
    await go('/admin/jobs/' + sample.jobId);
    await page.select('[name="action"]', 'approve');
    await fill('reason', 'Duyệt dữ liệu mẫu cục bộ để kiểm thử sáu CV PDF, không phải tuyển dụng thật.');
    await submit(`form[action$="/jobs/${sample.jobId}/moderate"]`);
  }
  await role('candidate'); await go('/candidate/cvs');
  // Archive only the known synthetic seed CV to reserve five active slots.
  const seedArchive = 'form[action$="/candidate/cvs/1/archive"]';
  if (await page.$(seedArchive)) {
    await submit(seedArchive); result.archivedCvIds.push('1');
  }
  for (const [index, sample] of samples.entries()) {
    await go('/candidate/cvs');
    await fill('display_name', sample.name + ' - mẫu kiểm thử');
    await (await page.$('input[type="file"][name="cv"]')).uploadFile(root + 'output/pdf/' + sample.filename);
    await submit('form[action$="/candidate/cvs"]');
    sample.cvId = await page.$$eval('article.panel', (els, label) => {
      const card = els.find(el => el.querySelector('h2')?.textContent === label);
      return card?.querySelector('a[href*="/cvs/"]')?.getAttribute('href').match(/cvs\/(\d+)/)?.[1];
    }, sample.name + ' - mẫu kiểm thử');
    assert.ok(sample.cvId);
    assert.equal(await pdfHash(sample.cvId), sample.sha256);
    await go(`/jobs/${sample.jobId}/apply`);
    await page.select('[name="cv_id"]', sample.cvId); await page.click('[name="consent"]');
    await submit(`form[action$="/jobs/${sample.jobId}/apply"]`);
    sample.applicationId = new URL(page.url()).pathname.match(/applications\/(\d+)/)?.[1];
    assert.ok(sample.applicationId);
    await page.screenshot({ path: output + `${index + 1}-application.png`, fullPage: true });
    sample.candidateDownloadMatches = true;
    if (index === 0) {
      await go('/candidate/cvs');
      await submit(`form[action$="/candidate/cvs/${sample.cvId}/archive"]`);
      result.archivedCvIds.push(sample.cvId);
      assert.equal(await pdfHash(sample.cvId), sample.sha256);
      result.archivedApplicationCvRetained = true;
    }
    await save(); console.log(`Applied ${sample.filename}: cv=${sample.cvId}, application=${sample.applicationId}`);
  }
  await go('/candidate/cvs');
  await page.screenshot({ path: output + 'candidate-cv-library.png', fullPage: true });
  await fill('display_name', 'Quota sixth active CV - expected rejection');
  await (await page.$('input[name="cv"]')).uploadFile(root + 'output/pdf/' + samples[0].filename);
  const errors = await submit('form[action$="/candidate/cvs"]', true);
  assert.ok(errors.some(message => message.includes('5 CV')));
  result.sixthActiveCvRejected = true;
  await role('employer');
  for (const sample of samples) {
    await go('/applications/' + sample.applicationId);
    assert.equal(await page.$eval('a[href*="/cvs/"]', el => el.getAttribute('href').match(/cvs\/(\d+)/)?.[1]), sample.cvId);
    assert.equal(await pdfHash(sample.cvId), sample.sha256);
    sample.employerDownloadMatches = true;
  }
  await page.screenshot({ path: output + 'employer-application.png', fullPage: true });
  const guest = await browser.createBrowserContext();
  const guestPage = await guest.newPage();
  await guestPage.goto(base + '/cvs/' + samples[1].cvId, { waitUntil: 'networkidle0' });
  assert.equal(new URL(guestPage.url()).pathname, '/login');
  result.guestCvDenied = true; await guest.close();
  assert.deepEqual(result.browserErrors, []);
  result.status = 'passed'; result.completedAt = new Date().toISOString();
  await save(); console.log(JSON.stringify(result));
} catch (error) {
  result.status = 'failed'; result.error = error.message; result.lastUrl = page.url();
  await save(); await page.screenshot({ path: output + 'failure.png', fullPage: true });
  console.error(JSON.stringify({ status: 'failed', message: error.message, url: page.url() }));
  process.exitCode = 1;
} finally { await browser.close(); }
