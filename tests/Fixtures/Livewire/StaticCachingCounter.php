<?php

namespace MarcoRieser\Livewire\Tests\Fixtures\Livewire;

use Livewire\Component;

class StaticCachingCounter extends Component
{
    public int $count = 0;

    public function render()
    {
        return view('livewire.static-caching-counter');
    }
}
