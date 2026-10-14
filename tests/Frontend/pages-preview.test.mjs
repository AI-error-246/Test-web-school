import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir, stat, lstat } from 'node:fs/promises';
import { resolve, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../storage/app/pages/', import.meta.url));
const files = await readdir(root, { recursive: true });
const pages = files.filter(file => file.endsWith('.html'));

test('Preview exports all three locales and four public role entry points', async () => {
    assert.ok(pages.length > 100);
    for (const locale of ['vi', 'en', 'ja']) {
        for (const suffix of ['index', 'candidate-candidate', 'employer-employer', 'admin-admin']) {
            assert.ok(files.includes(`${locale}-${suffix}.html`));
        }
    }
});

test('Artifact contains static allowlisted files only, with no symlinks or runtime data', async () => {
    for (const file of files) {
        const info = await lstat(resolve(root, file));
        assert.equal(info.isSymbolicLink(), false, file);
        if (info.isDirectory()) continue;
        assert.ok(['.html', '.css', '.js', '.woff2', '.ico', '.json', '.md', '.txt'].includes(extname(file)) || ['LICENSE', '.nojekyll'].includes(file.split(/[\\/]/).at(-1)), file);
        assert.doesNotMatch(file, /(?:\.env|\.php|\.sqlite|\.pdf|\.log|\.pem|session|private)/i);
        if (['.html', '.js', '.json'].includes(extname(file))) {
            const text = await readFile(resolve(root, file), 'utf8');
            assert.doesNotMatch(text, /sb_secret_|sb_publishable_|xwkfsxohvpebnaqqhuxz|eyJ[A-Za-z0-9_-]{20,}\./, file);
        }
    }
});

test('All page links and assets resolve inside the Pages artifact, including repository subpaths', async () => {
    for (const file of pages) {
        const html = await readFile(resolve(root, file), 'utf8');
        for (const [, value] of html.matchAll(/(?:href|src)="([^"]+)"/g)) {
            if (value.startsWith('#') || value.startsWith('data:')) continue;
            assert.doesNotMatch(value, /^(?:https?:|\/)/, `${file}: ${value}`);
            const target = resolve(dirname(resolve(root, file)), value.split(/[?#]/)[0]);
            assert.ok(target.startsWith(resolve(root)), file);
            assert.ok((await stat(target)).isFile(), `${file}: ${value}`);
        }
    }
});

test('Rendered screens block backend submission even when JavaScript is disabled', async () => {
    for (const file of pages.filter(file => file !== 'index.html')) {
        const html = await readFile(resolve(root, file), 'utf8');
        if (html.includes('http-equiv="refresh"')) continue;
        assert.ok(html.includes("connect-src 'none'"), file);
        assert.ok(html.includes("form-action 'none'"), file);
        assert.doesNotMatch(html, /csrf-token|name="_token"|data-notifications[\s=>]|<script(?![^>]*src=)/, file);
        for (const [form] of html.matchAll(/<form\b[^>]*>/g)) {
            assert.ok(form.includes('action="#preview-notice"'), `${file}: form action`);
        }
        for (const [input] of html.matchAll(/<input\b[^>]*type="(?:password|email|file)"[^>]*>/g)) {
            assert.match(input, /\bdisabled\b/, `${file}: sensitive field`);
        }
    }
});
