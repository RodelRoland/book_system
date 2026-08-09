<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button" data-open-screen="home" aria-label="Back home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
    </div>
    <div class="topbar-right">
        <button type="button" class="icon-button" aria-label="Settings">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
    </div>
</div>

<div class="profile-hero">
    <div class="profile-identity">
        <div class="profile-avatar"><?php echo htmlspecialchars($safe_lecturer_initials !== '' ? $safe_lecturer_initials : 'CB'); ?></div>
        <div>
            <div style="font-size:22px; font-weight:800; letter-spacing:-0.03em;"><?php echo htmlspecialchars($lecturer_name); ?></div>
            <div style="font-size:13px; opacity:0.88;">Lecturer</div>
            <div style="font-size:12px; opacity:0.82; margin-top:4px;"><?php echo htmlspecialchars($active_levels_label); ?> • <?php echo htmlspecialchars($semester_label); ?></div>
        </div>
    </div>
</div>

<div class="settings-list">
    <a href="common_request_portal.php" class="list-link"><div><strong>Request Portal</strong><span>Open the public ClassBookHub portal</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <a href="about.php" class="list-link"><div><strong>About ClassBookHub</strong><span>Learn more about the platform</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <a href="contact.php" class="list-link"><div><strong>Help & Support</strong><span>Support hours and contact information</span></div><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></a>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="dashboard_tab" value="profile" class="js-dashboard-tab">
        <button type="submit" name="logout" value="1" class="list-link logout" style="width:100%; text-align:left; border:none; background:#fff; cursor:pointer;">
            <div><strong>Logout</strong><span>End your lecturer session securely</span></div>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
        </button>
    </form>
</div>
