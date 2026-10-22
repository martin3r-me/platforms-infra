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

class UpdateTaskTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.tasks.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /infra/tasks/{id} - Ändert eine Maßnahme (Status open|in_progress|waiting|done, Titel, Priorität, Verantwortlich, Fälligkeit, Ergebnis-Notiz).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'task_id' => ['type' => 'integer', 'description' => 'ID der Maßnahme (ERFORDERLICH)'],
                'status' => ['type' => 'string', 'enum' => InfraTask::STATUSES],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => InfraTask::PRIORITIES],
                'user_in_charge_id' => ['type' => 'integer'],
                'due_date' => ['type' => 'string'],
                'done_note' => ['type' => 'string'],
            ],
            'required' => ['task_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveRootTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $task = InfraTask::where('team_id', $resolved['team_id'])->find((int) ($arguments['task_id'] ?? 0));
            if (!$task) {
                return ToolResult::error('NOT_FOUND', 'Maßnahme nicht gefunden.');
            }
            if (isset($arguments['priority']) && !in_array($arguments['priority'], InfraTask::PRIORITIES, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'priority ungültig.');
            }
            $task->fill(array_intersect_key($arguments, array_flip(['title', 'description', 'priority', 'user_in_charge_id', 'due_date', 'done_note'])));
            $task->save();
            if (isset($arguments['status']) && $arguments['status'] !== $task->status) {
                if (!in_array($arguments['status'], InfraTask::STATUSES, true)) {
                    return ToolResult::error('VALIDATION_ERROR', 'status ungültig.');
                }
                app(InfraTaskService::class)->move($task, $arguments['status'], $arguments['done_note'] ?? null);
            }

            return ToolResult::success(['task' => $task->fresh(['server', 'finding'])->toAgentArray()]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'category' => 'action', 'tags' => ['infra', 'tasks', 'update'], 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'idempotent' => true];
    }
}
