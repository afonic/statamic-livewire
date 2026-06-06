<?php

namespace MarcoRieser\Livewire\Tests\Concerns;

use Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets;
use Livewire\Livewire;
use Statamic\StaticCaching\StaticCacheManager;

/**
 * Helpers for static caching tests that simulate consecutive requests within
 * a single process. The reset pokes Livewire's request-scoped statics and the
 * counters match Livewire's rendered markup — update them together when
 * Livewire's internals shift.
 */
trait CanSimulateStaticCachingRequests
{
    protected function resetStateBetweenRequests(): void
    {
        Livewire::flushState();

        SupportScriptsAndAssets::$alreadyRunAssetKeys = [];
        SupportScriptsAndAssets::$renderedAssets = [];

        if (property_exists(SupportScriptsAndAssets::class, 'nonLivewireAssets')) {
            SupportScriptsAndAssets::$nonLivewireAssets = [];
        }

        app()->forgetInstance(StaticCacheManager::class);
    }

    protected function countLivewireScriptTags(string $content): int
    {
        return (int) preg_match_all('/livewire(?:\.min)?\.js\?id=/', $content);
    }

    protected function countLivewireStyleBlocks(string $content): int
    {
        return substr_count($content, '<!-- Livewire Styles -->');
    }
}
