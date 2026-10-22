<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class ListFindingsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.findings.GET';
    }

    public function getDescription(): string
    {
        return 'GET /infra/findings - Befunde (deduplizierte Probleme je Server+Prüfung). Default: offene, nach Schwere sortiert. Optional: status (open|resolved|accepted|all), server_id, severity.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['open', 'resolved', 'accepted', 'acknowledged', 'all']],
                'server_id' => ['type' => 'integer'],
                'severity' => ['type' => 'string', 'enum' => InfraFinding::SEVERITIES],
                'limit' => ['type' => 'integer', 'description' => 'Default 100, max 500'],
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

            $status = $arguments['status'] ?? 'open';
            $query = InfraFinding::with('server:id,name')->where('team_id', $resolved['team_id']);
            if ($status === 'open') {
                $query->open();
            } elseif ($status !== 'all') {
                $query->where('status', $status);
            }
            if (!empty($arguments['server_id'])) {
                $query->where('infra_server_id', (int) $arguments['server_id']);
            }
            if (!empty($arguments['severity'])) {
                $query->where('severity', $arguments['severity']);
            }

            $rows = $query->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
                ->orderByDesc('last_seen_at')
                ->limit(min(500, max(1, (int) ($arguments['limit'] ?? 100))))
                ->get();

            return ToolResult::success([
                'findings' => $rows->map(fn (InfraFinding $f) => $f->toAgentArray())->all(),
                'count' => $rows->count(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Befunde: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['infra', 'findings', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
