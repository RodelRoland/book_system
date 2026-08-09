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
    <title>Contact ClassBookHub</title>
    <style>
        :root {
            --bg: #f3f7fd;
            --surface: rgba(255, 255, 255, 0.98);
            --line: rgba(148, 163, 184, 0.18);
            --text: #10203a;
            --muted: #66768f;
            --primary-a: #3f8cff;
            --primary-b: #1556df;
            --success: #15936f;
            --shadow-xl: 0 28px 64px rgba(15, 23, 42, 0.12);
            --shadow-lg: 0 18px 42px rgba(15, 23, 42, 0.08);
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
            right: -60px;
            top: -48px;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(21, 147, 111, 0.16), rgba(21, 147, 111, 0));
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
            background: rgba(21, 147, 111, 0.1);
            color: var(--success);
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
            border-radius: 22px;
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
            background: linear-gradient(135deg, rgba(21, 147, 111, 0.12), rgba(63, 140, 255, 0.08));
            color: var(--success);
            font-size: 22px;
            flex-shrink: 0;
        }
        .section-head h2 {
            margin: 0;
            font-size: 22px;
            line-height: 1.15;
            letter-spacing: -0.03em;
        }
        .info-card p, .info-card li {
            margin: 0;
            color: #42536e;
            font-size: 15px;
            line-height: 1.75;
        }
        .support-grid {
            display: grid;
            gap: 14px;
        }
        .support-chip {
            padding: 14px 16px;
            border-radius: 18px;
            background: linear-gradient(135deg, #f7fbff 0%, #eef4ff 100%);
            border: 1px solid rgba(191, 219, 254, 0.7);
        }
        .support-chip span {
            display: block;
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .support-chip strong {
            color: #10203a;
            font-size: 16px;
            line-height: 1.5;
            word-break: break-word;
        }
        .feature-list {
            display: grid;
            gap: 10px;
            padding-left: 0;
            list-style: none;
        }
        .feature-list li {
            display: grid;
            grid-template-columns: 18px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
        }
        .feature-list li::before {
            content: "";
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--primary-a), var(--success));
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
            .support-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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
            <span class="hero-badge">Contact & Support</span>
            <h1>We’re here to help you move quickly and clearly.</h1>
            <p>If you need support with ClassBookHub, this page gives you the best way to reach out and the information that helps us assist you faster.</p>
        </div>
    </section>

    <section class="section-grid">
        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">?</div>
                <h2>We’re Here to Help</h2>
            </div>
            <p>ClassBookHub support is available to help with questions, access issues, request concerns, and other problems that affect your ability to use the platform smoothly.</p>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">!</div>
                <h2>Before Contacting Support</h2>
            </div>
            <p>Please check that you are using the correct page, entering the correct details, and refreshing the page if a temporary network delay may have interrupted your action.</p>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">+</div>
                <h2>We Can Help With</h2>
            </div>
            <ul class="feature-list">
                <li><span>Request or payment issues</span></li>
                <li><span>Book status or collection concerns</span></li>
                <li><span>Access or login support</span></li>
                <li><span>General questions about using ClassBookHub</span></li>
            </ul>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">i</div>
                <h2>To Help Us Assist You Faster</h2>
            </div>
            <p>When contacting support, include the issue you experienced, the page you were using, and any useful identifying details such as your index number, request reference, or a short description of what happened.</p>
        </article>

        <article class="info-card span-2">
            <div class="section-head">
                <div class="section-icon">@</div>
                <h2>Support Contact</h2>
            </div>
            <div class="support-grid">
                <div class="support-chip">
                    <span>Email</span>
                    <strong>rolandkitsi@gmail.com</strong>
                </div>
                <div class="support-chip">
                    <span>WhatsApp</span>
                    <strong>+233 54 909 0433</strong>
                </div>
            </div>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">o</div>
                <h2>Support Hours</h2>
            </div>
            <p><strong>Monday – Sunday</strong><br>7:00 AM – 5:00 PM (GMT)</p>
        </article>

        <article class="info-card">
            <div class="section-head">
                <div class="section-icon">*</div>
                <h2>Our Commitment</h2>
            </div>
            <p>We are committed to providing clear and dependable support so users can continue using ClassBookHub with confidence and minimal disruption.</p>
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
