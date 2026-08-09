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
$viewing_workspace = !empty($access_context['is_workspace_mode']);
$viewing_own_rep_workspace = !empty($access_context['is_own_rep_mode']);
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

$tables_available = [
    'group_draw_sessions' => false,
    'group_draw_groups' => false,
    'group_draw_participants' => false,
    'group_draw_draws' => false,
];
foreach (array_keys($tables_available) as $table_name) {
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'");
    $tables_available[$table_name] = ($res && $res->num_rows === 1);
}

$stats = [
    'sessions' => 0,
    'groups' => 0,
    'participants' => 0,
    'draws' => 0,
];

if ($tables_available['group_draw_sessions']) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM group_draw_sessions WHERE admin_id = ? AND semester_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $current_admin_id, $semester_id);
        $stmt->execute();
        $stmt->bind_result($stats['sessions']);
        $stmt->fetch();
        $stmt->close();
    }
}
if ($tables_available['group_draw_groups']) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM group_draw_groups WHERE admin_id = ? AND semester_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $current_admin_id, $semester_id);
        $stmt->execute();
        $stmt->bind_result($stats['groups']);
        $stmt->fetch();
        $stmt->close();
    }
}
if ($tables_available['group_draw_participants']) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM group_draw_participants WHERE admin_id = ? AND semester_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $current_admin_id, $semester_id);
        $stmt->execute();
        $stmt->bind_result($stats['participants']);
        $stmt->fetch();
        $stmt->close();
    }
}
if ($tables_available['group_draw_draws']) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM group_draw_draws WHERE admin_id = ? AND semester_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $current_admin_id, $semester_id);
        $stmt->execute();
        $stmt->bind_result($stats['draws']);
        $stmt->fetch();
        $stmt->close();
    }
}

$workspace_badge = $viewing_workspace
    ? 'Rep Workspace'
    : ($viewing_own_rep_workspace ? 'Own Rep Workspace' : 'Rep Workspace');
$rep_bottom_nav_active = '';
$rep_bottom_nav_profile_href = $actor_is_assistant ? 'assistant_profile.php' : 'my_profile.php';
$dashboard_msg = trim(strval($_GET['msg'] ?? ''));
$dashboard_notice = '';
if ($dashboard_msg === 'session_created') {
    $dashboard_notice = 'Group Draw session created successfully.';
} elseif ($dashboard_msg === 'schema_missing') {
    $dashboard_notice = 'Group Draw tables are not available yet in this workspace.';
} elseif ($dashboard_msg === 'participants_imported') {
    $dashboard_notice = 'Group Draw participants imported successfully.';
} elseif ($dashboard_msg === 'groups_generated') {
    $dashboard_notice = 'Group Draw groups generated successfully.';
}

$recent_sessions = [];
if ($tables_available['group_draw_sessions']) {
    $stmt = $conn->prepare("SELECT
            s.session_id,
            s.session_title,
            s.session_description,
            s.preferred_group_size,
            s.status,
            s.created_at,
            (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id) AS participant_count,
            (SELECT COUNT(*) FROM group_draw_groups g WHERE g.session_id = s.session_id) AS group_count
        FROM group_draw_sessions s
        WHERE s.admin_id = ? AND s.semester_id = ?
        ORDER BY s.created_at DESC
        LIMIT 5");
    if ($stmt) {
        $stmt->bind_param('ii', $current_admin_id, $semester_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $recent_sessions[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Draw</title>
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
            --teal: #0f766e;
            --teal-soft: #dff8f3;
            --amber: #b45309;
            --amber-soft: #fff5dd;
            --purple: #7c3aed;
            --purple-soft: #f0e8ff;
            --rose: #e11d48;
            --rose-soft: #ffe8ef;
            --shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 18px;
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
        .page {
            width: min(100%, 430px);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }
        .hero,
        .card,
        .placeholder-card,
        .session-row {
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
        .hero-copy {
            min-width: 0;
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
        .profile-badge img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
        .section-title {
            margin: 2px 4px 0;
            font-size: 20px;
            line-height: 1.2;
        }
        .section-copy {
            margin: 0 4px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }
        .notice-card {
            border-radius: var(--radius-lg);
            padding: 14px 16px;
            background: linear-gradient(180deg, #eef6ff, #ffffff);
            border: 1px solid #bfdbfe;
            box-shadow: var(--shadow);
            color: #1d4ed8;
            font-size: 13px;
            line-height: 1.55;
            font-weight: 700;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .card {
            border-radius: var(--radius-lg);
            padding: 16px;
        }
        .stat-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-size: 12px;
            font-weight: 800;
        }
        .stat-label .pill {
            width: 36px;
            height: 36px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .pill.blue { background: #e8f0ff; color: var(--primary); }
        .pill.teal { background: var(--teal-soft); color: var(--teal); }
        .pill.amber { background: var(--amber-soft); color: var(--amber); }
        .pill.purple { background: var(--purple-soft); color: var(--purple); }
        .stat-value {
            margin-top: 14px;
            font-size: 26px;
            line-height: 1;
            font-weight: 800;
            color: #0f172a;
        }
        .stat-note {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .placeholder-grid {
            display: grid;
            gap: 12px;
        }
        .placeholder-card {
            border-radius: var(--radius-lg);
            padding: 16px;
            display: grid;
            gap: 10px;
        }
        .placeholder-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .placeholder-title {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .placeholder-title h3 {
            margin: 0;
            font-size: 16px;
            line-height: 1.2;
        }
        .placeholder-title p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }
        .status-tag {
            padding: 7px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: var(--primary);
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }
        .placeholder-body {
            color: #334155;
            font-size: 13px;
            line-height: 1.6;
        }
        .placeholder-card.is-link {
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .placeholder-card.is-link:active {
            transform: translateY(1px) scale(0.99);
        }
        .placeholder-card.is-link:hover {
            border-color: #bfd4fb;
        }
        .session-list {
            display: grid;
            gap: 12px;
        }
        .session-row {
            border-radius: var(--radius-lg);
            padding: 15px 16px;
            display: grid;
            gap: 10px;
        }
        .session-row-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .session-row h3 {
            margin: 0;
            font-size: 16px;
            line-height: 1.25;
        }
        .session-row p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
        }
        .session-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .session-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 10px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            color: #334155;
            font-size: 11px;
            font-weight: 800;
        }
        .session-status {
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
            text-transform: capitalize;
        }
        .session-status.status-draft {
            background: #eef2ff;
            color: var(--primary);
        }
        .session-status.status-open {
            background: #dcfce7;
            color: #166534;
        }
        .session-status.status-closed {
            background: #fff7ed;
            color: #c2410c;
        }
        .session-status.status-archived {
            background: #f3f4f6;
            color: #475569;
        }
        .info-card {
            border-radius: var(--radius-lg);
            padding: 16px;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }
        .info-card h3 {
            margin: 0 0 8px;
            font-size: 16px;
        }
        .info-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.65;
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
        <a class="back-link" href="rep_dashboard.php">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back to Dashboard
        </a>
        <div class="hero-top">
            <div class="hero-copy">
                <span class="hero-eyebrow">Group Draw Module</span>
                <h1>Group Draw</h1>
                <p>Prepare and manage independent participant group assignments inside your workspace. Session setup, list upload, draw controls, and export steps will connect here next.</p>
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
            <span class="hero-chip"><?php echo htmlspecialchars($workspace_badge); ?></span>
            <span class="hero-chip"><?php echo htmlspecialchars($workspace_actor_role . ': ' . $workspace_actor_name); ?></span>
            <?php if ($current_admin_class !== ''): ?>
                <span class="hero-chip"><?php echo htmlspecialchars('Class: ' . $current_admin_class); ?></span>
            <?php endif; ?>
            <?php if ($semester_name !== ''): ?>
                <span class="hero-chip"><?php echo htmlspecialchars($semester_name); ?></span>
            <?php endif; ?>
            <span class="hero-chip"><?php echo htmlspecialchars('Create a new draft session'); ?></span>
        </div>
    </section>

    <?php if ($dashboard_notice !== ''): ?>
        <section class="notice-card"><?php echo htmlspecialchars($dashboard_notice); ?></section>
    <?php endif; ?>

    <div>
        <h2 class="section-title">Workspace Snapshot</h2>
        <p class="section-copy">This landing page is already scoped to the current rep workspace and semester. The counters below are ready to reflect your Group Draw records as the module grows.</p>
    </div>

    <section class="stats-grid">
        <div class="card">
            <div class="stat-label">
                <span class="pill blue">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 19V8m6 11V5m6 14v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 20h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </span>
                Sessions
            </div>
            <div class="stat-value"><?php echo intval($stats['sessions']); ?></div>
            <div class="stat-note">Draft, open, closed, and archived draw sessions for this workspace.</div>
        </div>
        <div class="card">
            <div class="stat-label">
                <span class="pill teal">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M7 4v6m10-6v6M5 11h14a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                Groups
            </div>
            <div class="stat-value"><?php echo intval($stats['groups']); ?></div>
            <div class="stat-note">Configured group slots and capacity targets waiting for session setup.</div>
        </div>
        <div class="card">
            <div class="stat-label">
                <span class="pill amber">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </span>
                Participants
            </div>
            <div class="stat-value"><?php echo intval($stats['participants']); ?></div>
            <div class="stat-note">Independent group-draw participants, separate from the class list module.</div>
        </div>
        <div class="card">
            <div class="stat-label">
                <span class="pill purple">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5 9 16.5 19 6.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 20h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </span>
                Draw Records
            </div>
            <div class="stat-value"><?php echo intval($stats['draws']); ?></div>
            <div class="stat-note">Completed draw assignments that will later feed export and session history.</div>
        </div>
    </section>

    <div>
        <h2 class="section-title">Recent Sessions</h2>
        <p class="section-copy">Your latest Group Draw session records for this workspace and active semester.</p>
    </div>

    <section class="session-list">
        <?php if ($recent_sessions): ?>
            <?php foreach ($recent_sessions as $session): ?>
                <?php
                $session_status = trim(strval($session['status'] ?? 'draft'));
                $session_status_class = 'session-status status-' . preg_replace('/[^a-z]/', '', strtolower($session_status));
                $session_created = trim(strval($session['created_at'] ?? ''));
                $session_created_label = $session_created !== '' ? date('M j, Y g:i A', strtotime($session_created)) : 'Just now';
                ?>
                <article class="session-row">
                    <div class="session-row-head">
                        <div>
                            <h3><?php echo htmlspecialchars(strval($session['session_title'] ?? 'Untitled Session')); ?></h3>
                            <?php if (trim(strval($session['session_description'] ?? '')) !== ''): ?>
                                <p><?php echo htmlspecialchars(strval($session['session_description'] ?? '')); ?></p>
                            <?php else: ?>
                                <p>No description added yet.</p>
                            <?php endif; ?>
                        </div>
                        <span class="<?php echo htmlspecialchars($session_status_class); ?>"><?php echo htmlspecialchars($session_status); ?></span>
                    </div>
                    <div class="session-meta">
                        <span class="session-chip">Created <?php echo htmlspecialchars($session_created_label); ?></span>
                        <span class="session-chip">Session #<?php echo intval($session['session_id'] ?? 0); ?></span>
                        <span class="session-chip">Preferred group size <?php echo intval($session['preferred_group_size'] ?? 4); ?></span>
                        <span class="session-chip"><?php echo intval($session['participant_count'] ?? 0); ?> participants</span>
                        <span class="session-chip"><?php echo intval($session['group_count'] ?? 0); ?> groups</span>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <article class="session-row">
                <div class="session-row-head">
                    <div>
                        <h3>No sessions yet</h3>
                        <p>Create your first draft session to begin configuring Group Draw inside this workspace.</p>
                    </div>
                </div>
            </article>
        <?php endif; ?>
    </section>

    <div>
        <h2 class="section-title">Coming Next</h2>
        <p class="section-copy">This phase keeps the module safe and independent. The next screens will plug into this dashboard without disturbing the class list, request, or payment workflows.</p>
    </div>

    <section class="placeholder-grid">
        <a href="group_draw_manage_session.php" class="placeholder-card is-link">
            <div class="placeholder-head">
                <div class="placeholder-title">
                    <span class="pill blue">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 4h9l3 3v13H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 11h6M9 15h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </span>
                    <div>
                        <h3>Session Setup</h3>
                        <p>Create your first draft Group Draw session now.</p>
                    </div>
                </div>
                <span class="status-tag">Manage</span>
            </div>
            <div class="placeholder-body">Session setup defines the title, status, semester scope, and workspace ownership for every Group Draw run without mixing with the main class list.</div>
        </a>

        <a href="group_draw_upload.php" class="placeholder-card is-link">
            <div class="placeholder-head">
                <div class="placeholder-title">
                    <span class="pill teal">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 18a4 4 0 0 1 8 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10-1a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm-1 10a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </span>
                    <div>
                        <h3>Upload Group List</h3>
                        <p>Independent participant and group upload flow lives here next.</p>
                    </div>
                </div>
                <span class="status-tag">Import</span>
            </div>
            <div class="placeholder-body">The upload tools will use the new Group Draw tables only, so participant pools stay isolated from your class list and book request records.</div>
        </a>

        <a href="group_draw_monitor.php" class="placeholder-card is-link">
            <div class="placeholder-head">
                <div class="placeholder-title">
                    <span class="pill amber">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M8 9h8M8 13h5M16.5 14.5l1.8 1.8 3.2-3.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div>
                        <h3>Live Progress</h3>
                        <p>Track draw participation before and during an open Group Draw session.</p>
                    </div>
                </div>
                <span class="status-tag">Monitor</span>
            </div>
            <div class="placeholder-body">Monitor participants who have drawn, see who is still waiting, and refresh safely from one clean session-focused dashboard.</div>
        </a>

        <a href="group_draw_results.php" class="placeholder-card is-link">
            <div class="placeholder-head">
                <div class="placeholder-title">
                    <span class="pill blue">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 7h8M8 11h8M8 15h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.8"/></svg>
                    </span>
                    <div>
                        <h3>Final Groups</h3>
                        <p>Review completed results and export your final session list to CSV.</p>
                    </div>
                </div>
                <span class="status-tag">Export</span>
            </div>
            <div class="placeholder-body">See the final grouped assignments, confirm who is still outstanding, and download a clean CSV without touching your class list or request records.</div>
        </a>
    </section>

    <section class="info-card">
        <h3>Independent by Design</h3>
        <p>Group Draw is now connected to the rep workspace as its own module entry point. It already respects the existing rep, assistant-rep, and workspace access pattern, while keeping its data model separate from class uploads, requests, payments, and reports.</p>
    </section>
</div>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
</body>
</html>
