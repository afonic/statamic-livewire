<?php

namespace MarcoRieser\Livewire\Tests\Features\StaticCaching;

use Illuminate\Filesystem\Filesystem;
use Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets;
use Livewire\Livewire;
use MarcoRieser\Livewire\Tests\Fixtures\Livewire\StaticCachingCounter;
use MarcoRieser\Livewire\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\StaticCache;
use Statamic\StaticCaching\Middleware\Cache as StaticCacheMiddleware;
use Statamic\StaticCaching\ResponseStatus;
use Statamic\StaticCaching\StaticCacheManager;

class DuplicateAssetInjectionTest extends TestCase
{
    private string $fileCachePath;

    protected function defineEnvironment($app): void
    {
        $this->fileCachePath = sys_get_temp_dir().'/statamic-livewire-static-'.uniqid();

        $app['config']->set('statamic.static_caching.strategies.full.path', $this->fileCachePath);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/test-page', function () {
            $request = request();

            return view('static-caching-page', [
                'has_nocache' => (bool) $request->query('nocache'),
                'has_scripts' => (bool) $request->query('scripts'),
                'has_styles' => (bool) $request->query('styles'),
            ])->render();
        })->middleware(StaticCacheMiddleware::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Livewire::component('static-caching-counter', StaticCachingCounter::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->fileCachePath)) {
            (new Filesystem)->deleteDirectory($this->fileCachePath);
        }

        parent::tearDown();
    }

    public static function scenarios(): array
    {
        return [
            '1: full / no nocache / assets / inject' => ['full', false, true, true],
            '2: full / nocache / assets / inject' => ['full', true, true, true],
            '3: full / nocache / no assets / inject' => ['full', true, false, true],
            '4: half / no nocache / assets / inject' => ['half', false, true, true],
            '5: half / nocache / assets / inject (regression)' => ['half', true, true, true],
            '6: half / nocache / no assets / inject (regression)' => ['half', true, false, true],
            '7: half / nocache / assets / no inject' => ['half', true, true, false],
            '8: half / nocache / no assets / no inject' => ['half', true, false, false],
        ];
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function livewire_assets_are_not_duplicated_on_cache_hit(
        string $strategy,
        bool $hasNocache,
        bool $hasAssets,
        bool $injectAssets,
    ): void {
        $this->configureScenario($strategy, $injectAssets);
        StaticCache::flush();

        $url = '/test-page?'.http_build_query([
            'nocache' => (int) $hasNocache,
            'scripts' => (int) $hasAssets,
            'styles' => (int) $hasAssets,
        ]);

        $miss = $this->get($url);
        $miss->assertOk();
        $this->assertNotSame(
            ResponseStatus::HIT,
            $miss->baseResponse->staticCacheResponseStatus(),
            'expected first request to be served fresh, not from cache',
        );

        $this->resetState();
        $this->configureScenario($strategy, $injectAssets);

        $hit = $this->get($url);
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );

        $this->assertSame(
            substr_count($miss->content(), 'livewire.js?id='),
            substr_count($hit->content(), 'livewire.js?id='),
            'script tag count drifted between cache miss and hit',
        );

        $this->assertSame(
            substr_count($miss->content(), '<!-- Livewire Styles -->'),
            substr_count($hit->content(), '<!-- Livewire Styles -->'),
            'style block count drifted between cache miss and hit',
        );
    }

    private function configureScenario(string $strategy, bool $injectAssets): void
    {
        config()->set('statamic.static_caching.strategy', $strategy);
        config()->set('livewire.inject_assets', $injectAssets);

        app()->forgetInstance(StaticCacheManager::class);
    }

    private function resetState(): void
    {
        Livewire::flushState();

        SupportScriptsAndAssets::$alreadyRunAssetKeys = [];

        app()->forgetInstance(StaticCacheManager::class);
    }
}
