<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClassBookHub Lecturer Portal</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f4f7ff;
            --surface: #ffffff;
            --line: #e4ebf5;
            --ink: #10213a;
            --muted: #63738d;
            --blue: #1454ff;
            --blue-deep: #0a2f7c;
            --green: #21b86d;
            --red: #ff4d4f;
            --shadow: 0 18px 42px rgba(19, 38, 73, 0.1);
        }
        html { scroll-behavior: smooth; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(180deg, #fbfdff 0%, #eef4fb 100%);
            color: var(--ink);
            padding: 16px 14px 116px;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .lecturer-shell {
            width: min(100%, 480px);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }
        .alert {
            border-radius: 18px;
            padding: 14px 16px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 12px 30px rgba(19, 38, 73, 0.08);
        }
        .alert-success { background: #ecfbf3; color: #0f8a50; border: 1px solid #c9f0d8; }
        .alert-error { background: #fff1f1; color: #c73232; border: 1px solid #ffd5d5; }
        .screen,
        .subscreen {
            display: none;
            gap: 16px;
        }
        .screen.is-active,
        .subscreen.is-active { display: grid; }
        .hidden { display: none !important; }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .topbar-left,
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .icon-button,
        .mini-action {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.9);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--ink);
            text-decoration: none;
            box-shadow: 0 10px 24px rgba(19, 38, 73, 0.06);
            cursor: pointer;
        }
        .mini-action {
            width: auto;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 700;
        }
        .icon-button svg { width: 18px; height: 18px; }
        .screen-title {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .screen-subtitle {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
            margin-top: 4px;
        }
        .hero-card {
            background: linear-gradient(160deg, #0b2b77 0%, #123f98 58%, #194be5 100%);
            color: #fff;
            border-radius: 28px;
            padding: 18px 18px 20px;
            box-shadow: 0 24px 42px rgba(17, 53, 126, 0.28);
            display: grid;
            gap: 16px;
        }
        .hero-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }
        .hero-copy small {
            display: block;
            font-size: 13px;
            opacity: 0.88;
            margin-bottom: 6px;
        }
        .hero-copy h1 {
            font-size: 18px;
            line-height: 1.22;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .hero-copy p {
            font-size: 13px;
            line-height: 1.55;
            opacity: 0.86;
        }
        .avatar {
            width: 62px;
            height: 62px;
            border-radius: 20px;
            background: linear-gradient(180deg, rgba(255,255,255,0.95), rgba(215,228,255,0.94));
            color: #1b53de;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 800;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
        }
        .semester-chip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.96);
            color: #11284f;
            font-size: 13px;
            font-weight: 700;
        }
        .semester-chip .hint {
            color: #5b6992;
            font-size: 12px;
            font-weight: 600;
        }
        .section-block {
            display: grid;
            gap: 12px;
        }
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .section-head h2 {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .section-head a,
        .section-head button {
            border: none;
            background: none;
            color: var(--blue);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .overview-grid,
        .quick-grid,
        .report-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .rep-list-grid {
            display: grid;
            gap: 12px;
        }
        .overview-card,
        .quick-card,
        .simple-card,
        .course-card,
        .rep-card,
        .report-card,
        .level-card,
        .profile-hero,
        .screen-panel,
        .form-panel,
        .analytics-hero,
        .card-rail {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
        }
        .overview-card {
            padding: 16px;
            color: #fff;
            display: grid;
            gap: 10px;
            min-height: 122px;
        }
        .overview-card .metric-label {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            opacity: 0.95;
        }
        .overview-card .metric-value {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.04em;
            line-height: 0.95;
        }
        .overview-card .metric-foot {
            font-size: 13px;
            opacity: 0.92;
        }
        .overview-card.blue { background: linear-gradient(145deg, #1d6fff, #1454ff); }
        .overview-card.green { background: linear-gradient(145deg, #2bcf84, #1dbd6e); }
        .overview-card.purple { background: linear-gradient(145deg, #8856f8, #733ef0); }
        .overview-card.orange { background: linear-gradient(145deg, #ffa136, #ff8614); }
        .quick-card {
            padding: 16px;
            display: grid;
            gap: 6px;
            text-align: left;
            color: var(--ink);
            cursor: pointer;
            border: 1px solid var(--line);
        }
        .icon-badge {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
        }
        .icon-badge.green { background: #e6f8ed; color: #128a4f; }
        .icon-badge.blue { background: #e9f0ff; color: #1d56df; }
        .icon-badge.purple { background: #f0eaff; color: #7642ee; }
        .icon-badge.orange { background: #fff1e0; color: #f47b0b; }
        .icon-badge.pink { background: #ffedf5; color: #e43f8c; }
        .quick-card strong,
        .simple-card strong,
        .course-card strong,
        .payment-row strong,
        .rep-card strong,
        .list-link strong {
            font-size: 14px;
            line-height: 1.35;
        }
        .quick-card span,
        .simple-card span,
        .course-card span,
        .payment-row span,
        .list-link span {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }
        .card-rail { overflow: hidden; }
        .payment-row,
        .list-link {
            display: grid;
            gap: 4px;
            padding: 14px 16px;
            text-decoration: none;
            color: var(--ink);
            border-bottom: 1px solid #edf2f8;
        }
        .payment-row:last-child,
        .list-link:last-child { border-bottom: none; }
        .row-inline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 70px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }
        .status-pill.paid { background: #e7faef; color: #179553; }
        .status-pill.pending { background: #fff2de; color: #e07700; }
        .search-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.96);
            font-size: 13px;
            color: var(--ink);
        }
        .search-input:focus {
            outline: none;
            border-color: rgba(20, 84, 255, 0.45);
            box-shadow: 0 0 0 4px rgba(20, 84, 255, 0.08);
        }
        .course-card {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 14px;
            align-items: start;
            padding: 16px;
            color: inherit;
            text-decoration: none;
        }
        .course-meta {
            display: grid;
            gap: 3px;
        }
        .course-meta small {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.4;
        }
        .course-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 10px;
        }
        .course-stats div { display: grid; gap: 3px; }
        .course-stats strong { font-size: 20px; letter-spacing: -0.03em; }
        .course-stats span { color: var(--muted); font-size: 11px; }
        .rep-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            background: #eef3ff;
            color: #315ef0;
            font-size: 12px;
            font-weight: 700;
        }
        .screen-panel,
        .form-panel,
        .analytics-hero {
            padding: 18px;
            display: grid;
            gap: 14px;
        }
        .screen-panel h3 { font-size: 18px; font-weight: 800; letter-spacing: -0.02em; }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .meta-box {
            border-radius: 18px;
            padding: 14px 12px;
            border: 1px solid #ebf0f6;
            background: #fafcff;
            text-align: center;
            display: grid;
            gap: 4px;
        }
        .meta-box span { color: #60708a; font-size: 11px; font-weight: 700; }
        .meta-box strong { font-size: 22px; letter-spacing: -0.03em; }
        .rep-card {
            padding: 16px;
            display: grid;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }
        .rep-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .rep-id {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .rep-avatar {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: linear-gradient(145deg, #29be77, #12a65d);
            color: #fff;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .rep-stat-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .rep-stat-grid div { display: grid; gap: 4px; }
        .rep-stat-grid span { color: #73829a; font-size: 11px; }
        .rep-stat-grid strong { font-size: 20px; letter-spacing: -0.03em; }
        .rep-stat-grid .success { color: #11a25b; }
        .rep-stat-grid .danger { color: #ff4b38; }
        .progress-bar {
            position: relative;
            height: 8px;
            border-radius: 999px;
            background: #edf2f7;
            overflow: hidden;
        }
        .progress-bar span {
            position: absolute;
            inset: 0 auto 0 0;
            border-radius: inherit;
            background: linear-gradient(90deg, #15b463, #34c47d);
        }
        .progress-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .progress-row strong {
            font-size: 12px;
            color: #42607c;
            min-width: 36px;
            text-align: right;
        }
        .detail-menu { display: grid; gap: 10px; }
        .detail-menu a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px;
            border-radius: 18px;
            background: #fff;
            border: 1px solid #eef3fa;
            text-decoration: none;
            color: var(--ink);
            box-shadow: 0 10px 24px rgba(19, 38, 73, 0.05);
        }
        .detail-menu a span { color: var(--muted); font-size: 12px; }
        .report-card {
            padding: 16px;
            display: grid;
            gap: 8px;
            border: 1px solid var(--line);
            text-align: left;
        }
        .report-card p {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }
        .chart-box {
            height: 170px;
            border-radius: 18px;
            background: linear-gradient(180deg, #fbfdff 0%, #f2f7ff 100%);
            border: 1px solid #edf2f9;
            padding: 12px;
        }
        .analytics-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .simple-card {
            padding: 14px;
            display: grid;
            gap: 4px;
        }
        .summary-list { display: grid; gap: 12px; }
        .summary-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: center;
        }
        .summary-item .bar {
            margin-top: 8px;
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: #edf2f7;
            overflow: hidden;
        }
        .summary-item .bar span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #276fff, #1354ff);
        }
        .profile-hero {
            background: linear-gradient(160deg, #0b2b77 0%, #1454ff 100%);
            color: #fff;
            padding: 18px;
            display: grid;
            gap: 12px;
            border: none;
        }
        .profile-identity {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .profile-avatar {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            background: rgba(255,255,255,0.96);
            color: #1652da;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 20px;
        }
        .settings-list { display: grid; gap: 10px; }
        .settings-list .logout { color: #d93030; }
        .badge-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .pill-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #eef3ff;
            color: #2557de;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }
        .form-group { display: grid; gap: 6px; }
        label {
            font-size: 12px;
            font-weight: 700;
            color: #42526d;
        }
        input[type="text"],
        input[type="number"],
        input[type="date"],
        input[type="search"],
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
            padding: 12px 14px;
            font-size: 13px;
            font-family: inherit;
            color: var(--ink);
        }
        textarea { min-height: 88px; resize: vertical; }
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: rgba(20, 84, 255, 0.45);
            box-shadow: 0 0 0 4px rgba(20, 84, 255, 0.08);
        }
        .primary-btn,
        .secondary-btn,
        .ghost-link {
            border: none;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .primary-btn {
            background: linear-gradient(145deg, #1b5bff, #1454ff);
            color: #fff;
            min-height: 46px;
            box-shadow: 0 16px 26px rgba(20, 84, 255, 0.22);
        }
        .secondary-btn {
            min-height: 44px;
            background: #f4f7fc;
            color: var(--ink);
            border: 1px solid var(--line);
            padding: 0 16px;
        }
        .ghost-link {
            color: var(--blue);
            background: transparent;
        }
        .chip-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-chip {
            border: 1px solid var(--line);
            background: #f6f8fb;
            color: #4d5f7c;
            border-radius: 12px;
            min-height: 38px;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .filter-chip.is-active {
            background: linear-gradient(145deg, #1b5bff, #1454ff);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 12px 22px rgba(20, 84, 255, 0.18);
        }
        .empty-state {
            padding: 24px 18px;
            text-align: center;
            color: var(--muted);
            background: rgba(255,255,255,0.7);
            border: 1px dashed #d7e0ee;
            border-radius: 20px;
        }
        .bottom-nav {
            position: fixed;
            left: 50%;
            transform: translateX(-50%);
            bottom: 14px;
            width: min(calc(100% - 28px), 480px);
            padding: 10px 12px;
            border-radius: 24px;
            background: rgba(255,255,255,0.97);
            border: 1px solid rgba(219, 228, 240, 0.96);
            box-shadow: 0 20px 38px rgba(15, 23, 42, 0.16);
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            z-index: 60;
        }
        .bottom-tab {
            border: none;
            background: transparent;
            color: #5e6d86;
            border-radius: 16px;
            min-height: 58px;
            display: grid;
            align-content: center;
            justify-items: center;
            gap: 4px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
        }
        .bottom-tab.is-active {
            color: #1454ff;
        }
        .bottom-tab.is-active {
            background: rgba(20, 84, 255, 0.08);
        }
        @media (min-width: 760px) {
            body { padding: 28px 24px 128px; }
            .lecturer-shell { width: min(100%, 1120px); }
            .overview-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .quick-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .report-grid,
            .rep-list-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .bottom-nav { width: min(calc(100% - 48px), 920px); }
        }
    </style>
</head>
<body>
<div class="lecturer-shell">
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <section class="screen" data-screen="home">
        <div class="hero-card">
            <div class="topbar">
                <div class="topbar-left">
                    <button type="button" class="icon-button" data-open-screen="profile" aria-label="Open profile">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                </div>
                <div class="topbar-right">
                    <a href="common_request_portal.php" class="mini-action">Portal</a>
                    <button type="button" class="icon-button" data-open-subscreen="payments" aria-label="Open payments">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0v5l-2 3h16l-2-3V8"/><path d="M10.5 20a1.5 1.5 0 0 0 3 0"/></svg>
                    </button>
                </div>
            </div>
            <div class="hero-head">
                <div class="hero-copy">
                    <small>Good morning,</small>
                    <h1><?php echo htmlspecialchars($lecturer_name); ?></h1>
                    <p>Lecturer portal for courses, representatives, requests, reports and collections.</p>
                </div>
                <div class="avatar"><?php echo htmlspecialchars($safe_lecturer_initials !== '' ? $safe_lecturer_initials : 'CB'); ?></div>
            </div>
            <div class="semester-chip">
                <div>
                    <div><?php echo htmlspecialchars($semester_label); ?></div>
                    <div class="hint"><?php echo htmlspecialchars($active_levels_label); ?></div>
                </div>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </div>
        </div>

        <div class="section-block">
            <div class="section-head"><h2>Overview</h2></div>
            <div class="overview-grid">
                <div class="overview-card blue">
                    <div class="metric-label"><span>Courses</span><span>Co</span></div>
                    <div class="metric-value"><?php echo number_format(count($course_cards)); ?></div>
                    <div class="metric-foot">Registered materials</div>
                </div>
                <div class="overview-card green">
                    <div class="metric-label"><span>Reps</span><span>Rp</span></div>
                    <div class="metric-value"><?php echo number_format(count($reps_list)); ?></div>
                    <div class="metric-foot">Active representatives</div>
                </div>
                <div class="overview-card purple">
                    <div class="metric-label"><span>Requests</span><span>Rq</span></div>
                    <div class="metric-value"><?php echo number_format($home_total_requests); ?></div>
                    <div class="metric-foot">Across your courses</div>
                </div>
                <div class="overview-card orange">
                    <div class="metric-label"><span>Outstanding</span><span>Gh</span></div>
                    <div class="metric-value">GH&#8373;<?php echo number_format($home_total_outstanding, 0); ?></div>
                    <div class="metric-foot">Pending lecturer settlements</div>
                </div>
            </div>
        </div>

        <div class="section-block">
            <div class="section-head"><h2>Quick Actions</h2></div>
            <div class="quick-grid">
                <button type="button" class="quick-card" data-open-subscreen="payments">
                    <span class="icon-badge green">GH</span>
                    <strong>Payments</strong>
                    <span>View recent collections</span>
                </button>
                <button type="button" class="quick-card" data-open-screen="reps">
                    <span class="icon-badge blue">RP</span>
                    <strong>Representatives</strong>
                    <span>Manage rep activity</span>
                </button>
                <button type="button" class="quick-card" data-open-screen="courses">
                    <span class="icon-badge purple">BK</span>
                    <strong>Courses</strong>
                    <span>Track requests and books</span>
                </button>
                <button type="button" class="quick-card" data-open-subscreen="analytics">
                    <span class="icon-badge orange">AN</span>
                    <strong>Analytics</strong>
                    <span>See summaries at a glance</span>
                </button>
            </div>
        </div>

        <div class="section-block">
            <div class="section-head">
                <h2>Recent Payments</h2>
                <button type="button" data-open-subscreen="payments">View all</button>
            </div>
            <div class="card-rail">
                <?php if ($recent_home_payments): ?>
                    <?php foreach ($recent_home_payments as $payment_row): ?>
                        <div class="payment-row">
                            <div class="row-inline">
                                <div>
                                    <strong><?php echo htmlspecialchars(strval($payment_row['rep_name'] ?? 'Unknown Rep')); ?></strong>
                                    <span><?php echo htmlspecialchars(date('M d, Y • g:i A', strtotime(strval($payment_row['payment_date'] ?? 'now')))); ?></span>
                                </div>
                                <div style="text-align:right;">
                                    <strong>GH&#8373; <?php echo number_format(floatval($payment_row['amount_paid'] ?? 0), 2); ?></strong>
                                    <div class="status-pill paid" style="margin-top:6px;">Paid</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No lecturer payments have been recorded yet for the active semester.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="screen" data-screen="courses">
        <?php if ($selected_course_summary): ?>
            <?php include __DIR__ . '/lecturer_dashboard_shell_courses_detail.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/lecturer_dashboard_shell_courses_list.php'; ?>
        <?php endif; ?>
    </section>

    <section class="screen" data-screen="reps">
        <?php if ($selected_rep_card): ?>
            <?php include __DIR__ . '/lecturer_dashboard_shell_reps_detail.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/lecturer_dashboard_shell_reps_list.php'; ?>
        <?php endif; ?>
    </section>

    <section class="screen" data-screen="reports">
        <?php include __DIR__ . '/lecturer_dashboard_shell_reports.php'; ?>
    </section>

    <section class="screen" data-screen="profile">
        <?php include __DIR__ . '/lecturer_dashboard_shell_profile.php'; ?>
    </section>

    <section class="subscreen" data-subscreen="payments">
        <?php include __DIR__ . '/lecturer_dashboard_shell_payments.php'; ?>
    </section>

    <section class="subscreen" data-subscreen="levels">
        <?php include __DIR__ . '/lecturer_dashboard_shell_levels.php'; ?>
    </section>

    <section class="subscreen" data-subscreen="analytics">
        <?php include __DIR__ . '/lecturer_dashboard_shell_analytics.php'; ?>
    </section>
</div>

<nav class="bottom-nav" aria-label="Lecturer navigation">
    <button type="button" class="bottom-tab" data-open-screen="home"><span>Home</span></button>
    <button type="button" class="bottom-tab" data-open-screen="courses"><span>Courses</span></button>
    <button type="button" class="bottom-tab" data-open-screen="reps"><span>Reps</span></button>
    <button type="button" class="bottom-tab" data-open-screen="reports"><span>Reports</span></button>
    <button type="button" class="bottom-tab" data-open-screen="profile"><span>Profile</span></button>
</nav>

<script>
(function () {
    const screens = Array.from(document.querySelectorAll('.screen'));
    const subscreens = Array.from(document.querySelectorAll('.subscreen'));
    const tabButtons = Array.from(document.querySelectorAll('[data-open-screen]'));
    const subButtons = Array.from(document.querySelectorAll('[data-open-subscreen]'));
    const closeSubButtons = Array.from(document.querySelectorAll('[data-close-subscreen]'));
    const bottomTabs = Array.from(document.querySelectorAll('.bottom-tab'));
    const tabInputs = Array.from(document.querySelectorAll('.js-dashboard-tab'));
    const allowedScreens = ['home', 'courses', 'reps', 'reports', 'profile'];
    const initialScreen = <?php
        $resolved_dashboard_tab = in_array($dashboard_tab, ['home', 'courses', 'reps', 'reports', 'profile'], true)
            ? $dashboard_tab
            : ($rep_id > 0 ? 'reps' : (($selected_assignment_id > 0 || $selected_book_id > 0) ? 'courses' : 'home'));
        echo json_encode($resolved_dashboard_tab);
    ?>;
    const initialSubview = <?php echo json_encode($dashboard_subview); ?>;

    function setTabInputs(activeScreen) {
        tabInputs.forEach((input) => { input.value = activeScreen; });
    }

    function setSubview(subviewName) {
        const isActive = subviewName && subscreens.some((screen) => screen.dataset.subscreen === subviewName);
        subscreens.forEach((screen) => {
            screen.classList.toggle('is-active', isActive && screen.dataset.subscreen === subviewName);
        });
        screens.forEach((screen) => {
            screen.classList.toggle('hidden', isActive);
        });
        document.querySelector('.bottom-nav').classList.toggle('hidden', isActive);
        const url = new URL(window.location.href);
        if (isActive) {
            url.searchParams.set('subview', subviewName);
        } else {
            url.searchParams.delete('subview');
        }
        window.history.replaceState({}, '', url.toString());
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function setScreen(screenName, options = {}) {
        const activeScreen = allowedScreens.includes(screenName) ? screenName : 'home';
        screens.forEach((screen) => {
            screen.classList.toggle('is-active', screen.dataset.screen === activeScreen);
        });
        bottomTabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.openScreen === activeScreen);
        });
        setTabInputs(activeScreen);
        if (!options.preserveSubview) {
            setSubview('');
        }
        if (!options.silent) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', activeScreen);
            window.history.replaceState({}, '', url.toString());
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => setScreen(button.dataset.openScreen || 'home'));
    });
    subButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            setSubview(button.dataset.openSubview || '');
        });
    });
    closeSubButtons.forEach((button) => {
        button.addEventListener('click', () => setSubview(''));
    });

    const courseSearch = document.getElementById('courseSearch');
    if (courseSearch) {
        courseSearch.addEventListener('input', () => {
            const query = courseSearch.value.trim().toLowerCase();
            document.querySelectorAll('.js-course-card').forEach((card) => {
                const haystack = card.dataset.query || '';
                card.classList.toggle('hidden', query !== '' && !haystack.includes(query));
            });
        });
    }

    const repSearch = document.getElementById('repSearch');
    if (repSearch) {
        repSearch.addEventListener('input', () => {
            const query = repSearch.value.trim().toLowerCase();
            document.querySelectorAll('.js-rep-card').forEach((card) => {
                const haystack = card.dataset.query || '';
                card.classList.toggle('hidden', query !== '' && !haystack.includes(query));
            });
        });
    }

    const paymentFilters = Array.from(document.querySelectorAll('[data-payment-filter]'));
    paymentFilters.forEach((filterButton) => {
        filterButton.addEventListener('click', () => {
            const state = filterButton.dataset.paymentFilter || 'all';
            paymentFilters.forEach((button) => button.classList.toggle('is-active', button === filterButton));
            document.querySelectorAll('.js-payment-row').forEach((row) => {
                row.classList.toggle('hidden', state !== 'all' && row.dataset.paymentState !== state);
            });
        });
    });

    setScreen(initialScreen, { preserveSubview: true, silent: true });
    if (initialSubview) {
        setSubview(initialSubview);
    }
})();
</script>
</body>
</html>
