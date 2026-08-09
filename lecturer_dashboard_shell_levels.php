<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button" data-close-subscreen aria-label="Back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div>
            <div class="screen-title">My Levels</div>
            <div class="screen-subtitle">Levels currently linked to your lecturer assignments.</div>
        </div>
    </div>
</div>

<div class="section-block">
    <?php if ($level_cards): ?>
        <?php foreach ($level_cards as $index => $level_card): ?>
            <?php
                $level_colors = ['green', 'blue', 'purple', 'orange'];
                $level_tone = $level_colors[$index % count($level_colors)];
                $level_gradient = 'linear-gradient(145deg,#26c86e,#10a95c)';
                if ($level_tone === 'blue') {
                    $level_gradient = 'linear-gradient(145deg,#2f7bff,#1256e0)';
                } elseif ($level_tone === 'purple') {
                    $level_gradient = 'linear-gradient(145deg,#8b58f7,#6e3eeb)';
                } elseif ($level_tone === 'orange') {
                    $level_gradient = 'linear-gradient(145deg,#ffa537,#ff8617)';
                }
            ?>
            <div class="rep-card">
                <div class="row-inline">
                    <div class="rep-id">
                        <div class="rep-avatar" style="background: <?php echo $level_gradient; ?>;"><?php echo htmlspecialchars(strval($level_card['level'] ?? '')); ?></div>
                        <div>
                            <strong>Level <?php echo htmlspecialchars(strval($level_card['level'] ?? '')); ?></strong>
                            <span><?php echo number_format(intval($level_card['courses'] ?? 0)); ?> courses • <?php echo number_format(intval($level_card['reps'] ?? 0)); ?> reps • <?php echo number_format(intval($level_card['requests'] ?? 0)); ?> requests</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">No levels are available yet.</div>
    <?php endif; ?>
</div>
