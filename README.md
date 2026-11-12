# Agent Arena 0.3.0: vollständiges Spiel

Agent Arena ist ein eingebettetes BASE3-Taktikspiel. Das Spiel wird durch `AgentArena\Display\AgentArenaDisplay` (`IDisplay`) dargestellt. Die Simulation besitzt feste serverseitige Regeln. Der LLM-Agent **NEXUS** spielt ausschließlich über eine MissionBay-AgentResource (`IAgentTool`), die zwei Funktionen anbietet.

## Spielinhalt

- 9 x 7 Felder auf drei wählbaren, festen Karten mit je drei Kontrollpunkten und drei Robotern pro Team.
- Pro Einheit höchstens eine Bewegung, danach ein Angriff oder eine Verteidigungsstellung.
- Scout: 5 LP, Bewegung 3, Nahkampf 2. Ranger: 6 LP, Bewegung 2, Fernkampf 2 bei freier Linie bis 3 Felder. Guardian: 9 LP, Bewegung 2, Nahkampf 3.
- Nach jeder vollständigen Runde erhält ein Team je besetztem Kontrollpunkt einen Punkt. Acht Punkte, vollständige Eliminierung oder das Ende von Runde zehn entscheiden die Partie. Bei Punktgleichstand nach Runde zehn entscheiden verbleibende Lebenspunkte, danach Unentschieden.
- Ein menschlicher Zug wird durch den Spieler eingereicht. NEXUS muss seine eigene vollständige Zugliste mit `arena_submit_turn` atomar einreichen.

## Installation

Am BASE3-Projektroot, dessen Plugin-Suche `plugin/` umfasst:

```sh
unzip -o agentarena_0.3.0_final_patch.zip
php plugin/AgentArena/test/run.php

# Nur falls aus einem sehr alten Stand vor 0.1.2 aktualisiert wird:
sh plugin/AgentArena/install/remove-obsolete-tool-files.sh
```

Bei einem eingebetteten Host muss die Plugin-Ablage mit dessen bereits bestehender ClassMap- und Asset-Auflösung übereinstimmen. Keine zusätzliche Router- oder Bootstrap-Implementierung installieren. Nach dem Entpacken den normalen BASE3-Bootstrap mit aktiviertem DatabaseMigrationRunner ausführen, der `ArenaMatchMigrationProvider` entdeckt.

### Erforderliche bereits existierende Dienste

- `IUsermanager`, `IAuthorizationService`: gültige native Benutzeridentität sowie ausdrückliches ALLOW für `feature/arenaplayfeature`.
- `IDatabase` und `IMigrationRunner`: aktive MySQL-/MariaDB-Verbindung und DatabaseMigrationRunner für die Arena-Tabelle.
- `IMvcView`, `IAssetResolver`, `ILinkTargetService`, `IRequest`: vorhandene BASE3-Webintegration.
- `IAgentConfigurationService` und `IAgentExecutionService`: Agent-Konfiguration und Runtime-Router aus der aktuellen AssistantFoundation-/MissionBay-/AssistantRuntime-Komposition.
- `MissionBay\Api\IAgentTool` und `MissionBay\Api\IAgentResource`: die einzelne Toolklasse `ArenaAgentTool` wird durch die normale ClassMap entdeckt und bietet beide Funktionen an. Profilverwaltung ausschliesslich in MissionBay.

**Quellstand:** Die Schnittstellen wurden gegen AssistantFoundation 4.18.0 und MissionBay 4.40.0 abgeglichen. Der Benutzer hat die vorherige Arena-Version 0.2.1 im ILIAS-Host als funktionierend bestaetigt. Die neuen Funktionen von 0.3.0 sind dort noch nicht getestet.

### Ausführliche NEXUS-Beispielkonfiguration

Unter `docs/nexus-agent-configuration.example.json` liegt eine ausführliche Beispielkonfiguration mit taktischem System-Prompt, vorgeschlagenem Tool-Profil und bewusst nicht ausgefüllten Modell- und Orchestrator-Referenzen. Die Erklärung aller Felder, Tool-Argumente, Ausführungsdaten und Integrationseinstellungen steht in `docs/nexus-agent-configuration.md`. Diese Dateien sind Beispiele, kein zusätzlicher Konfigurationsspeicher und kein automatischer Import.

### NEXUS aktivieren

1. Bei einem Update von frueheren Arena-Patches das Bereinigungsskript ausfuehren. Es entfernt ausschliesslich nicht mehr verwendete Toolklassen; bei einer Erstinstallation ist es ebenfalls unbedenklich.
2. MissionBay: **Agent Components** oeffnen, genau ein Komponenten-Preset `arena-agent-tool` mit dem Ressourcentyp `arenaagenttool` erstellen. Aktivieren, Capability `tool` waehlen; keine Docks oder Zusatzkonfiguration.
3. MissionBay: **Tool Profiles** oeffnen, `arena-agent-tool-profile` als internes, aktiviertes Profil anlegen und **nur das Preset `arena-agent-tool`** aufnehmen. Das eine Preset liefert bereits beide Funktionen. MCP ist nicht erforderlich.
4. MissionBay: **Agent Administration** oeffnen, den festen Agenten `agentarena.opponent` (`NEXUS`) bearbeiten, vorhandenes Chatmodell und Orchestrator-Profil waehlen und `arena-agent-tool-profile` als Tool-Profil zuordnen. Ein gespeicherter Agenten-Datensatz kann den Code-Default ueberschreiben.
5. Statischen User-Prompt, Memory-Profil und Context-Profil fuer das Spiel leer lassen. Der Spielservice liefert pro KI-Zug den dynamischen Auftrag und die Match-Kontextdaten; `arena_get_state` liefert den tatsaechlichen Spielstand.
6. NEXUS aktivieren und einen KI-Zug pruefen. Die Definition startet absichtlich mit `enabled=false`.

Die beiden Funktionen sind `arena_get_state` und `arena_submit_turn`. Nach MissionBays Preset-Namensraum lauten sie bei der Preset-ID `arena-agent-tool` normalerweise `arena_agent_tool__arena_get_state` und `arena_agent_tool__arena_submit_turn`.

Ausfuehrliche Preset- und Tool-Profil-Beispiele: `docs/nexus-agent-configuration.md`.

### Display einbetten

In einem vorhandenen Host über die ClassMap die `IDisplay`-Implementierung `agentarenadisplay` auflösen und `getOutput('html', false)` rendern. Optional:

```php
$display->setData(['match_id' => $ownedMatchId]);
```

Ohne übergebene Match-ID wird die zuletzt gespeicherte Partie des authentifizierten Benutzers geladen. Nur Besitzer mit einer gültigen BASE3-Feature-Entscheidung erhalten den Spielstand.

### Serveraktionen

Der separate `ArenaApiOutput` ist ein gewöhnliches BASE3-`IOutput`, das vom bestehenden LinkTargetService verlinkt wird. Akzeptiert wird nur `POST` mit strikt `application/json`, `Sec-Fetch-Site: same-origin` und nachgewiesener Benutzer-/Featureberechtigung. Aktionen: `start`, `resume`, `snapshot`, `command`, `end`, `ai`, `delete_all`, `history`, `replay`. `command` und `end` benötigen die aktuelle `revision`; bei konkurrierenden Requests gewinnt genau ein CAS-Update, der andere erhält `stale_state`.

Die Oberfläche speichert keinen Spielzustand außerhalb der Datenbank. Der ausstehende Agentenlauf ist über eine matchgebundene Nonce abgesichert. Der Tool-Aufruf übernimmt weder eine fremde Match-ID aus Modellargumenten noch eine vom Client behauptete Benutzeridentität.

### MissionBay-AgentResource und Tool-Profil

Das Plugin stellt genau **einen** discoverbaren Ressourcentyp bereit:
`ArenaAgentTool` (`getName() = arenaagenttool`). Er erweitert
`MissionBay\Resource\AbstractAgentResource` (implementiert bereits `IAgentResource`)
und implementiert `MissionBay\Api\IAgentTool`.

`getToolDefinitions()` liefert zwei eigenstaendige Funktionsdefinitionen:

- `arena_get_state`: rein lesend, liefert das aktuelle serverseitig freigegebene Match und moegliche Spielzuege.
- `arena_submit_turn`: schreibt eine vollstaendige, atomar validierte KI-Aktionsliste.

Beide Funktionen werden mit `callTool()` am **gleichen** Resource-Objekt ausgefuehrt. Die Autorisierung und der aktuelle einmalige Ausfuehrungs-Claim werden an der gemeinsamen Domain-Grenze geprueft. Jede Funktion behaelt eigene Eingabe- und Sicherheitsmetadaten.

**Eine Toolklasse, ein Komponenten-Preset, ein Tool-Profil.** Das Preset `arena-agent-tool`
referenziert `arenaagenttool`, das Profil `arena-agent-tool-profile` referenziert dieses
Preset. Das Plugin legt keines davon im SettingsStore an; die bestehende
MissionBay-Administration bleibt die einzige Konfigurationsstelle.

`arena_submit_turn` nutzt als eng begrenzte, durch einen bereits gestarteten
Spielzug freigegebene Mutation `requiresApproval=false` und
`commitGuardRequired=false`. Host-Autorisierung, matchgebundene Nonce,
Regelvalidierung und atomarer Revisionsvergleich bleiben zwingend. Diese
Ausnahme gilt nicht fuer andere Schreibtools.

Die Nonce gehoert ausschliesslich zum vertrauenswuerdigen AgentExecutionRequest-
Kontext. Sie erscheint weder im Frontend noch im Tool-Ergebnis. Ein abgebrochener
KI-Lauf gibt den unvollstaendigen Claim wieder frei.

## Teststatus und Abnahme

Im bereitgestellten Container wurden PHP-Syntaxprüfung, JavaScript-Syntaxprüfung, INI-Übersetzungsabgleich und die deterministischen Spiele-Smoketests ausgeführt. Weitere PHPUnit-Tests liegen unter `test/`, benötigen aber PHPUnit und die aktuelle Framework-Komposition.

Vor produktivem Einsatz zusätzlich im Zielprojekt prüfen:

1. Alle Dienste und die aktuellen AssistantFoundation- und MissionBay-Klassen lassen sich per DI/ClassMap aufloesen.
2. Ein Resource-Typ `arenaagenttool` ist in den Agent Components sichtbar; sein einzelnes Preset ist im Tool-Profil aktiv und NEXUS fuehrt beide Funktionen aus.
3. Eine komplette Partie endet regelkonform mit Benutzerzug, KI-Zug, Sieg oder Unentschieden.
4. Zwei konkurrierende Requests mit identischer Revision führen nicht zu Doppelzügen.
5. Anonyme, nicht berechtigte und fremde Benutzer erhalten weder Spielstand noch Mutation.
6. Ungültige Tool-Kommandos ändern den Spielstand nicht. Alte AI-Nonces werden nach dem Claimwechsel abgelehnt.
7. Standalone sowie alle gewünschten eingebetteten Host-Integrationen werden tatsächlich über ihren normalen Webpfad getestet, einschließlich Same-Origin-JSON- und Sessionverhalten.

Nicht als abgeschlossen getestet: MissionBay-Liveausführung, produktive Datenbankmigration, Host-HTTP, Last- und Revocation-Tests.

## Daten und Entfernen

Der Befehl **Alle meine Partien löschen** entfernt sämtliche Arena-Matches der aktuellen nativen Host-Identität. Die Datenbanktabelle wird durch das Löschen des Plugins nicht automatisch entfernt, da sonst Nutzerdaten verloren gehen könnten. Eine vollständige Entfernung umfasst bewusste Datenlöschung und das Entfernen des Pluginverzeichnisses. Siehe `PRIVACY.md` für Datenkategorien, begrenzte Verifizierbarkeit und Aufbewahrung.

## Schritt 2: taktische Erweiterung (Version 0.2.0)

Schritt 2 ist in diesem Patch enthalten und respektiert die gespeicherte NEXUS-Konfiguration:

- `arena_get_state` liefert zusätzlich `tactical_briefing` mit tatsächlicher Kontrollpunktbelegung und beobachteten Aktionen der bisherigen Partie. Der Eventverlauf bleibt im bestehenden Matchpayload erhalten, ohne neue Memory-Persistenz.
- `arena_submit_turn` akzeptiert optional `radio` (bis 160 Zeichen). Nur ein erfolgreich bestätigter KI-Zug speichert den deutschen NEXUS-Funkspruch als gewöhnliches Event derselben Matchrevision. Das bestehende Display zeigt ihn nach einem Seitenneuladen weiterhin an.
- Der User-Prompt wird durch `ArenaAgentTurnService` je Zug dynamisch erzeugt. Kein neues Tool-Profil, kein Context-Profil, kein Memory-Profil und keine neue Agent-Konfiguration sind notwendig.
- `resources/Arena_Field_Manual.pdf` und `resources/NEXUS_Tactical_Doctrine.pdf` sind die fertigen optionalen PDF-Wissensressourcen mit Markdown-Quelltext. Die bestehende MissionBay-Chatmodellressource kann eine PDF-Sammlung mit den Owner-Kennungen `agent` / `agentarena.opponent` nutzen, **wenn die Sammlung tatsächlich im vorhandenen Managed File Storage hinterlegt wurde** und das Modell PDF-Dateien unterstützt. Das Plugin erstellt keine eigene Uploadstrecke.

Das `IDisplay` hat in der oberen rechten Ecke **Anleitung** (lokalisierter, nativer Dialog mit Steuerung, Robotern, Kampf- und Siegbedingungen) und **Vollbild** (nativer Fullscreen-Modus mit Esc zum Verlassen). Der Vollbild-Button wird ausgeblendet, wenn der Browser die benötigte Fullscreen-API nicht anbietet. Eingebettete Host-Frames müssen den Browser-Vollbildmodus zulassen, falls die Einbettung dies einschränkt. Beide Bedienelemente erzeugen keinen weiteren HTTP-Endpunkt, speichern keinen zusätzlichen Zustand und sind auch während eines KI-Laufs verwendbar.

Details zur PDF-Einbindung, zur realen Agentenprofilzuordnung und zu den zusätzlichen Tests unter `docs/step2-agent-knowledge.md`.

## Schritt 3: vollständiges Spiel (Version 0.3.0)

Agent Arena enthält jetzt drei feste, symmetrische Karten: `standard` (Zentrum),
`bastion` (gedeckte Zugänge) und `crossfire` (offenere Schusslinien und versetzte
Kontrollpunkte). Bei **Neues Spiel** öffnet sich die Kartenauswahl mit drei
Miniaturvorschauen. Die gewählte `map_id` bleibt im vorhandenen Matchpayload
hinterlegt; ältere gespeicherte Matches ohne `map_id` sind weiterhin
Standard-Partien. Ein unbekannter Kartenname wird serverseitig abgewiesen.

Der neue Button **Partien** zeigt die letzten 20 aktualisierten Spiele des
aktuellen authentifizierten Benutzers. Laufende Spiele lassen sich fortsetzen.
Abgeschlossene Spiele lassen sich im **Replay** über Zurück, Vor, Abspielen,
Pause und einen Positionsregler verfolgen. Das Replay verändert keine
Spieldaten, erzeugt keine zusätzliche Zustandskopie im Backend und führt keine
KI-Ausführung durch. `ArenaRules::replayFrames()` rekonstruiert den Verlauf
mit den eigentlichen Spielregeln aus den gespeicherten Aktionen und bestätigt
abschließend, dass er dem gespeicherten Ergebnis entspricht. Fehlt ein
vollständiger Ereignisverlauf, wird das Replay abgelehnt.

Die Oberfläche besitzt jetzt einen Spielausgangsbildschirm, drei unverwechselbare
Robotersymbole, HP-Balken, Spielstandfortschritt, Karteninfos, genaueres
Aktionsprotokoll, Bewegungs- und Trefferanimationen. Mit
`prefers-reduced-motion: reduce` werden die Animationen unterdrückt. Die
Dialoge sind nativ, die Texte liegen vollständig in den `lang/Display/`
Übersetzungen. Die bestehende Funktion **Anleitung** erläutert auch
Kartenauswahl und Replay.

Die vorhandenen NEXUS-PDFs sind auf Version 0.3.0 aktualisiert. **Die bereits
eingerichtete Agenten-, Komponenten- und Tool-Profil-Konfiguration bleibt
unverändert.** NEXUS liest `map_id`, `points` und `walls` über das vorhandene
`arena_get_state`, das eine Toolklasse mit beiden Funktionen bleibt.

Die zwei neuen JSON-Aktionen `history` und `replay` verwenden denselben
`ArenaApiOutput`, dieselbe `IAuthorizationService`-Entscheidung und dieselbe
Match-Eigentümerprüfung. Sie lesen ausschließlich Daten des aktiven Host-Benutzers.
Eine Schemaänderung oder zweite Historien-Datenbank ist nicht erforderlich.

### Abnahme Schritt 3

1. Eine neue Partie auf jeder der drei Karten beginnen und alle sechs Einheiten bewegen.
2. Mit dem bestehenden NEXUS-Tool-Profil eine Partie auf jeder Karte beenden.
3. Im Archiv eine laufende Partie wieder aufnehmen, eine abgeschlossene Partie abspielen und zur laufenden Partie zurückkehren.
4. Verifizieren, dass ein Replay Spielstand und Matchrevision nicht verändert und fremde Match-IDs abgewiesen werden.
5. Fenstergröße, Vollbild, Hilfe, Mobilansicht und Tastaturbedienung in ILIAS und Standalone prüfen.
6. Berechtigungseinzug, parallele Anfragen und die Wiederherstellung des akzeptierten 0.2.1-Stands im Zielsystem testen.

Weitere technische Details und Quell-Tests: `docs/step3-game-release.md`.


## Host-AJAX-Transport (Version 0.2.1)

Das erste Arena-AJAX-Request lieferte im eingebetteten ILIAS-Host 403
`invalid_token`, weil der zusaetzliche BASE3-`IToken`-Abgleich scheiterte.
Die Arena wird im selben Browser-Origin wie ihr BASE3-Endpunkt eingebunden.
Deshalb erfordert der bestehende `ArenaApiOutput` nun strikt `POST`,
`Sec-Fetch-Site: same-origin` und `Content-Type: application/json`.
Die unnoetige zweite Token-Erzeugung im Display ist entfernt. Dies ist
**keine** generelle Freigabe von Cookie-authentifizierten POST-Endpunkten:
Form-POSTs, Cross-Origin-Fetches und Requests ohne einen nachgewiesenen
Same-Origin-Kontext werden abgewiesen. Browser ohne Fetch-Metadata-Header
sind in dieser Einbettungsvariante nicht unterstuetzt.

Die Autorisierung ueber `IAuthorizationService`, die echte Host-Identitaet,
die Match-Eigentuemerpruefung und die atomare Revisionspruefung bleiben
unveraendert. Die Feature- und Benutzerberechtigung ist keine CSRF-Pruefung.
Eine permissive CORS-/Proxy-Konfiguration ist nicht Teil des Plugins und
muss im jeweiligen Host gesondert geprueft werden.

## Version 0.3.1: Selektierbare Spielfiguren

Die Spielfeld-Schaltflaechen werden anhand der autoritativen Spielphase aktiviert.
Ein laufender AJAX-Request blockiert weiterhin jede Benutzeraktion ueber `isPlaying()`,
aber verhindert nicht mehr, dass die fertig gerenderte Partie nach Request-Ende
bedienbar bleibt. Dadurch sind die menschlichen Einheiten sofort nach Start,
Fortsetzen, eigenem Zug und NEXUS-Zug wieder auswaehlbar. Die Animationen werden
nicht durch ein zweites Rendern im `finally`-Block abgebrochen.

Regressionstest ohne Host: `node plugin/AgentArena/test/Display/ArenaBoardInteractionSmoke.js`.
Der bestehende PHP-Smoketest bleibt separat ausfuehrbar und erfordert kein Node.js.

## Vorbereitung historischer Datenbankmigrationen

Die bestehenden Migrationsklassen dieses Moduls implementieren jetzt zusaetzlich `ILegacyDatabaseMigration` und stellen eine explizite `down(): void`-Methode bereit, die `MigrationException('Not implemented')` ausloest. Dies ist keine funktionsfaehige Rueckwaertsmigration. Die bisherige `up()`-Logik, Migrationsnamen, Versionsnummern und Provider bleiben unveraendert. Der BASE3-Migrationsrunner setzt bei bereits ausgefuehrten, markierten Migrationen ausschliesslich den Checksum-Vergleich aus. Nicht ausgefuehrte Migrationen laufen weiterhin regulaer vorwaerts und werden in `base3_migrations` protokolliert. Die Vorbereitung erfordert keine manuelle Aenderung vorhandener Datenbankdaten. Der Legacy-Marker darf erst nach bewusstem Abgleich des gespeicherten Checksums mit der finalen Datei entfernt werden.
