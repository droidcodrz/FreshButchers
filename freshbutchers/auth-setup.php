<?php
require_once __DIR__ . '/config.php';
session_start();

$shop = defined('SHOPIFY_STORE') && SHOPIFY_STORE ? SHOPIFY_STORE : 'freshbutchers.myshopify.com';

$nonce = bin2hex(random_bytes(16));
$statePayload = base64_encode(json_encode(['shop' => $shop, 'nonce' => $nonce, 'ts' => time()]));
$stateHmac = hash_hmac('sha256', $statePayload, SHOPIFY_API_SECRET);
$state = $statePayload . '.' . $stateHmac;

$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_shop'] = $shop;

$params = http_build_query([
    'client_id' => SHOPIFY_API_KEY,
    'scope' => SHOPIFY_SCOPES,
    'redirect_uri' => APP_URL . '/auth/callback.php',
    'state' => $state,
]);

$url = "https://{$shop}/admin/oauth/authorize?{$params}";
header('Location: ' . $url);
exit;