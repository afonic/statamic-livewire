<?php

namespace MarcoRieser\Livewire\Tests\Features\StaticCaching;

use MarcoRieser\Livewire\Replacers\AssetsReplacer;
use MarcoRieser\Livewire\Replacers\SuppressAssetsInjectionReplacer;
use MarcoRieser\Livewire\ServiceProvider;
use MarcoRieser\Livewire\Tests\Fixtures\Replacers\CustomSuppressionReplacer;
use MarcoRieser\Livewire\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Statamic\StaticCaching\Replacers\CsrfTokenReplacer;
use Statamic\StaticCaching\Replacers\NoCacheReplacer;

class ReplacerRegistrationTest extends TestCase
{
    #[Test]
    public function replacers_are_registered_in_the_expected_order(): void
    {
        $replacers = config('statamic.static_caching.replacers');

        $assetsPosition = array_search(AssetsReplacer::class, $replacers, true);
        $csrfPosition = array_search(CsrfTokenReplacer::class, $replacers, true);

        $this->assertSame(NoCacheReplacer::class, $replacers[0], 'NoCacheReplacer must run first so nocache regions are rendered before assets are baked');
        $this->assertNotContains(SuppressAssetsInjectionReplacer::class, $replacers, 'asset suppression is opt-in and must not register by default');
        $this->assertIsInt($assetsPosition, 'AssetsReplacer must be registered');
        $this->assertIsInt($csrfPosition, 'CsrfTokenReplacer must be registered');
        $this->assertLessThan(
            $csrfPosition,
            $assetsPosition,
            'AssetsReplacer must run before CsrfTokenReplacer so baked tags get their CSRF token placeholdered',
        );
        $this->assertSame($replacers, array_values(array_unique($replacers)), 'replacers must not contain duplicates');
    }

    #[Test]
    public function suppression_replacer_is_registered_last_when_enabled(): void
    {
        config()->set('statamic-livewire.static_caching.suppress_duplicate_assets', true);

        $provider = $this->app->getProvider(ServiceProvider::class);
        (new ReflectionMethod($provider, 'bootReplacers'))->invoke($provider);

        $replacers = config('statamic.static_caching.replacers');

        $this->assertSame(SuppressAssetsInjectionReplacer::class, end($replacers), 'SuppressAssetsInjectionReplacer must run last, after the regions have re-rendered');
        $this->assertSame(NoCacheReplacer::class, $replacers[0]);
    }

    #[Test]
    public function disabling_suppression_removes_an_already_registered_replacer(): void
    {
        $provider = $this->app->getProvider(ServiceProvider::class);
        $bootReplacers = new ReflectionMethod($provider, 'bootReplacers');

        config()->set('statamic-livewire.static_caching.suppress_duplicate_assets', true);
        $bootReplacers->invoke($provider);
        $this->assertContains(SuppressAssetsInjectionReplacer::class, config('statamic.static_caching.replacers'));

        config()->set('statamic-livewire.static_caching.suppress_duplicate_assets', false);
        $bootReplacers->invoke($provider);
        $this->assertNotContains(SuppressAssetsInjectionReplacer::class, config('statamic.static_caching.replacers'));
    }

    /**
     * Subclasses are treated like the suppression replacer itself: they get
     * repositioned to the end (without adding the base class alongside) and
     * removed when suppression is disabled.
     */
    #[Test]
    public function subclassed_suppression_replacers_are_repositioned_last_when_enabled(): void
    {
        config()->set('statamic-livewire.static_caching.suppress_duplicate_assets', true);
        config()->set('statamic.static_caching.replacers', [
            CustomSuppressionReplacer::class,
            ...config('statamic.static_caching.replacers'),
        ]);

        $provider = $this->app->getProvider(ServiceProvider::class);
        $bootReplacers = new ReflectionMethod($provider, 'bootReplacers');

        $bootReplacers->invoke($provider);

        $replacers = config('statamic.static_caching.replacers');

        $this->assertSame(NoCacheReplacer::class, $replacers[0]);
        $this->assertSame(CustomSuppressionReplacer::class, end($replacers), 'a subclassed suppression replacer must be repositioned to run last');
        $this->assertNotContains(SuppressAssetsInjectionReplacer::class, $replacers, 'the base replacer must not be appended when a subclass is registered');

        config()->set('statamic-livewire.static_caching.suppress_duplicate_assets', false);
        $bootReplacers->invoke($provider);

        $this->assertNotContains(
            CustomSuppressionReplacer::class,
            config('statamic.static_caching.replacers'),
            'disabling suppression must also remove subclassed suppression replacers',
        );
    }

    /**
     * `php artisan config:cache` boots the app (running bootReplacers()) and
     * dumps the mutated config, so on the next boot bootReplacers() re-runs
     * on its own output. Re-applying it must not accumulate duplicates.
     */
    #[Test]
    public function replacer_registration_is_idempotent_when_rebooted_on_its_own_output(): void
    {
        $provider = $this->app->getProvider(ServiceProvider::class);
        $bootReplacers = new ReflectionMethod($provider, 'bootReplacers');

        $firstBoot = config('statamic.static_caching.replacers');

        $bootReplacers->invoke($provider);
        $bootReplacers->invoke($provider);

        $this->assertSame(
            $firstBoot,
            config('statamic.static_caching.replacers'),
            'booting replacers on an already-merged config must not change it',
        );
    }
}
