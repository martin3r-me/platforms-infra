<?php

namespace Platform\Infra\Policies;

use Platform\Core\Models\User;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Services\InfraAccess;

/** Server: Ersteller ODER im Org-Graphen erreichbar (siehe InfraAccess). */
class InfraServerPolicy
{
    public function view(User $user, InfraServer $server): bool
    {
        return app(InfraAccess::class)->canServer($user, $server, 'read');
    }

    public function update(User $user, InfraServer $server): bool
    {
        return app(InfraAccess::class)->canServer($user, $server, 'write');
    }

    public function delete(User $user, InfraServer $server): bool
    {
        return app(InfraAccess::class)->canServer($user, $server, 'manage');
    }

    public function create(User $user): bool
    {
        // Anlegen ist frei — der Server gehört seinem Ersteller, bis er verortet wird.
        return $user->currentTeam !== null;
    }
}
