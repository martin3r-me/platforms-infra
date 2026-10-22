<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraLogEntry;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class GetServerTool implements ToolContract, ToolMetadataContract
{
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.server.GET';
    }

    public function getDescription(): string
    {
        return 'GET /infra/servers/{id} - Ein Server mit letzten Messwerten, offenen Befunden und den jüngsten Fehler-Logs (24 h).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID.'],
                'server_id' => ['type' => 'integer', 'description' => 'ID des Servers (ERFORDERLICH).'],
            ],
            'required' => ['server_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveRootTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }

            $server = InfraServer::where('team_id', $resolved['team_id'])->withCount($this->openCounts())->find((int) ($arguments['server_id'] ?? 0));
            if (!$server) {
                return ToolResult::error('NOT_FOUND', 'Server nicht gefunden.');
            }

            $findings = $server->findings()->open()
                ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
                ->limit(100)->get()
                ->map(fn (InfraFinding $f) => $f->toAgentArray())->all();

            $errors = $server->logEntries()
                ->whereIn('level', InfraLogEntry::ERROR_LEVELS)
                ->where('occurred_at', '>=', now()->subDay())
                ->orderByDesc('occurred_at')->limit(50)->get(['level', 'source', 'message', 'occurred_at'])
                ->map(fn ($l) => [
                    'level' => $l->level,
                    'source' => $l->source,
                    'message' => mb_substr($l->message, 0, 500),
                    'occurred_at' => $l->occurred_at?->toIso8601String(),
                ])->all();

            return ToolResult::success([
                'server' => $this->serverPayload($server),
                'open_findings' => $findings,
                'recent_errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Servers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['infra', 'servers', 'get'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
