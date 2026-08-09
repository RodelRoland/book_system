<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
require_once 'app_helpers.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_run_setup_tasks')) {
        book_system_run_setup_tasks($conn);
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$csrf_token = csrf_get_token();

function book_system_group_draw_fetch_public_session(mysqli $conn, int $sessionId): ?array
{
    if ($sessionId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT
            session_id,
            admin_id,
            semester_id,
            session_title,
            session_description,
            preferred_group_size,
            status,
            created_at
        FROM group_draw_sessions
        WHERE session_id = ?
        LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function book_system_group_draw_fetch_public_participant(mysqli $conn, int $sessionId, string $normalizedIndex): ?array
{
    if ($sessionId <= 0 || $normalizedIndex === '') {
        return null;
    }

    $stmt = $conn->prepare("SELECT
            p.participant_id,
            p.participant_name,
            p.index_number,
            p.normalized_index_number,
            p.draw_status,
            p.assigned_group_id,
            p.assigned_at,
            g.group_name AS assigned_group
        FROM group_draw_participants p
        LEFT JOIN group_draw_groups g ON g.group_id = p.assigned_group_id
        WHERE p.session_id = ? AND p.normalized_index_number = ?
        LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('is', $sessionId, $normalizedIndex);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

$required_tables = [
    'group_draw_sessions',
    'group_draw_groups',
    'group_draw_participants',
    'group_draw_draws',
];
foreach ($required_tables as $table_name) {
    $table_check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'");
    if (!($table_check && $table_check->num_rows === 1)) {
        http_response_code(503);
        die('Group Draw is not available yet. Please contact your class representative.');
    }
}

$open_sessions = [];
$open_sessions_stmt = $conn->prepare("SELECT session_id, session_title
    FROM group_draw_sessions
    WHERE status = 'open'
    ORDER BY created_at DESC");
if ($open_sessions_stmt) {
    $open_sessions_stmt->execute();
    $open_sessions_res = $open_sessions_stmt->get_result();
    while ($open_sessions_res && ($row = $open_sessions_res->fetch_assoc())) {
        $open_sessions[] = $row;
    }
    $open_sessions_stmt->close();
}

$selected_session_id = intval($_POST['session_id'] ?? ($_GET['session_id'] ?? 0));
$selected_session = book_system_group_draw_fetch_public_session($conn, $selected_session_id);
$entered_index_number = trim(strval($_POST['index_number'] ?? ''));
$normalized_index_number = $entered_index_number !== '' && function_exists('book_system_normalize_index_number')
    ? book_system_normalize_index_number($entered_index_number)
    : strtoupper(preg_replace('/[^A-Z0-9]+/', '', trim($entered_index_number)));

$errors = [];
$info_message = '';
$eligible_participant = null;
$result_participant = null;
$already_participated = false;
$selected_card_number = intval($_POST['selected_card'] ?? 0);
$action = trim(strval($_POST['student_action'] ?? 'lookup'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    }

    if (!$selected_session) {
        $errors[] = 'Please choose a valid Group Draw session.';
    }

    if ($normalized_index_number === '') {
        $errors[] = 'Please enter your index number before continuing.';
    }

    if (!$errors && $selected_session) {
        $participant = book_system_group_draw_fetch_public_participant($conn, $selected_session_id, $normalized_index_number);
        if (!$participant) {
            $errors[] = 'We could not find that index number in this session. Please check the number and contact your class representative if you need help.';
        } elseif (strval($participant['draw_status'] ?? '') === 'drawn' && trim(strval($participant['assigned_group'] ?? '')) !== '') {
            $result_participant = $participant;
            $already_participated = true;
            $info_message = 'You have already participated. Your assigned group is ' . strtoupper(trim(strval($participant['assigned_group'] ?? ''))) . '.';
        } elseif (strval($selected_session['status'] ?? '') !== 'open') {
            $errors[] = 'This draw has been closed. Please contact your class representative.';
        } elseif ($action === 'draw') {
            if ($selected_card_number < 1 || $selected_card_number > 6) {
                $errors[] = 'Please choose one card to continue.';
            }

            if (!$errors) {
                mysqli_report(MYSQLI_REPORT_OFF);
                $conn->begin_transaction();
                try {
                    $session_lock_stmt = $conn->prepare("SELECT
                            session_id,
                            admin_id,
                            semester_id,
                            session_title,
                            status
                        FROM group_draw_sessions
                        WHERE session_id = ?
                        LIMIT 1
                        FOR UPDATE");
                    $locked_session = null;
                    if ($session_lock_stmt) {
                        $session_lock_stmt->bind_param('i', $selected_session_id);
                        $session_lock_stmt->execute();
                        $session_lock_res = $session_lock_stmt->get_result();
                        $locked_session = ($session_lock_res && $session_lock_res->num_rows === 1) ? $session_lock_res->fetch_assoc() : null;
                        $session_lock_stmt->close();
                    }

                    if (!$locked_session) {
                        throw new RuntimeException('This Group Draw session is no longer available.');
                    }

                    $locked_status = trim(strval($locked_session['status'] ?? 'draft'));

                    $participant_lock_stmt = $conn->prepare("SELECT
                            p.participant_id,
                            p.participant_name,
                            p.index_number,
                            p.normalized_index_number,
                            p.draw_status,
                            p.assigned_group_id,
                            p.assigned_at,
                            g.group_name AS assigned_group
                        FROM group_draw_participants p
                        LEFT JOIN group_draw_groups g ON g.group_id = p.assigned_group_id
                        WHERE p.session_id = ? AND p.normalized_index_number = ?
                        LIMIT 1
                        FOR UPDATE");
                    $locked_participant = null;
                    if ($participant_lock_stmt) {
                        $participant_lock_stmt->bind_param('is', $selected_session_id, $normalized_index_number);
                        $participant_lock_stmt->execute();
                        $participant_lock_res = $participant_lock_stmt->get_result();
                        $locked_participant = ($participant_lock_res && $participant_lock_res->num_rows === 1) ? $participant_lock_res->fetch_assoc() : null;
                        $participant_lock_stmt->close();
                    }

                    if (!$locked_participant) {
                        throw new RuntimeException('We could not find that index number in this session.');
                    }

                    if (strval($locked_participant['draw_status'] ?? '') === 'drawn' && trim(strval($locked_participant['assigned_group'] ?? '')) !== '') {
                        $conn->commit();
                        $result_participant = $locked_participant;
                        $already_participated = true;
                        $info_message = 'You have already participated. Your assigned group is ' . strtoupper(trim(strval($locked_participant['assigned_group'] ?? ''))) . '.';
                    } else {
                        if ($locked_status !== 'open') {
                            throw new RuntimeException('This draw has been closed. Please contact your class representative.');
                        }

                        $groups = [];
                        $groups_stmt = $conn->prepare("SELECT
                                group_id,
                                group_name,
                                capacity,
                                sort_order
                            FROM group_draw_groups
                            WHERE session_id = ? AND is_active = 1
                            ORDER BY sort_order ASC, group_id ASC");
                        if ($groups_stmt) {
                            $groups_stmt->bind_param('i', $selected_session_id);
                            $groups_stmt->execute();
                            $groups_res = $groups_stmt->get_result();
                            while ($groups_res && ($row = $groups_res->fetch_assoc())) {
                                $groups[] = $row;
                            }
                            $groups_stmt->close();
                        }

                        if (empty($groups)) {
                            throw new RuntimeException('This draw is not ready yet. Please contact your class representative.');
                        }

                        $assigned_counts = [];
                        $counts_stmt = $conn->prepare("SELECT assigned_group_id, COUNT(*) AS assigned_count
                            FROM group_draw_participants
                            WHERE session_id = ? AND assigned_group_id IS NOT NULL
                            GROUP BY assigned_group_id");
                        if ($counts_stmt) {
                            $counts_stmt->bind_param('i', $selected_session_id);
                            $counts_stmt->execute();
                            $counts_res = $counts_stmt->get_result();
                            while ($counts_res && ($row = $counts_res->fetch_assoc())) {
                                $assigned_counts[intval($row['assigned_group_id'] ?? 0)] = intval($row['assigned_count'] ?? 0);
                            }
                            $counts_stmt->close();
                        }

                        $available_slots = [];
                        foreach ($groups as $group_row) {
                            $group_id = intval($group_row['group_id'] ?? 0);
                            $capacity = intval($group_row['capacity'] ?? 0);
                            $assigned_count = intval($assigned_counts[$group_id] ?? 0);
                            $remaining_slots = max(0, $capacity - $assigned_count);
                            for ($i = 0; $i < $remaining_slots; $i++) {
                                $available_slots[] = $group_row;
                            }
                        }

                        if (empty($available_slots)) {
                            throw new RuntimeException('All group slots are already full. Please contact your class representative.');
                        }

                        $selected_group = $available_slots[random_int(0, count($available_slots) - 1)];
                        $selected_group_id = intval($selected_group['group_id'] ?? 0);
                        $selected_group_name = trim(strval($selected_group['group_name'] ?? 'Group'));
                        $participant_id = intval($locked_participant['participant_id'] ?? 0);
                        $session_owner_admin_id = intval($locked_session['admin_id'] ?? 0);
                        $session_semester_id = intval($locked_session['semester_id'] ?? 0);

                        $update_stmt = $conn->prepare("UPDATE group_draw_participants
                            SET draw_status = 'drawn', assigned_group_id = ?, assigned_at = NOW()
                            WHERE participant_id = ? AND draw_status <> 'drawn'
                            LIMIT 1");
                        if (!$update_stmt) {
                            throw new RuntimeException('Could not complete your draw right now. Please try again.');
                        }
                        $update_stmt->bind_param('ii', $selected_group_id, $participant_id);
                        $update_stmt->execute();
                        $updated_rows = $update_stmt->affected_rows;
                        $update_stmt->close();

                        if ($updated_rows !== 1) {
                            $existing_after_update = book_system_group_draw_fetch_public_participant($conn, $selected_session_id, $normalized_index_number);
                            if ($existing_after_update && strval($existing_after_update['draw_status'] ?? '') === 'drawn' && trim(strval($existing_after_update['assigned_group'] ?? '')) !== '') {
                                $conn->commit();
                                $result_participant = $existing_after_update;
                                $already_participated = true;
                                $info_message = 'You have already participated. Your assigned group is ' . strtoupper(trim(strval($existing_after_update['assigned_group'] ?? ''))) . '.';
                            } else {
                                throw new RuntimeException('Your draw could not be completed safely. Please try again.');
                            }
                        } else {
                            $draw_source = 'student';
                            $draw_notes = 'visual_card:' . $selected_card_number;
                            $insert_draw_stmt = $conn->prepare("INSERT INTO group_draw_draws
                                (session_id, participant_id, group_id, admin_id, semester_id, drawn_by_admin_id, draw_source, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            if (!$insert_draw_stmt) {
                                throw new RuntimeException('Could not record your draw result. Please try again.');
                            }
                            $insert_draw_stmt->bind_param(
                                'iiiiiiss',
                                $selected_session_id,
                                $participant_id,
                                $selected_group_id,
                                $session_owner_admin_id,
                                $session_semester_id,
                                $session_owner_admin_id,
                                $draw_source,
                                $draw_notes
                            );
                            if (!$insert_draw_stmt->execute()) {
                                $insert_draw_stmt->close();
                                throw new RuntimeException('Could not save your draw result. Please try again.');
                            }
                            $insert_draw_stmt->close();

                            $conn->commit();

                            $result_participant = [
                                'participant_id' => $participant_id,
                                'participant_name' => $locked_participant['participant_name'] ?? '',
                                'index_number' => $locked_participant['index_number'] ?? '',
                                'normalized_index_number' => $locked_participant['normalized_index_number'] ?? '',
                                'draw_status' => 'drawn',
                                'assigned_group_id' => $selected_group_id,
                                'assigned_at' => date('Y-m-d H:i:s'),
                                'assigned_group' => $selected_group_name,
                            ];
                            $info_message = 'Congratulations! You have drawn ' . strtoupper($selected_group_name) . '.';
                        }
                    }
                } catch (Throwable $throwable) {
                    $conn->rollback();
                    $errors[] = $throwable->getMessage();
                }
            }
        } else {
            $eligible_participant = $participant;
        }
    }
}

$show_confirmation_screen = (!$errors && !$result_participant && $eligible_participant && $selected_session && strval($selected_session['status'] ?? '') === 'open' && $action !== 'confirm');
$show_card_grid = (!$errors && !$result_participant && $eligible_participant && $selected_session && strval($selected_session['status'] ?? '') === 'open' && $action === 'confirm');
$result_group_display = strtoupper(trim(strval($result_participant['assigned_group'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Draw</title>
    <style>
        :root {
            --bg: #120722;
            --bg-deep: #090312;
            --bg-soft: #1d0f34;
            --panel: rgba(22, 10, 42, 0.92);
            --panel-strong: rgba(17, 7, 33, 0.98);
            --line: rgba(255, 215, 92, 0.14);
            --text: #fff8ec;
            --muted: #d7c9eb;
            --gold: #ffcf5a;
            --gold-strong: #f5b21a;
            --gold-soft: rgba(255, 207, 90, 0.16);
            --success: #4ade80;
            --warning: #f59e0b;
            --danger: #fb7185;
            --shadow: 0 24px 80px rgba(3, 1, 9, 0.5);
            --radius-xl: 34px;
            --radius-lg: 26px;
            --radius-md: 18px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top, rgba(255, 207, 90, 0.12), transparent 20%),
                radial-gradient(circle at 20% 20%, rgba(168, 85, 247, 0.16), transparent 30%),
                radial-gradient(circle at bottom right, rgba(59, 130, 246, 0.14), transparent 24%),
                linear-gradient(180deg, #05010b 0%, #130726 36%, #120722 100%);
            color: var(--text);
            padding: 18px 14px 32px;
        }
        .page {
            width: min(100%, 430px);
            margin: 0 auto;
            display: grid;
            gap: 18px;
        }
        .phone-shell {
            position: relative;
            overflow: hidden;
            border-radius: 38px;
            background: linear-gradient(180deg, rgba(10, 4, 22, 0.98), rgba(21, 8, 37, 0.98));
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            padding: 18px 16px 20px;
            display: grid;
            gap: 16px;
        }
        .phone-shell::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at top right, rgba(255, 207, 90, 0.12), transparent 24%),
                radial-gradient(circle at 10% 30%, rgba(124, 58, 237, 0.2), transparent 28%);
            pointer-events: none;
        }
        .phone-shell > * {
            position: relative;
            z-index: 1;
        }
        .hero,
        .panel,
        .result-card,
        .empty-card {
            background: linear-gradient(180deg, rgba(34, 13, 62, 0.94), rgba(18, 7, 34, 0.96));
            border: 1px solid var(--line);
        }
        .hero {
            border-radius: var(--radius-xl);
            padding: 22px 18px 20px;
            display: grid;
            gap: 12px;
        }
        .brand-mark {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 207, 90, 0.1);
            color: var(--gold);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .brand-mark strong {
            color: #fff7d1;
        }
        .hero h1 {
            margin: 0;
            font-size: 34px;
            line-height: 1.03;
        }
        .hero p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
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
            gap: 6px;
            padding: 9px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 207, 90, 0.12);
            background: rgba(255,255,255,0.04);
            color: #f8e7b8;
            font-size: 12px;
            font-weight: 700;
        }
        .panel,
        .result-card,
        .empty-card {
            border-radius: var(--radius-lg);
            padding: 18px;
            display: grid;
            gap: 14px;
        }
        .panel h2,
        .result-card h2,
        .empty-card h2 {
            margin: 0;
            font-size: 22px;
            line-height: 1.2;
        }
        .panel-copy {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }
        .message {
            border-radius: 18px;
            padding: 14px 15px;
            font-size: 13px;
            line-height: 1.55;
        }
        .message.error {
            background: rgba(251, 113, 133, 0.12);
            border: 1px solid rgba(251, 113, 133, 0.24);
            color: #fecdd3;
        }
        .message.info {
            background: rgba(255, 207, 90, 0.1);
            border: 1px solid rgba(255, 207, 90, 0.24);
            color: #fff2c3;
        }
        label {
            display: grid;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #e2e8f0;
        }
        select,
        input[type="text"] {
            width: 100%;
            border-radius: 16px;
            border: 1px solid rgba(255, 207, 90, 0.16);
            background: rgba(255,255,255,0.04);
            color: #fff;
            padding: 14px 15px;
            font-size: 15px;
            outline: none;
        }
        select option {
            color: #0f172a;
        }
        .button,
        .button-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 16px;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            border: 0;
            cursor: pointer;
        }
        .button {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-strong) 100%);
            color: #2c1400;
            box-shadow: 0 18px 36px rgba(245, 178, 26, 0.28);
        }
        .button-secondary {
            background: rgba(255,255,255,0.06);
            color: #fff3cd;
            border: 1px solid rgba(255, 207, 90, 0.18);
        }
        .button-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 16px;
            border-radius: 16px;
            background: transparent;
            color: var(--muted);
            border: 1px dashed rgba(255, 207, 90, 0.18);
            font-size: 13px;
            font-weight: 800;
        }
        .session-summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .summary-box {
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255, 207, 90, 0.12);
            padding: 14px;
            display: grid;
            gap: 5px;
        }
        .summary-box strong {
            font-size: 21px;
            line-height: 1;
        }
        .summary-box span {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }
        .student-identity {
            display: grid;
            gap: 12px;
        }
        .identity-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            border-radius: 22px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255, 207, 90, 0.12);
        }
        .identity-avatar {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(255, 207, 90, 0.2), rgba(124, 58, 237, 0.24));
            color: #fff8dc;
            font-size: 22px;
            font-weight: 900;
            flex-shrink: 0;
        }
        .identity-card h3 {
            margin: 0;
            font-size: 20px;
            line-height: 1.15;
        }
        .identity-card p {
            margin: 6px 0 0;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
        }
        .stage-section {
            display: none;
        }
        .stage-section.is-active {
            display: grid;
            gap: 14px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .status-open { background: rgba(52, 211, 153, 0.14); color: #9ef1bd; }
        .status-draft { background: rgba(245, 158, 11, 0.14); color: #fcd34d; }
        .status-closed,
        .status-archived { background: rgba(148, 163, 184, 0.14); color: #cbd5e1; }
        .draw-stage-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .draw-stage-head p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }
        .student-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            perspective: 1100px;
        }
        .draw-card {
            position: relative;
            min-height: 142px;
            border: 0;
            padding: 0;
            background: transparent;
            cursor: pointer;
            perspective: 1100px;
        }
        .draw-card-inner {
            position: relative;
            width: 100%;
            height: 100%;
            min-height: 142px;
            transform-style: preserve-3d;
            transition: transform 0.75s ease, opacity 0.55s ease, filter 0.55s ease, box-shadow 0.35s ease;
        }
        .draw-card.is-selected .draw-card-inner {
            transform: rotateY(180deg) translateY(-6px);
            box-shadow: 0 0 0 2px rgba(255, 207, 90, 0.24), 0 0 28px rgba(255, 207, 90, 0.6);
        }
        .draw-card.is-faded .draw-card-inner {
            opacity: 0.16;
            filter: blur(1px);
            transform: scale(0.95);
        }
        .draw-card.is-selected .draw-card-back {
            box-shadow: 0 0 0 2px rgba(255, 207, 90, 0.24), 0 0 30px rgba(255, 207, 90, 0.68), 0 24px 40px rgba(0, 0, 0, 0.35);
        }
        .draw-card-face {
            position: absolute;
            inset: 0;
            border-radius: 24px;
            backface-visibility: hidden;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.12);
        }
        .draw-card-back {
            background:
                linear-gradient(135deg, rgba(34, 16, 64, 0.98), rgba(16, 10, 36, 0.98)),
                radial-gradient(circle at top right, rgba(255, 207, 90, 0.2), transparent 26%);
            display: grid;
            place-items: center;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 18px 32px rgba(2, 6, 23, 0.35);
        }
        .draw-card-back::before,
        .draw-card-back::after {
            content: "";
            position: absolute;
            inset: 10px;
            border-radius: 18px;
            border: 1px solid rgba(255, 207, 90, 0.12);
        }
        .draw-card-back::after {
            inset: 20px;
            border-style: dashed;
        }
        .draw-card-mark {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            border-radius: 18px;
            background: rgba(255, 207, 90, 0.16);
            color: #fff8e1;
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .draw-card-front {
            transform: rotateY(180deg);
            background: linear-gradient(180deg, #fff6d9 0%, #ffd971 100%);
            display: grid;
            align-content: center;
            justify-items: center;
            text-align: center;
            padding: 20px;
            color: #261100;
            box-shadow: 0 18px 32px rgba(2, 6, 23, 0.18);
        }
        .draw-card-front strong {
            font-size: 30px;
            line-height: 1;
            margin-bottom: 8px;
        }
        .draw-card-front span {
            font-size: 12px;
            color: rgba(38, 17, 0, 0.8);
            font-weight: 700;
        }
        .draw-help {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
            text-align: center;
        }
        .drawing-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 22px;
            background: rgba(5, 2, 12, 0.76);
            backdrop-filter: blur(10px);
            z-index: 50;
        }
        .drawing-overlay.is-visible {
            display: flex;
        }
        .drawing-card {
            width: min(100%, 280px);
            padding: 22px;
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(33, 15, 60, 0.98), rgba(17, 8, 33, 0.98));
            border: 1px solid rgba(255, 207, 90, 0.2);
            box-shadow: 0 26px 80px rgba(1, 0, 5, 0.45);
            text-align: center;
            display: grid;
            gap: 14px;
            color: #fff8dc;
        }
        .drawing-spinner {
            width: 72px;
            height: 72px;
            margin: 0 auto;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(255, 207, 90, 0.22), rgba(255, 207, 90, 0.08));
            display: grid;
            place-items: center;
            position: relative;
            overflow: hidden;
        }
        .drawing-spinner::before {
            content: "";
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 3px solid rgba(255, 207, 90, 0.18);
            border-top-color: var(--gold);
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .result-group {
            font-size: 36px;
            line-height: 1;
            font-weight: 900;
            color: #fff2b0;
        }
        .result-caption {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .meta-item {
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255, 207, 90, 0.12);
            padding: 12px;
            display: grid;
            gap: 4px;
        }
        .meta-item strong {
            font-size: 13px;
            color: #e2e8f0;
        }
        .meta-item span {
            font-size: 12px;
            color: var(--muted);
        }
        .empty-card {
            text-align: center;
        }
        .footer-note {
            text-align: center;
            color: rgba(255, 243, 205, 0.72);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding-bottom: 4px;
        }
        .confetti-layer {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 45;
        }
        .confetti {
            position: absolute;
            top: -12vh;
            width: 12px;
            height: 18px;
            border-radius: 4px;
            opacity: 0.9;
            animation: confetti-fall linear forwards;
        }
        @keyframes confetti-fall {
            0% {
                transform: translate3d(0, 0, 0) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            100% {
                transform: translate3d(var(--drift, 0px), 115vh, 0) rotate(720deg);
                opacity: 0;
            }
        }
        @media (min-width: 768px) {
            body { padding-top: 28px; }
            .page { width: min(100%, 430px); }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="phone-shell">
        <section class="hero">
            <span class="brand-mark">CB <strong>ClassBookHub</strong> <span style="opacity:.72;">UI v2</span></span>
            <h1>Pick ONE card</h1>
            <p>You can only pick once. Enter your index number, confirm your identity, and complete your draw securely.</p>
            <div class="hero-meta">
                <?php if ($selected_session): ?>
                    <span class="hero-chip"><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'Selected Session')); ?></span>
                    <span class="hero-chip"><?php echo htmlspecialchars(strtoupper(strval($selected_session['status'] ?? 'draft'))); ?></span>
                <?php elseif (!empty($open_sessions)): ?>
                    <span class="hero-chip"><?php echo count($open_sessions); ?> open session<?php echo count($open_sessions) === 1 ? '' : 's'; ?></span>
                <?php else: ?>
                    <span class="hero-chip">Waiting for an open session</span>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($errors): ?>
            <section class="panel">
                <?php foreach ($errors as $error_text): ?>
                    <div class="message error"><?php echo htmlspecialchars($error_text); ?></div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($info_message !== '' && !$result_participant): ?>
            <section class="panel">
                <div class="message info"><?php echo htmlspecialchars($info_message); ?></div>
            </section>
        <?php endif; ?>

        <?php if (!$eligible_participant && !$result_participant): ?>
            <section class="panel">
                <h2>Welcome</h2>
                <p class="panel-copy">Use the session link your class representative shared with you, or choose the open session below and verify your index number to continue.</p>
                <form method="post" action="group_draw_student.php<?php echo $selected_session_id > 0 ? '?session_id=' . intval($selected_session_id) : ''; ?>" style="display:grid; gap:14px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="student_action" value="lookup">
                    <label>
                        Group Draw Session
                        <select name="session_id" required>
                            <option value="">Choose a session</option>
                            <?php foreach ($open_sessions as $session_option): ?>
                                <?php $session_option_id = intval($session_option['session_id'] ?? 0); ?>
                                <option value="<?php echo $session_option_id; ?>" <?php echo $session_option_id === $selected_session_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(strval($session_option['session_title'] ?? 'Open Session')); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($selected_session && strval($selected_session['status'] ?? '') !== 'open'): ?>
                                <option value="<?php echo intval($selected_session['session_id'] ?? 0); ?>" selected>
                                    <?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'Selected Session')); ?> (<?php echo htmlspecialchars(strtoupper(strval($selected_session['status'] ?? 'draft'))); ?>)
                                </option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label>
                        Index Number
                        <input type="text" name="index_number" value="<?php echo htmlspecialchars($entered_index_number); ?>" placeholder="Enter your index number" autocomplete="off" required>
                    </label>
                    <button type="submit" class="button">Verify Index Number</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($selected_session && !$result_participant): ?>
            <section class="panel">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h2><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'Selected Session')); ?></h2>
                        <p class="panel-copy"><?php echo htmlspecialchars(trim(strval($selected_session['session_description'] ?? '')) !== '' ? strval($selected_session['session_description']) : 'This session is ready for a fair, transparent, and secure draw.'); ?></p>
                    </div>
                    <span class="status-pill status-<?php echo htmlspecialchars(trim(strval($selected_session['status'] ?? 'draft'))); ?>">
                        <?php echo htmlspecialchars(strtoupper(trim(strval($selected_session['status'] ?? 'draft')))); ?>
                    </span>
                </div>
                <div class="session-summary">
                    <div class="summary-box">
                        <strong><?php echo intval($selected_session['preferred_group_size'] ?? 0); ?></strong>
                        <span>Preferred group size</span>
                    </div>
                    <div class="summary-box">
                        <strong><?php echo htmlspecialchars(date('M j, Y', strtotime(strval($selected_session['created_at'] ?? 'now')))); ?></strong>
                        <span>Session created</span>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($show_confirmation_screen): ?>
            <section class="panel student-identity" id="confirmation-panel">
                <h2>Student Confirmed</h2>
                <p class="panel-copy">We found your record. Confirm your details below, then continue to the card table.</p>
                <div class="identity-card">
                    <div class="identity-avatar"><?php echo htmlspecialchars(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', strval($eligible_participant['participant_name'] ?? 'ST')) . 'ST', 0, 2))); ?></div>
                    <div>
                        <h3><?php echo htmlspecialchars(strval($eligible_participant['participant_name'] ?? 'Participant')); ?></h3>
                        <p><?php echo htmlspecialchars(strval($eligible_participant['index_number'] ?? '')); ?><br><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'Selected Session')); ?></p>
                    </div>
                </div>
                <div style="display:grid; gap:10px;">
                    <form method="post" action="group_draw_student.php?session_id=<?php echo intval($selected_session_id); ?>" style="display:grid; gap:10px; margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="student_action" value="confirm">
                        <input type="hidden" name="session_id" value="<?php echo intval($selected_session_id); ?>">
                        <input type="hidden" name="index_number" value="<?php echo htmlspecialchars($entered_index_number); ?>">
                        <button type="submit" class="button" id="continue-to-draw">Continue to Draw</button>
                    </form>
                    <button type="button" class="button-ghost" id="change-index-button">Use a Different Index Number</button>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($show_card_grid): ?>
            <section class="panel stage-section is-active" id="card-stage">
                <div class="draw-stage-head">
                    <div>
                        <h2>Pick ONE card</h2>
                        <p>You can only pick once. Tap one card to begin the draw animation.</p>
                    </div>
                    <span class="status-pill status-open">Ready</span>
                </div>
                <form id="draw-form" method="post" action="group_draw_student.php?session_id=<?php echo intval($selected_session_id); ?>" style="display:grid; gap:14px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="student_action" value="draw">
                    <input type="hidden" name="session_id" value="<?php echo intval($selected_session_id); ?>">
                    <input type="hidden" name="index_number" value="<?php echo htmlspecialchars($entered_index_number); ?>">
                    <input type="hidden" name="selected_card" id="selected_card" value="">

                    <div class="student-card-grid" id="student-card-grid">
                        <?php for ($card = 1; $card <= 6; $card++): ?>
                            <button type="button" class="draw-card" data-card="<?php echo $card; ?>" aria-label="Choose card <?php echo $card; ?>">
                                <span class="draw-card-inner">
                                    <span class="draw-card-face draw-card-back">
                                        <span class="draw-card-mark">CBH</span>
                                    </span>
                                    <span class="draw-card-face draw-card-front">
                                        <strong><?php echo $card; ?></strong>
                                        <span>Selected Card</span>
                                    </span>
                                </span>
                            </button>
                        <?php endfor; ?>
                    </div>
                    <p class="draw-help">The selected card will glow, the others will fade away, and your result will be revealed after the secure assignment completes.</p>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($result_participant): ?>
            <section class="result-card">
                <span class="brand-mark"><?php echo $already_participated ? 'Saved Result' : 'Draw Complete'; ?></span>
                <h2><?php echo $already_participated ? 'You have already participated' : 'Congratulations!'; ?></h2>
                <div class="result-group"><?php echo htmlspecialchars($result_group_display !== '' ? $result_group_display : 'GROUP'); ?></div>
                <p class="result-caption">
                    <?php if ($already_participated): ?>
                        You have already participated. Your assigned group is <?php echo htmlspecialchars($result_group_display !== '' ? $result_group_display : 'GROUP'); ?>.
                    <?php else: ?>
                        Congratulations! You have drawn <?php echo htmlspecialchars($result_group_display !== '' ? $result_group_display : 'GROUP'); ?>.
                    <?php endif; ?>
                </p>
                <p class="result-caption"><?php echo $already_participated ? 'This saved result will continue to appear whenever you return to this page.' : 'Your draw has been recorded successfully.'; ?></p>
                <div class="meta-grid">
                    <div class="meta-item">
                        <strong>Participant</strong>
                        <span><?php echo htmlspecialchars(strval($result_participant['participant_name'] ?? '')); ?></span>
                    </div>
                    <div class="meta-item">
                        <strong>Index Number</strong>
                        <span><?php echo htmlspecialchars(strval($result_participant['index_number'] ?? '')); ?></span>
                    </div>
                    <div class="meta-item">
                        <strong>Session</strong>
                        <span><?php echo htmlspecialchars(strval($selected_session['session_title'] ?? 'Selected Session')); ?></span>
                    </div>
                    <div class="meta-item">
                        <strong>Drawn At</strong>
                        <span><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime(strval($result_participant['assigned_at'] ?? 'now')))); ?></span>
                    </div>
                </div>
            </section>
        <?php elseif (empty($open_sessions) && !$selected_session): ?>
            <section class="empty-card">
                <h2>No open draw is available right now</h2>
                <p class="panel-copy">Please wait for your class representative to open the session, then return using the session link they share.</p>
            </section>
        <?php endif; ?>

        <div class="footer-note">Fair • Transparent • Secure</div>
    </div>
</div>

<div class="drawing-overlay" id="drawing-overlay" aria-hidden="true">
    <div class="drawing-card">
        <div class="drawing-spinner"></div>
        <h2>Drawing your group...</h2>
        <p class="panel-copy">Please hold on while the secure assignment engine records your result.</p>
    </div>
</div>
<div class="confetti-layer" id="confetti-layer" aria-hidden="true"></div>

<script>
(function () {
    var confirmationPanel = document.getElementById('confirmation-panel');
    var cardStage = document.getElementById('card-stage');
    var changeIndexButton = document.getElementById('change-index-button');
    var form = document.getElementById('draw-form');
    var hiddenInput = document.getElementById('selected_card');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.draw-card'));
    var drawingOverlay = document.getElementById('drawing-overlay');
    var confettiLayer = document.getElementById('confetti-layer');

    if (changeIndexButton) {
        changeIndexButton.addEventListener('click', function () {
            window.location.href = 'group_draw_student.php<?php echo $selected_session_id > 0 ? '?session_id=' . intval($selected_session_id) : ''; ?>';
        });
    }

    if (form && hiddenInput && cards.length > 0) {
        cards.forEach(function (card) {
            card.addEventListener('click', function (event) {
                event.preventDefault();
                if (form.dataset.submitting === 'yes') {
                    return;
                }

                var selectedCard = String(card.getAttribute('data-card') || '');
                if (!selectedCard) {
                    return;
                }

                form.dataset.submitting = 'yes';
                hiddenInput.value = selectedCard;
                cards.forEach(function (item) {
                    if (item === card) {
                        item.classList.add('is-selected');
                    } else {
                        item.classList.add('is-faded');
                    }
                    item.disabled = true;
                });

                window.setTimeout(function () {
                    if (drawingOverlay) {
                        drawingOverlay.classList.add('is-visible');
                    }
                }, 420);

                window.setTimeout(function () {
                    form.submit();
                }, 1080);
            });
        });
    }

    <?php if ($result_participant && !$already_participated): ?>
    if (confettiLayer) {
        var colors = ['#ffcf5a', '#a855f7', '#60a5fa', '#ffffff', '#34d399'];
        for (var i = 0; i < 32; i++) {
            var piece = document.createElement('span');
            piece.className = 'confetti';
            piece.style.left = (Math.random() * 100) + '%';
            piece.style.background = colors[i % colors.length];
            piece.style.setProperty('--drift', ((Math.random() * 180) - 90) + 'px');
            piece.style.animationDuration = (3.4 + Math.random() * 1.8) + 's';
            piece.style.animationDelay = (Math.random() * 0.35) + 's';
            confettiLayer.appendChild(piece);
        }
        window.setTimeout(function () {
            confettiLayer.innerHTML = '';
        }, 6200);
    }
    <?php endif; ?>
})();
</script>
</body>
</html>
