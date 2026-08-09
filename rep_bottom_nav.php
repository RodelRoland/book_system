<?php
$rep_bottom_nav_active = trim(strval($rep_bottom_nav_active ?? ''));
$rep_bottom_nav_dashboard_href = trim(strval($rep_bottom_nav_dashboard_href ?? 'rep_dashboard.php'));
$rep_bottom_nav_requests_href = trim(strval($rep_bottom_nav_requests_href ?? 'view_request.php'));
$rep_bottom_nav_center_href = trim(strval($rep_bottom_nav_center_href ?? 'admin_manual_order.php'));
$rep_bottom_nav_reports_href = trim(strval($rep_bottom_nav_reports_href ?? 'activity_log.php'));
$rep_bottom_nav_profile_href = trim(strval($rep_bottom_nav_profile_href ?? ''));
if ($rep_bottom_nav_profile_href === '') {
    $rep_bottom_nav_profile_href = 'my_profile.php';
}

$rep_bottom_nav_items = [
    [
        'key' => 'dashboard',
        'label' => 'Dashboard',
        'href' => $rep_bottom_nav_dashboard_href,
        'icon' => '<path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1v-9.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'center' => false,
    ],
    [
        'key' => 'requests',
        'label' => 'Requests',
        'href' => $rep_bottom_nav_requests_href,
        'icon' => '<path d="M8 4h7l4 4v11a1 1 0 0 1-1 1H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M13 4v4h4M9 13h6M9 17h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        'center' => false,
    ],
    [
        'key' => 'add-payment',
        'label' => 'Add Payment',
        'href' => $rep_bottom_nav_center_href,
        'icon' => '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/>',
        'center' => true,
    ],
    [
        'key' => 'reports',
        'label' => 'Reports',
        'href' => $rep_bottom_nav_reports_href,
        'icon' => '<path d="M6 18V9m6 9V6m6 12v-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 20h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        'center' => false,
    ],
    [
        'key' => 'profile',
        'label' => 'Profile',
        'href' => $rep_bottom_nav_profile_href,
        'icon' => '<circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        'center' => false,
    ],
];
?>
<style>
.rep-mobile-nav-spacer {
    height: calc(108px + env(safe-area-inset-bottom, 0px));
}
.rep-mobile-nav-wrap {
    position: fixed;
    left: 50%;
    bottom: max(10px, env(safe-area-inset-bottom, 0px));
    transform: translateX(-50%);
    width: min(calc(100vw - 18px), 440px);
    z-index: 120;
    pointer-events: none;
}
.rep-mobile-nav-shell {
    pointer-events: auto;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    align-items: end;
    gap: 6px;
    padding: 10px 10px calc(11px + env(safe-area-inset-bottom, 0px));
    border-radius: 28px;
    border: 1px solid rgba(255, 255, 255, 0.94);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(246, 249, 255, 0.98));
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14), inset 0 1px 0 rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(16px);
}
.rep-mobile-nav-item {
    display: grid;
    justify-items: center;
    align-content: end;
    gap: 6px;
    min-width: 0;
    padding: 8px 4px 2px;
    text-decoration: none;
    color: #6b7280;
    transition: transform 0.18s ease, color 0.18s ease;
}
.rep-mobile-nav-item:active {
    transform: translateY(1px) scale(0.985);
}
.rep-mobile-nav-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 16px;
    transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
}
.rep-mobile-nav-item svg {
    width: 22px;
    height: 22px;
}
.rep-mobile-nav-label {
    display: block;
    width: 100%;
    text-align: center;
    font-size: 10px;
    line-height: 1.15;
    font-weight: 700;
    letter-spacing: 0.01em;
    white-space: nowrap;
}
.rep-mobile-nav-item.is-active {
    color: #1d4ed8;
}
.rep-mobile-nav-item.is-active .rep-mobile-nav-pill {
    background: rgba(37, 99, 235, 0.12);
    box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.08);
}
.rep-mobile-nav-item.is-active .rep-mobile-nav-label {
    color: #1d4ed8;
}
.rep-mobile-nav-item.is-center {
    transform: translateY(-18px);
    gap: 4px;
}
.rep-mobile-nav-item.is-center:active {
    transform: translateY(-17px) scale(0.985);
}
.rep-mobile-nav-item.is-center .rep-mobile-nav-pill {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    color: #ffffff;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 16px 28px rgba(37, 99, 235, 0.28), 0 0 0 8px rgba(255, 255, 255, 0.88);
}
.rep-mobile-nav-item.is-center.is-active .rep-mobile-nav-pill {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
}
.rep-mobile-nav-item.is-center .rep-mobile-nav-label {
    color: #1d4ed8;
}
@media (min-width: 760px) {
    .rep-mobile-nav-wrap {
        width: min(calc(100vw - 28px), 520px);
        bottom: max(14px, env(safe-area-inset-bottom, 0px));
    }
    .rep-mobile-nav-spacer {
        height: calc(116px + env(safe-area-inset-bottom, 0px));
    }
}
@media (max-width: 370px) {
    .rep-mobile-nav-shell {
        gap: 4px;
        padding-left: 8px;
        padding-right: 8px;
    }
    .rep-mobile-nav-pill {
        width: 40px;
        height: 40px;
        border-radius: 14px;
    }
    .rep-mobile-nav-item svg {
        width: 20px;
        height: 20px;
    }
    .rep-mobile-nav-label {
        font-size: 9px;
    }
    .rep-mobile-nav-item.is-center .rep-mobile-nav-pill {
        width: 54px;
        height: 54px;
    }
}
</style>
<div class="rep-mobile-nav-spacer" aria-hidden="true"></div>
<div class="rep-mobile-nav-wrap">
    <nav class="rep-mobile-nav-shell" aria-label="Rep navigation">
        <?php foreach ($rep_bottom_nav_items as $rep_bottom_nav_item): ?>
            <?php
            $rep_bottom_nav_item_active = $rep_bottom_nav_active === $rep_bottom_nav_item['key'];
            $rep_bottom_nav_item_classes = 'rep-mobile-nav-item';
            if ($rep_bottom_nav_item['center']) {
                $rep_bottom_nav_item_classes .= ' is-center';
            }
            if ($rep_bottom_nav_item_active) {
                $rep_bottom_nav_item_classes .= ' is-active';
            }
            ?>
            <a
                href="<?php echo htmlspecialchars($rep_bottom_nav_item['href']); ?>"
                class="<?php echo htmlspecialchars($rep_bottom_nav_item_classes); ?>"
                <?php echo $rep_bottom_nav_item_active ? 'aria-current="page"' : ''; ?>
            >
                <span class="rep-mobile-nav-pill">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><?php echo $rep_bottom_nav_item['icon']; ?></svg>
                </span>
                <span class="rep-mobile-nav-label"><?php echo htmlspecialchars($rep_bottom_nav_item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
