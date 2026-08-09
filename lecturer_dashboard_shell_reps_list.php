<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button" data-open-screen="home" aria-label="Back home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div>
            <div class="screen-title">Representatives</div>
            <div class="screen-subtitle">Track rep collections and outstanding balances.</div>
        </div>
    </div>
    <button type="button" class="icon-button" data-open-subscreen="payments" aria-label="Open payments">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 5h16M7 12h10M9 19h6"/></svg>
    </button>
</div>

<div class="section-block">
    <input type="search" class="search-input" id="repSearch" placeholder="Search representative...">
</div>

<div class="section-block rep-list-grid" id="repList">
    <?php if ($rep_cards): ?>
        <?php foreach ($rep_cards as $rep_card): ?>
            <a class="rep-card js-rep-card" href="lecturer_dashboard.php?tab=reps&rep_id=<?php echo intval($rep_card['admin_id']); ?><?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>" data-query="<?php echo htmlspecialchars(strtolower(trim(strval(($rep_card['full_name'] ?? '') . ' ' . ($rep_card['class_name'] ?? ''))))); ?>">
                <div class="rep-card-head">
                    <div class="rep-id">
                        <div class="rep-avatar"><?php echo htmlspecialchars(strtoupper(substr(strval($rep_card['full_name'] ?? 'RP'), 0, 2))); ?></div>
                        <div>
                            <strong><?php echo htmlspecialchars(strval($rep_card['full_name'] ?? 'Rep')); ?></strong>
                            <span><?php echo htmlspecialchars(strval($rep_card['class_name'] ?? '')); ?></span>
                        </div>
                    </div>
                    <div class="status-pill <?php echo floatval($rep_card['outstanding'] ?? 0) > 0 ? 'pending' : 'paid'; ?>"><?php echo floatval($rep_card['outstanding'] ?? 0) > 0 ? 'Pending' : 'Active'; ?></div>
                </div>
                <div class="rep-stat-grid">
                    <div><span>Collected</span><strong class="success">GH&#8373;<?php echo number_format(floatval($rep_card['collected'] ?? 0), 0); ?></strong></div>
                    <div><span>Outstanding</span><strong class="danger">GH&#8373;<?php echo number_format(floatval($rep_card['outstanding'] ?? 0), 0); ?></strong></div>
                    <div><span>Books</span><strong><?php echo number_format(intval($rep_card['books'] ?? 0)); ?></strong></div>
                </div>
                <div class="progress-row">
                    <div class="progress-bar"><span style="width: <?php echo intval($rep_card['health'] ?? 0); ?>%;"></span></div>
                    <strong><?php echo intval($rep_card['health'] ?? 0); ?>%</strong>
                </div>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">No representative activity is available in your current teaching scope yet.</div>
    <?php endif; ?>
</div>
