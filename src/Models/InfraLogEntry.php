<?php

namespace Platform\Infra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfraLogEntry extends Model
{
    public const UPDATED_AT = null;

    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    public const ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    protected $table = 'infra_log_entries';

    protected $fillable = [
        'team_id',
        'infra_server_id',
        'level',
        'source',
        'message',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(InfraServer::class, 'infra_server_id');
    }

    public static function levelVariant(string $level): string
    {
        return match (true) {
            in_array($level, self::ERROR_LEVELS, true) => 'danger',
            $level === 'warning' => 'warning',
            default => 'neutral',
        };
    }
}
