<?php

namespace Platform\Infra\Console\Commands;

use Illuminate\Console\Command;
use Platform\Infra\Models\InfraLogEntry;
use Platform\Infra\Models\InfraMetric;

class PruneInfraDataCommand extends Command
{
    protected $signature = 'infra:prune';

    protected $description = 'Delete raw infra logs and metrics beyond their retention window';

    public function handle(): int
    {
        $logsDays = max(1, (int) config('infra.retention.logs_days', 14));
        $metricsDays = max(1, (int) config('infra.retention.metrics_days', 30));

        $logs = InfraLogEntry::where('occurred_at', '<', now()->subDays($logsDays))->delete();
        $metrics = InfraMetric::where('recorded_at', '<', now()->subDays($metricsDays))->delete();

        $this->info("infra:prune — {$logs} log entries, {$metrics} metrics removed");

        return self::SUCCESS;
    }
}
