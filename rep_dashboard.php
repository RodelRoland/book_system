<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'trial_started_at', 'DATETIME NULL AFTER approved_at');
        book_system_setup_ensure_column($conn, 'admins', 'trial_expires_at', 'DATETIME NULL AFTER trial_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_active', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER trial_expires_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_started_at', 'DATETIME NULL AFTER subscription_active');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_expires_at', 'DATETIME NULL AFTER subscription_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'index_number', 'VARCHAR(50) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
    }
}

// Redirect to login if not logged in
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

// Handle Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_workspace']) && !empty($access_context['is_workspace_mode'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: rep_dashboard.php?msg=csrf_invalid');
        exit;
    }
    unset($_SESSION['super_admin_rep_context_id']);
    unset($_SESSION['super_admin_data_scope']);
    header("Location: manage_reps.php?msg=workspace_closed");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_admin_workspace']) && !empty($access_context['is_own_rep_mode'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: rep_dashboard.php?msg=csrf_invalid');
        exit;
    }
    $_SESSION['super_admin_data_scope'] = 'own';
    $_SESSION['super_admin_workspace_mode'] = 'admin';
    unset($_SESSION['super_admin_rep_context_id']);
    header("Location: admin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: rep_dashboard.php?msg=csrf_invalid');
        exit;
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

// Get current rep info
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_class = strval($access_context['effective_class_name'] ?? '');
$current_admin_name = strval($access_context['effective_full_name'] ?? $access_context['effective_username'] ?? 'Rep');
$actor_is_assistant = !empty($access_context['is_assistant_mode']);
$workspace_actor_name = trim(strval($actor_is_assistant ? ($access_context['actor_full_name'] ?? $access_context['actor_username'] ?? '') : $current_admin_name));
$viewing_workspace = !empty($access_context['is_workspace_mode']);
$viewing_own_rep_workspace = !empty($access_context['is_own_rep_mode']);
$profile_photo_path = '';
$rep_index_number = '';
$dashboard_msg = trim(strval($_GET['msg'] ?? ''));
$profile_initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $workspace_actor_name !== '' ? $workspace_actor_name : $current_admin_name), 0, 2));
if ($profile_initials === '') {
    $profile_initials = 'RP';
}
$profile_stmt = $conn->prepare("SELECT profile_photo_path, index_number FROM admins WHERE admin_id = ?");
if ($profile_stmt) {
    $profile_stmt->bind_param('i', $current_admin_id);
    $profile_stmt->execute();
    $profile_stmt->bind_result($profile_photo_path_result, $rep_index_number_result);
    if ($profile_stmt->fetch()) {
        $profile_photo_path = trim(strval($profile_photo_path_result ?? ''));
        $rep_index_number = trim(strval($rep_index_number_result ?? ''));
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
$rep_access_status = ($session_role === 'rep' && function_exists('book_system_get_rep_access_status'))
    ? book_system_get_rep_access_status($conn, $current_admin_id)
    : [];
$rep_rollout_notice = ($session_role === 'rep' && function_exists('book_system_get_rep_rollout_notice'))
    ? book_system_get_rep_rollout_notice($conn)
    : ['show' => false];

// Fetch rep's stats
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}

if (($session_role === 'rep' && !$viewing_workspace) || $viewing_own_rep_workspace) {
    $book_count_stmt = $conn->prepare("SELECT COUNT(*) AS total_books FROM books WHERE admin_id = ? AND semester_id = ?");
    if ($book_count_stmt) {
        $book_count_stmt->bind_param('ii', $current_admin_id, $semester_id);
        $book_count_stmt->execute();
        $book_count_row = $book_count_stmt->get_result()->fetch_assoc();
        $book_count_stmt->close();
        if (intval($book_count_row['total_books'] ?? 0) === 0) {
            header('Location: manage_books.php?msg=book_setup_required');
            exit;
        }
    }
}

$total_collected = 0.0;
$paid_to_lecturers = 0.0;
$total_pending = 0;
$net_balance = 0.0;

$active_semester_name = isset($ACTIVE_SEMESTER_NAME) ? strval($ACTIVE_SEMESTER_NAME) : '';
$active_semester_label = isset($ACTIVE_SEMESTER_LABEL) ? strval($ACTIVE_SEMESTER_LABEL) : $active_semester_name;

// Get rep's unique order link
$rep_username = strval($access_context['effective_username'] ?? '');
$order_link = "index.php?rep=" . urlencode($rep_username);
$request_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$request_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$request_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/book_system/rep_dashboard.php')), '/');
$public_order_link = $request_scheme . '://' . $request_host . ($request_dir !== '' ? $request_dir : '') . '/' . $order_link;

$csrf_token = csrf_get_token();
$dashboard_metrics = function_exists('book_system_get_dashboard_metrics')
    ? book_system_get_dashboard_metrics($conn, $semester_id, $current_admin_id)
    : [];
$dashboard_alerts = function_exists('book_system_get_dashboard_alerts')
    ? book_system_get_dashboard_alerts($conn, $semester_id, $current_admin_id)
    : [];
$semester_chart_rows = function_exists('book_system_get_semester_chart_data')
    ? book_system_get_semester_chart_data($conn, 6, $current_admin_id)
    : [];
$recent_activity = function_exists('book_system_fetch_recent_activity')
    ? book_system_fetch_recent_activity($conn, 8, $current_admin_id, false)
    : [];

if (!empty($dashboard_metrics)) {
    $total_collected = floatval($dashboard_metrics['cash_collected'] ?? $dashboard_metrics['paid_revenue'] ?? $total_collected);
    $paid_to_lecturers = floatval($dashboard_metrics['lecturer_paid'] ?? $paid_to_lecturers);
    $net_balance = floatval($dashboard_metrics['available_balance'] ?? ($total_collected - $paid_to_lecturers));
    $total_pending = intval($dashboard_metrics['unpaid_requests'] ?? $total_pending);
} else {
    $net_balance = $total_collected - $paid_to_lecturers;
}

$today_date = date('Y-m-d');
$unseen_request_count = 0;
$today_request_count = 0;
$class_list_count = 0;
$dashboardCountersLoader = static function () use ($conn, $today_date, $semester_id, $current_admin_id): array {
    $counters = [
        'unseen_request_count' => 0,
        'today_request_count' => 0,
        'class_list_count' => 0,
    ];

    $notification_stmt = $conn->prepare("SELECT
            SUM(CASE WHEN rep_viewed_at IS NULL THEN 1 ELSE 0 END) AS unseen_requests,
            SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS today_requests
        FROM requests
        WHERE semester_id = ? AND admin_id = ?");
    if ($notification_stmt) {
        $notification_stmt->bind_param('sii', $today_date, $semester_id, $current_admin_id);
        $notification_stmt->execute();
        $notification_res = $notification_stmt->get_result();
        if ($notification_res && $notification_res->num_rows === 1) {
            $notification_row = $notification_res->fetch_assoc();
            $counters['unseen_request_count'] = intval($notification_row['unseen_requests'] ?? 0);
            $counters['today_request_count'] = intval($notification_row['today_requests'] ?? 0);
        }
        $notification_stmt->close();
    }

    $class_list_stmt = $conn->prepare("SELECT COUNT(*) AS total_students FROM class_students WHERE admin_id = ? AND semester_id = ?");
    if ($class_list_stmt) {
        $class_list_stmt->bind_param('ii', $current_admin_id, $semester_id);
        $class_list_stmt->execute();
        $class_list_row = $class_list_stmt->get_result()->fetch_assoc();
        $counters['class_list_count'] = intval($class_list_row['total_students'] ?? 0);
        $class_list_stmt->close();
    }

    return $counters;
};

$dashboardCounters = function_exists('cache_get')
    ? cache_get('rep_dashboard_counters_v1_' . $current_admin_id . '_' . $semester_id . '_' . md5($today_date), 20, $dashboardCountersLoader)
    : $dashboardCountersLoader();

if (is_array($dashboardCounters)) {
    $unseen_request_count = intval($dashboardCounters['unseen_request_count'] ?? 0);
    $today_request_count = intval($dashboardCounters['today_request_count'] ?? 0);
    $class_list_count = intval($dashboardCounters['class_list_count'] ?? 0);
}

$chart_max_value = 1;
foreach ($semester_chart_rows as $chart_row) {
    $chart_max_value = max(
        $chart_max_value,
        floatval($chart_row['revenue'] ?? 0),
        floatval($chart_row['unpaid_balance'] ?? 0),
        intval($chart_row['collected_items'] ?? 0),
        intval($chart_row['pending_items'] ?? 0)
    );
}

$rep_meta_summary = trim($current_admin_class ?: 'Class Representative');
if ($active_semester_label !== '') {
    $rep_meta_summary .= ' • ' . $active_semester_label;
}

$chart_current = $semester_chart_rows[0] ?? [];
$chart_previous = $semester_chart_rows[1] ?? [];

$dashboard_change_formatter = static function (float $current, float $previous) : array {
    if ($previous <= 0) {
        return ['show' => false, 'text' => '', 'class' => 'neutral'];
    }

    $delta = (($current - $previous) / max(abs($previous), 0.01)) * 100;
    $class = $delta >= 0 ? 'up' : 'down';
    $sign = $delta >= 0 ? '↑ ' : '↓ ';

    return [
        'show' => true,
        'text' => $sign . number_format(abs($delta), 0) . '% vs last week',
        'class' => $class,
    ];
};

$cash_change = $dashboard_change_formatter(
    floatval($total_collected),
    floatval($chart_previous['revenue'] ?? 0)
);
$balance_change = $dashboard_change_formatter(
    floatval($net_balance),
    floatval(($chart_previous['revenue'] ?? 0) - ($chart_previous['unpaid_balance'] ?? 0))
);
$unpaid_change = $dashboard_change_formatter(
    floatval($total_pending),
    floatval($chart_previous['pending_items'] ?? 0)
);
$notification_has_updates = $today_request_count > 0 || $unseen_request_count > 0;
$reports_href = 'activity_log.php';
$announcements_href = 'common_request_portal.php';
$offers_href = 'common_request_portal.php?view=ads&source=rep#offersPanel';
$share_request_href = '#shareRequestLinkPanel';
$rep_bottom_nav_active = 'dashboard';
$rep_bottom_nav_profile_href = 'my_profile.php';
$utility_links = [
    ['href' => 'manage_books.php', 'label' => 'Manage Books'],
    ['href' => 'rep_export_data.php', 'label' => 'Download Data'],
    ['href' => 'rep_activity_log.php', 'label' => 'Team Activity'],
    ['href' => 'my_profile.php', 'label' => 'Profile'],
];
if (!$actor_is_assistant) {
    $utility_links[] = ['href' => 'manage_assistants.php', 'label' => 'Assistant Access'];
}
if (!$viewing_workspace && !$viewing_own_rep_workspace && !$actor_is_assistant) {
    $utility_links[] = ['href' => 'generate_access_code.php', 'label' => 'Workspace Access'];
}

$dashboard_refresh_state = [
    'admin_id' => $current_admin_id,
    'semester_id' => $semester_id,
    'cash_collected' => round($total_collected, 2),
    'paid_to_lecturers' => round($paid_to_lecturers, 2),
    'net_balance' => round($net_balance, 2),
    'pending_requests' => $total_pending,
    'unseen_request_count' => $unseen_request_count,
    'today_request_count' => $today_request_count,
    'class_list_count' => $class_list_count,
    'dashboard_msg' => $dashboard_msg,
    'alerts' => array_map(static function (array $alert): array {
        return [
            'type' => strval($alert['type'] ?? ''),
            'count' => intval($alert['count'] ?? 0),
            'href' => strval($alert['href'] ?? ''),
        ];
    }, is_array($dashboard_alerts) ? $dashboard_alerts : []),
];
$dashboard_refresh_token = sha1(json_encode($dashboard_refresh_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if (strval($_GET['refresh'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode([
        'success' => true,
        'state_token' => $dashboard_refresh_token,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rep Dashboard - <?php echo htmlspecialchars($current_admin_class); ?></title>
    <style>
        :root {
            --bg: #f6f8fc;
            --surface: #ffffff;
            --surface-soft: #f9fbff;
            --line: #e7edf6;
            --text: #1f2937;
            --muted: #6b7280;
            --primary: #2563eb;
            --green: #16a34a;
            --red: #ef4444;
            --purple: #7c3aed;
            --orange: #f97316;
            --shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top, rgba(37, 99, 235, 0.08), transparent 24%),
                linear-gradient(180deg, #ffffff 0%, var(--bg) 40%, #f3f6fb 100%);
            color: var(--text);
            min-height: 100vh;
            padding: 14px 12px 116px;
        }
        a { color: inherit; text-decoration: none; }
        button { font: inherit; }
        .dashboard-container {
            width: min(100%, 430px);
            margin: 0 auto;
        }
        .top-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        .header-copy {
            flex: 1;
            min-width: 0;
        }
        .header-copy h1 {
            font-size: 15px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }
        .header-copy p {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.4;
        }
        .notification-link {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.9);
            border: 1px solid var(--line);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        }
        .notification-link svg { color: #111827; }
        .notification-dot {
            position: absolute;
            top: 8px;
            right: 9px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
            box-shadow: 0 0 0 2px #fff;
        }
        .state-stack,
        .content-stack {
            display: grid;
            gap: 14px;
        }
        .banner-card,
        .profile-card,
        .quick-actions-card,
        .more-tools-card {
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.85);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }
        .banner-card {
            padding: 15px 16px;
            font-size: 13px;
            line-height: 1.65;
        }
        .trial-banner,
        .rollout-banner {
            background: linear-gradient(135deg, #fff8e1 0%, #fff4cc 100%);
            color: #8a5200;
            border-color: rgba(245, 158, 11, 0.22);
        }
        .portal-warning-banner {
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            color: #9a3412;
            border-color: rgba(249, 115, 22, 0.22);
        }
        .success-banner {
            background: linear-gradient(135deg, #ecfdf3 0%, #dcfce7 100%);
            color: #166534;
            border-color: rgba(34, 197, 94, 0.22);
        }
        .trial-banner strong,
        .rollout-banner strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }
        .profile-card {
            background: linear-gradient(135deg, #f9fbff 0%, #edf4ff 100%);
            border-color: #dbe7ff;
            padding: 14px;
        }
        .profile-main {
            display: grid;
            grid-template-columns: 92px 1fr;
            gap: 14px;
            align-items: center;
        }
        .profile-avatar {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(255,255,255,0.95);
            background: #dbeafe;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.16);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: 800;
            color: var(--primary);
            background-size: cover;
            background-position: center;
        }
        .profile-avatar.has-photo { color: transparent; }
        .profile-copy h2 {
            font-size: 15px;
            line-height: 1.3;
            font-weight: 800;
            color: #111827;
        }
        .profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.12);
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
        }
        .profile-lines {
            margin-top: 10px;
            display: grid;
            gap: 7px;
        }
        .profile-line {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            color: #374151;
            font-size: 12px;
            line-height: 1.45;
        }
        .profile-line svg {
            color: var(--primary);
            flex-shrink: 0;
            margin-top: 1px;
        }
        .workspace-actions {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .workspace-button {
            border: 1px solid #d6e3ff;
            background: rgba(255,255,255,0.88);
            color: #1d4ed8;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .section-title {
            font-size: 15px;
            font-weight: 800;
            color: #111827;
            margin: 6px 4px 2px;
        }
        .quick-actions-card,
        .more-tools-card {
            padding: 14px;
        }
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            min-height: 148px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
            text-align: center;
        }
        .quick-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .quick-icon svg { width: 24px; height: 24px; }
        .quick-icon.blue { background: #eef4ff; color: var(--primary); }
        .quick-icon.green { background: #ecfdf3; color: var(--green); }
        .quick-icon.purple { background: #f5f3ff; color: var(--purple); }
        .quick-icon.orange { background: #fff7ed; color: var(--orange); }
        .quick-icon.amber { background: #fff8e6; color: #d97706; }
        .quick-icon.rose { background: #fff1f2; color: #fb7185; }
        .quick-icon.teal { background: #ecfeff; color: #0f766e; }
        .quick-copy h3 {
            font-size: 13px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 6px;
        }
        .quick-copy p {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.45;
        }
        .quick-arrow {
            color: #9ca3af;
            flex-shrink: 0;
            align-self: center;
        }
        .share-link-card {
            padding: 16px;
            display: none;
            gap: 12px;
        }
        .share-link-card.is-open {
            display: grid;
        }
        .share-link-copy {
            display: grid;
            gap: 4px;
        }
        .share-link-copy h3 {
            font-size: 14px;
            font-weight: 800;
            color: #111827;
        }
        .share-link-copy p {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.45;
        }
        .share-link-shell {
            border: 1px solid #dbe7ff;
            background: #f8fbff;
            border-radius: 16px;
            padding: 12px;
            font-size: 12px;
            color: #1d4ed8;
            word-break: break-all;
        }
        .share-link-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .share-link-button {
            border: 1px solid #dbe7ff;
            background: #ffffff;
            color: #1d4ed8;
            padding: 11px 14px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
        }
        .share-link-button.primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-color: transparent;
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.18);
        }
        .more-tools-card h3 {
            font-size: 14px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 10px;
        }
        .tools-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .tool-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            background: #f3f7ff;
            border: 1px solid #dbe7ff;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
        }
        .bottom-nav-wrap {
            position: fixed;
            left: 50%;
            bottom: 10px;
            transform: translateX(-50%);
            width: min(calc(100vw - 18px), 430px);
            z-index: 40;
        }
        .bottom-nav {
            background: rgba(255,255,255,0.97);
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: 24px;
            box-shadow: 0 20px 36px rgba(15, 23, 42, 0.12);
            padding: 10px 10px calc(10px + env(safe-area-inset-bottom, 0px));
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            align-items: end;
            gap: 6px;
        }
        .bottom-nav-item {
            display: grid;
            justify-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
            padding-top: 4px;
        }
        .bottom-nav-item svg {
            width: 22px;
            height: 22px;
        }
        .bottom-nav-item.active {
            color: var(--primary);
        }
        .center-nav {
            transform: translateY(-18px);
        }
        .center-nav .center-button {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.28);
            margin-bottom: 4px;
        }
        .center-nav .center-button svg {
            width: 22px;
            height: 22px;
        }
        .center-nav span:last-child {
            color: var(--primary);
        }
        @media (min-width: 760px) {
            body {
                padding: 22px 18px 128px;
            }
            .dashboard-container {
                width: min(100%, 780px);
            }
            .top-header {
                margin-bottom: 18px;
            }
            .profile-card,
            .quick-actions-card,
            .more-tools-card {
                padding: 18px;
            }
            .quick-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .bottom-nav-wrap {
                width: min(calc(100vw - 24px), 520px);
            }
        }
        @media (max-width: 360px) {
            .profile-main {
                grid-template-columns: 1fr;
                text-align: center;
            }
            .profile-avatar {
                margin: 0 auto;
            }
            .quick-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <header class="top-header">
        <div class="header-copy">
            <h1><?php echo $actor_is_assistant ? 'Assistant Workspace' : 'Rep Dashboard'; ?></h1>
            <p><?php echo $actor_is_assistant ? 'Handle class activity on behalf of ' . htmlspecialchars($current_admin_name) : 'Overview of your class activity'; ?></p>
        </div>
        <a href="view_request.php?notification_view=1&request_day=<?php echo urlencode($today_date); ?>" class="notification-link" title="Open request notifications">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M15 17H9a2 2 0 0 1-2-2v-4.5a5 5 0 1 1 10 0V15a2 2 0 0 1-2 2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                <path d="M10 19a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            <?php if ($notification_has_updates): ?><span class="notification-dot"></span><?php endif; ?>
        </a>
    </header>

    <div class="state-stack">
        <?php if ($dashboard_msg === 'workspace_reset_success'): ?>
        <div class="banner-card success-banner">
            <strong>Workspace reset successfully</strong>
            <span>You can now upload a new class list and start fresh for the active semester.</span>
        </div>
        <?php endif; ?>
        <?php if (!empty($rep_rollout_notice['show'])): ?>
        <div class="banner-card rollout-banner">
            <strong>Free access is ending soon</strong>
            <span><?php echo htmlspecialchars(strval($rep_rollout_notice['message'] ?? '')); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($rep_access_status['is_trial_expiring_soon'])): ?>
        <div class="banner-card trial-banner">
            <strong>Free trial ending soon</strong>
            <span>
                <?php echo htmlspecialchars(strval($rep_access_status['reminder_message'] ?? 'Your free trial will expire soon.')); ?>
                Please make arrangements to subscribe before access stops at 12:00 AM on
                <?php echo htmlspecialchars(date('M d, Y', strtotime(strval($rep_access_status['trial_expires_at'] ?? 'now')))); ?>.
            </span>
        </div>
        <?php endif; ?>

        <?php if ($class_list_count <= 0): ?>
        <div class="banner-card portal-warning-banner">
            <strong>Upload your class list for the active semester</strong>
            <span>Your class list has not been uploaded for the active semester. Your class members cannot find you on the Common Request Portal until you upload it.</span>
        </div>
        <?php endif; ?>

        <section class="profile-card">
            <div class="profile-main">
                <div
                    class="profile-avatar<?php echo $profile_photo_path !== '' ? ' has-photo' : ''; ?>"
                    <?php if ($profile_photo_path !== ''): ?>
                        style="background-image:url('<?php echo htmlspecialchars($profile_photo_path, ENT_QUOTES); ?>');"
                    <?php endif; ?>
                ><?php echo htmlspecialchars($profile_initials); ?></div>
                <div class="profile-copy">
                    <h2>Welcome back, <?php echo htmlspecialchars($actor_is_assistant ? $workspace_actor_name : $current_admin_name); ?>!</h2>
                    <span class="profile-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2 2.7 5.48L21 8.27l-4.5 4.39 1.06 6.19L12 16l-5.56 2.85 1.06-6.19L3 8.27l6.3-.79L12 2Z"/></svg>
                        <?php echo $actor_is_assistant ? 'Assistant Rep' : 'Class Representative'; ?>
                    </span>
                    <div class="profile-lines">
                        <?php if ($actor_is_assistant): ?>
                        <div class="profile-line">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12h16M12 4v16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <span><strong>Working Under:</strong> <?php echo htmlspecialchars($current_admin_name); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="profile-line">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 19a6 6 0 1 1 12 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/></svg>
                            <span><strong>Class:</strong> <?php echo htmlspecialchars($current_admin_class ?: 'Class Representative'); ?></span>
                        </div>
                        <?php if (!$actor_is_assistant && $rep_index_number !== ''): ?>
                        <div class="profile-line">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 9h8M8 13h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <span><strong>Index Number:</strong> <?php echo htmlspecialchars($rep_index_number); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="workspace-actions">
                <?php if ($viewing_workspace): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="leave_workspace" value="1" class="workspace-button">Leave Workspace</button>
                </form>
                <?php elseif ($viewing_own_rep_workspace): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="return_admin_workspace" value="1" class="workspace-button">Return to Admin Workspace</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="logout" value="1" class="workspace-button">Logout</button>
                </form>
                <a href="common_request_portal.php" class="workspace-button">Portal</a>
            </div>
        </section>
    </div>

    <h2 class="section-title">Quick Actions</h2>
    <section class="quick-actions-card">
        <div class="quick-grid">
            <a href="manage_books.php" class="quick-action">
                <div class="quick-icon blue">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7l-5-4H6a2 2 0 0 0-2 2v14Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 10h6M9 14h6M9 18h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>Add Books</h3>
                    <p>Add or manage course materials</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <a href="upload_class.php" class="quick-action">
                <div class="quick-icon purple">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 18a4 4 0 0 1 8 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10-1a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm-1 10a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>My Class</h3>
                    <p>View class members</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <a href="lecturer_payments.php?tab=payment" class="quick-action">
                <div class="quick-icon green">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="7" width="16" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 12h8M9 16h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>Record Payment</h3>
                    <p>Lecturer payments &amp; books</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <a href="rep_activity_log.php" class="quick-action">
                <div class="quick-icon blue">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 7h10M8 12h10M8 17h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="5" cy="7" r="1.5" fill="currentColor"/><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="5" cy="17" r="1.5" fill="currentColor"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>Team Activity</h3>
                    <p>See who did what</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <a href="group_draw_dashboard.php" class="quick-action">
                <div class="quick-icon teal">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M8 9h8M8 13h5M16.5 14.5l1.8 1.8 3.2-3.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>Group Draw</h3>
                    <p>Run group assignments</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <?php if (!$actor_is_assistant): ?>
            <a href="manage_assistants.php" class="quick-action">
                <div class="quick-icon purple">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="3" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="8" r="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M4.5 19a4.5 4.5 0 0 1 9 0M14 18c.3-1.7 1.6-3 3.5-3s3.2 1.3 3.5 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>Assistant Access</h3>
                    <p>Create and manage helpers</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars($announcements_href); ?>" class="quick-action">
                <div class="quick-icon amber">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 13V7a1 1 0 0 1 1-1h2l7-2v14l-7-2H6a1 1 0 0 1-1-1Zm2 3 1.5 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>Announcements</h3>
                    <p>Send updates to class</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <a href="<?php echo htmlspecialchars($offers_href); ?>" class="quick-action">
                <div class="quick-icon rose">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4 4 9l8 5 8-5-8-5Zm0 10v6m-6-3 6 3 6-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>View Ads</h3>
                    <p>See latest ads</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <a href="<?php echo htmlspecialchars($share_request_href); ?>" class="quick-action">
                <div class="quick-icon teal">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 5h3a2 2 0 0 1 2 2v3M10 19H7a2 2 0 0 1-2-2v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m9 12 6-6m0 0v4m0-4h-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="quick-copy">
                    <h3>Share Request Link</h3>
                    <p>Copy your class request portal link</p>
                </div>
                <svg class="quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </div>
    </section>

    <h2 class="section-title" id="shareRequestLinkTitle" style="display:none;">Share Request Link</h2>
    <section class="quick-actions-card share-link-card" id="shareRequestLinkPanel">
        <div class="share-link-copy">
            <h3>Let your class find you faster</h3>
            <p>Share this direct request link with your students so they can open your book request page quickly.</p>
        </div>
        <div class="share-link-shell" id="requestLinkValue"><?php echo htmlspecialchars($public_order_link); ?></div>
        <div class="share-link-actions">
            <button type="button" class="share-link-button primary" id="copyRequestLinkButton">Copy Link</button>
            <a href="<?php echo htmlspecialchars($public_order_link); ?>" target="_blank" rel="noopener" class="share-link-button">Open Link</a>
        </div>
    </section>
</div>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>

<script>
const copyRequestLinkButton = document.getElementById('copyRequestLinkButton');
const requestLinkValue = document.getElementById('requestLinkValue');
const shareRequestLinkPanel = document.getElementById('shareRequestLinkPanel');
const shareRequestLinkTitle = document.getElementById('shareRequestLinkTitle');
const shareRequestLinkTriggers = document.querySelectorAll('a[href="#shareRequestLinkPanel"]');

function openShareRequestPanel() {
    if (!shareRequestLinkPanel || !shareRequestLinkTitle) {
        return;
    }
    shareRequestLinkPanel.classList.add('is-open');
    shareRequestLinkTitle.style.display = '';
    shareRequestLinkTitle.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

shareRequestLinkTriggers.forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        openShareRequestPanel();
    });
});

if (window.location.hash === '#shareRequestLinkPanel') {
    openShareRequestPanel();
}

if (copyRequestLinkButton && requestLinkValue) {
    copyRequestLinkButton.addEventListener('click', async () => {
        const linkValue = requestLinkValue.textContent.trim();
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(linkValue);
            } else {
                const tempInput = document.createElement('textarea');
                tempInput.value = linkValue;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
            }
            copyRequestLinkButton.textContent = 'Copied';
            window.setTimeout(() => {
                copyRequestLinkButton.textContent = 'Copy Link';
            }, 1800);
        } catch (error) {
            copyRequestLinkButton.textContent = 'Copy failed';
            window.setTimeout(() => {
                copyRequestLinkButton.textContent = 'Copy Link';
            }, 1800);
        }
    });
}

(function () {
    const refreshUrl = new URL(window.location.href);
    refreshUrl.searchParams.set('refresh', 'status');
    const currentStateToken = <?php echo json_encode($dashboard_refresh_token); ?>;
    let refreshTimer = null;
    let refreshInFlight = false;
    let lastRefreshAt = 0;

    function checkForDashboardChanges() {
        const now = Date.now();
        if (document.visibilityState !== 'visible') {
            return;
        }
        if (refreshInFlight || (now - lastRefreshAt) < 10000) {
            return;
        }

        refreshInFlight = true;
        lastRefreshAt = now;
        refreshUrl.searchParams.set('_rt', String(now));

        fetch(refreshUrl.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            cache: 'no-store'
        })
        .then((response) => response.text())
        .then((rawText) => {
            const payload = JSON.parse(String(rawText || '').replace(/^\uFEFF/, ''));
            if (payload && payload.success && payload.state_token && payload.state_token !== currentStateToken) {
                window.location.reload();
            }
        })
        .catch(() => {})
        .finally(() => {
            refreshInFlight = false;
        });
    }

    refreshTimer = window.setInterval(checkForDashboardChanges, 15000);
    window.addEventListener('focus', checkForDashboardChanges);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            checkForDashboardChanges();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            checkForDashboardChanges();
        }
    });
})();
</script>

<?php include 'footer.php'; ?>

</body>
</html>


