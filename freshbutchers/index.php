<?php
/**
 * FreshButchers SuiteFleet - Main Entry Point / Router
 */
// Check if config.php exists
if (!file_exists(__DIR__ . '/config.php')) {
    die('<h1 style="color:red">ERROR: config.php not found!</h1>
    <p>Copy <b>config.example.php</b> to <b>config.php</b> and update DB credentials.</p>');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/shopify.php';
require_once __DIR__ . '/includes/suitefleet.php';
require_once __DIR__ . '/includes/helpers.php';

session_start();

// Check if direct token mode (Custom App)
$hasDirectToken = defined('SHOPIFY_STORE') && SHOPIFY_STORE && defined('SHOPIFY_ACCESS_TOKEN') && SHOPIFY_ACCESS_TOKEN;

// Check if running on localhost (use HTTP_HOST which reflects actual request URL, not Apache config)
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocalhost = (strpos($httpHost, 'localhost') !== false)
    || (strpos($httpHost, '127.0.0.1') !== false);

// Get shop from: direct config > query params > session
$shop = null;
if ($hasDirectToken) {
    $shop = SHOPIFY_STORE;
} else {
    $shop = $_GET['shop'] ?? $_SESSION['shop'] ?? null;
}

// If no shop, use store from config as fallback
if (!$shop && defined('SHOPIFY_STORE') && SHOPIFY_STORE) {
    $shop = SHOPIFY_STORE;
}

// If still no shop, show install form
if (!$shop) {
    include __DIR__ . '/install.php';
    exit;
}

// Verify HMAC if coming from Shopify (skip on localhost and direct token mode)
if (isset($_GET['hmac']) && !$isLocalhost && !$hasDirectToken) {
    if (!ShopifyAPI::verifyHmac($_GET)) {
        die('Invalid HMAC. Request not from Shopify.');
    }
}

// Store shop in session
$_SESSION['shop'] = $shop;

// Check if we have an access token
$db = Database::getInstance();
$accessToken = null;

if ($hasDirectToken) {
    $accessToken = SHOPIFY_ACCESS_TOKEN;
} else {
    $session = $db->getSessionByShop($shop);
    if ($session && $session['access_token']) {
        $accessToken = $session['access_token'];
    }
}

// If no token and not localhost, redirect to OAuth
if (!$accessToken && !$isLocalhost) {
    $installUrl = ShopifyAPI::getInstallUrl($shop);
    ?>
    <!DOCTYPE html>
    <html><head><title>Authorizing...</title></head>
    <body>
        <p style="text-align:center;margin-top:80px;font-family:sans-serif;color:#666;">Redirecting to Shopify for authorization...</p>
        <script>
            if (window.top !== window.self) {
                window.top.location.href = <?= json_encode($installUrl) ?>;
            } else {
                window.location.href = <?= json_encode($installUrl) ?>;
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Route to correct page
$page = $_GET['page'] ?? 'dashboard';

switch ($page) {
    case 'dashboard':
        include __DIR__ . '/pages/dashboard.php';
        break;
    case 'orders':
        include __DIR__ . '/pages/orders.php';
        break;
    case 'shipments':
        include __DIR__ . '/pages/shipments.php';
        break;
    case 'settings':
        include __DIR__ . '/pages/settings.php';
        break;
    default:
        include __DIR__ . '/pages/dashboard.php';
}