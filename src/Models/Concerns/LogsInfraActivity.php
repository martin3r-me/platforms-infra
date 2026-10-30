<?php

namespace Platform\Infra\Models\Concerns;

use Illuminate\Support\Arr;
use Platform\ActivityLog\Traits\LogsActivity;

/**
 * Activity-Log für den Aktivitätsstrom der Org-Knoten — ohne das Rauschen des Observers:
 * Felder aus activityQuietAttributes() zählen nicht als Änderung. Ändert ein Report nur
 * diese (last_seen_at, occurrence_count, …), entsteht kein Eintrag.
 */
trait LogsInfraActivity
{
    use LogsActivity {
        getActivityProperties as baseActivityProperties;
    }

    /** @return string[] */
    abstract protected function activityQuietAttributes(): array;

    protected function getActivityProperties(string $event): array
    {
        return Arr::except($this->baseActivityProperties($event), array_merge(['uuid'], $this->activityQuietAttributes()));
    }
}
