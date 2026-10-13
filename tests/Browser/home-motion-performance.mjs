import assert from 'node:assert/strict';
import { writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const output = root + 'PROJECT/06_Testing/home-motion-performance/';
await mkdir(output, { recursive: true });
const result = { status: 'running', startedAt: new Date().toISOString(), checks: [], errors: [], timings: {} };
const save = () => writeFile(output + 'browser.json', JSON.stringify(result, null, 2));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--no-first-run'] });
const page = await browser.newPage();
page.on('pageerror', error => result.errors.push(error.name));
const go = async path => {
    const response = await page.goto('http://127.0.0.1:3001' + path, { waitUntil: 'load', timeout: 60000 });
    assert.equal(response.status(), 200);
    return page.evaluate(() => {
        const entry = performance.getEntriesByType('navigation')[0];
        return { ttfbMs: Math.round(entry.responseStart - entry.startTime), loadMs: Math.round(entry.loadEventEnd || entry.domContentLoadedEventEnd) };
    });
};
const scroll = async y => {
    await page.evaluate(value => window.scrollTo({ top: value, behavior: 'instant' }), y);
    await new Promise(resolve => setTimeout(resolve, 120));
};
const offset = selector => page.$eval(selector, el => parseFloat(el.style.getPropertyValue('--scroll-y')) || 0);
try {
    await page.setViewport({ width: 1440, height: 1000 });
    result.timings.home = [];
    result.timings.jobs = [];
    for (const path of ['/', '/jobs']) {
        for (let i = 0; i < 3; i++) result.timings[path === '/' ? 'home' : 'jobs'].push(await go(path));
    }
    await go('/');
    await new Promise(resolve => setTimeout(resolve, 850));
    const original = await offset('.career-board');
    await scroll(360);
    const downward = await offset('.career-board');
    assert.ok(downward < original - 3, 'Hero must move with downward scroll');
    await page.screenshot({ path: output + 'hero-scroll-desktop.png' });
    await scroll(0);
    assert.ok(Math.abs(await offset('.career-board') - original) < 0.1, 'Hero returns on upward scroll');
    result.checks.push('Hero reverses with scroll direction');
    const processY = await page.$eval('.process-section', el => el.getBoundingClientRect().top + scrollY);
    await scroll(processY - 900);
    const stepBefore = await offset('.process-step');
    await scroll(processY - 220);
    const stepAfter = await offset('.process-step');
    assert.ok(stepAfter < stepBefore, 'Process steps move as the section crosses viewport');
    await new Promise(resolve => setTimeout(resolve, 750));
    await page.screenshot({ path: output + 'process-scroll-desktop.png' });
    await scroll(processY - 900);
    assert.ok(Math.abs(await offset('.process-step') - stepBefore) < 0.1, 'Process returns on upward scroll');
    result.checks.push('Three steps reverse with scroll direction');
    const stableOffset = await offset('.process-step');
    await new Promise(resolve => setTimeout(resolve, 250));
    assert.equal(await offset('.process-step'), stableOffset);
    result.checks.push('Offsets stay stable when not scrolling');
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await scroll(360);
    assert.equal(await offset('.career-board'), 0);
    assert.equal(await page.$eval('.process-step', el => getComputedStyle(el).translate), 'none');
    result.checks.push('Dynamic reduced motion resets offsets and stops transforms');
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'no-preference' }]);
    await page.setViewport({ width: 390, height: 844 });
    await scroll(400);
    assert.ok(Math.abs(await offset('.career-board')) <= 14.4);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await new Promise(resolve => setTimeout(resolve, 750));
    await page.screenshot({ path: output + 'hero-scroll-mobile.png' });
    await scroll(processY);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    result.checks.push('Mobile motion stays bounded with no page overflow');
    await page.setJavaScriptEnabled(false);
    await go('/');
    assert.equal(await page.$eval('.hero-copy', el => getComputedStyle(el).opacity), '1');
    assert.ok(await page.$eval('.nav-links', el => el.getBoundingClientRect().height > 0));
    result.checks.push('Content and navigation remain available without JavaScript');
    assert.deepEqual(result.errors, []);
    result.status = 'passed';
    result.completedAt = new Date().toISOString();
    await save();
    console.log(JSON.stringify(result));
} catch (error) {
    result.status = 'failed'; result.failure = error.message; await save();
    console.error(error.message); process.exitCode = 1;
} finally {
    await browser.close();
}
