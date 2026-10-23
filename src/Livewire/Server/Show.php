<?php

namespace Platform\Infra\Livewire\Server;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Database\Eloquent\Model;
use Platform\Core\Livewire\Concerns\HasPageContext;
use Livewire\WithPagination;
use Platform\Infra\Livewire\Concerns\EditsServerForm;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraLogEntry;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Models\InfraTask;
use Platform\Infra\Services\InfraTeamResolver;

class Show extends Component
{
    use EditsServerForm;
    use HasPageContext;
    use WithPagination;

    public InfraServer $server;

    #[Url(except: 'findings')]
    public string $tab = 'findings';
    public string $findingFilter = 'open';
    public string $logLevel = 'problems';

    public bool $showEdit = false;
    public bool $confirmDelete = false;

    public bool $showNote = false;
    public ?int $noteFindingId = null;
    public string $noteStatus = '';
    public string $note = '';

    public function mount(InfraServer $server): void
    {
        abort_unless((int) $server->team_id === InfraTeamResolver::rootTeamId(Auth::user()), 404);
        $this->server = $server;
    }

    protected function pageContext(): ?Model
    {
        return $this->server;
    }

    protected function pageContextOptions(): array
    {
        return [
            'url' => route('infra.servers.show', $this->server),
            'source' => 'infra.server.view',
        ];
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['findings', 'tasks', 'security', 'processes', 'logs', 'metrics', 'details'], true) ? $tab : 'findings';
        $this->resetPage();
    }

    public function updatedLogLevel(): void
    {
        $this->resetPage();
    }

    /** "Maßnahme" on a finding: create a linked task and show the board. */
    public function taskFromFinding(int $findingId): void
    {
        $finding = InfraFinding::where('infra_server_id', $this->server->id)->find($findingId);
        if ($finding) {
            $open = InfraTask::where('infra_finding_id', $finding->id)->notDone()->exists();
            if (!$open) {
                app(\Platform\Infra\Services\InfraTaskService::class)->create($this->server->team_id, ['finding_id' => $finding->id], Auth::id());
            }
            $this->tab = 'tasks';
        }
    }

    public function openEdit(): void
    {
        $this->resetValidation();
        $this->form = $this->formFromServer($this->server);
        $this->showEdit = true;
    }

    public function saveServer(): void
    {
        $data = $this->validatedForm($this->server->team_id, $this->server->id);
        $this->server->update($data);
        $this->showEdit = false;
    }

    public function deleteServer(): void
    {
        $this->server->delete();
        $this->redirectRoute('infra.dashboard', navigate: true);
    }

    /** Status change with an optional note; "resolved" and "accepted" ask for the why. */
    public function startTransition(int $findingId, string $status): void
    {
        if (!in_array($status, InfraFinding::STATUSES, true)) {
            return;
        }
        if (in_array($status, [InfraFinding::STATUS_RESOLVED, InfraFinding::STATUS_ACCEPTED], true)) {
            $this->noteFindingId = $findingId;
            $this->noteStatus = $status;
            $this->note = '';
            $this->showNote = true;

            return;
        }
        $this->applyTransition($findingId, $status, null);
    }

    public function confirmTransition(): void
    {
        if ($this->showNote && $this->noteFindingId) {
            $this->applyTransition($this->noteFindingId, $this->noteStatus, $this->note);
        }
        $this->showNote = false;
        $this->noteFindingId = null;
    }

    protected function applyTransition(int $findingId, string $status, ?string $note): void
    {
        $finding = InfraFinding::where('infra_server_id', $this->server->id)->find($findingId);
        $finding?->transition($status, Auth::id(), $note);
    }

    public function render()
    {
        $findings = InfraFinding::query()
            ->where('infra_server_id', $this->server->id)
            ->when($this->findingFilter === 'open', fn ($q) => $q->open())
            ->when(in_array($this->findingFilter, [InfraFinding::STATUS_RESOLVED, InfraFinding::STATUS_ACCEPTED], true),
                fn ($q) => $q->where('status', $this->findingFilter))
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->get();

        $logs = $this->tab === 'logs'
            ? InfraLogEntry::query()
                ->where('infra_server_id', $this->server->id)
                ->when($this->logLevel === 'problems', fn ($q) => $q->whereIn('level', array_merge(InfraLogEntry::ERROR_LEVELS, ['warning'])))
                ->when($this->logLevel === 'errors', fn ($q) => $q->whereIn('level', InfraLogEntry::ERROR_LEVELS))
                ->when($this->logLevel === 'logins', fn ($q) => $q->where('source', 'ssh-login'))
                ->orderByDesc('occurred_at')
                ->paginate(50)
            : null;

        $metrics = $this->tab === 'metrics'
            ? $this->server->metrics()->where('recorded_at', '>=', now()->subDays(7))->orderBy('recorded_at')->get()
            : collect();

        $this->server->loadCount([
            'findings as open_critical_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_CRITICAL),
            'findings as open_warning_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_WARNING),
        ]);

        $members = $this->showEdit
            ? ($this->server->team?->users()->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all() ?? [])
            : [];

        // Sicherheit: Fehlversuche je Tag (7 Tage) + letzte erfolgreiche Anmeldungen.
        $security = null;
        if ($this->tab === 'security') {
            $daily = $this->server->metrics()
                ->where('recorded_at', '>=', now()->subDays(6)->startOfDay())
                ->whereNotNull('ssh_failed')
                ->get(['recorded_at', 'ssh_failed'])
                ->groupBy(fn ($m) => $m->recorded_at->toDateString())
                ->map(fn ($g) => (int) $g->sum('ssh_failed'));
            $days = collect(range(6, 0))->map(fn ($i) => now()->subDays($i)->toDateString())
                ->mapWithKeys(fn ($d) => [$d => $daily[$d] ?? 0]);
            $security = [
                'failed_daily' => $days,
                'logins' => $this->server->logEntries()->where('source', 'ssh-login')->orderByDesc('occurred_at')->limit(20)->get(),
            ];
        }

        $tabCounts = [
            'findings' => $this->server->findings()->open()->count(),
            'tasks' => $this->server->tasks()->notDone()->count(),
            'logs' => $this->server->logEntries()->whereIn('level', InfraLogEntry::ERROR_LEVELS)->where('occurred_at', '>=', now()->subDay())->count(),
        ];
        $taskByFinding = InfraTask::where('infra_server_id', $this->server->id)->notDone()
            ->whereNotNull('infra_finding_id')->pluck('id', 'infra_finding_id');

        return view('infra::livewire.server.show', [
            'tabCounts' => $tabCounts,
            'security' => $security,
            'taskByFinding' => $taskByFinding,
            'findings' => $findings,
            'logs' => $logs,
            'metrics' => $metrics,
            'health' => $this->server->health(),
            'options' => $this->formOptions() + ['members' => $members],
        ])->layout('platform::layouts.app');
    }
}
