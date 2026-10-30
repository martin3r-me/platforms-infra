<?php

namespace Platform\Infra\Livewire;

use Livewire\Attributes\Locked;
use Platform\Core\Livewire\Terminal\Time;
use Platform\Infra\Models\InfraTask;

/**
 * Zeiterfassung im Maßnahmen-Dialog — die Kontext-Panel-App, aber fest an eine Maßnahme
 * gebunden. Maßnahmen haben keine eigene Seite und lassen sich nicht in die Orga hängen;
 * ihre Zeiten zählen über den Server mit.
 */
class TaskTime extends Time
{
    #[Locked]
    public ?int $taskId = null;

    public function mount(?int $taskId = null): void
    {
        parent::mount();
        $this->taskId = $taskId;
        $this->contextType = InfraTask::class;
        $this->contextId = $taskId;
    }

    /** Kontext kommt nur aus der (gesperrten) taskId, nie vom Client. */
    public function hydrate(): void
    {
        $this->contextType = InfraTask::class;
        $this->contextId = $this->taskId;
    }

    /** Seitenkontext (z. B. Server-Polling) darf die Maßnahme nicht überschreiben. */
    public function receiveTerminalContext(
        ?string $contextType = null,
        ?int $contextId = null,
        ?string $contextSubject = null,
        ?string $contextSource = null,
        ?string $contextUrl = null,
        array $contextMeta = [],
    ): void {
        $this->contextType = InfraTask::class;
        $this->contextId = $this->taskId;
    }
}
