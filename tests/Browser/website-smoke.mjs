import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

// Explicitly local: exercises real forms and retains the resulting demo workflow.
const base = 'http://127.0.0.1:3001';
const output = fileURLToPath(new URL('../../../../06_Testing/browser/', import.meta.url));
await mkdir(output, { recursive: true });
const browser = await puppeteer.launch({
  executablePath: process.env.BROWSER_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  headless: true,
  args: ['--no-first-run', '--disable-features=msEdgeSidebarV2'],
});
const page = await browser.newPage();
const errors = [];
const steps = [];
page.on('pageerror', error => errors.push(error.message));
page.on('dialog', dialog => dialog.accept());
const goto = async path => {
  const response = await page.goto(base + path, { waitUntil: 'networkidle0' });
  assert.equal(response.status(), 200, `${path} HTTP ${response.status()}`);
};
const submit = async selector => {
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector + ' button[type="submit"], ' + selector + ' button:not([type])')]);
  const problems = await page.$$eval('.alert-danger', els => els.map(el => el.textContent));
  assert.deepEqual(problems, [], `Form validation: ${problems}`);
};
const role = async value => {
  if (await page.$('form[action$="/logout"]')) await submit('form[action$="/logout"]');
  await goto('/');
  await submit(`form[action$="/demo/${value}"]`);
  assert.equal(new URL(page.url()).pathname, '/' + value);
};
const fill = async (name, value) => {
  await page.$eval(`[name="${name}"]`, (el, value) => { el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); }, value);
};
const snapshot = async name => {
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Horizontal overflow ${name}`);
  await page.screenshot({ path: output + name + '.png', fullPage: true });
};
try {
  await page.setViewport({ width: 1440, height: 1000 });
  await goto('/'); await snapshot('01-home-desktop');
  await goto('/jobs'); assert.ok(await page.$('.job-card')); await snapshot('02-search-desktop');
  await page.setViewport({ width: 390, height: 844 });
  await goto('/'); await page.click('.nav-toggle');
  assert.equal(await page.$eval('.nav-toggle', el => el.getAttribute('aria-expanded')), 'true');
  await snapshot('03-home-mobile');
  await goto('/jobs'); await snapshot('04-search-mobile');
  steps.push('Public pages, desktop/mobile layout and mobile navigation');
  await page.setViewport({ width: 1440, height: 1000 });
  await role('employer'); await goto('/employer/company'); await snapshot('05-company');
  await goto('/employer/jobs/create');
  const title = `Thực tập Laravel · kiểm thử ${new Date().toISOString().replace(/[:.]/g, '-')}`;
  await fill('title', title);
  for (const name of ['category_id', 'location_id']) {
    const value = await page.$eval(`[name="${name}"] option:nth-child(2)`, el => el.value);
    await page.select(`[name="${name}"]`, value);
  }
  await page.select('[name="work_mode"]', 'HYBRID');
  await fill('deadline_at', new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 16));
  await fill('description', 'Tham gia phát triển website tuyển dụng cùng nhóm kỹ thuật.');
  await fill('requirements', 'Sinh viên có kiến thức PHP, Laravel và cơ sở dữ liệu quan hệ.');
  await fill('benefits', 'Có người hướng dẫn, thực hành dự án và hỗ trợ xác nhận thực tập.');
  await fill('allowance_min', '3000000'); await fill('allowance_max', '5000000');
  await submit('form[action$="/employer/jobs"]');
  const jobId = new URL(page.url()).pathname.match(/jobs\/(\d+)\/edit/)?.[1];
  assert.ok(jobId, page.url());
  await submit(`form[action$="/jobs/${jobId}/submit"]`);
  await snapshot('06-employer-submitted');
  steps.push('Employer creates complete draft and submits for moderation');
  await role('admin'); await goto(`/admin/jobs/${jobId}`);
  await page.select('[name="action"]', 'approve'); await fill('reason', 'Dữ liệu kiểm thử cục bộ: thông tin đầy đủ, duyệt để kiểm tra luồng ứng tuyển.');
  await submit(`form[action$="/jobs/${jobId}/moderate"]`);
  await snapshot('07-admin-approved');
  steps.push('Admin approves the exact job version and records audit history');
  await role('candidate'); await goto('/candidate/profile'); await snapshot('08-candidate-profile');
  await goto('/candidate/cvs'); await snapshot('09-candidate-cvs');
  await goto(`/jobs?q=${encodeURIComponent(title)}`);
  assert.equal(await page.$$eval('.job-card', els => els.length), 1);
  await goto(`/jobs/${jobId}`); await snapshot('10-job-detail');
  await goto(`/jobs/${jobId}/apply`);
  const cv = await page.$eval('[name="cv_id"] option:nth-child(2)', el => el.value);
  await page.select('[name="cv_id"]', cv); await page.click('[name="consent"]');
  await submit(`form[action$="/jobs/${jobId}/apply"]`);
  const applicationId = new URL(page.url()).pathname.match(/applications\/(\d+)/)?.[1];
  assert.ok(applicationId, page.url()); await snapshot('11-application-submitted');
  const pdf = await page.evaluate(async () => {
    const url = document.querySelector('a[href*="/cvs/"]').href;
    const response = await fetch(url); return { status: response.status, type: response.headers.get('content-type'), header: (await response.text()).slice(0, 5) };
  });
  assert.equal(pdf.status, 200); assert.ok(pdf.type.includes('application/pdf')); assert.equal(pdf.header, '%PDF-');
  steps.push('Candidate finds approved job, submits with private CV and sees immutable application');
  await role('employer'); await goto(`/employer/jobs/${jobId}/applications`);
  await goto(`/applications/${applicationId}`);
  await page.select('[name="status"]', 'REVIEWING'); await submit(`form[action$="/applications/${applicationId}"]`);
  await page.select('[name="status"]', 'SELECTED'); await submit(`form[action$="/applications/${applicationId}"]`);
  assert.equal(await page.$('select[name="status"]'), null); await snapshot('12-employer-selected');
  steps.push('Employer transitions SUBMITTED → REVIEWING → SELECTED with history');
  await role('candidate'); await goto(`/applications/${applicationId}`);
  assert.ok((await page.$eval('.timeline', el => el.textContent)).includes('Được lựa chọn'));
  await page.setViewport({ width: 390, height: 844 }); await snapshot('13-application-mobile');
  await page.setViewport({ width: 1440, height: 1000 });
  await role('admin'); await goto('/admin'); await snapshot('14-admin-dashboard');
  await goto('/admin/statistics'); await snapshot('15-admin-statistics');
  await goto('/admin/reports'); await snapshot('16-admin-reports');
  steps.push('Candidate sees final state; Admin dashboard, statistics and reports render');
  assert.deepEqual(errors, [], 'Browser JavaScript errors');
  await writeFile(output + 'result.json', JSON.stringify({ status: 'passed', date: new Date().toISOString(), jobId, applicationId, steps, browserErrors: errors }, null, 2));
  console.log(JSON.stringify({ status: 'passed', jobId, applicationId, steps, screenshots: output }));
} catch (error) {
  await page.screenshot({ path: output + 'failure.png', fullPage: true });
  console.error(JSON.stringify({ status: 'failed', url: page.url(), message: error.message, steps, browserErrors: errors }));
  process.exitCode = 1;
} finally { await browser.close(); }
