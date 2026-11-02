<?php

namespace Platform\Infra\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Models\InfraTask;
use Platform\Infra\Services\InfraAccess;
use Platform\Infra\Services\InfraTaskService;
use Platform\Infra\Services\InfraTeamResolver;

/**
 * Measures board — embedded on a server page (serverId set) or as the organisation-wide board.
 * Columns are the task statuses; drag moves a card between them.
 */
class TaskBoard extends Component
{
    #[\Livewire\Attributes\Locked]
    public ?int $serverId = null;

    public string $newTitle = '';
    public ?int $newServerId = null;

    public bool $showEdit = false;
    public bool $confirmDelete = false;
    public ?int $editId = null;
    public array $edit = [];

    public function mount(?int $serverId = null): void
    {
        if ($serverId) {
            $server = InfraServer::where('team_id', $this->teamId())->findOrFail($serverId);
            $this->authorize('view', $server);
        }
        $this->serverId = $serverId;
    }

    protected function teamId(): int
    {
        $teamId = InfraTeamResolver::rootTeamId(Auth::user());
        abort_unless($teamId, 403);

        return $teamId;
    }

    /** Maßnahme des Teams, für die der User die Fähigkeit $cap hat (sonst null). */
    protected function task(int $id, string $cap = 'write'): ?InfraTask
    {
        $task = InfraTask::where('team_id', $this->teamId())
            ->when($this->serverId, fn ($q) => $q->where('infra_server_id', $this->serverId))
            ->find($id);

        return $task && app(InfraAccess::class)->canTask(Auth::user(), $task, $cap) ? $task : null;
    }

    /** Server des Teams, an dem der User arbeiten darf (Maßnahmen anlegen/umhängen). */
    protected function writableServerId(?int $serverId): ?int
    {
        if (!$serverId) {
            return null;
        }
        $server = InfraServer::where('team_id', $this->teamId())->find($serverId);

        return $server && app(InfraAccess::class)->canServer(Auth::user(), $server, 'write') ? (int) $server->id : null;
    }

    public function addTask(): void
    {
        $service = app(InfraTaskService::class);
        $this->validate(['newTitle' => ['required', 'string', 'max:255']], [], ['newTitle' => 'Titel']);
        $serverId = $this->serverId ?? $this->newServerId;
        if ($serverId && !$this->writableServerId($serverId)) {
            abort(403);
        }
        $service->create($this->teamId(), [
            'title' => $this->newTitle,
            'server_id' => $serverId,
        ], Auth::id());
        $this->newTitle = '';
    }

    public function noop(): void
    {
        // Column order is fixed (statuses); the container still expects a handler.
    }

    public function updateTaskOrder($groups): void
    {
        $service = app(InfraTaskService::class);
        foreach ($groups as $group) {
            $status = (string) ($group['value'] ?? '');
            if (!in_array($status, InfraTask::STATUSES, true)) {
                continue;
            }
            foreach ($group['items'] ?? [] as $item) {
                $task = $this->task((int) $item['value']);
                if (!$task) {
                    continue;
                }
                $task->position = (int) $item['order'];
                if ($task->status !== $status) {
                    $service->move($task, $status);
                } else {
                    $task->save();
                }
            }
        }
    }

    public function openTask(int $id): void
    {
        $task = $this->task($id, 'read');
        if (!$task) {
            return;
        }
        $this->resetValidation();
        $this->confirmDelete = false;
        $this->editId = $task->id;
        $this->edit = [
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'infra_server_id' => $task->infra_server_id,
            'user_in_charge_id' => $task->user_in_charge_id,
            'due_date' => $task->due_date?->toDateString(),
            'done_note' => $task->done_note,
        ];
        $this->showEdit = true;
    }

    public function saveTask(): void
    {
        $service = app(InfraTaskService::class);
        $task = $this->editId ? $this->task($this->editId) : null;
        if (!$task) {
            $this->showEdit = false;

            return;
        }
        $data = $this->validate([
            'edit.title' => ['required', 'string', 'max:255'],
            'edit.description' => ['nullable', 'string', 'max:20000'],
            'edit.status' => ['required', Rule::in(InfraTask::STATUSES)],
            'edit.priority' => ['required', Rule::in(InfraTask::PRIORITIES)],
            'edit.infra_server_id' => ['nullable', 'integer'],
            'edit.user_in_charge_id' => ['nullable', 'integer'],
            'edit.due_date' => ['nullable', 'date'],
            'edit.done_note' => ['nullable', 'string', 'max:5000'],
        ], [], ['edit.title' => 'Titel'])['edit'];

        $status = $data['status'];
        unset($data['status']);
        if (!empty($data['infra_server_id']) && (int) $data['infra_server_id'] !== (int) $task->infra_server_id) {
            $data['infra_server_id'] = $this->writableServerId((int) $data['infra_server_id']);
        }
        foreach ($data as $k => $v) {
            $task->{$k} = $v === '' ? null : $v;
        }
        $task->save();
        if ($task->status !== $status) {
            $service->move($task, $status);
        }
        $this->showEdit = false;
    }

    public function deleteTask(): void
    {
        $this->task((int) $this->editId)?->delete();
        $this->confirmDelete = false;
        $this->showEdit = false;
    }

    public function render()
    {
        $teamId = $this->teamId();
        $access = app(InfraAccess::class);
        $user = Auth::user();
        $base = InfraTask::query()
            ->with(['server', 'finding:id,status,severity', 'userInCharge:id,name'])
            ->where('team_id', $teamId)
            ->when($this->serverId, fn ($q) => $q->where('infra_server_id', $this->serverId));

        $columns = [];
        foreach (InfraTask::STATUSES as $status) {
            $q = (clone $base)->where('status', $status);
            $columns[$status] = $access->visibleTasks($user, $status === InfraTask::STATUS_DONE
                ? $q->orderByDesc('done_at')->limit(20)->get()
                : $q->orderBy('position')->get());
        }

        $editTask = $this->showEdit && $this->editId ? $this->task($this->editId, 'read') : null;
        $writable = fn ($servers) => $servers->filter(fn ($s) => $access->canServer($user, $s, 'write'))->values();
        $servers = $this->serverId ? collect() : $writable(InfraServer::where('team_id', $teamId)->watched()->orderBy('name')->get());
        $members = $this->showEdit
            ? (\Platform\Core\Models\Team::find($teamId)?->users()->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all() ?? [])
            : [];

        return view('infra::livewire.task-board', [
            'columns' => $columns,
            'serverOptions' => $servers->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all(),
            'allServerOptions' => $this->showEdit
                ? $writable(InfraServer::where('team_id', $teamId)->orderBy('name')->get())
                    ->when($editTask?->server, fn ($c) => $c->push($editTask->server)->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values())
                    ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all()
                : [],
            'members' => $members,
            'canEditTask' => $editTask && $access->canTask($user, $editTask, 'write'),
        ]);
    }
}
