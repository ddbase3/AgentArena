# Schritt 1: Zuständigkeiten und Prüfpunkte

## Architektonische Entscheidung

- `IDisplay`: `AgentArenaDisplay` ist ausschließlich für die Einbettung/HTML-Ausgabe verantwortlich.
- `IOutput`: `ArenaApiOutput` nutzt den vorhandenen BASE3-ServiceSelector und LinkTargetService für POST/JSON. Es existiert kein zweiter Router.
- `IAuthorizationService`: die Host-Autorität prüft `feature/arenaplayfeature`. `IUsermanager` liefert ausschließlich die native Identität.
- `ArenaRules`: autoritative, reine Spielregeln; Frontend und LLM entscheiden niemals, ob eine Aktion gültig ist.
- `ArenaGameService` und `ArenaMatchRepository`: einziges Eigentum an autoritativen Match-Zuständen, unveränderliche Revision und atomarer CAS pro Schritt.
- `IAgentConfigurationService`: effektive NEXUS-Konfiguration; keine direkten Agenten-SettingsStore-Schreibvorgänge.
- `IAgentExecutionService`: die vorhandene AssistantRuntime-Routing-API führt MissionBay aus.
- `MissionBay\Api\IAgentTool` und `MissionBay\Api\IAgentResource`: genau ein Resource-Typ `arenaagenttool` mit zwei getrennten Funktionsdefinitionen, discoverbar ueber die ClassMap. Die vorhandene MissionBay-Profilverwaltung konfiguriert ein Preset und ein Tool-Profil. Keine zweite Tool-Runtime.
- `IRequest`: nur POST, browsernachgewiesene Same-Origin-JSON-Requests; keine Plugin-CSRF-Tokenverwaltung.

## Laufzeitgrenzen

Der Agent muss aktiviert und für das feste Tool-Profil konfiguriert werden. Ein nicht ausgeführter oder ungültiger KI-Zug bleibt nach `releaseAi()` in `ai`; es gibt keinen regelbasierten Ersatzgegner. Eine angefangene AI-Claim kann erst nach 300 Sekunden durch eine neue Claim ersetzt werden, sofern der ursprüngliche Prozess unterbrochen war; die alte Nonce verliert damit ihre Wirksamkeit. Der Tool-Commit ist atomar für eine komplette KI-Aktionsliste.

Der bereitgestellte MissionBay-Quellstand 4.40.0 belegt Tool-Resource, Preset-Materialisierung, Tool-Profil-Aufloesung und Agent-Runtime. Die praktische Ausfuehrung mit einem Modell und dem echten Host bleibt integrationsseitig offen.

## Verifikationsmatrix

| Anforderung | Lokal | Zielhost |
| --- | --- | --- |
| Spielstart/Einheiten/Kontrollpunkte | PHP-Smoke bestanden | offen |
| Bewegung/Angriff/Defensive/Spielende | PHP-Smoke bestanden | offen |
| PHP-/JavaScript-Syntax | geprüft | offen |
| Deutsch-/Englisch-Katalog | geprüft | offen |
| Host-Authentifizierung und Feature-ALLOW | PHPUnit-Test geliefert, nicht ausgeführt | offen |
| MySQL-Migration und CAS-Konkurrenz | Code/Lint, keine laufende DB | offen |
| MissionBay Tool-Resource, Preset und Profil | Quellcode 4.40.0 geprueft | Host-Integration offen |
| Einbettung in ILIAS/Standalone | Quellverträge geprüft | offen |
| Reale Same-Origin- und Zugriffstests | Code/Lint | offen |
| Visuelle WCAG-Prüfung | noch nicht möglich | offen |

Keine der offenen Prüfpunkte wurde als bereits bestanden behauptet.


## Schritt 2: Quellen und Umfang

Der zuständige Regelkern `ArenaRules` erzeugt `tacticalBriefing` aus den
bereits im Matchpayload dokumentierten Zügen und der aktuellen Belegung.
Es gibt keine neue Verlaufstabelle oder einen separaten Strategiezustand.
Die Schutz- und Revisionsgrenzen bleiben unverändert: `ArenaGameService`
validiert vor dem atomaren Match-Commit auch den optionalen Funkspruch.

Die bekannte MissionBay-`ConfiguredChatModelAgentResource` liest PDFs aus
der bestehenden Managed-File-Sammlung, wenn `chatbot_config_group` und
`chatbot_config_name` im vertrauenswürdigen Kontext gesetzt sind. Für die
Arena sind das `agent` und `agentarena.opponent`. Das Plugin implementiert
weder Dateiupload noch PDF-Konvertierung, sondern liefert nur beide
statischen Handbücher als Ressourcen und deren Markdown-Quellen.

Der aktuelle Export bestätigt Modell, Orchestrator und Tool-Profil, aber
nicht den effektiven enabled-Status. Eine Live-Ausführung kann nur im
laufenden Host nachgewiesen werden.

## Display-Steuerung in Schritt 2

Die bestehenden `IDisplay`-Templates und Asset-Dateien tragen die Schaltflächen **Anleitung** und **Vollbild**. Die Anleitung nutzt das native `dialog.showModal()` und das Browser-Fokusmanagement. Fullscreen nutzt ausschließlich `Element.requestFullscreen()` und `document.exitFullscreen()`; die Schaltfläche synchronisiert `aria-pressed` über `fullscreenchange`. Bei nicht unterstützter API wird sie ausgeblendet. Es existiert keine alternative Fullscreen- oder Modalschicht. Beide Aktionen sind clientseitige UI-Funktionen ohne neue Datenhaltung oder Request-Route. Alle sichtbaren Texte werden aus den vorhandenen `lang/Display`-Katalogen bezogen.

## Schritt 3: Sourced replay and map ownership

`ArenaRules::MAPS` ist die einzige Quelle für die drei Spielfeldgeometrien.
`create($matchId, $mapId)` prüft die Kennung und schreibt die gewählte
Geometrie einmal in das bestehende Matchpayload. Altstände ohne `map_id`
werden als Standard-Karte gelesen. Es existieren keine Map-Resolver,
Map-Datenbanken oder Host-Adapter für diesen Zweck.

`ArenaMatchRepository::listOwned()` liest den tatsächlichen Tabellenbestand
für den aktuellen Host-Benutzer (maximal 20); dies ist keine zweite
Matchhistorie. `ArenaGameService::history()` und `replay()` führen die
vorhandene Eigentümer- und Featureentscheidung aus. `ArenaRules::replayFrames()`
führt die protokollierten Aktionen mit dem selben Regelkern erneut aus und
vergleicht das Endergebnis gegen den gespeicherten Matchstand. Unvollständige
oder manipulierte Logs werden verworfen. Die Replay-Antwort enthält weder
AI-Nonce noch die originale Ereignis- oder Optionsstruktur als duplizierten
Spielzustand. Replay ist nur für abgeschlossene Partien vorgesehen.

Die neue Oberfläche verwendet ausschließlich das bestehende `IDisplay`,
`ArenaApiOutput` und `IAssetResolver`. Keine Änderung an MissionBay,
Agentdefinition, Toolprofil, `IAgentExecutionService` oder Datenbankschema.
