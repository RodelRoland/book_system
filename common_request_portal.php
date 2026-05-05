<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
global $conn;
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
    }
}

function portal_lower(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function portal_extract_level(string $className, string $storedLevel = ''): string {
    $storedLevel = preg_replace('/[^0-9]/', '', $storedLevel);
    if ($storedLevel !== '') {
        return $storedLevel;
    }

    if (preg_match('/(^|[^0-9])([0-9]{3})([^0-9]|$)/', $className, $matches)) {
        return strval($matches[2] ?? '');
    }

    return '';
}

function portal_normalize_program(string $programName, string $className): string {
    $programName = trim($programName);
    if ($programName !== '') {
        return $programName;
    }

    $derived = preg_replace('/\b(level|lvl|hnd)\b/i', '', $className);
    $derived = preg_replace('/[0-9]/', '', $derived);
    $derived = preg_replace('/\s+/', ' ', trim($derived));
    return $derived;
}

$search_term = trim(strval($_GET['q'] ?? ''));
$selected_level = preg_replace('/[^0-9]/', '', strval($_GET['level'] ?? ''));
$selected_program = trim(strval($_GET['program'] ?? ''));
$normalized_search = portal_lower($search_term);
$normalized_program_filter = portal_lower($selected_program);

$all_levels = [];
$all_programs = [];
$reps = [];

$portal_rep_rows = function_exists('cache_get')
    ? cache_get('public_portal_reps', 180, function() use ($conn) {
        $rows = [];
        $rep_stmt = $conn->prepare("SELECT admin_id, full_name, public_display_name, profile_photo_path, class_name, academic_level, program_name, show_on_public_portal
            FROM admins
            WHERE role = 'rep'
              AND is_active = 1
              AND COALESCE(show_on_public_portal, 1) = 1
              AND class_name IS NOT NULL
              AND TRIM(class_name) <> ''
            ORDER BY class_name ASC, COALESCE(NULLIF(public_display_name, ''), full_name) ASC");

        if ($rep_stmt) {
            $rep_stmt->execute();
            $rep_result = $rep_stmt->get_result();
            if ($rep_result) {
                while ($row = $rep_result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            $rep_stmt->close();
        }

        return $rows;
    })
    : [];

foreach ($portal_rep_rows as $row) {
    $class_name = trim(strval($row['class_name'] ?? ''));
    $full_name = trim(strval($row['full_name'] ?? ''));
    $display_name = trim(strval($row['public_display_name'] ?? ''));
    if ($display_name === '') {
        $display_name = $full_name;
    }
    $profile_photo_path = trim(strval($row['profile_photo_path'] ?? ''));
    $level = portal_extract_level($class_name, strval($row['academic_level'] ?? ''));
    $program = portal_normalize_program(strval($row['program_name'] ?? ''), $class_name);
    $search_haystack = portal_lower(trim($class_name . ' ' . $display_name . ' ' . $full_name . ' ' . $program . ' ' . $level));

    if ($level !== '') {
        $all_levels[$level] = $level;
    }
    if ($program !== '') {
        $all_programs[$program] = $program;
    }

    if ($normalized_search !== '' && strpos($search_haystack, $normalized_search) === false) {
        continue;
    }
    if ($selected_level !== '' && $level !== $selected_level) {
        continue;
    }
    if ($normalized_program_filter !== '' && portal_lower($program) !== $normalized_program_filter) {
        continue;
    }

    $reps[] = [
        'admin_id' => intval($row['admin_id'] ?? 0),
        'class_name' => $class_name,
        'full_name' => $full_name,
        'display_name' => $display_name,
        'profile_photo_path' => $profile_photo_path,
        'academic_level' => $level,
        'program_name' => $program,
    ];
}

ksort($all_levels, SORT_NUMERIC);
natcasesort($all_programs);

$grouped_reps = [];
foreach ($reps as $rep) {
    $group_key = $rep['academic_level'] !== '' ? 'Level ' . $rep['academic_level'] : 'Other Classes';
    if (!isset($grouped_reps[$group_key])) {
        $grouped_reps[$group_key] = [];
    }
    $grouped_reps[$group_key][] = $rep;
}

$visible_class_count = count($reps);
$visible_group_count = count($grouped_reps);
$visible_program_count = count($all_programs);

$portal_ads = function_exists('cache_get')
    ? cache_get('public_portal_ads', 120, function() use ($conn) {
        $rows = [];
        $ads_result = @$conn->query("SELECT ad_id, title, description, owner_name, owner_contact, link_url, badge_text, image_path, view_count, click_count
            FROM portal_ads
            WHERE is_active = 1
            ORDER BY display_order ASC, created_at DESC
            LIMIT 20");
        if ($ads_result) {
            while ($ad_row = $ads_result->fetch_assoc()) {
                $rows[] = $ad_row;
            }
        }
        return $rows;
    })
    : [];

$today_key = date('Y-m-d');
$seen_ads = isset($_SESSION['portal_ad_views']) && is_array($_SESSION['portal_ad_views']) ? $_SESSION['portal_ad_views'] : [];
$ads_to_mark_viewed = [];
foreach ($portal_ads as $portal_ad) {
    $ad_id = intval($portal_ad['ad_id'] ?? 0);
    if ($ad_id <= 0) {
        continue;
    }
    if (($seen_ads[$ad_id] ?? '') !== $today_key) {
        $ads_to_mark_viewed[] = $ad_id;
        $seen_ads[$ad_id] = $today_key;
    }
}
$_SESSION['portal_ad_views'] = $seen_ads;

if (!empty($ads_to_mark_viewed)) {
    $ads_to_mark_viewed = array_values(array_unique(array_map('intval', $ads_to_mark_viewed)));
    $id_list = implode(',', $ads_to_mark_viewed);
    if ($id_list !== '') {
        $conn->query("UPDATE portal_ads SET view_count = view_count + 1, last_viewed_at = NOW() WHERE ad_id IN ($id_list)");
    }
}
$portal_ads_loop = count($portal_ads) > 1 ? array_merge($portal_ads, $portal_ads) : $portal_ads;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book System | Common Request Portal</title>
    <style>
        :root {
            --bg: #f2f5f9;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --surface-tint: #edf2fb;
            --surface-gold: #fff6e8;
            --text: #102132;
            --muted: #657488;
            --line: #d8e0ea;
            --navy: #102c6a;
            --blue: #2550c4;
            --teal: #11836d;
            --gold: #cb7f14;
            --danger: #d3465a;
            --shadow: 0 24px 54px rgba(16, 33, 50, 0.08);
            --radius-xl: 30px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            min-height: 100vh;
            padding: 18px 16px 34px;
            background:
                radial-gradient(circle at top left, rgba(37, 80, 196, 0.10), transparent 24%),
                radial-gradient(circle at top right, rgba(203, 127, 20, 0.10), transparent 18%),
                linear-gradient(180deg, #fbfcfe 0%, var(--bg) 56%, #edf2f8 100%);
        }

        .shell {
            width: min(1380px, calc(100vw - 32px));
            margin: 0 auto;
        }

        .masthead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
            padding: 12px 6px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            font-size: 18px;
            font-weight: 900;
            color: #fff;
            background:
                linear-gradient(135deg, rgba(255,255,255,0.18), transparent 48%),
                linear-gradient(135deg, var(--navy) 0%, var(--blue) 70%, var(--teal) 100%);
            box-shadow: 0 14px 26px rgba(37, 80, 196, 0.24);
        }

        .brand-copy strong {
            display: block;
            font-size: 15px;
            letter-spacing: -0.02em;
            color: var(--text);
        }

        .brand-copy span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .masthead-note {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(16, 32, 51, 0.07);
            color: #34445d;
            font: 800 11px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 34px;
            padding: clamp(24px, 4vw, 40px);
            margin-bottom: 22px;
            background:
                linear-gradient(135deg, rgba(255,255,255,0.12), transparent 34%),
                radial-gradient(circle at 82% 24%, rgba(255,255,255,0.20), transparent 22%),
                linear-gradient(135deg, #0c2155 0%, #173785 42%, #1f4ebf 70%, #11836d 100%);
            box-shadow: 0 28px 60px rgba(12, 33, 85, 0.24);
        }

        .hero::before,
        .hero::after {
            content: '';
            position: absolute;
            pointer-events: none;
        }

        .hero::before {
            right: -80px;
            top: 50px;
            width: 300px;
            height: 300px;
            border-radius: 46px;
            transform: rotate(12deg);
            background: rgba(255,255,255,0.08);
        }

        .hero::after {
            left: 48%;
            bottom: -120px;
            width: 360px;
            height: 360px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.8fr);
            gap: 26px;
            align-items: center;
        }

        .hero-copy {
            color: #fff;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.18);
            background: rgba(255,255,255,0.10);
            font: 800 11px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero-copy h1 {
            max-width: 7ch;
            margin-top: 18px;
            font: 700 clamp(42px, 6vw, 76px)/0.92 Georgia, 'Times New Roman', serif;
            line-height: 0.92;
            letter-spacing: -0.055em;
        }

        .hero-copy p {
            margin-top: 16px;
            max-width: 480px;
            color: rgba(255,255,255,0.9);
            font: 500 16px/1.75 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .hero-summary {
            display: grid;
            gap: 12px;
        }

        .hero-panel {
            padding: 18px 20px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.16);
            background: rgba(255,255,255,0.10);
            backdrop-filter: blur(12px);
            color: #fff;
        }

        .hero-panel-label {
            display: block;
            margin-bottom: 10px;
            font: 800 11px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.88;
        }

        .hero-panel strong {
            display: block;
            font: 700 42px/0.98 Georgia, 'Times New Roman', serif;
            letter-spacing: -0.05em;
            margin-bottom: 6px;
        }

        .hero-panel span {
            display: block;
            font: 500 13px/1.6 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: rgba(255,255,255,0.86);
        }

        .hero-panel-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(260px, 300px) minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }

        .content-grid.with-ads {
            grid-template-columns: minmax(260px, 300px) minmax(0, 1fr) minmax(270px, 315px);
        }

        .panel,
        .ads-rail {
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(16, 32, 51, 0.07);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        .panel {
            padding: 24px;
        }

        .filters {
            position: sticky;
            top: 18px;
        }

        .step-stack {
            display: grid;
            gap: 14px;
        }

        .step-card {
            border: 1px solid rgba(16, 33, 50, 0.07);
            border-radius: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
            padding: 18px;
        }

        .step-card.is-hidden {
            display: none;
        }

        .step-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--surface-tint);
            color: var(--blue);
            font: 800 13px/1 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .step-title {
            font: 800 15px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 16px;
        }

        .section-eyebrow {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--surface-tint);
            color: var(--blue);
            font: 800 11px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .panel-head strong {
            color: var(--text);
            font: 800 14px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: -0.02em;
        }

        .filter-form {
            display: grid;
            gap: 12px;
        }

        .field,
        .select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 15px;
            background: #fff;
            color: var(--text);
            font: 500 14px/1.4 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .field:focus,
        .select:focus {
            outline: none;
            border-color: rgba(36, 81, 198, 0.42);
            box-shadow: 0 0 0 4px rgba(36, 81, 198, 0.10);
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .chip-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .chip {
            width: 100%;
            padding: 14px 12px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
            font: 800 13px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.02em;
        }

        .chip:hover {
            transform: translateY(-1px);
            border-color: rgba(37, 80, 196, 0.22);
        }

        .chip.active {
            background: linear-gradient(135deg, var(--navy) 0%, var(--blue) 100%);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 14px 24px rgba(37, 80, 196, 0.18);
        }

        .btn-primary,
        .btn-secondary,
        .btn-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 16px;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            font: 800 13px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-primary:hover,
        .btn-secondary:hover,
        .btn-cta:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            padding: 14px 16px;
            background: linear-gradient(135deg, var(--navy) 0%, var(--blue) 100%);
            color: #fff;
            box-shadow: 0 16px 26px rgba(36, 81, 198, 0.18);
        }

        .btn-secondary {
            padding: 13px 16px;
            background: var(--surface-tint);
            color: var(--blue);
        }

        .filter-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 16px;
        }

        .filter-stat {
            padding: 16px;
            border-radius: 18px;
            background: var(--surface-soft);
            border: 1px solid rgba(16, 32, 51, 0.06);
        }

        .filter-stat strong {
            display: block;
            font-size: 30px;
            line-height: 1;
            letter-spacing: -0.05em;
            margin-bottom: 6px;
        }

        .filter-stat span {
            display: block;
            color: var(--muted);
            font: 800 11px/1.45 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .results-head {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .results-title h2 {
            font-size: clamp(28px, 3vw, 40px);
            font-family: Georgia, 'Times New Roman', serif;
            line-height: 0.95;
            letter-spacing: -0.05em;
            margin-top: 8px;
        }

        .results-title p {
            margin-top: 10px;
            color: var(--muted);
            font: 500 14px/1.65 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .results-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            border-radius: 999px;
            background: var(--surface-soft);
            color: #3d4b60;
            font: 800 11px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .level-section + .level-section {
            margin-top: 24px;
        }

        .level-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 10px;
            margin-bottom: 12px;
            border-bottom: 1px solid rgba(16, 32, 51, 0.07);
        }

        .level-head h3 {
            font-size: 18px;
            letter-spacing: -0.03em;
        }

        .level-count {
            min-width: 42px;
            padding: 6px 11px;
            border-radius: 999px;
            background: var(--surface-tint);
            color: var(--blue);
            text-align: center;
            font: 800 12px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .class-list {
            display: grid;
            gap: 12px;
        }

        .class-row {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(170px, .9fr) auto;
            gap: 16px;
            align-items: center;
            padding: 18px 18px 18px 20px;
            border-radius: 22px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid rgba(16, 32, 51, 0.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .class-row:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(16, 32, 51, 0.07);
            border-color: rgba(36, 81, 198, 0.16);
        }

        .class-main {
            min-width: 0;
        }

        .class-main h4 {
            font-size: 22px;
            font-family: Georgia, 'Times New Roman', serif;
            line-height: 1.05;
            letter-spacing: -0.04em;
            margin-bottom: 8px;
        }

        .class-tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            background: var(--surface-tint);
            color: var(--blue);
            font: 800 11px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .tag.level {
            background: rgba(15, 138, 114, 0.10);
            color: var(--green);
        }

        .class-side {
            min-width: 0;
        }

        .rep-preview {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .rep-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(16, 44, 106, 0.10) 0%, rgba(37, 80, 196, 0.14) 100%);
            border: 2px solid rgba(37, 80, 196, 0.10);
            color: var(--navy);
            font: 800 14px/1 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.04em;
            background-size: cover;
            background-position: center;
            overflow: hidden;
        }

        .rep-avatar.has-photo {
            color: transparent;
            border-color: rgba(17, 131, 109, 0.22);
            box-shadow: 0 12px 22px rgba(16, 33, 50, 0.10);
        }

        .class-side strong {
            display: block;
            color: var(--text);
            font: 800 14px/1.45 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin-bottom: 4px;
        }

        .class-side span {
            display: block;
            color: var(--muted);
            font: 500 13px/1.55 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-cta {
            padding: 14px 18px;
            white-space: nowrap;
            background: linear-gradient(135deg, var(--navy) 0%, var(--blue) 100%);
            color: #fff;
            box-shadow: 0 14px 24px rgba(36, 81, 198, 0.16);
        }

        .load-more-wrap {
            margin-top: 14px;
            display: flex;
            justify-content: center;
        }

        .selected-card {
            display: none;
            margin-top: 18px;
            padding: 18px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(16, 44, 106, 0.05) 0%, rgba(37, 80, 196, 0.06) 100%);
            border: 1px solid rgba(37, 80, 196, 0.12);
        }

        .selected-card.is-visible {
            display: block;
        }

        .selected-card strong {
            display: block;
            margin-bottom: 10px;
            font: 800 16px/1.3 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--navy);
        }

        .selected-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        .selected-item {
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(16, 33, 50, 0.06);
        }

        .selected-item > span {
            display: block;
            color: var(--muted);
            font: 800 10px/1.3 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .selected-item strong {
            margin: 0;
            color: var(--text);
            font: 700 15px/1.35 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .selected-rep-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .selected-rep-wrap strong {
            margin-bottom: 0;
        }

        .empty {
            padding: 48px 22px;
            text-align: center;
            border-radius: 26px;
            border: 1px dashed rgba(16, 32, 51, 0.16);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .empty strong {
            display: block;
            font-size: 24px;
            font-family: Georgia, 'Times New Roman', serif;
            letter-spacing: -0.03em;
            margin-bottom: 8px;
        }

        .empty span {
            color: var(--muted);
            font: 500 14px/1.7 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .ads-rail {
            position: sticky;
            top: 18px;
            overflow: hidden;
            padding: 22px;
            background:
                radial-gradient(circle at top right, rgba(255,255,255,0.55), transparent 20%),
                linear-gradient(180deg, #fffdf8 0%, #fff4e3 100%);
            border-color: rgba(217, 138, 28, 0.18);
        }

        .ads-head {
            margin-bottom: 16px;
        }

        .ads-head h2 {
            font-size: 26px;
            font-family: Georgia, 'Times New Roman', serif;
            line-height: 0.98;
            letter-spacing: -0.04em;
            margin-top: 8px;
            color: #573003;
        }

        .ads-count {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.72);
            color: #8a5b12;
            font: 800 11px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.07em;
            text-transform: uppercase;
        }

        .ads-window {
            position: relative;
            height: 650px;
            overflow: hidden;
            mask-image: linear-gradient(to bottom, transparent 0%, #000 7%, #000 93%, transparent 100%);
            -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 7%, #000 93%, transparent 100%);
        }

        .ads-scroller {
            display: grid;
            gap: 14px;
            animation: portalAdsUp 44s linear infinite;
        }

        .ads-window:hover .ads-scroller {
            animation-play-state: paused;
        }

        .ad-link-wrap {
            color: inherit;
            text-decoration: none;
        }

        .ad-card {
            overflow: hidden;
            border-radius: 22px;
            background: rgba(255,255,255,0.96);
            border: 1px solid rgba(93, 56, 3, 0.10);
            box-shadow: 0 14px 26px rgba(123, 85, 24, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .ad-link-wrap:hover .ad-card {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(123, 85, 24, 0.12);
        }

        .ad-image {
            width: 100%;
            height: 164px;
            object-fit: cover;
            background: #ead8bd;
        }

        .ad-body {
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .ad-badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 7px 11px;
            border-radius: 999px;
            background: var(--gold-soft);
            color: #8a5b12;
            font: 800 10px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .ad-title {
            font-size: 20px;
            font-family: Georgia, 'Times New Roman', serif;
            line-height: 1.06;
            letter-spacing: -0.03em;
            color: #4f2d05;
        }

        .ad-copy {
            color: #71593c;
            font: 500 13px/1.7 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .ad-meta {
            display: grid;
            gap: 4px;
            color: #4b5565;
            font: 700 12px/1.55 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .ad-visit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #9b5b09;
            font: 800 12px/1.2 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .footer-note {
            margin-top: 18px;
            text-align: center;
            color: #6b7a90;
            font: 500 12px/1.6 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        @keyframes portalAdsUp {
            from { transform: translateY(0); }
            to { transform: translateY(-50%); }
        }

        @media (max-width: 1180px) {
            .hero-copy h1 {
                max-width: 9ch;
            }
        }

        @media (max-width: 980px) {
            .hero-grid {
                grid-template-columns: 1fr;
            }

            .content-grid,
            .content-grid.with-ads {
                grid-template-columns: 1fr;
            }

            .filters,
            .ads-rail {
                position: static;
            }

            .ads-window {
                height: auto;
                overflow: visible;
                mask-image: none;
                -webkit-mask-image: none;
            }

            .ads-scroller {
                animation: none;
            }
        }

        @media (max-width: 760px) {
            body {
                padding: 12px 10px 28px;
            }

            .shell {
                width: min(100%, calc(100vw - 20px));
            }

            .masthead {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero {
                border-radius: 24px;
                padding: 22px 18px;
            }

            .hero-copy h1 {
                font-size: clamp(38px, 11vw, 52px);
            }

            .hero-panel-row,
            .filter-stats,
            .chip-grid,
            .selected-grid,
            .row {
                grid-template-columns: 1fr;
            }

            .panel,
            .ads-rail {
                padding: 18px;
                border-radius: 22px;
            }

            .class-row {
                grid-template-columns: 1fr;
                align-items: start;
            }

            .btn-cta {
                width: 100%;
            }

            .results-head {
                align-items: start;
            }

            .results-meta {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="masthead">
        <div class="brand">
            <div class="brand-mark">BS</div>
            <div class="brand-copy">
                <strong>Book System</strong>
                <span>Common Request Portal</span>
            </div>
        </div>
        <div class="masthead-note">Live directory</div>
    </header>

    <section class="hero">
        <div class="hero-grid">
            <div class="hero-copy">
                <span class="eyebrow">Common Request Portal</span>
                <h1>Select your class.</h1>
                <p>Find the right class page and continue.</p>
            </div>

            <div class="hero-summary">
                <div class="hero-panel">
                    <span class="hero-panel-label">Visible Classes</span>
                    <strong><?php echo $visible_class_count; ?></strong>
                    <span>Available request pages.</span>
                </div>
                <div class="hero-panel-row">
                    <div class="hero-panel">
                        <span class="hero-panel-label">Levels</span>
                        <strong><?php echo count($all_levels); ?></strong>
                        <span>Academic groups.</span>
                    </div>
                    <div class="hero-panel">
                        <span class="hero-panel-label">Programs</span>
                        <strong><?php echo $visible_program_count; ?></strong>
                        <span>Searchable options.</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="content-grid<?php echo !empty($portal_ads) ? ' with-ads' : ''; ?>">
        <aside class="panel filters">
            <div class="panel-head">
                <span class="section-eyebrow">Filters</span>
                <strong><?php echo $visible_class_count; ?> results</strong>
            </div>

            <div class="step-stack">
                <section class="step-card">
                    <div class="step-head">
                        <div class="step-number">1</div>
                        <div class="step-title">Choose level</div>
                    </div>
                    <div id="levelChips" class="chip-grid"></div>
                </section>

                <section id="programStep" class="step-card is-hidden">
                    <div class="step-head">
                        <div class="step-number">2</div>
                        <div class="step-title">Choose program</div>
                    </div>
                    <input id="programSearch" type="text" class="field" placeholder="Search program">
                    <div id="programChips" class="chip-grid" style="margin-top:12px;"></div>
                </section>

                <section id="classStep" class="step-card is-hidden">
                    <div class="step-head">
                        <div class="step-number">3</div>
                        <div class="step-title">Search class</div>
                    </div>
                    <input id="classSearch" type="text" class="field" placeholder="Search class or rep">
                    <div class="filter-stats">
                        <div class="filter-stat">
                            <strong id="resultCount"><?php echo $visible_class_count; ?></strong>
                            <span>Matches</span>
                        </div>
                        <div class="filter-stat">
                            <strong id="selectedLevelLabel">0</strong>
                            <span>Selected level</span>
                        </div>
                    </div>
                </section>

                <button id="resetFinder" type="button" class="btn-secondary">Reset Finder</button>
            </div>
        </aside>

        <main class="panel">
            <div class="results-head">
                <div class="results-title">
                    <span class="section-eyebrow">Directory</span>
                    <h2>Choose your class</h2>
                    <p>Results appear here after you select a level and program.</p>
                </div>
                <div class="results-meta">
                    <span id="levelMeta" class="meta-chip">No level selected</span>
                    <span id="programMeta" class="meta-chip">No program selected</span>
                </div>
            </div>

            <div id="resultsPrompt" class="empty">
                <strong>Start with your level</strong>
                <span>Select a level, then program, then pick your class.</span>
            </div>

            <div id="resultsContainer" style="display:none;"></div>
            <div id="loadMoreWrap" class="load-more-wrap" style="display:none;">
                <button id="loadMoreBtn" type="button" class="btn-secondary">Load More</button>
            </div>

            <div id="selectedCard" class="selected-card">
                <strong>Selected class</strong>
                <div class="selected-grid">
                    <div class="selected-item">
                        <span>Class</span>
                        <strong id="selectedClassName">-</strong>
                    </div>
                    <div class="selected-item">
                        <span>Program</span>
                        <strong id="selectedProgramName">-</strong>
                    </div>
                    <div class="selected-item">
                        <span>Rep</span>
                        <div id="selectedRepWrap" class="selected-rep-wrap">
                            <span id="selectedRepAvatar" class="rep-avatar">RP</span>
                            <strong id="selectedRepName">-</strong>
                        </div>
                    </div>
                </div>
                <a id="selectedContinueBtn" href="#" class="btn-primary" style="width:100%;">Continue to Request</a>
            </div>
        </main>

        <?php if (!empty($portal_ads)): ?>
            <aside class="ads-rail">
                <div class="ads-head">
                    <span class="ads-count"><?php echo count($portal_ads); ?> live listings</span>
                    <h2>Campus spotlight</h2>
                </div>

                <div class="ads-window">
                    <div class="ads-scroller">
                        <?php foreach ($portal_ads_loop as $ad): ?>
                            <?php $has_link = !empty($ad['link_url']); ?>
                            <?php if ($has_link): ?>
                                <a class="ad-link-wrap" href="portal_ad_redirect.php?ad_id=<?php echo intval($ad['ad_id'] ?? 0); ?>" target="_blank" rel="noopener noreferrer">
                            <?php endif; ?>

                            <article class="ad-card">
                                <?php if (!empty($ad['image_path'])): ?>
                                    <img
                                        src="<?php echo htmlspecialchars(strval($ad['image_path'])); ?>"
                                        alt="<?php echo htmlspecialchars(strval($ad['title'] ?? 'Business advert')); ?>"
                                        class="ad-image"
                                    >
                                <?php endif; ?>

                                <div class="ad-body">
                                    <?php if (!empty($ad['badge_text'])): ?>
                                        <span class="ad-badge"><?php echo htmlspecialchars(strval($ad['badge_text'])); ?></span>
                                    <?php endif; ?>

                                    <h3 class="ad-title"><?php echo htmlspecialchars(strval($ad['title'] ?? '')); ?></h3>
                                    <div class="ad-copy"><?php echo htmlspecialchars(strval($ad['description'] ?? '')); ?></div>

                                    <div class="ad-meta">
                                        <?php
                                        $owner_name = trim(strval($ad['owner_name'] ?? ''));
                                        if ($owner_name !== '') {
                                            echo '<span>' . htmlspecialchars($owner_name) . '</span>';
                                        }
                                        ?>
                                        <?php if (!empty($ad['owner_contact'])): ?>
                                            <span><?php echo htmlspecialchars(strval($ad['owner_contact'])); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($has_link): ?>
                                        <span class="ad-visit">Visit business page</span>
                                    <?php endif; ?>
                                </div>
                            </article>

                            <?php if ($has_link): ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        <?php endif; ?>
    </div>

    <div class="footer-note">Direct rep links still work.</div>
</div>
<script>
const repsData = <?php echo json_encode(array_map(static function ($rep) {
    return [
        'admin_id' => intval($rep['admin_id'] ?? 0),
        'class_name' => strval($rep['class_name'] ?? ''),
        'full_name' => strval($rep['full_name'] ?? ''),
        'display_name' => strval($rep['display_name'] ?? ''),
        'profile_photo_path' => strval($rep['profile_photo_path'] ?? ''),
        'academic_level' => strval($rep['academic_level'] ?? ''),
        'program_name' => strval($rep['program_name'] ?: 'General'),
    ];
}, $reps), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const state = {
    level: '',
    program: '',
    query: '',
    selectedId: null,
    limit: 12
};

const els = {
    levelChips: document.getElementById('levelChips'),
    programStep: document.getElementById('programStep'),
    programSearch: document.getElementById('programSearch'),
    programChips: document.getElementById('programChips'),
    classStep: document.getElementById('classStep'),
    classSearch: document.getElementById('classSearch'),
    resultCount: document.getElementById('resultCount'),
    selectedLevelLabel: document.getElementById('selectedLevelLabel'),
    levelMeta: document.getElementById('levelMeta'),
    programMeta: document.getElementById('programMeta'),
    resultsPrompt: document.getElementById('resultsPrompt'),
    resultsContainer: document.getElementById('resultsContainer'),
    loadMoreWrap: document.getElementById('loadMoreWrap'),
    loadMoreBtn: document.getElementById('loadMoreBtn'),
    selectedCard: document.getElementById('selectedCard'),
    selectedClassName: document.getElementById('selectedClassName'),
    selectedProgramName: document.getElementById('selectedProgramName'),
    selectedRepAvatar: document.getElementById('selectedRepAvatar'),
    selectedRepName: document.getElementById('selectedRepName'),
    selectedContinueBtn: document.getElementById('selectedContinueBtn'),
    resetFinder: document.getElementById('resetFinder')
};

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function normalize(value) {
    return String(value || '').trim().toLowerCase();
}

function getInitials(name) {
    const cleaned = String(name || '').trim();
    if (!cleaned) {
        return 'RP';
    }
    return cleaned
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('') || 'RP';
}

function renderAvatar(photoPath, name) {
    const initials = escapeHtml(getInitials(name));
    if (photoPath) {
        return `<span class="rep-avatar has-photo" style="background-image:url('${escapeHtml(photoPath)}');">${initials}</span>`;
    }
    return `<span class="rep-avatar">${initials}</span>`;
}

function updateAvatarElement(element, photoPath, name) {
    if (!element) {
        return;
    }
    element.textContent = getInitials(name);
    element.style.backgroundImage = photoPath ? `url('${String(photoPath).replace(/'/g, "\\'")}')` : '';
    element.classList.toggle('has-photo', !!photoPath);
}

function getLevels() {
    const levelSet = new Set();
    repsData.forEach((rep) => {
        levelSet.add(rep.academic_level || 'Other');
    });
    return Array.from(levelSet).sort((a, b) => {
        if (a === 'Other') return 1;
        if (b === 'Other') return -1;
        return parseInt(a, 10) - parseInt(b, 10);
    });
}

function getProgramsForLevel(level) {
    const levelPrograms = new Set();
    repsData.forEach((rep) => {
        const repLevel = rep.academic_level || 'Other';
        if (repLevel === level) {
            levelPrograms.add(rep.program_name || 'General');
        }
    });
    return Array.from(levelPrograms).sort((a, b) => a.localeCompare(b));
}

function getFilteredResults() {
    return repsData.filter((rep) => {
        const repLevel = rep.academic_level || 'Other';
        const repProgram = rep.program_name || 'General';
        const matchesLevel = state.level !== '' ? repLevel === state.level : true;
        const matchesProgram = state.program !== '' ? repProgram === state.program : true;
        const repName = rep.display_name || rep.full_name || '';
        const haystack = normalize(rep.class_name + ' ' + repName + ' ' + rep.full_name + ' ' + repProgram + ' ' + repLevel);
        const matchesQuery = state.query !== '' ? haystack.includes(normalize(state.query)) : true;
        return matchesLevel && matchesProgram && matchesQuery;
    });
}

function renderLevels() {
    const levels = getLevels();
    els.levelChips.innerHTML = levels.map((level) => {
        const label = level === 'Other' ? 'Other' : 'Level ' + level;
        const active = state.level === level ? ' active' : '';
        return `<button type="button" class="chip${active}" data-level="${escapeHtml(level)}">${escapeHtml(label)}</button>`;
    }).join('');

    els.levelChips.querySelectorAll('[data-level]').forEach((button) => {
        button.addEventListener('click', () => {
            const chosenLevel = button.getAttribute('data-level') || '';
            state.level = chosenLevel;
            state.program = '';
            state.query = '';
            state.selectedId = null;
            state.limit = 12;
            els.classSearch.value = '';
            els.programSearch.value = '';
            updateFinder();
        });
    });
}

function renderPrograms() {
    if (!state.level) {
        els.programStep.classList.add('is-hidden');
        els.programChips.innerHTML = '';
        return;
    }

    const searchValue = normalize(els.programSearch.value);
    const programs = getProgramsForLevel(state.level).filter((program) => normalize(program).includes(searchValue));
    els.programStep.classList.remove('is-hidden');
    els.programChips.innerHTML = programs.map((program) => {
        const active = state.program === program ? ' active' : '';
        return `<button type="button" class="chip${active}" data-program="${escapeHtml(program)}">${escapeHtml(program)}</button>`;
    }).join('');

    els.programChips.querySelectorAll('[data-program]').forEach((button) => {
        button.addEventListener('click', () => {
            state.program = button.getAttribute('data-program') || '';
            state.query = '';
            state.selectedId = null;
            state.limit = 12;
            els.classSearch.value = '';
            updateFinder();
        });
    });
}

function renderResults() {
    const hasLevel = !!state.level;
    const hasProgram = !!state.program;

    els.levelMeta.textContent = hasLevel ? (state.level === 'Other' ? 'Other' : 'Level ' + state.level) : 'No level selected';
    els.programMeta.textContent = hasProgram ? state.program : 'No program selected';
    els.selectedLevelLabel.textContent = hasLevel ? (state.level === 'Other' ? 'Other' : state.level) : '0';

    if (!hasLevel) {
        els.classStep.classList.add('is-hidden');
        els.resultsPrompt.style.display = 'block';
        els.resultsPrompt.innerHTML = '<strong>Start with your level</strong><span>Select a level, then program, then pick your class.</span>';
        els.resultsContainer.style.display = 'none';
        els.loadMoreWrap.style.display = 'none';
        return;
    }

    if (!hasProgram) {
        els.classStep.classList.add('is-hidden');
        els.resultsPrompt.style.display = 'block';
        els.resultsPrompt.innerHTML = '<strong>Choose your program</strong><span>Program choices will narrow the class list immediately.</span>';
        els.resultsContainer.style.display = 'none';
        els.loadMoreWrap.style.display = 'none';
        return;
    }

    els.classStep.classList.remove('is-hidden');
    const results = getFilteredResults();
    els.resultCount.textContent = results.length;

    if (!results.length) {
        els.resultsPrompt.style.display = 'block';
        els.resultsPrompt.innerHTML = '<strong>No matching classes</strong><span>Try another search or reset the finder.</span>';
        els.resultsContainer.style.display = 'none';
        els.loadMoreWrap.style.display = 'none';
        return;
    }

    const visible = results.slice(0, state.limit);
    els.resultsPrompt.style.display = 'none';
    els.resultsContainer.style.display = 'block';
    els.resultsContainer.innerHTML = `
        <section class="level-section">
            <div class="level-head">
                <h3>${escapeHtml(state.program)}</h3>
                <span class="level-count">${results.length}</span>
            </div>
            <div class="class-list">
                ${visible.map((rep) => `
                    <article class="class-row">
                        <div class="class-main">
                            <h4>${escapeHtml(rep.class_name)}</h4>
                            <div class="class-tags">
                                ${rep.academic_level ? `<span class="tag level">Level ${escapeHtml(rep.academic_level)}</span>` : ''}
                                <span class="tag">${escapeHtml(rep.program_name || 'General')}</span>
                            </div>
                        </div>
                        <div class="class-side">
                            <div class="rep-preview">
                                ${renderAvatar(rep.profile_photo_path, rep.display_name || rep.full_name)}
                                <div>
                                    <strong>${escapeHtml(rep.display_name || rep.full_name)}</strong>
                                    <span>Assigned rep</span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-cta select-rep-btn" data-id="${rep.admin_id}">Select</button>
                    </article>
                `).join('')}
            </div>
        </section>
    `;

    els.resultsContainer.querySelectorAll('.select-rep-btn').forEach((button) => {
        button.addEventListener('click', () => {
            state.selectedId = parseInt(button.getAttribute('data-id') || '0', 10);
            renderSelectedCard();
        });
    });

    els.loadMoreWrap.style.display = results.length > state.limit ? 'flex' : 'none';
}

function renderSelectedCard() {
    const selected = repsData.find((rep) => rep.admin_id === state.selectedId);
    if (!selected) {
        els.selectedCard.classList.remove('is-visible');
        return;
    }

    els.selectedClassName.textContent = selected.class_name;
    els.selectedProgramName.textContent = selected.program_name || 'General';
    els.selectedRepName.textContent = selected.display_name || selected.full_name;
    updateAvatarElement(els.selectedRepAvatar, selected.profile_photo_path, selected.display_name || selected.full_name);
    els.selectedContinueBtn.href = `index.php?rep_id=${selected.admin_id}`;
    els.selectedCard.classList.add('is-visible');
}

function updateFinder() {
    renderLevels();
    renderPrograms();
    renderResults();
    renderSelectedCard();
}

els.programSearch.addEventListener('input', () => {
    renderPrograms();
});

els.classSearch.addEventListener('input', (event) => {
    state.query = event.target.value || '';
    state.limit = 12;
    state.selectedId = null;
    renderResults();
    renderSelectedCard();
});

els.loadMoreBtn.addEventListener('click', () => {
    state.limit += 12;
    renderResults();
});

els.resetFinder.addEventListener('click', () => {
    state.level = '';
    state.program = '';
    state.query = '';
    state.selectedId = null;
    state.limit = 12;
    els.programSearch.value = '';
    els.classSearch.value = '';
    updateFinder();
});

updateFinder();
</script>
</body>
</html>

