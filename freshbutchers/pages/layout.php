<?php
/**
 * FreshButchers SuiteFleet - Common Layout Template
 *
 * Variables expected before including this file:
 *   $pageTitle   - string: page title for <title> and header
 *   $pageContent - string: HTML content for the main area
 *   $shop        - string: current shop domain (from session)
 */

$currentPage = $_GET['page'] ?? 'dashboard';
$shopParam   = urlencode($shop ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'FreshButchers SuiteFleet') ?></title>

    <!-- Shopify App Bridge -->
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #1a1a1a;
            background: #f6f6f7;
        }
        a { color: #2c6ecb; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Navigation Bar ── */
        .nav-bar {
            background: #ffffff;
            border-bottom: 1px solid #e1e3e5;
            padding: 0 24px;
            display: flex;
            align-items: center;
            height: 56px;
            gap: 0;
        }
        .nav-bar .nav-brand {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a1a;
            margin-right: 32px;
            white-space: nowrap;
        }
        .nav-bar .nav-links {
            display: flex;
            gap: 0;
            height: 100%;
            align-items: stretch;
        }
        .nav-bar .nav-links a {
            display: flex;
            align-items: center;
            padding: 0 16px;
            font-size: 14px;
            font-weight: 500;
            color: #6d7175;
            border-bottom: 3px solid transparent;
            transition: color 0.15s, border-color 0.15s;
        }
        .nav-bar .nav-links a:hover {
            color: #1a1a1a;
            text-decoration: none;
        }
        .nav-bar .nav-links a.active {
            color: #2c6ecb;
            border-bottom-color: #2c6ecb;
        }
        .nav-bar .nav-links a.external {
            margin-left: auto;
            color: #8c9196;
            font-size: 13px;
        }
        .nav-bar .nav-links a.external:hover {
            color: #2c6ecb;
        }
        .nav-spacer { flex: 1; }

        /* ── Page Container ── */
        .page-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .page-header {
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
        }
        .page-header p {
            font-size: 14px;
            color: #6d7175;
            margin-top: 4px;
        }

        /* ── Cards ── */
        .card {
            background: #ffffff;
            border: 1px solid #e1e3e5;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
        }
        .card-header {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f1f1;
        }

        /* ── Stat Cards Grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid #e1e3e5;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1a1a1a;
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6d7175;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card.stat-total   { border-top: 3px solid #2c6ecb; }
        .stat-card.stat-transit  { border-top: 3px solid #e9c46a; }
        .stat-card.stat-delivered { border-top: 3px solid #2a9d8f; }
        .stat-card.stat-failed   { border-top: 3px solid #e76f51; }

        /* ── Tables ── */
        .table-wrapper {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        table thead th {
            text-align: left;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 13px;
            color: #6d7175;
            background: #fafbfb;
            border-bottom: 1px solid #e1e3e5;
            white-space: nowrap;
        }
        table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f1f1;
            vertical-align: middle;
        }
        table tbody tr:hover {
            background: #fafbfb;
        }
        table tbody tr:last-child td {
            border-bottom: none;
        }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            color: #fff;
            white-space: nowrap;
        }
        .badge-pending    { background: #f4a261; }
        .badge-created    { background: #457b9d; }
        .badge-assigned   { background: #457b9d; }
        .badge-transit    { background: #e9c46a; color: #1a1a1a; }
        .badge-delivered  { background: #2a9d8f; }
        .badge-failed     { background: #e76f51; }
        .badge-cancelled  { background: #e76f51; }
        .badge-unfulfilled { background: #f4a261; }
        .badge-fulfilled   { background: #2a9d8f; }
        .badge-partial     { background: #e9c46a; color: #1a1a1a; }
        .badge-default    { background: #8c9196; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: background 0.15s, box-shadow 0.15s;
            text-decoration: none;
            line-height: 1;
            white-space: nowrap;
        }
        .btn:hover { text-decoration: none; }
        .btn-primary {
            background: #2c6ecb;
            color: #fff;
            border-color: #2c6ecb;
        }
        .btn-primary:hover {
            background: #1f5199;
            border-color: #1f5199;
        }
        .btn-secondary {
            background: #ffffff;
            color: #1a1a1a;
            border-color: #c9cccf;
        }
        .btn-secondary:hover {
            background: #f6f6f7;
        }
        .btn-success {
            background: #2a9d8f;
            color: #fff;
            border-color: #2a9d8f;
        }
        .btn-success:hover {
            background: #228176;
        }
        .btn-danger {
            background: #e76f51;
            color: #fff;
            border-color: #e76f51;
        }
        .btn-sm {
            padding: 5px 10px;
            font-size: 13px;
        }
        .btn:disabled, .btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* ── Banners / Alerts ── */
        .banner {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            display: none;
        }
        .banner.show { display: block; }
        .banner-success {
            background: #e3f5e1;
            color: #1a7e36;
            border: 1px solid #b3e6b0;
        }
        .banner-error {
            background: #fce4e4;
            color: #c53030;
            border: 1px solid #f5b1b1;
        }
        .banner-info {
            background: #e3f0fc;
            color: #1f5199;
            border: 1px solid #a7cff2;
        }

        /* ── Forms ── */
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #1a1a1a;
            margin-bottom: 4px;
        }
        .form-group .form-hint {
            font-size: 12px;
            color: #8c9196;
            margin-top: 2px;
        }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            font-size: 14px;
            border: 1px solid #c9cccf;
            border-radius: 6px;
            background: #ffffff;
            color: #1a1a1a;
            transition: border-color 0.15s;
        }
        .form-control:focus {
            outline: none;
            border-color: #2c6ecb;
            box-shadow: 0 0 0 2px rgba(44,110,203,0.15);
        }
        .form-control:read-only {
            background: #f6f6f7;
            color: #6d7175;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        /* ── Utilities ── */
        .text-muted { color: #8c9196; }
        .text-sm { font-size: 13px; }
        .text-right { text-align: right; }
        .mt-8 { margin-top: 8px; }
        .mt-16 { margin-top: 16px; }
        .mb-16 { margin-bottom: 16px; }
        .mb-24 { margin-bottom: 24px; }
        .flex { display: flex; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        .gap-8 { gap: 8px; }
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #8c9196;
        }
        .empty-state p {
            margin-top: 8px;
            font-size: 14px;
        }

        /* ── Loading Spinner ── */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #c9cccf;
            border-top-color: #2c6ecb;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 6px;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Footer ── */
        .page-footer {
            text-align: center;
            padding: 24px;
            font-size: 12px;
            color: #8c9196;
            border-top: 1px solid #e1e3e5;
            margin-top: 48px;
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="nav-bar">
        <div class="nav-brand">FreshButchers</div>
        <div class="nav-links">
            <a href="index.php?shop=<?= $shopParam ?>&page=dashboard"
               class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
            <a href="index.php?shop=<?= $shopParam ?>&page=orders"
               class="<?= $currentPage === 'orders' ? 'active' : '' ?>">Orders</a>
            <a href="index.php?shop=<?= $shopParam ?>&page=shipments"
               class="<?= $currentPage === 'shipments' ? 'active' : '' ?>">Shipments</a>
            <a href="index.php?shop=<?= $shopParam ?>&page=settings"
               class="<?= $currentPage === 'settings' ? 'active' : '' ?>">Settings</a>
        </div>
        <div class="nav-spacer"></div>
        <div class="nav-links">
            <a href="<?= htmlspecialchars(buildPortalUrl()) ?>" target="_blank" class="external">SuiteFleet Portal &rarr;</a>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="page-container">
        <?= $pageContent ?? '' ?>
    </div>

    <!-- Footer -->
    <div class="page-footer">
        FreshButchers &times; Transcorp Logistics (SuiteFleet) &mdash; <?= date('Y') ?>
    </div>

    <script>
        // Initialize Shopify App Bridge if running inside Shopify admin
        (function() {
            if (window.shopify && window.shopify.environment) {
                // App Bridge is auto-initialized in embedded context
                console.log('App Bridge loaded in embedded context');
            }
        })();

        /**
         * Show a banner message
         * @param {string} type  - 'success', 'error', or 'info'
         * @param {string} message
         * @param {string} bannerId - ID of the banner element
         */
        function showBanner(type, message, bannerId) {
            var banner = document.getElementById(bannerId);
            if (!banner) return;
            banner.className = 'banner banner-' + type + ' show';
            banner.textContent = message;
            // Auto-hide after 8 seconds
            setTimeout(function() {
                banner.classList.remove('show');
            }, 8000);
        }
    </script>
</body>
</html>
