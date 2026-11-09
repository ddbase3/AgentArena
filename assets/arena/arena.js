(function () {
    'use strict';

    function mount(root) {
        if (root.dataset.aaMounted === '1') { return; }
        root.dataset.aaMounted = '1';
        var conf = JSON.parse(root.querySelector('[data-aa-config]').textContent);
        var t = conf.text;
        var find = function (key) { return root.querySelector('[data-aa-' + key + ']'); };
        var board = find('board'), message = find('message'), radioPanel = find('radio-panel');
        var radioText = find('radio-text'), error = find('error'), unitPanel = find('unit'), log = find('log');
        var startButton = find('start'), historyButton = find('history'), deleteButton = find('delete');
        var guardButton = find('guard'), endButton = find('end'), aiButton = find('ai');
        var mapDialog = find('map-dialog'), historyDialog = find('history-dialog');
        var helpDialog = find('help-dialog'), helpButton = find('help');
        var fullscreenButton = find('fullscreen'), fullscreenText = find('fullscreen-text');
        var replayControls = find('replay-controls'), replayRange = find('replay-range');
        var state = null, selected = null, busy = false, matchId = conf.matchId;
        var replay = null, replayTimer = null;
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        function el(tag, className, text) {
            var result = document.createElement(tag);
            if (className) { result.className = className; }
            if (text !== undefined) { result.textContent = text; }
            return result;
        }
        function format(key, values) {
            return (t[key] || key).replace(/\{([a-z_]+)\}/g, function (match, name) {
                return Object.prototype.hasOwnProperty.call(values, name) ? String(values[name]) : match;
            });
        }
        async function request(action, extra) {
            var body = Object.assign({ action: action, match_id: matchId }, extra || {});
            var response = await fetch(conf.api, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin', body: JSON.stringify(body)
            });
            var data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error && data.error.message ? data.error.message : t.unexpected_error);
            }
            return data;
        }
        function showError(value) { error.hidden = !value; error.textContent = value || ''; }
        function setBusy(value) {
            busy = value;
            startButton.disabled = value;
            historyButton.disabled = value;
            deleteButton.disabled = value;
            guardButton.disabled = value || guardButton.disabled;
            endButton.disabled = value || endButton.disabled;
            renderControls();
        }
        function gameView() { return replay ? replay.frames[replay.index].state : state; }
        // Rendering can finish before busy is cleared; event handlers still guard pending requests.
        function isHumanTurn() { return !!state && !replay && state.phase === 'human'; }
        function isPlaying() { return isHumanTurn() && !busy; }
        function unitLabel(unit) { return t['unit_' + unit.type] || unit.type; }
        function readableUnit(id) {
            var parts = String(id || '').split('_');
            return (parts[0] === 'a' ? 'NEXUS ' : t.human + ' ') + (t['unit_' + parts[1]] || parts[1] || '');
        }
        function eventText(event) {
            if (!event) { return t.match_started; }
            if (event.key === 'unit_moved') {
                return format('event_move', { unit: readableUnit(event.unit), x: Number(event.x) + 1, y: Number(event.y) + 1 });
            }
            if (event.key === 'unit_attacked') {
                return format('event_attack', { unit: readableUnit(event.unit), target: readableUnit(event.target), damage: event.damage });
            }
            if (event.key === 'unit_guarded') { return format('event_guard', { unit: readableUnit(event.unit) }); }
            if (event.key === 'nexus_radio') { return t.nexus_radio; }
            return t[event.key] || t.event_unknown;
        }
        function accessibleTile(x, y, unit, wall, point) {
            var labels = [t.cell, String(x + 1), String(y + 1)];
            if (point) { labels.push(t.control_point); }
            if (wall) { labels.push(t.obstacle); }
            if (unit) {
                labels.push(unit.team === 'human' ? t.human : 'NEXUS', unitLabel(unit));
                labels.push(String(unit.hp), t.health);
                if (unit.guard) { labels.push(t.guarding); }
            }
            return labels.join(' ');
        }
        function svgUnit(type) {
            var ns = 'http://www.w3.org/2000/svg';
            var svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('viewBox', '0 0 32 32');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('focusable', 'false');
            var shapes = {
                scout: [['circle', {cx:16,cy:16,r:5}],['path',{d:'M16 3v6M16 23v6M3 16h6M23 16h6M7 7l4 4M21 21l4 4M25 7l-4 4M11 21l-4 4'}]],
                guardian: [['path',{d:'M16 3 27 8v9c0 6-5 10-11 13C10 27 5 23 5 17V8L16 3Z'}],['path',{d:'M10 16h12M16 10v12'}]],
                ranger: [['circle',{cx:16,cy:16,r:9}],['circle',{cx:16,cy:16,r:3}],['path',{d:'M16 2v6M16 24v6M2 16h6M24 16h6'}]]
            };
            (shapes[type] || shapes.scout).forEach(function (description) {
                var node = document.createElementNS(ns, description[0]);
                Object.keys(description[1]).forEach(function (key) { node.setAttribute(key, description[1][key]); });
                svg.appendChild(node);
            });
            return svg;
        }
        function drawBoard() {
            var view = gameView();
            var focus = board.contains(document.activeElement) ? document.activeElement.dataset.aaCoordinate : null;
            board.replaceChildren();
            var points = (view && view.points) || conf.maps.standard.points;
            var walls = (view && view.walls) || conf.maps.standard.walls;
            var options = isHumanTurn() && view.options ? view.options[selected] : null;
            var units = view ? Object.values(view.units).filter(function (item) { return item.hp > 0; }) : [];
            var recent = replay ? replay.frames[replay.index].event : view && view.events && view.events[view.events.length - 1];
            for (var y = 0; y < 7; y++) {
                for (var x = 0; x < 9; x++) {
                    var cell = el('button', 'aa-cell');
                    cell.type = 'button';
                    cell.dataset.aaCoordinate = x + ':' + y;
                    var wall = walls.some(function (position) { return position[0] === x && position[1] === y; });
                    var point = points.some(function (position) { return position[0] === x && position[1] === y; });
                    var unit = units.find(function (robot) { return robot.x === x && robot.y === y; });
                    if (wall) { cell.classList.add('aa-wall'); cell.appendChild(el('span', 'aa-rock', '▰')); }
                    if (point) { cell.classList.add('aa-control'); if (!unit) { cell.appendChild(el('span', 'aa-beacon', '◆')); } }
                    if (unit) {
                        var token = el('span', 'aa-token aa-' + unit.team + ' aa-type-' + unit.type);
                        token.dataset.aaUnit = unit.id;
                        token.appendChild(svgUnit(unit.type));
                        token.appendChild(el('span', 'aa-hp', String(unit.hp)));
                        if (unit.guard) { token.appendChild(el('span', 'aa-guard-mark', '◈')); }
                        cell.appendChild(token);
                        if (unit.id === selected && !replay) { cell.classList.add('aa-selected'); }
                    }
                    if (recent && recent.key === 'unit_moved' && recent.x === x && recent.y === y) {
                        cell.classList.add('aa-last-move');
                    }
                    var canMove = !!(options && options.moves.some(function (pos) { return pos[0] === x && pos[1] === y; }));
                    var canAttack = !!(options && unit && options.targets.includes(unit.id));
                    if (canMove) { cell.classList.add('aa-legal-move'); }
                    if (canAttack) { cell.classList.add('aa-legal-attack'); }
                    cell.setAttribute('aria-label', accessibleTile(x, y, unit, wall, point));
                    cell.disabled = !isHumanTurn() || (!unit && !canMove) || (unit && unit.team !== 'human' && !canAttack);
                    (function (xx, yy, robot, move, attack) {
                        cell.addEventListener('click', function () {
                            if (!isPlaying()) { return; }
                            if (robot && robot.team === 'human') {
                                selected = selected === robot.id ? null : robot.id;
                                render(); return;
                            }
                            if (!selected) { return; }
                            if (move) {
                                operation('command', { revision: state.revision,
                                    command: { type: 'move', unit: selected, x: xx, y: yy } });
                            } else if (attack) {
                                operation('command', { revision: state.revision,
                                    command: { type: 'attack', unit: selected, target: robot.id } });
                            }
                        });
                    })(x, y, unit, canMove, canAttack);
                    board.appendChild(cell);
                }
            }
            if (focus && !busy) {
                var nextFocus = Array.from(board.children).find(function (cell) {
                    return cell.dataset.aaCoordinate === focus && !cell.disabled;
                });
                if (nextFocus) { nextFocus.focus({ preventScroll: true }); }
            }
        }
        function animateTransition(before, after) {
            if (!before || !after || reducedMotion.matches) { return; }
            Object.keys(after.units).forEach(function (id) {
                var oldUnit = before.units[id], newUnit = after.units[id];
                if (!oldUnit || !newUnit || newUnit.hp <= 0) { return; }
                var cell = Array.from(board.children).find(function (item) {
                    return item.dataset.aaCoordinate === newUnit.x + ':' + newUnit.y;
                });
                if (!cell) { return; }
                var token = cell.querySelector('.aa-token');
                if (!token) { return; }
                if (oldUnit.x !== newUnit.x || oldUnit.y !== newUnit.y) {
                    var oldCell = Array.from(board.children).find(function (item) {
                        return item.dataset.aaCoordinate === oldUnit.x + ':' + oldUnit.y;
                    });
                    if (oldCell && typeof token.animate === 'function') {
                        var from = oldCell.getBoundingClientRect(), to = cell.getBoundingClientRect();
                        token.animate([
                            { transform: 'translate(' + (from.left - to.left) + 'px,' + (from.top - to.top) + 'px) scale(.91)', opacity: .8 },
                            { transform: 'translate(0,0) scale(1)', opacity: 1 }
                        ], { duration: 360, easing: 'cubic-bezier(.2,.8,.2,1)' });
                    }
                }
                if (newUnit.hp < oldUnit.hp && typeof cell.animate === 'function') {
                    cell.animate([
                        { boxShadow: 'inset 0 0 0 2px #ff737d, 0 0 24px #ff737d' },
                        { boxShadow: 'inset 0 0 0 0 transparent, 0 0 0 transparent' }
                    ], { duration: 600, easing: 'ease-out' });
                }
            });
        }
        function renderControls() {
            var live = isPlaying();
            var choice = live && state.options && state.options[selected];
            guardButton.disabled = !(choice && choice.guard);
            endButton.disabled = !live;
            aiButton.hidden = !!replay || !state || (state.phase !== 'ai' && state.phase !== 'ai_running');
            aiButton.disabled = busy;
            root.querySelector('[data-aa-live-actions]').hidden = !!replay;
            replayControls.hidden = !replay;
            find('result-replay').disabled = busy;
        }
        function render() {
            var view = gameView();
            drawBoard();
            renderControls();
            var mapId = view ? view.map_id || 'standard' : 'standard';
            find('map-title').textContent = t['map_' + mapId];
            find('map-description').textContent = t['map_' + mapId + '_desc'];
            ['human', 'ai'].forEach(function (team) {
                var score = view ? view.scores[team] : 0;
                root.querySelector('[data-aa-score="' + team + '"]').textContent = String(score);
                root.querySelector('[data-aa-meter="' + team + '"]').style.width = Math.min(100, (score / 8) * 100) + '%';
            });
            find('round').textContent = view ? t.round + ' ' + view.round + ' / ' + view.limit : t.ready;
            if (replay) { message.textContent = t.replay_info; }
            else if (view && view.winner) { message.textContent = t['win_' + view.winner]; }
            else if (view && view.phase === 'human') { message.textContent = t.your_turn; }
            else if (view && (view.phase === 'ai' || view.phase === 'ai_running')) { message.textContent = t.thinking; }
            else { message.textContent = t.welcome; }
            var events = replay ? replay.frames.slice(0, replay.index + 1).map(function (frame) { return frame.event; }) :
                (view && view.events) || [];
            var lastRadio = events.slice().reverse().find(function (event) {
                return event.key === 'nexus_radio' && typeof event.text === 'string';
            });
            radioPanel.hidden = !lastRadio;
            radioText.textContent = lastRadio ? lastRadio.text : '';
            unitPanel.replaceChildren();
            if (selected && view && view.units[selected] && !replay) {
                var unit = view.units[selected];
                unitPanel.appendChild(el('strong', '', unitLabel(unit)));
                unitPanel.appendChild(el('div', 'aa-unit-hp', t.health + ': ' + unit.hp + ' / ' + unit.max_hp));
                var hpTrack = el('div', 'aa-unit-health-track');
                var hpFill = el('span', 'aa-unit-health-fill');
                hpFill.style.width = Math.round(unit.hp / unit.max_hp * 100) + '%';
                hpTrack.appendChild(hpFill);
                unitPanel.appendChild(hpTrack);
                unitPanel.appendChild(el('small', '', unit.acted ? t.acted : unit.moved ? t.moved : t.move_hint));
            } else { unitPanel.textContent = replay ? t.replay_info_short : t.no_unit; }
            log.replaceChildren();
            events.slice(-6).reverse().forEach(function (event) {
                var item = el('li', 'aa-log-item', eventText(event));
                var round = el('span', 'aa-log-round', t.round + ' ' + (event.round || 1));
                item.prepend(round);
                log.appendChild(item);
            });
            var result = find('result');
            result.hidden = !!replay || !state || !state.winner;
            if (!result.hidden) {
                find('result-title').textContent = t['win_' + state.winner];
                find('result-summary').textContent = format('result_summary', {
                    human: state.scores.human, ai: state.scores.ai, round: state.round
                });
            }
            if (replay) {
                var current = replay.frames[replay.index];
                find('replay-event').textContent = eventText(current.event);
                find('replay-step').textContent = (replay.index + 1) + ' / ' + replay.frames.length;
                replayRange.max = String(replay.frames.length - 1);
                replayRange.value = String(replay.index);
                find('replay-prev').disabled = replay.index === 0;
                find('replay-next').disabled = replay.index >= replay.frames.length - 1;
                find('replay-play').textContent = replayTimer ? 'Ⅱ' : '▶';
                find('replay-play').setAttribute('aria-label', replayTimer ? t.replay_pause : t.replay_play);
            }
        }
        function clearReplayTimer() {
            if (replayTimer !== null) { clearInterval(replayTimer); replayTimer = null; }
        }
        function exitReplay() { clearReplayTimer(); replay = null; render(); }
        function toggleReplay() {
            if (!replay) { return; }
            if (replayTimer !== null) { clearReplayTimer(); render(); return; }
            if (replay.index === replay.frames.length - 1) { replay.index = 0; }
            replayTimer = setInterval(function () {
                if (!replay || replay.index >= replay.frames.length - 1) { clearReplayTimer(); render(); return; }
                var old = replay.frames[replay.index].state;
                replay.index++;
                render(); animateTransition(old, replay.frames[replay.index].state);
                if (replay.index >= replay.frames.length - 1) { clearReplayTimer(); render(); }
            }, 850);
            render();
        }
        async function enterReplay(id) {
            if (busy) { return; }
            clearReplayTimer();
            setBusy(true); showError('');
            try {
                var data = await request('replay', { match_id: id });
                if (!data.frames || !data.frames.length) { throw new Error(t.replay_unavailable); }
                replay = { frames: data.frames, index: 0 };
                selected = null;
                if (historyDialog.open) { historyDialog.close(); }
                render();
                find('replay-play').focus();
            } catch (e) { showError(e.message); }
            finally { setBusy(false); }
        }
        async function operation(action, extra) {
            if (busy) { return; }
            setBusy(true); showError('');
            try {
                var before = state;
                var result = await request(action, extra);
                clearReplayTimer(); replay = null;
                state = result.state;
                if (!state) { matchId = ''; selected = null; render(); return; }
                matchId = state.id;
                if (selected && (!state.units[selected] || state.units[selected].hp <= 0)) { selected = null; }
                render(); animateTransition(before && before.id === state.id ? before : null, state);
                if (action === 'end' && state.phase === 'ai') { await playAi(); }
            } catch (e) {
                showError(e.message);
                if (matchId) {
                    try { state = (await request('snapshot')).state; render(); } catch (_) { /* Keep original error. */ }
                }
            } finally { setBusy(false); }
        }
        async function playAi() {
            if (!state || (state.phase !== 'ai' && state.phase !== 'ai_running')) { return; }
            message.textContent = t.thinking;
            try {
                var before = state;
                var result = await request('ai');
                state = result.state;
                selected = null;
                render(); animateTransition(before, state);
            } catch (e) {
                showError(e.message);
                try { state = (await request('snapshot')).state; render(); } catch (_) { /* Keep original error. */ }
            }
        }
        function buildMapPreviews() {
            root.querySelectorAll('[data-aa-map-preview]').forEach(function (preview) {
                var map = conf.maps[preview.dataset.aaMapPreview];
                if (!map) { return; }
                preview.replaceChildren();
                for (var y = 0; y < 7; y++) {
                    for (var x = 0; x < 9; x++) {
                        var tile = el('span', 'aa-map-pixel');
                        if (map.walls.some(function (pos) { return pos[0] === x && pos[1] === y; })) {
                            tile.classList.add('aa-map-wall');
                        }
                        if (map.points.some(function (pos) { return pos[0] === x && pos[1] === y; })) {
                            tile.classList.add('aa-map-point');
                        }
                        preview.appendChild(tile);
                    }
                }
            });
        }
        function openMapDialog() { if (!busy) { mapDialog.showModal(); } }
        function renderHistory(matches) {
            var list = find('history-list');
            list.replaceChildren();
            if (!matches.length) { list.appendChild(el('p', 'aa-empty-history', t.archive_empty)); return; }
            matches.forEach(function (match) {
                var row = el('article', 'aa-history-row');
                var heading = el('div', 'aa-history-main');
                heading.appendChild(el('strong', '', t['map_' + (match.map_id || 'standard')]));
                heading.appendChild(el('small', '', t.round + ' ' + match.round + ' · ' +
                    match.scores.human + ' : ' + match.scores.ai + ' · ' + String(match.updated_at).slice(0, 16)));
                heading.appendChild(el('span', 'aa-history-status', match.winner ? t['win_' + match.winner] : t.match_in_progress));
                row.appendChild(heading);
                var actions = el('div', 'aa-history-actions');
                if (!match.winner) {
                    var resume = el('button', 'aa-button aa-secondary', t.resume_match);
                    resume.type = 'button';
                    resume.addEventListener('click', function () {
                        historyDialog.close(); selected = null; exitReplay();
                        operation('snapshot', { match_id: match.id });
                    });
                    actions.appendChild(resume);
                } else {
                    var watch = el('button', 'aa-button aa-secondary', t.replay_button);
                    watch.type = 'button';
                    watch.addEventListener('click', function () { enterReplay(match.id); });
                    actions.appendChild(watch);
                }
                row.appendChild(actions);
                list.appendChild(row);
            });
        }
        async function openHistory() {
            if (busy) { return; }
            historyDialog.showModal();
            find('history-list').replaceChildren(el('p', 'aa-empty-history', t.loading));
            try {
                var data = await request('history');
                renderHistory(data.matches || []);
            } catch (e) { find('history-list').replaceChildren(el('p', 'aa-empty-history', e.message)); }
        }
        helpButton.addEventListener('click', function () { helpDialog.showModal(); find('help-close').focus(); });
        find('help-close').addEventListener('click', function () { helpDialog.close(); });
        find('help-done').addEventListener('click', function () { helpDialog.close(); });
        helpDialog.addEventListener('close', function () { helpButton.focus(); });
        find('map-close').addEventListener('click', function () { mapDialog.close(); });
        find('history-close').addEventListener('click', function () { historyDialog.close(); });
        mapDialog.querySelectorAll('[data-aa-map-choice]').forEach(function (choice) {
            choice.addEventListener('click', function () {
                mapDialog.close(); selected = null;
                operation('start', { map_id: choice.dataset.aaMapChoice });
            });
        });
        if (typeof root.requestFullscreen !== 'function' || typeof document.exitFullscreen !== 'function') {
            fullscreenButton.hidden = true;
        } else {
            function updateFullscreen() {
                var active = document.fullscreenElement === root;
                fullscreenButton.setAttribute('aria-pressed', String(active));
                fullscreenButton.setAttribute('aria-label', active ? t.fullscreen_exit : t.fullscreen_enter);
                fullscreenText.textContent = active ? t.fullscreen_exit : t.fullscreen_enter;
            }
            fullscreenButton.addEventListener('click', async function () {
                try {
                    if (document.fullscreenElement === root) { await document.exitFullscreen(); }
                    else { await root.requestFullscreen(); }
                } catch (_) { showError(t.fullscreen_failed); }
            });
            document.addEventListener('fullscreenchange', updateFullscreen);
            updateFullscreen();
        }
        startButton.addEventListener('click', openMapDialog);
        find('result-new').addEventListener('click', openMapDialog);
        historyButton.addEventListener('click', openHistory);
        deleteButton.addEventListener('click', function () {
            if (!busy && window.confirm(t.confirm_delete)) { selected = null; operation('delete_all'); }
        });
        guardButton.addEventListener('click', function () {
            if (isPlaying() && selected) {
                operation('command', { revision: state.revision, command: { type: 'guard', unit: selected } });
            }
        });
        endButton.addEventListener('click', function () {
            if (isPlaying()) { selected = null; operation('end', { revision: state.revision }); }
        });
        aiButton.addEventListener('click', function () {
            if (!busy && state) { setBusy(true); showError(''); playAi().finally(function () { setBusy(false); }); }
        });
        find('result-replay').addEventListener('click', function () {
            if (state && state.winner) { enterReplay(state.id); }
        });
        find('replay-exit').addEventListener('click', exitReplay);
        find('replay-play').addEventListener('click', toggleReplay);
        find('replay-prev').addEventListener('click', function () {
            if (replay) { clearReplayTimer(); replay.index = Math.max(0, replay.index - 1); render(); }
        });
        find('replay-next').addEventListener('click', function () {
            if (replay) { clearReplayTimer(); replay.index = Math.min(replay.frames.length - 1, replay.index + 1); render(); }
        });
        replayRange.addEventListener('input', function () {
            if (replay) { clearReplayTimer(); replay.index = Number(replayRange.value); render(); }
        });
        buildMapPreviews();
        render();
        operation(matchId ? 'snapshot' : 'resume');
    }
    function boot() { document.querySelectorAll('[data-agent-arena]').forEach(mount); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
    else { boot(); }
})();
