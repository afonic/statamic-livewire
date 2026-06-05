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
         * Order is load-bearing: NoCacheReplacer renders the nocache regions,
         * so it must run before AssetsReplacer can bake assets for components
         * inside them. AssetsReplacer must run before CsrfTokenReplacer so the
         * baked tags get their CSRF token placeholdered (a real token in the
         * cache would be served to every visitor). The suppression replacer
         * runs last, after the regions have re-rendered on a cache hit.
         */
        $statamicReplacers = config()->array('statamic.static_caching.replacers', []);

        $noCacheReplacers = array_values(array_filter(
            $statamicReplacers,
            fn (string $replacer) => is_a($replacer, NoCacheReplacer::class, true),
        ));

        $remainingReplacers = array_values(array_filter(
            $statamicReplacers,
            fn (string $replacer) => ! is_a($replacer, NoCacheReplacer::class, true),
        ));

        config()->set('statamic.static_caching.replacers', array_merge(
            $noCacheReplacers,
            config()->array('statamic-livewire.replacers', []),
            $remainingReplacers,
            [SuppressAssetsInjectionReplacer::class],
        ));
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
