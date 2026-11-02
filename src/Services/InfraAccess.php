<?php

namespace Platform\Infra\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Authz\AuthzResolver;
use Platform\Core\Models\User;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Models\InfraTask;

/**
 * Zugriff auf Server und Maßnahmen — wie Planner-Projekte: Ersteller (owns) ODER
 * strukturell im Org-Graphen erreichbar (may). read < write < manage.
 *
 * Sonderfall „unbeansprucht": Server, die der Observer-Agent angemeldet hat (Ersteller
 * fehlt, ist ein KI-User oder der Bot-User einer Agent-Einheit wie Jazz) und die noch nirgends in der Orga hängen. Die sieht und
 * bearbeitet jeder infra-Nutzer des Root-Teams — sonst könnte sie niemand übernehmen
 * oder aufhängen. Löschen (manage) bleibt dem Graphen bzw. Ersteller vorbehalten.
 *
 * Befunde, Logs und Metriken gehören zum Server und folgen ihm. Maßnahmen: Ersteller
 * ODER über ihren Server; ohne Server und ohne menschlichen Ersteller wie unbeansprucht.
 */
class InfraAccess
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private AuthzResolver $resolver)
    {
    }

    public function canServer(User $user, InfraServer $server, string $cap): bool
    {
        if (! $server->getKey()) {
            return false;
        }

        return $this->cache["s:{$user->id}:{$server->id}:{$cap}"] ??= (
            $this->resolver->owns($user, InfraServer::class, (int) $server->id)
            || $this->resolver->may($user, $cap, InfraServer::class, (int) $server->id)
            || ($cap !== 'manage' && $this->isUnclaimed($server))
        );
    }

    public function canTask(User $user, InfraTask $task, string $cap): bool
    {
        if (! $task->getKey()) {
            return false;
        }

        return $this->cache["t:{$user->id}:{$task->id}:{$cap}"] ??= (
            $this->resolver->owns($user, InfraTask::class, (int) $task->id)
            || ($task->server ? $this->canServer($user, $task->server, $cap) : ($cap !== 'manage' && ! $this->hasHumanCreator($task->created_by_user_id)))
        );
    }

    /**
     * Unbeansprucht = kein menschlicher Ersteller und nirgends in der Orga aufgehängt.
     */
    public function isUnclaimed(InfraServer $server): bool
    {
        return $this->cache["u:{$server->id}"] ??= (
            ! $this->hasHumanCreator($server->created_by_user_id)
            && ! DB::table('authz_resource_link')
                ->where('resource_type', InfraServer::class)
                ->where('resource_id', $server->id)
                ->exists()
        );
    }

    /** Sichtbare Server einer bereits geladenen Liste. */
    public function visibleServers(User $user, Collection $servers): Collection
    {
        return $servers->filter(fn (InfraServer $s) => $this->canServer($user, $s, 'read'))->values();
    }

    /** IDs aller Server des Teams, die der User sehen darf (für Befund-/Log-/Maßnahmen-Abfragen). */
    public function visibleServerIds(User $user, int $teamId): array
    {
        return $this->visibleServers($user, InfraServer::where('team_id', $teamId)->get())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Sichtbare Maßnahmen einer bereits geladenen Liste. */
    public function visibleTasks(User $user, Collection $tasks): Collection
    {
        return $tasks->filter(fn (InfraTask $t) => $this->canTask($user, $t, 'read'))->values();
    }

    /**
     * Server übernehmen: nur ein unbeanspruchter Server bekommt den User als Ersteller.
     */
    public function claim(User $user, InfraServer $server): bool
    {
        if (! $this->hasHumanCreator((int) $user->id) || ! $this->isUnclaimed($server)) {
            return false;
        }
        $server->created_by_user_id = $user->id;
        $server->save();
        $this->cache = [];

        return true;
    }

    /**
     * Ein Mensch wird verantwortlich für einen Server ohne menschlichen Ersteller →
     * er wird Ersteller (sonst bliebe der Server ohne Besitzer, der ihn aufhängen kann).
     */
    public function adoptByResponsible(InfraServer $server): void
    {
        if ($server->user_in_charge_id && ! $this->hasHumanCreator($server->created_by_user_id)
            && $this->hasHumanCreator($server->user_in_charge_id)) {
            $server->created_by_user_id = $server->user_in_charge_id;
            $this->cache = [];
        }
    }

    private function hasHumanCreator(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        return $this->cache["h:{$userId}"] ??= (
            User::whereKey($userId)->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'ai_user'))->exists()
            && ! $this->isAgentUser($userId)
        );
    }

    /** Bot-User eines Agenten: hängt an einer Org-Einheit vom Typ „agent" (z. B. Jazz). */
    private function isAgentUser(int $userId): bool
    {
        if (! class_exists(\Platform\Organization\Models\OrganizationEntity::class)) {
            return false;
        }

        return \Platform\Organization\Models\OrganizationEntity::query()
            ->where('linked_user_id', $userId)
            ->agents()
            ->exists();
    }
}
