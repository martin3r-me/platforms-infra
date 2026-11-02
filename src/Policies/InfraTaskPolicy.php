<?php

namespace Platform\Infra\Policies;

use Platform\Core\Models\User;
use Platform\Infra\Models\InfraTask;
use Platform\Infra\Services\InfraAccess;

/** Maßnahmen: Ersteller ODER über ihren Server (siehe InfraAccess). */
class InfraTaskPolicy
{
    public function view(User $user, InfraTask $task): bool
    {
        return app(InfraAccess::class)->canTask($user, $task, 'read');
    }

    public function update(User $user, InfraTask $task): bool
    {
        return app(InfraAccess::class)->canTask($user, $task, 'write');
    }

    public function delete(User $user, InfraTask $task): bool
    {
        return app(InfraAccess::class)->canTask($user, $task, 'write');
    }

    public function create(User $user): bool
    {
        return $user->currentTeam !== null;
    }
}
