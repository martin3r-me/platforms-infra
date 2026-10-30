<?php

namespace Platform\Infra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Organization\Traits\HasTimeEntries;
use Platform\Infra\Models\Concerns\LogsInfraActivity;
use Symfony\Component\Uid\UuidV7;

/**
 * A measure (Maßnahme): work to be done on the infrastructure — optionally on one server,
 * optionally triggered by one finding. Whether the finding is really gone is decided by
 * the next observation, not by closing the task.
 */
class InfraTask extends Model
{
    use SoftDeletes, HasTimeEntries, LogsInfraActivity;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_DONE = 'done';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_WAITING, self::STATUS_DONE];
    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $table = 'infra_tasks';

    protected $fillable = [
        'uuid',
        'team_id',
        'infra_server_id',
        'infra_finding_id',
        'title',
        'description',
        'status',
        'priority',
        'position',
        'due_date',
        'user_in_charge_id',
        'created_by_user_id',
        'done_at',
        'done_note',
    ];

    protected $casts = [
        'position' => 'integer',
        'due_date' => 'date',
        'done_at' => 'datetime',
    ];

    /** Umsortieren auf dem Board ist keine Aktivität. */
    protected function activityQuietAttributes(): array
    {
        return ['position'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
            if (!$model->position) {
                $model->position = (int) static::where('team_id', $model->team_id)->where('status', $model->status ?? self::STATUS_OPEN)->max('position') + 1;
            }
        });
        static::saving(function (self $model) {
            if ($model->isDirty('status')) {
                $model->done_at = $model->status === self::STATUS_DONE ? ($model->done_at ?? now()) : null;
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(InfraServer::class, 'infra_server_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(InfraFinding::class, 'infra_finding_id');
    }

    public function userInCharge(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_in_charge_id');
    }

    public function scopeNotDone($query)
    {
        return $query->where('status', '!=', self::STATUS_DONE);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'Offen',
            self::STATUS_IN_PROGRESS => 'In Arbeit',
            self::STATUS_WAITING => 'Wartet',
            self::STATUS_DONE => 'Erledigt',
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            self::STATUS_IN_PROGRESS => 'sky',
            self::STATUS_WAITING => 'amber',
            self::STATUS_DONE => 'emerald',
            default => 'slate',
        };
    }

    public static function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'high' => 'Hoch',
            'low' => 'Niedrig',
            default => 'Normal',
        };
    }

    public function toAgentArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'server_id' => $this->infra_server_id,
            'server' => $this->server?->name,
            'finding_id' => $this->infra_finding_id,
            'finding_status' => $this->finding?->status,
            'user_in_charge_id' => $this->user_in_charge_id,
            'due_date' => $this->due_date?->toDateString(),
            'done_at' => $this->done_at?->toIso8601String(),
            'done_note' => $this->done_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
