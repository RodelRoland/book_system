<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? '');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}

$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_name = strval($access_context['effective_full_name'] ?? $access_context['effective_username'] ?? 'Rep');
$current_admin_class = trim(strval($access_context['effective_class_name'] ?? ''));
$actor_is_assistant = !empty($access_context['is_assistant_mode']);
$workspace_actor_name = trim(strval($actor_is_assistant ? ($access_context['actor_full_name'] ?? $access_context['actor_username'] ?? '') : $current_admin_name));
$workspace_actor_name = $workspace_actor_name !== '' ? $workspace_actor_name : $current_admin_name;
$workspace_actor_role = $actor_is_assistant ? 'Assistant Rep' : 'Class Representative';
$profile_photo_path = '';
$profile_initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $workspace_actor_name), 0, 2));
if ($profile_initials === '') {
    $profile_initials = 'GD';
}

$profile_stmt = $conn->prepare("SELECT profile_photo_path FROM admins WHERE admin_id = ?");
if ($profile_stmt) {
    $profile_stmt->bind_param('i', $current_admin_id);
    $profile_stmt->execute();
    $profile_stmt->bind_result($profile_photo_path_result);
    if ($profile_stmt->fetch()) {
        $profile_photo_path = trim(strval($profile_photo_path_result ?? ''));
    }
    $profile_stmt->close();
}
if ($actor_is_assistant) {
    $profile_photo_path = trim(strval($access_context['actor_profile_photo_path'] ?? ''));
    if ($workspace_actor_name === '') {
        header('Location: my_profile.php?setup=1');
        exit;
    }
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}
$semester_name = '';
if ($semester_id > 0) {
    $semester_stmt = $conn->prepare("SELECT semester_name FROM semesters WHERE semester_id = ? LIMIT 1");
    if ($semester_stmt) {
        $semester_stmt->bind_param('i', $semester_id);
        $semester_stmt->execute();
        $semester_stmt->bind_result($semester_name_result);
        if ($semester_stmt->fetch()) {
            $semester_name = trim(strval($semester_name_result ?? ''));
        }
        $semester_stmt->close();
    }
}

$sessions_table_available = false;
$groups_table_available = false;
$participants_table_available = false;
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_sessions'");
$sessions_table_available = ($table_check && $table_check->num_rows === 1);
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_groups'");
$groups_table_available = ($table_check && $table_check->num_rows === 1);
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_participants'");
$participants_table_available = ($table_check && $table_check->num_rows === 1);
if (!$sessions_table_available || !$groups_table_available || !$participants_table_available) {
    header('Location: group_draw_dashboard.php?msg=schema_missing');
    exit;
}

$csrf_token = csrf_get_token();
$rep_bottom_nav_active = '';
$rep_bottom_nav_profile_href = $actor_is_assistant ? 'assistant_profile.php' : 'my_profile.php';

function book_system_group_draw_manage_redirect(string $msg): void {
    header('Location: group_draw_manage_session.php?msg=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['session_action'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        book_system_group_draw_manage_redirect('csrf_invalid');
    }

    $action = trim(strval($_POST['session_action'] ?? ''));
    $target_session_id = intval($_POST['session_id'] ?? 0);
    if ($semester_id <= 0 || $target_session_id <= 0) {
        book_system_group_draw_manage_redirect($action === 'close' ? 'cannot_close_invalid_state' : 'cannot_open_invalid_state');
    }

    $stmt = $conn->prepare("SELECT
            s.session_id,
            s.status,
            (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id) AS participant_count,
            (SELECT COUNT(*) FROM group_draw_groups g WHERE g.session_id = s.session_id) AS group_count,
            COALESCE((SELECT SUM(g.capacity) FROM group_draw_groups g WHERE g.session_id = s.session_id), 0) AS total_capacity,
            (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id AND p.draw_status = 'drawn') AS drawn_count
        FROM group_draw_sessions s
        WHERE s.session_id = ? AND s.admin_id = ? AND s.semester_id = ?
        LIMIT 1");
    $session_row = null;
    if ($stmt) {
        $stmt->bind_param('iii', $target_session_id, $current_admin_id, $semester_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $session_row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if (!$session_row) {
        book_system_group_draw_manage_redirect($action === 'close' ? 'cannot_close_invalid_state' : 'cannot_open_invalid_state');
    }

    $status = trim(strval($session_row['status'] ?? 'draft'));
    $participant_count = intval($session_row['participant_count'] ?? 0);
    $group_count = intval($session_row['group_count'] ?? 0);
    $total_capacity = intval($session_row['total_capacity'] ?? 0);

    if ($action === 'open') {
        if ($status !== 'draft') {
            book_system_group_draw_manage_redirect('cannot_open_invalid_state');
        }
        if ($participant_count <= 0) {
            book_system_group_draw_manage_redirect('cannot_open_missing_participants');
        }
        if ($group_count <= 0) {
            book_system_group_draw_manage_redirect('cannot_open_missing_groups');
        }
        if ($total_capacity < $participant_count) {
            book_system_group_draw_manage_redirect('cannot_open_capacity_short');
        }

        $update_stmt = $conn->prepare("UPDATE group_draw_sessions SET status = 'open' WHERE session_id = ? AND admin_id = ? AND semester_id = ? LIMIT 1");
        if ($update_stmt) {
            $update_stmt->bind_param('iii', $target_session_id, $current_admin_id, $semester_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        book_system_group_draw_manage_redirect('draw_opened');
    }

    if ($action === 'close') {
        if ($status !== 'open') {
            book_system_group_draw_manage_redirect('cannot_close_invalid_state');
        }

        $update_stmt = $conn->prepare("UPDATE group_draw_sessions SET status = 'closed' WHERE session_id = ? AND admin_id = ? AND semester_id = ? LIMIT 1");
        if ($update_stmt) {
            $update_stmt->bind_param('iii', $target_session_id, $current_admin_id, $semester_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        book_system_group_draw_manage_redirect('draw_closed');
    }
}

$page_msg = trim(strval($_GET['msg'] ?? ''));
$notice_text = '';
$notice_type = 'info';
switch ($page_msg) {
    case 'draw_opened':
        $notice_text = 'Session opened successfully. Students can now draw when the student draw phase is available.';
        $notice_type = 'success';
        break;
    case 'draw_closed':
        $notice_text = 'Session closed successfully. Students can no longer draw in this session.';
        $notice_type = 'success';
        break;
    case 'cannot_open_missing_participants':
        $notice_text = 'This session cannot be opened yet because no participants have been imported.';
        $notice_type = 'warning';
        break;
    case 'cannot_open_missing_groups':
        $notice_text = 'This session cannot be opened yet because groups have not been generated.';
        $notice_type = 'warning';
        break;
    case 'cannot_open_capacity_short':
        $notice_text = 'This session cannot be opened because total group capacity is below the participant count.';
        $notice_type = 'warning';
        break;
    case 'cannot_open_invalid_state':
        $notice_text = 'This session cannot be opened from its current state.';
        $notice_type = 'warning';
        break;
    case 'cannot_close_invalid_state':
        $notice_text = 'This session cannot be closed from its current state.';
        $notice_type = 'warning';
        break;
    case 'csrf_invalid':
        $notice_text = 'Your session expired. Please try again.';
        $notice_type = 'warning';
        break;
}

$sessions = [];
$stmt = $conn->prepare("SELECT
        s.session_id,
        s.session_title,
        s.status,
        s.created_at,
        (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id) AS participant_count,
        (SELECT COUNT(*) FROM group_draw_groups g WHERE g.session_id = s.session_id) AS group_count,
        COALESCE((SELECT SUM(g.capacity) FROM group_draw_groups g WHERE g.session_id = s.session_id), 0) AS total_capacity,
        (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id AND p.draw_status = 'drawn') AS drawn_count
    FROM group_draw_sessions s
    WHERE s.admin_id = ? AND s.semester_id = ?
    ORDER BY s.created_at DESC");
if ($stmt) {
    $stmt->bind_param('ii', $current_admin_id, $semester_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $sessions[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sessions</title>
    <style>
        :root {
            --bg: #f5f8fe;
            --surface: rgba(255, 255, 255, 0.96);
            --surface-soft: #f8fbff;
            --line: #e0ebfb;
            --text: #172033;
            --muted: #667085;
            --primary: #2563eb;
            --primary-soft: #e8f0ff;
            --success: #166534;
            --success-soft: #dcfce7;
            --warning: #b45309;
            --warning-soft: #fff7ed;
            --shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.08), transparent 34%),
                linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
            color: var(--text);
            padding: 14px 12px 120px;
        }
        a { color: inherit; text-decoration: none; }
        button { font: inherit; }
        .page {
            width: min(100%, 430px);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }
        .hero, .card, .session-card, .notice {
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.92);
            box-shadow: var(--shadow);
        }
        .hero {
            border-radius: var(--radius-xl);
            padding: 18px;
            display: grid;
            gap: 14px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            font-size: 13px;
            font-weight: 800;
        }
        .hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .hero h1 {
            margin: 10px 0 6px;
            font-size: 28px;
            line-height: 1.08;
        }
        .hero p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid var(--line);
            font-size: 12px;
            color: #334155;
            font-weight: 700;
        }
        .profile-badge {
            width: 58px;
            height: 58px;
            border-radius: 20px;
            background: linear-gradient(135deg, #dbeafe, #eef4ff);
            border: 1px solid rgba(37,99,235,0.12);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            color: var(--primary);
            font-size: 18px;
            font-weight: 800;
        }
        .profile-badge img { width: 100%; height: 100%; object-fit: cover; }
        .card {
            border-radius: var(--radius-lg);
            padding: 16px;
            display: grid;
            gap: 12px;
        }
        .card h2 {
            margin: 0;
            font-size: 20px;
            line-height: 1.2;
        }
        .card-copy {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .create-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 16px;
            border-radius: 16px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.22);
        }
        .notice {
            border-radius: var(--radius-lg);
            padding: 14px 16px;
            font-size: 13px;
            line-height: 1.55;
        }
        .notice.info {
            color: #1d4ed8;
            border-color: #bfdbfe;
            background: linear-gradient(180deg, #eef6ff, #ffffff);
        }
        .notice.success {
            color: var(--success);
            border-color: #bbf7d0;
            background: linear-gradient(180deg, #ecfdf5, #ffffff);
        }
        .notice.warning {
            color: #9a3412;
            border-color: #fed7aa;
            background: linear-gradient(180deg, #fff7ed, #ffffff);
        }
        .session-list {
            display: grid;
            gap: 14px;
        }
        .session-card {
            border-radius: var(--radius-lg);
            padding: 16px;
            display: grid;
            gap: 14px;
        }
        .session-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .session-head h3 {
            margin: 0;
            font-size: 18px;
            line-height: 1.25;
        }
        .session-head p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .status-draft { background: #eef2ff; color: var(--primary); }
        .status-open { background: #dcfce7; color: var(--success); }
        .status-closed { background: #fff7ed; color: #c2410c; }
        .status-archived { background: #f3f4f6; color: #475569; }
        .session-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .metric {
            border-radius: 16px;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            padding: 12px 13px;
        }
        .metric strong {
            display: block;
            font-size: 20px;
            line-height: 1;
            margin-bottom: 6px;
            color: #0f172a;
        }
        .metric span {
            font-size: 12px;
            color: var(--muted);
        }
        .session-created {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .session-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .button,
        .button-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 16px;
            border-radius: 16px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }
        .button {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.22);
        }
        .button-secondary {
            background: #fff;
            color: #334155;
            border-color: var(--line);
        }
        .helper-note {
            padding: 12px 13px;
            border-radius: 16px;
            background: #f8fbff;
            border: 1px dashed #cbdcf7;
            color: #334155;
            font-size: 12px;
            line-height: 1.55;
        }
        .empty-card {
            border-radius: var(--radius-lg);
            padding: 16px;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        @media (min-width: 760px) {
            body { padding-top: 20px; }
            .page { width: min(100%, 520px); gap: 18px; }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <a class="back-link" href="group_draw_dashboard.php">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back to Group Draw
        </a>
        <div class="hero-top">
            <div>
                <span class="hero-eyebrow">Session Management</span>
                <h1>Manage Sessions</h1>
                <p>Open and close Group Draw sessions when they are ready. Draft sessions can still accept participants and groups, while closed and archived sessions are protected from further draw activity.</p>
            </div>
            <div class="profile-badge" aria-hidden="true">
                <?php if ($profile_photo_path !== ''): ?>
                    <img src="<?php echo htmlspecialchars($profile_photo_path); ?>" alt="">
                <?php else: ?>
                    <?php echo htmlspecialchars($profile_initials); ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-meta">
            <span class="hero-chip"><?php echo htmlspecialchars($workspace_actor_role . ': ' . $workspace_actor_name); ?></span>
            <?php if ($current_admin_class !== ''): ?>
                <span class="hero-chip"><?php echo htmlspecialchars('Class: ' . $current_admin_class); ?></span>
            <?php endif; ?>
            <?php if ($semester_name !== ''): ?>
                <span class="hero-chip"><?php echo htmlspecialchars($semester_name); ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <h2>Session Control</h2>
        <p class="card-copy">Only draft sessions with participants, generated groups, and enough total capacity can be opened. Open sessions can be closed. Archived sessions remain read-only.</p>
        <a href="group_draw_create_session.php" class="create-link">Create New Session</a>
    </section>

    <?php if ($notice_text !== ''): ?>
        <section class="notice <?php echo htmlspecialchars($notice_type); ?>"><?php echo htmlspecialchars($notice_text); ?></section>
    <?php endif; ?>

    <?php if (!$sessions): ?>
        <section class="empty-card">
            No Group Draw sessions exist yet for this workspace. Create a new session to begin setup.
        </section>
    <?php else: ?>
        <section class="session-list">
            <?php foreach ($sessions as $session): ?>
                <?php
                $session_id = intval($session['session_id'] ?? 0);
                $status = trim(strval($session['status'] ?? 'draft'));
                $participant_count = intval($session['participant_count'] ?? 0);
                $group_count = intval($session['group_count'] ?? 0);
                $total_capacity = intval($session['total_capacity'] ?? 0);
                $drawn_count = intval($session['drawn_count'] ?? 0);
                $created_label = trim(strval($session['created_at'] ?? '')) !== '' ? date('M j, Y g:i A', strtotime(strval($session['created_at']))) : 'Just now';
                $status_class = 'status-' . preg_replace('/[^a-z]/', '', strtolower($status));
                $can_open = ($status === 'draft' && $participant_count > 0 && $group_count > 0 && $total_capacity >= $participant_count);
                $can_close = ($status === 'open');
                ?>
                <article class="session-card" id="session-<?php echo $session_id; ?>">
                    <div class="session-head">
                        <div>
                            <h3><?php echo htmlspecialchars(strval($session['session_title'] ?? 'Untitled Session')); ?></h3>
                            <p><?php echo htmlspecialchars('Session #' . $session_id); ?></p>
                        </div>
                        <span class="status-pill <?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars($status); ?></span>
                    </div>

                    <div class="session-grid">
                        <div class="metric">
                            <strong><?php echo $participant_count; ?></strong>
                            <span>Participants</span>
                        </div>
                        <div class="metric">
                            <strong><?php echo $group_count; ?></strong>
                            <span>Groups</span>
                        </div>
                        <div class="metric">
                            <strong><?php echo $total_capacity; ?></strong>
                            <span>Total Capacity</span>
                        </div>
                        <div class="metric">
                            <strong><?php echo $drawn_count; ?></strong>
                            <span>Students Drawn</span>
                        </div>
                    </div>

                    <div class="session-created">Created <?php echo htmlspecialchars($created_label); ?></div>

                    <?php if ($status === 'draft' && !$can_open): ?>
                        <div class="helper-note">
                            <?php if ($participant_count <= 0): ?>
                                Participants are still missing for this draft session.
                            <?php elseif ($group_count <= 0): ?>
                                Groups must be generated before this session can be opened.
                            <?php elseif ($total_capacity < $participant_count): ?>
                                Total group capacity is lower than the participant count, so opening is blocked.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="session-actions">
                        <?php if ($can_open): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                                <button type="submit" name="session_action" value="open" class="button">Open Draw</button>
                            </form>
                        <?php elseif ($can_close): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                                <button type="submit" name="session_action" value="close" class="button">Close Draw</button>
                            </form>
                        <?php else: ?>
                            <a href="#session-<?php echo $session_id; ?>" class="button-secondary">View</a>
                        <?php endif; ?>

                        <?php if ($status === 'draft'): ?>
                            <a href="group_draw_upload.php?session_id=<?php echo $session_id; ?>" class="button-secondary">Participants</a>
                            <a href="group_draw_generate_groups.php?session_id=<?php echo $session_id; ?>" class="button-secondary">Groups</a>
                        <?php endif; ?>
                        <a href="group_draw_student.php?session_id=<?php echo $session_id; ?>" class="button-secondary" target="_blank" rel="noopener">Student Link</a>
                        <a href="group_draw_results.php?session_id=<?php echo $session_id; ?>" class="button-secondary">Results</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
</body>
</html>
