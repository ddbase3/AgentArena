<?php
	$config = $this->_['arena_config'];
	$t = $config['text'];
	$rootId = 'agentarena_' . bin2hex(random_bytes(5));
	$serialized = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
	$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?php echo $h($this->_['arena_css']); ?>">
<section id="<?php echo $h($rootId); ?>" class="agent-arena" data-agent-arena="1"
    aria-label="<?php echo $h($t['title']); ?>">
    <header class="aa-topbar">
        <div class="aa-brand">
            <span class="aa-brandmark" aria-hidden="true"><span class="aa-brand-core"></span></span>
            <span class="aa-brand-words"><strong><?php echo $h($t['title']); ?></strong><small><?php echo $h($t['subtitle']); ?></small></span>
        </div>
        <div class="aa-top-actions">
            <button type="button" class="aa-top-action" data-aa-history aria-haspopup="dialog"
                aria-controls="<?php echo $h($rootId . '_history'); ?>">
                <span class="aa-action-symbol" aria-hidden="true">▤</span><?php echo $h($t['archive_button']); ?>
            </button>
            <button type="button" class="aa-top-action" data-aa-help aria-haspopup="dialog"
                aria-controls="<?php echo $h($rootId . '_help'); ?>">
                <span class="aa-action-symbol" aria-hidden="true">?</span><?php echo $h($t['help_button']); ?>
            </button>
            <button type="button" class="aa-top-action" data-aa-fullscreen aria-pressed="false"
                aria-label="<?php echo $h($t['fullscreen_enter']); ?>">
                <span class="aa-action-symbol" aria-hidden="true">⛶</span>
                <span data-aa-fullscreen-text><?php echo $h($t['fullscreen_enter']); ?></span>
            </button>
        </div>
    </header>
    <div class="aa-main">
        <div class="aa-arena-panel">
            <div class="aa-mission-head">
                <div>
                    <span class="aa-eyebrow"><?php echo $h($t['sector_label']); ?></span>
                    <h2 data-aa-map-title><?php echo $h($t['map_standard']); ?></h2>
                    <p data-aa-map-description><?php echo $h($t['map_standard_desc']); ?></p>
                </div>
                <div class="aa-mission-chip"><span class="aa-chip-dot" aria-hidden="true"></span><?php echo $h($t['objective_label']); ?></div>
            </div>
            <div class="aa-scorebar" aria-live="polite">
                <div class="aa-score-team aa-blue">
                    <span class="aa-score-tag"><?php echo $h($t['human']); ?></span>
                    <span class="aa-score-number" data-aa-score="human">0</span>
                    <span class="aa-score-track" aria-hidden="true"><span data-aa-meter="human"></span></span>
                </div>
                <div class="aa-score-center"><span class="aa-score-divider">VS</span><span data-aa-round><?php echo $h($t['ready']); ?></span></div>
                <div class="aa-score-team aa-purple">
                    <span class="aa-score-tag">NEXUS</span>
                    <span class="aa-score-number" data-aa-score="ai">0</span>
                    <span class="aa-score-track" aria-hidden="true"><span data-aa-meter="ai"></span></span>
                </div>
            </div>
            <div class="aa-board-wrap">
                <div class="aa-board" role="group" aria-label="<?php echo $h($t['board']); ?>" data-aa-board></div>
                <div class="aa-finish" data-aa-result hidden>
                    <span class="aa-finish-eyebrow"><?php echo $h($t['mission_complete']); ?></span>
                    <h2 data-aa-result-title></h2>
                    <p data-aa-result-summary></p>
                    <div class="aa-finish-actions">
                        <button type="button" class="aa-button aa-secondary" data-aa-result-replay><?php echo $h($t['replay_button']); ?></button>
                        <button type="button" class="aa-button aa-primary" data-aa-result-new><?php echo $h($t['new_game']); ?></button>
                    </div>
                </div>
            </div>
            <div class="aa-replay-controls" data-aa-replay-controls hidden>
                <div class="aa-replay-heading"><span class="aa-eyebrow"><?php echo $h($t['replay_mode']); ?></span><strong data-aa-replay-event></strong></div>
                <div class="aa-replay-navigation">
                    <button type="button" class="aa-icon-button" data-aa-replay-prev aria-label="<?php echo $h($t['replay_previous']); ?>">◀</button>
                    <button type="button" class="aa-icon-button aa-replay-play" data-aa-replay-play aria-label="<?php echo $h($t['replay_play']); ?>">▶</button>
                    <button type="button" class="aa-icon-button" data-aa-replay-next aria-label="<?php echo $h($t['replay_next']); ?>">▶▶</button>
                    <label class="aa-replay-range-label" for="<?php echo $h($rootId . '_replay_position'); ?>"><?php echo $h($t['replay_position']); ?></label>
                    <input id="<?php echo $h($rootId . '_replay_position'); ?>" type="range" min="0" max="0" value="0" step="1" data-aa-replay-range>
                    <span class="aa-replay-step" data-aa-replay-step>1 / 1</span>
                    <button type="button" class="aa-button aa-secondary aa-replay-exit" data-aa-replay-exit><?php echo $h($t['replay_exit']); ?></button>
                </div>
            </div>
            <div class="aa-legend" aria-hidden="true">
                <span class="aa-legend-item"><i class="aa-legend-human"></i><?php echo $h($t['human']); ?></span>
                <span class="aa-legend-item"><i class="aa-legend-ai"></i>NEXUS</span>
                <span class="aa-legend-item"><i class="aa-legend-point"></i><?php echo $h($t['control_point']); ?></span>
                <span class="aa-legend-item"><i class="aa-legend-wall"></i><?php echo $h($t['obstacle']); ?></span>
            </div>
        </div>
        <aside class="aa-side">
            <div class="aa-ai-head">
                <div class="aa-avatar" aria-hidden="true"><span>◆</span></div>
                <div><strong>NEXUS</strong><small><?php echo $h($t['opponent']); ?></small></div>
                <span class="aa-light" aria-hidden="true"></span>
            </div>
            <div class="aa-comm" data-aa-message role="status" aria-live="polite"><?php echo $h($t['welcome']); ?></div>
            <div class="aa-radio" data-aa-radio-panel hidden>
                <div class="aa-radio-title"><?php echo $h($t['radio_title']); ?></div>
                <p data-aa-radio-text></p>
            </div>
            <h3><?php echo $h($t['selected_unit']); ?></h3>
            <div class="aa-unit-detail" data-aa-unit><?php echo $h($t['no_unit']); ?></div>
            <div class="aa-actions" data-aa-live-actions>
                <button type="button" class="aa-button aa-secondary" data-aa-guard disabled><?php echo $h($t['guard']); ?></button>
                <button type="button" class="aa-button aa-primary" data-aa-end disabled><?php echo $h($t['end_turn']); ?></button>
            </div>
            <button type="button" class="aa-button aa-retry" data-aa-ai hidden><?php echo $h($t['retry_ai']); ?></button>
            <h3><?php echo $h($t['event_log']); ?></h3>
            <ol class="aa-log" data-aa-log></ol>
            <div class="aa-side-footer">
                <button type="button" class="aa-button aa-new" data-aa-start><?php echo $h($t['new_game']); ?></button>
                <button type="button" class="aa-button aa-delete" data-aa-delete><?php echo $h($t['delete_matches']); ?></button>
            </div>
            <div class="aa-error" data-aa-error role="alert" aria-live="assertive" hidden></div>
        </aside>
    </div>
    <dialog id="<?php echo $h($rootId . '_maps'); ?>" class="aa-dialog aa-map-dialog" data-aa-map-dialog
        aria-labelledby="<?php echo $h($rootId . '_maps_title'); ?>">
        <div class="aa-dialog-heading">
            <div><span class="aa-eyebrow"><?php echo $h($t['select_map_eyebrow']); ?></span>
                <h2 id="<?php echo $h($rootId . '_maps_title'); ?>"><?php echo $h($t['select_map']); ?></h2>
            </div>
            <button type="button" class="aa-dialog-close" data-aa-map-close aria-label="<?php echo $h($t['close_dialog']); ?>">✕</button>
        </div>
        <p class="aa-dialog-intro"><?php echo $h($t['map_intro']); ?></p>
        <div class="aa-map-choices">
            <?php foreach (['standard', 'bastion', 'crossfire'] as $mapId): ?>
                <button type="button" class="aa-map-card" data-aa-map-choice="<?php echo $h($mapId); ?>">
                    <span class="aa-mini-map" data-aa-map-preview="<?php echo $h($mapId); ?>" aria-hidden="true"></span>
                    <span class="aa-map-card-copy"><strong><?php echo $h($t['map_' . $mapId]); ?></strong>
                        <small><?php echo $h($t['map_' . $mapId . '_desc']); ?></small></span>
                    <span class="aa-map-card-cta"><?php echo $h($t['map_choose']); ?> ⟶</span>
                </button>
            <?php endforeach; ?>
        </div>
    </dialog>
    <dialog id="<?php echo $h($rootId . '_history'); ?>" class="aa-dialog aa-history-dialog" data-aa-history-dialog
        aria-labelledby="<?php echo $h($rootId . '_history_title'); ?>">
        <div class="aa-dialog-heading">
            <div><span class="aa-eyebrow"><?php echo $h($t['archive_eyebrow']); ?></span>
                <h2 id="<?php echo $h($rootId . '_history_title'); ?>"><?php echo $h($t['archive_title']); ?></h2></div>
            <button type="button" class="aa-dialog-close" data-aa-history-close aria-label="<?php echo $h($t['close_dialog']); ?>">✕</button>
        </div>
        <p class="aa-dialog-intro"><?php echo $h($t['archive_description']); ?></p>
        <div class="aa-history-list" data-aa-history-list aria-live="polite"></div>
    </dialog>
    <dialog id="<?php echo $h($rootId . '_help'); ?>" class="aa-dialog aa-help-dialog" data-aa-help-dialog
        aria-labelledby="<?php echo $h($rootId . '_help_title'); ?>">
        <div class="aa-dialog-heading">
            <div><span class="aa-eyebrow"><?php echo $h($t['title']); ?></span>
                <h2 id="<?php echo $h($rootId . '_help_title'); ?>"><?php echo $h($t['help_title']); ?></h2></div>
            <button type="button" class="aa-dialog-close" data-aa-help-close aria-label="<?php echo $h($t['help_close']); ?>">✕</button>
        </div>
        <p class="aa-dialog-intro"><?php echo $h($t['help_intro']); ?></p>
        <div class="aa-help-content">
            <section><h3><?php echo $h($t['help_how_heading']); ?></h3>
                <ol><li><?php echo $h($t['help_how_1']); ?></li><li><?php echo $h($t['help_how_2']); ?></li>
                    <li><?php echo $h($t['help_how_3']); ?></li><li><?php echo $h($t['help_how_4']); ?></li></ol>
            </section>
            <section><h3><?php echo $h($t['help_units_heading']); ?></h3>
                <ul class="aa-help-unit-list"><li><strong>Scout</strong><span><?php echo $h($t['help_scout']); ?></span></li>
                    <li><strong>Guardian</strong><span><?php echo $h($t['help_guardian']); ?></span></li>
                    <li><strong>Ranger</strong><span><?php echo $h($t['help_ranger']); ?></span></li></ul>
            </section>
            <section><h3><?php echo $h($t['help_maps_heading']); ?></h3><p><?php echo $h($t['help_maps']); ?></p></section>
            <section><h3><?php echo $h($t['help_combat_heading']); ?></h3><p><?php echo $h($t['help_combat']); ?></p></section>
            <section><h3><?php echo $h($t['help_win_heading']); ?></h3><p><?php echo $h($t['help_win']); ?></p></section>
            <section><h3><?php echo $h($t['help_replay_heading']); ?></h3><p><?php echo $h($t['help_replay']); ?></p></section>
        </div>
        <div class="aa-help-footer"><span><?php echo $h($t['help_ai']); ?></span>
            <button type="button" class="aa-button aa-primary" data-aa-help-done><?php echo $h($t['help_done']); ?></button></div>
    </dialog>
    <script type="application/json" data-aa-config><?php echo $serialized; ?></script>
</section>
<script src="<?php echo $h($this->_['arena_js']); ?>" defer></script>
