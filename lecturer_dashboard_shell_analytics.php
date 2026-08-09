<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button" data-close-subscreen aria-label="Back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div>
            <div class="screen-title">Analytics</div>
            <div class="screen-subtitle">A quick semester snapshot for your lecturer workspace.</div>
        </div>
    </div>
</div>

<div class="analytics-hero">
    <div>
        <div class="screen-subtitle" style="margin-top:0;">Total Revenue</div>
        <div class="screen-title" style="font-size:34px; margin-top:6px; color:#11a25b;">GH&#8373;<?php echo number_format($home_total_paid, 2); ?></div>
    </div>
    <div class="chart-box">
        <?php
            $chart_points = [];
            $chart_seed = array_slice($top_requested_rows, 0, 6);
            $chart_count = count($chart_seed);
            $chart_max = max(1, intval($chart_seed[0]['requests'] ?? 1));
            foreach ($chart_seed as $point_index => $chart_row) {
                $x = $chart_count > 1 ? ($point_index * (260 / max($chart_count - 1, 1))) : 0;
                $y = 120 - ((intval($chart_row['requests'] ?? 0) / $chart_max) * 88);
                $chart_points[] = round($x, 1) . ',' . round($y, 1);
            }
        ?>
        <svg viewBox="0 0 280 140" width="100%" height="100%" preserveAspectRatio="none">
            <path d="M0 120 H280" stroke="#dbe7f7" stroke-width="1"/>
            <path d="M0 80 H280" stroke="#e9eff9" stroke-width="1"/>
            <path d="M0 40 H280" stroke="#eef3fb" stroke-width="1"/>
            <?php if (count($chart_points) > 1): ?>
                <polyline fill="none" stroke="#1d56df" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points="<?php echo htmlspecialchars(implode(' ', $chart_points)); ?>"/>
            <?php endif; ?>
        </svg>
    </div>
</div>

<div class="analytics-metrics">
    <div class="simple-card">
        <span>Paid</span>
        <strong style="color:#10a65a;">GH&#8373;<?php echo number_format($home_total_paid, 2); ?></strong>
    </div>
    <div class="simple-card">
        <span>Outstanding</span>
        <strong style="color:#ff4b38;">GH&#8373;<?php echo number_format($home_total_outstanding, 2); ?></strong>
    </div>
    <div class="simple-card">
        <span>Requests</span>
        <strong><?php echo number_format($home_total_requests); ?></strong>
    </div>
    <div class="simple-card">
        <span>Books Issued</span>
        <strong><?php echo number_format($home_total_books_collected); ?></strong>
    </div>
</div>

<div class="screen-panel">
    <div class="section-head"><h2>Top Requested Books</h2></div>
    <div class="summary-list">
        <?php if ($top_requested_rows): ?>
            <?php $top_book_max = max(1, intval($top_requested_rows[0]['requests'] ?? 1)); ?>
            <?php foreach ($top_requested_rows as $summary_row): ?>
                <?php $percentage = intval(round((intval($summary_row['requests'] ?? 0) / $top_book_max) * 100)); ?>
                <div class="summary-item">
                    <div>
                        <strong><?php echo htmlspecialchars(strval($summary_row['book_title'] ?? 'Book')); ?></strong>
                        <div class="bar"><span style="width: <?php echo $percentage; ?>%;"></span></div>
                    </div>
                    <div style="text-align:right;">
                        <strong><?php echo number_format(intval($summary_row['requests'] ?? 0)); ?></strong>
                        <span><?php echo $percentage; ?>%</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">No request activity has been recorded yet.</div>
        <?php endif; ?>
    </div>
</div>
