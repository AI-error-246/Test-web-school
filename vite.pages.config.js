import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { defineConfig } from 'vite';

// Community release: https://github.com/MengTo/threeui/tree/68802d5428071ada5c20db8094b1649e6bb770ed
// Keep this public preview separate from the originally requested four-variant bundle.
const communityRoot = new URL('./resources/vendor/threeui-community/', import.meta.url);
const hashes = {
    'src/shaders/sylva-living-world/SylvaLivingWorldScene.tsx': '26d13ce750c3bf8ec92955255d77c7b58acf12c709a5c88fb242081ac520aca8',
    'src/shaders/sylva-living-world/sources/inner-green-3d.html': '69c3694bd63f44ef9f007ebe4dac57a83e4402e0cdf6b54dd10b96dd4f05e197',
    'src/shaders/sylva-living-world/sources/inner-green-assets/three.min.js': '8a5f7249903b54d30f79f708699d2fed2d6a1d0741a4cd41377d1f01bb5a2271',
    'src/shaders/sylva-living-world/sources/inner-green-assets/lexend-latin.woff2': '1ec8f6ee2750554b4bc59ff0b507d316a82a7ba37e0e5bebc41d3bd9b9faad46',
    'src/shaders/fonts/fragment-mono.woff2': '4f4dc27f4a770c0d02fde800daa836c8adc0d1e423b28da74baaf0d1cc3ab96c',
    'src/shaders/community.css': 'dc36aeb914df4c554164304292d75738a770e454b1e2710bac1ce4169d5fee2a',
    'LICENSE': 'b2a071ba22fefd803f4e9d1f5c777dd79c62d191695586a1a73fd4d0ebd051c6',
    'ASSET-LICENSES.md': '12a19a4d84bed2fb80cc978c632bc3c3b71dfae3688d687358a492f9fa4e3f99',
    'FONT-LICENSES.md': '05f4ec01ea30193a48878689209f39a2b6541e44291ad8ddc1909a1c1b8b6eeb',
    'THIRD_PARTY_NOTICES.md': 'a7b2740c4d7d50160eb0fcb8a44ec1fb42a5bfa47ba0b51343fee94dd761c01a',
};
const projectRequire = createRequire(import.meta.url);

export default defineConfig({
    base: './',
    publicDir: false,
    oxc: { jsx: { runtime: 'automatic' } },
    plugins: [{
        name: 'verified-community-preview',
        enforce: 'pre',
        buildStart() {
            for (const [path, expected] of Object.entries(hashes)) {
                const actual = createHash('sha256').update(readFileSync(new URL(path, communityRoot))).digest('hex');
                if (actual !== expected) this.error(`Community source SHA-256 mismatch: ${path}`);
            }
        },
        transform(code, id) {
            if (!id.replaceAll('\\', '/').endsWith('/resources/vendor/threeui-community/src/shaders/sylva-living-world/sources/inner-green-3d.html?raw')) return null;
            const fontUrl = 'inner-green-assets/lexend-latin.woff2';
            if (code.split(fontUrl).length !== 2) this.error('Expected exactly one canonical Lexend font URL.');
            // The sandbox has an opaque origin; embed verified bytes without changing vendored sources.
            const font = readFileSync(new URL(`src/shaders/sylva-living-world/sources/${fontUrl}`, communityRoot));
            return { code: code.replace(fontUrl, `data:font/woff2;base64,${font.toString('base64')}`), map: null };
        },
        generateBundle() {
            for (const name of ['LICENSE', 'ASSET-LICENSES.md', 'FONT-LICENSES.md', 'THIRD_PARTY_NOTICES.md']) {
                this.emitFile({ type: 'asset', fileName: `licenses/threeui/${name}`, source: readFileSync(new URL(name, communityRoot)) });
            }
            const reactDomRoot = dirname(projectRequire.resolve('react-dom/package.json'));
            for (const name of ['react', 'react-dom', 'scheduler']) {
                const packageRoot = dirname(projectRequire.resolve(`${name}/package.json`, { paths: [reactDomRoot] }));
                this.emitFile({ type: 'asset', fileName: `licenses/${name}/LICENSE`, source: readFileSync(join(packageRoot, 'LICENSE')) });
            }
        },
    }],
    build: {
        outDir: 'public/build/pages-scene',
        emptyOutDir: true,
        manifest: 'manifest.json',
        rolldownOptions: {
            input: 'resources/js/pages-scene-entry.ts',
        },
    },
});
