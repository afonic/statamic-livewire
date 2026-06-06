<?php

namespace MarcoRieser\Livewire\Tests\Features\StaticCaching;

use Illuminate\Filesystem\Filesystem;
use Livewire\Livewire;
use MarcoRieser\Livewire\Tests\Concerns\CanSimulateStaticCachingRequests;
use MarcoRieser\Livewire\Tests\Fixtures\Livewire\StaticCachingCounter;
use MarcoRieser\Livewire\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\StaticCache;
use Statamic\StaticCaching\Middleware\Cache as StaticCacheMiddleware;
use Statamic\StaticCaching\ResponseStatus;
use Statamic\StaticCaching\StaticCacheManager;

/**
 * The baked Livewire script tag carries the real CSRF token, so AssetsReplacer
 * must run before CsrfTokenReplacer placeholders it — otherwise the warming
 * visitor's token gets served to everyone.
 */
class CsrfTokenPlaceholderTest extends TestCase
{
    use CanSimulateStaticCachingRequests;

    private string $fileCachePath;

    protected function defineEnvironment($app): void
    {
        $this->fileCachePath = sys_get_temp_dir().'/statamic-livewire-csrf-'.uniqid();

        $app['config']->set('statamic.static_caching.strategies.full.path', $this->fileCachePath);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('livewire.inject_assets', true);
        $app['config']->set('session.driver', 'array');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/csrf-page', function () {
            $request = request();

            return view('static-caching-page', [
                'has_nocache' => (bool) $request->query('nocache'),
                'has_scripts' => false,
                'has_styles' => false,
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

    #[Test]
    public function real_csrf_token_is_not_baked_into_full_measure_static_file(): void
    {
        $this->configureStrategy('full');
        StaticCache::flush();

        $this->startSession();
        $token = csrf_token();

        $this->assertNotEmpty($token, 'expected a session csrf token to exist');

        $this->get('/csrf-page')->assertOk();

        $files = (new Filesystem)->allFiles($this->fileCachePath);

        $this->assertNotEmpty($files, 'expected a static file to be written');

        $content = file_get_contents($files[0]->getPathname());

        $this->assertStringContainsString('data-csrf="STATAMIC_CSRF_TOKEN"', $content,
            'Livewire script tag should carry the CSRF placeholder in the static file');

        $this->assertStringNotContainsString('data-csrf="'.$token.'"', $content,
            'REAL session CSRF token was baked into the full-measure static file');
    }

    #[Test]
    public function half_measure_cache_hits_serve_the_current_visitors_csrf_token(): void
    {
        $this->configureStrategy('half');
        StaticCache::flush();

        $this->startSession();
        $warmToken = csrf_token();

        $this->get('/csrf-page?nocache=1')->assertOk();

        $this->resetStateBetweenRequests();
        $this->configureStrategy('half');

        session()->regenerateToken();
        $visitorToken = csrf_token();

        $this->assertNotSame($warmToken, $visitorToken);

        $hit = $this->get('/csrf-page?nocache=1');
        $hit->assertOk();
        $this->assertSame(
            ResponseStatus::HIT,
            $hit->baseResponse->staticCacheResponseStatus(),
            'expected second request to be served from cache',
        );

        $this->assertStringContainsString('data-csrf="'.$visitorToken.'"', $hit->content(),
            'cache hit should carry the current visitor\'s CSRF token');

        $this->assertStringNotContainsString('data-csrf="'.$warmToken.'"', $hit->content(),
            'cache hit served the warming visitor\'s CSRF token');
    }

    private function configureStrategy(string $strategy): void
    {
        config()->set('statamic.static_caching.strategy', $strategy);

        app()->forgetInstance(StaticCacheManager::class);
    }
}
