import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const base = process.env.PREVIEW_URL || 'http://127.0.0.1:3010/';
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--enable-unsafe-swiftshader'] });
const output = new URL('../../storage/app/private/pages-review/', import.meta.url);
await mkdir(output, { recursive: true });
const errors = [];
const failed = [];
const network = [];
try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1000 });
    page.on('pageerror', error => errors.push(error.message));
    page.on('requestfailed', request => failed.push(`${request.url()}: ${request.failure()?.errorText}`));
    page.on('response', response => { if (response.status() >= 400) failed.push(`${response.url()}: HTTP ${response.status()}`); });
    page.on('request', request => { if (['xhr', 'fetch'].includes(request.resourceType()) || request.method() !== 'GET') network.push(request.url()); });
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    for (const path of ['vi-index.html', 'vi-jobs.html', 'vi-candidate-candidate.html', 'vi-candidate-candidate-profile.html', 'vi-employer-employer.html', 'vi-admin-admin.html', 'en-index.html', 'ja-index.html']) {
        console.log('Checking '+path);
        const response = await page.goto(base + path, { waitUntil: 'networkidle0' });
        assert.equal(response.status(), 200, path);
        assert.ok(await page.$('.preview-banner'), path);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${path}: horizontal overflow`);
        if (path === 'vi-index.html') {
            await page.waitForSelector('[data-sylva-host] iframe', { timeout: 20000 });
            const frame = page.frames().find(frame => frame.url() === 'about:srcdoc');
            assert.ok(frame, 'Living Green frame');
            await frame.waitForFunction(() => { const canvas = document.querySelector('canvas'); return canvas && canvas.width > 0 && canvas.height > 0; });
            await page.screenshot({ path: new URL('home.png', output).pathname.replace(/^\/(\w:)/, '$1'), fullPage: false });
        }
    }
    await page.goto(base + 'vi-candidate-candidate-profile.html', { waitUntil: 'networkidle0' });
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.select('select[data-vi]', 'en')]);
    assert.equal(await page.evaluate(() => document.documentElement.lang), 'en');
    await page.click('[data-theme-toggle]');
    const theme = await page.evaluate(() => document.documentElement.dataset.theme);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('.preview-banner a:last-child')]);
    assert.ok(page.url().endsWith('en-admin-admin.html'), page.url());
    assert.equal(await page.evaluate(() => document.documentElement.dataset.theme), theme);
    await page.goto(base + 'vi-login.html', { waitUntil: 'networkidle0' });
    assert.equal(await page.$eval('input[type=password]', input => input.disabled), true);
    await page.$eval('form[data-preview-form]:not(.language-switcher)', form => form.requestSubmit());
    assert.equal(await page.$eval('#preview-notice', node => node.hidden), false);
    for (const path of ['vi-index.html', 'vi-admin-admin.html']) {
        await page.setViewport({ width: 390, height: 844 });
        await page.goto(base + path, { waitUntil: 'networkidle0' });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${path}: mobile overflow`);
        await page.screenshot({ path: new URL(path.replace('.html', '-mobile.png'), output).pathname.replace(/^\/(\w:)/, '$1') });
    }
    assert.deepEqual(network, [], 'No API requests or submissions');
    assert.deepEqual(failed, [], 'No failed asset requests');
    assert.deepEqual(errors, [], 'No browser errors');
    console.log('Static preview browser checks passed: roles, languages, theme, animation, desktop/mobile, inert forms; no API calls.');
} catch (error) {
    console.error(JSON.stringify({errors, failed, network}));
    throw error;
} finally {
    await browser.close();
}
