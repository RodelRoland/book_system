<div class="topbar">
    <div class="topbar-left">
        <a href="lecturer_dashboard.php?tab=courses" class="icon-button" aria-label="Back to courses">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <div class="screen-title"><?php echo htmlspecialchars(strval($selected_course_summary['course_code'] ?? 'My Course')); ?></div>
            <div class="screen-subtitle"><?php echo htmlspecialchars(strval($selected_course_summary['material_title'] ?? '')); ?> • Level <?php echo htmlspecialchars(strval($selected_course_summary['level'] ?? '')); ?></div>
        </div>
    </div>
    <button type="button" class="icon-button" data-open-subscreen="analytics" aria-label="Open analytics">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V5M10 19V9M16 19V3M22 19v-7"/></svg>
    </button>
</div>

<?php
    $course_outstanding = 0.0;
    foreach (array_map('intval', $selected_course_summary['matched_book_ids'] ?? []) as $course_book_id) {
        $price = floatval($book_details_map[$course_book_id]['price'] ?? 0);
        foreach ($rep_cards as $rep_card) {
            $rep_id_for_course = intval($rep_card['admin_id'] ?? 0);
            $rep_book_copies = intval($rep_received_map[$course_book_id][$rep_id_for_course]['rep_copies'] ?? 0);
            $rep_book_paid = floatval($rep_payment_map[$course_book_id][$rep_id_for_course]['amount_paid_total'] ?? 0);
            $course_outstanding += max(($rep_book_copies * $price) - $rep_book_paid, 0);
        }
    }
    $course_level_regex = lecturer_dashboard_level_regex(strval($selected_course_summary['level'] ?? ''));
?>

<div class="screen-panel">
    <div class="meta-grid">
        <div class="meta-box"><span>Representatives</span><strong><?php echo number_format(intval($selected_course_summary['reps'] ?? 0)); ?></strong></div>
        <div class="meta-box"><span>Requests</span><strong><?php echo number_format(intval($selected_course_summary['requests'] ?? 0)); ?></strong></div>
        <div class="meta-box"><span>Outstanding</span><strong>GH&#8373;<?php echo number_format($course_outstanding, 0); ?></strong></div>
    </div>
</div>

<div class="section-block">
    <div class="section-head">
        <h2>Representatives</h2>
        <a href="lecturer_dashboard.php?tab=reps">View All Representatives</a>
    </div>
    <div class="section-block rep-list-grid">
        <?php $course_rep_found = false; ?>
        <?php foreach ($rep_cards as $rep_card): ?>
            <?php
                $rep_class = strval($rep_card['class_name'] ?? '');
                if ($course_level_regex !== '' && $rep_class !== '' && !@preg_match('/' . $course_level_regex . '/i', $rep_class)) {
                    continue;
                }
                $course_rep_found = true;
            ?>
            <a class="rep-card" href="lecturer_dashboard.php?tab=reps&rep_id=<?php echo intval($rep_card['admin_id']); ?>&assignment_id=<?php echo intval($selected_course_summary['material_id']); ?>">
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
        <?php if (!$course_rep_found): ?>
            <div class="empty-state">No representatives are currently linked to this course level.</div>
        <?php endif; ?>
    </div>
</div>

<div class="section-block">
    <div class="section-head"><h2>Books In This Course</h2></div>
    <div class="badge-row">
        <?php foreach (array_map('intval', $selected_course_summary['matched_book_ids'] ?? []) as $course_book_id): ?>
            <a class="pill-chip" href="lecturer_dashboard.php?tab=courses&assignment_id=<?php echo intval($selected_course_summary['material_id']); ?>&book_id=<?php echo $course_book_id; ?>">
                <?php echo htmlspecialchars(strval($book_details_map[$course_book_id]['book_title'] ?? 'Book')); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($selected_book_id > 0): ?>
    <div class="screen-panel">
        <div class="section-head">
            <h3><?php echo htmlspecialchars($selected_book_title); ?></h3>
            <span class="pill-chip"><?php echo number_format($collected_students_count); ?> collected</span>
        </div>
        <div class="section-block">
            <?php if ($rep_totals): ?>
                <?php foreach ($rep_totals as $rep_total): ?>
                    <div class="list-link">
                        <div class="row-inline">
                            <div>
                                <strong><?php echo htmlspecialchars(strval($rep_total['full_name'] ?? 'Unknown Rep')); ?></strong>
                                <span><?php echo htmlspecialchars(strval($rep_total['class_name'] ?? '')); ?></span>
                            </div>
                            <strong><?php echo number_format(intval($rep_total['copies'] ?? 0)); ?> copies</strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">No distribution records were found for this selected book yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-panel">
        <div class="section-head"><h2>Recent Entries</h2></div>
        <?php if ($recent_distributions): ?>
            <?php foreach ($recent_distributions as $distribution_row): ?>
                <form method="POST" class="screen-panel" style="box-shadow:none; border-style:dashed;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="distribution_id" value="<?php echo intval($distribution_row['distribution_id'] ?? 0); ?>">
                    <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                    <input type="hidden" name="dashboard_tab" value="courses" class="js-dashboard-tab">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="given_date" value="<?php echo htmlspecialchars(strval($distribution_row['given_date'] ?? '')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Rep</label>
                        <select name="rep_admin_id">
                            <option value="0">-- Unknown --</option>
                            <?php foreach ($reps_list as $rep_option): ?>
                                <?php $rep_option_id = intval($rep_option['admin_id'] ?? 0); ?>
                                <option value="<?php echo $rep_option_id; ?>" <?php echo intval($distribution_row['rep_admin_id'] ?? 0) === $rep_option_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(strval(($rep_option['full_name'] ?? '') !== '' ? $rep_option['full_name'] : ($rep_option['class_name'] ?? 'Rep'))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Copies</label>
                        <input type="number" name="copies_given" step="1" value="<?php echo intval($distribution_row['copies_given'] ?? 0); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" name="notes" value="<?php echo htmlspecialchars(strval($distribution_row['notes'] ?? '')); ?>">
                    </div>
                    <div class="row-inline">
                        <button type="submit" name="update_distribution" value="1" class="secondary-btn">Update</button>
                        <button type="submit" name="delete_distribution" value="1" class="secondary-btn" onclick="return confirm('Delete this entry?');" style="color:#c73232;">Delete</button>
                    </div>
                </form>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">No recent distribution entries were found for this selected book.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>
