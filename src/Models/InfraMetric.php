<?php

namespace Platform\Infra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfraMetric extends Model
{
    public $timestamps = false;

    protected $table = 'infra_metrics';

    protected $fillable = [
        'infra_server_id',
        'recorded_at',
        'load1',
        'cpu_count',
        'mem_used_pct',
        'swap_used_pct',
        'disk_used_pct',
        'uptime_s',
        'ssh_failed',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'load1' => 'float',
        'cpu_count' => 'integer',
        'mem_used_pct' => 'float',
        'swap_used_pct' => 'float',
        'disk_used_pct' => 'float',
        'uptime_s' => 'integer',
        'ssh_failed' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(InfraServer::class, 'infra_server_id');
    }
}
