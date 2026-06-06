<?php

namespace MarcoRieser\Livewire\Tests\Fixtures\Tags;

use Livewire\Livewire;
use Statamic\Tags\Tags;

/**
 * Mounts a Livewire component (or nothing) based on static state, so tests
 * can make a nocache region render differently on warming vs hit.
 */
class StaticCachingGate extends Tags
{
    protected static $handle = 'static_caching_gate';

    public static ?string $component = null;

    public function index(): string
    {
        if (! static::$component) {
            return '';
        }

        return Livewire::mount(static::$component);
    }
}
