import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const output = fileURLToPath(new URL('../../../../06_Testing/multilingual-assistant/', import.meta.url));
await mkdir(output, { recursive: true });
const report = { at: new Date().toISOString(), mode: 'REAL_INTERNAL_ASSISTANT_ISOLATED_DATABASE', checks: [], errors: [] };
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: false, defaultViewport: { width: 1440, height: 950 }, args: ['--no-first-run', '--window-size=1480,1000'] });
const page = await browser.newPage();
await page.evaluateOnNewDocument(() => {
    window.uiMotionEvents = [];
    document.addEventListener('animationstart', event => window.uiMotionEvents.push(event.animationName));
});
page.on('pageerror', error => report.errors.push(error.name + ': ' + error.message));
let assistantRequests = 0;
page.on('request', req => { if (new URL(req.url()).pathname === '/assistant') assistantRequests++; });
const go = path => page.goto('http://127.0.0.1:3002' + path, { waitUntil: 'networkidle0' });
const submit = selector => Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector)]);
const language = async locale => {
    assert.equal(await page.$eval('.language-switcher button', el => getComputedStyle(el).display), 'none');
    if (await page.$eval('html', el => el.lang) !== locale) {
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.select('#site-language', locale),
        ]);
    }
    assert.equal(await page.$eval('html', el => el.lang), locale);
};
const fits = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Page overflow');
try {
    await go('/');
    for (const [locale, text] of [['en', 'Small first steps.'], ['ja', '小さな一歩。'], ['vi', 'Bước đầu nhỏ.']]) {
        await language(locale);
        assert.ok((await page.$eval('h1', el => el.textContent)).includes(text));
        await fits();
        await page.screenshot({ path: output + 'home-' + locale + '.png', fullPage: true });
    }
    assert.equal(assistantRequests, 0, 'Page loads must not call AI');
    report.checks.push('Desktop VI/EN/JA switch, persistence, original page layout; zero AI requests during load');
    assert.ok(await page.evaluate(() => window.uiMotionEvents.includes('bridge-arrive')));
    await page.waitForFunction(() => !document.getAnimations().some(animation => ['bridge-arrive', 'detail-arrive', 'note-arrive'].includes(animation.animationName) && animation.playState === 'running'));
    report.checks.push('Decorative hero sequence starts and finishes without an idle animation loop');

    await language('ja');
    await go('/login');
    await page.click('[data-password-toggle]');
    assert.equal(await page.$eval('#login-password', el => el.type), 'text');
    assert.equal(await page.$eval('[data-password-toggle]', el => el.textContent), '非表示');
    await page.click('[data-password-toggle]');
    assert.equal(await page.$eval('#login-password', el => el.type), 'password');
    report.checks.push('Japanese password toggle and language maintained across navigation');

    await go('/');
    await page.click('.support-launcher');
    assert.equal(await page.$eval('#support-question', el => el === document.activeElement), true);
    const faqResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/assistant');
    await page.click('[data-support-question]');
    const faq = await (await faqResponse).json();
    assert.equal(faq.source, 'local');
    assert.equal(faq.locale, 'ja');
    assert.equal(faq.topic, 'apply');
    await page.waitForFunction(() => !document.querySelector('.support-form').hasAttribute('aria-busy'));
    assert.ok((await page.$eval('.support-messages', el => el.textContent)).includes(faq.answer));
    assert.equal(await page.$eval('.support-privacy', el => el.textContent.includes('OpenAI')), false);
    await page.waitForFunction(() => !document.querySelector('.support-message.is-new'));
    assert.ok(await page.evaluate(() => window.uiMotionEvents.includes('message-arrive')));
    await page.screenshot({ path: output + 'assistant-ja.png' });
    report.checks.push('Japanese quick question receives real local FAQ with finite new-message animation; no provider configuration');

    const ask = async question => {
        await page.type('#support-question', question);
        const pending = page.waitForResponse(response => new URL(response.url()).pathname === '/assistant');
        await page.click('.support-form [type=submit]');
        const result = await (await pending).json();
        await page.waitForFunction(() => !document.querySelector('.support-form').hasAttribute('aria-busy'));
        return result;
    };
    const unrelated = await ask('What is the weather today?');
    assert.equal(unrelated.topic, 'scope');
    assert.deepEqual(unrelated.jobs, []);
    const jobs = await ask('Tìm thực tập thiết kế');
    assert.equal(jobs.source, 'local');
    assert.ok(jobs.jobs.length > 0, 'Public simulated design job should be found');
    assert.ok(await page.$('.support-job[href^="http://127.0.0.1:3002/jobs/"]'));
    report.checks.push('Unrelated weather question receives scope guidance; internship search reads real public records in isolated database');

    await page.keyboard.press('Escape');
    assert.equal(await page.$eval('.support-panel', el => el.hidden), true);
    assert.equal(await page.$eval('.support-launcher', el => el === document.activeElement), true);
    await page.setViewport({ width: 390, height: 844 });
    await fits();
    await page.click('.nav-toggle');
    assert.equal(await page.$eval('.nav-toggle', el => el.getAttribute('aria-expanded')), 'true');
    await page.click('.nav-toggle');
    await page.click('.support-launcher');
    assert.equal(await page.$$eval('.support-message.is-new', elements => elements.length), 0, 'Old messages must not replay entry animation');
    await page.waitForFunction(() => document.querySelector('.support-panel').getAnimations().every(animation => animation.playState !== 'running'));
    const bounds = await page.$eval('.support-panel', el => { const r = el.getBoundingClientRect(); return {left:r.left, right:r.right, top:r.top, bottom:r.bottom}; });
    assert.ok(bounds.left >= 0 && bounds.right <= 390 && bounds.top >= 0 && bounds.bottom <= 844);
    await page.screenshot({ path: output + 'assistant-mobile-ja.png' });
    report.checks.push('Mobile Japanese header/menu/panel fit 390px; Escape restores focus');

    await page.click('[data-support-clear]');
    await page.setRequestInterception(true);
    let mode = 'xss';
    let captured;
    const intercept = async req => {
        if (new URL(req.url()).pathname !== '/assistant') return req.continue();
        captured = JSON.parse(req.postData());
        if (mode === 'xss') return req.respond({ status:200, contentType:'application/json', body: JSON.stringify({answer:'<script>window.botXss=1</script>', jobs:[{id:1,title:'<img src=x onerror=window.botXss=2>',company:'Demo only',location:'Demo',work_mode:'REMOTE',url:'/jobs/1'},{id:2,title:'Untrusted link',url:'https://evil.invalid'}],source:'local',locale:'ja'}) });
        if (mode === 'error') return req.respond({ status:503, contentType:'application/json', body: JSON.stringify({message:'SIMULATED SERVICE UNAVAILABLE'}) });
        return req.continue();
    };
    page.on('request', intercept);
    await page.type('#support-question', 'Test render only');
    await page.click('.support-form [type=submit]');
    await page.waitForFunction(() => document.querySelector('.support-messages').textContent.includes('<script>'));
    assert.equal(await page.evaluate(() => window.botXss), undefined);
    assert.equal(await page.$$eval('.support-messages script,.support-messages img', els => els.length), 0);
    assert.equal(await page.$$eval('.support-job', els => els.length), 1);
    assert.deepEqual(Object.keys(captured), ['message']);
    report.checks.push('Response/job text escaped; external job link rejected; only current question is sent (browser response fixture)');
    mode = 'error';
    await page.type('#support-question', 'Failure test');
    await page.click('.support-form [type=submit]');
    await page.waitForFunction(() => document.querySelector('.support-messages').textContent.includes('SIMULATED SERVICE UNAVAILABLE'));
    assert.equal(await page.$eval('.support-form [type=submit]', el => el.disabled), false);
    report.checks.push('Service error shown and question can be retried');
    page.off('request', intercept);
    await page.setRequestInterception(false);
    await page.click('[data-support-close]');

    await page.setViewport({width:1440,height:950});
    await language('en');
    await go('/login');
    await page.type('[name=email]', 'candidate@simulation.test');
    await page.type('[name=password]', 'Simulation-only-2026');
    await submit('form[action$="/login"] button[type=submit]');
    assert.ok(new URL(page.url()).pathname.startsWith('/candidate'), 'Simulation login failed: ' + await page.$eval('main', el => el.textContent.trim().slice(0, 600)));
    await go('/candidate/profile');
    assert.ok((await page.$eval('main', el => el.textContent)).includes('Full name'));
    await page.screenshot({path:output+'candidate-en.png',fullPage:true});
    await page.click('.account-trigger');
    await submit('form[action$="/logout"] button');
    await go('/login');
    assert.equal(await page.$eval('html', el => el.lang), 'en');
    report.checks.push('Candidate workspace translated; preference survives sign-out');
    assert.deepEqual(report.errors, []);
    report.ok = true;
    await go('/');
    await language('vi');
} catch (error) {
    report.ok = false;
    report.failure = error.message;
    report.stack = error.stack;
    await page.screenshot({path:output+'failure.png',fullPage:true}).catch(() => {});
    process.exitCode = 1;
} finally {
    await writeFile(output + 'browser.json', JSON.stringify(report,null,2));
    console.log(JSON.stringify(report));
    await browser.close();
}
