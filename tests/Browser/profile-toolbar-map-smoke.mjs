import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const output = fileURLToPath(new URL('../../../../06_Testing/profile-toolbar-map/', import.meta.url));
await mkdir(output, { recursive: true });
const base = 'http://127.0.0.1:3002';
const report = { at: new Date().toISOString(), mode: 'ISOLATED_SIMULATION', checks: [], errors: [], measurements: {} };
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: false, defaultViewport: { width: 1440, height: 1000 }, args: ['--no-first-run', '--window-size=1480,1050'] });
const page = await browser.newPage();
page.on('pageerror', error => report.errors.push(error.message));
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const go = async path => {
    const response = await page.goto(base + path, { waitUntil: 'networkidle0' });
    assert.equal(response.status(), 200);
};
const click = selector => Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector)]);
const language = async locale => {
    if (await page.$eval('html', el => el.lang) !== locale) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.select('#site-language', locale)]);
    }
};
try {
    await go('/login');
    await language('vi');
    await page.type('[name="email"]', 'candidate@simulation.test');
    await page.type('[name="password"]', 'Simulation-only-2026');
    await click('button[type="submit"].btn-primary');
    await page.click('.account-trigger');
    await click('.account-panel a[href$="/candidate/profile/view"]');
    assert.ok(await page.$('.profile-overview'));
    await click('.profile-overview a[href$="/candidate/profile"]');
    const photoPath = output + 'avatar-fixture.png';
    await writeFile(photoPath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64'));
    await page.$eval('[name="full_name"]', el => { el.value = 'Ứng viên kiểm thử hồ sơ'; });
    await page.$eval('[name="university"]', el => { el.value = 'Đại học mô phỏng'; });
    await page.$eval('[name="introduction"]', el => { el.value = 'Hồ sơ thử nghiệm, không phải thông tin cá nhân thật.\nQuan tâm thiết kế sản phẩm.'; });
    await page.$eval('[name="remove_avatar"]', el => { el.checked = false; }).catch(() => {});
    await (await page.$('[name="avatar"]')).uploadFile(photoPath);
    await click('.profile-actions button');
    assert.equal(await page.$('.alert-danger'), null);
    await go('/candidate/profile/view');
    assert.equal(await page.$eval('.profile-heading h2', el => el.textContent), 'Ứng viên kiểm thử hồ sơ');
    await page.waitForFunction(() => document.querySelector('.profile-avatar')?.naturalWidth > 0);
    const avatar = await page.evaluate(async () => {
        const response = await fetch('/candidate/profile/avatar');
        return { status: response.status, type: response.headers.get('content-type'), cache: response.headers.get('cache-control') };
    });
    assert.equal(avatar.status, 200);
    assert.equal(avatar.type, 'image/png');
    assert.match(avatar.cache, /no-store/);
    await page.screenshot({ path: output + 'profile-desktop.png', fullPage: true });
    report.checks.push('Own profile save and preview, multipart photo upload, private image retrieval and persistence through navigation');

    for (const locale of ['en', 'ja', 'vi']) {
        await language(locale);
        assert.equal(await page.$eval('html', el => el.lang), locale);
        assert.ok((await page.$eval('.page-header h1', el => el.textContent)).length > 3);
        await page.click('.account-trigger');
        assert.equal(await page.$eval('.account-trigger', el => el.getAttribute('aria-expanded')), 'true');
        await page.keyboard.press('Escape');
        assert.equal(await page.$eval('.account-dropdown', el => el.open), false);
        assert.equal(await page.$eval('.account-trigger', el => el === document.activeElement), true);
    }
    report.checks.push('VI/EN/JA immediate language selection, account menu Escape closes and restores keyboard focus');

    // Backend transition/isolation is covered by NotificationFeedTest on PostgreSQL.
    // These explicit UI fixtures exercise client rendering and mark-read interactions.
    let unread = true;
    let sessionExpired = false;
    let feedRequests = 0;
    const fixtureBody = 'Bạn được chọn vào vị trí Thiết kế sản phẩm tại Studio mô phỏng.';
    await page.setRequestInterception(true);
    page.on('request', request => {
        const path = new URL(request.url()).pathname;
        if (path === '/candidate/notifications/feed') {
            feedRequests++;
            if (sessionExpired) return request.respond({ status: 302, headers: { location: '/jobs' } });
            return request.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ unread_count: unread ? 1 : 0, items: [{ id: 900001, title: 'Hồ sơ của bạn đã được tuyển chọn!', body: fixtureBody + ' <img src=x onerror="window.injected=true">', status: 'SELECTED', unread, changed_at: '2026-10-14T09:00:00Z', url: '/applications/900001', read_url: '/candidate/notifications/900001/read' }] }) });
        }
        if (path === '/candidate/notifications/900001/read') {
            assert.equal(request.method(), 'POST');
            assert.ok(request.headers()['x-csrf-token']);
            if (sessionExpired) return request.respond({ status: 302, headers: { location: '/jobs' } });
            unread = false;
            return request.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ unread_count: 0 }) });
        }
        return request.continue();
    });
    await page.reload({ waitUntil: 'networkidle0' });
    await page.click('.notification-trigger');
    await page.waitForSelector('.notification-item.is-selected');
    assert.ok((await page.$eval('.notification-item-body', el => el.textContent)).includes(fixtureBody));
    assert.equal(await page.$('.notification-item img'), null);
    assert.equal(await page.evaluate(() => window.injected), undefined);
    assert.equal(await page.$eval('[data-notification-count]', el => el.textContent), '1');
    await page.screenshot({ path: output + 'selected-notification.png' });
    await page.click('.notification-read');
    await page.waitForFunction(() => document.querySelector('[data-notification-count]').hidden);
    assert.equal(await page.$('.notification-read'), null);
    await page.click('.account-trigger');
    await pause(50);
    assert.equal(await page.$eval('.notification-dropdown', el => el.open), false);
    await page.keyboard.press('Escape');
    const beforeIdle = feedRequests;
    await pause(2200);
    assert.equal(feedRequests, beforeIdle);
    report.checks.push('Simulated selected notification badge/list, safe text rendering, CSRF mark-read without reload, mutual disclosure closing, no rapid polling');

    await page.setViewport({ width: 390, height: 844 });
    await page.click('.notification-trigger');
    await pause(200);
    const mobile = await page.evaluate(() => {
        const bounds = document.querySelector('.notification-panel').getBoundingClientRect();
        return { left: bounds.left, right: bounds.right, overflow: document.documentElement.scrollWidth > innerWidth, header: document.querySelector('.site-header').getBoundingClientRect().height, profileHeading: document.querySelector('.profile-heading').getBoundingClientRect().width };
    });
    assert.ok(mobile.left >= 0 && mobile.right <= 390);
    assert.equal(mobile.overflow, false);
    assert.ok(mobile.header <= 80);
    assert.ok(mobile.profileHeading >= 220, 'Profile text must remain readable instead of one-character columns');
    report.measurements.mobile = mobile;
    await page.screenshot({ path: output + 'toolbar-mobile.png', fullPage: true });
    await page.keyboard.press('Escape');
    await page.click('.nav-toggle');
    assert.equal(await page.$eval('.nav-toggle', el => el.getAttribute('aria-expanded')), 'true');
    await page.keyboard.press('Escape');
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await page.hover('.site-header .brand');
    assert.equal(await page.$eval('.brand-traveler', el => getComputedStyle(el).animationName), 'none');
    report.checks.push('390px compact toolbar/dropdown fit, working mobile navigation, reduced-motion disables animated brand');
    unread = true;
    await page.reload({ waitUntil: 'networkidle0' });
    await page.click('.notification-trigger');
    await page.waitForSelector('.notification-read');
    assert.equal(await page.$eval('[data-notification-announcement]', el => el.closest('details')), null);
    sessionExpired = true;
    await page.click('.notification-read');
    await page.waitForFunction(() => document.querySelectorAll('.notification-item').length === 0);
    assert.equal(await page.$eval('[data-notification-count]', el => el.hidden), true);
    assert.equal(await page.$eval('[data-notification-retry]', el => el.hidden), true);
    await page.reload({ waitUntil: 'networkidle0' });
    await page.click('.notification-trigger');
    await page.waitForFunction(() => !document.querySelector('[data-notification-feedback]').hidden);
    await pause(1400);
    assert.equal(await page.$('.notification-item'), null);
    assert.equal(await page.$eval('[data-notification-retry]', el => el.hidden), true);
    sessionExpired = false;
    report.checks.push('Expired session redirects clear private dropdown data and stop retries; live announcement exists outside collapsed disclosure');
    await page.emulateMediaFeatures([]);
    await page.setViewport({ width: 1440, height: 1000 });
    await go('/candidate/profile');
    await page.click('[name="remove_avatar"]');
    await click('.profile-actions button');
    await go('/candidate/profile/view');
    assert.equal(await page.$('.profile-avatar'), null);
    assert.equal(await page.evaluate(async () => (await fetch('/candidate/profile/avatar')).status), 404);
    report.checks.push('Remove profile photo persists and private image endpoint returns 404');

    await page.click('.account-trigger');
    await click('form[action$="/logout"] button');
    await go('/jobs');
    const jobLink = await page.$eval('a[href*="/jobs/"]', el => el.getAttribute('href'));
    let mapsRequests = 0;
    page.on('request', request => { if (/https:\/\/[^/]*google\./.test(request.url())) mapsRequests++; });
    await page.goto(jobLink, { waitUntil: 'networkidle0' });
    assert.ok(await page.$('[data-company-map]'));
    assert.equal(await page.$('[data-company-map] iframe'), null);
    assert.equal(mapsRequests, 0);
    const expectedMap = await page.$eval('[data-company-map]', el => el.dataset.mapSrc);
    const mapResponse = page.waitForResponse(response => response.url() === expectedMap, { timeout: 20000 }).catch(() => null);
    await page.click('[data-map-load]');
    await page.waitForSelector('[data-company-map] iframe');
    const response = await mapResponse;
    report.measurements.mapHTTP = response?.status() ?? 'not observed; external network unavailable';
    assert.ok(mapsRequests >= 1);
    await page.$eval('[data-map-load]', el => el.click());
    assert.equal((await page.$$('[data-company-map] iframe')).length, 1);
    assert.equal(await page.$eval('[data-map-external]', el => new URL(el.href).hostname), 'www.google.com');
    await page.$eval('[data-company-map]', el => el.scrollIntoView({ block: 'center' }));
    await pause(1500);
    await page.screenshot({ path: output + 'company-map.png' });
    report.checks.push('Company map makes zero Google requests before click, loads one real Google iframe on demand, retains official external link');
    await page.setJavaScriptEnabled(false);
    await page.reload({ waitUntil: 'networkidle0' });
    assert.equal(await page.$('[data-company-map] iframe'), null);
    assert.ok(await page.$('[data-map-external]'));
    assert.notEqual(await page.$eval('.nav-links', el => getComputedStyle(el).display), 'none');
    report.checks.push('No-JavaScript fallback retains navigation and external map link');
    assert.deepEqual(report.errors, []);
} catch (error) {
    report.failure = error.stack;
    await page.screenshot({ path: output + 'failure.png', fullPage: true }).catch(() => {});
    throw error;
} finally {
    await writeFile(output + 'browser.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
    await browser.close();
}
