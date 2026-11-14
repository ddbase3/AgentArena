# Schritt 2: Taktisches Wissen, Spielverlauf und NEXUS-Funk

## Konfiguration, die unverändert bleibt

Der Agent `agentarena.opponent` wurde im vorliegenden MissionBay-Export mit `openai_6_luna`, Orchestrator `native-tool-loop`, Tool-Profil `arena-agent-tool-profile` und genau einem `arenaagenttool`-Preset (`arena-agent-tool`) aufgelöst. Der Export enthält keine Diagnostics-Warnungen. `user_prompt`, `memory_profile` und `context_profile` sind leer. Das ist für Agent Arena sinnvoll.

Der Export belegt **nicht** den effektiven `enabled`-Wert des Agents. Vor dem ersten Lauf in der Agentenadministration dessen Aktivierung und das vorhandene autorisierte Match prüfen.

Das Plugin ändert die gespeicherte Agentenkonfiguration nicht. Insbesondere werden Tool-Profil-ID, Modell, System-Prompt, Memory und Context nicht per Code überschrieben.

## Was das Plugin mit dem vorhandenen Tool macht

- Die **lesende** Funktion `arena_get_state` gibt weiterhin den aktuellen autoritativen Matchzustand aus. Neu ist `state.tactical_briefing` mit Live-Belegung der drei Punkte, den letzten tatsächlich beobachteten Spieler- und KI-Aktionen und einer Zählung dokumentierter menschlicher Angriffsziele.
- Die **schreibende** Funktion `arena_submit_turn` akzeptiert weiterhin `commands` (Pflicht) und neu `radio` (optional, maximal 160 Unicode-Zeichen, ohne Steuerzeichen). Der Satz wird **mit derselben atomaren Matchrevision** nur nach erfolgreicher Zugvalidierung gespeichert.
- NEXUS erhält pro Zug einen dynamischen User-Prompt, der diese Fähigkeiten erläutert. Der bereits gespeicherte System-Prompt bleibt maßgeblich. Kein neues Tool, Preset, Tool-Profil, Context- oder Memory-Profil.
- Das Radio wird als Event im vorhandenen Matchpayload gespeichert und über das vorhandene `IDisplay` angezeigt. Die Anzeige verwendet `textContent`, keine HTML-Auswertung. Nach Neuladen erscheint der zuletzt bestätigte Funkspruch weiter.

Die Einträge eines Matches werden für maximal zehn Runden in der vorhandenen Match-JSON aufbewahrt; das bisherige Fenster von 48 Einträgen wird auf 256 angehoben. Bereits vorher abgeschnittene alte Ereignisse können nicht nachträglich hergestellt werden. Neue Partien halten ihren üblichen Spielverlauf komplett vor. Es gibt keine zusätzliche Chat-History-Tabelle.

## Optionale PDF-Ressourcen

Die mitgelieferten Originaldokumente in `resources/` sind:

- `Arena_Field_Manual.pdf`: Spielregeln, Zugformat und Werkzeuggrenzen.
- `NEXUS_Tactical_Doctrine.pdf`: taktische Entscheidungsprinzipien, Anpassung an beobachtete Aktionen und Funksprüche.

Die Markdown-Quelldokumente mit identischen Inhalten liegen daneben. Die PDF-Dateien sind statisches Referenzwissen, **keine Live-Datenquelle für Spielzustand oder Berechtigungen**.

MissionBays bestehendes `ConfiguredChatModelAgentResource` verwendet `IAiFileCapableChatModel` und die bestehende `AiProviderFileReferenceResolver`-Infrastruktur. Der Dateibesitz wird über die vertrauenswürdigen Kontextschlüssel `chatbot_config_group` und `chatbot_config_name` festgelegt. Agent Arena setzt für seinen Lauf `agent` und `agentarena.opponent`. Falls die bestehende Managed-File-Storage-Verwaltung unter dieser Eigentümerkennung eine PDF-Sammlung bereitstellt, werden die darin hinterlegten PDF-Dateien durch **die vorhandene MissionBay-Modellressource** hochgeladen und für einen kompatiblen Modelltreiber verfügbar gemacht.

Das Plugin erzeugt und befüllt diese Managed-File-Sammlung **nicht automatisch** und führt keine eigene PDF-Verarbeitung oder Upload-Route ein. Die bereitgestellte MissionBay-Agentenadministration zeigt in dem geprüften Agentenexport keinen eigenen PDF-Uploadbereich. Daher ist die tatsächliche Administrationsoberfläche zum Befüllen dieser Sammlung im Zielhost vor Verwendung zu verifizieren. Ohne PDFs bleibt die taktische Funktion aus dem beobachteten Matchverlauf vollständig nutzbar.

Die Wahl eines nicht dateifähigen Modelltreibers bei vorhandenen PDFs kann durch MissionBay ausdrücklich abgelehnt werden. Es gibt keinen zusätzlichen Ersatztreiber. Für den derzeitigen Modellpreset `openai_6_luna` ist die konkret ausgewählte Service-Treiberkonfiguration im Export nicht ersichtlich.

## Warum kein Memory- und Context-Profil?

Memory-Profile sind für persistente Konversationshistorien sinnvoll, Agent Arena braucht sie derzeit nicht: Spielzugereignisse und Rundendaten stammen aus der bestehenden autoritativen Match-JSON. Ein Context-Profil würde dieselben Matchdaten nochmals halten oder injizieren; auch das ist unnötig. Dynamischer User-Prompt und `arena_get_state` reichen aus.

Wenn künftig bewusst eine neue Funktion mit echter agentischer Konversationshistorie gefordert wird, ist die vorhandene MissionBay-Memory zuständig, nie eine eigene Arena-Memory-Implementierung.

## Anzeige und Bedienung

Die vorhandene `AgentArenaDisplay`-Oberfläche enthält oben rechts die Schaltflächen **Anleitung** und **Vollbild**. Die Anleitung ist ein natives HTML-Dialogfenster mit Spielablauf, Roboterfähigkeiten, Kampf- und Siegbedingungen. Es schließt über den Dialog-Button oder Esc und gibt den Tastaturfokus an den Ausgangsbutton zurück. Texte liegen in den bestehenden deutschen und englischen Display-Katalogen.

Der Vollbildmodus verwendet die Browser-Fullscreen-API für das vorhandene Arena-Element, nicht eine zweite Darstellung. Der Button zeigt den aktiven Zustand an und ermöglicht das Verlassen; Esc beendet den nativen Browser-Vollbildmodus. Falls der Browser oder die Host-Einbettung Vollbild verhindert, wird der Zustand nicht simuliert. Die Aktion verändert weder das Match noch MissionBay.

## Testen

1. `unzip -o agentarena_step2_patch.zip` am BASE3-Projektroot.
2. Falls das alte Bereinigungsskript noch nicht gelaufen ist, `sh components/Base3/AgentArena/install/remove-obsolete-tool-files.sh` einmal ausführen.
3. `php components/Base3/AgentArena/test/run.php` für die deterministischen Tests.
4. In der vorhandenen Agentenadministration prüfen: NEXUS aktiviert, Tool-Profil `arena-agent-tool-profile` ausgewählt, ein Preset `arena-agent-tool` mit Typ `arenaagenttool`.
5. Eine Partie starten, zwei unterschiedliche menschliche Züge spielen, anschließend in den Tool-Resultaten `tactical_briefing` und die beobachteten Aktionen prüfen.
6. Optional mit PDF-Sammlung testen: im konfigurierten File-Owner `agent/agentarena.opponent` zwei PDFs ablegen und die Provider-Dateifähigkeit nachweisen, nicht aus der Anzeige allein schließen.
7. Auf dem Spielbrett einen über das Tool bestätigten Funkspruch prüfen, Seite neu laden, Funkspruch muss erhalten bleiben. Ungültige Funktexte und illegale Züge dürfen keine Mutation auslösen.
8. **Anleitung** öffnen und über Schließen, Spielen und Esc beenden; mit Tastatur und Screenreader prüfen, dass Fokus im Dialog geführt und nach Schließen wiederhergestellt wird. Deutsch und Englisch prüfen.
9. **Vollbild** aktivieren, mit Button und Esc wieder verlassen. In Host-Frames beachten, ob Fullscreen freigegeben ist; keine zweite Anzeige verwenden.

Live-Erfolg und Host-Integration sind durch den lokalen Quellstand nicht nachgewiesen.
