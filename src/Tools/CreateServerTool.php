<?php

namespace Platform\Infra\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Tools\Concerns\ResolvesInfraTeam;

class CreateServerTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesInfraTeam;

    public const FIELDS = ['hostname', 'role', 'environment', 'provider', 'provider_ref', 'ip_public', 'ip_private', 'ssh_user', 'ssh_port', 'status', 'notes', 'user_in_charge_id'];

    public function getName(): string
    {
        return 'infra.servers.POST';
    }

    public function getDescription(): string
    {
        return 'POST /infra/servers - Legt einen Server im Bestand an (Name ist je Organisation eindeutig).';
    }

    public static function fieldSchema(): array
    {
        return [
            'hostname' => ['type' => 'string'],
            'role' => ['type' => 'string', 'description' => 'z. B. Web, Datenbank, GPU'],
            'environment' => ['type' => 'string', 'enum' => InfraServer::ENVIRONMENTS],
            'provider' => ['type' => 'string', 'enum' => InfraServer::PROVIDERS],
            'provider_ref' => ['type' => 'string', 'description' => 'ID beim Anbieter'],
            'ip_public' => ['type' => 'string'],
            'ip_private' => ['type' => 'string'],
            'ssh_user' => ['type' => 'string'],
            'ssh_port' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => InfraServer::STATUSES],
            'notes' => ['type' => 'string'],
            'user_in_charge_id' => ['type' => 'integer', 'description' => 'User-ID der verantwortlichen Person/des Agenten'],
        ];
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => ['team_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'description' => 'Name (ERFORDERLICH)']] + self::fieldSchema(),
            'required' => ['name'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveRootTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $name = trim((string) ($arguments['name'] ?? ''));
            if ($name === '') {
                return ToolResult::error('VALIDATION_ERROR', 'name ist erforderlich.');
            }
            if (InfraServer::where('team_id', $resolved['team_id'])->where('name', $name)->exists()) {
                return ToolResult::error('DUPLICATE', "Server \"{$name}\" existiert bereits.");
            }
            foreach (['environment' => InfraServer::ENVIRONMENTS, 'provider' => InfraServer::PROVIDERS, 'status' => InfraServer::STATUSES] as $k => $allowed) {
                if (isset($arguments[$k]) && !in_array($arguments[$k], $allowed, true)) {
                    return ToolResult::error('VALIDATION_ERROR', "{$k} muss einer von: " . implode(', ', $allowed) . ' sein.');
                }
            }

            $server = InfraServer::create(array_intersect_key($arguments, array_flip(self::FIELDS)) + [
                'team_id' => $resolved['team_id'],
                'name' => $name,
                'created_by_user_id' => $context->user->id,
            ]);

            return ToolResult::success(['server' => $this->serverPayload($server->fresh())]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['infra', 'servers', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
