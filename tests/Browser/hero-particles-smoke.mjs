import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const output = root + 'PROJECT/06_Testing/hero-particles/';
await mkdir(output, { recursive: true });
const result = { status: 'running', checks: [], errors: [], startedAt: new Date().toISOString() };
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--no-first-run'] });
const page = await browser.newPage();
page.on('pageerror', error => result.errors.push(error.message));
await page.evaluateOnNewDocument(() => {
    window.particlePaints = 0;
    const clear = CanvasRenderingContext2D.prototype.clearRect;
    CanvasRenderingContext2D.prototype.clearRect = function (...args) {
        if (this.canvas.matches('.hero-particles')) window.particlePaints++;
        return clear.apply(this, args);
    };
});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const paints = () => page.evaluate(() => window.particlePaints);
const fingerprint = () => page.$eval('.hero-particles', canvas => {
    const bytes = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    let hash = 2166136261;
    let painted = 0;
    for (let i = 3; i < bytes.length; i += 4) {
        if (bytes[i]) painted++;
        hash = Math.imul(hash ^ bytes[i], 16777619);
    }
    return { hash, painted };
});
try {
    await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
    const response = await page.goto('http://127.0.0.1:3001/', { waitUntil: 'networkidle0' });
    assert.equal(response.status(), 200);
    await pause(900);
    const base = await fingerprint();
    assert.ok(base.painted > 100, 'Particle field must be visible');
    assert.equal(await page.$eval('.hero-particles', el => getComputedStyle(el).pointerEvents), 'none');
    await page.screenshot({ path: output + 'hero-desktop.png' });
    const bounds = await page.$eval('.landing-hero', el => { const r = el.getBoundingClientRect(); return { x: r.left, y: r.top, width: r.width, height: r.height }; });
    await page.mouse.move(bounds.x + bounds.width * .46, bounds.y + 85, { steps: 12 });
    await pause(300);
    assert.notEqual((await fingerprint()).hash, base.hash, 'Nearby particles must react to cursor');
    await page.screenshot({ path: output + 'hero-pointer.png' });
    result.checks.push('Particles react to mouse; decorative canvas does not intercept controls');
    await page.mouse.move(20, 20);
    await pause(2600);
    const restingPaints = await paints();
    await pause(350);
    assert.equal(await paints(), restingPaints, 'Idle particles must stop rendering');
    result.checks.push('Rendering stops after pointer leaves and particles settle');
    await page.mouse.move(bounds.x + bounds.width * .48, bounds.y + 125);
    await page.evaluate(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' }));
    await pause(400);
    const offscreenPaints = await paints();
    await pause(350);
    assert.equal(await paints(), offscreenPaints, 'Offscreen particles must stop rendering');
    result.checks.push('Rendering pauses outside viewport');
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await pause(350);
    const still = await fingerprint();
    await page.mouse.move(bounds.x + 180, bounds.y + 95, { steps: 10 });
    await pause(400);
    assert.equal((await fingerprint()).hash, still.hash, 'Reduced motion keeps particles static');
    result.checks.push('Reduced motion switches to static particles');
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'no-preference' }]);
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    await page.reload({ waitUntil: 'networkidle0' });
    await pause(900);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    const touchPaints = await paints();
    await pause(350);
    assert.equal(await paints(), touchPaints, 'Touch mode must not run continuous animation');
    await page.screenshot({ path: output + 'hero-mobile.png' });
    result.checks.push('Mobile shows static field without page overflow or continuous rendering');
    await page.type('#hero-query', 'Laravel');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('.hero-search button')]);
    assert.equal(new URL(page.url()).pathname, '/jobs');
    assert.equal(new URL(page.url()).searchParams.get('q'), 'Laravel');
    assert.equal(await page.$('.hero-particles'), null);
    assert.equal(await page.$('script[src*="hero-particles.js"]'), null);
    result.checks.push('Search still works; particle script loads only on homepage');
    assert.deepEqual(result.errors, []);
    result.status = 'passed'; result.completedAt = new Date().toISOString();
    console.log(JSON.stringify({ status: result.status, checks: result.checks.length }));
} catch (error) {
    result.status = 'failed'; result.failure = error.message;
    console.error(error.message); process.exitCode = 1;
} finally {
    await writeFile(output + 'browser.json', JSON.stringify(result, null, 2));
    await browser.close();
}
