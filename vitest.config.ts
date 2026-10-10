import { defineConfig } from 'vitest/config';
import { fileURLToPath, URL } from 'node:url';

/**
 * Browser-side unit tests (`npm test`). Kept apart from vite.config.ts: the Laravel plugin and its
 * font download are not needed, and tests must never reach the network.
 */
export default defineConfig({
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
        restoreMocks: true,
        unstubGlobals: true,
    },
});
