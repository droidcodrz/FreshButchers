<?php
/**
 * FreshButchers SuiteFleet - Webhook Handler
 *
 * Receives and processes Shopify webhooks:
 *   - orders/create: Log new orders
 *   - orders/updated: Auto-assign to SuiteFleet when tagged "SuiteFleet:Pending"
 *   - orders/cancelled: Update mapping status to cancelled
 *
 * Shopify sends webhooks as POST with:
 *   - X-Shopify-Hmac-Sha256: HMAC verification header
 *   - X-Shopify-Topic: e.g., "orders/updated"
 *   - X-Shopify-Shop-Domain: e.g., "freshbutchers.myshopify.com"
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/shopify.php';
require_once __DIR__ . '/includes/suitefleet.php';
require_once __DIR__ . '/includes/helpers.php';

// ── 1. Read raw POST body ──
$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    exit;
}

// ── 2. Verify HMAC ──
$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';

if (!$hmacHeader || !ShopifyAPI::verifyWebhookHmac($rawBody, $hmacHeader)) {
    error_log('Webhook HMAC verification failed');
    http_response_code(401);
    exit;
}

// ── 3. Extract headers ──
$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';
$shop = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '';

if (!$topic || !$shop) {
    http_response_code(400);
    exit;
}

// ── 4. Decode JSON payload ──
$payload = json_decode($rawBody, true);

if (!$payload) {
    error_log("Webhook: Failed to decode JSON payload for topic={$topic} shop={$shop}");
    http_response_code(400);
    exit;
}

// ── 5. Process based on topic ──
$db = Database::getInstance();

try {
    switch ($topic) {
        case 'orders/updated':
            handleOrderUpdated($shop, $payload, $db);
            break;

        case 'orders/create':
            // Log the new order creation; assignment happens on update with tag
            $db->logSync($shop, 'webhook_order_created', [
                'order_id' => $payload['id'] ?? null,
                'order_name' => $payload['name'] ?? null,
            ], null, 200, true);
            break;

        case 'orders/cancelled':
            handleOrderCancelled($shop, $payload, $db);
            break;

        default:
            error_log("Webhook: Unhandled topic={$topic} for shop={$shop}");
            break;
    }
} catch (Exception $e) {
    error_log("Webhook error [{$topic}] shop={$shop}: " . $e->getMessage());

    $db->logSync($shop, "webhook_{$topic}", [
        'order_id' => $payload['id'] ?? null,
    ], null, 500, false, $e->getMessage());
}

// Always return 200 to Shopify so it doesn't retry indefinitely
http_response_code(200);
exit;

// ═══════════════════════════════════════
// Handler Functions
// ═══════════════════════════════════════

/**
 * Handle orders/updated webhook
 *
 * If the order has tag "SuiteFleet:Pending" and NOT "SuiteFleet:Assigned",
 * create a SuiteFleet task, save the mapping, and update tags.
 */
function handleOrderUpdated($shop, $payload, $db) {
    $orderId = $payload['admin_graphql_api_id'] ?? null;
    $orderName = $payload['name'] ?? '';
    $tags = $payload['tags'] ?? '';

    // Parse tags string into array
    $tagList = array_map('trim', explode(',', $tags));

    $hasPending = in_array('SuiteFleet:Pending', $tagList);
    $hasAssigned = in_array('SuiteFleet:Assigned', $tagList);

    // Only process if tagged as Pending and not yet Assigned
    if (!$hasPending || $hasAssigned) {
        return;
    }

    // Check if already mapped (idempotency)
    $shopifyOrderId = (string)$payload['id'];
    $existingMapping = $db->getOrderMapping($shop, $shopifyOrderId);
    if ($existingMapping && $existingMapping['suitefleet_shipment_id']) {
        error_log("Webhook: Order {$orderName} already assigned to SuiteFleet, skipping.");
        return;
    }

    // Prepare order data from webhook payload (REST format)
    $orderData = prepareOrderFromWebhook($payload);

    // Create SuiteFleet task
    $suitefleet = new SuiteFleetAPI();
    $taskResult = $suitefleet->createTask($orderData);

    // Save mapping to database
    $address = $payload['shipping_address'] ?? [];
    $lineItems = [];
    foreach (($payload['line_items'] ?? []) as $item) {
        $lineItems[] = $item['title'] . ' x' . $item['quantity'];
    }

    $db->saveOrderMapping([
        'shop' => $shop,
        'shopify_order_id' => $shopifyOrderId,
        'shopify_order_number' => $orderName,
        'suitefleet_order_ref' => $orderData['orderReference'],
        'suitefleet_shipment_id' => $taskResult['shipmentId'],
        'suitefleet_task_id' => $taskResult['taskId'],
        'tracking_number' => $taskResult['trackingNumber'],
        'tracking_url' => $taskResult['trackingUrl'],
        'shipment_status' => $taskResult['status'] ?? 'created',
        'shipping_method' => $orderData['shippingMethod'],
        'customer_name' => $orderData['customerName'],
        'customer_email' => $orderData['customerEmail'],
        'customer_phone' => $orderData['customerPhone'],
        'delivery_address' => trim(($address['address1'] ?? '') . ', ' . ($address['city'] ?? '') . ', ' . ($address['country'] ?? ''), ', '),
        'order_items' => implode('; ', $lineItems),
    ]);

    // Update tags on Shopify: remove Pending, add Assigned
    $shopify = new ShopifyAPI($shop);

    if ($orderId) {
        $shopify->removeOrderTags($orderId, ['SuiteFleet:Pending']);
        $shopify->addOrderTags($orderId, ['SuiteFleet:Assigned']);

        // Set metafields with SuiteFleet IDs
        $metafields = [
            'shipment_id' => $taskResult['shipmentId'] ?? '',
            'tracking_number' => $taskResult['trackingNumber'] ?? '',
            'order_reference' => $orderData['orderReference'],
        ];
        $shopify->setOrderMetafields($orderId, $metafields);
    }

    // Log success
    $db->logSync($shop, 'webhook_order_assigned', [
        'order_id' => $shopifyOrderId,
        'order_name' => $orderName,
    ], $taskResult, 200, true);

    error_log("Webhook: Order {$orderName} assigned to SuiteFleet (shipment={$taskResult['shipmentId']})");
}

/**
 * Handle orders/cancelled webhook
 *
 * Update the order mapping status to cancelled.
 */
function handleOrderCancelled($shop, $payload, $db) {
    $shopifyOrderId = (string)$payload['id'];
    $orderName = $payload['name'] ?? '';

    $mapping = $db->getOrderMapping($shop, $shopifyOrderId);

    if ($mapping) {
        $db->updateOrderMappingByOrder($shop, $shopifyOrderId, [
            'shipment_status' => 'cancelled',
        ]);

        $db->logSync($shop, 'webhook_order_cancelled', [
            'order_id' => $shopifyOrderId,
            'order_name' => $orderName,
        ], null, 200, true);

        error_log("Webhook: Order {$orderName} marked as cancelled");
    }
}
