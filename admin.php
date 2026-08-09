<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
        book_system_setup_ensure_column($conn, 'semesters', 'semester_start_date', 'DATE NULL AFTER semester_name');
    }
    if (function_exists('book_system_setup_ensure_admin_role_support')) {
        book_system_setup_ensure_admin_role_support($conn);
    }
}

if (isset($_SESSION['admin_logged_in']) && ($_SESSION['admin_role'] ?? '') === 'super_admin') {
    $_SESSION['super_admin_data_scope'] = 'own';
    $_SESSION['super_admin_workspace_mode'] = 'admin';
    unset($_SESSION['super_admin_rep_context_id']);
}

if (isset($_SESSION['admin_logged_in']) && ($_SESSION['admin_role'] ?? '') === 'temporary_admin') {
    $temporaryAdminStatus = function_exists('book_system_get_temporary_admin_status')
        ? book_system_get_temporary_admin_status($conn, intval($_SESSION['admin_id'] ?? 0))
        : ['can_access' => false, 'permissions' => []];
    $tempPermissions = is_array($temporaryAdminStatus['permissions'] ?? null)
        ? $temporaryAdminStatus['permissions']
        : ($_SESSION['temp_admin_permissions'] ?? []);

    if (!empty($temporaryAdminStatus['can_access'])) {
        $_SESSION['temp_admin_permissions'] = $tempPermissions;
        $_SESSION['temp_admin_expires_at'] = strval($temporaryAdminStatus['expires_at'] ?? '');
        header('Location: rep_dashboard.php');
        exit;
    }

    session_destroy();
    header('Location: login.php?msg=temp_admin_expired');
    exit;
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: admin.php?msg=csrf_invalid");
        exit;
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

if (
    isset($_SESSION['admin_logged_in']) &&
    ($_SESSION['admin_role'] ?? '') === 'super_admin' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['enter_my_rep_workspace'])
) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: admin.php?msg=csrf_invalid");
        exit;
    }
    $_SESSION['super_admin_data_scope'] = 'own';
    $_SESSION['super_admin_workspace_mode'] = 'own_rep';
    unset($_SESSION['super_admin_rep_context_id']);
    header("Location: rep_dashboard.php");
    exit;
}

if (
    isset($_SESSION['admin_logged_in']) &&
    function_exists('book_system_user_can_access_admin_feature') &&
    book_system_user_can_access_admin_feature('semester_management') &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['set_active_semester'])
) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: admin.php?msg=csrf_invalid");
        exit;
    }
    $new_id = intval($_POST['semester_id']);
    if ($new_id > 0) {
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE semester_id = ?");
        $stmt->bind_param("i", $new_id);
        $stmt->execute();
        if (function_exists('book_system_bump_portal_lookup_cache_version')) {
            book_system_bump_portal_lookup_cache_version($conn);
        }
        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'set_active_semester', 'semester', $new_id, []);
        }
    }
    header("Location: admin.php");
    exit;
}

if (
    isset($_SESSION['admin_logged_in']) &&
    function_exists('book_system_user_can_access_admin_feature') &&
    book_system_user_can_access_admin_feature('semester_management') &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_semester'])
) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: admin.php?msg=csrf_invalid");
        exit;
    }
    $name = trim($_POST['semester_name'] ?? '');
    $semester_start_date = trim(strval($_POST['semester_start_date'] ?? ''));
    $date_object = DateTime::createFromFormat('Y-m-d', $semester_start_date);
    if ($name !== '' && $date_object && $date_object->format('Y-m-d') === $semester_start_date) {
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("INSERT INTO semesters (semester_name, semester_start_date, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE semester_start_date = VALUES(semester_start_date), is_active = 1");
        $stmt->bind_param("ss", $name, $semester_start_date);
        $stmt->execute();
        $new_semester_id = intval($conn->insert_id);
        if (function_exists('book_system_bump_portal_lookup_cache_version')) {
            book_system_bump_portal_lookup_cache_version($conn);
        }
        if ($new_semester_id <= 0) {
            $lookup = $conn->prepare("SELECT semester_id FROM semesters WHERE semester_name = ? LIMIT 1");
            if ($lookup) {
                $lookup->bind_param('s', $name);
                $lookup->execute();
                $lookup_result = $lookup->get_result();
                if ($lookup_result && $lookup_result->num_rows === 1) {
                    $new_semester_id = intval($lookup_result->fetch_assoc()['semester_id'] ?? 0);
                }
                $lookup->close();
            }
        }
        $carry_forward_summary = function_exists('book_system_run_balance_carry_forward')
            ? book_system_run_balance_carry_forward($conn, $new_semester_id)
            : ['carried_count' => 0, 'carried_total' => 0.0];
        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'create_semester', 'semester', $new_semester_id, [
                'semester_name' => $name,
                'semester_start_date' => $semester_start_date,
                'carried_balance_count' => intval($carry_forward_summary['carried_count'] ?? 0),
                'carried_balance_total' => floatval($carry_forward_summary['carried_total'] ?? 0),
            ]);
            if (floatval($carry_forward_summary['carried_total'] ?? 0) > 0) {
                book_system_audit_log($conn, 'carry_forward_balance', 'semester', $new_semester_id, [
                    'target_semester_id' => $new_semester_id,
                    'carried_balance_count' => intval($carry_forward_summary['carried_count'] ?? 0),
                    'carried_balance_total' => floatval($carry_forward_summary['carried_total'] ?? 0),
                    'source' => 'admin_dashboard',
                ]);
            }
        }
    }
    header("Location: admin.php");
    exit;
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$current_admin_class = $_SESSION['admin_class_name'] ?? '';
$is_super_admin = ($current_admin_role === 'super_admin');
$is_temporary_admin = ($current_admin_role === 'temporary_admin');
$is_platform_admin = ($is_super_admin || $is_temporary_admin);
$can_manage_semesters = function_exists('book_system_user_can_access_admin_feature')
    ? book_system_user_can_access_admin_feature('semester_management')
    : false;
$can_manage_reps = function_exists('book_system_user_can_access_admin_feature')
    ? book_system_user_can_access_admin_feature('manage_reps')
    : false;
$can_manage_rep_signups = function_exists('book_system_user_can_access_admin_feature')
    ? book_system_user_can_access_admin_feature('manage_rep_signups')
    : false;
$can_manage_lecturers = function_exists('book_system_user_can_access_admin_feature')
    ? book_system_user_can_access_admin_feature('manage_lecturers')
    : false;
$can_manage_ads = function_exists('book_system_user_can_access_admin_feature')
    ? book_system_user_can_access_admin_feature('manage_ads')
    : false;
$can_run_setup = function_exists('book_system_user_can_access_admin_feature')
    ? book_system_user_can_access_admin_feature('system_setup')
    : false;
$can_run_maintenance = function_exists('book_system_user_can_access_admin_feature')
    ? book_system_user_can_access_admin_feature('maintenance')
    : false;
$show_my_rep_workspace = $is_super_admin;
$pending_rep_signup_count = 0;
$has_new_rep_signups = false;
if ($can_manage_rep_signups) {
    $pending_signup_result = $conn->query("SELECT COUNT(*) AS total_pending FROM rep_signup_requests WHERE status = 'pending'");
    if ($pending_signup_result && $pending_signup_result->num_rows === 1) {
        $pending_signup_row = $pending_signup_result->fetch_assoc();
        $pending_rep_signup_count = intval($pending_signup_row['total_pending'] ?? 0);
        $has_new_rep_signups = $pending_rep_signup_count > 0;
    }
}
$csrf_token = csrf_get_token();
$metrics_admin_id = $is_platform_admin ? null : $current_admin_id;
$dashboard_metrics = function_exists('book_system_get_dashboard_metrics')
    ? book_system_get_dashboard_metrics($conn, $semester_id, $metrics_admin_id)
    : [];
$dashboard_alerts = function_exists('book_system_get_dashboard_alerts')
    ? book_system_get_dashboard_alerts($conn, $semester_id, $metrics_admin_id)
    : [];
$recent_activity = function_exists('book_system_fetch_recent_activity')
    ? book_system_fetch_recent_activity($conn, 8, $current_admin_id, $is_platform_admin)
    : [];
$usage_chart_date = trim(strval($_GET['usage_date'] ?? ''));
if ($usage_chart_date === '') {
    $usage_chart_date = date('Y-m-d');
}
$usage_date_object = DateTime::createFromFormat('Y-m-d', $usage_chart_date);
if (!$usage_date_object || $usage_date_object->format('Y-m-d') !== $usage_chart_date) {
    $usage_chart_date = date('Y-m-d');
}
$can_view_rep_usage_tracker = $is_platform_admin && $can_manage_reps;
$rep_usage_chart = $can_view_rep_usage_tracker && function_exists('book_system_get_rep_usage_daily_chart')
    ? book_system_get_rep_usage_daily_chart($conn, $usage_chart_date)
    : ['rows' => [], 'rep_count' => 0, 'active_rep_count' => 0, 'formatted_total' => '0s'];
$rep_usage_rows = is_array($rep_usage_chart['rows'] ?? null) ? $rep_usage_chart['rows'] : [];
$most_active_rep = !empty($rep_usage_rows) ? $rep_usage_rows[0] : null;
$semesters = [];
$semesters_result = $conn->query("SELECT semester_id, semester_name, semester_start_date, is_active FROM semesters ORDER BY semester_id DESC");
if ($semesters_result) {
    while ($semester_row = $semesters_result->fetch_assoc()) {
        $semesters[] = $semester_row;
    }
}
$active_semester_name = isset($ACTIVE_SEMESTER_NAME) ? strval($ACTIVE_SEMESTER_NAME) : '';
$active_semester_label = isset($ACTIVE_SEMESTER_LABEL) ? strval($ACTIVE_SEMESTER_LABEL) : $active_semester_name;
$overview = [
    'total_reps' => 0,
    'pending_approvals' => $pending_rep_signup_count,
    'active_classes' => 0,
    'total_requests' => 0,
    'cash_collected' => round(floatval($dashboard_metrics['cash_collected'] ?? 0), 2),
    'outstanding_balance' => round(floatval($dashboard_metrics['outstanding_balance'] ?? 0), 2),
];
$repCountStmt = $conn->prepare("SELECT COUNT(*) AS total FROM admins WHERE role = 'rep' AND is_active = 1");
if ($repCountStmt) {
    $repCountStmt->execute();
    $repCountResult = $repCountStmt->get_result();
    $repCountRow = $repCountResult ? $repCountResult->fetch_assoc() : null;
    $repCountStmt->close();
    if (is_array($repCountRow)) {
        $overview['total_reps'] = intval($repCountRow['total'] ?? 0);
    }
}
$requestCountSql = "SELECT COUNT(*) AS total FROM requests WHERE semester_id = ?";
if ($metrics_admin_id) {
    $requestCountSql .= " AND admin_id = ?";
}
$requestCountStmt = $conn->prepare($requestCountSql);
if ($requestCountStmt) {
    if ($metrics_admin_id) {
        $requestCountStmt->bind_param('ii', $semester_id, $metrics_admin_id);
    } else {
        $requestCountStmt->bind_param('i', $semester_id);
    }
    $requestCountStmt->execute();
    $requestCountResult = $requestCountStmt->get_result();
    $requestCountRow = $requestCountResult ? $requestCountResult->fetch_assoc() : null;
    $requestCountStmt->close();
    if (is_array($requestCountRow)) {
        $overview['total_requests'] = intval($requestCountRow['total'] ?? 0);
    }
}
$activeClassesSql = "SELECT COUNT(DISTINCT CONCAT(cs.admin_id, '|', UPPER(TRIM(COALESCE(cs.class_name, ''))))) AS total
    FROM class_students cs
    INNER JOIN admins a ON a.admin_id = cs.admin_id
    WHERE cs.semester_id = ?
      AND TRIM(COALESCE(cs.class_name, '')) <> ''
      AND a.is_active = 1
      AND a.role IN ('rep', 'super_admin')";
if ($metrics_admin_id) {
    $activeClassesSql .= " AND cs.admin_id = ?";
}
$activeClassesStmt = $conn->prepare($activeClassesSql);
if ($activeClassesStmt) {
    if ($metrics_admin_id) {
        $activeClassesStmt->bind_param('ii', $semester_id, $metrics_admin_id);
    } else {
        $activeClassesStmt->bind_param('i', $semester_id);
    }
    $activeClassesStmt->execute();
    $activeClassesResult = $activeClassesStmt->get_result();
    $activeClassesRow = $activeClassesResult ? $activeClassesResult->fetch_assoc() : null;
    $activeClassesStmt->close();
    if (is_array($activeClassesRow)) {
        $overview['active_classes'] = intval($activeClassesRow['total'] ?? 0);
    }
}
$pending_signups = [];
if ($can_manage_rep_signups) {
    $pendingCardsStmt = $conn->prepare("SELECT rsr.signup_id, rsr.full_name, rsr.class_name, rsr.created_at, d.department_name
        FROM rep_signup_requests rsr
        LEFT JOIN departments d ON d.department_id = rsr.department_id
        WHERE rsr.status = 'pending'
        ORDER BY rsr.created_at DESC
        LIMIT 4");
    if ($pendingCardsStmt) {
        $pendingCardsStmt->execute();
        $pendingCardsResult = $pendingCardsStmt->get_result();
        while ($pendingCardsResult && ($pendingRow = $pendingCardsResult->fetch_assoc())) {
            $pending_signups[] = $pendingRow;
        }
        $pendingCardsStmt->close();
    }
}
$dashboard_links = [];
if ($show_my_rep_workspace) {
    $dashboard_links[] = ['type' => 'form', 'name' => 'enter_my_rep_workspace', 'value' => '1', 'title' => 'My Rep Workspace', 'meta' => 'Open your rep tools, books, and requests', 'tone' => 'violet', 'icon' => '&#128188;'];
}
if ($can_manage_reps) {
    $dashboard_links[] = ['type' => 'link', 'href' => 'manage_reps.php', 'title' => 'Manage Reps', 'meta' => 'Create and manage representative accounts', 'tone' => 'amber', 'icon' => '&#128101;'];
}
if ($can_manage_rep_signups) {
    $dashboard_links[] = ['type' => 'link', 'href' => 'manage_rep_signups.php', 'title' => 'Approve Rep Requests', 'meta' => 'Review payment proofs and activate new reps', 'tone' => 'green', 'icon' => '&#9989;'];
}
if ($can_manage_semesters && $is_super_admin) {
    $dashboard_links[] = ['type' => 'link', 'href' => 'super_admin_home.php', 'title' => 'Manage Semesters', 'meta' => 'Open, create, and switch active semester records', 'tone' => 'blue', 'icon' => '&#128198;'];
}
$dashboard_links[] = ['type' => 'link', 'href' => 'activity_log.php', 'title' => 'View Reports', 'meta' => 'Open the reports and audit overview', 'tone' => 'slate', 'icon' => '&#128202;'];
if ($can_manage_reps) {
    $dashboard_links[] = ['type' => 'link', 'href' => 'manage_departments.php', 'title' => 'Manage Departments', 'meta' => 'Maintain department and class routing data', 'tone' => 'cyan', 'icon' => '&#127970;'];
}
if ($can_run_setup) {
    $dashboard_links[] = ['type' => 'link', 'href' => 'admin_setup.php', 'title' => 'System Settings', 'meta' => 'Open setup and migration tools', 'tone' => 'indigo', 'icon' => '&#9881;'];
}
$dashboard_links[] = ['type' => 'link', 'href' => 'common_request_portal.php', 'title' => 'View Common Request Portal', 'meta' => 'See the student-facing request experience', 'tone' => 'purple', 'icon' => '&#127760;'];
$dashboard_links[] = ['type' => 'form', 'name' => 'logout', 'value' => '1', 'title' => 'Logout', 'meta' => 'Securely sign out of the admin workspace', 'tone' => 'rose', 'icon' => '&#128682;'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        :root {
            --bg: #f5f7fb;
            --surface: #ffffff;
            --surface-soft: #f8fbff;
            --line: #e4eaf3;
            --text: #14213d;
            --muted: #667085;
            --primary: #2563eb;
            --primary-soft: #eaf2ff;
            --green: #16a34a;
            --amber: #d97706;
            --rose: #e11d48;
            --cyan: #0891b2;
            --slate: #334155;
            --violet: #7c3aed;
            --shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            min-height: 100vh;
            background: linear-gradient(180deg, #fbfcff 0%, var(--bg) 100%);
            color: var(--text);
            padding: 14px 12px 28px;
        }
        .dashboard-container {
            width: min(100%, 430px);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }
        .dashboard-header,
        .dashboard-section {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }
        .dashboard-header {
            display: grid;
            gap: 16px;
            padding: 18px;
        }
        .hero-copy,
        .header-actions,
        .header-control-block,
        .section-title-block,
        .quick-link .text,
        .quick-form .text {
            display: grid;
            gap: 8px;
        }
        .hero-label {
            display: inline-flex;
            width: fit-content;
            align-items: center;
            justify-content: center;
            padding: 7px 11px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .dashboard-header h1 {
            font-size: clamp(1.65rem, 8vw, 2.2rem);
            line-height: 1.08;
            letter-spacing: -0.04em;
        }
        .subtitle {
            color: var(--muted);
            font-size: 0.92rem;
            line-height: 1.6;
        }
        .hero-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .hero-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 12px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid var(--line);
            font-size: 12px;
            font-weight: 700;
            color: #344054;
        }
        .header-top-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .header-panel {
            padding: 14px;
            border-radius: var(--radius-lg);
            background: var(--surface-soft);
            border: 1px solid var(--line);
        }
        .header-block-label,
        .metric-label,
        .usage-summary-card .label,
        .usage-filter-form label {
            font-size: 11px;
            font-weight: 800;
            color: var(--muted);
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .header-form,
        .header-create-form,
        .usage-filter-form {
            margin: 0;
            display: grid;
            gap: 10px;
        }
        .header-select,
        .header-input,
        .usage-filter-form input[type="date"] {
            width: 100%;
            min-height: 48px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            padding: 0 14px;
            font: inherit;
            font-size: 0.95rem;
        }
        .header-select:focus,
        .header-input:focus,
        .usage-filter-form input[type="date"]:focus {
            outline: none;
            border-color: #93c5fd;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }
        .header-btn,
        .logout-btn,
        .usage-filter-form button,
        .approval-btn,
        .quick-link,
        .quick-form button,
        .header-notification {
            min-height: 48px;
            border: none;
            border-radius: 14px;
            font: inherit;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.18s ease, transform 0.18s ease;
        }
        .header-btn,
        .quick-link,
        .quick-form button,
        .usage-filter-form button {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
        }
        .logout-btn {
            background: #f8fafc;
            color: var(--text);
            border: 1px solid var(--line);
        }
        .header-btn:hover,
        .logout-btn:hover,
        .usage-filter-form button:hover,
        .approval-btn:hover,
        .quick-link:hover,
        .quick-form button:hover,
        .header-notification:hover {
            transform: translateY(-1px);
        }
        .header-notification {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-soft);
            border: 1px solid #bfdbfe;
            color: var(--primary);
            text-decoration: none;
        }
        .header-notification.has-alert {
            background: #fff1f2;
            border-color: #fecdd3;
            color: var(--rose);
        }
        .header-notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            border-radius: 999px;
            background: var(--rose);
            color: white;
            font-size: 10px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            line-height: 1;
        }
        .dashboard-section {
            padding: 18px;
            display: grid;
            gap: 16px;
        }
        .section-header,
        .section-topbar,
        .approval-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }
        .section-title {
            font-size: 1.06rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .section-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 12px;
            border-radius: 12px;
            text-decoration: none;
            background: var(--primary-soft);
            color: var(--primary);
            border: 1px solid #bfdbfe;
            font-size: 0.84rem;
            font-weight: 800;
        }
        .section-copy,
        .section-kicker,
        .usage-summary-card .meta,
        .metric-card .meta,
        .approval-card .meta,
        .activity-card .meta,
        .approval-class,
        .usage-class {
            color: var(--muted);
            font-size: 0.86rem;
            line-height: 1.55;
        }
        .overview-grid,
        .menu-grid,
        .usage-summary-grid,
        .activity-grid,
        .approval-grid,
        .usage-chart-list {
            display: grid;
            gap: 12px;
        }
        .overview-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .metric-card,
        .approval-card,
        .activity-card,
        .usage-chart-item,
        .usage-summary-card {
            border-radius: 18px;
            background: #fff;
            border: 1px solid var(--line);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
        }
        .metric-card {
            display: grid;
            gap: 8px;
            padding: 15px 14px;
            border-top: 4px solid var(--primary);
        }
        .metric-card[data-tone="green"] { border-top-color: var(--green); }
        .metric-card[data-tone="amber"] { border-top-color: var(--amber); }
        .metric-card[data-tone="rose"] { border-top-color: var(--rose); }
        .metric-card[data-tone="cyan"] { border-top-color: var(--cyan); }
        .metric-card[data-tone="slate"] { border-top-color: var(--slate); }
        .metric-card[data-tone="violet"] { border-top-color: var(--violet); }
        .metric-value,
        .usage-summary-card .value {
            font-size: clamp(1.2rem, 5vw, 1.7rem);
            line-height: 1.05;
            font-weight: 800;
            letter-spacing: -0.04em;
        }
        .menu-grid {
            grid-template-columns: 1fr;
        }
        .quick-link,
        .quick-form button {
            width: 100%;
            display: grid;
            grid-template-columns: 44px 1fr 18px;
            gap: 12px;
            align-items: center;
            text-align: left;
            padding: 15px 14px;
            text-decoration: none;
        }
        .quick-link .icon,
        .quick-form .icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.2rem;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .quick-link .text h3,
        .quick-form .text h3,
        .approval-name,
        .activity-title,
        .usage-name {
            font-size: 0.96rem;
            font-weight: 800;
        }
        .quick-link .text h3,
        .quick-form .text h3 {
            color: #ffffff;
            letter-spacing: -0.02em;
        }
        .quick-link .meta,
        .quick-form .meta {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.82rem;
            line-height: 1.45;
            font-weight: 600;
        }
        .quick-link::after,
        .quick-form button::after {
            content: "\203A";
            color: rgba(255, 255, 255, 0.96);
            font-size: 1.2rem;
            font-weight: 800;
            text-align: right;
        }
        .quick-link[data-tone="green"], .quick-form button[data-tone="green"] { background: linear-gradient(135deg, #18a870 0%, #14b8a6 100%); }
        .quick-link[data-tone="amber"], .quick-form button[data-tone="amber"] { background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%); }
        .quick-link[data-tone="cyan"], .quick-form button[data-tone="cyan"] { background: linear-gradient(135deg, #0891b2 0%, #38bdf8 100%); }
        .quick-link[data-tone="slate"], .quick-form button[data-tone="slate"] { background: linear-gradient(135deg, #334155 0%, #475569 100%); }
        .quick-link[data-tone="indigo"], .quick-form button[data-tone="indigo"] { background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); }
        .quick-link[data-tone="purple"], .quick-form button[data-tone="purple"] { background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%); }
        .quick-link[data-tone="rose"], .quick-form button[data-tone="rose"] { background: linear-gradient(135deg, #e11d48 0%, #fb7185 100%); }
        .quick-form,
        .approval-form,
        .logout-form { margin: 0; }
        .approval-card,
        .activity-card,
        .usage-chart-item,
        .usage-summary-card {
            padding: 16px;
            display: grid;
            gap: 12px;
        }
        .approval-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .approval-btn {
            width: 100%;
            min-height: 44px;
            border-radius: 12px;
        }
        .approval-btn.approve { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .approval-btn.reject { background: #fef2f2; color: #b91c1c; border: 1px solid #fecdd3; }
        .alert-grid { display: grid; gap: 10px; }
        .alert-note,
        .empty-note {
            padding: 15px 16px;
            border-radius: 16px;
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .activity-card .details {
            color: var(--text);
            font-size: 0.9rem;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }
        .usage-filter-form { width: 100%; }
        .usage-summary-grid { grid-template-columns: 1fr; }
        .usage-bar-track {
            width: 100%;
            height: 10px;
            border-radius: 999px;
            background: #e7ecfb;
            overflow: hidden;
        }
        .usage-bar-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(135deg, var(--primary) 0%, var(--violet) 100%);
        }
        .usage-bar-area {
            display: grid;
            gap: 10px;
        }
        .usage-bar-values {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 0.82rem;
        }
        @media (min-width: 760px) {
            body { padding: 24px 18px 34px; }
            .dashboard-container { width: min(100%, 1020px); }
            .dashboard-header {
                grid-template-columns: minmax(0, 1.15fr) minmax(300px, 0.95fr);
                align-items: start;
                padding: 22px;
            }
            .header-create-form {
                grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) auto;
                align-items: end;
            }
            .overview-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .menu-grid,
            .approval-grid,
            .activity-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .usage-summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .usage-filter-form {
                width: auto;
                grid-template-columns: 1fr auto;
                align-items: end;
            }
            .usage-filter-form label { grid-column: 1 / -1; }
        }
        @media (max-width: 520px) {
            .header-top-actions { grid-template-columns: 1fr; }
            .header-notification,
            .logout-btn,
            .header-btn { width: 100%; }
            .overview-grid,
            .approval-actions { grid-template-columns: 1fr; }
            .quick-link,
            .quick-form button { grid-template-columns: 44px 1fr; }
            .quick-link::after,
            .quick-form button::after { display: none; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <section class="dashboard-header">
            <div class="hero-copy">
                <div class="hero-label">ClassBookHub Admin</div>
                <h1>Admin Workspace</h1>
                <p class="subtitle">Manage reps, semesters, approvals, and reports from one simple mobile-friendly dashboard.</p>
                <div class="hero-chip-row">
                    <span class="hero-chip">
                        <?php
                        if ($is_super_admin) {
                            echo 'Super Admin';
                        } elseif ($is_temporary_admin) {
                            echo 'Temporary Admin';
                        } else {
                            echo htmlspecialchars($current_admin_class ?: 'Admin Workspace');
                        }
                        ?>
                    </span>
                    <span class="hero-chip"><?php echo htmlspecialchars($active_semester_label !== '' ? $active_semester_label : 'No active semester selected'); ?></span>
                    <?php if ($is_temporary_admin && !empty($_SESSION['temp_admin_expires_at'])): ?>
                        <span class="hero-chip">Access ends <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime(strval($_SESSION['temp_admin_expires_at'])))); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="header-actions">
                <div class="header-top-actions">
                    <?php if ($can_manage_rep_signups): ?>
                        <a href="manage_rep_signups.php" class="header-notification<?php echo $has_new_rep_signups ? ' has-alert' : ''; ?>" title="<?php echo $has_new_rep_signups ? htmlspecialchars($pending_rep_signup_count . ' pending rep signup request' . ($pending_rep_signup_count === 1 ? '' : 's')) : 'No pending rep signup requests'; ?>" aria-label="<?php echo $has_new_rep_signups ? htmlspecialchars($pending_rep_signup_count . ' pending rep signup request' . ($pending_rep_signup_count === 1 ? '' : 's')) : 'Rep signup notifications'; ?>">
                            &#128276;
                            <?php if ($has_new_rep_signups): ?>
                                <span class="header-notification-badge"><?php echo $pending_rep_signup_count > 99 ? '99+' : $pending_rep_signup_count; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <a href="common_request_portal.php" class="header-btn">Portal</a>
                    <form method="POST" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <button type="submit" name="logout" value="1" class="logout-btn">Logout</button>
                    </form>
                </div>
                <?php if ($can_manage_semesters): ?>
                    <div class="header-control-block header-panel">
                        <div class="header-block-label">Active Semester</div>
                        <form method="POST" class="header-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="set_active_semester" value="1">
                            <select name="semester_id" onchange="this.form.submit()" class="header-select">
                                <?php foreach ($semesters as $semester_option): ?>
                                    <?php
                                    $semesterOptionLabel = function_exists('book_system_build_semester_label')
                                        ? book_system_build_semester_label(strval($semester_option['semester_name'] ?? ''), strval($semester_option['semester_start_date'] ?? ''))
                                        : strval($semester_option['semester_name'] ?? '');
                                    ?>
                                    <option value="<?php echo intval($semester_option['semester_id']); ?>" <?php echo intval($semester_option['is_active'] ?? 0) === 1 ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($semesterOptionLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="header-control-block header-panel">
                        <div class="header-block-label">Create Semester</div>
                        <form method="POST" class="header-create-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="text" name="semester_name" placeholder="New semester name" required class="header-input">
                            <input type="date" name="semester_start_date" required class="header-input date">
                            <button type="submit" name="create_semester" value="1" class="header-btn">Create</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-block">
                    <h2 class="section-title">Overview</h2>
                    <p class="section-copy">Current semester totals at a glance.</p>
                </div>
            </div>
            <div class="overview-grid">
                <article class="metric-card" data-tone="amber"><div class="metric-label">Total Reps</div><div class="metric-value"><?php echo number_format($overview['total_reps']); ?></div><div class="meta">Active representative accounts across the current rollout.</div></article>
                <article class="metric-card" data-tone="rose"><div class="metric-label">Pending Rep Approvals</div><div class="metric-value"><?php echo number_format($overview['pending_approvals']); ?></div><div class="meta">Signup requests waiting for approval or rejection.</div></article>
                <article class="metric-card" data-tone="cyan"><div class="metric-label">Active Classes</div><div class="metric-value"><?php echo number_format($overview['active_classes']); ?></div><div class="meta">Uploaded class lists currently available in the active semester.</div></article>
                <article class="metric-card" data-tone="slate"><div class="metric-label">Total Requests</div><div class="metric-value"><?php echo number_format($overview['total_requests']); ?></div><div class="meta">Request volume recorded inside the active semester workspace.</div></article>
                <article class="metric-card" data-tone="green"><div class="metric-label">Total Cash Collected</div><div class="metric-value">GHS <?php echo number_format($overview['cash_collected'], 2); ?></div><div class="meta">Includes manual confirmations, partial payments, and Paystack-verified payments.</div></article>
                <article class="metric-card" data-tone="violet"><div class="metric-label">Outstanding Balance</div><div class="metric-value">GHS <?php echo number_format($overview['outstanding_balance'], 2); ?></div><div class="meta">Remaining balances still unsettled in the active semester.</div></article>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-block">
                    <h2 class="section-title">Quick Actions</h2>
                    <p class="section-copy">Open the tools you need most.</p>
                </div>
            </div>
            <div class="menu-grid">
                <?php foreach ($dashboard_links as $dashboard_link): ?>
                    <?php if (($dashboard_link['type'] ?? '') === 'form'): ?>
                        <form method="POST" class="quick-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <button type="submit" name="<?php echo htmlspecialchars(strval($dashboard_link['name'] ?? '')); ?>" value="<?php echo htmlspecialchars(strval($dashboard_link['value'] ?? '1')); ?>" data-tone="<?php echo htmlspecialchars(strval($dashboard_link['tone'] ?? 'blue')); ?>">
                                <div class="icon"><?php echo $dashboard_link['icon']; ?></div>
                                <div class="text"><h3><?php echo htmlspecialchars(strval($dashboard_link['title'] ?? 'Action')); ?></h3><p class="meta"><?php echo htmlspecialchars(strval($dashboard_link['meta'] ?? '')); ?></p></div>
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars(strval($dashboard_link['href'] ?? '#')); ?>" class="quick-link" data-tone="<?php echo htmlspecialchars(strval($dashboard_link['tone'] ?? 'blue')); ?>">
                            <div class="icon"><?php echo $dashboard_link['icon']; ?></div>
                            <div class="text"><h3><?php echo htmlspecialchars(strval($dashboard_link['title'] ?? 'Action')); ?></h3><p class="meta"><?php echo htmlspecialchars(strval($dashboard_link['meta'] ?? '')); ?></p></div>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($can_manage_rep_signups): ?>
            <section class="dashboard-section">
                <div class="section-header">
                    <div class="section-title-block">
                        <h2 class="section-title">Pending Rep Approvals</h2>
                        <p class="section-copy">Approve or reject new rep requests quickly.</p>
                    </div>
                    <a href="manage_rep_signups.php" class="section-link">Open full queue</a>
                </div>
                <?php if (!empty($pending_signups)): ?>
                    <div class="approval-grid">
                        <?php foreach ($pending_signups as $pending_signup): ?>
                            <article class="approval-card">
                                <div class="approval-card-head">
                                    <div>
                                        <div class="approval-name"><?php echo htmlspecialchars(strval($pending_signup['full_name'] ?? 'Rep Request')); ?></div>
                                        <div class="approval-class"><?php echo htmlspecialchars(implode(' / ', array_filter([strval($pending_signup['class_name'] ?? ''), strval($pending_signup['department_name'] ?? '')])) ?: 'Class details not provided'); ?></div>
                                    </div>
                                    <span class="hero-chip">Pending</span>
                                </div>
                                <div class="meta">Requested <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime(strval($pending_signup['created_at'] ?? 'now')))); ?></div>
                                <div class="approval-actions">
                                    <form method="post" action="manage_rep_signups.php" class="approval-form" onsubmit="return confirm('Approve this request and activate the rep account?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="signup_id" value="<?php echo intval($pending_signup['signup_id'] ?? 0); ?>">
                                        <button type="submit" class="approval-btn approve">Approve</button>
                                    </form>
                                    <form method="post" action="manage_rep_signups.php" class="approval-form" onsubmit="return confirm('Reject this request?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="signup_id" value="<?php echo intval($pending_signup['signup_id'] ?? 0); ?>">
                                        <button type="submit" class="approval-btn reject">Reject</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-note">No pending rep approvals right now.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-block">
                    <h2 class="section-title">Recent Activity</h2>
                    <p class="section-copy">Latest actions from the current workspace.</p>
                </div>
            </div>
            <?php if (!empty($recent_activity) && is_array($recent_activity)): ?>
                <div class="activity-grid">
                    <?php foreach ($recent_activity as $activity_item): ?>
                        <?php
                        $activityTitle = trim(strval($activity_item['title'] ?? ''));
                        if ($activityTitle === '') {
                            $activityTitle = ucwords(str_replace('_', ' ', strval($activity_item['action'] ?? 'Activity')));
                        }
                        $activityMetaParts = array_filter([
                            strval($activity_item['actor_name'] ?? ''),
                            strval($activity_item['created_at_label'] ?? ''),
                            strval($activity_item['target_type'] ?? ''),
                        ]);
                        $activityDetails = trim(strval($activity_item['details'] ?? ''));
                        ?>
                        <article class="activity-card">
                            <div class="activity-title"><?php echo htmlspecialchars($activityTitle); ?></div>
                            <div class="meta"><?php echo htmlspecialchars(!empty($activityMetaParts) ? implode(' / ', $activityMetaParts) : 'System activity'); ?></div>
                            <?php if ($activityDetails !== ''): ?><div class="details"><?php echo htmlspecialchars($activityDetails); ?></div><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-note">No recent activity yet.</div>
            <?php endif; ?>
        </section>

        <?php if ($can_view_rep_usage_tracker): ?>
            <section class="dashboard-section">
                <div class="section-topbar">
                    <div class="section-title-block">
                        <h2 class="section-title">Rep Performance Tracker</h2>
                        <p class="section-kicker">Daily rep usage for the selected date.</p>
                    </div>
                    <form method="GET" class="usage-filter-form">
                        <label for="usage_date">Usage Date</label>
                        <input type="date" id="usage_date" name="usage_date" value="<?php echo htmlspecialchars($usage_chart_date); ?>">
                        <button type="submit">View</button>
                    </form>
                </div>
                <div class="usage-summary-grid">
                    <div class="usage-summary-card"><div class="label">Active Reps</div><div class="value"><?php echo intval($rep_usage_chart['active_rep_count'] ?? 0); ?></div><div class="meta"><?php echo intval($rep_usage_chart['rep_count'] ?? 0); ?> rep account(s) tracked for the selected day</div></div>
                    <div class="usage-summary-card"><div class="label">Tracked Time</div><div class="value"><?php echo htmlspecialchars(strval($rep_usage_chart['formatted_total'] ?? '0s')); ?></div><div class="meta">Combined active usage time across all reps for this date</div></div>
                    <div class="usage-summary-card"><div class="label">Most Active Rep</div><div class="value"><?php echo $most_active_rep ? htmlspecialchars(strval($most_active_rep['formatted_duration'] ?? '0s')) : '0s'; ?></div><div class="meta"><?php echo $most_active_rep ? htmlspecialchars(trim(strval($most_active_rep['full_name'] ?? '') . ' / ' . strval($most_active_rep['class_name'] ?? ''))) : 'No rep activity recorded yet'; ?></div></div>
                </div>
                <?php if (!empty($rep_usage_rows)): ?>
                    <div class="usage-chart-list">
                        <?php foreach ($rep_usage_rows as $usage_row): ?>
                            <?php
                            $identityBits = array_filter([
                                strval($usage_row['class_name'] ?? ''),
                                strval($usage_row['department_name'] ?? ''),
                            ]);
                            $lastActivity = trim(strval($usage_row['last_activity_at'] ?? ''));
                            $lastActivityLabel = $lastActivity !== '' ? date('g:i A', strtotime($lastActivity)) : 'No activity yet';
                            ?>
                            <div class="usage-chart-item">
                                <div>
                                    <div class="usage-name"><?php echo htmlspecialchars(strval($usage_row['full_name'] ?? 'Rep')); ?></div>
                                    <div class="usage-class"><?php echo htmlspecialchars(!empty($identityBits) ? implode(' / ', $identityBits) : 'Rep account'); ?></div>
                                </div>
                                <div class="usage-bar-area">
                                    <div class="usage-bar-track"><div class="usage-bar-fill" style="width: <?php echo max(0, min(100, floatval($usage_row['percentage'] ?? 0))); ?>%;"></div></div>
                                    <div class="usage-bar-values"><span><strong><?php echo htmlspecialchars(strval($usage_row['formatted_duration'] ?? '0s')); ?></strong></span><span>Last activity: <?php echo htmlspecialchars($lastActivityLabel); ?></span></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-note">No rep accounts are available for tracking yet.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
<?php if (file_exists(__DIR__ . '/notifications_widget.php')) { require __DIR__ . '/notifications_widget.php'; } ?>
<?php include 'footer.php'; ?>
</body>
</html>
