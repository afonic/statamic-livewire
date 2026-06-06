<?php

namespace MarcoRieser\Livewire\Tests\Features\StaticCaching;

use Livewire\Livewire;
use MarcoRieser\Livewire\Replacers\SuppressAssetsInjectionReplacer;
use MarcoRieser\Livewire\Tests\Concerns\CanSimulateStaticCachingRequests;
use MarcoRieser\Livewire\Tests\Fixtures\Livewire\StaticCachingCounter;
use MarcoRieser\Livewire\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\StaticCache;
use Statamic\StaticCaching\Middleware\Cache as StaticCacheMiddleware;
use Statamic\StaticCaching\ResponseStatus;

class SuppressionDisabledTest extends TestCase
{
    use CanSimulateStaticCachingRequests;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('statamic.static_caching.strategy', 'half');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/suppression-disabled-page', function () {
            return view('static-caching-page', [
                'has_nocache' => true,
                'has_scripts' => false,
                'has_styles' => false,
            ])->render();
        })->middleware(StaticCacheMiddleware::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Livewire::component('static-caching-counter', StaticCachingCounter::class);

        $this->resetStateBetweenRequests();
    }

    /**
     * Suppression is opt-in: by default nothing is suppressed and cache hits
     * may duplicate the asset tags.
     */
    #[Test]
    public function asset_injection_is_not_suppressed_by_default(): void
    {
        StaticCache::flush();

        $this->assertNotContains(
            SuppressAssetsInjectionReplacer::class,
            config('statamic.static_caching.replacers'),
        );

        $miss = $this->get('/suppression-disabled-page');
        $miss->assertOk();
        $this->assertSame(1, $this->countLivewireScriptTags($miss->content()));

        $this->resetStateBetweenRequests();

        $hit = $this->get('/suppression-disabled-page');
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );
        $this->assertSame(
            2,
            $this->countLivewireScriptTags($hit->content()),
            'expected the duplicate injection with suppression off',
        );
    }
}
