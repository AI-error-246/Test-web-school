import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';

// Pin the complete registered sources; changing even one byte must stop the build.
const hashes = {
    'src/shaders/sylva-living-world/SylvaLivingWorldScene.tsx': 'e29b92a16596bc9383e1dbd4630e83b70ec2a59dbae48de1d3b7ddc48c0b2082',
    'src/shaders/sylva-living-world/sources/inner-green-3d.html': '69c3694bd63f44ef9f007ebe4dac57a83e4402e0cdf6b54dd10b96dd4f05e197',
    'src/shaders/sylva-living-world/sources/inner-green-assets/three.min.js': '8a5f7249903b54d30f79f708699d2fed2d6a1d0741a4cd41377d1f01bb5a2271',
    'src/shaders/threeui.css': 'efe4447139f1358dd8e9be68edf6fa46cbefbd1de423a4d6c439ca61d2c8eccf',
    'src/shaders/fonts/fragment-mono.woff2': '4f4dc27f4a770c0d02fde800daa836c8adc0d1e423b28da74baaf0d1cc3ab96c',
    'src/shaders/sylva-living-world/sources/inner-green-assets/lexend-latin.woff2': '1ec8f6ee2750554b4bc59ff0b507d316a82a7ba37e0e5bebc41d3bd9b9faad46',
};

for (const [path, expected] of Object.entries(hashes)) {
    test(`Registered Sylva source is byte-exact: ${path}`, async () => {
        const bytes = await readFile(new URL('../../resources/vendor/threeui/' + path, import.meta.url));
        assert.equal(createHash('sha256').update(bytes).digest('hex'), expected);
    });
}
