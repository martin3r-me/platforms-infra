<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class UpdateServerTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesInfraTeam;

    public function getName(): string
    {
        return 'infra.servers.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /infra/servers/{id} - Ändert Stammdaten eines Servers (z. B. Rolle, IPs, Status, Verantwortlich). Nur übergebene Felder werden geändert.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'server_id' => ['type' => 'integer', 'description' => 'ID des Servers (ERFORDERLICH)'],
                'name' => ['type' => 'string'],
            ] + CreateServerTool::fieldSchema(),
            'required' => ['server_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveRootTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $server = InfraServer::where('team_id', $resolved['team_id'])->find((int) ($arguments['server_id'] ?? 0));
            if (!$server) {
                return ToolResult::error('NOT_FOUND', 'Server nicht gefunden.');
            }
            foreach (['environment' => InfraServer::ENVIRONMENTS, 'provider' => InfraServer::PROVIDERS, 'status' => InfraServer::STATUSES] as $k => $allowed) {
                if (isset($arguments[$k]) && !in_array($arguments[$k], $allowed, true)) {
                    return ToolResult::error('VALIDATION_ERROR', "{$k} muss einer von: " . implode(', ', $allowed) . ' sein.');
                }
            }

            $changes = array_intersect_key($arguments, array_flip(array_merge(CreateServerTool::FIELDS, ['name'])));
            if (isset($changes['name'])) {
                $changes['name'] = trim((string) $changes['name']);
                $taken = InfraServer::where('team_id', $resolved['team_id'])->where('name', $changes['name'])->where('id', '!=', $server->id)->exists();
                if ($changes['name'] === '' || $taken) {
                    return ToolResult::error('VALIDATION_ERROR', 'Name ist leer oder bereits vergeben.');
                }
            }
            $server->update($changes);

            return ToolResult::success(['server' => $this->serverPayload($server->fresh()), 'changed' => array_keys($changes)]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['infra', 'servers', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
