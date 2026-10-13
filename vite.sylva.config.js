import { defineConfig } from 'vite';

export default defineConfig({
    base: '/build/sylva/',
    publicDir: false,
    oxc: { jsx: { runtime: 'automatic' } },
    build: {
        outDir: 'public/build/sylva',
        emptyOutDir: true,
        manifest: 'manifest.json',
        rolldownOptions: {
            input: 'resources/js/sylva-entry.ts',
        },
    },
});
