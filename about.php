<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ClassBookHub">
    <link rel="icon" type="image/png" sizes="1254x1254" href="assets/images/logo/classbookhub-icon.png">
    <link rel="apple-touch-icon" href="assets/images/logo/classbookhub-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <title>About ClassBookHub</title>
    <style>
        :root {
            --bg: #f3f7fd;
            --surface: rgba(255, 255, 255, 0.98);
            --surface-soft: #fbfdff;
            --line: rgba(148, 163, 184, 0.18);
            --text: #10203a;
            --muted: #66768f;
            --primary-a: #3f8cff;
            --primary-b: #1556df;
            --shadow-xl: 0 28px 64px rgba(15, 23, 42, 0.12);
            --shadow-lg: 0 18px 42px rgba(15, 23, 42, 0.08);
            --radius-xl: 28px;
            --radius-lg: 22px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(63, 140, 255, 0.12), transparent 26%),
                linear-gradient(180deg, #fafdff 0%, var(--bg) 52%, #ebf2fb 100%);
            color: var(--text);
            padding: 10px 0 36px;
        }
        a { color: inherit; }
        .page-shell {
            width: min(960px, calc(100vw - 20px));
            margin: 0 auto;
            display: grid;
            gap: 18px;
        }
        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 34px;
            padding: 22px 18px 26px;
            background:
                linear-gradient(180deg, rgba(7, 20, 46, 0.08), rgba(7, 20, 46, 0.02)),
                linear-gradient(135deg, #ffffff 0%, #f7fbff 44%, #eef4ff 100%);
            border: 1px solid rgba(255,255,255,0.84);
            box-shadow: var(--shadow-xl);
        }
        .hero::after {
            content: "";
            position: absolute;
            right: -56px;
            top: -48px;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(63, 140, 255, 0.16), rgba(63, 140, 255, 0));
            pointer-events: none;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(191, 219, 254, 0.9);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.06);
            font-weight: 800;
            color: #12305f;
        }
        .brand-pill .logo {
            width: 42px;
            height: 42px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            color: #fff;
            font-weight: 900;
        }
        .hero-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(209, 219, 229, 0.9);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04);
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            color: #24416b;
        }
        .nav-link.primary {
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            border-color: transparent;
            color: #fff;
        }
        .hero-copy {
            display: grid;
            gap: 10px;
            max-width: 700px;
            position: relative;
            z-index: 1;
        }
        .hero-copy h1 {
            margin: 0;
            font-size: clamp(32px, 6vw, 54px);
            line-height: 1.02;
            letter-spacing: -0.04em;
        }
        .hero-copy p {
            margin: 0;
            font-size: 16px;
            line-height: 1.65;
            color: var(--muted);
            max-width: 620px;
        }
        .hero-badge {
            display: inline-flex;
            width: fit-content;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary-b);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .section-grid {
            display: grid;
            gap: 16px;
        }
        .info-card {
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.82);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 22px 18px;
            display: grid;
            gap: 12px;
        }
        .section-head {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, rgba(63, 140, 255, 0.12), rgba(21, 86, 223, 0.08));
            color: var(--primary-b);
            font-size: 22px;
            flex-shrink: 0;
        }
        .section-head h2 {
            margin: 0;
            font-size: 22px;
            line-height: 1.15;
            letter-spacing: -0.03em;
        }
        .info-card p {
            margin: 0;
            color: #42536e;
            font-size: 15px;
            line-height: 1.75;
        }
        .feature-list {
            display: grid;
            gap: 10px;
        }
        .feature-item {
            display: grid;
            grid-template-columns: 18px minmax(0, 1fr);
            gap: 10px;
            align-items: flex-start;
            color: #294268;
            font-size: 15px;
            line-height: 1.6;
        }
        .feature-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--primary-a), var(--primary-b));
            margin-top: 7px;
        }
        .footer-panel {
            background: rgba(255,255,255,0.82);
            border: 1px solid rgba(255,255,255,0.78);
            border-radius: 22px;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.05);
            padding: 16px 14px;
            display: grid;
            gap: 12px;
            text-align: center;
        }
        .footer-links {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .footer-links a {
            text-decoration: none;
            color: #1d4ed8;
            font-size: 13px;
            font-weight: 800;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.86);
            border: 1px solid rgba(191, 219, 254, 0.78);
        }
        @media (min-width: 760px) {
            .section-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .info-card.span-2 {
                grid-column: 1 / -1;
            }
        }
        @media (max-width: 559px) {
            body { padding-top: 8px; }
            .page-shell { width: calc(100vw - 14px); gap: 14px; }
            .hero { border-radius: 28px; padding: 16px 14px 20px; }
            .top-bar { margin-bottom: 18px; }
            .brand-pill { padding: 8px 11px; gap: 10px; font-size: 14px; }
            .brand-pill .logo { width: 38px; height: 38px; border-radius: 14px; }
            .hero-copy h1 { font-size: 30px; }
            .hero-copy p { font-size: 14px; line-height: 1.55; }
            .nav-link { width: 100%; }
            .hero-actions { width: 100%; }
            .info-card { padding: 18px 15px; border-radius: 20px; }
            .section-head h2 { font-size: 20px; }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <section class="hero">
        <div class="top-bar">
            <div class="brand-pill">
                <div class="logo">CB</div>
                <span>ClassBookHub</span>
            </div>
            <div class="hero-actions">
                <a href="common_request_portal.php" class="nav-link primary">Request Portal</a>
                <a href="index.php" class="nav-link">Back to Home</a>
            </div>
        </div>
        <div class="hero-copy">
            <span class="hero-badge">About ClassBookHub</span>
            <h1>A clearer and smarter way to manage course material requests.</h1>
            <p>ClassBookHub is built to make the request, payment, and distribution of course materials more organized, transparent, and easier to manage for everyone involved.</p>
        </div>
    </section>

    <section class="section-grid">
        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">i</div>
                <h2>Who We Are</h2>
            </div>
            <p>ClassBookHub is a digital platform designed to simplify how students request course materials and how representatives coordinate records, payments, and distribution in one organized space.</p>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">!</div>
                <h2>The Problem We Solve</h2>
            </div>
            <p>Managing course material requests manually often creates confusion, duplicate requests, missing records, payment disputes, and poor visibility into what has been requested, received, or given out.</p>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">+</div>
                <h2>Our Solution</h2>
            </div>
            <p>ClassBookHub provides a structured request and tracking system that helps users place requests faster, helps representatives manage records with confidence, and reduces the friction that usually comes with manual coordination.</p>
        </article>

        <article class="info-card span-2">
            <div class="section-head">
                <div class="section-icon">*</div>
                <h2>Why Choose ClassBookHub?</h2>
            </div>
            <p>ClassBookHub provides a more organized and transparent way to manage course material distribution by supporting:</p>
            <div class="feature-list">
                <div class="feature-item"><span class="feature-dot"></span><span>Centralized request management</span></div>
                <div class="feature-item"><span class="feature-dot"></span><span>Transparent payment tracking</span></div>
                <div class="feature-item"><span class="feature-dot"></span><span>Duplicate request prevention</span></div>
                <div class="feature-item"><span class="feature-dot"></span><span>Accurate reporting and record keeping</span></div>
                <div class="feature-item"><span class="feature-dot"></span><span>Efficient coordination among class representatives, students, and lecturers</span></div>
                <div class="feature-item"><span class="feature-dot"></span><span>A secure and user-friendly experience</span></div>
            </div>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">#</div>
                <h2>Our Commitment</h2>
            </div>
            <p>We are committed to making course material management more dependable, easier to follow, and more trustworthy by reducing confusion and improving visibility across the full request process.</p>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">~</div>
                <h2>Our Vision</h2>
            </div>
            <p>Our vision is to create a smoother and more transparent academic support experience where request handling, payment tracking, and distribution records feel simple, professional, and dependable.</p>
        </article>
    </section>

    <section class="footer-panel">
        <div class="footer-links">
            <a href="common_request_portal.php">Home / Request Portal</a>
            <a href="about.php">About Us</a>
            <a href="contact.php">Contact Us</a>
        </div>
    </section>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
