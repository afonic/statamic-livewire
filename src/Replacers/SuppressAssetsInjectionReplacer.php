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
use Statamic\StaticCaching\Replacer;
use Statamic\StaticCaching\Replacers\NoCacheReplacer;

/**
 * On a half-measure cache hit, re-rendering the nocache regions re-arms
 * Livewire's auto asset injection (RequestHandled fires after Statamic's
 * middleware), duplicating the asset tags already in the cached HTML. This
 * replacer suppresses that — it must run after NoCacheReplacer, so the
 * service provider appends it last.
 *
 * @see NoCacheReplacer::replaceInCachedResponse()
 * @see AssetsReplacer::prepareResponseToCache()
 */
class SuppressAssetsInjectionReplacer implements Replacer
{
    public function prepareResponseToCache(Response $response, Response $initial): void
    {
        //
    }

    /**
     * Full measure swaps its regions client-side, so nothing arms the
     * injection there. Fresh non-cacheable responses also pass through here
     * and still need normal injection — isServingFromCache() screens them out.
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

        // `@assets` re-collected by the region re-render are already in the
        // cached HTML and are not gated by the flags above.
        SupportScriptsAndAssets::$renderedAssets = [];

        if (property_exists(SupportScriptsAndAssets::class, 'nonLivewireAssets')) {
            SupportScriptsAndAssets::$nonLivewireAssets = [];
        }
    }

    /**
     * Mirrors the middleware's canBeCached()/shouldBeCached() so suppression
     * only fires on the cache-hit path; hasCachedPage() is the authoritative
     * signal. Keep in sync when upgrading statamic/cms.
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
