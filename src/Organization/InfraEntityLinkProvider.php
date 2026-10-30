<?php

namespace Platform\Infra\Organization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Models\InfraTask;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Organization\Contracts\HasMetricDefinitions;

/**
 * Server lassen sich per DimensionLink in die Orga hängen und sind zeitbuchbar
 * (Kontext für Zeiteinträge) — analog zu Planner-Projekten.
 */
class InfraEntityLinkProvider implements EntityLinkProvider, HasMetricDefinitions
{
    public function morphAliases(): array
    {
        return ['infra_server', 'infra_task', 'infra_finding'];
    }

    public function linkTypeConfig(): array
    {
        return [
            'infra_server' => ['label' => 'Server', 'singular' => 'Server', 'icon' => 'server', 'route' => 'infra.servers.show'],
            // Nur für den Aktivitätsstrom (Labels) — gehängt wird der Server, nicht seine Kinder.
            'infra_task' => ['label' => 'Maßnahmen', 'singular' => 'Maßnahme', 'icon' => 'wrench-screwdriver', 'route' => null],
            'infra_finding' => ['label' => 'Befunde', 'singular' => 'Befund', 'icon' => 'exclamation-triangle', 'route' => null],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        if ($morphAlias !== 'infra_server') {
            return;
        }

        $query->with('userInCharge:id,name')->withCount([
            'findings as open_critical_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_CRITICAL),
            'findings as open_warning_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_WARNING),
            'tasks as open_tasks_count' => fn ($q) => $q->notDone(),
        ]);
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        if ($morphAlias !== 'infra_server') {
            return [];
        }

        return [
            'health' => InfraServer::healthLabel($model->health()),
            'environment' => $model->environment,
            'responsible' => $model->userInCharge?->name,
            'findings_open' => (int) ($model->open_critical_count ?? 0) + (int) ($model->open_warning_count ?? 0),
            'tasks_open' => (int) ($model->open_tasks_count ?? 0),
            'is_active' => $model->status !== InfraServer::STATUS_RETIRED,
        ];
    }

    public function metadataDisplayRules(): array
    {
        return [
            'infra_server' => [
                ['field' => 'health', 'format' => 'badge'],
                ['field' => 'environment', 'format' => 'badge'],
                ['field' => 'responsible', 'format' => 'text'],
                ['field' => 'findings_open', 'format' => 'count', 'suffix' => 'Befunde'],
                ['field' => 'tasks_open', 'format' => 'count', 'suffix' => 'Maßnahmen'],
                ['field' => 'is_active', 'format' => 'boolean_active'],
            ],
        ];
    }

    public function timeTrackableCascades(): array
    {
        return [
            'infra_server' => [InfraServer::class, ['tasks']],
        ];
    }

    /** Maßnahmen und Befunde eines Servers erscheinen im Aktivitätsstrom seines Knotens. */
    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        if ($morphAlias !== 'infra_server' || empty($linkableIds)) {
            return [];
        }

        return array_filter([
            InfraTask::class => InfraTask::whereIn('infra_server_id', $linkableIds)->pluck('id')->all(),
            InfraFinding::class => InfraFinding::whereIn('infra_server_id', $linkableIds)->pluck('id')->all(),
        ]);
    }

    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        if ($morphAlias !== 'infra_server') {
            return [];
        }

        $allIds = array_values(array_unique(array_merge(...array_values($linksByEntity))));
        if (empty($allIds)) {
            return [];
        }

        $servers = InfraServer::whereIn('id', $allIds)->get(['id', 'status'])->keyBy('id');

        $findings = InfraFinding::whereIn('infra_server_id', $allIds)->open()
            ->selectRaw('infra_server_id, severity, COUNT(*) as cnt')
            ->groupBy('infra_server_id', 'severity')
            ->get()
            ->groupBy('infra_server_id');

        $tasks = InfraTask::whereIn('infra_server_id', $allIds)
            ->selectRaw('infra_server_id, SUM(CASE WHEN status = ? THEN 0 ELSE 1 END) as open_cnt, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as done_cnt', [InfraTask::STATUS_DONE, InfraTask::STATUS_DONE])
            ->groupBy('infra_server_id')
            ->get()
            ->keyBy('infra_server_id');

        $result = [];
        foreach ($linksByEntity as $entityId => $ids) {
            $row = [
                'infra_servers_active' => 0,
                'infra_findings_critical' => 0,
                'infra_findings_warning' => 0,
                'infra_tasks_open' => 0,
                'infra_tasks_done' => 0,
            ];

            foreach ($ids as $id) {
                $server = $servers[$id] ?? null;
                if (! $server) {
                    continue;
                }
                if ($server->status !== InfraServer::STATUS_RETIRED) {
                    $row['infra_servers_active']++;
                }
                foreach ($findings[$id] ?? [] as $f) {
                    if ($f->severity === InfraFinding::SEVERITY_CRITICAL) {
                        $row['infra_findings_critical'] += (int) $f->cnt;
                    } elseif ($f->severity === InfraFinding::SEVERITY_WARNING) {
                        $row['infra_findings_warning'] += (int) $f->cnt;
                    }
                }
                $row['infra_tasks_open'] += (int) ($tasks[$id]->open_cnt ?? 0);
                $row['infra_tasks_done'] += (int) ($tasks[$id]->done_cnt ?? 0);
            }

            $result[$entityId] = $row;
        }

        return $result;
    }

    public function metricDefinitions(): array
    {
        return [
            'infra_servers_active'    => ['label' => 'Aktive Server', 'group' => 'infra', 'direction' => 'neutral', 'unit' => 'count', 'dimension' => 'org_capital', 'type' => 'stock', 'aggregation_mode' => 'rolled_up', 'basis' => 'stichtag'],
            'infra_findings_critical' => ['label' => 'Kritische Befunde (offen)', 'group' => 'infra', 'direction' => 'down', 'unit' => 'count', 'dimension' => 'quality', 'type' => 'stock', 'aggregation_mode' => 'rolled_up', 'basis' => 'stichtag'],
            'infra_findings_warning'  => ['label' => 'Warnungen (offen)', 'group' => 'infra', 'direction' => 'down', 'unit' => 'count', 'dimension' => 'quality', 'type' => 'stock', 'aggregation_mode' => 'rolled_up', 'basis' => 'stichtag'],
            'infra_tasks_open'        => ['label' => 'Infra-Maßnahmen (offen)', 'group' => 'infra', 'direction' => 'down', 'unit' => 'count', 'dimension' => 'complexity', 'type' => 'stock', 'aggregation_mode' => 'rolled_up', 'basis' => 'stichtag'],
            'infra_tasks_done'        => ['label' => 'Infra-Maßnahmen (erledigt)', 'group' => 'infra', 'direction' => 'up', 'unit' => 'count', 'pair' => 'infra_tasks_open', 'dimension' => 'throughput', 'type' => 'flow', 'aggregation_mode' => 'rolled_up', 'basis' => 'cumulative_since_start'],
        ];
    }

    /** Beteiligte für ein Gespräch im Kontext (Parlan): Verantwortlicher + Ersteller. */
    public function contextParticipants(string $morphAlias, Model $object): array
    {
        if ($morphAlias !== 'infra_server') {
            return [];
        }

        return array_values(array_unique(array_filter([(int) $object->user_in_charge_id, (int) $object->created_by_user_id])));
    }

    /** Ausgemusterter Server = erledigt. */
    public function contextDone(string $morphAlias, Model $object): ?bool
    {
        return $morphAlias === 'infra_server' ? $object->status === InfraServer::STATUS_RETIRED : null;
    }
}
