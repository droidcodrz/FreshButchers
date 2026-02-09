<?php
/**
 * FreshButchers SuiteFleet - Orders Page
 *
 * Fetches unfulfilled orders from Shopify, shows assignment status,
 * and allows assigning orders to Transcorp via SuiteFleet.
 * Included by index.php - $shop, $db, $session are already available.
 */

$shop = $_SESSION['shop'] ?? '';
$db   = Database::getInstance();

// Initialize Shopify API - use direct token if available
$directToken = (defined('SHOPIFY_ACCESS_TOKEN') && SHOPIFY_ACCESS_TOKEN) ? SHOPIFY_ACCESS_TOKEN : null;
$shopifyAPI = new ShopifyAPI($shop, $directToken);

$orders   = [];
$fetchErr = null;

try {
    $result = $shopifyAPI->fetchOrders('unfulfilled', 50);
    $orders = $result['edges'] ?? [];
} catch (Exception $e) {
    $fetchErr = $e->getMessage();
}

// Load all existing mappings for this shop (keyed by Shopify order ID)
$allMappings = $db->getOrderMappings($shop, null, 200);
$mappingsByOrderId = [];
foreach ($allMappings as $m) {
    $mappingsByOrderId[$m['shopify_order_id']] = $m;
}

// Start output buffering
ob_start();
?>

<div class="page-header flex-between">
    <div>
        <h1>Orders</h1>
        <p>Unfulfilled Shopify orders &mdash; assign to Transcorp Logistics via SuiteFleet</p>
    </div>
    <div class="btn-group">
        <button class="btn btn-primary" id="btnProcessPending" onclick="processPendingOrders()">
            Process Pending Orders
        </button>
        <a href="index.php?shop=<?= urlencode($shop) ?>&page=orders" class="btn btn-secondary">
            Refresh
        </a>
    </div>
</div>

<!-- Banner for feedback messages -->
<div id="ordersBanner" class="banner"></div>

<?php if ($fetchErr): ?>
    <div class="banner banner-error show">
        Failed to fetch orders from Shopify: <?= htmlspecialchars($fetchErr) ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header flex-between">
        <span>Unfulfilled Orders (<?= count($orders) ?>)</span>
    </div>

    <?php if (empty($orders)): ?>
        <div class="empty-state">
            <p>No unfulfilled orders found. Orders will appear here once placed in your Shopify store.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Location</th>
                        <th>Total</th>
                        <th>Shipping</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $edge):
                        $order       = $edge['node'];
                        $orderId     = $order['id'];           // GraphQL GID
                        $orderName   = $order['name'] ?? '-';
                        $customer    = getCustomerName($order);
                        $address     = $order['shippingAddress'] ?? [];
                        $city        = $address['city'] ?? '';
                        $country     = $address['country'] ?? '';
                        $location    = trim($city . ($city && $country ? ', ' : '') . $country);
                        $total       = ($order['totalPriceSet']['shopMoney']['amount'] ?? '0')
                                     . ' ' . ($order['totalPriceSet']['shopMoney']['currencyCode'] ?? 'AED');
                        $shipping    = $order['shippingLine']['title'] ?? 'Standard';
                        $fulfillment = $order['displayFulfillmentStatus'] ?? 'UNFULFILLED';

                        // Check if already assigned
                        $mapping   = $mappingsByOrderId[$orderId] ?? null;
                        $isAssigned = !empty($mapping);
                    ?>
                    <tr id="order-row-<?= htmlspecialchars($orderId) ?>">
                        <td><strong><?= htmlspecialchars($orderName) ?></strong></td>
                        <td><?= htmlspecialchars($customer) ?></td>
                        <td><?= htmlspecialchars($location ?: '-') ?></td>
                        <td><?= htmlspecialchars($total) ?></td>
                        <td><?= htmlspecialchars($shipping) ?></td>
                        <td>
                            <?php if ($isAssigned): ?>
                                <?= statusBadge($mapping['shipment_status'] ?? 'created') ?>
                            <?php else: ?>
                                <?= statusBadge('unfulfilled') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isAssigned): ?>
                                <span class="text-muted text-sm">
                                    Assigned
                                    <?php if (!empty($mapping['suitefleet_order_ref'])): ?>
                                        (<?= htmlspecialchars($mapping['suitefleet_order_ref']) ?>)
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <button class="btn btn-primary btn-sm"
                                        id="assign-btn-<?= md5($orderId) ?>"
                                        onclick="assignOrder('<?= htmlspecialchars(addslashes($orderId)) ?>', '<?= htmlspecialchars(addslashes($orderName)) ?>', this)">
                                    Assign to Transcorp
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
/**
 * Assign a single order to Transcorp via SuiteFleet
 */
function assignOrder(orderId, orderName, btn) {
    if (!confirm('Assign order ' + orderName + ' to Transcorp Logistics?')) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Assigning...';

    fetch('api/assign-order.php?shop=<?= urlencode($shop) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: orderId })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showBanner('success', 'Order ' + orderName + ' assigned successfully. Ref: ' + (data.reference || '-'), 'ordersBanner');
            // Replace the button with "Assigned" text
            btn.outerHTML = '<span class="text-muted text-sm">Assigned (' + (data.reference || '-') + ')</span>';
        } else {
            btn.disabled = false;
            btn.textContent = 'Assign to Transcorp';
            showBanner('error', 'Failed to assign ' + orderName + ': ' + (data.error || 'Unknown error'), 'ordersBanner');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Assign to Transcorp';
        showBanner('error', 'Network error: ' + err.message, 'ordersBanner');
    });
}

/**
 * Process all pending orders in bulk
 */
function processPendingOrders() {
    var btn = document.getElementById('btnProcessPending');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Processing...';

    fetch('api/process-pending.php?shop=<?= urlencode($shop) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Process Pending Orders';
        if (data.success) {
            showBanner('success', 'Processed ' + (data.processed || 0) + ' pending order(s).', 'ordersBanner');
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            showBanner('error', data.error || 'Failed to process pending orders.', 'ordersBanner');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Process Pending Orders';
        showBanner('error', 'Network error: ' + err.message, 'ordersBanner');
    });
}
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Orders - FreshButchers SuiteFleet';
include __DIR__ . '/layout.php';
?>
