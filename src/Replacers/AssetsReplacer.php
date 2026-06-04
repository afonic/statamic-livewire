<?php

namespace MarcoRieser\Livewire\Replacers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Statamic\Facades\StaticCache;
use Statamic\Statamic;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\FileCacher;
use Statamic\StaticCaching\Cachers\NullCacher;
use Statamic\StaticCaching\Replacer;
use Statamic\StaticCaching\Replacers\NoCacheReplacer;

class AssetsReplacer implements Replacer
{
    public function prepareResponseToCache(Response $response, Response $initial): void
    {
        if (! $content = $response->getContent()) {
            return;
        }

        // Don't disturb Livewires assets injection when caching is off.
        if (app(Cacher::class) instanceof NullCacher) {
            return;
        }

        $assetsHead = '';
        $assetsBody = '';

        $assets = array_values(SupportScriptsAndAssets::getAssets());

        if (count($assets) > 0) {
            foreach ($assets as $asset) {
                $assetsHead .= $asset."\n";
            }
        }

        if ($this->shouldInjectLivewireAssets()) {
            $assetsHead .= FrontendAssets::styles()."\n";
            $assetsBody .= FrontendAssets::scripts()."\n";

            /**
             * Ensure Livewire injects its assets on the initial request.
             *
             * @see SupportAutoInjectedAssets
             */
            app(FrontendAssets::class)->hasRenderedStyles = false;
            app(FrontendAssets::class)->hasRenderedScripts = false;
        }

        $response->setContent(
            SupportAutoInjectedAssets::injectAssets($content, $assetsHead, $assetsBody)
        );
    }

    protected function shouldInjectLivewireAssets(): bool
    {
        if (! SupportAutoInjectedAssets::$forceAssetInjection && config()->boolean('livewire.inject_assets', true) === false) {
            return false;
        }

        if ((! SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest) && (! SupportAutoInjectedAssets::$forceAssetInjection)) {
            return false;
        }

        if (app(FrontendAssets::class)->hasRenderedScripts) {
            return false;
        }

        return true;
    }

    /**
     * Half-measure re-renders nocache regions, which re-dehydrates Livewire
     * components and arms auto-injection. Suppress it — the cached HTML
     * already has the asset tags. Full measure has nothing to suppress.
     *
     * Statamic also calls this method on fresh non-cacheable responses
     * (Cache middleware line ~101). Skip those — they need normal injection.
     *
     * @see NoCacheReplacer::replaceInCachedResponse()
     */
    public function replaceInCachedResponse(Response $response): void
    {
        $cacher = app(Cacher::class);

        if ($cacher instanceof FileCacher) {
            return;
        }

        if (! $this->isServingFromCache($cacher, request(), $response)) {
            return;
        }

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app(FrontendAssets::class)->hasRenderedScripts = true;
        app(FrontendAssets::class)->hasRenderedStyles = true;
    }

    /**
     * Mirrors Statamic\StaticCaching\Middleware\Cache::canBeCached so that the
     * suppression only fires when this request actually took the cache-hit
     * path. Anything that bypasses canBeCached (token preview, recache token,
     * non-GET, CP) is a fresh render that still needs Livewire auto-injection.
     *
     * Also screens out the RegionNotFound fallback path: Statamic enters the
     * cache-hit branch, NoCacheReplacer throws, the exception is swallowed,
     * and the middleware re-renders via next(). If that fresh response is
     * non-cacheable it still reaches us via line ~101. A genuinely cached
     * response can never carry those uncacheable markers (shouldBeCached
     * filters them at cache-write time), so their presence here proves we
     * are on the fresh path.
     */
    protected function isServingFromCache(Cacher $cacher, Request $request, Response $response): bool
    {
        if ($request->method() !== 'GET') {
            return false;
        }

        if (Statamic::isCpRoute()) {
            return false;
        }

        if ($request->statamicToken()) {
            return false;
        }

        $recacheToken = $request->input(StaticCache::recacheTokenParameter());

        if ($recacheToken !== null && StaticCache::checkRecacheToken($recacheToken)) {
            return false;
        }

        if ($response->headers->has('X-Statamic-Draft')
            || $response->headers->has('X-Statamic-Private')
            || $response->headers->has('X-Statamic-Protected')
            || $response->headers->has('X-Statamic-Uncacheable')) {
            return false;
        }

        if (! in_array($response->getStatusCode(), [200, 404], true)) {
            return false;
        }

        if ($response->getContent() === '' || $response->getContent() === false) {
            return false;
        }

        return $cacher->hasCachedPage($request);
    }
}
