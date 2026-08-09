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

$session_table_available = false;
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_sessions'");
$session_table_available = ($table_check && $table_check->num_rows === 1);
if (!$session_table_available) {
    header('Location: group_draw_dashboard.php?msg=schema_missing');
    exit;
}

$csrf_token = csrf_get_token();
$errors = [];
$session_title = trim(strval($_POST['session_title'] ?? ''));
$session_description = trim(strval($_POST['session_description'] ?? ''));
$preferred_group_size = trim(strval($_POST['preferred_group_size'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    if ($semester_id <= 0) {
        $errors[] = 'No active semester is available for this workspace.';
    }

    if ($session_title === '') {
        $errors[] = 'Session title is required.';
    } elseif (mb_strlen($session_title) > 120) {
        $errors[] = 'Session title must be 120 characters or fewer.';
    }

    if ($session_description !== '' && mb_strlen($session_description) > 255) {
        $errors[] = 'Description must be 255 characters or fewer.';
    }

    if ($preferred_group_size === '') {
        $errors[] = 'Preferred group size is required.';
    } elseif (!ctype_digit($preferred_group_size) || intval($preferred_group_size) < 2 || intval($preferred_group_size) > 20) {
        $errors[] = 'Preferred group size must be a number between 2 and 20.';
    }

    if (!$errors) {
        $status = 'draft';
        $max_draws_per_participant = 1;
        $preferred_group_size_int = intval($preferred_group_size);
        $stmt = $conn->prepare("INSERT INTO group_draw_sessions
            (admin_id, semester_id, session_title, session_description, preferred_group_size, status, max_draws_per_participant)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param(
                'iissisi',
                $current_admin_id,
                $semester_id,
                $session_title,
                $session_description,
                $preferred_group_size_int,
                $status,
                $max_draws_per_participant
            );
            if ($stmt->execute()) {
                $stmt->close();
                header('Location: group_draw_dashboard.php?msg=session_created');
                exit;
            }
            $errors[] = 'Could not create the session right now. Please try again.';
            $stmt->close();
        } else {
            $errors[] = 'Could not prepare the session form. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Group Draw Session</title>
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
        button, input, textarea {
            font: inherit;
        }
        .page {
            width: min(100%, 430px);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }
        .hero,
        .card {
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
        .profile-badge img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
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
        .error-box {
            display: grid;
            gap: 8px;
            padding: 14px;
            border-radius: var(--radius-md);
            border: 1px solid rgba(220, 38, 38, 0.16);
            background: var(--danger-soft);
            color: #991b1b;
            font-size: 13px;
            line-height: 1.55;
        }
        .error-box strong {
            font-size: 13px;
        }
        .field-grid {
            display: grid;
            gap: 14px;
        }
        .field-label {
            display: grid;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #22304a;
        }
        .field-label small {
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
            line-height: 1.45;
        }
        .field-input,
        .field-textarea {
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            padding: 14px 15px;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .field-input:focus,
        .field-textarea:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
        }
        .field-textarea {
            min-height: 112px;
            resize: vertical;
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
                <span class="hero-eyebrow">Session Setup</span>
                <h1>Create New Session</h1>
                <p>Start a new Group Draw session for this workspace. The session will be saved as a draft so you can continue setup safely in later steps.</p>
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
        <h2>Session Details</h2>
        <p class="card-copy">This creates only the session record. Group upload, participant upload, drawing, and export will be connected in later phases.</p>

        <?php if ($errors): ?>
            <div class="error-box">
                <strong>Please fix these issues:</strong>
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="field-grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <label class="field-label">
                Session title
                <input
                    class="field-input"
                    type="text"
                    name="session_title"
                    maxlength="120"
                    required
                    placeholder="Example: Level 300 Group Draw"
                    value="<?php echo htmlspecialchars($session_title); ?>"
                >
            </label>

            <label class="field-label">
                Description
                <small>Optional note about what this session is for.</small>
                <textarea
                    class="field-textarea"
                    name="session_description"
                    maxlength="255"
                    placeholder="Example: Group assignment for Level 300 project teams."
                ><?php echo htmlspecialchars($session_description); ?></textarea>
            </label>

            <label class="field-label">
                Preferred group size
                <small>Example: 3 or 4. This is collected now for planning, and will be used in the next group-setup phase.</small>
                <input
                    class="field-input"
                    type="number"
                    min="2"
                    max="20"
                    step="1"
                    name="preferred_group_size"
                    required
                    placeholder="4"
                    value="<?php echo htmlspecialchars($preferred_group_size); ?>"
                >
            </label>

            <div class="helper-note">
                The session will be saved with your preferred group size, status <strong>draft</strong>, and one draw allowed per participant. No groups, participants, or draw actions are created yet in this phase.
            </div>

            <div class="actions">
                <a href="group_draw_dashboard.php" class="button-secondary">Cancel</a>
                <button type="submit" class="button">Create Session</button>
            </div>
        </form>
    </section>
</div>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
</body>
</html>
