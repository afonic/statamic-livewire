<?php

namespace MarcoRieser\Livewire\Hooks;

use Illuminate\Contracts\View\View;
use Livewire\ComponentHook;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportIslands\Compiler\IslandCompiler;
use Livewire\Mechanisms\HandleComponents\ViewContext;
use MarcoRieser\Livewire\Islands\IslandManager;

use function Livewire\trigger;
use function Livewire\wrap;

/**
 * Re-renders the component's Antlers view when island cache files have been
 * cleared, so the {{ livewire:island }} tags rewrite them before Livewire
 * looks for them.
 */
class AntlersIslandsRegenerator extends ComponentHook
{
    protected static bool $regenerating = false;

    /**
     * Each render pass registers its view and counts island occurrences from zero.
     */
    public function render($view, $data): void
    {
        app(IslandManager::class)->startRenderPass(
            $this->component,
            $view instanceof View ? (string) $view->name() : '',
        );
    }

    /**
     * Fires right before Livewire renders an island view, so regeneration
     * sees the state that island render is about to use — after incoming
     * property updates and mid-method state changes.
     */
    public function renderIsland($name, $view, $properties): void
    {
        $this->regenerateMissingIslandCacheFiles();
    }

    /**
     * Lazy islands mount through __lazyLoadIsland inside a window where the
     * renderIsland hook has to stay inactive, so their cache files are
     * ensured before that window opens.
     */
    public function call($method, $params, $returnEarly, $metadata): void
    {
        if ($method === '__lazyLoadIsland') {
            $this->regenerateMissingIslandCacheFiles();
        }
    }

    /**
     * Skipped while islands mount (their tags just wrote the cache files and
     * a regeneration render would re-store them) and while regenerating
     * (replayed island renders re-enter through the renderIsland hook).
     */
    protected function regenerateMissingIslandCacheFiles(): void
    {
        if (static::$regenerating || $this->component->islandIsMounting()) {
            return;
        }

        $islands = $this->component->getIslands();

        $missing = collect($islands)
            ->filter(fn (array $island) => str_starts_with($island['token'] ?? '', 'antlers-'))
            ->contains(fn (array $island) => ! file_exists(IslandCompiler::getCachedPathFromToken($island['token'])));

        if (! $missing) {
            return;
        }

        static::$regenerating = true;

        try {
            $this->regenerateAntlersIslandCacheFiles($islands);
        } finally {
            static::$regenerating = false;
        }
    }

    /**
     * Renders through Livewire's render trigger so component hooks provide
     * the same view data as on a regular render.
     *
     * @param  array<int, array{name: string, token: string}>  $islands
     */
    protected function regenerateAntlersIslandCacheFiles(array $islands): void
    {
        $view = $this->resolveComponentView();

        if ($view instanceof View) {
            $properties = Utils::getPublicPropertiesDefinedOnSubclass($this->component);

            $view->with(array_merge($properties, ['__livewire' => $this->component]));

            $finish = trigger('render', $this->component, $view, $properties);

            $viewContext = new ViewContext;

            $html = $view->render(fn ($view) => $viewContext->extractFromEnvironment($view->getFactory()));

            $replaceHtml = function ($newHtml) use (&$html) {
                $html = $newHtml;
            };

            $finish($html, $replaceHtml, $viewContext);
        }

        $this->regenerateNestedIslandCacheFiles($islands);
    }

    /**
     * Components may define render() or provide their view through view().
     */
    protected function resolveComponentView(): ?View
    {
        if (method_exists($this->component, 'render')) {
            $view = wrap($this->component)->render();
        } elseif ($this->component->hasProvidedView()) {
            $view = $this->component->getProvidedView();
        } else {
            $view = null;
        }

        return $view instanceof View ? $view : null;
    }

    /**
     * Nested island tags only execute while their containing island renders,
     * so islands whose cache files exist are rendered (output discarded)
     * until every file is back, one nesting level per round.
     *
     * @param  array<int, array{name: string, token: string}>  $islands
     */
    protected function regenerateNestedIslandCacheFiles(array $islands): void
    {
        $islands = collect($islands)
            ->filter(fn (array $island) => str_starts_with($island['token'] ?? '', 'antlers-'));

        $rendered = [];

        while ($islands->contains(fn (array $island) => ! file_exists(IslandCompiler::getCachedPathFromToken($island['token'])))) {
            $renderable = $islands->filter(fn (array $island) => ! in_array($island['token'], $rendered)
                && file_exists(IslandCompiler::getCachedPathFromToken($island['token'])));

            if ($renderable->isEmpty()) {
                return;
            }

            $renderable->each(function (array $island) use (&$rendered) {
                $rendered[] = $island['token'];

                $this->component->renderIslandView($island['name'], $island['token']);
            });
        }
    }
}
