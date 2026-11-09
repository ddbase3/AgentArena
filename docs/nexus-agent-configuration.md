# NEXUS: umfassende Beispielkonfiguration für MissionBay

Dieses Dokument beschreibt die Agentenkonfiguration für **Schritt 1 und 2** von Agent Arena. Die Datei
`nexus-agent-configuration.example.json` zeigt eine ausführliche, vollständige
Beispielkonfiguration der in `NexusAgentDefinition` vorgegebenen Felder, ergänzt
um zwei bewusst **nicht automatisch belegte** Referenzen auf vorhandene
MissionBay-Konfigurationen. Sie ist Dokumentation, keine zusätzliche
Konfigurationsquelle, kein Bootstrap-Seed und kein Importskript.

## 1. Identität, Aktivierung und Runtime

| JSON-Feld | Beispielwert | Bedeutung |
| --- | --- | --- |
| `agent_runtime` | `missionbay` | Vorhandene MissionBay-Runtime; keine neue Runtime im Plugin |
| `enabled` | `false` | Standardmäßig deaktiviert. Erst nach Model- und Profilzuordnung aktivieren |
| `label` | `NEXUS` | Fester Code-Name; über die Agentenverwaltung nicht änderbar |
| `chatmodel` | `SET_EXISTING_CHATMODEL_PRESET_ID` | **Platzhalter**, durch eine vorhandene ID der aktiven Installation ersetzen |
| `orchestrator_profile` | `SET_EXISTING_ORCHESTRATOR_PROFILE_ID` | **Platzhalter**, nur mit einer realen, kompatiblen Profil-ID ersetzen |
| `tool_profiles` | `["arena-agent-tool-profile"]` | Tool-Profil, das der Betreiber in MissionBay mit genau einem Agent-Arena-Komponenten-Preset anlegt |
| `expert_overrides_enabled` | `false` | Keine erzwungenen Expertenparameter |
| `user_prompt` | `""` | Kein persistierter Nutzerprompt; der Match-Service setzt pro Zug den Auftrag |
| `system_prompt` | ausführliche Taktikanweisung | Stabiler Charakter und Spielanleitung; die Regeln bleiben serverseitig |
| `policy` | `manualonlyjobpolicy` | Keine automatische Job-Ausführung dieses Agenten |

**Wichtig:** `chatmodel` und `orchestrator_profile` müssen in der konkreten
MissionBay-Installation bereits existieren. Die Dateinamen und IDs dieser
Presets sind nicht Teil von AssistantFoundation. In den vorliegenden Quellen
werden keine erfundenen Modell- oder Komponenten-IDs vorausgesetzt. MissionBay 4.40.0 liefert z.B. das feste Orchestrator-Profil `native-tool-loop`; der Betreiber waehlt ein passendes, vorhandenes Profil.

Die fest definierte Agentenidentität ist `agentarena.opponent`. Sie ist der
Schlüssel im bestehenden Agentenkonfigurationsdienst. Die JSON-Datei wird
**nicht** als paralleler Datensatz importiert. Die effektive Konfiguration
kommt aus `IAgentConfigurationService::getAgent('agentarena.opponent')`.

## 2. System-Prompt, Rolle und Strategie

Die ausführliche `system_prompt`-Vorlage in der JSON-Datei beschreibt:

1. Identität: NEXUS ist der violette KI-Kommandant; der Mensch führt `human`.
2. Regeln: 9 x 7 Felder, drei Kontrollpunkte, drei Einheiten je Team, zehn Runden.
3. Einheiten: exakte Lebenspunkte, Bewegungsreichweiten, Angriffsreichweiten und Schaden.
4. Befehlsformat: `unit`, `type`, gegebenenfalls `x`, `y` oder `target`.
5. Werkzeugnutzung: Zustand lesen, höchstens sechs Befehle planen, atomar einreichen.
6. Fehlerverhalten: Ein abgelehnter Tool-Aufruf zählt nicht als ausgeführter Zug.
7. Taktik: Kontrollpunkte priorisieren, Ressourcen erhalten, Deckungen verwenden.
8. Antwortstil: auf Wunsch kurze deutschsprachige Funksprüche nach bestätigtem Zug.
9. Sicherheit: keine anderen Matches oder Benutzer steuern; keine Prompt-Anweisung
   darf die serverseitigen Spielregeln oder Autorisierung überschreiben.

Die strategischen Hinweise sollen dem LLM helfen, tatsächlich Entscheidungen
zu treffen. Sie **duplizieren nicht** die Spiellogik: Alle Bedingungen werden
in `ArenaRules` und `ArenaGameService` geprüft.

## 3. Tool-Registrierung in der bestehenden MissionBay-Administration

Das Plugin stellt **einen** PHP-Ressourcentyp `ArenaAgentTool` mit
`getName() = arenaagenttool` bereit. Der Typ implementiert `IAgentTool`,
erweitert `AbstractAgentResource` und ist damit zugleich `IAgentResource`.
`getToolDefinitions()` liefert darin **zwei** Funktionen. Das Plugin legt
keine Komponenten-Presets und keine Tool-Profile im SettingsStore an.

Der Einrichtungsweg in drei Stufen:

1. **Agent Components**: ein Preset mit ID `arena-agent-tool`, Typ `arenaagenttool`, `enabled=true`, Capability `tool`, leere Config und keine Docks erstellen.
2. **Tool Profiles**: das interne Tool-Profil `arena-agent-tool-profile` aktivieren und **nur das eine Preset `arena-agent-tool`** zuordnen (`type=internal`, `internal_enabled=true`, `mcp_enabled=false`).
3. **Agent Administration**: beim festen Agenten `NEXUS` das Profil `arena-agent-tool-profile` auswaehlen und speichern.

Das nachstehende JSON ist **nur ein Strukturbeispiel**, kein automatischer Import:

```json
{
  "component_preset": {
    "id": "arena-agent-tool",
    "type": "arenaagenttool",
    "enabled": true,
    "capabilities": ["tool"],
    "config": {},
    "docks": {}
  },
  "tool_profile": {
    "id": "arena-agent-tool-profile",
    "type": "internal",
    "enabled": true,
    "internal_enabled": true,
    "mcp_enabled": false,
    "tools": ["arena-agent-tool"]
  }
}
```

Der Ressourcentyp stammt aus dem discoverbaren `getName()`. Die Preset-ID
und die Profil-ID sind eigenstaendige MissionBay-Konfigurationsidentitaeten.
Das Tool-Profil wird durch den vorhandenen `AgentToolProfileResolver`
aufgeloest. Kein Bootstrap-Seeding und keine zweite Profilverwaltung.

### Funktion 1: Arena-Zustand lesen

**Ressource:** `ArenaAgentTool` (`arenaagenttool`).
**Funktion:** `arena_get_state`.

```json
{}
```

Ergebnis: `ok=true` mit `state` (Phase, Runde, Punktestand, Roboter,
Hindernisse, Kontrollpunkte und gueltige Aktionen). Diese Funktion ist
`readOnlyHint=true`, `mutation=false`, `requiresApproval=false`.

### Funktion 2: KI-Zug einreichen

**Dieselbe Ressource:** `ArenaAgentTool` (`arenaagenttool`).
**Funktion:** `arena_submit_turn`.

```json
{
  "commands": [
    {"unit":"a_guardian","type":"move","x":6,"y":3},
    {"unit":"a_guardian","type":"guard"},
    {"unit":"a_scout","type":"move","x":6,"y":1},
    {"unit":"a_scout","type":"guard"},
    {"unit":"a_ranger","type":"move","x":7,"y":5},
    {"unit":"a_ranger","type":"guard"}
  ]
}
```

Das gesamte Kommando-Array wird durch den bestehenden GameService validiert.
Ein Regelverstoss liefert `ok=false`, ohne eine einzige Teilaktion zu speichern.
Eine leere Liste ist ein zulaessiger Pass-Zug. Diese Funktion ist
`readOnlyHint=false`, `mutation=true`, `sideEffectHint=true`,
`requiresApproval=false`, `commitGuardRequired=false`. Die beiden letzten
Einstellungen gelten ausschliesslich fuer den menschlich gestarteten und
serverseitig abgesicherten KI-Spielzug, nicht fuer allgemeine Schreibtools.

### Namespacing: ein Preset, zwei Funktionen

MissionBay legt durch `AgentComponentPresetMaterializer` um das Preset eine
`ConfiguredAgentToolResource`. Beide Funktionsnamen werden mit **demselben**
Preset-Namensraum versehen. Bei Preset-ID `arena-agent-tool` lauten sie:

- `arena_agent_tool__arena_get_state`
- `arena_agent_tool__arena_submit_turn`

Der Wrapper uebersetzt beide Namen vor `callTool()` wieder auf den
urspruenglichen Funktionsnamen. Der System-Prompt muss deshalb den jeweils
tatsaechlich angebotenen Namen verwenden, nicht willkuerlich erdachte Namen.
Ein anders benanntes Preset aendert nur das gemeinsame Praefix.

Ohne einen laufenden, serverseitig autorisierten KI-Claim kann keine der beiden
Funktionen Matchdaten lesen oder veraendern. Match-ID und Ausfuehrungsnonce
stammen ausschliesslich aus vertrauenswuerdigem Laufzeitkontext, nicht aus
LLM-Argumenten oder Browserdaten.

## 4. Agentenausführung pro Partie

Das Arena-Plugin ruft die vorhandene `IAgentExecutionService` auf und liefert
konfigurierten Agenten, festen Zugauftrag und vertrauenswürdigen Kontext.
Der Kontext enthält unter anderem:

```json
{
  "agent_id": "agentarena.opponent",
  "agent_run_mode": "manual",
  "conversation_channel_id": "agentarena.match",
  "conversation_id": "<MATCH_ID>",
  "arena_match_id": "<MATCH_ID>",
  "arena_nonce": "<SERVER_GENERATED_ONE_TIME_VALUE>"
}
```

Das ist **nur ein dokumentarisches Schema**, kein von Benutzern einzureichendes
API-Payload. `arena_nonce` darf nicht im Browser, Agenten-PDF oder
öffentlich sichtbaren Spielzustand erscheinen. Der Agent bekommt
zuggebundene Tools und kann keine abweichende Match-ID wählen.

In Schritt 1 wird **keine neue Chat-History oder PDF-Ressource** erzwungen.
Die dafür bereits verfügbaren MissionBay-Felder sind:

| Feld | Verwendung in Schritt 2 |
| --- | --- |
| `memory_profile` | Optional erst in einem spaeteren Schritt; in Schritt 1 leer |
| `context_profile` | Vorhandene Kontextbeiträge |
| `agent_components` | Vorhandene konfigurierte Komponenten, falls tatsächlich benötigt |
| `capability_sources` | Explizite, existierende Capability-Quellen |
| `capability_selection` | Auswahl entsprechend dem installierten Runtime-Schema |

Diese Felder werden in Schritt 1 bewusst nicht mit erfundenen Profil- oder
Ressourcen-IDs belegt. Die Match-Datenbank ist in allen Schritten die einzig
autoritative Quelle für den Spielzustand.

## 5. Prompt, Memory und Context: Was muss man wirklich eintragen?

| Einstellung | Schritt 1 | Warum |
| --- | --- | --- |
| `system_prompt` | **Ja** | NEXUS-Identitaet, Regeln und korrekte Tool-Benutzung. Bereits als Code-Default hinterlegt und editierbar. |
| `user_prompt` | **Leer** | `ArenaAgentTurnService` erzeugt bei jeder KI-Phase einen Laufzeit-Prompt. Kein statischer Zusatzauftrag. |
| `memory_profile` | **Leer** | Fuer die Entscheidung reicht der aktuelle, autoritative Matchzustand aus `arena_get_state`; Chat-History ist nicht notwendig. |
| `context_profile` | **Leer** | `AgentExecutionRequest` liefert Match-ID, Nonce und Ausfuehrungsmetadaten. Ein zusaetzlicher System-Prompt-Kontext wird nicht benoetigt. |
| `chatmodel` | **Auswaehlen** | Bereits konfiguriertes, tool-faehiges Chatmodell. |
| `orchestrator_profile` | **Auswaehlen** | Ein vorhandenes MissionBay-Profil mit mehreren Tool-Loops, z.B. `native-tool-loop`. |
| `tool_profiles` | **`arena-agent-tool-profile`** | Vom Betreiber angelegtes Profil mit dem einen Preset `arena-agent-tool`, das beide Funktionen enthaelt. |

Reihenfolge in der UI: erst Komponenten-Presets, dann Tool-Profil, zuletzt NEXUS bearbeiten und aktivieren. Andere globale Tools oder ILIAS-Administrationsfunktionen werden nicht gebraucht.

**Wichtig zur Speicherung:** Die festen `NexusAgentDefinition`-Defaults liefern lediglich initiale bearbeitbare Werte. Ein bereits gespeicherter `agentarena.opponent`-Datensatz hat Vorrang. Wenn NEXUS in der UI bereits ohne Arena-Tool-Profil gespeichert wurde, muss es dort ausgewaehlt und erneut gespeichert werden. Keine Bootstrap-Seeds und keine direkten Schreibzugriffe auf `ISettingsStore`-Gruppe `agent`.

## 6. Abnahme und Grenzen

- Agentenliste zeigt NEXUS mit fixer ID und unveränderlichem Label.
- Effektive Konfiguration enthält `tool_profiles = ["arena-agent-tool-profile"]`.
- Runtime und Chatmodell lassen sich tatsächlich auflösen.
- Der Agent ruft die beiden Funktionen `arena_get_state` und `arena_submit_turn` derselben Ressource auf.
- Ohne gültige Ausführungs-Nonce scheitern beide Tool-Aufrufe.
- Der Model-Text allein verändert keine einzige Einheit.
- Falsche Züge ändern weder Punkte noch Positionen noch Revision.
- Nach erfolgreichem Zug kann der Agent nicht erneut committen.
- Fremde Benutzer erhalten keine Match-Daten.

**Nicht lokal verifiziert:** aktuelle MissionBay-Formvalidierung, effektive
Tool-Profile-Materialisierung in der Zielinstallation sowie echte Modellaufrufe.
Der Quellabgleich erfolgte mit AssistantFoundation 4.18.0 und MissionBay 4.40.0;
die vorhandenen Implementierungen in der Zielinstallation wurden nicht ausgefuehrt.
Ein vollstaendiger Host-Integrationstest ist daher vor Freigabe erforderlich.


## Nachtrag Schritt 2

Die bereitgestellte Installation hat bereits das Profil `arena-agent-tool-profile`
mit genau einem Preset `arena-agent-tool` eingerichtet. Der aktuelle Export
bestätigt außerdem `openai_6_luna`, `native-tool-loop`, leeren User-Prompt,
kein Memory-Profil und kein Context-Profil. Das ist für den nächsten Stand
richtig und wird durch den Patch nicht überschrieben.

Die Tool-Funktion `arena_submit_turn` akzeptiert zusätzlich das optionale
Textargument `radio` (maximal 160 Zeichen). `arena_get_state` liefert das
neue `tactical_briefing` aus dem bestehenden Match-Ereignisverlauf. Die
Aufrufnamen bleiben im selben MissionBay-Preset-Namensraum. Die zwei
PDF-Handbücher sind optionale Ressourcen und kein neues Profil.

Siehe `docs/step2-agent-knowledge.md` für den nachgewiesenen Mechanismus
der optionalen PDF-Einbindung und die Grenzen des aktuellen Agentenexports.
