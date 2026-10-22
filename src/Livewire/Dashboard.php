<?php

namespace Platform\Infra\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Infra\Livewire\Concerns\EditsServerForm;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraLogEntry;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Models\InfraTask;
use Platform\Infra\Services\InfraTeamResolver;

class Dashboard extends Component
{
    use EditsServerForm;

    public bool $showCreate = false;

    #[Url(except: false)]
    public bool $showRetired = false;

    #[Url(except: '')]
    public string $search = '';

    /** all | problems | ok | silent */
    #[Url(except: 'all')]
    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'problems', 'ok', 'silent'], true) ? $filter : 'all';
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->form = $this->blankForm();
        $this->showCreate = true;
    }

    public function createServer(): void
    {
        $teamId = $this->teamId();
        $data = $this->validatedForm($teamId);

        $server = InfraServer::create($data + [
            'team_id' => $teamId,
            'created_by_user_id' => Auth::id(),
        ]);

        $this->showCreate = false;
        $this->redirectRoute('infra.servers.show', ['server' => $server->id], navigate: true);
    }

    protected function teamId(): int
    {
        $teamId = InfraTeamResolver::rootTeamId(Auth::user());
        abort_unless($teamId, 403);

        return $teamId;
    }

    public function render()
    {
        $teamId = $this->teamId();

        $all = InfraServer::query()
            ->where('team_id', $teamId)
            ->when(!$this->showRetired, fn ($q) => $q->watched())
            ->withCount([
                'findings as open_critical_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_CRITICAL),
                'findings as open_warning_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_WARNING),
                'tasks as open_tasks_count' => fn ($q) => $q->notDone(),
            ])
            ->get()
            ->each(fn (InfraServer $s) => $s->setAttribute('health_state', $s->health()));

        $counts = [
            'all' => $all->count(),
            'problems' => $all->whereIn('health_state', [InfraServer::HEALTH_CRITICAL, InfraServer::HEALTH_WARNING])->count(),
            'ok' => $all->where('health_state', InfraServer::HEALTH_OK)->count(),
            'silent' => $all->whereIn('health_state', [InfraServer::HEALTH_STALE, InfraServer::HEALTH_UNKNOWN])->count(),
        ];

        $term = mb_strtolower(trim($this->search));
        $servers = $all
            ->filter(fn ($s) => match ($this->filter) {
                'problems' => in_array($s->health_state, [InfraServer::HEALTH_CRITICAL, InfraServer::HEALTH_WARNING], true),
                'ok' => $s->health_state === InfraServer::HEALTH_OK,
                'silent' => in_array($s->health_state, [InfraServer::HEALTH_STALE, InfraServer::HEALTH_UNKNOWN], true),
                default => true,
            })
            ->filter(fn ($s) => $term === '' || str_contains(mb_strtolower(implode(' ', [$s->name, $s->role, $s->ip_private, $s->ip_public, $s->hostname])), $term))
            ->sortBy([
                fn ($a, $b) => InfraServer::healthRank($a->health_state) <=> InfraServer::healthRank($b->health_state),
                fn ($a, $b) => strnatcasecmp($a->name, $b->name),
            ])
            ->values();

        $openFindings = InfraFinding::query()
            ->with('server:id,name')
            ->where('team_id', $teamId)
            ->open()
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('last_seen_at')
            ->limit(12)
            ->get();

        $stats = [
            'servers' => $all->where('status', '!=', InfraServer::STATUS_RETIRED)->count(),
            'critical' => InfraFinding::where('team_id', $teamId)->open()->where('severity', InfraFinding::SEVERITY_CRITICAL)->count(),
            'warning' => InfraFinding::where('team_id', $teamId)->open()->where('severity', InfraFinding::SEVERITY_WARNING)->count(),
            'tasks_open' => InfraTask::where('team_id', $teamId)->notDone()->count(),
            'tasks_mine' => InfraTask::where('team_id', $teamId)->notDone()->where('user_in_charge_id', Auth::id())->count(),
            'errors_24h' => InfraLogEntry::where('team_id', $teamId)
                ->whereIn('level', InfraLogEntry::ERROR_LEVELS)
                ->where('occurred_at', '>=', now()->subDay())
                ->count(),
            'last_check' => $all->max('last_seen_at'),
        ];

        $recentlyResolved = InfraFinding::query()
            ->with('server:id,name')
            ->where('team_id', $teamId)
            ->where('status', InfraFinding::STATUS_RESOLVED)
            ->orderByDesc('resolved_at')
            ->limit(8)
            ->get();

        return view('infra::livewire.dashboard', [
            'servers' => $servers,
            'counts' => $counts,
            'openFindings' => $openFindings,
            'recentlyResolved' => $recentlyResolved,
            'stats' => $stats,
            'options' => $this->formOptions(),
        ])->layout('platform::layouts.app');
    }
}
