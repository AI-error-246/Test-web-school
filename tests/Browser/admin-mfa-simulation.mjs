import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const output = fileURLToPath(new URL('../../../../06_Testing/admin-mfa/', import.meta.url));
await mkdir(output, { recursive: true });
const result = { at: new Date().toISOString(), mode: 'SIMULATION_ONLY', checks: [], errors: [] };
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: false, defaultViewport: { width: 1280, height: 900 }, args: ['--no-first-run', '--window-size=1400,1000'] });
const page = await browser.newPage();
page.on('pageerror', e => result.errors.push(e.name));
const go = path => page.goto('http://127.0.0.1:3002' + path, { waitUntil: 'networkidle0' });
const submit = selector => Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector)]);
const login = async () => {
    await go('/admin/login');
    await page.type('[name=email]', 'admin@simulation.test');
    await page.type('[name=password]', 'Simulation-only-2026');
    await submit('form[action$="/admin/login"] button[type=submit]');
};
try {
    await login();
    assert.equal(new URL(page.url()).pathname, '/admin/mfa');
    assert.ok((await page.$eval('body', e => e.innerText)).includes('MÔ PHỎNG CỤC BỘ'));
    await go('/admin/users');
    assert.equal(new URL(page.url()).pathname, '/admin/mfa');
    result.checks.push('Password alone cannot access admin users');
    if (await page.$('form[action$="/mfa/enroll"]')) {
        await submit('form[action$="/mfa/enroll"] button');
        assert.ok(await page.$('img[alt="Mã QR thiết lập ứng dụng xác thực"]'));
        result.checks.push('Enrollment uses provider image and manual-key disclosure');
    }
    await page.type('[name=code]', '000000');
    await submit('form[action$="/mfa/verify"] button');
    assert.equal(new URL(page.url()).pathname, '/admin/mfa');
    assert.equal(await page.$eval('[name=code]', e => e.value), '');
    assert.ok((await page.$eval('main', e => e.innerText)).includes('Mã không hợp lệ'));
    await page.screenshot({ path: output + 'mfa-desktop.png', fullPage: true });
    result.checks.push('Incorrect OTP refused and not flashed back');
    await page.setViewport({ width: 390, height: 844 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.screenshot({ path: output + 'mfa-mobile.png', fullPage: true });
    const response = await go('/admin/mfa');
    assert.equal(response.headers()['referrer-policy'], 'no-referrer');
    result.checks.push('Mobile 390px no overflow and no-referrer');
    await page.setViewport({ width: 1280, height: 900 });
    await page.type('[name=code]', '123456');
    await submit('form[action$="/mfa/verify"] button');
    assert.equal(new URL(page.url()).pathname, '/admin/users');
    await page.screenshot({ path: output + 'admin-verified.png', fullPage: true });
    result.checks.push('Valid mocked AAL2 restores requested admin destination');
    await submit('form[action$="/logout"] button');
    await login();
    assert.equal(new URL(page.url()).pathname, '/admin/mfa');
    assert.equal(await page.$('form[action$="/mfa/enroll"]'), null);
    assert.ok(await page.$('[name=code]'));
    result.checks.push('Next login requires existing factor, no reenrollment');
    assert.deepEqual(result.errors, []);
    result.status = 'PASS';
} catch (error) {
    result.status = 'FAIL';
    result.error = error.message;
    process.exitCode = 1;
} finally {
    await writeFile(output + 'browser.json', JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result));
    await browser.close();
}
