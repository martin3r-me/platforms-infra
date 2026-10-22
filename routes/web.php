<?php

use Platform\Infra\Livewire\Dashboard;
use Platform\Infra\Livewire\Server\Show as ServerShow;
use Platform\Infra\Livewire\Tasks;

Route::get('/', Dashboard::class)->name('infra.dashboard');
Route::get('/servers/{server}', ServerShow::class)->name('infra.servers.show');
Route::get('/tasks', Tasks::class)->name('infra.tasks');
