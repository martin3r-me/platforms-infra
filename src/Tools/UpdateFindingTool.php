<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class UpdateFindingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.findings.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /infra/findings/{id} - Setzt den Status eines Befunds: acknowledged (in Arbeit), resolved (behoben), accepted (bewusst toleriert), open (wieder öffnen). Optional mit Notiz.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'finding_id' => ['type' => 'integer', 'description' => 'ID des Befunds (ERFORDERLICH)'],
                'status' => ['type' => 'string', 'enum' => InfraFinding::STATUSES, 'description' => 'Neuer Status (ERFORDERLICH)'],
                'note' => ['type' => 'string', 'description' => 'Was getan wurde / warum toleriert'],
            ],
            'required' => ['finding_id', 'status'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveRootTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            if (!in_array($arguments['status'] ?? null, InfraFinding::STATUSES, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'status muss einer von: ' . implode(', ', InfraFinding::STATUSES) . ' sein.');
            }
            $finding = InfraFinding::where('team_id', $resolved['team_id'])->find((int) ($arguments['finding_id'] ?? 0));
            if (!$finding) {
                return ToolResult::error('NOT_FOUND', 'Befund nicht gefunden.');
            }

            $finding->transition($arguments['status'], $context->user->id, $arguments['note'] ?? null);

            return ToolResult::success(['finding' => $finding->fresh('server')->toAgentArray()]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['infra', 'findings', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
