<div class="topbar">
    <div class="topbar-left">
        <a href="lecturer_dashboard.php?tab=reps<?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>" class="icon-button" aria-label="Back to representatives">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <div class="screen-title"><?php echo htmlspecialchars(strval($selected_rep_card['full_name'] ?? 'Representative')); ?></div>
            <div class="screen-subtitle"><?php echo htmlspecialchars(strval($selected_rep_card['class_name'] ?? '')); ?></div>
        </div>
    </div>
    <button type="button" class="icon-button" data-open-subscreen="payments" aria-label="Open payments">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12h16M12 4v16"/></svg>
    </button>
</div>

<div class="screen-panel">
    <div class="meta-grid">
        <div class="meta-box"><span>Collected</span><strong style="color:#11a25b;">GH&#8373;<?php echo number_format(floatval($selected_rep_card['collected'] ?? 0), 0); ?></strong></div>
        <div class="meta-box"><span>Outstanding</span><strong style="color:#ff4b38;">GH&#8373;<?php echo number_format(floatval($selected_rep_card['outstanding'] ?? 0), 0); ?></strong></div>
        <div class="meta-box"><span>Books</span><strong><?php echo number_format(intval($selected_rep_card['books'] ?? 0)); ?></strong></div>
    </div>
</div>

<div class="detail-menu">
    <a href="lecturer_rep_view.php?rep_id=<?php echo intval($selected_rep_card['admin_id']); ?><?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>"><div><strong>Students</strong><span>Open detailed student activity for this rep</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <a href="lecturer_rep_view.php?rep_id=<?php echo intval($selected_rep_card['admin_id']); ?><?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>"><div><strong>Books Requested</strong><span>View purchases tied to your assigned courses</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <a href="#" data-open-subscreen="payments"><div><strong>Payments & Collections</strong><span>Review current payments and outstanding balances</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <a href="lecturer_dashboard.php?tab=courses<?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>"><div><strong>Request History</strong><span>Jump back to the relevant course request summary</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <a href="lecturer_dashboard.php?tab=reports"><div><strong>Settlement Summary</strong><span>See reconciliation and summary reporting</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <a href="contact.php"><div><strong>Messages / Notes</strong><span>Reach support or leave notes for follow-up</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
</div>

<a href="lecturer_rep_view.php?rep_id=<?php echo intval($selected_rep_card['admin_id']); ?><?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>" class="primary-btn" style="min-height:52px;">View Details</a>
