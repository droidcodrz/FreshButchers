<?php
/**
 * FreshButchers SuiteFleet - Process All Pending Orders API
 *
 * POST /api/process-pending.php
 *
 * Fetches all Shopify orders tagged "SuiteFleet:Pending",
 * creates SuiteFleet tasks for each, saves mappings, and updates tags.
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

    // ── 2. Fetch orders tagged "SuiteFleet:Pending" from Shopify ──
    $shopify = new ShopifyAPI($shop, $shopAccessToken);

    $query = '
        query getPendingOrders($first: Int!, $query: String) {
            orders(first: $first, query: $query, sortKey: CREATED_AT, reverse: true) {
                edges {
                    node {
                        id name displayFulfillmentStatus createdAt
                        totalPriceSet { shopMoney { amount currencyCode } }
                        customer { firstName lastName email phone displayName }
                        shippingAddress { firstName lastName name address1 address2 city province country zip latitude longitude }
                        shippingLine { title code }
                        lineItems(first: 50) { edges { node { id title quantity sku variant { price weight weightUnit } } } }
                        fulfillmentOrders(first: 10) { edges { node { id status lineItems(first: 50) { edges { node { id totalQuantity remainingQuantity } } } } } }
                        note tags
                    }
                }
                pageInfo { hasNextPage }
            }
        }
    ';

    $result = $shopify->graphql($query, [
        'first' => 50,
        'query' => 'tag:SuiteFleet\\:Pending -tag:SuiteFleet\\:Assigned',
    ]);

    $orders = $result['data']['orders']['edges'] ?? [];

    if (empty($orders)) {
        jsonResponse([
            'success' => true,
            'message' => 'No pending orders found',
            'processed' => 0,
            'failed' => 0,
            'results' => [],
        ]);
    }

    // ── 3. Process each order ──
    $suitefleet = new SuiteFleetAPI();
    $processed = 0;
    $failed = 0;
    $results = [];

    foreach ($orders as $edge) {
        $order = $edge['node'];
        $orderId = $order['id'];
        $orderName = $order['name'] ?? '';
        $numericOrderId = preg_replace('/.*\//', '', $orderId);

        try {
            // Check if already mapped (idempotency)
            $existingMapping = $db->getOrderMapping($shop, $numericOrderId);
            if ($existingMapping && $existingMapping['suitefleet_shipment_id']) {
                // Already assigned, just fix the tags
                $shopify->removeOrderTags($orderId, ['SuiteFleet:Pending']);
                $shopify->addOrderTags($orderId, ['SuiteFleet:Assigned']);

                $results[] = [
                    'orderName' => $orderName,
                    'status' => 'skipped',
                    'reason' => 'Already assigned',
                ];
                continue;
            }

            // Prepare order data
            $orderData = prepareOrderForSuiteFleet($order);

            // Create SuiteFleet task
            $taskResult = $suitefleet->createTask($orderData);

            // Save mapping
            $address = $order['shippingAddress'] ?? [];
            $lineItems = [];
            foreach (($order['lineItems']['edges'] ?? []) as $liEdge) {
                $item = $liEdge['node'];
                $lineItems[] = ($item['title'] ?? '') . ' x' . ($item['quantity'] ?? 1);
            }

            $db->saveOrderMapping([
                'shop' => $shop,
                'shopify_order_id' => $numericOrderId,
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

            // Update tags
            $shopify->removeOrderTags($orderId, ['SuiteFleet:Pending']);
            $shopify->addOrderTags($orderId, ['SuiteFleet:Assigned']);

            // Set metafields
            $shopify->setOrderMetafields($orderId, [
                'shipment_id' => $taskResult['shipmentId'] ?? '',
                'tracking_number' => $taskResult['trackingNumber'] ?? '',
                'order_reference' => $orderData['orderReference'],
                'portal_url' => buildPortalUrl($taskResult['shipmentId']),
            ]);

            $processed++;
            $results[] = [
                'orderName' => $orderName,
                'status' => 'assigned',
                'shipmentId' => $taskResult['shipmentId'],
                'trackingNumber' => $taskResult['trackingNumber'],
            ];

            // Log individual success
            $db->logSync($shop, 'process_pending_order', [
                'order_id' => $numericOrderId,
                'order_name' => $orderName,
            ], $taskResult, 200, true);

        } catch (Exception $e) {
            $failed++;
            $results[] = [
                'orderName' => $orderName,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];

            error_log("Process pending: Failed to assign order {$orderName}: " . $e->getMessage());

            $db->logSync($shop, 'process_pending_order', [
                'order_id' => $numericOrderId,
                'order_name' => $orderName,
            ], null, 500, false, $e->getMessage());
        }
    }

    // ── 4. Return results ──
    jsonResponse([
        'success' => true,
        'message' => "Processed {$processed} orders" . ($failed > 0 ? ", {$failed} failed" : ''),
        'processed' => $processed,
        'failed' => $failed,
        'total' => count($orders),
        'results' => $results,
    ]);

} catch (Exception $e) {
    error_log('Process pending error: ' . $e->getMessage());
    jsonResponse([
        'error' => 'Failed to process pending orders: ' . $e->getMessage(),
    ], 500);
}
