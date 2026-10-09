import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    // `npm run dev` builds once with --mode development: readable output + source maps.
    const isDevBuild = mode === 'development';

    // Docker sets ASSET_BUILD_DIRECTORY=build-docker so its build (with Docker's Reverb port baked
    // in) never overwrites Valet's public/build. Must match config('app.asset_build_directory').
    const buildDirectory = process.env.ASSET_BUILD_DIRECTORY || 'build';

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                buildDirectory,
                hotFile: buildDirectory === 'build' ? 'public/hot' : `public/${buildDirectory}.hot`,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        build: {
            minify: !isDevBuild,
            sourcemap: isDevBuild,
        },
        server: {
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
