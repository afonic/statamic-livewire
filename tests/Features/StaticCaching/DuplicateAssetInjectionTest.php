<?php

namespace MarcoRieser\Livewire\Tests\Features\StaticCaching;

use Illuminate\Filesystem\Filesystem;
use Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets;
use Livewire\Livewire;
use MarcoRieser\Livewire\Tests\Fixtures\Livewire\AssetsCounter;
use MarcoRieser\Livewire\Tests\Fixtures\Livewire\StaticCachingCounter;
use MarcoRieser\Livewire\Tests\Fixtures\Tags\StaticCachingGate;
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
        $app['config']->set('statamic-livewire.static_caching.suppress_duplicate_assets', true);
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

        $router->get('/assets-page', function () {
            return view('static-caching-assets-page')->render();
        })->middleware(StaticCacheMiddleware::class);

        $router->get('/gated-page', function () {
            return view('static-caching-gated-page')->render();
        })->middleware(StaticCacheMiddleware::class);

        $router->get('/missing-page', function () {
            return response(view('static-caching-page', [
                'has_nocache' => true,
                'has_scripts' => true,
                'has_styles' => true,
            ])->render(), 404);
        })->middleware(StaticCacheMiddleware::class);

        $router->get('/draft-page', function () {
            return response(view('static-caching-page', [
                'has_nocache' => true,
                'has_scripts' => false,
                'has_styles' => false,
            ])->render())->header('X-Statamic-Draft', 'true');
        })->middleware(StaticCacheMiddleware::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Livewire::component('static-caching-counter', StaticCachingCounter::class);
        Livewire::component('assets-counter', AssetsCounter::class);

        StaticCachingGate::register();

        $this->resetState();
    }

    protected function tearDown(): void
    {
        StaticCachingGate::$component = null;

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
            '3: full / no nocache / no assets / inject' => ['full', false, false, true],
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

        $expectedAssetCount = ($hasAssets || $injectAssets) ? 1 : 0;

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

        $this->assertSame(
            $expectedAssetCount,
            $this->countLivewireScriptTags($miss->content()),
            'unexpected script tag count on cache miss',
        );
        $this->assertSame(
            $expectedAssetCount,
            $this->countLivewireStyleBlocks($miss->content()),
            'unexpected style block count on cache miss',
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
            $expectedAssetCount,
            $this->countLivewireScriptTags($hit->content()),
            'script tag count drifted between cache miss and hit',
        );
        $this->assertSame(
            $expectedAssetCount,
            $this->countLivewireStyleBlocks($hit->content()),
            'style block count drifted between cache miss and hit',
        );
    }

    /**
     * KNOWN LIMITATION: full-measure regions render client-side and never
     * trigger asset injection — manual asset tags are required.
     */
    #[Test]
    public function full_measure_with_only_nocache_components_requires_manual_asset_tags(): void
    {
        $this->configureScenario('full', true);
        StaticCache::flush();

        $url = '/test-page?nocache=1&scripts=0&styles=0';

        $miss = $this->get($url);
        $miss->assertOk();
        $this->assertSame(0, $this->countLivewireScriptTags($miss->content()));

        $this->resetState();
        $this->configureScenario('full', true);

        $hit = $this->get($url);
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );
        $this->assertSame(0, $this->countLivewireScriptTags($hit->content()));
    }

    #[Test]
    public function head_assets_are_not_duplicated_on_cache_hit(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        $miss = $this->get('/assets-page');
        $miss->assertOk();
        $this->assertSame(
            1,
            substr_count($miss->content(), 'fake-head-asset.js'),
            'expected the @assets asset exactly once on cache miss',
        );

        $this->resetState();
        $this->configureScenario('half', true);

        $hit = $this->get('/assets-page');
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );
        $this->assertSame(
            1,
            substr_count($hit->content(), 'fake-head-asset.js'),
            '@assets head asset got duplicated on cache hit',
        );
    }

    /**
     * A component the warming request never rendered (e.g. login-gated)
     * must still get its assets injected on the hit.
     */
    #[Test]
    public function component_rendered_only_on_hit_gets_livewire_assets_injected(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        StaticCachingGate::$component = null;

        $miss = $this->get('/gated-page');
        $miss->assertOk();
        $this->assertSame(
            0,
            $this->countLivewireScriptTags($miss->content()),
            'expected no Livewire assets on the warming response without a component',
        );

        $this->resetState();
        $this->configureScenario('half', true);

        StaticCachingGate::$component = 'static-caching-counter';

        $hit = $this->get('/gated-page');
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );
        $this->assertStringContainsString(
            'wire:id',
            $hit->content(),
            'expected the gated component to render inside the nocache region',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($hit->content()),
            'Livewire scripts must be injected when the cached shell does not contain them',
        );
        $this->assertSame(
            1,
            $this->countLivewireStyleBlocks($hit->content()),
            'Livewire styles must be injected when the cached shell does not contain them',
        );
    }

    #[Test]
    public function assets_block_rendered_only_on_hit_is_injected(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        StaticCachingGate::$component = null;

        $miss = $this->get('/gated-page');
        $miss->assertOk();
        $this->assertSame(
            0,
            substr_count($miss->content(), 'fake-head-asset.js'),
            'expected no @assets asset on the warming response without a component',
        );

        $this->resetState();
        $this->configureScenario('half', true);

        StaticCachingGate::$component = 'assets-counter';

        $hit = $this->get('/gated-page');
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );
        $this->assertSame(
            1,
            substr_count($hit->content(), 'fake-head-asset.js'),
            'an @assets asset collected only on the hit must be injected',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($hit->content()),
            'Livewire scripts must be injected when the cached shell does not contain them',
        );
    }

    #[Test]
    public function new_assets_block_on_hit_is_injected_without_duplicating_baked_scripts(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        StaticCachingGate::$component = 'static-caching-counter';

        $miss = $this->get('/gated-page');
        $miss->assertOk();
        $this->assertSame(1, $this->countLivewireScriptTags($miss->content()));
        $this->assertSame(
            0,
            substr_count($miss->content(), 'fake-head-asset.js'),
            'the warming component must not collect the @assets asset',
        );

        $this->resetState();
        $this->configureScenario('half', true);

        StaticCachingGate::$component = 'assets-counter';

        $hit = $this->get('/gated-page');
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );
        $this->assertSame(
            1,
            substr_count($hit->content(), 'fake-head-asset.js'),
            'an @assets asset not baked into the cached shell must be injected on the hit',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($hit->content()),
            'baked Livewire scripts must not be duplicated while injecting new @assets',
        );
        $this->assertSame(
            1,
            $this->countLivewireStyleBlocks($hit->content()),
            'baked Livewire styles must not be duplicated while injecting new @assets',
        );
    }

    #[Test]
    public function gated_component_rendered_on_both_warming_and_hit_stays_deduplicated(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        StaticCachingGate::$component = 'assets-counter';

        $miss = $this->get('/gated-page');
        $miss->assertOk();
        $this->assertSame(1, $this->countLivewireScriptTags($miss->content()));
        $this->assertSame(1, substr_count($miss->content(), 'fake-head-asset.js'));

        $this->resetState();
        $this->configureScenario('half', true);

        $hit = $this->get('/gated-page');
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($hit->content()),
            'script tag count drifted between cache miss and hit',
        );
        $this->assertSame(
            1,
            $this->countLivewireStyleBlocks($hit->content()),
            'style block count drifted between cache miss and hit',
        );
        $this->assertSame(
            1,
            substr_count($hit->content(), 'fake-head-asset.js'),
            '@assets head asset got duplicated on cache hit',
        );
    }

    #[Test]
    public function assets_are_still_injected_on_fresh_non_cacheable_responses(): void
    {
        $this->configureScenario('half', true);
        config()->set('statamic.static_caching.exclude.urls', ['/test-page*']);
        StaticCache::flush();

        $url = '/test-page?nocache=1&scripts=0&styles=0';

        $first = $this->get($url);
        $first->assertOk();
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($first->content()),
            'expected Livewire assets on the first request to an excluded URL',
        );

        $this->resetState();
        $this->configureScenario('half', true);

        $second = $this->get($url);
        $second->assertOk();
        $this->assertNotSame(
            ResponseStatus::HIT,
            $second->baseResponse->staticCacheResponseStatus(),
            'an excluded URL must never be served from cache',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($second->content()),
            'Livewire assets were wrongly suppressed on a fresh non-cacheable response',
        );
    }

    /**
     * Half measure caches 404s too; the cached status and manual asset tags
     * must survive the hit.
     */
    #[Test]
    public function cached_404_pages_keep_their_status_and_asset_tags_on_cache_hit(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        $miss = $this->get('/missing-page');
        $miss->assertNotFound();
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($miss->content()),
            'expected exactly one manual script tag on the fresh 404',
        );

        $this->resetState();
        $this->configureScenario('half', true);

        $hit = $this->get('/missing-page');
        $hit->assertNotFound();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected the 404 to be served from cache on the second request',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($hit->content()),
            'script tag count drifted on the cached 404',
        );
        $this->assertSame(
            1,
            $this->countLivewireStyleBlocks($hit->content()),
            'style block count drifted on the cached 404',
        );
    }

    /**
     * Recache-token requests bypass the cached page and must be served
     * fresh with assets injected normally.
     */
    #[Test]
    public function recache_token_requests_are_served_fresh_with_assets_injected(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        $url = '/test-page?nocache=1&scripts=0&styles=0';

        $miss = $this->get($url);
        $miss->assertOk();
        $this->assertSame(1, $this->countLivewireScriptTags($miss->content()));

        $this->resetState();
        $this->configureScenario('half', true);

        $recache = $this->get($url.'&'.http_build_query([
            StaticCache::recacheTokenParameter() => StaticCache::recacheToken(),
        ]));
        $recache->assertOk();
        $this->assertNotSame(
            ResponseStatus::HIT,
            $recache->baseResponse->staticCacheResponseStatus(),
            'a valid recache token request must never be served from cache',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($recache->content()),
            'Livewire assets were wrongly suppressed on a recache token request',
        );
    }

    /**
     * Draft responses are never cached and must keep normal asset injection.
     */
    #[Test]
    public function draft_responses_are_never_cached_and_keep_injected_assets(): void
    {
        $this->configureScenario('half', true);
        StaticCache::flush();

        $first = $this->get('/draft-page');
        $first->assertOk();
        $this->assertSame(1, $this->countLivewireScriptTags($first->content()));

        $this->resetState();
        $this->configureScenario('half', true);

        $second = $this->get('/draft-page');
        $second->assertOk();
        $this->assertNotSame(
            ResponseStatus::HIT,
            $second->baseResponse->staticCacheResponseStatus(),
            'a draft response must never be served from cache',
        );
        $this->assertSame(
            1,
            $this->countLivewireScriptTags($second->content()),
            'Livewire assets were wrongly suppressed on a draft response',
        );
    }

    private function countLivewireScriptTags(string $content): int
    {
        return (int) preg_match_all('/livewire(?:\.min)?\.js\?id=/', $content);
    }

    private function countLivewireStyleBlocks(string $content): int
    {
        return substr_count($content, '<!-- Livewire Styles -->');
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
        SupportScriptsAndAssets::$renderedAssets = [];

        if (property_exists(SupportScriptsAndAssets::class, 'nonLivewireAssets')) {
            SupportScriptsAndAssets::$nonLivewireAssets = [];
        }

        app()->forgetInstance(StaticCacheManager::class);
    }
}
