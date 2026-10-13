import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

// Explicit --run required: creates a labelled saved filter and saved job for the synthetic candidate.
if (!process.argv.includes('--run')) throw new Error('Use --run to exercise the synthetic accounts on the running website.');
const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const credentials = JSON.parse(await readFile(root + 'PROJECT/04_Code/laravel/storage/app/private/cloud-transfer-smoke-input.json', 'utf8'));
const output = root + 'PROJECT/06_Testing/abc-features/';
await mkdir(output, { recursive: true });
const result = { status: 'running', startedAt: new Date().toISOString(), checks: [], errors: [], screenshots: [] };
const save = () => writeFile(output + 'browser.json', JSON.stringify(result, null, 2));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--no-first-run'] });
const page = await browser.newPage();
page.on('pageerror', error => result.errors.push(error.name));
const go = async path => {
    const response = await page.goto('http://127.0.0.1:3001' + path, { waitUntil: 'networkidle0', timeout: 60000 });
    assert.equal(response.status(), 200, 'Page returned an unexpected status');
};
const submit = async selector => {
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 }), page.click(selector + ' button[type="submit"], ' + selector + ' button:not([type])')]);
    assert.equal(await page.$('.alert-danger'), null, 'Form validation or service failure');
};
const capture = async (name, width = 1440) => {
    await page.setViewport({ width, height: 1000 });
    await page.evaluate(() => scrollTo(0, 0));
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Horizontal page overflow');
    await page.screenshot({ path: output + name + '.png', fullPage: true });
    result.screenshots.push(name + '.png');
};
const login = async role => {
    await page.setViewport({ width: 1440, height: 1000 });
    await go('/login');
    await page.type('[name="email"]', credentials[role].email);
    await page.type('[name="password"]', credentials[role].password);
    await submit('form[action$="/login"]');
    assert.equal(new URL(page.url()).pathname, '/' + role, 'Real sign-in did not reach workspace');
};
try {
    await login('candidate');
    assert.ok(await page.$('progress[aria-label="Mức hoàn thiện hồ sơ"]'));
    await capture('candidate-dashboard');
    await go('/jobs');
    const detailPath = await page.$eval('a[href*="/jobs/"]', el => new URL(el.href).pathname);
    result.jobId = Number(detailPath.split('/').pop());
    await go(detailPath);
    const saveForm = 'form[action$="/candidate/saved-jobs/' + result.jobId + '"]';
    if (!await page.$(saveForm + ' input[name="_method"]')) await submit(saveForm);
    await go('/candidate/saved-jobs');
    assert.ok(await page.$('a[href$="' + detailPath + '"]'));
    await capture('saved-jobs');
    await capture('saved-jobs-mobile', 390);
    await go('/candidate/job-history');
    assert.ok(await page.$('a[href$="' + detailPath + '"]'));
    result.checks.push('A: real candidate login, profile completion, save job and automatic view history');
    await page.setViewport({ width: 1440, height: 1000 });
    await go('/candidate/saved-searches');
    const filterName = 'Kiểm thử A/B/C - Laravel';
    if (!await page.evaluate(name => document.body.innerText.includes(name), filterName)) {
        await go('/jobs?q=Laravel&sort=newest');
        await page.click('details summary');
        await page.type('form[action$="/candidate/saved-searches"] [name="name"]', filterName);
        await submit('form[action$="/candidate/saved-searches"]');
    }
    await go('/candidate/saved-searches');
    assert.ok(await page.evaluate(name => document.body.innerText.includes(name), filterName));
    await capture('saved-searches');
    await capture('saved-searches-mobile', 390);
    await go('/candidate/notifications');
    await capture('notifications-mobile', 390);
    result.checks.push('B: saved filter persists and notifications screen renders on mobile');
    await page.setViewport({ width: 1440, height: 1000 });
    await submit('form[action$="/logout"]');
    await login('employer');
    await capture('employer-dashboard');
    await capture('employer-dashboard-mobile', 390);
    await page.setViewport({ width: 1440, height: 1000 });
    await go('/employer/jobs');
    const applicationsPath = await page.$eval('a[href$="/applications"]', el => new URL(el.href).pathname);
    await go(applicationsPath);
    assert.ok(await page.$('[name="q"]'));
    await capture('employer-applications');
    await capture('employer-applications-mobile', 390);
    const applicationLink = await page.$('a[href*="/applications/"]');
    if (applicationLink) {
        const path = await applicationLink.evaluate(el => new URL(el.href).pathname);
        await go(path);
        assert.ok(await page.$('form[action$="/note"] textarea[name="body"]'));
        await capture('employer-private-note-mobile', 390);
    }
    result.checks.push('C: employer statistics, candidate filters and private-note interface render');
    assert.deepEqual(result.errors, []);
    result.status = 'passed';
} catch (error) {
    result.status = 'failed';
    result.failure = error.name + ': ' + (error.message.includes('Form validation') ? 'Form validation or service failure' : error.message.slice(0, 180));
    process.exitCode = 1;
} finally {
    result.completedAt = new Date().toISOString();
    await save();
    await browser.close();
    console.log(JSON.stringify(result));
}
