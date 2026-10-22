<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Infra\Models\InfraTask;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class ListTasksTool implements ToolContract, ToolMetadataContract
{
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.tasks.GET';
    }

    public function getDescription(): string
    {
        return 'GET /infra/tasks - Maßnahmen (Board) der Infrastruktur. Default: nicht erledigte. Optional: status, server_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => array_merge(InfraTask::STATUSES, ['all'])],
                'server_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveRootTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $status = $arguments['status'] ?? null;
            $rows = InfraTask::with(['server:id,name', 'finding:id,status'])
                ->where('team_id', $resolved['team_id'])
                ->when($status === null, fn ($q) => $q->notDone())
                ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
                ->when(!empty($arguments['server_id']), fn ($q) => $q->where('infra_server_id', (int) $arguments['server_id']))
                ->orderBy('status')->orderBy('position')
                ->limit(300)->get();

            return ToolResult::success(['tasks' => $rows->map(fn (InfraTask $t) => $t->toAgentArray())->all(), 'count' => $rows->count()]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Maßnahmen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'read', 'tags' => ['infra', 'tasks', 'list'], 'read_only' => true, 'requires_auth' => true,
            'requires_team' => true, 'risk_level' => 'safe', 'idempotent' => true];
    }
}
