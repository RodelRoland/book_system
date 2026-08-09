<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button" data-open-screen="home" aria-label="Back home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div>
            <div class="screen-title">My Courses</div>
            <div class="screen-subtitle">Track materials, requests and books.</div>
        </div>
    </div>
    <button type="button" class="icon-button" data-open-subscreen="levels" aria-label="View levels">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 7h14M7 12h10M9 17h6"/></svg>
    </button>
</div>

<div class="section-block">
    <input type="search" class="search-input" id="courseSearch" placeholder="Search course...">
</div>

<div class="section-block" id="courseList">
    <?php if ($course_cards): ?>
        <?php foreach ($course_cards as $course_card): ?>
            <a class="course-card js-course-card" href="lecturer_dashboard.php?tab=courses&assignment_id=<?php echo intval($course_card['material_id']); ?>" data-query="<?php echo htmlspecialchars(strtolower(trim(strval(($course_card['course_code'] ?? '') . ' ' . ($course_card['material_title'] ?? '') . ' level ' . ($course_card['level'] ?? ''))))); ?>">
                <span class="icon-badge blue">CS</span>
                <div>
                    <div class="course-meta">
                        <strong><?php echo htmlspecialchars(strval($course_card['course_code'] ?? '')); ?></strong>
                        <small><?php echo htmlspecialchars(strval($course_card['material_title'] ?? '')); ?></small>
                        <small>Level <?php echo htmlspecialchars(strval($course_card['level'] ?? '')); ?></small>
                    </div>
                    <div class="course-stats">
                        <div><strong><?php echo number_format(intval($course_card['students'] ?? 0)); ?></strong><span>Students</span></div>
                        <div><strong><?php echo number_format(intval($course_card['requests'] ?? 0)); ?></strong><span>Requests</span></div>
                    </div>
                </div>
                <div style="display:grid; justify-items:end; gap:10px;">
                    <div class="rep-count"><?php echo number_format(intval($course_card['reps'] ?? 0)); ?> Reps</div>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </div>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">No teaching assignments are registered yet.</div>
    <?php endif; ?>
</div>

<div class="form-panel">
    <div class="section-head"><h2>Add / Manage Courses</h2></div>
    <form method="POST" class="section-block">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="add_material" value="1">
        <input type="hidden" name="dashboard_tab" value="courses" class="js-dashboard-tab">
        <div class="form-group">
            <label>Material Name</label>
            <input type="text" name="material_title" placeholder="e.g. Database Systems" required>
        </div>
        <div class="form-group">
            <label>Course Code</label>
            <input type="text" name="course_code" placeholder="e.g. ICT361" required>
        </div>
        <div class="form-group">
            <label>Academic Level</label>
            <input type="text" name="academic_level" placeholder="e.g. 300" inputmode="numeric" required>
        </div>
        <button type="submit" class="primary-btn">Add Teaching Assignment</button>
    </form>

    <div class="section-block">
        <?php if ($registered_materials): ?>
            <?php foreach ($registered_materials as $material): ?>
                <form method="POST" class="list-link" onsubmit="return confirm('Remove this material?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="remove_material" value="1">
                    <input type="hidden" name="material_id" value="<?php echo intval($material['material_id']); ?>">
                    <input type="hidden" name="dashboard_tab" value="courses" class="js-dashboard-tab">
                    <div class="row-inline">
                        <div>
                            <strong><?php echo htmlspecialchars(strval($material['course_code'] ?? '')); ?></strong>
                            <span><?php echo htmlspecialchars(strval($material['material_title'] ?? '')); ?> • Level <?php echo htmlspecialchars(strval($material['academic_level'] ?? '')); ?></span>
                        </div>
                        <button type="submit" class="ghost-link" style="color:#d92c2c;">Remove</button>
                    </div>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="form-panel">
    <div class="section-head"><h2>Record Distribution</h2></div>
    <form method="POST" class="section-block">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="record_distribution" value="1">
        <input type="hidden" name="dashboard_tab" value="courses" class="js-dashboard-tab">
        <div class="form-group">
            <label>Book</label>
            <select name="book_id" required>
                <option value="">-- Select assigned book --</option>
                <?php foreach ($assigned_books_list as $book_option): ?>
                    <option value="<?php echo intval($book_option['book_id'] ?? 0); ?>">
                        <?php echo htmlspecialchars(strval($book_option['book_title'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Course Rep</label>
            <select name="rep_admin_id">
                <option value="0">-- Unknown / Not selected --</option>
                <?php foreach ($reps_list as $rep_option): ?>
                    <option value="<?php echo intval($rep_option['admin_id'] ?? 0); ?>">
                        <?php echo htmlspecialchars(strval(($rep_option['full_name'] ?? '') !== '' ? $rep_option['full_name'] : ($rep_option['class_name'] ?? 'Rep'))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Copies Given</label>
            <input type="number" name="copies_given" step="1" required placeholder="e.g. 50">
        </div>
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="given_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" placeholder="Optional notes"></textarea>
        </div>
        <button type="submit" class="primary-btn">Record Distribution</button>
    </form>
</div>
