import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const output = root + 'PROJECT/06_Testing/password-recovery/';
const credentials = JSON.parse(await readFile(root + 'PROJECT/04_Code/laravel/storage/app/private/cloud-transfer-smoke-input.json', 'utf8'));
await mkdir(output, { recursive: true });
const result = { at: new Date().toISOString(), status: 'RUNNING', checks: [], errors: [], emailDelivery: 'NOT_TESTED: requires a user-controlled real mailbox and Supabase email/redirect settings' };
const visible = process.env.VISIBLE_DEMO === '1';
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: !visible, defaultViewport: { width: 1280, height: 900 }, args: ['--no-first-run', '--window-size=1400,1000'] });
const page = await browser.newPage();
page.on('pageerror', error => result.errors.push(error.name));
const go = async (path, expectedStatus = 200) => {
    const response = await page.goto('http://127.0.0.1:3001' + path, { waitUntil: 'networkidle0', timeout: 60000 });
    assert.equal(response.status(), expectedStatus);
    return response;
};
const submit = async selector => {
    const [response] = await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 }), page.click(selector)]);
    assert.equal(response.status(), 200);
};
try {
    await go('/login');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('a[href$="/forgot-password"]')]);
    assert.equal(new URL(page.url()).pathname, '/forgot-password');
    await page.screenshot({ path: output + 'forgot-desktop.png', fullPage: true });
    result.checks.push('Login link opens recovery form');
    await page.click('form[action$="/forgot-password"] button');
    assert.equal(await page.$eval('#recovery-email', element => element.validity.valueMissing), true);
    const csrfResponse = await fetch('http://127.0.0.1:3001/forgot-password', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Sec-Fetch-Site': 'cross-site' }, body: JSON.stringify({ email: 'probe@example.test' }) });
    assert.equal(csrfResponse.status, 419);
    result.checks.push('Required email and cross-site request without CSRF token denied');
    await page.type('#recovery-email', 'recovery-probe-20261007@example.test');
    await submit('form[action$="/forgot-password"] button');
    assert.ok(await page.$eval('main', element => element.innerText.includes('Nếu email có tài khoản hợp lệ')));
    result.checks.push('Synthetic unknown address gets generic result; delivery not claimed');
    await go('/password/callback');
    assert.equal(new URL(page.url()).pathname, '/forgot-password');
    assert.ok(await page.$eval('main', element => element.innerText.includes('Liên kết không hợp lệ')));
    await go('/reset-password');
    assert.equal(new URL(page.url()).pathname, '/forgot-password');
    result.checks.push('Missing callback and direct reset denied without recovery grant');
    const recoveryResponse = await go('/forgot-password');
    assert.equal(recoveryResponse.headers()['referrer-policy'], 'no-referrer');
    await page.setViewport({ width: 390, height: 844 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.screenshot({ path: output + 'forgot-mobile.png', fullPage: true });
    result.checks.push('390px mobile layout and no-referrer');
    await page.setViewport({ width: 1280, height: 900 });
    await go('/admin/login');
    assert.ok(await page.$('a[href$="/forgot-password"]'));
    await go('/login');
    await page.type('[name="email"]', credentials.candidate.email);
    await page.type('[name="password"]', credentials.candidate.password);
    await submit('form[action$="/login"] button[type="submit"]');
    assert.equal(new URL(page.url()).pathname, '/candidate');
    await submit('form[action$="/logout"] button');
    await go('/candidate');
    assert.equal(new URL(page.url()).pathname, '/login');
    result.checks.push('Existing real Supabase login/logout still works with auth_version');
    await go('/forgot-password');
    assert.deepEqual(result.errors, []);
    result.status = 'PASS_UI_AND_REGRESSION';
} catch (error) {
    result.status = 'FAIL';
    result.error = error.name + ': ' + error.message;
    process.exitCode = 1;
} finally {
    await writeFile(output + 'browser.json', JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result));
    if (visible && result.status !== 'FAIL') {
        await page.bringToFront();
        browser.disconnect();
    } else {
        await browser.close();
    }
}
