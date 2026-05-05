<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
}

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS portal_ads (
    ad_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL,
    owner_name VARCHAR(120) NULL,
    owner_contact VARCHAR(120) NULL,
    link_url VARCHAR(255) NULL,
    badge_text VARCHAR(50) NULL,
    image_path VARCHAR(255) NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    view_count INT NOT NULL DEFAULT 0,
    click_count INT NOT NULL DEFAULT 0,
    last_viewed_at DATETIME NULL,
    last_clicked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_portal_ads_active (is_active, display_order, created_at)
)");
if (function_exists('book_system_setup_ensure_column')) {
    book_system_setup_ensure_column($conn, 'portal_ads', 'image_path', 'VARCHAR(255) NULL AFTER badge_text');
    book_system_setup_ensure_column($conn, 'portal_ads', 'view_count', 'INT NOT NULL DEFAULT 0 AFTER is_active');
    book_system_setup_ensure_column($conn, 'portal_ads', 'click_count', 'INT NOT NULL DEFAULT 0 AFTER view_count');
    book_system_setup_ensure_column($conn, 'portal_ads', 'last_viewed_at', 'DATETIME NULL AFTER click_count');
    book_system_setup_ensure_column($conn, 'portal_ads', 'last_clicked_at', 'DATETIME NULL AFTER last_viewed_at');
}
$conn->query("ALTER TABLE portal_ads MODIFY owner_name VARCHAR(120) NULL");

function portal_ads_upload_dir(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'portal_ads';
}

function portal_ads_public_path(string $filename): string {
    return 'uploads/portal_ads/' . $filename;
}

function portal_ads_store_image(array $file, string &$error = ''): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'Image upload failed. Please try again.';
        return null;
    }

    $tmpPath = strval($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $error = 'Invalid uploaded image.';
        return null;
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime = $finfo ? strval(finfo_file($finfo, $tmpPath) ?: '') : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        $error = 'Only JPG, PNG, WEBP, and GIF images are allowed.';
        return null;
    }

    $uploadDir = portal_ads_upload_dir();
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        $error = 'Could not prepare the advert upload folder.';
        return null;
    }

    $filename = 'ad_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $target)) {
        $error = 'Could not save the uploaded image.';
        return null;
    }

    return portal_ads_public_path($filename);
}

function portal_ads_normalize_link(?string $url): string {
    $value = trim(strval($url ?? ''));
    if ($value === '') {
        return '';
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    if (preg_match('#^(www\.|wa\.me/|api\.whatsapp\.com/|instagram\.com/|facebook\.com/|x\.com/|twitter\.com/|tiktok\.com/|youtube\.com/)#i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    } else {
        $value = 'https://' . $value;
    }

    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();
$editing_ad_id = intval($_GET['edit'] ?? 0);
$editing_ad = null;

if ($editing_ad_id > 0) {
    $edit_stmt = $conn->prepare("SELECT * FROM portal_ads WHERE ad_id = ? LIMIT 1");
    if ($edit_stmt) {
        $edit_stmt->bind_param('i', $editing_ad_id);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();
        if ($edit_result && $edit_result->num_rows === 1) {
            $editing_ad = $edit_result->fetch_assoc();
        } else {
            $editing_ad_id = 0;
        }
        $edit_stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $action = strval($_POST['action'] ?? '');

        if ($action === 'create_ad') {
            $title = trim(strval($_POST['title'] ?? ''));
            $description = trim(strval($_POST['description'] ?? ''));
            $owner_name = trim(strval($_POST['owner_name'] ?? ''));
            $owner_contact = trim(strval($_POST['owner_contact'] ?? ''));
            $raw_link_url = trim(strval($_POST['link_url'] ?? ''));
            $link_url = portal_ads_normalize_link($raw_link_url);
            $badge_text = trim(strval($_POST['badge_text'] ?? ''));
            $display_order = intval($_POST['display_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $image_error = '';
            $image_path = portal_ads_store_image($_FILES['image_file'] ?? [], $image_error);

            if ($title === '' || $description === '') {
                $error_msg = 'Title and description are required.';
            } elseif ($image_error !== '') {
                $error_msg = $image_error;
            } elseif ($raw_link_url !== '' && $link_url === '') {
                $error_msg = 'Enter a valid business page link.';
            } else {
                $stmt = $conn->prepare("INSERT INTO portal_ads (title, description, owner_name, owner_contact, link_url, badge_text, image_path, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('sssssssii', $title, $description, $owner_name, $owner_contact, $link_url, $badge_text, $image_path, $display_order, $is_active);
                    if ($stmt->execute()) {
                        $success_msg = 'Advert created successfully.';
                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'create_portal_ad', 'portal_ad', intval($conn->insert_id), [
                                'title' => $title,
                                'owner_name' => $owner_name,
                            ]);
                        }
                    } else {
                        $error_msg = 'Failed to save advert.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'update_ad') {
            $ad_id = intval($_POST['ad_id'] ?? 0);
            $title = trim(strval($_POST['title'] ?? ''));
            $description = trim(strval($_POST['description'] ?? ''));
            $owner_name = trim(strval($_POST['owner_name'] ?? ''));
            $owner_contact = trim(strval($_POST['owner_contact'] ?? ''));
            $raw_link_url = trim(strval($_POST['link_url'] ?? ''));
            $link_url = portal_ads_normalize_link($raw_link_url);
            $badge_text = trim(strval($_POST['badge_text'] ?? ''));
            $display_order = intval($_POST['display_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $remove_image = isset($_POST['remove_image']) ? 1 : 0;
            $existing_image = trim(strval($_POST['existing_image'] ?? ''));
            $image_error = '';
            $new_image_path = portal_ads_store_image($_FILES['image_file'] ?? [], $image_error);

            if ($ad_id <= 0 || $title === '' || $description === '') {
                $error_msg = 'Title and description are required.';
            } elseif ($image_error !== '') {
                $error_msg = $image_error;
            } elseif ($raw_link_url !== '' && $link_url === '') {
                $error_msg = 'Enter a valid business page link.';
            } else {
                $final_image_path = $existing_image;
                if ($remove_image) {
                    $final_image_path = '';
                }
                if ($new_image_path !== null) {
                    $final_image_path = $new_image_path;
                }

                $stmt = $conn->prepare("UPDATE portal_ads
                    SET title = ?, description = ?, owner_name = ?, owner_contact = ?, link_url = ?, badge_text = ?, image_path = ?, display_order = ?, is_active = ?
                    WHERE ad_id = ?");
                if ($stmt) {
                    $stmt->bind_param('sssssssiii', $title, $description, $owner_name, $owner_contact, $link_url, $badge_text, $final_image_path, $display_order, $is_active, $ad_id);
                    if ($stmt->execute()) {
                        $success_msg = 'Advert updated successfully.';
                        if ($existing_image !== '' && (($remove_image && $new_image_path === null) || ($new_image_path !== null && $existing_image !== $new_image_path))) {
                            $old_image_full_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $existing_image);
                            if (is_file($old_image_full_path)) {
                                @unlink($old_image_full_path);
                            }
                        }
                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'update_portal_ad', 'portal_ad', $ad_id, [
                                'title' => $title,
                                'owner_name' => $owner_name,
                            ]);
                        }
                        $editing_ad_id = 0;
                        $editing_ad = null;
                    } else {
                        $error_msg = 'Failed to update advert.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'toggle_ad') {
            $ad_id = intval($_POST['ad_id'] ?? 0);
            if ($ad_id > 0) {
                $stmt = $conn->prepare("UPDATE portal_ads SET is_active = NOT is_active WHERE ad_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $ad_id);
                    $stmt->execute();
                    $stmt->close();
                    $success_msg = 'Advert status updated.';
                    if (function_exists('book_system_audit_log')) {
                        book_system_audit_log($conn, 'toggle_portal_ad', 'portal_ad', $ad_id, []);
                    }
                }
            }
        } elseif ($action === 'delete_ad') {
            $ad_id = intval($_POST['ad_id'] ?? 0);
            if ($ad_id > 0) {
                $image_path = '';
                $find_stmt = $conn->prepare("SELECT image_path FROM portal_ads WHERE ad_id = ? LIMIT 1");
                if ($find_stmt) {
                    $find_stmt->bind_param('i', $ad_id);
                    $find_stmt->execute();
                    $find_res = $find_stmt->get_result();
                    if ($find_res && $find_res->num_rows === 1) {
                        $image_path = strval($find_res->fetch_assoc()['image_path'] ?? '');
                    }
                    $find_stmt->close();
                }
                $stmt = $conn->prepare("DELETE FROM portal_ads WHERE ad_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $ad_id);
                    $stmt->execute();
                    $stmt->close();
                    $success_msg = 'Advert deleted.';
                    if ($image_path !== '') {
                        $image_full_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $image_path);
                        if (is_file($image_full_path)) {
                            @unlink($image_full_path);
                        }
                    }
                    if (function_exists('book_system_audit_log')) {
                        book_system_audit_log($conn, 'delete_portal_ad', 'portal_ad', $ad_id, []);
                    }
                }
            }
        }
    }
}

$ad_totals = [
    'total_ads' => 0,
    'active_ads' => 0,
    'total_views' => 0,
    'total_clicks' => 0,
];
$top_ad = null;
$summary_result = $conn->query("SELECT
        COUNT(*) AS total_ads,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_ads,
        COALESCE(SUM(view_count), 0) AS total_views,
        COALESCE(SUM(click_count), 0) AS total_clicks
    FROM portal_ads");
if ($summary_result) {
    $ad_totals = array_merge($ad_totals, $summary_result->fetch_assoc() ?: []);
}
$top_result = $conn->query("SELECT title, view_count, click_count
    FROM portal_ads
    ORDER BY click_count DESC, view_count DESC, created_at DESC
    LIMIT 1");
if ($top_result) {
    $top_ad = $top_result->fetch_assoc() ?: null;
}
$ad_leaderboard = [];
$leaderboard_result = $conn->query("SELECT ad_id, title, is_active, view_count, click_count, last_clicked_at
    FROM portal_ads
    ORDER BY click_count DESC, view_count DESC, created_at DESC
    LIMIT 5");
if ($leaderboard_result) {
    while ($leaderboard_row = $leaderboard_result->fetch_assoc()) {
        $ad_leaderboard[] = $leaderboard_row;
    }
}

$ads = [];
$ads_result = $conn->query("SELECT * FROM portal_ads ORDER BY display_order ASC, created_at DESC");
if ($ads_result) {
    while ($row = $ads_result->fetch_assoc()) {
        $ads[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Portal Ads</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .page-container { max-width: 1120px; margin: 0 auto; }
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 26px 30px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .page-header h1 { font-size: 24px; font-weight: 700; }
        .subtitle { opacity: 0.9; margin-top: 4px; font-size: 13px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .content-grid {
            display: grid;
            grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #eef2f7;
        }
        .summary-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 8px;
        }
        .summary-value {
            color: #111827;
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 6px;
        }
        .summary-note {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }
        .report-card {
            margin-bottom: 22px;
        }
        .leaderboard-list {
            display: grid;
            gap: 12px;
        }
        .leaderboard-row {
            display: grid;
            grid-template-columns: 46px minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }
        .leaderboard-rank {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 13px;
            font-weight: 800;
        }
        .leaderboard-title {
            color: #111827;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.4;
            margin-bottom: 4px;
        }
        .leaderboard-meta {
            color: #64748b;
            font-size: 12px;
            line-height: 1.55;
        }
        .leaderboard-metrics {
            text-align: right;
            color: #334155;
            font-size: 12px;
            line-height: 1.6;
            font-weight: 700;
        }
        .metric-pills {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .metric-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 800;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .card-title {
            font-size: 18px;
            font-weight: 800;
            color: #333;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            font-weight: 700;
            color: #555;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .form-input, .form-textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
        }
        .form-textarea { min-height: 110px; resize: vertical; }
        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .btn {
            padding: 11px 18px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; width: 100%; }
        .btn-success { background: #16a34a; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-danger { background: #dc2626; color: white; }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .ads-list { display: grid; gap: 16px; }
        .ad-item {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 18px;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }
        .ad-item.is-editing {
            border-color: rgba(102, 126, 234, 0.4);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.08);
        }
        .ad-head {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 12px;
            margin-bottom: 10px;
        }
        .ad-title { font-size: 18px; font-weight: 800; color: #111827; }
        .ad-meta { color: #64748b; font-size: 13px; line-height: 1.6; }
        .ad-badge {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .status-pill {
            display: inline-flex;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .inline-form { display: inline; }
        .image-help {
            margin-top: 8px;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }
        .current-image-box {
            margin-top: 10px;
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }
        .empty-state {
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            padding: 28px;
            text-align: center;
            color: #64748b;
            background: #f8fafc;
        }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: 1fr 1fr; }
            .content-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 600px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <div>
            <h1>Manage Portal Ads</h1>
            <div class="subtitle">Create and control the adverts shown on the common student request portal.</div>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back to Dashboard</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Total Adverts</div>
            <div class="summary-value"><?php echo intval($ad_totals['total_ads'] ?? 0); ?></div>
            <div class="summary-note">All advert records currently stored in the portal manager.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Active Adverts</div>
            <div class="summary-value"><?php echo intval($ad_totals['active_ads'] ?? 0); ?></div>
            <div class="summary-note">Adverts currently visible to students on the common request portal.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total Views</div>
            <div class="summary-value"><?php echo intval($ad_totals['total_views'] ?? 0); ?></div>
            <div class="summary-note">Session-based visibility count from the live public portal.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total Clicks</div>
            <div class="summary-value"><?php echo intval($ad_totals['total_clicks'] ?? 0); ?></div>
            <div class="summary-note">
                <?php
                $top_title = trim(strval($top_ad['title'] ?? ''));
                $overall_views = max(1, intval($ad_totals['total_views'] ?? 0));
                $overall_clicks = intval($ad_totals['total_clicks'] ?? 0);
                $overall_ctr = intval($ad_totals['total_views'] ?? 0) > 0 ? round(($overall_clicks / max(1, intval($ad_totals['total_views'] ?? 0))) * 100, 1) : 0;
                if ($top_title !== '') {
                    echo 'Top performer: ' . htmlspecialchars($top_title) . '. Overall CTR: ' . htmlspecialchars(number_format($overall_ctr, 1)) . '%.';
                } else {
                    echo 'Clicks are tracked whenever a student opens a business page.';
                }
                ?>
            </div>
        </div>
    </div>

    <?php if (!empty($ad_leaderboard)): ?>
        <section class="card report-card">
            <h2 class="card-title">Performance Snapshot</h2>
            <div class="leaderboard-list">
                <?php foreach ($ad_leaderboard as $index => $ad_rank): ?>
                    <?php
                    $rank_views = intval($ad_rank['view_count'] ?? 0);
                    $rank_clicks = intval($ad_rank['click_count'] ?? 0);
                    $rank_ctr = $rank_views > 0 ? round(($rank_clicks / $rank_views) * 100, 1) : 0;
                    ?>
                    <div class="leaderboard-row">
                        <div class="leaderboard-rank">#<?php echo $index + 1; ?></div>
                        <div>
                            <div class="leaderboard-title"><?php echo htmlspecialchars(strval($ad_rank['title'] ?? '')); ?></div>
                            <div class="leaderboard-meta">
                                <?php echo !empty($ad_rank['is_active']) ? 'Visible on portal' : 'Currently hidden'; ?>
                                <?php if (!empty($ad_rank['last_clicked_at'])): ?>
                                    | Last click: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime(strval($ad_rank['last_clicked_at'])))); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="leaderboard-metrics">
                            Views: <?php echo $rank_views; ?><br>
                            Clicks: <?php echo $rank_clicks; ?><br>
                            CTR: <?php echo number_format($rank_ctr, 1); ?>%
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="content-grid">
        <section class="card">
            <h2 class="card-title"><?php echo $editing_ad ? 'Edit Advert' : 'Add New Advert'; ?></h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="<?php echo $editing_ad ? 'update_ad' : 'create_ad'; ?>">
                <?php if ($editing_ad): ?>
                    <input type="hidden" name="ad_id" value="<?php echo intval($editing_ad['ad_id'] ?? 0); ?>">
                    <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars(strval($editing_ad['image_path'] ?? '')); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Advert Title *</label>
                    <input type="text" name="title" class="form-input" placeholder="e.g. Campus Graphic Design Services" value="<?php echo htmlspecialchars(strval($editing_ad['title'] ?? '')); ?>" required>
                </div>

                <div class="form-group">
                    <label>Description *</label>
                    <textarea name="description" class="form-textarea" placeholder="Short summary of the student business or service." required><?php echo htmlspecialchars(strval($editing_ad['description'] ?? '')); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                    <label>Owner Name</label>
                    <input type="text" name="owner_name" class="form-input" placeholder="Optional business owner name" value="<?php echo htmlspecialchars(strval($editing_ad['owner_name'] ?? '')); ?>">
                    </div>
                    <div class="form-group">
                        <label>Owner Contact</label>
                        <input type="text" name="owner_contact" class="form-input" placeholder="e.g. 0244001122" value="<?php echo htmlspecialchars(strval($editing_ad['owner_contact'] ?? '')); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Link URL</label>
                        <input type="text" name="link_url" class="form-input" placeholder="Optional WhatsApp, Instagram, or website link" value="<?php echo htmlspecialchars(strval($editing_ad['link_url'] ?? '')); ?>">
                    </div>
                    <div class="form-group">
                        <label>Badge Text</label>
                        <input type="text" name="badge_text" class="form-input" placeholder="e.g. Student Business" value="<?php echo htmlspecialchars(strval($editing_ad['badge_text'] ?? '')); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label><?php echo $editing_ad ? 'Replace Advert Image' : 'Advert Image'; ?></label>
                    <input type="file" name="image_file" class="form-input" accept=".jpg,.jpeg,.png,.webp,.gif">
                    <div class="image-help">Images help the advert stand out better on the public portal rail.</div>
                    <?php if ($editing_ad && !empty($editing_ad['image_path'])): ?>
                        <div class="current-image-box">
                            <img src="<?php echo htmlspecialchars(strval($editing_ad['image_path'])); ?>" alt="Current advert image" style="width:100%; max-height:160px; object-fit:cover; border-radius:12px; border:1px solid #e5e7eb;">
                            <label style="display:flex; align-items:center; gap:10px; margin-top:10px; font-size:13px; color:#475569;">
                                <input type="checkbox" name="remove_image" value="1" style="width:auto;">
                                Remove current image
                            </label>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" class="form-input" value="<?php echo intval($editing_ad['display_order'] ?? 0); ?>">
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <label style="display:flex; align-items:center; gap:10px; margin-bottom:0;">
                            <input type="checkbox" name="is_active" value="1" <?php echo !$editing_ad || !empty($editing_ad['is_active']) ? 'checked' : ''; ?> style="width:auto;">
                            Show advert immediately
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo $editing_ad ? 'Update Advert' : 'Save Advert'; ?></button>
                <?php if ($editing_ad): ?>
                    <a href="manage_ads.php" class="btn" style="width:100%; margin-top:10px; background:#e2e8f0; color:#334155;">Cancel Editing</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="card">
            <h2 class="card-title">Current Portal Ads</h2>
            <?php if (empty($ads)): ?>
                <div class="empty-state">No adverts have been added yet.</div>
            <?php else: ?>
                <div class="ads-list">
                    <?php foreach ($ads as $ad): ?>
                        <article class="ad-item <?php echo $editing_ad_id === intval($ad['ad_id'] ?? 0) ? 'is-editing' : ''; ?>">
                            <div class="ad-head">
                                <div>
                                    <div class="ad-title"><?php echo htmlspecialchars(strval($ad['title'] ?? '')); ?></div>
                                    <div class="ad-meta">
                                        <?php if (!empty($ad['owner_name'])): ?>
                                            Owner: <?php echo htmlspecialchars(strval($ad['owner_name'])); ?>
                                        <?php else: ?>
                                            Owner not shown
                                        <?php endif; ?>
                                        <?php if (!empty($ad['owner_contact'])): ?>
                                            | Contact: <?php echo htmlspecialchars(strval($ad['owner_contact'])); ?>
                                        <?php endif; ?>
                                        | Order: <?php echo intval($ad['display_order'] ?? 0); ?>
                                    </div>
                                </div>
                                <span class="status-pill <?php echo !empty($ad['is_active']) ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo !empty($ad['is_active']) ? 'Active' : 'Hidden'; ?>
                                </span>
                            </div>

                            <?php if (!empty($ad['image_path'])): ?>
                                <div style="margin-bottom:12px;">
                                    <img src="<?php echo htmlspecialchars(strval($ad['image_path'])); ?>" alt="<?php echo htmlspecialchars(strval($ad['title'] ?? 'Advert image')); ?>" style="width:100%; max-height:180px; object-fit:cover; border-radius:14px; border:1px solid #e5e7eb;">
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($ad['badge_text'])): ?>
                                <div class="ad-badge" style="margin-bottom:10px;"><?php echo htmlspecialchars(strval($ad['badge_text'])); ?></div>
                            <?php endif; ?>

                            <div class="ad-meta" style="color:#334155;"><?php echo nl2br(htmlspecialchars(strval($ad['description'] ?? ''))); ?></div>

                            <div class="metric-pills">
                                <span class="metric-pill">Views: <?php echo intval($ad['view_count'] ?? 0); ?></span>
                                <span class="metric-pill">Clicks: <?php echo intval($ad['click_count'] ?? 0); ?></span>
                                <span class="metric-pill">
                                    CTR:
                                    <?php
                                    $ad_views = intval($ad['view_count'] ?? 0);
                                    $ad_clicks = intval($ad['click_count'] ?? 0);
                                    $ad_ctr = $ad_views > 0 ? round(($ad_clicks / $ad_views) * 100, 1) : 0;
                                    echo htmlspecialchars(number_format($ad_ctr, 1));
                                    ?>%
                                </span>
                            </div>

                            <div class="ad-meta" style="margin-top:10px;">
                                <?php if (!empty($ad['last_viewed_at'])): ?>
                                    Last Viewed: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime(strval($ad['last_viewed_at'])))); ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ad['last_clicked_at'])): ?>
                                <div class="ad-meta" style="margin-top:6px;">
                                    Last Clicked: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime(strval($ad['last_clicked_at'])))); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($ad['link_url'])): ?>
                                <div style="margin-top:10px;">
                                    <a href="<?php echo htmlspecialchars(strval($ad['link_url'])); ?>" target="_blank" rel="noopener noreferrer" style="color:#4f46e5; font-weight:700; text-decoration:none;">Open advert link</a>
                                </div>
                            <?php endif; ?>

                            <div class="actions">
                                <a href="manage_ads.php?edit=<?php echo intval($ad['ad_id'] ?? 0); ?>" class="btn" style="background:#dbeafe; color:#1d4ed8;">Edit</a>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="toggle_ad">
                                    <input type="hidden" name="ad_id" value="<?php echo intval($ad['ad_id'] ?? 0); ?>">
                                    <button type="submit" class="btn <?php echo !empty($ad['is_active']) ? 'btn-warning' : 'btn-success'; ?>">
                                        <?php echo !empty($ad['is_active']) ? 'Hide Advert' : 'Show Advert'; ?>
                                    </button>
                                </form>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this advert?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="delete_ad">
                                    <input type="hidden" name="ad_id" value="<?php echo intval($ad['ad_id'] ?? 0); ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>

