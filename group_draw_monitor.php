<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
require_once 'app_helpers.php';

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

$required_tables = [
    'group_draw_sessions',
    'group_draw_groups',
    'group_draw_participants',
];
foreach ($required_tables as $table_name) {
    $table_check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'");
    if (!($table_check && $table_check->num_rows === 1)) {
        header('Location: group_draw_dashboard.php?msg=schema_missing');
        exit;
    }
}

$rep_bottom_nav_active = '';
$rep_bottom_nav_profile_href = $actor_is_assistant ? 'assistant_profile.php' : 'my_profile.php';

$sessions = [];
$sessions_stmt = $conn->prepare("SELECT
        s.session_id,
        s.session_title,
        s.status,
        s.created_at,
        s.preferred_group_size,
        (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id) AS participant_count,
        (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id AND p.draw_status = 'drawn') AS drawn_count,
        (SELECT COUNT(*) FROM group_draw_groups g WHERE g.session_id = s.session_id) AS group_count,
        COALESCE((SELECT SUM(g.capacity) FROM group_draw_groups g WHERE g.session_id = s.session_id), 0) AS total_capacity
    FROM group_draw_sessions s
    WHERE s.admin_id = ? AND s.semester_id = ?
    ORDER BY FIELD(s.status, 'open', 'draft', 'closed', 'archived'), s.created_at DESC");
if ($sessions_stmt) {
    $sessions_stmt->bind_param('ii', $current_admin_id, $semester_id);
    $sessions_stmt->execute();
    $sessions_res = $sessions_stmt->get_result();
    while ($sessions_res && ($row = $sessions_res->fetch_assoc())) {
        $row['participant_count'] = intval($row['participant_count'] ?? 0);
        $row['drawn_count'] = intval($row['drawn_count'] ?? 0);
        $row['group_count'] = intval($row['group_count'] ?? 0);
        $row['total_capacity'] = intval($row['total_capacity'] ?? 0);
        $sessions[] = $row;
    }
    $sessions_stmt->close();
}

$selected_session_id = intval($_GET['session_id'] ?? 0);
$selected_session = null;
foreach ($sessions as $session_row) {
    if (intval($session_row['session_id'] ?? 0) === $selected_session_id) {
        $selected_session = $session_row;
        break;
    }
}
if (!$selected_session && !empty($sessions)) {
    $selected_session = $sessions[0];
    $selected_session_id = intval($selected_session['session_id'] ?? 0);
}

$drawn_participants = [];
$remaining_participants = [];
$progress_percentage = 0;
$remaining_count = 0;
$refresh_token = '';

if ($selected_session) {
    $participant_count = intval($selected_session['participant_count'] ?? 0);
    $drawn_count = intval($selected_session['drawn_count'] ?? 0);
    $remaining_count = max(0, $participant_count - $drawn_count);
    $progress_percentage = $participant_count > 0
        ? intval(round(($drawn_count / $participant_count) * 100))
        : 0;

    $drawn_stmt = $conn->prepare("SELECT
            p.participant_name,
            p.index_number,
            p.assigned_at,
            p.draw_status,
            COALESCE(g.group_name, 'Pending Group') AS assigned_group
        FROM group_draw_participants p
        LEFT JOIN group_draw_groups g ON g.group_id = p.assigned_group_id
        WHERE p.session_id = ? AND p.draw_status = 'drawn'
        ORDER BY p.assigned_at DESC, p.participant_name ASC");
    if ($drawn_stmt) {
        $drawn_stmt->bind_param('i', $selected_session_id);
        $drawn_stmt->execute();
        $drawn_res = $drawn_stmt->get_result();
        while ($drawn_res && ($row = $drawn_res->fetch_assoc())) {
            $drawn_participants[] = $row;
        }
        $drawn_stmt->close();
    }

    $remaining_stmt = $conn->prepare("SELECT
            participant_name,
            index_number,
            draw_status
        FROM group_draw_participants
        WHERE session_id = ? AND draw_status <> 'drawn'
        ORDER BY participant_name ASC");
    if ($remaining_stmt) {
        $remaining_stmt->bind_param('i', $selected_session_id);
        $remaining_stmt->execute();
        $remaining_res = $remaining_stmt->get_result();
        while ($remaining_res && ($row = $remaining_res->fetch_assoc())) {
            $remaining_participants[] = $row;
        }
        $remaining_stmt->close();
    }

    $refresh_state = [
        'session_id' => $selected_session_id,
        'status' => strval($selected_session['status'] ?? ''),
        'participant_count' => $participant_count,
        'drawn_count' => $drawn_count,
        'remaining_count' => $remaining_count,
        'group_count' => intval($selected_session['group_count'] ?? 0),
        'total_capacity' => intval($selected_session['total_capacity'] ?? 0),
        'drawn_tail' => array_slice(array_map(static function (array $row): string {
            return strval(($row['index_number'] ?? '') . '|' . ($row['assigned_group'] ?? '') . '|' . ($row['assigned_at'] ?? ''));
        }, $drawn_participants), 0, 8),
        'remaining_tail' => array_slice(array_map(static function (array $row): string {
            return strval(($row['index_number'] ?? '') . '|' . ($row['draw_status'] ?? ''));
        }, $remaining_participants), 0, 8),
    ];
    $refresh_token = function_exists('book_system_build_refresh_token')
        ? book_system_build_refresh_token($refresh_state)
        : sha1(json_encode($refresh_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

if ($refresh_token !== '' && function_exists('book_system_maybe_output_refresh_status')) {
    book_system_maybe_output_refresh_status($refresh_token);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Draw Monitor</title>
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
        .hero, .card, .session-picker, .list-card, .empty-state {
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
        .session-picker {
            border-radius: var(--radius-lg);
            padding: 16px;
            display: grid;
            gap: 12px;
        }
        .session-picker-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .session-picker h2, .list-card h2 {
            margin: 0;
            font-size: 18px;
        }
        .refresh-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--primary);
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 800;
        }
        select {
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #fff;
            padding: 13px 14px;
            font-size: 14px;
            color: var(--text);
            outline: none;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .card {
            border-radius: var(--radius-lg);
            padding: 16px;
            display: grid;
            gap: 6px;
        }
        .stat-label {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .stat-value {
            font-size: 24px;
            line-height: 1.05;
            font-weight: 900;
        }
        .stat-note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .status-draft { background: var(--amber-soft); color: var(--amber); }
        .status-open { background: var(--teal-soft); color: var(--teal); }
        .status-closed,
        .status-archived { background: var(--primary-soft); color: var(--primary); }
        .progress-card {
            border-radius: var(--radius-lg);
            padding: 18px;
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.92);
            box-shadow: var(--shadow);
            display: grid;
            gap: 12px;
        }
        .progress-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .progress-title {
            margin: 0;
            font-size: 18px;
        }
        .progress-bar {
            width: 100%;
            height: 14px;
            border-radius: 999px;
            background: #e7efff;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
        }
        .progress-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }
        .list-card {
            border-radius: var(--radius-lg);
            padding: 16px;
            display: grid;
            gap: 12px;
        }
        .list-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .count-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 10px;
            border-radius: 999px;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800;
            color: var(--muted);
        }
        .participant-list {
            display: grid;
            gap: 10px;
        }
        .participant-row {
            border-radius: 18px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            padding: 14px;
            display: grid;
            gap: 8px;
        }
        .participant-main {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 12px;
        }
        .participant-name {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
        }
        .participant-index {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }
        .participant-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            border: 1px solid var(--line);
            background: #fff;
            color: #334155;
        }
        .empty-state {
            border-radius: var(--radius-lg);
            padding: 24px 18px;
            text-align: center;
            color: var(--muted);
            display: grid;
            gap: 8px;
        }
        @media (min-width: 768px) {
            body { padding-top: 22px; }
            .page { width: min(100%, 720px); }
            .summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <a class="back-link" href="group_draw_dashboard.php">
            <span>&larr;</span>
            <span>Back to Group Draw</span>
        </a>

        <div class="hero-top">
            <div>
                <span class="hero-eyebrow">Live Monitor</span>
                <h1>Track Draw Progress</h1>
                <p>Monitor participation before and during a live session without touching the class list, request flow, or any assignment logic.</p>
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
            <span class="hero-chip"><?php echo htmlspecialchars($workspace_actor_name); ?></span>
            <span class="hero-chip"><?php echo htmlspecialchars($workspace_actor_role); ?></span>
            <?php if ($current_admin_class !== ''): ?>
                <span class="hero-chip"><?php echo htmlspecialchars($current_admin_class); ?></span>
            <?php endif; ?>
            <?php if ($semester_name !== ''): ?>
                <span class="hero-chip"><?php echo htmlspecialchars($semester_name); ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="session-picker">
        <div class="session-picker-head">
            <div>
                <h2>Select Session</h2>
                <p class="section-copy">Choose one of your Group Draw sessions for this semester and refresh anytime to watch live progress.</p>
            </div>
            <a class="refresh-button" href="group_draw_monitor.php<?php echo $selected_session_id > 0 ? '?session_id=' . intval($selected_session_id) : ''; ?>">Refresh</a>
        </div>

        <?php if (!empty($sessions)): ?>
            <form method="get" action="group_draw_monitor.php">
                <select name="session_id" onchange="this.form.submit()">
                    <?php foreach ($sessions as $session_row): ?>
                        <?php $session_row_id = intval($session_row['session_id'] ?? 0); ?>
                        <option value="<?php echo $session_row_id; ?>" <?php echo $session_row_id === $selected_session_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(strval($session_row['session_title'] ?? 'Untitled Session')); ?>
                            (<?php echo strtoupper(htmlspecialchars(strval($session_row['status'] ?? 'draft'))); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php else: ?>
            <div class="empty-state">
                <strong>No sessions yet</strong>
                <span>Create a draft Group Draw session first before monitoring progress.</span>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($selected_session): ?>
        <?php
            $selected_status = trim(strval($selected_session['status'] ?? 'draft'));
            $participant_count = intval($selected_session['participant_count'] ?? 0);
            $drawn_count = intval($selected_session['drawn_count'] ?? 0);
            $group_count = intval($selected_session['group_count'] ?? 0);
            $total_capacity = intval($selected_session['total_capacity'] ?? 0);
        ?>
        <section class="progress-card">
            <div class="progress-head">
                <div>
                    <h2 class="progress-title"><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'Selected Session')); ?></h2>
                    <p class="section-copy">Created <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime(strval($selected_session['created_at'] ?? 'now')))); ?></p>
                </div>
                <span class="status-pill status-<?php echo htmlspecialchars($selected_status); ?>">
                    <?php echo htmlspecialchars(strtoupper($selected_status)); ?>
                </span>
            </div>

            <div class="summary-grid">
                <div class="card">
                    <div class="stat-label">Participants</div>
                    <div class="stat-value"><?php echo $participant_count; ?></div>
                    <div class="stat-note">Total uploaded in this session.</div>
                </div>
                <div class="card">
                    <div class="stat-label">Drawn</div>
                    <div class="stat-value"><?php echo $drawn_count; ?></div>
                    <div class="stat-note">Completed participants so far.</div>
                </div>
                <div class="card">
                    <div class="stat-label">Remaining</div>
                    <div class="stat-value"><?php echo $remaining_count; ?></div>
                    <div class="stat-note">Still waiting to draw.</div>
                </div>
                <div class="card">
                    <div class="stat-label">Capacity</div>
                    <div class="stat-value"><?php echo $total_capacity; ?></div>
                    <div class="stat-note"><?php echo $group_count; ?> groups prepared for this session.</div>
                </div>
            </div>

            <div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo max(0, min(100, $progress_percentage)); ?>%;"></div>
                </div>
                <div class="progress-meta">
                    <span><?php echo $progress_percentage; ?>% complete</span>
                    <span>Preferred group size <?php echo intval($selected_session['preferred_group_size'] ?? 0); ?></span>
                </div>
            </div>
        </section>

        <section class="list-card">
            <div class="list-card-head">
                <h2>Participants Who Have Drawn</h2>
                <span class="count-chip"><?php echo $drawn_count; ?> drawn</span>
            </div>

            <?php if (!empty($drawn_participants)): ?>
                <div class="participant-list">
                    <?php foreach ($drawn_participants as $participant): ?>
                        <article class="participant-row">
                            <div class="participant-main">
                                <div>
                                    <h3 class="participant-name"><?php echo htmlspecialchars(strval($participant['participant_name'] ?? 'Participant')); ?></h3>
                                    <p class="participant-index"><?php echo htmlspecialchars(strval($participant['index_number'] ?? '')); ?></p>
                                </div>
                                <span class="status-pill status-open">Drawn</span>
                            </div>
                            <div class="participant-meta">
                                <span class="meta-pill"><?php echo htmlspecialchars(strval($participant['assigned_group'] ?? 'Pending Group')); ?></span>
                                <span class="meta-pill"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime(strval($participant['assigned_at'] ?? 'now')))); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <strong>No participants have drawn yet</strong>
                    <span>Draw activity will appear here as soon as the student draw phase is added and used.</span>
                </div>
            <?php endif; ?>
        </section>

        <section class="list-card">
            <div class="list-card-head">
                <h2>Participants Yet to Draw</h2>
                <span class="count-chip"><?php echo $remaining_count; ?> remaining</span>
            </div>

            <?php if (!empty($remaining_participants)): ?>
                <div class="participant-list">
                    <?php foreach ($remaining_participants as $participant): ?>
                        <article class="participant-row">
                            <div class="participant-main">
                                <div>
                                    <h3 class="participant-name"><?php echo htmlspecialchars(strval($participant['participant_name'] ?? 'Participant')); ?></h3>
                                    <p class="participant-index"><?php echo htmlspecialchars(strval($participant['index_number'] ?? '')); ?></p>
                                </div>
                                <span class="status-pill status-draft"><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', strval($participant['draw_status'] ?? 'not_drawn')))); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <strong>Everyone has drawn</strong>
                    <span>This session currently has no remaining participants waiting to draw.</span>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/rep_bottom_nav.php';
if ($refresh_token !== '' && function_exists('book_system_render_refresh_polling_script')) {
    book_system_render_refresh_polling_script($refresh_token, [
        'interval_ms' => 15000,
        'min_gap_ms' => 10000,
    ]);
}
?>
</body>
</html>
