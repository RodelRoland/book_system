<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button" data-open-screen="home" aria-label="Back home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div>
            <div class="screen-title">Reports</div>
            <div class="screen-subtitle">Generate and export report summaries.</div>
        </div>
    </div>
</div>

<div class="report-grid">
    <button type="button" class="report-card" data-open-subscreen="analytics">
        <span class="icon-badge green">DR</span>
        <strong>Daily Report</strong>
        <p>Today’s activity snapshot across requests, collections and payments.</p>
    </button>
    <div class="report-card">
        <span class="icon-badge purple">SR</span>
        <strong>Semester Report</strong>
        <p><?php echo htmlspecialchars($semester_label); ?> summary for your assigned courses and reps.</p>
    </div>
    <button type="button" class="report-card" data-open-screen="courses">
        <span class="icon-badge blue">CR</span>
        <strong>Course Report</strong>
        <p>Jump into course-level request and distribution details.</p>
    </button>
    <button type="button" class="report-card" data-open-screen="reps">
        <span class="icon-badge orange">RR</span>
        <strong>Representative Report</strong>
        <p>Review rep collections, balances and stock flow.</p>
    </button>
    <div class="report-card">
        <span class="icon-badge green">XL</span>
        <strong>Export Excel</strong>
        <p>Use the existing CSV exports from book and rep detail screens when needed.</p>
    </div>
    <div class="report-card">
        <span class="icon-badge pink">PF</span>
        <strong>Export PDF</strong>
        <p>Print or save this lecturer portal as PDF from your browser when needed.</p>
    </div>
</div>

<div class="screen-panel">
    <div class="section-head"><h2>Reconciliation</h2></div>
    <?php if ($reconciliation_rows): ?>
        <div class="section-block">
            <?php foreach (array_slice($reconciliation_rows, 0, 4) as $reconciliation_row): ?>
                <div class="list-link">
                    <div class="row-inline">
                        <div>
                            <strong><?php echo htmlspecialchars(strval($reconciliation_row['rep_name'] ?? 'Unknown Rep')); ?></strong>
                            <span><?php echo htmlspecialchars(strval($reconciliation_row['material_title'] ?? '')); ?></span>
                        </div>
                        <div class="status-pill <?php echo strval($reconciliation_row['status_class'] ?? '') === 'pill-green' ? 'paid' : 'pending'; ?>">
                            <?php echo htmlspecialchars(strval($reconciliation_row['status_label'] ?? 'Review')); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">No reconciliation records are available yet for this semester.</div>
    <?php endif; ?>
</div>
