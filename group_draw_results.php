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
        (SELECT COUNT(*) FROM group_draw_groups g WHERE g.session_id = s.session_id) AS group_count
    FROM group_draw_sessions s
    WHERE s.admin_id = ? AND s.semester_id = ?
    ORDER BY FIELD(s.status, 'open', 'closed', 'draft', 'archived'), s.created_at DESC");
if ($sessions_stmt) {
    $sessions_stmt->bind_param('ii', $current_admin_id, $semester_id);
    $sessions_stmt->execute();
    $sessions_res = $sessions_stmt->get_result();
    while ($sessions_res && ($row = $sessions_res->fetch_assoc())) {
        $row['participant_count'] = intval($row['participant_count'] ?? 0);
        $row['drawn_count'] = intval($row['drawn_count'] ?? 0);
        $row['group_count'] = intval($row['group_count'] ?? 0);
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

$grouped_results = [];
$remaining_participants = [];
$remaining_count = 0;
$refresh_token = '';

if ($selected_session) {
    $group_stmt = $conn->prepare("SELECT
            g.group_id,
            g.group_name,
            g.capacity,
            g.sort_order
        FROM group_draw_groups g
        WHERE g.session_id = ?
        ORDER BY g.sort_order ASC, g.group_id ASC");
    if ($group_stmt) {
        $group_stmt->bind_param('i', $selected_session_id);
        $group_stmt->execute();
        $group_res = $group_stmt->get_result();
        while ($group_res && ($group_row = $group_res->fetch_assoc())) {
            $grouped_results[intval($group_row['group_id'] ?? 0)] = [
                'group_name' => strval($group_row['group_name'] ?? 'Group'),
                'capacity' => intval($group_row['capacity'] ?? 0),
                'members' => [],
            ];
        }
        $group_stmt->close();
    }

    $drawn_stmt = $conn->prepare("SELECT
            p.participant_name,
            p.index_number,
            p.assigned_at,
            p.assigned_group_id
        FROM group_draw_participants p
        WHERE p.session_id = ? AND p.draw_status = 'drawn' AND p.assigned_group_id IS NOT NULL
        ORDER BY p.assigned_at ASC, p.participant_name ASC");
    if ($drawn_stmt) {
        $drawn_stmt->bind_param('i', $selected_session_id);
        $drawn_stmt->execute();
        $drawn_res = $drawn_stmt->get_result();
        while ($drawn_res && ($participant_row = $drawn_res->fetch_assoc())) {
            $group_id = intval($participant_row['assigned_group_id'] ?? 0);
            if (isset($grouped_results[$group_id])) {
                $grouped_results[$group_id]['members'][] = $participant_row;
            }
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
        while ($remaining_res && ($participant_row = $remaining_res->fetch_assoc())) {
            $remaining_participants[] = $participant_row;
        }
        $remaining_stmt->close();
    }

    $remaining_count = count($remaining_participants);
    $refresh_state = [
        'session_id' => $selected_session_id,
        'status' => strval($selected_session['status'] ?? ''),
        'participant_count' => intval($selected_session['participant_count'] ?? 0),
        'drawn_count' => intval($selected_session['drawn_count'] ?? 0),
        'remaining_count' => $remaining_count,
        'group_count' => intval($selected_session['group_count'] ?? 0),
        'grouped_result_counts' => array_map(static function (array $group): string {
            return strval($group['group_name'] . '|' . count($group['members']) . '|' . intval($group['capacity'] ?? 0));
        }, array_values($grouped_results)),
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
    <title>Final Groups</title>
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
        .hero, .card, .session-picker, .group-card, .list-card, .empty-state {
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
        .section-copy {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }
        .session-picker, .list-card, .group-card {
            border-radius: var(--radius-lg);
            padding: 16px;
            display: grid;
            gap: 12px;
        }
        .session-picker-head, .list-card-head, .group-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .session-picker h2, .list-card h2, .group-card h2 {
            margin: 0;
            font-size: 18px;
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
        .action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .button-secondary, .button-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 15px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 800;
        }
        .button-secondary {
            border: 1px solid var(--line);
            background: #fff;
            color: var(--primary);
        }
        .button-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.22);
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
            .page { width: min(100%, 760px); }
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
                <span class="hero-eyebrow">Final Groups</span>
                <h1>Results & Export</h1>
                <p>Review completed group assignments, see who is still outstanding, and export the final session list to CSV whenever you need to share or archive it.</p>
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
                <p class="section-copy">Choose one of your Group Draw sessions for this semester to review the grouped results and export the final list.</p>
            </div>
            <?php if ($selected_session): ?>
                <a class="button-secondary" href="group_draw_results.php?session_id=<?php echo intval($selected_session_id); ?>">Refresh</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($sessions)): ?>
            <form method="get" action="group_draw_results.php">
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
                <span>Create a Group Draw session first before viewing final results.</span>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($selected_session): ?>
        <?php
            $selected_status = trim(strval($selected_session['status'] ?? 'draft'));
            $participant_count = intval($selected_session['participant_count'] ?? 0);
            $drawn_count = intval($selected_session['drawn_count'] ?? 0);
        ?>
        <section class="list-card">
            <div class="list-card-head">
                <div>
                    <h2><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'Selected Session')); ?></h2>
                    <p class="section-copy">Session created <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime(strval($selected_session['created_at'] ?? 'now')))); ?></p>
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
                    <div class="stat-note">Assignments already completed.</div>
                </div>
                <div class="card">
                    <div class="stat-label">Remaining</div>
                    <div class="stat-value"><?php echo $remaining_count; ?></div>
                    <div class="stat-note">Still yet to draw.</div>
                </div>
                <div class="card">
                    <div class="stat-label">Groups</div>
                    <div class="stat-value"><?php echo intval($selected_session['group_count'] ?? 0); ?></div>
                    <div class="stat-note">Prepared result groups.</div>
                </div>
            </div>

            <div class="action-row">
                <a class="button-primary" href="group_draw_export_csv.php?session_id=<?php echo intval($selected_session_id); ?>">Export CSV</a>
                <a class="button-secondary" href="group_draw_monitor.php?session_id=<?php echo intval($selected_session_id); ?>">View Live Progress</a>
            </div>
        </section>

        <?php if (!empty($grouped_results)): ?>
            <?php foreach ($grouped_results as $group_id => $group_row): ?>
                <section class="group-card">
                    <div class="group-card-head">
                        <div>
                            <h2><?php echo htmlspecialchars(strval($group_row['group_name'] ?? 'Group')); ?></h2>
                            <p class="section-copy"><?php echo count($group_row['members']); ?> member<?php echo count($group_row['members']) === 1 ? '' : 's'; ?> assigned, capacity <?php echo intval($group_row['capacity'] ?? 0); ?></p>
                        </div>
                        <span class="status-pill status-open"><?php echo count($group_row['members']); ?>/<?php echo intval($group_row['capacity'] ?? 0); ?></span>
                    </div>

                    <?php if (!empty($group_row['members'])): ?>
                        <div class="participant-list">
                            <?php foreach ($group_row['members'] as $participant_row): ?>
                                <article class="participant-row">
                                    <div class="participant-main">
                                        <div>
                                            <h3 class="participant-name"><?php echo htmlspecialchars(strval($participant_row['participant_name'] ?? 'Participant')); ?></h3>
                                            <p class="participant-index"><?php echo htmlspecialchars(strval($participant_row['index_number'] ?? '')); ?></p>
                                        </div>
                                        <span class="status-pill status-open">Drawn</span>
                                    </div>
                                    <div class="participant-meta">
                                        <span class="meta-pill"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime(strval($participant_row['assigned_at'] ?? 'now')))); ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <strong>No one has drawn this group yet</strong>
                            <span>Assignments will appear here as students complete their draw.</span>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php else: ?>
            <section class="empty-state">
                <strong>No groups generated yet</strong>
                <span>Generate groups first before reviewing final grouped results.</span>
            </section>
        <?php endif; ?>

        <section class="list-card">
            <div class="list-card-head">
                <h2>Participants Yet to Draw</h2>
                <span class="status-pill status-draft"><?php echo $remaining_count; ?> remaining</span>
            </div>

            <?php if (!empty($remaining_participants)): ?>
                <div class="participant-list">
                    <?php foreach ($remaining_participants as $participant_row): ?>
                        <article class="participant-row">
                            <div class="participant-main">
                                <div>
                                    <h3 class="participant-name"><?php echo htmlspecialchars(strval($participant_row['participant_name'] ?? 'Participant')); ?></h3>
                                    <p class="participant-index"><?php echo htmlspecialchars(strval($participant_row['index_number'] ?? '')); ?></p>
                                </div>
                                <span class="status-pill status-draft"><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', strval($participant_row['draw_status'] ?? 'not_drawn')))); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <strong>Everyone has drawn</strong>
                    <span>There are no remaining participants waiting in this session.</span>
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
