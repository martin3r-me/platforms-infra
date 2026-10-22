<?php

namespace Platform\Infra\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Models\InfraTask;
use Platform\Infra\Services\InfraReportService;
use Platform\Infra\Services\InfraTaskService;
use Platform\Infra\Services\InfraTeamResolver;

/**
 * Agent API (Bearer token, auth:api). The observer pulls what to watch, reports what it
 * saw, and works the findings. Facts in, structure out — no free text protocol.
 */
class AgentController extends Controller
{
    /** GET /api/infra/agent/servers — the inventory to watch (active + maintenance). */
    public function servers(Request $request): JsonResponse
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());
        if (!$teamId) {
            return response()->json(['message' => 'No team'], 403);
        }

        $servers = InfraServer::query()
            ->where('team_id', $teamId)
            ->watched()
            ->withCount($this->openCounts())
            ->orderBy('name')
            ->get()
            ->map(fn (InfraServer $s) => $s->toAgentArray());

        return response()->json(['data' => $servers]);
    }

    /**
     * POST /api/infra/agent/servers — register or update a server by name (discovery).
     * Only inventory fields; humans own status and responsibility.
     */
    public function upsertServer(Request $request): JsonResponse
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());
        if (!$teamId) {
            return response()->json(['message' => 'No team'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', Rule::in(InfraServer::ENVIRONMENTS)],
            'provider' => ['nullable', Rule::in(InfraServer::PROVIDERS)],
            'provider_ref' => ['nullable', 'string', 'max:255'],
            'ip_public' => ['nullable', 'string', 'max:64'],
            'ip_private' => ['nullable', 'string', 'max:64'],
            'ssh_user' => ['nullable', 'string', 'max:64'],
            'ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $server = InfraServer::query()->where('team_id', $teamId)->where('name', $data['name'])->first();
        $created = false;
        if (!$server) {
            $server = new InfraServer([
                'team_id' => $teamId,
                'name' => $data['name'],
                'created_by_user_id' => $request->user()->id,
                'status' => InfraServer::STATUS_ACTIVE,
            ]);
            $created = true;
        }
        // Agent fills gaps; it never blanks a field a human has set.
        foreach (array_filter($data, fn ($v) => $v !== null && $v !== '') as $k => $v) {
            $server->{$k} = $v;
        }
        $server->save();

        return response()->json(['data' => $server->toAgentArray(), 'created' => $created], $created ? 201 : 200);
    }

    /**
     * POST /api/infra/agent/servers/{id}/report — one observation:
     * { observed_at?, os?, metrics?{load1,cpu_count,mem_used_pct,swap_used_pct,uptime_s,disks[{mount,used_pct,size_gb}]},
     *   checks[]: checks actually run (open findings of these that are missing get resolved),
     *   findings[]{check,subject?,severity,title,detail?,sample?}, logs[]{level,source?,message,occurred_at?,context?} }
     */
    public function report(Request $request, int $id, InfraReportService $service): JsonResponse
    {
        $server = $this->resolveServer($request, $id);
        if (!$server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $request->validate([
            'observed_at' => ['nullable', 'string'],
            'reachable' => ['nullable', 'boolean'],
            'os' => ['nullable', 'string', 'max:255'],
            'metrics' => ['nullable', 'array'],
            'checks' => ['nullable', 'array'],
            'checks.*' => ['string', 'max:64'],
            'findings' => ['nullable', 'array'],
            'findings.*.check' => ['required', 'string', 'max:64'],
            'findings.*.subject' => ['nullable', 'string', 'max:255'],
            'findings.*.severity' => ['nullable', Rule::in(InfraFinding::SEVERITIES)],
            'findings.*.title' => ['required', 'string', 'max:255'],
            'findings.*.detail' => ['nullable', 'string'],
            'findings.*.sample' => ['nullable', 'array'],
            'logs' => ['nullable', 'array'],
            'logs.*.message' => ['required', 'string'],
        ]);

        $result = $service->ingest($server, $request->all(), (int) $request->user()->id);
        $server->refresh()->loadCount($this->openCounts());

        return response()->json(['data' => $result + ['health' => $server->health()]]);
    }

    /** GET /api/infra/agent/findings?status=open&server_id=&severity= — the work list. */
    public function findings(Request $request): JsonResponse
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());
        if (!$teamId) {
            return response()->json(['message' => 'No team'], 403);
        }

        $status = $request->query('status', 'open');
        $query = InfraFinding::query()->with('server:id,name')->where('team_id', $teamId);
        if ($status === 'open') {
            $query->open();
        } elseif (in_array($status, InfraFinding::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($request->filled('server_id')) {
            $query->where('infra_server_id', (int) $request->query('server_id'));
        }
        if (in_array($request->query('severity'), InfraFinding::SEVERITIES, true)) {
            $query->where('severity', $request->query('severity'));
        }

        $rows = $query
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderBy('first_seen_at')
            ->limit(min(500, max(1, (int) $request->query('limit', 200))))
            ->get()
            ->map(fn (InfraFinding $f) => $f->toAgentArray());

        return response()->json(['data' => $rows]);
    }

    /** POST /api/infra/agent/findings/{id}/status {status: acknowledged|resolved|open, note?} */
    public function findingStatus(Request $request, int $id): JsonResponse
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());
        $finding = InfraFinding::query()->where('team_id', $teamId)->find($id);
        if (!$finding) {
            return response()->json(['message' => 'Finding not found'], 404);
        }

        // "accepted" (tolerating a problem) is a human decision — not available to the agent.
        $data = $request->validate([
            'status' => ['required', Rule::in([InfraFinding::STATUS_ACKNOWLEDGED, InfraFinding::STATUS_RESOLVED, InfraFinding::STATUS_OPEN])],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($finding->status === InfraFinding::STATUS_ACCEPTED) {
            return response()->json(['message' => 'Finding was accepted by a human; leave it as is.'], 409);
        }

        $finding->transition($data['status'], (int) $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => $finding->toAgentArray()]);
    }

    /** GET /api/infra/agent/tasks?scope=mine|open|all&server_id= — measures (default: open, mine first). */
    public function tasks(Request $request): JsonResponse
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());
        if (!$teamId) {
            return response()->json(['message' => 'No team'], 403);
        }
        $me = (int) $request->user()->id;
        $scope = $request->query('scope', 'open');

        $rows = InfraTask::query()->with(['server:id,name', 'finding:id,status'])
            ->where('team_id', $teamId)
            ->when($scope !== 'all', fn ($q) => $q->notDone())
            ->when($scope === 'mine', fn ($q) => $q->where('user_in_charge_id', $me))
            ->when($request->filled('server_id'), fn ($q) => $q->where('infra_server_id', (int) $request->query('server_id')))
            ->orderByRaw('CASE WHEN user_in_charge_id = ? THEN 0 WHEN user_in_charge_id IS NULL THEN 1 ELSE 2 END', [$me])
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderBy('position')
            ->limit(200)
            ->get()
            ->map(fn (InfraTask $t) => $t->toAgentArray());

        return response()->json(['data' => $rows]);
    }

    /** POST /api/infra/agent/tasks {title?, description?, priority?, server_id?, finding_id?, assign_self?} */
    public function createTask(Request $request, InfraTaskService $service): JsonResponse
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());
        if (!$teamId) {
            return response()->json(['message' => 'No team'], 403);
        }
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255', 'required_without:finding_id'],
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::in(InfraTask::PRIORITIES)],
            'server_id' => ['nullable', 'integer'],
            'finding_id' => ['nullable', 'integer'],
            'assign_self' => ['nullable', 'boolean'],
        ]);
        if ($request->boolean('assign_self')) {
            $data['user_in_charge_id'] = (int) $request->user()->id;
        }
        // One open task per finding: return the existing one instead of a duplicate.
        if (!empty($data['finding_id'])) {
            $existing = InfraTask::where('team_id', $teamId)->where('infra_finding_id', (int) $data['finding_id'])->notDone()->first();
            if ($existing) {
                return response()->json(['data' => $existing->toAgentArray(), 'created' => false]);
            }
        }
        $task = $service->create($teamId, $data, (int) $request->user()->id);

        return response()->json(['data' => $task->fresh(['server', 'finding'])->toAgentArray(), 'created' => true], 201);
    }

    /** POST /api/infra/agent/tasks/{id}/status {status, note?, claim?} — move a measure; claim=true takes it over. */
    public function taskStatus(Request $request, int $id, InfraTaskService $service): JsonResponse
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());
        $task = InfraTask::where('team_id', $teamId)->find($id);
        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(InfraTask::STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
            'claim' => ['nullable', 'boolean'],
        ]);
        $me = (int) $request->user()->id;
        if ($task->user_in_charge_id && $task->user_in_charge_id !== $me) {
            return response()->json(['message' => 'Task belongs to someone else.'], 409);
        }
        if ($request->boolean('claim') && !$task->user_in_charge_id) {
            $task->user_in_charge_id = $me;
        }
        $service->move($task, $data['status'], $data['note'] ?? null);

        return response()->json(['data' => $task->fresh(['server', 'finding'])->toAgentArray()]);
    }

    protected function resolveServer(Request $request, int $id): ?InfraServer
    {
        $teamId = InfraTeamResolver::rootTeamId($request->user());

        return $teamId ? InfraServer::query()->where('team_id', $teamId)->find($id) : null;
    }

    protected function openCounts(): array
    {
        return [
            'findings as open_critical_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_CRITICAL),
            'findings as open_warning_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_WARNING),
        ];
    }
}
