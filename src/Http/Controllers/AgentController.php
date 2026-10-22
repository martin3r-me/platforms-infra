<?php

namespace Platform\Infra\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Services\InfraReportService;
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
