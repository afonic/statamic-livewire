<?php

namespace MarcoRieser\Livewire;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Synthesizers\Synth;
use MarcoRieser\Livewire\Hooks\CascadeVariablesAutoloader;
use MarcoRieser\Livewire\Hooks\ComputedPropertiesAutoloader;
use MarcoRieser\Livewire\Hooks\SynthesizerAugmentor;
use MarcoRieser\Livewire\Http\Middleware\HydrateCascadeByLivewireUrl;
use MarcoRieser\Livewire\Http\Middleware\ResolveCurrentSiteByLivewireUrl;
use MarcoRieser\Livewire\Replacers\SuppressAssetsInjectionReplacer;
use Statamic\Http\Middleware\Localize;
use Statamic\Providers\AddonServiceProvider;
use Statamic\StaticCaching\Replacers\NoCacheReplacer;

class ServiceProvider extends AddonServiceProvider
{
    protected array $middlewares = [];

    protected $tags = [
        'MarcoRieser\Livewire\Tags\Livewire',
    ];

    public function register(): void
    {
        parent::register();

        $this->registerSynthesizerAugmentation();
        $this->registerComputedPropertiesAutoloader();
        $this->registerCascadeVariablesAutoloader();
    }

    public function bootAddon(): void
    {
        $this->bootLocalization();
        $this->bootCascadeRestoration();
        $this->bootReplacers();
        $this->bootSynthesizers();
        $this->bootMiddlewares();
    }

    protected function bootLocalization(): void
    {
        if (! config()->boolean('statamic-livewire.localization', true)) {
            return;
        }

        $this->middlewares[] = ResolveCurrentSiteByLivewireUrl::class;
        $this->middlewares[] = Localize::class;
    }

    protected function bootCascadeRestoration(): void
    {
        $this->middlewares[] = HydrateCascadeByLivewireUrl::class;
    }

    protected function bootReplacers(): void
    {
        /**
         * Order matters: NoCacheReplacer first, then the addon replacers, then
         * the suppression replacer last (when enabled). array_unique keeps the
         * merge idempotent across config:cache re-runs.
         */
        $statamicReplacers = config()->array('statamic.static_caching.replacers', []);

        $suppressDuplicateAssets = config()->boolean('statamic-livewire.static_caching.suppress_duplicate_assets', false);

        $noCacheReplacers = array_values(array_filter(
            $statamicReplacers,
            fn (string $replacer) => is_a($replacer, NoCacheReplacer::class, true),
        ));

        $remainingReplacers = array_values(array_filter(
            $statamicReplacers,
            fn (string $replacer) => ! is_a($replacer, NoCacheReplacer::class, true)
                && $replacer !== SuppressAssetsInjectionReplacer::class,
        ));

        config()->set('statamic.static_caching.replacers', array_values(array_unique(array_merge(
            $noCacheReplacers,
            config()->array('statamic-livewire.replacers', []),
            $remainingReplacers,
            $suppressDuplicateAssets ? [SuppressAssetsInjectionReplacer::class] : [],
        ))));
    }

    protected function bootSynthesizers(): void
    {
        if (! config('statamic-livewire.synthesizers.enabled', false)) {
            return;
        }

        collect(config()->array('statamic-livewire.synthesizers.classes', []))
            ->filter(fn (string $synthesizer) => is_subclass_of($synthesizer, Synth::class))
            ->each(fn (string $synthesizer) => Livewire::propertySynthesizer($synthesizer));
    }

    protected function registerSynthesizerAugmentation(): void
    {
        Livewire::componentHook(SynthesizerAugmentor::class);
    }

    protected function registerComputedPropertiesAutoloader(): void
    {
        Livewire::componentHook(ComputedPropertiesAutoloader::class);
    }

    protected function registerCascadeVariablesAutoloader(): void
    {
        Livewire::componentHook(CascadeVariablesAutoloader::class);
    }

    protected function bootMiddlewares(): void
    {
        collect($this->app->make(Router::class)->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => $route->named('*livewire.update'))
            ->each(fn (Route $route) => $route->middleware($this->middlewares));
    }
}
