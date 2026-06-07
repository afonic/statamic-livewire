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
         * the suppression replacers (including subclasses) last when enabled.
         * Both config lists get partitioned so suppression replacers run last
         * no matter where they were registered. array_unique keeps the merge
         * idempotent across config:cache re-runs.
         */
        $statamicReplacers = $this->partitionReplacers(config()->array('statamic.static_caching.replacers', []));
        $addonReplacers = $this->partitionReplacers(config()->array('statamic-livewire.replacers', []));

        $suppressDuplicateAssets = config()->boolean('statamic-livewire.static_caching.suppress_duplicate_assets', false);

        $suppressionReplacers = array_merge($statamicReplacers['suppression'], $addonReplacers['suppression']);

        config()->set('statamic.static_caching.replacers', array_values(array_unique(array_merge(
            $statamicReplacers['noCache'],
            $addonReplacers['noCache'],
            $addonReplacers['remaining'],
            $statamicReplacers['remaining'],
            $suppressDuplicateAssets ? ($suppressionReplacers ?: [SuppressAssetsInjectionReplacer::class]) : [],
        ))));
    }

    /**
     * @param  array<int, string>  $replacers
     * @return array{noCache: array<int, string>, suppression: array<int, string>, remaining: array<int, string>}
     */
    protected function partitionReplacers(array $replacers): array
    {
        $isNoCache = fn (string $replacer): bool => is_a($replacer, NoCacheReplacer::class, true);
        $isSuppression = fn (string $replacer): bool => is_a($replacer, SuppressAssetsInjectionReplacer::class, true);

        return [
            'noCache' => array_values(array_filter($replacers, $isNoCache)),
            'suppression' => array_values(array_filter($replacers, $isSuppression)),
            'remaining' => array_values(array_filter(
                $replacers,
                fn (string $replacer) => ! $isNoCache($replacer) && ! $isSuppression($replacer),
            )),
        ];
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
