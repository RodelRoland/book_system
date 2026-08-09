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
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_sessions'");
$sessions_table_available = ($table_check && $table_check->num_rows === 1);
$table_check = $conn->query("SHOW TABLES LIKE 'group_draw_participants'");
$participants_table_available = ($table_check && $table_check->num_rows === 1);
if (!$sessions_table_available || !$participants_table_available) {
    header('Location: group_draw_dashboard.php?msg=schema_missing');
    exit;
}

$draft_sessions = [];
$draft_stmt = $conn->prepare("SELECT session_id, session_title, session_description, preferred_group_size, created_at
    FROM group_draw_sessions
    WHERE admin_id = ? AND semester_id = ? AND status = 'draft'
    ORDER BY created_at DESC");
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

$paste_text = trim(strval($_POST['participants_text'] ?? ''));
$errors = [];
$summary = null;
$csrf_token = csrf_get_token();
$rep_bottom_nav_active = '';
$rep_bottom_nav_profile_href = $actor_is_assistant ? 'assistant_profile.php' : 'my_profile.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    if ($semester_id <= 0) {
        $errors[] = 'No active semester is available for this workspace.';
    }

    if (!$selected_session) {
        $errors[] = 'Please select one of your draft sessions.';
    }

    if ($paste_text === '') {
        $errors[] = 'Paste at least one participant row.';
    }

    if (!$errors && $selected_session) {
        $lines = preg_split('/\r\n|\r|\n/', $paste_text) ?: [];
        $parsed_rows = [];
        $seen_normalized = [];
        $duplicate_rows = [];
        $invalid_rows = [];
        $valid_rows = [];

        foreach ($lines as $index => $line) {
            $line_number = $index + 1;
            $raw_line = trim(strval($line));
            if ($raw_line === '') {
                continue;
            }

            $parts = explode(',', $raw_line, 2);
            if (count($parts) < 2) {
                $invalid_rows[] = "Line {$line_number}: Use 'Index Number, Full Name'.";
                continue;
            }

            $index_number = trim(strval($parts[0]));
            $full_name = trim(strval($parts[1]));
            $normalized_index_number = function_exists('book_system_normalize_index_number')
                ? book_system_normalize_index_number($index_number)
                : strtoupper(preg_replace('/[^A-Z0-9]+/', '', trim($index_number)));

            if ($normalized_index_number === '') {
                $invalid_rows[] = "Line {$line_number}: Index number is missing.";
                continue;
            }
            if ($full_name === '') {
                $invalid_rows[] = "Line {$line_number}: Full name is missing.";
                continue;
            }

            if (isset($seen_normalized[$normalized_index_number])) {
                $duplicate_rows[] = "Line {$line_number}: Duplicate index number {$normalized_index_number} in pasted text.";
                continue;
            }

            $seen_normalized[$normalized_index_number] = true;
            $parsed_rows[] = [
                'line_number' => $line_number,
                'index_number' => $index_number,
                'normalized_index_number' => $normalized_index_number,
                'participant_name' => $full_name,
            ];
        }

        $existing_indexes = [];
        if ($parsed_rows) {
            $existing_stmt = $conn->prepare("SELECT normalized_index_number
                FROM group_draw_participants
                WHERE session_id = ?");
            if ($existing_stmt) {
                $existing_stmt->bind_param('i', $selected_session_id);
                $existing_stmt->execute();
                $existing_res = $existing_stmt->get_result();
                while ($existing_res && ($row = $existing_res->fetch_assoc())) {
                    $existing_indexes[strval($row['normalized_index_number'] ?? '')] = true;
                }
                $existing_stmt->close();
            }
        }

        foreach ($parsed_rows as $parsed_row) {
            if (isset($existing_indexes[$parsed_row['normalized_index_number']])) {
                $duplicate_rows[] = "Line {$parsed_row['line_number']}: Index number {$parsed_row['normalized_index_number']} already exists in this session.";
                continue;
            }
            $valid_rows[] = $parsed_row;
        }

        $imported_count = 0;
        $insert_failed = false;
        if ($valid_rows) {
            $conn->begin_transaction();
            $insert_stmt = $conn->prepare("INSERT INTO group_draw_participants
                (session_id, admin_id, semester_id, participant_name, index_number, normalized_index_number, draw_status, assigned_group_id, assigned_at)
                VALUES (?, ?, ?, ?, ?, ?, 'not_drawn', NULL, NULL)");
            if ($insert_stmt) {
                foreach ($valid_rows as $valid_row) {
                    $participant_name = $valid_row['participant_name'];
                    $index_number = $valid_row['index_number'];
                    $normalized_index_number = $valid_row['normalized_index_number'];
                    $insert_stmt->bind_param(
                        'iiisss',
                        $selected_session_id,
                        $current_admin_id,
                        $semester_id,
                        $participant_name,
                        $index_number,
                        $normalized_index_number
                    );
                    if (!$insert_stmt->execute()) {
                        $insert_failed = true;
                        break;
                    }
                    $imported_count++;
                }
                $insert_stmt->close();
            } else {
                $insert_failed = true;
            }

            if ($insert_failed) {
                $conn->rollback();
                $errors[] = 'Could not finish importing the participant list. Nothing was saved.';
                $imported_count = 0;
            } else {
                $conn->commit();
            }
        }

        if (!$errors) {
            $skipped_count = count($duplicate_rows) + count($invalid_rows);
            $summary = [
                'imported_count' => $imported_count,
                'skipped_count' => $skipped_count,
                'duplicate_rows' => $duplicate_rows,
                'invalid_rows' => $invalid_rows,
            ];
            if ($imported_count > 0) {
                $paste_text = '';
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
    <title>Upload Group List</title>
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
            --success: #166534;
            --success-soft: #dcfce7;
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
        button, input, textarea, select { font: inherit; }
        .page {
            width: min(100%, 430px);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }
        .hero, .card {
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
        .field-select,
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
        .field-select:focus,
        .field-textarea:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
        }
        .field-textarea {
            min-height: 220px;
            resize: vertical;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            line-height: 1.6;
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
        .message-box.success {
            border: 1px solid rgba(22, 101, 52, 0.16);
            background: var(--success-soft);
            color: #166534;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .summary-tile {
            padding: 13px 14px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid var(--line);
        }
        .summary-tile strong {
            display: block;
            font-size: 20px;
            line-height: 1;
            margin-bottom: 6px;
            color: #0f172a;
        }
        .summary-tile span {
            font-size: 12px;
            color: var(--muted);
        }
        .list-box {
            display: grid;
            gap: 8px;
        }
        .list-box h3 {
            margin: 0;
            font-size: 15px;
        }
        .list-box ul {
            margin: 0;
            padding-left: 18px;
            color: #334155;
            font-size: 12px;
            line-height: 1.6;
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
                <span class="hero-eyebrow">Upload Group List</span>
                <h1>Import Participants</h1>
                <p>Paste participants into one of your draft Group Draw sessions using the format <strong>Index Number, Full Name</strong>. This import stays separate from the main class list.</p>
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
            No draft Group Draw sessions are available yet. Create a draft session first, then come back here to import participants.
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Paste Participant List</h2>
            <p class="card-copy">Choose a draft session, then paste one participant per line in the format <strong>Index Number, Full Name</strong>.</p>

            <?php if ($errors): ?>
                <div class="message-box error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($summary): ?>
                <div class="message-box success">
                    <div>Import completed for <strong><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'selected session')); ?></strong>.</div>
                </div>
                <div class="summary-grid">
                    <div class="summary-tile">
                        <strong><?php echo intval($summary['imported_count']); ?></strong>
                        <span>Imported</span>
                    </div>
                    <div class="summary-tile">
                        <strong><?php echo intval($summary['skipped_count']); ?></strong>
                        <span>Skipped</span>
                    </div>
                </div>
                <?php if ($summary['duplicate_rows']): ?>
                    <div class="list-box">
                        <h3>Duplicate Rows</h3>
                        <ul>
                            <?php foreach ($summary['duplicate_rows'] as $row): ?>
                                <li><?php echo htmlspecialchars($row); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($summary['invalid_rows']): ?>
                    <div class="list-box">
                        <h3>Invalid Rows</h3>
                        <ul>
                            <?php foreach ($summary['invalid_rows'] as $row): ?>
                                <li><?php echo htmlspecialchars($row); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" class="field-grid">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <label class="field-label">
                    Draft session
                    <select name="session_id" class="field-select" required>
                        <option value="">Select a draft session</option>
                        <?php foreach ($draft_sessions as $draft_session): ?>
                            <?php $draft_session_id = intval($draft_session['session_id'] ?? 0); ?>
                            <option value="<?php echo $draft_session_id; ?>" <?php echo $draft_session_id === $selected_session_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(strval($draft_session['session_title'] ?? 'Untitled Session')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <?php if ($selected_session): ?>
                    <div class="helper-note">
                        Selected session: <strong><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? '')); ?></strong>
                        <?php if (trim(strval($selected_session['session_description'] ?? '')) !== ''): ?>
                            <br><?php echo htmlspecialchars(strval($selected_session['session_description'] ?? '')); ?>
                        <?php endif; ?>
                        <br>Preferred group size: <strong><?php echo intval($selected_session['preferred_group_size'] ?? 4); ?></strong>
                    </div>
                <?php endif; ?>

                <label class="field-label">
                    Participant list
                    <small>One participant per line. Example:<br><code>5230100552, Roland Kitsi</code></small>
                    <textarea
                        class="field-textarea"
                        name="participants_text"
                        required
                        placeholder="5230100552, Roland Kitsi&#10;5230100553, Akosua Mensah&#10;5230100554, Emmanuel Owusu"
                    ><?php echo htmlspecialchars($paste_text); ?></textarea>
                </label>

                <div class="helper-note">
                    During import, index numbers are normalized by trimming, converting to uppercase, and removing non-alphanumeric spacing characters. Duplicate index numbers inside the pasted text or already inside the selected session will be skipped.
                </div>

                <div class="actions">
                    <a href="group_draw_dashboard.php" class="button-secondary">Cancel</a>
                    <button type="submit" class="button">Import Participants</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
</body>
</html>
