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
$participants_table_available = false;
$groups_table_available = false;
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_sessions'");
$sessions_table_available = ($table_check && $table_check->num_rows === 1);
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_participants'");
$participants_table_available = ($table_check && $table_check->num_rows === 1);
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_groups'");
$groups_table_available = ($table_check && $table_check->num_rows === 1);
if (!$sessions_table_available || !$participants_table_available || !$groups_table_available) {
    header('Location: group_draw_dashboard.php?msg=schema_missing');
    exit;
}

$draft_sessions = [];
$draft_stmt = $conn->prepare("SELECT
        s.session_id,
        s.session_title,
        s.session_description,
        s.preferred_group_size,
        s.created_at,
        (SELECT COUNT(*) FROM group_draw_participants p WHERE p.session_id = s.session_id) AS participant_count,
        (SELECT COUNT(*) FROM group_draw_groups g WHERE g.session_id = s.session_id) AS group_count
    FROM group_draw_sessions s
    WHERE s.admin_id = ? AND s.semester_id = ? AND s.status = 'draft'
    ORDER BY s.created_at DESC");
if ($draft_stmt) {
    $draft_stmt->bind_param('ii', $current_admin_id, $semester_id);
    $draft_stmt->execute();
    $draft_res = $draft_stmt->get_result();
    while ($draft_res && ($row = $draft_res->fetch_assoc())) {
        $draft_sessions[] = $row;
    }
    $draft_stmt->close();
}

$selected_session_id = intval($_POST['session_id'] ?? ($_GET['session_id'] ?? 0));
$selected_session = null;
foreach ($draft_sessions as $draft_session) {
    if (intval($draft_session['session_id'] ?? 0) === $selected_session_id) {
        $selected_session = $draft_session;
        break;
    }
}
if (!$selected_session && count($draft_sessions) === 1) {
    $selected_session = $draft_sessions[0];
    $selected_session_id = intval($selected_session['session_id'] ?? 0);
}

function book_system_group_draw_capacity_plan(int $participantCount, int $preferredGroupSize): array {
    if ($participantCount <= 0) {
        return ['group_count' => 0, 'capacities' => []];
    }
    $preferredGroupSize = max(2, min(20, $preferredGroupSize));
    $groupCount = (int) ceil($participantCount / $preferredGroupSize);
    $groupCount = max(1, min($groupCount, $participantCount));
    $baseSize = intdiv($participantCount, $groupCount);
    $remainder = $participantCount % $groupCount;
    $capacities = [];
    for ($i = 0; $i < $groupCount; $i++) {
        $capacities[] = $baseSize + ($i < $remainder ? 1 : 0);
    }
    return [
        'group_count' => $groupCount,
        'capacities' => $capacities,
    ];
}

$generation_plan = null;
if ($selected_session) {
    $generation_plan = book_system_group_draw_capacity_plan(
        intval($selected_session['participant_count'] ?? 0),
        intval($selected_session['preferred_group_size'] ?? 4)
    );
}

$errors = [];
$success_message = '';
$csrf_token = csrf_get_token();
$rep_bottom_nav_active = '';
$rep_bottom_nav_profile_href = $actor_is_assistant ? 'assistant_profile.php' : 'my_profile.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_generate'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    if ($semester_id <= 0) {
        $errors[] = 'No active semester is available for this workspace.';
    }

    if (!$selected_session) {
        $errors[] = 'Please select one of your draft sessions.';
    }

    if ($selected_session) {
        $participant_count = intval($selected_session['participant_count'] ?? 0);
        $group_count = intval($selected_session['group_count'] ?? 0);
        if ($participant_count <= 0) {
            $errors[] = 'This session has no participants yet. Import participants first.';
        }
        if ($group_count > 0) {
            $errors[] = 'Groups have already been generated for this session. Regeneration is blocked until a reset flow is added.';
        }
    }

    if (!$errors && $selected_session && $generation_plan) {
        $capacities = $generation_plan['capacities'];
        if (!$capacities) {
            $errors[] = 'No group capacities could be generated for this session.';
        } else {
            $conn->begin_transaction();
            $insert_failed = false;
            $insert_stmt = $conn->prepare("INSERT INTO group_draw_groups
                (session_id, admin_id, semester_id, group_name, capacity, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)");
            if ($insert_stmt) {
                foreach ($capacities as $index => $capacity) {
                    $group_name = 'Group ' . ($index + 1);
                    $sort_order = $index + 1;
                    $insert_stmt->bind_param(
                        'iiisii',
                        $selected_session_id,
                        $current_admin_id,
                        $semester_id,
                        $group_name,
                        $capacity,
                        $sort_order
                    );
                    if (!$insert_stmt->execute()) {
                        $insert_failed = true;
                        break;
                    }
                }
                $insert_stmt->close();
            } else {
                $insert_failed = true;
            }

            if ($insert_failed) {
                $conn->rollback();
                $errors[] = 'Could not generate groups right now. Nothing was saved.';
            } else {
                $conn->commit();
                header('Location: group_draw_dashboard.php?msg=groups_generated');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Groups</title>
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
            --danger: #dc2626;
            --danger-soft: #fee2e2;
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
        button, input, select { font: inherit; }
        .page {
            width: min(100%, 430px);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }
        .hero, .card, .tile {
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
            gap: 14px;
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
        .field-label {
            display: grid;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #22304a;
        }
        .field-select {
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            padding: 14px 15px;
            outline: none;
        }
        .message-box {
            display: grid;
            gap: 8px;
            padding: 14px;
            border-radius: var(--radius-md);
            font-size: 13px;
            line-height: 1.55;
        }
        .message-box.error {
            border: 1px solid rgba(220, 38, 38, 0.16);
            background: var(--danger-soft);
            color: #991b1b;
        }
        .message-box.warning {
            border: 1px solid rgba(180, 83, 9, 0.16);
            background: var(--warning-soft);
            color: #9a3412;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .tile {
            border-radius: 16px;
            padding: 14px;
        }
        .tile strong {
            display: block;
            font-size: 22px;
            line-height: 1;
            margin-bottom: 6px;
            color: #0f172a;
        }
        .tile span {
            font-size: 12px;
            color: var(--muted);
        }
        .capacity-list {
            display: grid;
            gap: 10px;
        }
        .capacity-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 14px;
            border-radius: 16px;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            font-size: 13px;
        }
        .capacity-row strong {
            font-size: 14px;
        }
        .helper-note {
            padding: 13px 14px;
            border-radius: 16px;
            background: var(--surface-soft);
            border: 1px dashed #cbdcf7;
            color: #334155;
            font-size: 12px;
            line-height: 1.55;
        }
        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .button,
        .button-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 50px;
            border-radius: 16px;
            border: 1px solid transparent;
            font-weight: 800;
            text-decoration: none;
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
                <span class="hero-eyebrow">Prepare Card Pool</span>
                <h1>Generate Groups</h1>
                <p>Create the balanced group slots for a draft session after participants have been imported. This step only prepares the group capacity pool and does not assign anyone yet.</p>
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

    <?php if (count($draft_sessions) === 0): ?>
        <section class="empty-card">
            No draft sessions are ready for group generation yet. Create a session and import participants first.
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Select Draft Session</h2>
            <p class="card-copy">Choose one of your draft sessions that already has participants. Group generation is blocked if groups already exist for that session.</p>

            <?php if ($errors): ?>
                <div class="message-box error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="field-grid">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <label class="field-label">
                    Draft session
                    <select name="session_id" class="field-select" required onchange="this.form.submit()">
                        <option value="">Select a draft session</option>
                        <?php foreach ($draft_sessions as $draft_session): ?>
                            <?php $draft_session_id = intval($draft_session['session_id'] ?? 0); ?>
                            <option value="<?php echo $draft_session_id; ?>" <?php echo $draft_session_id === $selected_session_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(strval($draft_session['session_title'] ?? 'Untitled Session')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <?php if ($selected_session && $generation_plan): ?>
                    <div class="summary-grid">
                        <div class="tile">
                            <strong><?php echo intval($selected_session['participant_count'] ?? 0); ?></strong>
                            <span>Participants</span>
                        </div>
                        <div class="tile">
                            <strong><?php echo intval($selected_session['preferred_group_size'] ?? 4); ?></strong>
                            <span>Preferred group size</span>
                        </div>
                        <div class="tile">
                            <strong><?php echo intval($generation_plan['group_count'] ?? 0); ?></strong>
                            <span>Proposed groups</span>
                        </div>
                        <div class="tile">
                            <strong><?php echo intval($selected_session['group_count'] ?? 0); ?></strong>
                            <span>Existing groups</span>
                        </div>
                    </div>

                    <div class="helper-note">
                        Session: <strong><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? '')); ?></strong>
                        <?php if (trim(strval($selected_session['session_description'] ?? '')) !== ''): ?>
                            <br><?php echo htmlspecialchars(strval($selected_session['session_description'] ?? '')); ?>
                        <?php endif; ?>
                    </div>

                    <div class="capacity-list">
                        <?php foreach (($generation_plan['capacities'] ?? []) as $index => $capacity): ?>
                            <div class="capacity-row">
                                <strong><?php echo htmlspecialchars('Group ' . ($index + 1)); ?></strong>
                                <span><?php echo htmlspecialchars($capacity . ' participants'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (intval($selected_session['group_count'] ?? 0) > 0): ?>
                        <div class="message-box warning">
                            <div>Groups already exist for this session. Regeneration is blocked until a safe reset option is added.</div>
                        </div>
                    <?php elseif (intval($selected_session['participant_count'] ?? 0) <= 0): ?>
                        <div class="message-box warning">
                            <div>This session has no participants yet. Import participants before generating groups.</div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="confirm_generate" value="1">
                        <div class="helper-note">
                            This will create <strong><?php echo intval($generation_plan['group_count'] ?? 0); ?></strong> balanced groups named <strong>Group 1</strong>, <strong>Group 2</strong>, and so on. The session will remain in <strong>draft</strong>.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="actions">
                    <a href="group_draw_dashboard.php" class="button-secondary">Cancel</a>
                    <button
                        type="submit"
                        class="button"
                        <?php echo (!$selected_session || intval($selected_session['participant_count'] ?? 0) <= 0 || intval($selected_session['group_count'] ?? 0) > 0) ? 'disabled style="opacity:.6;cursor:not-allowed;"' : ''; ?>
                    >
                        Generate Groups
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
</body>
</html>
