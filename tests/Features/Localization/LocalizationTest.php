<?php

namespace MarcoRieser\Livewire\Tests\Features\Localization;

use Illuminate\Routing\Route;
use MarcoRieser\Livewire\Tests\Concerns\CanManipulateAddonConfig;
use MarcoRieser\Livewire\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Http\Middleware\AddViewPaths;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class LocalizationTest extends TestCase
{
    use CanManipulateAddonConfig;
    use PreventsSavingStacheItemsToDisk;

    #[Test]
    public function localization_is_enabled_by_default()
    {
        $this->assertTrue(config('statamic-livewire.localization'));
    }

    /**
     * Site-specific views have to resolve on update requests like they do on
     * the initial page render.
     */
    #[Test]
    public function update_route_receives_the_view_path_middleware()
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn (Route $route) => $route->named('*livewire.update'));

        $this->assertNotNull($route);
        $this->assertContains(AddViewPaths::class, $route->gatherMiddleware());
    }
}
