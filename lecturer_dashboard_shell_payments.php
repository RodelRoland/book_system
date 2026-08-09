<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button" data-close-subscreen aria-label="Back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div>
            <div class="screen-title">Payments</div>
            <div class="screen-subtitle">Monitor recent collections and outstanding rep balances.</div>
        </div>
    </div>
</div>

<div class="chip-filters">
    <button type="button" class="filter-chip is-active" data-payment-filter="all">All</button>
    <button type="button" class="filter-chip" data-payment-filter="paid">Paid</button>
    <button type="button" class="filter-chip" data-payment-filter="pending">Pending</button>
</div>

<div class="card-rail" id="paymentFeed">
    <?php if ($payment_rows): ?>
        <?php foreach ($payment_rows as $payment_row): ?>
            <div class="payment-row js-payment-row" data-payment-state="paid">
                <div class="row-inline">
                    <div>
                        <strong><?php echo htmlspecialchars(strval($payment_row['rep_name'] ?? 'Unknown Rep')); ?></strong>
                        <span><?php echo htmlspecialchars(date('M d, Y • g:i A', strtotime(strval($payment_row['payment_date'] ?? 'now')))); ?></span>
                    </div>
                    <div style="text-align:right;">
                        <strong>GH&#8373; <?php echo number_format(floatval($payment_row['amount_paid'] ?? 0), 2); ?></strong>
                        <div class="status-pill paid" style="margin-top:6px;">Paid</div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php foreach (array_filter($rep_cards, static function (array $rep_card): bool { return floatval($rep_card['outstanding'] ?? 0) > 0; }) as $rep_card): ?>
        <div class="payment-row js-payment-row" data-payment-state="pending">
            <div class="row-inline">
                <div>
                    <strong><?php echo htmlspecialchars(strval($rep_card['full_name'] ?? 'Rep')); ?></strong>
                    <span><?php echo htmlspecialchars(strval($rep_card['class_name'] ?? '')); ?></span>
                </div>
                <div style="text-align:right;">
                    <strong>GH&#8373; <?php echo number_format(floatval($rep_card['outstanding'] ?? 0), 2); ?></strong>
                    <div class="status-pill pending" style="margin-top:6px;">Pending</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$payment_rows && !$rep_cards): ?>
        <div class="empty-state">No payment activity is available yet.</div>
    <?php endif; ?>
</div>
