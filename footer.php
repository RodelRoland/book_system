<?php
$year = date('Y');
?>

<div style="margin-top: 30px; text-align: center; color: #8a8a8a; font-size: 13px; padding: 18px 0;">
    <div style="max-width: 1200px; margin: 0 auto; border-top: 1px solid rgba(0,0,0,0.08); padding-top: 16px;">
        &copy; <?php echo htmlspecialchars($year); ?> Roland Kitsi. All rights reserved.
    </div>
</div>

<style>
@media (max-width: 900px) {
    body { padding-left: 12px !important; padding-right: 12px !important; }
    .page-container { max-width: 100% !important; }
    .container { padding-left: 14px !important; padding-right: 14px !important; }
}

@media (max-width: 768px) {
    .page-header,
    .dashboard-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px !important;
        padding: 18px 18px !important;
    }

    .search-section {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .search-input {
        min-width: 0 !important;
        width: 100% !important;
    }

    .stats-grid,
    .menu-grid,
    .grid {
        grid-template-columns: 1fr !important;
    }

    .grid,
    .grid-2,
    .stats-grid,
    .menu-grid {
        min-width: 0 !important;
    }

    .grid > *,
    .grid-2 > *,
    .stats-grid > *,
    .menu-grid > * {
        min-width: 0 !important;
    }

    .card {
        min-width: 0 !important;
    }

    .dashboard-container,
    .page-container {
        padding-left: 0 !important;
        padding-right: 0 !important;
    }

    .card,
    .login-card,
    .login-container {
        padding: 18px 16px !important;
    }

    .page-header .back-btn,
    .page-header .btn,
    .dashboard-header .logout-btn,
    .dashboard-header button,
    .login-btn,
    .btn-primary {
        width: 100% !important;
        justify-content: center !important;
    }

    .page-header form,
    .dashboard-header form {
        width: 100% !important;
    }

    input[type="text"],
    input[type="password"],
    input[type="number"],
    input[type="date"],
    input[type="tel"],
    select,
    textarea {
        width: 100% !important;
        max-width: 100% !important;
    }

    table {
        display: block !important;
        width: 100% !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        white-space: nowrap !important;
    }

    .table-container {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .table-container table {
        display: table !important;
        overflow: visible !important;
    }
}

@media (max-width: 480px) {
    h1 { font-size: 20px !important; }
    .page-header h1 { font-size: 20px !important; }
    .page-header .subtitle { font-size: 12px !important; }
    .card h2 { font-size: 15px !important; }
}
</style>
