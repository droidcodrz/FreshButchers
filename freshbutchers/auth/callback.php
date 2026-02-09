<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/shopify.php';

session_start();

try {
    $shop = $_GET['shop'] ?? '';
    $code = $_GET['code'] ?? '';

    if (!$shop || !$code) {
        http_response_code(400);
        die('Error: Missing required parameters (shop, code).');
    }

    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop)) {
        http_response_code(400);
        die('Error: Invalid shop domain format.');
    }

    $accessToken = ShopifyAPI::exchangeToken($shop, $code);
    if (!$accessToken) {
        throw new Exception('Failed to obtain access token from Shopify.');
    }

    $db = Database::getInstance();
    $db->saveSession('offline_' . $shop, $shop, $accessToken, SHOPIFY_SCOPES, 'installed');

    $_SESSION['shop'] = $shop;
    $storeName = str_replace('.myshopify.com', '', $shop);
    header('Location: https://admin.shopify.com/store/' . urlencode($storeName) . '/apps/' . SHOPIFY_API_KEY);
    exit;

} catch (Exception $e) {
    error_log('OAuth callback error: ' . $e->getMessage());
    http_response_code(500);
    die('Installation failed: ' . htmlspecialchars($e->getMessage()) . '. Please try again.');
}