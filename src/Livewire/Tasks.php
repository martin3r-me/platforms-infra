<?php

namespace Platform\Infra\Livewire;

use Livewire\Component;

/** Organisation-wide measures board (all servers). */
class Tasks extends Component
{
    public function render()
    {
        return view('infra::livewire.tasks')->layout('platform::layouts.app');
    }
}
