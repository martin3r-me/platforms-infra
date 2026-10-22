<?php

namespace Platform\Infra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * A finding is one observed problem on one server — deduplicated by (check, subject).
 * Lifecycle: open → acknowledged → resolved, or accepted (known and deliberately tolerated).
 * A resolved finding that is reported again reopens; an accepted one stays accepted.
 */
class InfraFinding extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ACCEPTED = 'accepted';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED, self::STATUS_RESOLVED, self::STATUS_ACCEPTED];
    public const SEVERITIES = [self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_CRITICAL];

    protected $table = 'infra_findings';

    protected $fillable = [
        'uuid',
        'team_id',
        'infra_server_id',
        'check_key',
        'subject',
        'fingerprint',
        'severity',
        'title',
        'detail',
        'sample',
        'status',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_note',
        'reported_by_user_id',
    ];

    protected $casts = [
        'sample' => 'array',
        'occurrence_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public static function fingerprintFor(string $check, string $subject): string
    {
        return hash('sha256', mb_strtolower(trim($check)) . '|' . mb_strtolower(trim($subject)));
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(InfraServer::class, 'infra_server_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'resolved_by_user_id');
    }

    /** Open = needs attention (open or acknowledged). */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED], true);
    }

    public function transition(string $status, ?int $userId = null, ?string $note = null): self
    {
        $this->status = $status;
        if ($status === self::STATUS_RESOLVED) {
            $this->resolved_at = now();
            $this->resolved_by_user_id = $userId;
        } elseif ($status === self::STATUS_OPEN) {
            $this->resolved_at = null;
            $this->resolved_by_user_id = null;
        }
        if ($note !== null && trim($note) !== '') {
            $this->resolution_note = trim($note);
        }
        $this->save();

        return $this;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'Offen',
            self::STATUS_ACKNOWLEDGED => 'In Arbeit',
            self::STATUS_RESOLVED => 'Behoben',
            self::STATUS_ACCEPTED => 'Akzeptiert',
            default => $status,
        };
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_CRITICAL => 'Kritisch',
            self::SEVERITY_WARNING => 'Warnung',
            default => 'Info',
        };
    }

    public static function severityVariant(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_CRITICAL => 'danger',
            self::SEVERITY_WARNING => 'warning',
            default => 'info',
        };
    }

    public function toAgentArray(): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->infra_server_id,
            'server' => $this->server?->name,
            'check' => $this->check_key,
            'subject' => $this->subject,
            'severity' => $this->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'status' => $this->status,
            'occurrence_count' => $this->occurrence_count,
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
        ];
    }
}
