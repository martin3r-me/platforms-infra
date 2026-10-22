# Platform Infra

Der Raum für den Infrastruktur-Betrieb: Server-Bestand, Zustand, Befunde und Logs —
gepflegt vom Beobachter (Agent, z. B. Jazz) und kuratiert von Menschen.

- **Paket:** `martin3r/platform-infra`
- **Namespace:** `Platform\Infra\`
- **Tabellen-Prefix:** `infra_`
- **Scope:** Root-Team (`scope_type = parent`) — Server gehören der Organisation.

## Modell

| Objekt | Zweck |
|---|---|
| `InfraServer` | Bestand: Name, Rolle, Umgebung, Anbieter, IPs, SSH-Ziel, Status, Verantwortlich. Letzter Zustand (`last_seen_at`, `last_report`) denormalisiert. |
| `InfraFinding` | Ein Problem auf einem Server, dedupliziert über (Prüfung, Gegenstand). Lebenszyklus: offen → in Arbeit → behoben, oder akzeptiert. |
| `InfraLogEntry` | Rohe Log-Zeilen (v. a. Warnungen/Fehler), Aufbewahrung `INFRA_LOG_RETENTION_DAYS` (14). |
| `InfraTask` | Maßnahme: Arbeit an der Infrastruktur, optional an einem Server und/oder aus einem Befund. Board je Server (Reiter „Maßnahmen") und gesamt (`/infra/tasks`). Ob ein Befund wirklich weg ist, entscheidet die nächste Prüfung, nicht das Schließen der Maßnahme. |
| `InfraMetric` | Zeitreihe je Meldung (Last, RAM, Swap, Platte max.), Aufbewahrung `INFRA_METRIC_RETENTION_DAYS` (30). |

Zustand eines Servers wird **abgeleitet**, nie gespeichert: kritischer Befund → *Kritisch*,
keine Meldung seit `INFRA_STALE_AFTER_MINUTES` (30) → *Keine Meldung*, Warnung → *Warnung*, sonst *OK*.

## Agent-API (`auth:api`, Bearer)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/infra/agent/servers` | Was zu beobachten ist (aktiv + Wartung) |
| POST | `/api/infra/agent/servers` | Server per Name melden/ergänzen (füllt nur Lücken) |
| POST | `/api/infra/agent/servers/{id}/report` | Eine Beobachtung: `metrics`, `checks`, `findings`, `logs` |
| GET | `/api/infra/agent/findings` | Arbeitsliste (Default offen) |
| POST | `/api/infra/agent/findings/{id}/status` | `acknowledged` / `resolved` / `open` (+ `note`) |
| GET | `/api/infra/agent/tasks?scope=open\|mine\|all` | Maßnahmen (eigene zuerst) |
| POST | `/api/infra/agent/tasks` | Maßnahme anlegen (`finding_id` → vorbelegt, eine offene je Befund) |
| POST | `/api/infra/agent/tasks/{id}/status` | Status setzen (`claim` übernimmt eine freie) |

**Snapshot-Semantik:** `checks` nennt die Prüfungen, die tatsächlich gelaufen sind. Offene Befunde
dieser Prüfungen, die nicht erneut gemeldet werden, gelten als behoben. Ein behobener Befund, der
wieder auftaucht, öffnet sich erneut. *Akzeptieren* ist eine menschliche Entscheidung (nicht per Agent-API).

```json
{
  "os": "Ubuntu 24.04",
  "metrics": {"load1": 0.42, "cpu_count": 8, "mem_used_pct": 61, "swap_used_pct": 0, "uptime_s": 86400,
              "disks": [{"mount": "/", "used_pct": 82, "size_gb": 160}]},
  "checks": ["disk", "services", "logrotate"],
  "findings": [{"check": "disk", "subject": "/", "severity": "warning", "title": "Platte / zu 82 % belegt"}],
  "logs": [{"level": "error", "source": "nginx", "message": "…", "occurred_at": "2026-10-22T07:10:00Z"}]
}
```

## MCP-Tools

`infra.servers.GET`, `infra.server.GET`, `infra.servers.POST`, `infra.servers.PUT`,
`infra.findings.GET`, `infra.findings.PUT`, `infra.tasks.GET`, `infra.tasks.POST`, `infra.tasks.PUT`.

`reachable: false` in einem Report: Befunde zählen, der Server gilt aber nicht als „gesehen".

## Später

Sites (Forge), Deployments, Zertifikate, Backups als eigene Objekte;
Kennzahlen an die Organisation (EntityLinkProvider); Sync des Bestands aus Hetzner/Forge.
