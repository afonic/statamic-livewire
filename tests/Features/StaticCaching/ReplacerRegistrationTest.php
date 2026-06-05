<?php

namespace MarcoRieser\Livewire\Tests\Features\StaticCaching;

use MarcoRieser\Livewire\Replacers\AssetsReplacer;
use MarcoRieser\Livewire\Replacers\SuppressAssetsInjectionReplacer;
use MarcoRieser\Livewire\ServiceProvider;
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
        $this->assertSame(SuppressAssetsInjectionReplacer::class, end($replacers), 'SuppressAssetsInjectionReplacer must run last, after the regions have re-rendered');
        $this->assertIsInt($assetsPosition, 'AssetsReplacer must be registered');
        $this->assertIsInt($csrfPosition, 'CsrfTokenReplacer must be registered');
        $this->assertLessThan(
            $csrfPosition,
            $assetsPosition,
            'AssetsReplacer must run before CsrfTokenReplacer so baked tags get their CSRF token placeholdered',
        );
        $this->assertSame($replacers, array_values(array_unique($replacers)), 'replacers must not contain duplicates');
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
