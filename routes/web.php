<?php

use Platform\Infra\Livewire\Dashboard;
use Platform\Infra\Livewire\Server\Show as ServerShow;

Route::get('/', Dashboard::class)->name('infra.dashboard');
Route::get('/servers/{server}', ServerShow::class)->name('infra.servers.show');
