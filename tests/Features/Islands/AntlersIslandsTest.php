<?php

namespace MarcoRieser\Livewire\Tests\Features\Islands;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\View\ViewException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarcoRieser\Livewire\Attributes\Cascade;
use MarcoRieser\Livewire\Exceptions\IslandException;
use MarcoRieser\Livewire\Islands\IslandRenderer;
use MarcoRieser\Livewire\Islands\WithSnapshot;
use MarcoRieser\Livewire\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Orchestra\Testbench\package_path;

class AntlersIslandsTest extends TestCase
{
    /**
     * The island cache in the testbench skeleton survives across PHPUnit processes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
    }

    #[Test]
    public function island_content_is_rendered_with_antlers_on_mount()
    {
        $testable = $this->mountIslandComponent();

        $testable->assertSee('Outside the island');
        $testable->assertSee('Hello World!');
        $testable->assertSeeHtml('type=island|name=stats|token=antlers-');
    }

    #[Test]
    public function island_is_skipped_on_subsequent_renders()
    {
        $testable = $this->mountIslandComponent();

        $testable->call('$refresh');

        $testable->assertSee('Outside the island');
        $testable->assertDontSee('Hello World!');
        $testable->assertSeeHtml('mode=skip');
    }

    #[Test]
    public function island_can_be_rendered_from_php()
    {
        $testable = $this->mountIslandComponent();

        $testable->set('name', 'Statamic')->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello Statamic!', $fragments[0]);
        $this->assertStringContainsString('type=island|name=stats|token=antlers-', $fragments[0]);
    }

    #[Test]
    public function deferred_island_renders_placeholder_on_mount()
    {
        $testable = $this->mountIslandComponent('antlers-island-deferred');

        $testable->assertSee('Loading stats...');
        $testable->assertDontSee('Hello World!');
        $testable->assertSeeHtml('wire:init="__lazyLoadIsland"');
    }

    #[Test]
    public function lazy_island_renders_placeholder_on_mount()
    {
        $testable = $this->mountIslandComponent('antlers-island-lazy');

        $testable->assertSee('Loading stats...');
        $testable->assertDontSee('Hello World!');
        $testable->assertSeeHtml('wire:intersect.once="__lazyLoadIsland"');
    }

    #[Test]
    public function deferred_island_renders_its_content_when_lazy_loaded()
    {
        $testable = $this->mountIslandComponent('antlers-island-deferred');

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $testable->update(calls: [[
            'method' => '__lazyLoadIsland',
            'params' => [],
            'path' => '',
            'metadata' => ['island' => ['name' => 'stats', 'mode' => 'morph']],
        ]]);

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
        $this->assertStringNotContainsString('Loading stats...', $fragments[0]);
    }

    #[Test]
    public function island_cache_files_are_regenerated_when_missing()
    {
        $testable = $this->mountIslandComponent();

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
    }

    /**
     * Cache regeneration has to resolve views provided through view() like
     * Livewire's normal render path does.
     */
    #[Test]
    public function island_cache_files_are_regenerated_for_components_with_a_provided_view()
    {
        $component = new class extends Component
        {
            public string $name = 'World';

            public function refreshStats(): void
            {
                $this->renderIsland('stats');
            }

            public function view()
            {
                return view('antlers-island');
            }
        };

        Livewire::component('antlers-island-provided-view-component', $component::class);

        $testable = Livewire::test('antlers-island-provided-view-component');

        $testable->assertSee('Hello World!');

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
    }

    /**
     * The regeneration render must run the render hooks, otherwise islands
     * inside conditions depending on autoloaded data are silently dropped.
     */
    #[Test]
    public function islands_inside_computed_property_conditions_are_regenerated_when_missing()
    {
        $testable = $this->mountIslandComponent('antlers-island-computed-condition');

        $testable->assertSee('Hello World!');

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
    }

    /**
     * The regeneration render must not run before boot() and the other
     * lifecycle hooks have initialized the component.
     */
    #[Test]
    public function island_cache_files_are_regenerated_after_lifecycle_hooks_initialize_the_component()
    {
        $component = new class extends Component
        {
            public string $name = 'World';

            protected string $resolvedViewName;

            public function boot(): void
            {
                $this->resolvedViewName = 'antlers-island';
            }

            public function refreshStats(): void
            {
                $this->renderIsland('stats');
            }

            public function render()
            {
                return view($this->resolvedViewName);
            }
        };

        Livewire::component('antlers-island-boot-component', $component::class);

        $testable = Livewire::test('antlers-island-boot-component');

        $testable->assertSee('Hello World!');

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
    }

    /**
     * Island renders are not always reached through a method call: updated()
     * hooks run on property updates, so regeneration hooks those too.
     */
    #[Test]
    public function island_cache_files_are_regenerated_when_an_updated_hook_renders_an_island()
    {
        $component = new class extends Component
        {
            public string $name = 'World';

            public function updatedName(): void
            {
                $this->renderIsland('stats');
            }

            public function render()
            {
                return view('antlers-island');
            }
        };

        Livewire::component('antlers-island-updated-hook-component', $component::class);

        $testable = Livewire::test('antlers-island-updated-hook-component');

        $testable->assertSee('Hello World!');

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $testable->set('name', 'Statamic');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello Statamic!', $fragments[0]);
    }

    /**
     * Regeneration runs right before the island view renders, so it sees the
     * state the request produced — not the one it arrived with.
     */
    #[Test]
    public function islands_behind_conditions_enabled_by_the_incoming_update_are_regenerated()
    {
        $testable = $this->mountConditionalIslandComponent();

        $testable->set('show', false);

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $testable->set('show', true);

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
    }

    #[Test]
    public function islands_behind_conditions_enabled_by_the_called_action_are_regenerated()
    {
        $testable = $this->mountConditionalIslandComponent();

        $testable->set('show', false);

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $testable->call('reveal');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
    }

    /**
     * An island whose tag stays unreachable cannot get its cache file back:
     * the render has to fail instead of regenerating endlessly.
     */
    #[Test]
    public function islands_unreachable_under_the_resulting_state_fail_without_recursing()
    {
        $testable = $this->mountConditionalIslandComponent();

        $testable->set('show', false);

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $this->expectException(FileNotFoundException::class);

        $testable->call('refreshStats');
    }

    #[Test]
    public function island_renderer_renders_the_placeholder_branch()
    {
        $renderer = new IslandRenderer;

        $this->assertSame('<p>Loading...</p>', trim($renderer->render(['__placeholder' => ''], '<p>Hello {{ name }}!</p>', '<p>Loading...</p>')));
        $this->assertSame('', $renderer->render(['__placeholder' => ''], '<p>Hello {{ name }}!</p>'));
        $this->assertSame('<p>Hello World!</p>', trim($renderer->render(['name' => 'World'], '<p>Hello {{ name }}!</p>', '<p>Loading...</p>')));
    }

    #[Test]
    public function with_data_round_trips_rich_values_through_the_snapshot_pipeline()
    {
        $snapshot = app(WithSnapshot::class)->snapshot(['released' => Carbon::parse('2026-06-10 12:00:00')]);

        $with = app(WithSnapshot::class)->resurrect($snapshot);

        $this->assertInstanceOf(Carbon::class, $with['released']);
        $this->assertSame('2026-06-10 12:00:00', $with['released']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function non_dehydratable_with_data_throws_an_island_exception()
    {
        $this->expectException(IslandException::class);
        $this->expectExceptionMessage('The with data of the {{ livewire:island }} tag could not be dehydrated');

        app(WithSnapshot::class)->snapshot(['callback' => fn () => null]);
    }

    /**
     * Snapshots persisted in cache files would put captured values on disk
     * and break fresh mounts once their release token or checksum expires.
     */
    #[Test]
    public function with_snapshots_are_stored_in_the_islands_memo_instead_of_the_cache_files()
    {
        $testable = $this->mountIslandComponent('antlers-island-with');

        $islands = $testable->snapshot['memo']['islands'] ?? [];

        $this->assertNotEmpty($islands);
        $this->assertArrayHasKey('with', $islands[0]);
        $this->assertArrayHasKey('memo', $islands[0]['with']);

        $files = File::allFiles(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringNotContainsString('json_decode', $file->getContents());
        }
    }

    #[Test]
    public function fresh_mounts_render_islands_after_a_release_token_change()
    {
        $this->mountIslandComponent('antlers-island-with');

        config()->set('livewire.release_token', 'release-2');

        $testable = $this->mountIslandComponent('antlers-island-with');

        $testable->assertSee('Hi World!');
    }

    #[Test]
    public function always_island_is_rendered_on_subsequent_renders()
    {
        $testable = $this->mountIslandComponent('antlers-island-always');

        $testable->call('$refresh');

        $testable->assertSee('Hello World!');
        $testable->assertDontSeeHtml('mode=skip');
    }

    #[Test]
    public function skipped_island_renders_placeholder_on_mount()
    {
        $testable = $this->mountIslandComponent('antlers-island-skip');

        $testable->assertSee('Skipped for now...');
        $testable->assertDontSee('Hello World!');
        $testable->assertDontSeeHtml('__lazyLoadIsland');
    }

    #[Test]
    public function nested_island_keeps_its_placeholder()
    {
        $testable = $this->mountIslandComponent('antlers-island-nested');

        $testable->assertSee('Outer island content');
        $testable->assertSee('Loading inner...');
        $testable->assertDontSee('Inner island content');
        $testable->assertSeeHtml('wire:init="__lazyLoadIsland"');
        $testable->assertSeeHtml('name=inner');
    }

    #[Test]
    public function aliased_island_tags_extract_placeholders_across_nesting()
    {
        $testable = $this->mountIslandComponent('antlers-island-alias-nested');

        $testable->assertSee('Outer island content');
        $testable->assertSee('Loading inner...');
        $testable->assertDontSee('Inner island content');
        $testable->assertSeeHtml('name=inner');
    }

    /**
     * Placeholder extraction must pair the outer tags across a nested island
     * carrying its own placeholder block instead of truncating at its closing tag.
     */
    #[Test]
    public function placeholder_containing_a_nested_island_with_its_own_placeholder_stays_intact()
    {
        $testable = $this->mountIslandComponent('antlers-island-placeholder-nested-island');

        $testable->assertSee('Loading outer...');
        $testable->assertSee('Loading inner...');
        $testable->assertDontSee('Outer island content');
        $testable->assertDontSee('Inner island content');
        $testable->assertSeeHtml('name=inner');
    }

    /**
     * Nested island tags only run while their containing island renders, so
     * the regeneration pass has to descend into the outer island's view.
     */
    #[Test]
    public function nested_island_cache_files_are_regenerated_when_missing()
    {
        $testable = $this->mountIslandComponent('antlers-island-nested');

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $testable->call('refreshInner');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Inner island content', $fragments[0]);
    }

    #[Test]
    public function directive_with_data_is_available_in_the_island()
    {
        $testable = $this->mountIslandComponent('antlers-island-with');

        $testable->assertSee('Hi World!');

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hi World!', $fragments[0]);
    }

    #[Test]
    public function directive_with_data_can_be_an_inline_array()
    {
        $testable = $this->mountIslandComponent('antlers-island-with-inline');

        $testable->assertSee('Inline World!');
    }

    #[Test]
    public function runtime_with_data_overrides_directive_with_data()
    {
        $testable = $this->mountIslandComponent('antlers-island-with');

        $testable->call('refreshStatsWithRuntimeData');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Runtime World!', $fragments[0]);
    }

    /**
     * Islands build their context through IslandRenderer instead of the render
     * hooks, so cascade and computed availability is asserted on the mount
     * render and on a PHP-triggered island render separately.
     */
    #[Test]
    public function cascade_and_computed_properties_are_available_inside_islands()
    {
        $component = new
        #[Cascade]
        class extends Component
        {
            public function refreshStats(): void
            {
                $this->renderIsland('stats');
            }

            #[Computed]
            public function label(): string
            {
                return 'Computed label';
            }

            public function render()
            {
                return view('antlers-island-cascade-computed');
            }
        };

        Livewire::component('antlers-island-cascade-computed-component', $component::class);

        $testable = Livewire::test('antlers-island-cascade-computed-component');

        $testable->assertSee('Computed: Computed label');
        $testable->assertSee('Environment: testing');

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Computed: Computed label', $fragments[0]);
        $this->assertStringContainsString('Environment: testing', $fragments[0]);
    }

    /**
     * A cascade key colliding with a computed property resolves to the
     * cascade value in full views, so islands resolve the same value.
     */
    #[Test]
    public function colliding_cascade_variables_override_computed_properties_inside_islands()
    {
        $component = new
        #[Cascade]
        class extends Component
        {
            public function refreshStats(): void
            {
                $this->renderIsland('stats');
            }

            #[Computed]
            public function environment(): string
            {
                return 'Computed environment';
            }

            public function render()
            {
                return view('antlers-island-cascade-computed-collision');
            }
        };

        Livewire::component('antlers-island-cascade-collision-component', $component::class);

        $testable = Livewire::test('antlers-island-cascade-collision-component');

        $testable->assertSee('Outside: testing');
        $testable->assertSee('Inside: testing');
        $testable->assertDontSee('Computed environment');

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Inside: testing', $fragments[0]);
        $this->assertStringNotContainsString('Computed environment', $fragments[0]);
    }

    /**
     * Captured "with" values land after computed properties in the island context.
     */
    #[Test]
    public function directive_with_data_overrides_computed_properties_inside_islands()
    {
        $component = new class extends Component
        {
            #[Computed]
            public function label(): string
            {
                return 'Computed label';
            }

            public function render()
            {
                return view('antlers-island-with-precedence');
            }
        };

        Livewire::component('antlers-island-with-precedence-component', $component::class);

        $testable = Livewire::test('antlers-island-with-precedence-component');

        $testable->assertSee('Label: With label');
        $testable->assertDontSee('Computed label');
    }

    #[Test]
    public function island_token_stays_stable_when_dynamic_with_data_changes()
    {
        $testable = $this->mountIslandComponent('antlers-island-with-dynamic');

        $testable->assertSee('Hi World!');

        preg_match('/token=(antlers-[a-f0-9\-]+)/', $testable->html(), $matches);
        $token = $matches[1];

        $testable->set('greeting', 'Yo');

        $testable->assertSeeHtml('token='.$token);

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('token='.$token, $fragments[0]);
        $this->assertStringContainsString('Hi World!', $fragments[0]);
    }

    /**
     * A cache clear regenerates the template but keeps the mount-time "with" values.
     */
    #[Test]
    public function island_cache_files_are_regenerated_when_missing_after_dynamic_with_data_changed()
    {
        $testable = $this->mountIslandComponent('antlers-island-with-dynamic');

        $testable->set('greeting', 'Yo');

        File::deleteDirectory(app('livewire.compiler')->cacheManager->cacheDirectory.'/islands');
        File::cleanDirectory(config('view.compiled'));

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hi World!', $fragments[0]);
    }

    #[Test]
    public function same_name_islands_render_independently()
    {
        $testable = $this->mountIslandComponent('antlers-island-same-name');

        $testable->assertSee('First stats island: World');
        $testable->assertSee('Second stats island: World');

        $testable->call('refreshStats');

        $this->assertCount(2, $testable->effects['islandFragments'] ?? []);
    }

    #[Test]
    public function same_identity_islands_keep_their_own_tokens()
    {
        $testable = $this->mountIslandComponent('antlers-island-same-identity');

        $testable->assertSee('Hi World from a twin island!');
        $testable->assertSee('Yo World from a twin island!');

        preg_match_all('/token=(antlers-[a-f0-9\-]+)/', $testable->html(), $matches);
        $tokens = array_unique($matches[1]);

        $this->assertCount(2, $tokens);

        $testable->call('$refresh');

        foreach ($tokens as $token) {
            $testable->assertSeeHtml('token='.$token);
        }
    }

    /**
     * Changing every dynamic "with" value used to collapse both twins onto
     * the first memoized token, cross-wiring their fragments.
     */
    #[Test]
    public function twin_islands_keep_their_own_tokens_when_all_dynamic_with_data_changes()
    {
        $testable = $this->mountIslandComponent('antlers-island-same-identity-dynamic');

        preg_match_all('/token=(antlers-[a-f0-9\-]+)/', $testable->html(), $matches);
        $tokens = array_values(array_unique($matches[1]));

        $this->assertCount(2, $tokens);

        $testable->set('greetingA', 'Hey');
        $testable->set('greetingB', 'Yaw');

        $testable->call('$refresh');

        foreach ($tokens as $token) {
            $testable->assertSeeHtml('token='.$token);
        }
    }

    /**
     * Tokens are independent of the island template, so clients that mounted
     * before a template edit keep addressing the same island.
     */
    #[Test]
    public function island_tokens_stay_stable_when_the_island_template_changes()
    {
        $path = $this->createEditableView('antlers-island-editable', <<<'ANTLERS'
        <div>
            <p>Outside the island</p>
            {{ livewire:island name="stats" }}
                <p>Hello {{ name }}!</p>
            {{ /livewire:island }}
        </div>
        ANTLERS);

        $testable = $this->mountIslandComponent('antlers-island-editable');

        preg_match('/token=(antlers-[a-f0-9\-]+)/', $testable->html(), $matches);
        $token = $matches[1];

        File::put($path, str_replace('Hello', 'Howdy', File::get($path)));

        $edited = $this->mountIslandComponent('antlers-island-editable');

        $edited->assertSee('Howdy World!');
        $edited->assertSeeHtml('token='.$token);

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Howdy World!', $fragments[0]);
    }

    /**
     * Tokens are scoped to the rendered view, so one view can't overwrite
     * another view's same-name island cache file.
     */
    #[Test]
    public function same_name_islands_in_different_views_keep_their_own_cache_files()
    {
        $testable = $this->mountIslandComponent();

        preg_match('/token=(antlers-[a-f0-9\-]+)/', $testable->html(), $matches);
        $token = $matches[1];

        $other = $this->mountIslandComponent('antlers-island-other-view');

        $other->assertSee('Howdy World!');

        preg_match('/token=(antlers-[a-f0-9\-]+)/', $other->html(), $otherMatches);

        $this->assertNotSame($token, $otherMatches[1]);

        $testable->call('refreshStats');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hello World!', $fragments[0]);
    }

    /**
     * Statamic's AddViewPaths middleware resolves one logical view name to
     * site-specific files, so tokens identify the resolved file per site.
     */
    #[Test]
    public function site_specific_views_sharing_a_name_keep_their_own_island_cache_files()
    {
        $finder = view()->getFinder();
        $paths = $finder->getPaths();
        $sitePaths = array_merge([package_path().'/tests/__fixtures__/views/de'], $paths);

        $finder->setPaths($sitePaths);
        $finder->flush();

        $siteSpecific = $this->mountIslandComponent('antlers-island-site');

        $siteSpecific->assertSee('Hallo from the site view!');

        $finder->setPaths($paths);
        $finder->flush();

        $base = $this->mountIslandComponent('antlers-island-site');

        $base->assertSee('Hello from the base view!');

        preg_match('/token=(antlers-[a-f0-9\-]+)/', $siteSpecific->html(), $siteMatches);
        preg_match('/token=(antlers-[a-f0-9\-]+)/', $base->html(), $baseMatches);

        $this->assertNotSame($siteMatches[1], $baseMatches[1]);

        $finder->setPaths($sitePaths);
        $finder->flush();

        $siteSpecific->call('refreshStats');

        $fragments = $siteSpecific->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Hallo from the site view!', $fragments[0]);
    }

    #[Test]
    public function placeholder_inside_an_antlers_comment_is_not_extracted()
    {
        $testable = $this->mountIslandComponent('antlers-island-commented-placeholder');

        $testable->assertDontSee('Hidden placeholder');
        $testable->assertDontSee('Hello World!');
        $testable->assertSeeHtml('wire:init="__lazyLoadIsland"');
    }

    #[Test]
    public function placeholder_inside_a_noparse_region_is_not_extracted()
    {
        $testable = $this->mountIslandComponent('antlers-island-noparse-placeholder');

        $testable->assertSee('Hello World!');
        $testable->assertSee('{{ placeholder }}Documented placeholder{{ /placeholder }}', false);
    }

    #[Test]
    public function escaped_placeholder_tags_are_not_extracted()
    {
        $testable = $this->mountIslandComponent('antlers-island-escaped-placeholder');

        $testable->assertSee('Hello World!');
        $testable->assertSee('{{ placeholder }}Documented placeholder{{ /placeholder }}', false);
    }

    /**
     * A literal island tag inside noparse must not open a protected region
     * that swallows the real placeholder behind it.
     */
    #[Test]
    public function island_tag_inside_a_noparse_region_does_not_shift_placeholder_extraction()
    {
        $testable = $this->mountIslandComponent('antlers-island-noparse-island-example');

        $testable->assertSee('Loading stats...');
        $testable->assertDontSee('Hello World!');
        $testable->assertSeeHtml('wire:intersect.once="__lazyLoadIsland"');
    }

    #[Test]
    public function empty_island_renders_and_can_be_targeted_from_php()
    {
        $component = new class extends Component
        {
            public function refreshLogs(): void
            {
                $this->renderIsland('logs', content: '<p>Log line</p>');
            }

            public function render()
            {
                return view('antlers-island-empty');
            }
        };

        Livewire::component('antlers-island-empty-component', $component::class);

        $testable = Livewire::test('antlers-island-empty-component');

        $testable->assertSeeHtml('type=island|name=logs|token=antlers-');

        $testable->call('refreshLogs');

        $fragments = $testable->effects['islandFragments'] ?? [];

        $this->assertCount(1, $fragments);
        $this->assertStringContainsString('Log line', $fragments[0]);
    }

    #[Test]
    public function island_tag_requires_a_name()
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('The {{ livewire:island }} tag requires a name parameter.');

        $this->mountIslandComponent('antlers-island-nameless');
    }

    /**
     * "0" is falsy, so the name presence check must not mistake it for a
     * missing parameter.
     */
    #[Test]
    public function island_name_may_be_zero()
    {
        $testable = $this->mountIslandComponent('antlers-island-zero-name');

        $testable->assertSee('Hello World!');
        $testable->assertSeeHtml('type=island|name=0|token=antlers-');
    }

    #[Test]
    public function island_name_may_not_contain_special_characters()
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('The {{ livewire:island }} name may only contain letters, numbers, underscores, dashes and dots.');

        $this->mountIslandComponent('antlers-island-invalid-name');
    }

    #[Test]
    public function island_with_parameter_has_to_be_an_array()
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('The with parameter of the {{ livewire:island }} tag has to be an array.');

        $this->mountIslandComponent('antlers-island-with-scalar');
    }

    #[Test]
    public function island_tag_can_only_be_used_inside_a_livewire_component_view()
    {
        $this->expectException(IslandException::class);
        $this->expectExceptionMessage('The {{ livewire:island }} tag can only be used inside the Antlers view of a Livewire component.');

        view('antlers-island')->render();
    }

    /**
     * The view name is passed as a mount parameter so it survives Livewire
     * re-instantiating the component on every request.
     */
    protected function mountIslandComponent(string $view = 'antlers-island'): Testable
    {
        $component = new class extends Component
        {
            public string $viewName = 'antlers-island';

            public string $name = 'World';

            public string $greeting = 'Hi';

            public string $greetingA = 'Hi';

            public string $greetingB = 'Yo';

            public array $withData = ['greeting' => 'Hi'];

            public function refreshStats(): void
            {
                $this->renderIsland('stats');
            }

            public function refreshStatsWithRuntimeData(): void
            {
                $this->renderIsland('stats', with: ['greeting' => 'Runtime']);
            }

            public function refreshInner(): void
            {
                $this->renderIsland('inner');
            }

            #[Computed]
            public function showStats(): bool
            {
                return true;
            }

            public function render()
            {
                return view($this->viewName);
            }
        };

        Livewire::component('antlers-island-component', $component::class);

        return Livewire::test('antlers-island-component', ['viewName' => $view]);
    }

    /**
     * The island only renders while "show" is true, so cache file
     * regeneration depends on the state the incoming request produces.
     */
    protected function mountConditionalIslandComponent(): Testable
    {
        $component = new class extends Component
        {
            public string $name = 'World';

            public bool $show = true;

            public function updatedShow(): void
            {
                if ($this->show) {
                    $this->renderIsland('stats');
                }
            }

            public function reveal(): void
            {
                $this->show = true;

                $this->renderIsland('stats');
            }

            public function refreshStats(): void
            {
                $this->renderIsland('stats');
            }

            public function render()
            {
                return view('antlers-island-conditional');
            }
        };

        Livewire::component('antlers-island-conditional-component', $component::class);

        return Livewire::test('antlers-island-conditional-component');
    }

    /**
     * Writes a view the test can edit in place without touching the shared fixtures.
     */
    protected function createEditableView(string $name, string $contents): string
    {
        $directory = storage_path('antlers-islands-test-views');

        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory);

        view()->addLocation($directory);

        File::put($path = $directory.'/'.$name.'.antlers.html', $contents);

        return $path;
    }
}
