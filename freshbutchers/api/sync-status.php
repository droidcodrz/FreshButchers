<?php
/**
 * FreshButchers SuiteFleet - Sync Shipment Statuses API
 *
 * POST /api/sync-status.php
 * Also callable via GET for cron job compatibility.
 *
 * Fetches pending order mappings from database, queries SuiteFleet
 * for current status, and updates the local database records.
 * Optionally creates Shopify fulfillments when orders are delivered.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/shopify.php';
require_once __DIR__ . '/../includes/suitefleet.php';
require_once __DIR__ . '/../includes/helpers.php';

// Accept both POST and GET (for cron jobs)
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'])) {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// For cron jobs: allow passing shop as query parameter
// For UI calls: use session
session_start();
// $shop = $_GET['shop'] ?? $_SESSION['shop'] ?? null;

// if (!$shop) {
//     jsonResponse(['error' => 'No shop specified. Pass shop as query parameter or authenticate first.'], 401);
$hasDirectToken = defined('SHOPIFY_ACCESS_TOKEN') && SHOPIFY_ACCESS_TOKEN;

if ($hasDirectToken) {
    $shop = SHOPIFY_STORE;
    $shopAccessToken = SHOPIFY_ACCESS_TOKEN;
} else {
    $shop = $_GET['shop'] ?? $_SESSION['shop'] ?? null;
    if (!$shop) {
        jsonResponse(['error' => 'No shop specified. Pass shop as query parameter or authenticate first.'], 401);
    }
}

try {
    $db = Database::getInstance();
    // $session = $db->getSessionByShop($shop);

    // if (!$session || !$session['access_token']) {
    //     jsonResponse(['error' => 'No access token for shop. Please reinstall the app.'], 401);

    if (!$hasDirectToken) {
        $session = $db->getSessionByShop($shop);
        if (!$session || !$session['access_token']) {
            jsonResponse(['error' => 'No access token for shop. Please reinstall the app.'], 401);
        }
        $shopAccessToken = $session['access_token'];
    }

    // ── 1. Get pending mappings from database ──
    // These are orders that have been assigned to SuiteFleet but not yet delivered/cancelled
    $pendingMappings = $db->getPendingMappings($shop);

    if (empty($pendingMappings)) {
        jsonResponse([
            'success' => true,
            'message' => 'No pending shipments to sync',
            'synced' => 0,
            'updated' => 0,
            'results' => [],
        ]);
    }

    // ── 2. Query SuiteFleet for each shipment ──
    $suitefleet = new SuiteFleetAPI();
   $shopify = new ShopifyAPI($shop, $shopAccessToken);

    $synced = 0;
    $updated = 0;
    $results = [];

    foreach ($pendingMappings as $mapping) {
        $shipmentId = $mapping['suitefleet_shipment_id'];
        $mappingId = $mapping['id'];
        $orderName = $mapping['shopify_order_number'] ?? '';

        try {
            // Fetch current status from SuiteFleet
            $statusData = $suitefleet->getShipmentStatus($shipmentId);
            $synced++;

            $newStatus = $statusData['status'] ?? '';
            $oldStatus = $mapping['shipment_status'] ?? '';

            // Build update data
            $updateData = [
                'last_synced_at' => date('Y-m-d H:i:s'),
            ];

            // Only update if status actually changed
            if ($newStatus && $newStatus !== $oldStatus) {
                $updateData['shipment_status'] = $newStatus;

                // Update tracking info if available
                if (!empty($statusData['trackingNumber'])) {
                    $updateData['tracking_number'] = $statusData['trackingNumber'];
                }
                if (!empty($statusData['trackingUrl'])) {
                    $updateData['tracking_url'] = $statusData['trackingUrl'];
                }

                $updated++;

                // If delivered, create Shopify fulfillment
                if ($newStatus === 'delivered') {
                    try {
                        $shopifyOrderId = $mapping['shopify_order_id'];
                        $gid = "gid://shopify/Order/{$shopifyOrderId}";
                        $order = $shopify->fetchOrderById($gid);

                        if ($order) {
                            $trackingNumber = $statusData['trackingNumber'] ?? $mapping['tracking_number'] ?? '';
                            $trackingUrl = $statusData['trackingUrl'] ?? $mapping['tracking_url'] ?? '';

                            if ($trackingNumber) {
                                $shopify->createFulfillment($order, $trackingNumber, $trackingUrl);
                            }

                            // Add delivered tag
                            $shopify->addOrderTags($gid, ['SuiteFleet:Delivered']);
                        }
                    } catch (Exception $fulfillmentEx) {
                        error_log("Sync: Failed to create fulfillment for {$orderName}: " . $fulfillmentEx->getMessage());
                    }
                }

                // If failed, tag the order
                if ($newStatus === 'failed') {
                    try {
                        $gid = "gid://shopify/Order/{$mapping['shopify_order_id']}";
                        $shopify->addOrderTags($gid, ['SuiteFleet:Failed']);
                    } catch (Exception $tagEx) {
                        error_log("Sync: Failed to add Failed tag for {$orderName}: " . $tagEx->getMessage());
                    }
                }

                $results[] = [
                    'orderName' => $orderName,
                    'shipmentId' => $shipmentId,
                    'oldStatus' => $oldStatus,
                    'newStatus' => $newStatus,
                    'changed' => true,
                ];
            } else {
                $results[] = [
                    'orderName' => $orderName,
                    'shipmentId' => $shipmentId,
                    'status' => $oldStatus,
                    'changed' => false,
                ];
            }

            // Update mapping in database
            $db->updateOrderMapping($mappingId, $updateData);

        } catch (Exception $e) {
            error_log("Sync: Failed to fetch status for shipment {$shipmentId} ({$orderName}): " . $e->getMessage());

            // Update last_synced_at even on failure so we don't hammer the API
            $db->updateOrderMapping($mappingId, [
                'last_synced_at' => date('Y-m-d H:i:s'),
            ]);

            $results[] = [
                'orderName' => $orderName,
                'shipmentId' => $shipmentId,
                'error' => $e->getMessage(),
                'changed' => false,
            ];
        }
    }

    // ── 3. Log sync run ──
    $db->logSync($shop, 'sync_statuses', [
        'total_pending' => count($pendingMappings),
    ], [
        'synced' => $synced,
        'updated' => $updated,
    ], 200, true);

    // ── 4. Return results ──
    jsonResponse([
        'success' => true,
        'message' => "Synced {$synced} shipments, {$updated} status changes",
        'synced' => $synced,
        'updated' => $updated,
        'total' => count($pendingMappings),
        'results' => $results,
    ]);

} catch (Exception $e) {
    error_log('Sync status error: ' . $e->getMessage());
    jsonResponse([
        'error' => 'Failed to sync statuses: ' . $e->getMessage(),
    ], 500);
}
