# Agent Arena: Technisches Datenschutzinventar

Stand: 2026-11-09, Agent Arena 0.3.0. Dieses Dokument beschreibt den implementierten Plugin-Quellstand. Es ist keine deployment-spezifische Datenschutzerklärung und keine Behauptung vollständiger DSGVO-Konformität.

## Zweck und verarbeitete Daten

Agent Arena ermöglicht einem authentifizierten Host-Benutzer das Spielen gegen einen LLM-Agenten. Für diese Funktion verarbeitet die Anwendung:

| Daten | Herkunft | Zweck | Speicherort |
| --- | --- | --- | --- |
| Native Benutzer-ID und genaue Autoritäts-/Instanz-ID | Host-`IUsermanager` und `IAuthorizationService` | Zuordnung und Berechtigungsprüfung von Matches | `agentarena_matches.owner_id`, `authority` |
| Zufällige 128-Bit-Match-ID | Plugin | Identifikation einer Partie | `agentarena_matches.id` und Spielpayload |
| Kartenkennung, Einheiten, Positionen, Aktionen, Lebenspunkte, Punkte, Rundenzahl und bisherige Zugereignisse | Spieler und deterministische Regeln | Partie fortsetzen, taktische Rückschau und Regeln prüfen | `agentarena_matches.payload` |
| Optionaler NEXUS-Funkspruch, maximal 160 Zeichen | LLM-Antwort nach bestätigtem Tool-Zug | Spielwelt und Funkspruchanzeige | Als Ereignis in `agentarena_matches.payload` |
| Momentane zufällige KI-Ausführungsnonce und Startzeit | Plugin | Sperre gegen gleichzeitige oder überholte KI-Züge | Im Matchpayload bis zur Freigabe oder Commit |
| Revisionsnummer und Änderungs-/Erstellungszeit | Plugin/DB | Konkurrenzkontrolle, Wiederaufnahme | `agentarena_matches.revision`, Zeitstempel |
| Agent-Anfragekontext und Spielinformationen | Plugin und Tool-Ergebnis | LLM entscheidet einen KI-Zug | In der Agent-Runtime, bei externem LLM möglicherweise an den Modellanbieter übertragen |

Die Agentenausführung erhält eine Match-ID, Ausführungsnonce und Konversationskennung. Die gewählte Karte (`map_id`), Hindernisse und Kontrollpunkte sind Teil des existierenden Spielpayloads und können über das Arena-Tool an das LLM gelangen. Für mögliche PDF-Modellressourcen erhält die bestehende MissionBay-Runtime außerdem die festen Dateibesitzerangaben `agent` und `agentarena.opponent`. Tools geben nur autorisierte Matchdaten zurück. Die Nonce wird vor der Frontend-Ausgabe entfernt. Das Plugin führt selbst keine LLM-Prompt- oder Modellantwort-Logs, erstellt keine separate Chat-Historie und implementiert keinen zusätzlichen Memory-Speicher. Der bereits im Match enthaltene Ereignisverlauf kann bis zu 256 Einträge umfassen. Der Replay-Endpunkt rekonstruiert für den authentifizierten Besitzer Ansichten ausschließlich aus diesem Verlauf, ohne zusätzliche Tabelle oder persistente Replay-Kopie. Frühere Matches mit unvollständiger Historie können nicht wiedergegeben werden.

**Optionale PDF-Wissensressourcen:** Zwei statische Spielhandbücher werden mitgeliefert. Sie sind erst beim tatsächlichen Hinterlegen im bestehenden Managed File Storage (`agent/agentarena.opponent`) Modellressourcen. MissionBay kann solche PDF-Dateien über einen dateifähigen Chatmodelltreiber bei einem externen Anbieter hochladen; dort können Providerdatei-Referenzen und abweichende Löschfristen bestehen. Der Upload und die Providerlöschung werden durch bestehende MissionBay-Dienste verwaltet, nicht durch dieses Plugin. Das `delete_all`-Spielkommando entfernt keine separat gespeicherten PDF-Dateien und keine externen Providerdateien.

**Externe Verarbeitung:** Wenn im tatsächlich konfigurierten MissionBay-Agenten ein externer Modellanbieter ausgewählt ist, werden Teile der Spielsituation und des Tool-Ergebnisses an diesen Anbieter übertragen. Region, Auftragsverarbeitung, Unterauftragsverarbeiter, Löschzusagen und eventuelle Trainingsnutzung hängen vom realen Modellprofil und der Deployment-Konfiguration ab. Sie lassen sich aus diesem Plugin nicht verifizieren und müssen durch den Verantwortlichen geprüft werden.

**Memory-Risiko:** Die feste Agentendefinition konfiguriert selbst kein Memory-Profil. Wird durch die Administration dennoch ein Profil ergänzt, kann die vorhandene MissionBay-Runtime Konversationsinhalte speichern. Der `delete_all`-Befehl löscht nur Arena-Matches. Die Löschung eines zusätzlich aktivierten Agenten-Memorys gehört dann zu dessen bestehendem Eigentümer `IAgentConversationService` und muss im Einsatz geprüft werden, bevor Memory aktiviert wird.

## Zugriff, Empfänger und Sicherheitskontrollen

Nur der vom Host authentifizierte Benutzer kann seine eigenen Matches lesen und bearbeiten. Alle HTTP-Aktionen benötigen `IAuthorizationService::decide(ALLOW)`, tatsächliche Host-ID, Same-Origin-JSON-Request und Eigentümerabgleich in der Datenbank. Jede Mutation wird atomar anhand der aktuellen Matchrevision ausgeführt. Der Agent erhält keine generischen Host-Berechtigungen und darf über die Tool-Funktionen ausschließlich KI-Einheiten des eigenen Matches steuern.

Empfänger sind die betreibende Anwendung, deren Datenbankadministration und die über MissionBay tatsächlich konfigurierten LLM-/Logging-Dienste. Verschlüsselung im Ruhezustand, Backups, vollständige Transportunterstützung und Empfängerübermittlungen sind hostingabhängig und hier nicht belegt. Keine Tracking-Skripte, Werbung oder Statistikdienste sind im Plugin enthalten. Statische CSS-/JS-Assets werden über den BASE3-AssetResolver eingebunden. Die Schaltflächen Anleitung, Vollbild, Kartenauswahl und Replay-Steuerung sind clientseitig. Das Partienarchiv liest maximal 20 Match-Metadatensätze über denselben autorisierten JSON-Endpunkt. Die Wiedergabe wird nur temporär im Browser gehalten und speichert keine neue dauerhafte Historie.

## Dauer, Löschung und Betroffenenrechte

Die Arena-Tabelle speichert einschließlich ihres bis zu 256 Ereignisse umfassenden Spielverlaufs und optionaler Funktexte Matches, bis der Besitzer **Alle meine Partien löschen** ausführt oder der Betreiber einen Datensatz entfernt. **Derzeit gibt es keine automatische zeitgesteuerte Löschfrist.** Das ist eine offene betriebliche Entscheidung und muss vor produktiver Bereitstellung mit der zuständigen Stelle geklärt werden. Ein `delete_all` entfernt alle Arena-Matches für die konkret authentifizierte Autorität und Host-Benutzer-ID. Eine abgegebene Agentenanfrage an einen externen LLM-Provider kann damit nicht aus dessen Systemen entfernt werden.

Eine Löschung in der Spieltabelle bedeutet nicht automatisch die Löschung von Datenbankbackups, externen Agent-Logs oder separat konfigurierten Memory-Profilen. Solche Kopien und die Wahrnehmung von Auskunfts-, Berichtigungs-, Lösch- und Widerspruchsrechten unterliegen den bestehenden betriebs- und anbieterseitigen Prozessen. Vor Datenbank- oder Plugin-Entfernung ist die Erhaltung eventuell benötigter Datensätze mit dem Betreiber abzustimmen.

## Minderjährige und besondere Datenkategorien

Das Spiel benötigt weder besondere Kategorien personenbezogener Daten noch freie Texteingaben durch Spieler. Der Agent entscheidet auf Grundlage technischer Spielsituationen. Der Host ist verantwortlich für zulässige Zielgruppen, Bereitstellung, Aufsicht und Rechtsgrundlage.

## Barrierefreiheit

Die Oberfläche bietet Tastatursteuerung, sichtbare Fokusindikatoren, `aria-label` für Felder und Replay-Bedienelemente, Live-Status und Animationen mit `prefers-reduced-motion`. Kartenauswahl und Archiv verwenden native Dialoge, die Wiedergabe einen regulären Range-Schieberegler. Der native Anleitung-Dialog bietet Schließen mit Esc und Rückgabe des Fokus; der Vollbild-Button hält seinen aktiven Zustand über `aria-pressed` fest. Die Browserunterstützung und eingebettete Frame-Berechtigungen können den Vollbildmodus beschränken. Die Projektvorgabe WCAG 2.2 AA muss im eingebetteten Host manuell und automatisiert geprüft werden; eine solche vollständige Prüfung wurde in dieser Containerumgebung nicht durchgeführt.

## Vor Release zu prüfen

- Rechtsgrundlage, Information und externe Modellverträge im konkreten Deployment.
- Aufbewahrungsfrist, sichere Bereinigung alter Matches, Backup-Lebenszyklus und Agentenlogs.
- Vor Nutzung der optionalen PDF-Ressourcen: Berechtigung zur Managed-File-Sammlung, dateifähigen Modelltreiber, Providerübertragung und Löschprozesse prüfen.
- Vor Aktivierung von Memory: tatsächliche Konversationszuordnung und Löschweiterleitung beim zuständigen `IAgentConversationService`.
- Host-Zugriff via HTTPS und Same-Origin-JSON-Requests mit `Sec-Fetch-Site`, `Content-Type: application/json`, Session- und Authorisierungsverhalten verifizieren.
- Host-übergreifende Rechteprüfungen, Fehlersituationen, WCAG-Prüfung und reale Modell-/Tool-Ausführung.

## Änderung 0.3.0: Karten und Replay

Quelle: `src/Game/ArenaRules.php`, `src/Repository/ArenaMatchRepository.php`,
`src/Service/ArenaGameService.php`, `src/Output/ArenaApiOutput.php`,
`assets/arena/arena.js`, `test/Game/ArenaReplayTest.php`. Kartenauswahl,
Lesen des Archivs und Replay erfolgen weiterhin im vorhandenen
Sicherheitsbereich. Der neue `history`-Aufruf liefert nur Kartenkennung,
Rundenzahl, Ergebnis, Punkte und Datenbank-Zeitstempel der maximal 20 letzten
Matches des authentifizierten Eigentümers. Der `replay`-Aufruf gibt für eine
abgeschlossene, gültige eigene Partie rekonstruierte Einheitenpositionen,
Aktionen, Punkte und zeitliche Abfolge zurück. Keine neuen Datenbanktabellen,
keine Browser-Storage-APIs, kein Analytics- oder Drittanbieter-Dienst.

Ein früher gespeicherter unvollständiger Ereignisverlauf kann kein
nachträgliches Replay liefern. Der normale Spielstand und die Löschfunktion
bleiben dennoch unverändert. Ein Live-Test des neuen Archivs und der
Replays mit der produktiven Host-Benutzerzuordnung ist offen.
