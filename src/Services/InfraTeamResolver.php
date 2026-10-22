<?php

namespace Platform\Infra\Services;

/**
 * Infra is root-scoped: whoever acts (human in the UI, agent via API) works on the
 * servers of the root team of their current team.
 */
class InfraTeamResolver
{
    public static function rootTeamId(?object $user): ?int
    {
        if (!$user) {
            return null;
        }
        $team = $user->currentTeamRelation ?? null;
        if (!$team) {
            $team = method_exists($user, 'teams') ? $user->teams()->first() : null;
        }
        if (!$team) {
            return null;
        }

        return (int) (method_exists($team, 'getRootTeam') ? $team->getRootTeam()->id : $team->id);
    }
}
