'use strict';

// Runs the actual Arena UI against a minimal local DOM and a controlled JSON API.
// No browser, database, MissionBay or external npm dependencies are required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.style = {};
        this.listeners = {};
        this._disabled = false;
        this.hidden = false;
        this.open = false;
        this.value = '';
        this.textContent = '';
        this._classes = new Set();
        this.classList = {
            add: (...names) => names.forEach(name => this._classes.add(name)),
            contains: name => this._classes.has(name)
        };
    }
    set disabled(value) { this._disabled = Boolean(value); }
    get disabled() { return this._disabled; }
    set className(value) { this._classes = new Set(String(value).split(/\s+/).filter(Boolean)); }
    get className() { return Array.from(this._classes).join(' '); }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    appendChild(node) { this.children.push(node); return node; }
    prepend(node) { this.children.unshift(node); }
    replaceChildren(...nodes) { this.children = nodes; }
    contains() { return false; }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    click() {
        if (this.disabled) { return; }
        for (const callback of this.listeners.click || []) { callback(); }
    }
    focus() {}
    getBoundingClientRect() { return { left: 0, top: 0 }; }
    querySelector(selector) {
        if (selector.startsWith('.')) {
            return this.children.find(node => node.classList?.contains(selector.slice(1))) || null;
        }
        return null;
    }
    showModal() { this.open = true; }
    close() { this.open = false; }
}

function createState() {
    const units = {};
    for (const [team, prefix, x] of [['human', 'h', 0], ['ai', 'a', 8]]) {
        for (const [type, y, hp] of [['scout', 1, 5], ['guardian', 3, 9], ['ranger', 5, 6]]) {
            const id = `${prefix}_${type}`;
            units[id] = { id, team, type, x, y, hp, max_hp: hp, guard: false, moved: false, acted: false };
        }
    }
    return {
        id: 'test-match', revision: 0, phase: 'human', map_id: 'standard',
        round: 1, limit: 10, winner: null, scores: { human: 0, ai: 0 },
        points: [[2,3],[4,3],[6,3]], walls: [[3,1],[3,5],[5,1],[5,5]],
        events: [{ key: 'match_started', round: 1 }], units,
        options: { h_scout: { moves: [[1,1],[2,1]], targets: [], guard: true } }
    };
}

async function runScenario(resumeExisting) {
    let game = createState();
    let matchId = resumeExisting ? game.id : '';
    let commands = 0;
    let aiCalls = 0;
    let releaseCommand;
    let commandRequested;
    const commandStarted = new Promise(resolve => { commandRequested = resolve; });
    let releaseAi;
    let aiRequested;
    const aiStarted = new Promise(resolve => { aiRequested = resolve; });
    const elements = new Map();
    const el = key => {
        if (!elements.has(key)) { elements.set(key, new Element()); }
        return elements.get(key);
    };
    const mapChoice = new Element('button');
    mapChoice.dataset.aaMapChoice = 'standard';
    const root = new Element('section');
    root.dataset.aaMounted = '0';
    const config = {
        api: '/arenaapioutput.json', matchId, text: {},
        maps: { standard: { points: game.points, walls: game.walls } }
    };
    const mapText = { map_standard: 'Standard', map_standard_desc: 'Map',
        round: 'Round', your_turn: 'Your turn', thinking: 'NEXUS',
        human: 'Human', no_unit: 'No unit', unit_scout: 'Scout',
        unit_guardian: 'Guardian', unit_ranger: 'Ranger', health: 'HP',
        match_started: 'Started', result_summary: '{human}:{ai}',
        ready: 'Ready', welcome: 'Welcome' };
    config.text = mapText;
    const configNode = new Element('script');
    configNode.textContent = JSON.stringify(config);
    root.querySelector = selector => {
        if (selector === '[data-aa-config]') { return configNode; }
        const score = selector.match(/^\[data-aa-score="(human|ai)"\]$/);
        if (score) { return el(`score_${score[1]}`); }
        const meter = selector.match(/^\[data-aa-meter="(human|ai)"\]$/);
        if (meter) { return el(`meter_${meter[1]}`); }
        const key = selector.match(/^\[data-aa-([a-z-]+)\]$/);
        return key ? el(key[1]) : null;
    };
    root.querySelectorAll = () => [];
    el('map-dialog').querySelectorAll = selector => selector === '[data-aa-map-choice]' ? [mapChoice] : [];
    const document = {
        readyState: 'complete',
        activeElement: null,
        fullscreenElement: null,
        createElement: tag => new Element(tag),
        createElementNS: (_, tag) => new Element(tag),
        querySelectorAll: selector => selector === '[data-agent-arena]' ? [root] : [],
        addEventListener() {}
    };
    const fetch = async (_, options) => {
        const body = JSON.parse(options.body);
        let state = null;
        if (body.action === 'resume') { state = null; }
        else if (body.action === 'snapshot') { state = game; }
        else if (body.action === 'start') { game = createState(); state = game; }
        else if (body.action === 'command') {
            commands++;
            commandRequested();
            await new Promise(resolve => { releaseCommand = resolve; });
            assert.equal(body.command.unit, 'h_scout');
            assert.equal(body.command.type, 'move');
            assert.deepEqual([body.command.x, body.command.y], [1,1]);
            game = structuredClone(game);
            game.units.h_scout.x = 1;
            game.units.h_scout.y = 1;
            game.units.h_scout.moved = true;
            game.options.h_scout.moves = [];
            game.revision++;
            state = game;
        } else if (body.action === 'end') {
            game = structuredClone(game);
            game.phase = 'ai';
            game.options = {};
            game.revision++;
            state = game;
        } else if (body.action === 'ai') {
            aiCalls++;
            aiRequested();
            await new Promise(resolve => { releaseAi = resolve; });
            game = structuredClone(game);
            game.phase = 'human';
            game.round++;
            game.options = { h_scout: { moves: [[1,2]], targets: [], guard: true } };
            game.revision++;
            state = game;
        } else { throw Error('Unexpected action: ' + body.action); }
        return { ok: true, json: async () => ({ ok: true, state }) };
    };
    const context = vm.createContext({
        document, window: { matchMedia: () => ({ matches: true }), confirm: () => true },
        fetch, console, setInterval, clearInterval
    });
    const file = path.join(__dirname, '../../assets/arena/arena.js');
    vm.runInContext(fs.readFileSync(file, 'utf8'), context, { filename: file });
    const settle = async () => {
        for (let i = 0; i < 5; i++) { await new Promise(resolve => setImmediate(resolve)); }
    };
    const tile = (x,y) => {
        const cell = el('board').children.find(node => node.dataset.aaCoordinate === `${x}:${y}`);
        assert(cell, `Missing cell at ${x}:${y}`);
        return cell;
    };
    await settle();
    if (!resumeExisting) {
        assert.equal(tile(0,1).disabled, true, 'No match is not selectable');
        el('start').click();
        mapChoice.click();
        await settle();
    }
    assert.equal(el('end').disabled, false, 'End turn is available to the human');
    assert.equal(tile(0,1).disabled, false, 'Human scout is selectable after loading');
    assert.equal(tile(0,3).disabled, false, 'Human guardian is selectable after loading');
    assert.equal(tile(0,5).disabled, false, 'Human ranger is selectable after loading');
    assert.equal(tile(8,1).disabled, true, 'Opponent cannot be selected');
    assert.equal(tile(1,1).disabled, true, 'Move destination requires selection');
    tile(0,1).click();
    assert.equal(tile(0,1).classList.contains('aa-selected'), true, 'Scout was selected');
    assert.equal(tile(1,1).disabled, false, 'Legal destination becomes selectable');
    tile(1,1).click();
    await commandStarted;
    tile(0,3).click();
    assert.equal(commands, 1, 'Concurrent clicks do not create a second request');
    releaseCommand();
    await settle();
    assert.equal(commands, 1, 'Human movement reached the existing API');
    assert.equal(tile(1,1).disabled, false, 'Moved scout stays selectable in the same turn');
    el('end').click();
    await aiStarted;
    assert.equal(tile(1,1).disabled, true, 'Board disabled during AI phase');
    releaseAi();
    await settle();
    assert.equal(aiCalls, 1, 'NEXUS turn executed once');
    assert.equal(tile(1,1).disabled, false, 'Human scout selectable after NEXUS');
    assert.equal(tile(8,1).disabled, true, 'AI unit remains nonselectable');
    assert.equal(el('end').disabled, false, 'Human may end a subsequent round');
}

(async () => {
    await runScenario(true);
    await runScenario(false);
    console.log('Arena UI interaction smoke passed: resume, start, command, AI round');
})().catch(error => { console.error(error); process.exitCode = 1; });
