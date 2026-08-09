<?php
if (!isset($_SESSION['admin_logged_in']) || !function_exists('book_system_get_unread_notifications')) {
    return;
}

$notificationWidgetAdminId = intval($_SESSION['admin_id'] ?? 0);
$notificationWidgetRole = strval($_SESSION['admin_role'] ?? '');
if ($notificationWidgetAdminId <= 0 || $notificationWidgetRole === '') {
    return;
}

$notificationWidgetPayload = book_system_get_unread_notifications($conn, $notificationWidgetAdminId, $notificationWidgetRole, 20);
$notificationWidgetItems = array_values(is_array($notificationWidgetPayload['items'] ?? null) ? $notificationWidgetPayload['items'] : []);
$notificationWidgetCount = intval($notificationWidgetPayload['count'] ?? 0);
$notificationWidgetCsrf = csrf_get_token();
$notificationWidgetAccent = '#5b6cf0';
if ($notificationWidgetRole === 'rep') {
    $notificationWidgetAccent = '#2e7d32';
} elseif ($notificationWidgetRole === 'temporary_admin') {
    $notificationWidgetAccent = '#1f2937';
}
?>
<style>
    .cbh-notify-shell {
        position: fixed;
        right: 18px;
        bottom: 18px;
        z-index: 1200;
    }
    .cbh-notify-bell {
        width: 58px;
        height: 58px;
        border: none;
        border-radius: 50%;
        background: linear-gradient(135deg, <?php echo htmlspecialchars($notificationWidgetAccent); ?> 0%, #764ba2 100%);
        color: #fff;
        cursor: pointer;
        box-shadow: 0 16px 35px rgba(15, 23, 42, 0.24);
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    .cbh-notify-badge {
        position: absolute;
        top: -2px;
        right: -2px;
        min-width: 24px;
        height: 24px;
        border-radius: 999px;
        background: #ef4444;
        color: #fff;
        padding: 0 7px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 800;
        border: 2px solid #fff;
    }
    .cbh-notify-panel {
        position: absolute;
        right: 0;
        bottom: 72px;
        width: min(360px, calc(100vw - 24px));
        background: rgba(255,255,255,0.98);
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 20px;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        overflow: hidden;
        display: none;
        backdrop-filter: blur(10px);
    }
    .cbh-notify-panel.is-open {
        display: block;
    }
    .cbh-notify-panel-head {
        padding: 16px 18px 12px;
        background: linear-gradient(135deg, rgba(91,108,240,0.08) 0%, rgba(118,75,162,0.06) 100%);
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    }
    .cbh-notify-panel-head strong {
        display: block;
        font-size: 15px;
        color: #111827;
    }
    .cbh-notify-panel-head span {
        display: block;
        margin-top: 4px;
        font-size: 12px;
        color: #64748b;
    }
    .cbh-notify-list {
        max-height: 360px;
        overflow-y: auto;
    }
    .cbh-notify-empty {
        padding: 24px 18px;
        font-size: 14px;
        color: #64748b;
        text-align: center;
    }
    .cbh-notify-item {
        padding: 16px 18px;
        border-bottom: 1px solid #eef2f7;
    }
    .cbh-notify-item:last-child {
        border-bottom: none;
    }
    .cbh-notify-item-title {
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
    }
    .cbh-notify-item-message {
        margin-top: 6px;
        font-size: 13px;
        line-height: 1.55;
        color: #475569;
    }
    .cbh-notify-item-meta {
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }
    .cbh-notify-item-time {
        font-size: 12px;
        color: #94a3b8;
    }
    .cbh-notify-item-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .cbh-notify-action,
    .cbh-notify-link {
        border-radius: 999px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }
    .cbh-notify-action {
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: #fff;
        color: #334155;
    }
    .cbh-notify-link {
        border: none;
        background: rgba(91,108,240,0.12);
        color: <?php echo htmlspecialchars($notificationWidgetAccent); ?>;
    }
    .cbh-toast-stack {
        position: fixed;
        right: 18px;
        top: 18px;
        width: min(360px, calc(100vw - 24px));
        display: flex;
        flex-direction: column;
        gap: 12px;
        z-index: 1250;
        pointer-events: none;
    }
    .cbh-toast {
        background: rgba(17, 24, 39, 0.96);
        color: #fff;
        border-radius: 18px;
        padding: 14px 16px;
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.28);
        pointer-events: auto;
        overflow: hidden;
    }
    .cbh-toast strong {
        display: block;
        font-size: 14px;
        margin-bottom: 6px;
    }
    .cbh-toast p {
        font-size: 13px;
        line-height: 1.5;
        color: rgba(255,255,255,0.88);
    }
    .cbh-toast-actions {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        flex-wrap: wrap;
    }
    .cbh-toast-btn {
        border: none;
        border-radius: 999px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }
    .cbh-toast-btn.primary {
        background: #fff;
        color: #111827;
    }
    .cbh-toast-btn.secondary {
        background: rgba(255,255,255,0.16);
        color: #fff;
    }
    @media (max-width: 640px) {
        .cbh-notify-shell {
            right: 14px;
            bottom: 14px;
        }
        .cbh-notify-bell {
            width: 54px;
            height: 54px;
        }
        .cbh-toast-stack {
            right: 12px;
            top: 12px;
            width: calc(100vw - 24px);
        }
        .cbh-notify-panel {
            width: calc(100vw - 24px);
        }
    }
</style>

<div class="cbh-toast-stack" id="cbhToastStack"></div>

<div class="cbh-notify-shell" id="cbhNotifyShell">
    <div class="cbh-notify-panel" id="cbhNotifyPanel" aria-hidden="true">
        <div class="cbh-notify-panel-head">
            <strong>Live Notifications</strong>
            <span>New request and signup activity will appear here while this page stays open.</span>
        </div>
        <div class="cbh-notify-list" id="cbhNotifyList"></div>
    </div>
    <button type="button" class="cbh-notify-bell" id="cbhNotifyBell" aria-label="Notifications">
        <span aria-hidden="true">&#128276;</span>
        <span class="cbh-notify-badge" id="cbhNotifyBadge"<?php echo $notificationWidgetCount > 0 ? '' : ' style="display:none"'; ?>>
            <?php echo $notificationWidgetCount > 99 ? '99+' : $notificationWidgetCount; ?>
        </span>
    </button>
</div>

<script>
(function () {
    const initialNotifications = <?php echo json_encode($notificationWidgetItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const csrfToken = <?php echo json_encode($notificationWidgetCsrf); ?>;
    const endpoints = {
        check: 'check_notifications.php',
        markRead: 'mark_notification_read.php'
    };

    const state = {
        unread: Array.isArray(initialNotifications) ? initialNotifications : [],
        seenIds: new Set((Array.isArray(initialNotifications) ? initialNotifications : []).map(item => String(item.notification_id))),
        pollHandle: null,
        panelOpen: false
    };

    const bell = document.getElementById('cbhNotifyBell');
    const badge = document.getElementById('cbhNotifyBadge');
    const panel = document.getElementById('cbhNotifyPanel');
    const list = document.getElementById('cbhNotifyList');
    const toastStack = document.getElementById('cbhToastStack');

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function updateBadge() {
        const count = state.unread.length;
        if (count > 0) {
            badge.style.display = 'inline-flex';
            badge.textContent = count > 99 ? '99+' : String(count);
        } else {
            badge.style.display = 'none';
        }
    }

    function renderPanel() {
        if (!state.unread.length) {
            list.innerHTML = '<div class="cbh-notify-empty">No unread notifications right now.</div>';
            updateBadge();
            return;
        }

        list.innerHTML = state.unread.map(function (item) {
            const targetUrl = item.target_url && item.target_url !== '#' ? item.target_url : '';
            const openButton = targetUrl
                ? '<a href="' + escapeHtml(targetUrl) + '" class="cbh-notify-link" data-open-notification="' + String(item.notification_id) + '">Open</a>'
                : '';
            return '' +
                '<div class="cbh-notify-item" data-notification-item="' + String(item.notification_id) + '">' +
                    '<div class="cbh-notify-item-title">' + escapeHtml(item.title) + '</div>' +
                    '<div class="cbh-notify-item-message">' + escapeHtml(item.message) + '</div>' +
                    '<div class="cbh-notify-item-meta">' +
                        '<span class="cbh-notify-item-time">' + escapeHtml(item.relative_time || 'Just now') + '</span>' +
                        '<div class="cbh-notify-item-actions">' +
                            openButton +
                            '<button type="button" class="cbh-notify-action" data-mark-notification="' + String(item.notification_id) + '">Mark read</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        }).join('');
        updateBadge();
    }

    function playBeep() {
        try {
            const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextCtor) {
                return;
            }
            const context = new AudioContextCtor();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.setValueAtTime(0.001, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.08, context.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.25);
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + 0.28);
            oscillator.onended = function () {
                if (typeof context.close === 'function') {
                    context.close();
                }
            };
        } catch (error) {
            console.debug('Notification beep unavailable.', error);
        }
    }

    function removeToast(notificationId) {
        const node = toastStack.querySelector('[data-toast-id="' + String(notificationId) + '"]');
        if (node) {
            node.remove();
        }
    }

    function showToast(notification) {
        removeToast(notification.notification_id);

        const toast = document.createElement('div');
        toast.className = 'cbh-toast';
        toast.setAttribute('data-toast-id', String(notification.notification_id));
        const hasTarget = notification.target_url && notification.target_url !== '#';
        toast.innerHTML = '' +
            '<strong>' + escapeHtml(notification.title) + '</strong>' +
            '<p>' + escapeHtml(notification.message) + '</p>' +
            '<div class="cbh-toast-actions">' +
                (hasTarget ? '<button type="button" class="cbh-toast-btn primary" data-open-toast="' + String(notification.notification_id) + '">Open</button>' : '') +
                '<button type="button" class="cbh-toast-btn secondary" data-dismiss-toast="' + String(notification.notification_id) + '">Dismiss</button>' +
            '</div>';
        toastStack.prepend(toast);

        window.setTimeout(function () {
            removeToast(notification.notification_id);
        }, 12000);
    }

    function syncNotifications(payload, triggerToasts) {
        const incoming = Array.isArray(payload.notifications) ? payload.notifications : [];
        let hasFresh = false;

        if (triggerToasts) {
            incoming.slice().reverse().forEach(function (notification) {
                const notificationId = String(notification.notification_id);
                if (!state.seenIds.has(notificationId)) {
                    state.seenIds.add(notificationId);
                    showToast(notification);
                    hasFresh = true;
                }
            });
        }

        state.unread = incoming;
        renderPanel();
        if (hasFresh) {
            playBeep();
        }
    }

    function markNotificationRead(notificationId, navigateTo) {
        const body = new URLSearchParams();
        body.set('notification_id', String(notificationId));
        body.set('csrf_token', csrfToken);

        return fetch(endpoints.markRead, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function () {
            state.unread = state.unread.filter(function (item) {
                return String(item.notification_id) !== String(notificationId);
            });
            renderPanel();
            removeToast(notificationId);
            if (navigateTo) {
                window.location.href = navigateTo;
            }
        }).catch(function () {
            if (navigateTo) {
                window.location.href = navigateTo;
            }
        });
    }

    function pollNotifications() {
        if (document.visibilityState === 'hidden' || navigator.onLine === false) {
            return;
        }
        fetch(endpoints.check, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                return;
            }
            syncNotifications(payload, true);
        }).catch(function (error) {
            console.debug('Notification polling skipped.', error);
        });
    }

    bell.addEventListener('click', function () {
        state.panelOpen = !state.panelOpen;
        panel.classList.toggle('is-open', state.panelOpen);
        panel.setAttribute('aria-hidden', state.panelOpen ? 'false' : 'true');
    });

    document.addEventListener('click', function (event) {
        const markButton = event.target.closest('[data-mark-notification]');
        if (markButton) {
            markNotificationRead(markButton.getAttribute('data-mark-notification'));
            return;
        }

        const openLink = event.target.closest('[data-open-notification]');
        if (openLink) {
            event.preventDefault();
            markNotificationRead(openLink.getAttribute('data-open-notification'), openLink.getAttribute('href'));
            return;
        }

        const openToast = event.target.closest('[data-open-toast]');
        if (openToast) {
            const notificationId = openToast.getAttribute('data-open-toast');
            const notification = state.unread.find(function (item) {
                return String(item.notification_id) === String(notificationId);
            });
            const targetUrl = notification && notification.target_url ? notification.target_url : '';
            markNotificationRead(notificationId, targetUrl || null);
            return;
        }

        const dismissToast = event.target.closest('[data-dismiss-toast]');
        if (dismissToast) {
            markNotificationRead(dismissToast.getAttribute('data-dismiss-toast'));
            return;
        }

        if (!event.target.closest('#cbhNotifyShell') && !event.target.closest('#cbhToastStack')) {
            state.panelOpen = false;
            panel.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
        }
    });

    renderPanel();
    state.pollHandle = window.setInterval(pollNotifications, 12000);
})();
</script>
