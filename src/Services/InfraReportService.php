<?php

namespace Platform\Infra\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraLogEntry;
use Platform\Infra\Models\InfraMetric;
use Platform\Infra\Models\InfraServer;

/**
 * Ingests one observation of one server. Snapshot semantics for findings:
 * every check listed in `checks` was actually run, so an open finding of such a check
 * that is not reported again is resolved automatically. Checks not run stay untouched.
 */
class InfraReportService
{
    /**
     * @param array{os?:string,metrics?:array,checks?:array,findings?:array,logs?:array,observed_at?:string} $report
     * @return array{findings_created:int,findings_updated:int,findings_reopened:int,findings_auto_resolved:int,logs_stored:int,metric_stored:bool}
     */
    public function ingest(InfraServer $server, array $report, ?int $reporterUserId = null): array
    {
        $observedAt = $this->parseTime($report['observed_at'] ?? null) ?? now();
        $result = [
            'findings_created' => 0,
            'findings_updated' => 0,
            'findings_reopened' => 0,
            'findings_auto_resolved' => 0,
            'logs_stored' => 0,
            'metric_stored' => false,
        ];

        DB::transaction(function () use ($server, $report, $reporterUserId, $observedAt, &$result) {
            $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : null;

            // reachable=false: the observer could not reach the server (or only consulted the provider) —
            // findings still count, but the server was not "seen".
            if (($report['reachable'] ?? true) !== false) {
                $server->last_seen_at = $observedAt;
            }
            if (!empty($report['os'])) {
                $server->os = mb_substr((string) $report['os'], 0, 255);
            }
            if ($metrics !== null) {
                $server->last_report = $metrics + ['observed_at' => $observedAt->toIso8601String()];
            }
            $server->save();

            if ($metrics !== null) {
                $result['metric_stored'] = $this->storeMetric($server, $metrics, $observedAt);
            }

            $seen = [];
            foreach ((array) ($report['findings'] ?? []) as $f) {
                if (!is_array($f) || empty($f['check']) || empty($f['title'])) {
                    continue;
                }
                $outcome = $this->upsertFinding($server, $f, $observedAt, $reporterUserId);
                $seen[] = $outcome['fingerprint'];
                $result['findings_' . $outcome['outcome']]++;
            }

            $checks = array_values(array_filter(array_map(
                fn ($c) => mb_strtolower(trim((string) $c)),
                (array) ($report['checks'] ?? [])
            )));
            if ($checks !== []) {
                $stale = InfraFinding::query()
                    ->where('infra_server_id', $server->id)
                    ->open()
                    ->whereIn('check_key', $checks)
                    ->when($seen !== [], fn ($q) => $q->whereNotIn('fingerprint', $seen))
                    ->get();
                foreach ($stale as $finding) {
                    $finding->transition(InfraFinding::STATUS_RESOLVED, null, 'Automatisch: bei der Prüfung am ' . $observedAt->format('d.m.Y H:i') . ' nicht mehr festgestellt.');
                    $result['findings_auto_resolved']++;
                }
            }

            $result['logs_stored'] = $this->storeLogs($server, (array) ($report['logs'] ?? []), $observedAt);
        });

        return $result;
    }

    /** @return array{fingerprint:string,outcome:string} */
    protected function upsertFinding(InfraServer $server, array $f, Carbon $observedAt, ?int $reporterUserId): array
    {
        $check = mb_strtolower(mb_substr(trim((string) $f['check']), 0, 64));
        $subject = mb_substr(trim((string) ($f['subject'] ?? '')), 0, 255);
        $fingerprint = InfraFinding::fingerprintFor($check, $subject);
        $severity = in_array($f['severity'] ?? null, InfraFinding::SEVERITIES, true) ? $f['severity'] : InfraFinding::SEVERITY_WARNING;

        $attributes = [
            'severity' => $severity,
            'title' => mb_substr(trim((string) $f['title']), 0, 255),
            'detail' => isset($f['detail']) ? mb_substr((string) $f['detail'], 0, 10000) : null,
            'sample' => is_array($f['sample'] ?? null) ? $f['sample'] : null,
            'last_seen_at' => $observedAt,
        ];

        $existing = InfraFinding::query()
            ->where('infra_server_id', $server->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        if (!$existing) {
            InfraFinding::create($attributes + [
                'team_id' => $server->team_id,
                'infra_server_id' => $server->id,
                'check_key' => $check,
                'subject' => $subject,
                'fingerprint' => $fingerprint,
                'status' => InfraFinding::STATUS_OPEN,
                'occurrence_count' => 1,
                'first_seen_at' => $observedAt,
                'reported_by_user_id' => $reporterUserId,
            ]);

            return ['fingerprint' => $fingerprint, 'outcome' => 'created'];
        }

        $existing->fill($attributes);
        $existing->occurrence_count++;
        $outcome = 'updated';
        if ($existing->status === InfraFinding::STATUS_RESOLVED) {
            $existing->status = InfraFinding::STATUS_OPEN;
            $existing->resolved_at = null;
            $existing->resolved_by_user_id = null;
            $existing->resolution_note = null;
            $outcome = 'reopened';
        }
        $existing->save();

        return ['fingerprint' => $fingerprint, 'outcome' => $outcome];
    }

    protected function storeMetric(InfraServer $server, array $m, Carbon $observedAt): bool
    {
        $disk = null;
        foreach ((array) ($m['disks'] ?? []) as $d) {
            if (is_array($d) && isset($d['used_pct']) && is_numeric($d['used_pct'])) {
                $disk = max($disk ?? 0, (float) $d['used_pct']);
            }
        }

        InfraMetric::create([
            'infra_server_id' => $server->id,
            'recorded_at' => $observedAt,
            'load1' => $this->num($m['load1'] ?? null),
            'cpu_count' => isset($m['cpu_count']) && is_numeric($m['cpu_count']) ? (int) $m['cpu_count'] : null,
            'mem_used_pct' => $this->pct($m['mem_used_pct'] ?? null),
            'swap_used_pct' => $this->pct($m['swap_used_pct'] ?? null),
            'disk_used_pct' => $this->pct($disk),
            'uptime_s' => isset($m['uptime_s']) && is_numeric($m['uptime_s']) ? (int) $m['uptime_s'] : null,
        ]);

        return true;
    }

    protected function storeLogs(InfraServer $server, array $logs, Carbon $observedAt): int
    {
        $max = (int) config('infra.max_logs_per_report', 500);
        $rows = [];
        $now = now();
        foreach (array_slice($logs, 0, $max) as $l) {
            if (!is_array($l) || !isset($l['message']) || trim((string) $l['message']) === '') {
                continue;
            }
            $level = mb_strtolower((string) ($l['level'] ?? 'info'));
            $rows[] = [
                'team_id' => $server->team_id,
                'infra_server_id' => $server->id,
                'level' => in_array($level, InfraLogEntry::LEVELS, true) ? $level : 'info',
                'source' => isset($l['source']) ? mb_substr((string) $l['source'], 0, 64) : null,
                'message' => mb_substr((string) $l['message'], 0, 10000),
                'context' => is_array($l['context'] ?? null) ? json_encode($l['context']) : null,
                'occurred_at' => $this->parseTime($l['occurred_at'] ?? null) ?? $observedAt,
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            InfraLogEntry::insert($chunk);
        }

        return count($rows);
    }

    protected function parseTime(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $t = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Reporter clocks are not trusted into the future.
        return $t->isFuture() ? now() : $t;
    }

    protected function num(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    protected function pct(mixed $v): ?float
    {
        return is_numeric($v) ? max(0.0, min(100.0, round((float) $v, 2))) : null;
    }
}
