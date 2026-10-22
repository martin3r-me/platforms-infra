<?php

namespace Platform\Infra\Services;

use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Models\InfraTask;

/** One place for creating and moving tasks — UI, agent API and tools share the rules. */
class InfraTaskService
{
    /**
     * @param array{title?:string,description?:?string,priority?:string,server_id?:?int,finding_id?:?int,user_in_charge_id?:?int,due_date?:?string,status?:string} $data
     */
    public function create(int $teamId, array $data, ?int $userId): InfraTask
    {
        $finding = !empty($data['finding_id'])
            ? InfraFinding::where('team_id', $teamId)->find((int) $data['finding_id'])
            : null;
        $serverId = !empty($data['server_id'])
            ? InfraServer::where('team_id', $teamId)->whereKey((int) $data['server_id'])->value('id')
            : $finding?->infra_server_id;

        $title = trim((string) ($data['title'] ?? '')) ?: ($finding ? 'Beheben: ' . $finding->title : '');
        abort_if($title === '', 422, 'title is required');

        return InfraTask::create([
            'team_id' => $teamId,
            'infra_server_id' => $serverId,
            'infra_finding_id' => $finding?->id,
            'title' => mb_substr($title, 0, 255),
            'description' => $data['description'] ?? ($finding?->detail),
            'status' => in_array($data['status'] ?? null, InfraTask::STATUSES, true) ? $data['status'] : InfraTask::STATUS_OPEN,
            'priority' => in_array($data['priority'] ?? null, InfraTask::PRIORITIES, true)
                ? $data['priority']
                : ($finding?->severity === InfraFinding::SEVERITY_CRITICAL ? 'high' : 'normal'),
            'user_in_charge_id' => $data['user_in_charge_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'created_by_user_id' => $userId,
        ]);
    }

    public function move(InfraTask $task, string $status, ?string $note = null): InfraTask
    {
        abort_unless(in_array($status, InfraTask::STATUSES, true), 422, 'invalid status');
        $task->status = $status;
        if ($status === InfraTask::STATUS_DONE && $note !== null && trim($note) !== '') {
            $task->done_note = trim($note);
        }
        $task->save();

        // Working on a finding acknowledges it; the next observation decides whether it is gone.
        if (in_array($status, [InfraTask::STATUS_IN_PROGRESS, InfraTask::STATUS_WAITING], true)
            && $task->finding?->status === InfraFinding::STATUS_OPEN) {
            $task->finding->transition(InfraFinding::STATUS_ACKNOWLEDGED);
        }

        return $task;
    }
}
