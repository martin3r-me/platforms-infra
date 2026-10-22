<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Infra\Models\InfraTask;
use Platform\Infra\Services\InfraTaskService;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class CreateTaskTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.tasks.POST';
    }

    public function getDescription(): string
    {
        return 'POST /infra/tasks - Legt eine Maßnahme an, optional an einem Server und/oder aus einem Befund (finding_id; dann sind Titel/Server/Priorität vorbelegt).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'title' => ['type' => 'string', 'description' => 'Titel (erforderlich ohne finding_id)'],
                'description' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => InfraTask::PRIORITIES],
                'server_id' => ['type' => 'integer'],
                'finding_id' => ['type' => 'integer'],
                'user_in_charge_id' => ['type' => 'integer'],
                'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveRootTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            if (empty($arguments['title']) && empty($arguments['finding_id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'title oder finding_id ist erforderlich.');
            }
            $task = app(InfraTaskService::class)->create($resolved['team_id'], $arguments, $context->user->id);

            return ToolResult::success(['task' => $task->fresh(['server', 'finding'])->toAgentArray()]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'category' => 'action', 'tags' => ['infra', 'tasks', 'create'], 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'idempotent' => false];
    }
}
