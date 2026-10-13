import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

// Visual acceptance on the running site: authentication and reads only.
const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const input = JSON.parse(await readFile(root + 'PROJECT/04_Code/laravel/storage/app/private/cloud-transfer-smoke-input.json', 'utf8'));
const output = root + 'PROJECT/06_Testing/ui-refresh/';
await mkdir(output, { recursive: true });
const result = { status: 'running', startedAt: new Date().toISOString(), checks: [], screenshots: [], errors: [] };
const save = () => writeFile(output + 'browser.json', JSON.stringify(result, null, 2));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--no-first-run'] });
const page = await browser.newPage();
page.on('pageerror', error => result.errors.push(error.name));
const base = 'http://127.0.0.1:3001';
const go = async (path, status = 200) => {
    const response = await page.goto(base + path, { waitUntil: 'networkidle0', timeout: 60000 });
    assert.equal(response.status(), status, `${path}: HTTP ${response.status()}`);
};
const capture = async (name, width) => {
    await page.setViewport({ width, height: 1000 });
    await page.evaluate(() => window.scrollTo(0, 0));
    await new Promise(resolve => setTimeout(resolve, 800));
    const dimensions = await page.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth }));
    assert.ok(dimensions.content <= dimensions.viewport + 1, `${name}: horizontal overflow ${JSON.stringify(dimensions)}`);
    await page.screenshot({ path: output + name + '.png', fullPage: true });
    result.screenshots.push(name + '.png');
    await save();
};
const submit = async selector => {
    const [response] = await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 }),
        page.click(selector + ' button[type="submit"], ' + selector + ' button:not([type])'),
    ]);
    assert.equal(response.status(), 200);
    assert.equal(await page.$('.alert-danger'), null, 'Form error');
};
const logout = async () => {
    await page.setViewport({ width: 1440, height: 1000 });
    await submit('form[action$="/logout"]');
};
const login = async (role, path = '/login') => {
    await page.setViewport({ width: 1440, height: 1000 });
    await go(path);
    await page.type('[name="email"]', input[role].email);
    await page.type('[name="password"]', input[role].password);
    await submit(`form[action$="${path}"]`);
    assert.equal(new URL(page.url()).pathname, '/' + role);
    result.checks.push(`${role}: real Supabase sign-in`);
};
try {
    await page.setViewport({ width: 1440, height: 1000 });
    await go('/');
    await capture('home-desktop', 1440);
    await capture('home-tablet', 768);
    await capture('home-mobile', 390);
    await page.click('.nav-toggle');
    assert.equal(await page.$eval('.nav-toggle', el => el.getAttribute('aria-expanded')), 'true');
    await page.keyboard.press('Escape');
    assert.equal(await page.$eval('.nav-toggle', el => el.getAttribute('aria-expanded')), 'false');
    assert.equal(await page.$eval('.nav-toggle', el => el === document.activeElement), true);
    result.checks.push('Mobile menu opens; Escape closes and restores focus');
    await page.click('.nav-toggle');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#primary-navigation a[href$="/jobs"]')]);
    assert.equal(new URL(page.url()).pathname, '/jobs');
    await capture('jobs-mobile', 390);
    await capture('jobs-desktop', 1440);
    const jobPath = await page.$eval('.job-card h3 a', el => new URL(el.href).pathname);
    await go(jobPath);
    await capture('job-mobile', 360);
    await go('/register');
    await capture('register-mobile', 390);
    await go('/login');
    await capture('login-desktop', 1440);
    await page.click('[data-password-toggle]');
    assert.equal(await page.$eval('[name="password"]', el => el.type), 'text');
    await page.click('[data-password-toggle]');
    assert.equal(await page.$eval('[name="password"]', el => el.type), 'password');
    result.checks.push('Password visibility toggle works');
    await go('/admin');
    assert.equal(new URL(page.url()).pathname, '/admin/login');
    await capture('admin-login-desktop', 1440);
    await capture('admin-login-mobile', 390);
    result.checks.push('Guest admin access requires dedicated sign-in');
    await login('candidate');
    await capture('candidate-desktop', 1440);
    await capture('candidate-mobile', 390);
    await go('/candidate/cvs');
    await capture('candidate-cvs-mobile', 390);
    await go('/admin', 403);
    result.checks.push('Candidate cannot access admin (403)');
    await go('/candidate');
    await logout();
    await login('employer');
    await capture('employer-desktop', 1440);
    await go('/employer/jobs');
    await capture('employer-jobs-mobile', 390);
    await logout();
    await login('admin', '/admin/login');
    await capture('admin-desktop', 1440);
    await capture('admin-mobile', 390);
    await go('/admin/users');
    await capture('admin-users-mobile', 390);
    await go('/admin/statistics');
    await capture('admin-statistics-desktop', 1440);
    await logout();
    await go('/admin');
    assert.equal(new URL(page.url()).pathname, '/admin/login');
    result.checks.push('Logout removes admin access');
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await go('/');
    const motion = await page.$eval('.landing-hero', el => ({ duration: getComputedStyle(el).animationDuration, opacity: getComputedStyle(el).opacity }));
    assert.ok(parseFloat(motion.duration) <= 0.01);
    assert.equal(motion.opacity, '1');
    result.checks.push('Reduced-motion mode keeps content visible and disables entrance animation');
    await page.setJavaScriptEnabled(false);
    await go('/');
    await capture('home-no-javascript-mobile', 390);
    assert.ok(await page.$eval('.nav-links', el => el.getBoundingClientRect().height > 0));
    result.checks.push('Navigation and content remain available without JavaScript');
    assert.deepEqual(result.errors, []);
    result.status = 'passed';
    result.completedAt = new Date().toISOString();
    await save();
    console.log(JSON.stringify({ status: result.status, checks: result.checks.length, screenshots: result.screenshots.length }));
} catch (error) {
    result.status = 'failed';
    result.failure = error.message;
    await save();
    console.error(error.message);
    process.exitCode = 1;
} finally {
    await browser.close();
}
