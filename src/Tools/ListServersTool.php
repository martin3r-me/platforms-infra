<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class ListServersTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.servers.GET';
    }

    public function getDescription(): string
    {
        return 'GET /infra/servers - Listet die Server der Organisation mit Zustand (ok/warning/critical/stale/unknown), letzter Meldung und offenen Befunden. Optional: status, search.';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas($this->getStandardGetSchema(), [
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID. Default: aktuelles Team (es gilt dessen Root-Team).'],
                'status' => ['type' => 'string', 'enum' => InfraServer::STATUSES, 'description' => 'Optional: nach Status filtern. Default: aktiv + Wartung.'],
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

            $query = InfraServer::where('team_id', $resolved['team_id'])->withCount($this->openCounts());
            if (!empty($arguments['status'])) {
                $query->where('status', $arguments['status']);
            } else {
                $query->watched();
            }
            $this->applyStandardSearch($query, $arguments, ['name', 'hostname', 'role', 'ip_public', 'ip_private']);
            $this->applyStandardSort($query, $arguments, ['name', 'last_seen_at', 'created_at'], 'name', 'asc');
            $result = $this->applyStandardPaginationResult($query, $arguments);

            return ToolResult::success([
                'servers' => $result['data']->map(fn (InfraServer $s) => $s->toAgentArray() + [
                    'open_critical' => (int) $s->open_critical_count,
                    'open_warning' => (int) $s->open_warning_count,
                ])->values()->all(),
                'pagination' => $result['pagination'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Server: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['infra', 'servers', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
