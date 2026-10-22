<?php

namespace Platform\Infra\Tools\Concerns;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraServer;

/** Infra is root-scoped: resolve the caller's team, check membership, act on its root team. */
trait ResolvesInfraTeam
{
    /** @return array{team_id:?int, error:?ToolResult} */
    protected function resolveRootTeam(array $arguments, ToolContext $context): array
    {
        if (!$context->user) {
            return ['team_id' => null, 'error' => ToolResult::error('AUTH_ERROR', 'Kein User im Kontext gefunden.')];
        }

        $teamId = $arguments['team_id'] ?? $context->team?->id;
        $team = $teamId ? Team::find((int) $teamId) : null;
        if (!$team) {
            return ['team_id' => null, 'error' => ToolResult::error('MISSING_TEAM', 'Kein Team angegeben und kein Team im Kontext gefunden. Nutze "core.teams.GET".')];
        }
        if (!$context->user->teams()->where('teams.id', $team->id)->exists()) {
            return ['team_id' => null, 'error' => ToolResult::error('ACCESS_DENIED', 'Du hast keinen Zugriff auf dieses Team.')];
        }

        return ['team_id' => (int) $team->getRootTeam()->id, 'error' => null];
    }

    protected function serverPayload(InfraServer $s): array
    {
        return $s->toAgentArray() + [
            'notes' => $s->notes,
            'os' => $s->os,
            'user_in_charge_id' => $s->user_in_charge_id,
            'last_report' => $s->last_report,
            'url' => route('infra.servers.show', $s),
        ];
    }

    protected function openCounts(): array
    {
        return [
            'findings as open_critical_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_CRITICAL),
            'findings as open_warning_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_WARNING),
        ];
    }
}
