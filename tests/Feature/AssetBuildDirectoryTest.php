<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class AssetBuildDirectoryTest extends TestCase
{
    public function test_without_the_setting_vite_uses_the_default_build_and_hot_file(): void
    {
        foreach ([null, ''] as $value) {
            config(['app.asset_build_directory' => 'build-phpunit']);
            $this->configure();

            config(['app.asset_build_directory' => $value]);
            $this->configure();

            $this->assertSame(public_path('hot'), Vite::hotFile());
        }
    }

    public function test_the_setting_moves_the_build_directory_and_hot_file(): void
    {
        config(['app.asset_build_directory' => 'build-phpunit']);
        $this->configure();

        $this->assertSame(public_path('build-phpunit.hot'), Vite::hotFile());

        $this->expectException(ViteManifestNotFoundException::class);
        $this->expectExceptionMessage(public_path('build-phpunit/manifest.json'));

        Vite::asset('resources/css/app.css');
    }

    private function configure(): void
    {
        $this->app->getProvider(AppServiceProvider::class)->configureAssetBuildDirectory();
    }
}
