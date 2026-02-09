<?php
/**
 * FreshButchers SuiteFleet - Assign Single Order API
 *
 * POST /api/assign-order.php
 * Body: { "orderId": "gid://shopify/Order/123", "shippingMethod": "Standard" }
 *
 * Fetches the order from Shopify, creates a SuiteFleet task,
 * saves the mapping, and updates order tags/metafields.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/shopify.php';
require_once __DIR__ . '/../includes/suitefleet.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

session_start();

try {
    // ── 1. Get shop from session ──
    // $shop = $_SESSION['shop'] ?? $_GET['shop'] ?? null;

    // if (!$shop) {
    //     jsonResponse(['error' => 'No shop in session. Please authenticate first.'], 401);
    // }

    // Verify we have an access token
    $db = Database::getInstance();
    // $session = $db->getSessionByShop($shop);

    // if (!$session || !$session['access_token']) {
    //     jsonResponse(['error' => 'No access token for shop. Please reinstall the app.'], 401);

     $hasDirectToken = defined('SHOPIFY_ACCESS_TOKEN') && SHOPIFY_ACCESS_TOKEN;

    if ($hasDirectToken) {
        $shop = SHOPIFY_STORE;
        $shopAccessToken = SHOPIFY_ACCESS_TOKEN;
    } else {
        $shop = $_SESSION['shop'] ?? $_GET['shop'] ?? null;
        if (!$shop) {
            jsonResponse(['error' => 'No shop in session. Please authenticate first.'], 401);
        }
        $session = $db->getSessionByShop($shop);
        if (!$session || !$session['access_token']) {
            jsonResponse(['error' => 'No access token for shop. Please reinstall the app.'], 401);
        }
        $shopAccessToken = $session['access_token'];
     }

    // ── 2. Read JSON POST body ──
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['orderId'])) {
        jsonResponse(['error' => 'Missing required field: orderId'], 400);
    }

    $orderId = $input['orderId'];
    $shippingMethodOverride = $input['shippingMethod'] ?? null;

    // ── 3. Fetch order from Shopify ──
   $shopify = new ShopifyAPI($shop, $shopAccessToken);
    $order = $shopify->fetchOrderById($orderId);

    if (!$order) {
        jsonResponse(['error' => 'Order not found in Shopify', 'orderId' => $orderId], 404);
    }

    // Check if already assigned (idempotency)
    $orderTags = $order['tags'] ?? [];
    if (in_array('SuiteFleet:Assigned', $orderTags)) {
        // Extract numeric ID from GID
        $numericId = preg_replace('/.*\//', '', $orderId);
        $existingMapping = $db->getOrderMapping($shop, $numericId);
        jsonResponse([
            'error' => 'Order is already assigned to SuiteFleet',
            'mapping' => $existingMapping,
        ], 409);
    }

    // ── 4. Prepare order data for SuiteFleet ──
    $orderData = prepareOrderForSuiteFleet($order);

    // Apply shipping method override if provided
    if ($shippingMethodOverride) {
        $orderData['shippingMethod'] = $shippingMethodOverride;
    }

    // ── 5. Create SuiteFleet task ──
    $suitefleet = new SuiteFleetAPI();
    $taskResult = $suitefleet->createTask($orderData);

    // ── 6. Save mapping to database ──
    $address = $order['shippingAddress'] ?? [];
    $lineItems = [];
    foreach (($order['lineItems']['edges'] ?? []) as $edge) {
        $item = $edge['node'];
        $lineItems[] = ($item['title'] ?? '') . ' x' . ($item['quantity'] ?? 1);
    }

    // Extract numeric order ID from GraphQL GID
    $numericOrderId = preg_replace('/.*\//', '', $orderId);

    $mappingId = $db->saveOrderMapping([
        'shop' => $shop,
        'shopify_order_id' => $numericOrderId,
        'shopify_order_number' => $order['name'] ?? '',
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

    // ── 7. Update tags on Shopify order ──
    $shopify->removeOrderTags($orderId, ['SuiteFleet:Pending']);
    $shopify->addOrderTags($orderId, ['SuiteFleet:Assigned']);

    // ── 8. Set metafields with SuiteFleet IDs ──
    $metafields = [
        'shipment_id' => $taskResult['shipmentId'] ?? '',
        'tracking_number' => $taskResult['trackingNumber'] ?? '',
        'order_reference' => $orderData['orderReference'],
        'portal_url' => buildPortalUrl($taskResult['shipmentId']),
    ];
    $shopify->setOrderMetafields($orderId, $metafields);

    // ── 9. Log and respond ──
    $db->logSync($shop, 'assign_order', [
        'order_id' => $numericOrderId,
        'order_name' => $order['name'] ?? '',
    ], $taskResult, 200, true);

    jsonResponse([
        'success' => true,
        'message' => 'Order assigned to SuiteFleet successfully',
        'order' => [
            'shopifyOrderId' => $numericOrderId,
            'orderName' => $order['name'] ?? '',
            'orderReference' => $orderData['orderReference'],
        ],
        'suitefleet' => [
            'shipmentId' => $taskResult['shipmentId'],
            'taskId' => $taskResult['taskId'],
            'trackingNumber' => $taskResult['trackingNumber'],
            'trackingUrl' => $taskResult['trackingUrl'],
            'status' => $taskResult['status'],
        ],
    ]);

} catch (Exception $e) {
    error_log('Assign order error: ' . $e->getMessage());

    // Log failure
    try {
        $db = Database::getInstance();
        $db->logSync($shop ?? 'unknown', 'assign_order', [
            'order_id' => $input['orderId'] ?? null,
        ], null, 500, false, $e->getMessage());
    } catch (Exception $logEx) {
        // Ignore logging errors
    }

    jsonResponse([
        'error' => 'Failed to assign order: ' . $e->getMessage(),
    ], 500);
}
