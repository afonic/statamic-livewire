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

/**
 * On half-measure cache hits, the nocache region re-render re-arms
 * Livewire's auto asset injection. Suppresses only assets already present
 * in the served content. Must run after NoCacheReplacer.
 *
 * @see SupportAutoInjectedAssets
 * @see AssetsReplacer::prepareResponseToCache()
 */
class SuppressAssetsInjectionReplacer implements Replacer
{
    public function prepareResponseToCache(Response $response, Response $initial): void
    {
        //
    }

    /**
     * Marks already-served scripts/styles as rendered and drops baked
     * `@assets`, leaving Livewire's RequestHandled listener only what is
     * genuinely missing from the shell.
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

        $content = (string) $response->getContent();

        if ($this->containsLivewireScripts($content)) {
            app(FrontendAssets::class)->hasRenderedScripts = true;
        }

        if ($this->containsLivewireStyles($content)) {
            app(FrontendAssets::class)->hasRenderedStyles = true;
        }

        SupportScriptsAndAssets::$renderedAssets = $this->withoutAssetsBakedIntoContent(
            SupportScriptsAndAssets::$renderedAssets,
            $content,
        );

        if (property_exists(SupportScriptsAndAssets::class, 'nonLivewireAssets')) {
            SupportScriptsAndAssets::$nonLivewireAssets = $this->withoutAssetsBakedIntoContent(
                SupportScriptsAndAssets::$nonLivewireAssets,
                $content,
            );
        }
    }

    /**
     * Matches the injected script tag and `@livewireScriptConfig` setups.
     */
    protected function containsLivewireScripts(string $content): bool
    {
        return str_contains($content, 'data-update-uri')
            || str_contains($content, 'window.livewireScriptConfig');
    }

    /**
     * The selector marker survives comment-stripping minifiers.
     */
    protected function containsLivewireStyles(string $content): bool
    {
        return str_contains($content, '<!-- Livewire Styles -->')
            || str_contains($content, '[wire\:loading]');
    }

    /**
     * Drops assets already baked into the served content, nonce-stripped on
     * both sides so per-request CSP nonces can't defeat the comparison.
     *
     * @param  array<string, string>  $assets
     * @return array<string, string>
     */
    protected function withoutAssetsBakedIntoContent(array $assets, string $content): array
    {
        $normalizedContent = $this->stripNonces($content);

        return array_filter($assets, function ($asset) use ($normalizedContent): bool {
            $asset = trim($this->stripNonces((string) $asset));

            return $asset !== '' && ! str_contains($normalizedContent, $asset);
        });
    }

    protected function stripNonces(string $html): string
    {
        return preg_replace('/\snonce="[^"]*"/', '', $html) ?? $html;
    }

    /**
     * Mirrors the middleware's canBeCached()/shouldBeCached(); keep in sync
     * when upgrading statamic/cms.
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
