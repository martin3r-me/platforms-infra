<?php

namespace Platform\Infra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Organization\Contracts\HasChildContextRelations;
use Platform\Organization\Traits\HasTimeEntries;
use Platform\Infra\Models\Concerns\LogsInfraActivity;
use Symfony\Component\Uid\UuidV7;

class InfraServer extends Model implements HasChildContextRelations
{
    use SoftDeletes, HasTimeEntries, LogsInfraActivity;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_RETIRED = 'retired';

    public const HEALTH_OK = 'ok';
    public const HEALTH_WARNING = 'warning';
    public const HEALTH_CRITICAL = 'critical';
    public const HEALTH_STALE = 'stale';
    public const HEALTH_UNKNOWN = 'unknown';

    public const PROVIDERS = ['hetzner', 'forge', 'digitalocean', 'other'];
    public const ENVIRONMENTS = ['production', 'staging', 'development'];
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_MAINTENANCE, self::STATUS_RETIRED];

    protected $table = 'infra_servers';

    protected $fillable = [
        'uuid',
        'team_id',
        'created_by_user_id',
        'user_in_charge_id',
        'name',
        'hostname',
        'role',
        'environment',
        'provider',
        'provider_ref',
        'ip_public',
        'ip_private',
        'ssh_user',
        'ssh_port',
        'status',
        'notes',
        'last_seen_at',
        'os',
        'last_report',
    ];

    protected $casts = [
        'ssh_port' => 'integer',
        'last_seen_at' => 'datetime',
        'last_report' => 'array',
    ];

    /** Was der Observer bei jeder Meldung schreibt, ist keine Aktivität. */
    protected function activityQuietAttributes(): array
    {
        return ['last_seen_at', 'os', 'last_report'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function userInCharge(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_in_charge_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(InfraFinding::class, 'infra_server_id');
    }

    public function logEntries(): HasMany
    {
        return $this->hasMany(InfraLogEntry::class, 'infra_server_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(InfraTask::class, 'infra_server_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(InfraMetric::class, 'infra_server_id');
    }

    public function scopeWatched($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_MAINTENANCE]);
    }

    public function isStale(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->lt(now()->subMinutes((int) config('infra.stale_after_minutes', 30)));
    }

    /**
     * Health is derived, never stored: open findings decide the colour, a silent server
     * is "stale" (the observer lost sight of it), a never-reported one is "unknown".
     * Expects open_critical_count / open_warning_count via withCount() when available.
     */
    public function health(): string
    {
        $critical = $this->open_critical_count ?? $this->findings()->open()->where('severity', InfraFinding::SEVERITY_CRITICAL)->count();
        $warning = $this->open_warning_count ?? $this->findings()->open()->where('severity', InfraFinding::SEVERITY_WARNING)->count();

        if ($critical > 0) {
            return self::HEALTH_CRITICAL;
        }
        if (!$this->last_seen_at) {
            return $warning > 0 ? self::HEALTH_WARNING : self::HEALTH_UNKNOWN;
        }
        if ($this->isStale()) {
            return self::HEALTH_STALE;
        }

        return $warning > 0 ? self::HEALTH_WARNING : self::HEALTH_OK;
    }

    public static function healthLabel(string $health): string
    {
        return match ($health) {
            self::HEALTH_OK => 'OK',
            self::HEALTH_WARNING => 'Warnung',
            self::HEALTH_CRITICAL => 'Kritisch',
            self::HEALTH_STALE => 'Keine Meldung',
            default => 'Unbekannt',
        };
    }

    /** Sort order: what needs attention first. */
    public static function healthRank(string $health): int
    {
        return match ($health) {
            self::HEALTH_CRITICAL => 0,
            self::HEALTH_WARNING => 1,
            self::HEALTH_STALE => 2,
            self::HEALTH_UNKNOWN => 3,
            default => 4,
        };
    }

    public static function healthVariant(string $health): string
    {
        return match ($health) {
            self::HEALTH_OK => 'success',
            self::HEALTH_WARNING, self::HEALTH_STALE => 'warning',
            self::HEALTH_CRITICAL => 'danger',
            default => 'neutral',
        };
    }

    /** Highest used percentage across all reported mounts. */
    public function maxDiskUsedPct(): ?float
    {
        $disks = $this->last_report['disks'] ?? [];
        if (!is_array($disks) || $disks === []) {
            return null;
        }
        $max = null;
        foreach ($disks as $d) {
            if (isset($d['used_pct']) && is_numeric($d['used_pct'])) {
                $max = max($max ?? 0, (float) $d['used_pct']);
            }
        }

        return $max;
    }

    public function toAgentArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'role' => $this->role,
            'environment' => $this->environment,
            'provider' => $this->provider,
            'provider_ref' => $this->provider_ref,
            'ip_public' => $this->ip_public,
            'ip_private' => $this->ip_private,
            'ssh_user' => $this->ssh_user,
            'ssh_port' => $this->ssh_port,
            'status' => $this->status,
            'health' => $this->health(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }

    /** Zeiten auf Maßnahmen zählen beim Server mit. */
    public static function childContextRelations(): array
    {
        return ['tasks'];
    }
}
