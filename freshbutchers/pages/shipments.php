<?php
/**
 * FreshButchers SuiteFleet - Shipments Page
 *
 * Shows all order-to-shipment mappings with tracking details
 * and provides a manual sync button.
 * Included by index.php - $shop, $db, $session are already available.
 */

$shop = $_SESSION['shop'] ?? '';
$db   = Database::getInstance();

// Fetch all mappings for this shop
$mappings = $db->getOrderMappings($shop, null, 200);

// Start output buffering
ob_start();
?>

<div class="page-header flex-between">
    <div>
        <h1>Shipments</h1>
        <p>Track all shipments dispatched through Transcorp Logistics (SuiteFleet)</p>
    </div>
    <div class="btn-group">
        <button class="btn btn-primary" id="btnSyncNow" onclick="syncStatus()">
            Sync Now
        </button>
        <a href="<?= htmlspecialchars(buildPortalUrl()) ?>" target="_blank" class="btn btn-secondary">
            SuiteFleet Portal &rarr;
        </a>
    </div>
</div>

<!-- Banner for feedback messages -->
<div id="shipmentsBanner" class="banner"></div>

<div class="card">
    <div class="card-header flex-between">
        <span>All Shipments (<?= count($mappings) ?>)</span>
        <span class="text-muted text-sm">
            Auto-sync every <?= (int)SYNC_INTERVAL_MINUTES ?> minutes
        </span>
    </div>

    <?php if (empty($mappings)): ?>
        <div class="empty-state">
            <p>No shipments found. Assign orders from the <a href="index.php?shop=<?= urlencode($shop) ?>&page=orders">Orders</a> page to create shipments.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>SF Reference</th>
                        <th>Shipment ID</th>
                        <th>Tracking #</th>
                        <th>Status</th>
                        <th>Method</th>
                        <th>Last Synced</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mappings as $mapping): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($mapping['shopify_order_number'] ?? '-') ?></strong>
                                <?php if (!empty($mapping['customer_name'])): ?>
                                    <br><span class="text-muted text-sm"><?= htmlspecialchars($mapping['customer_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($mapping['suitefleet_order_ref'] ?? '-') ?></td>
                            <td>
                                <?php if (!empty($mapping['suitefleet_shipment_id'])): ?>
                                    <a href="<?= htmlspecialchars(buildPortalUrl($mapping['suitefleet_shipment_id'])) ?>"
                                       target="_blank"
                                       title="View in SuiteFleet Portal">
                                        <?= htmlspecialchars($mapping['suitefleet_shipment_id']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($mapping['tracking_number'])): ?>
                                    <?php if (!empty($mapping['tracking_url'])): ?>
                                        <a href="<?= htmlspecialchars($mapping['tracking_url']) ?>" target="_blank">
                                            <?= htmlspecialchars($mapping['tracking_number']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($mapping['tracking_number']) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= statusBadge($mapping['shipment_status'] ?? 'pending') ?></td>
                            <td><?= htmlspecialchars($mapping['shipping_method'] ?? 'Standard') ?></td>
                            <td class="text-muted text-sm">
                                <?php if (!empty($mapping['last_synced_at'])): ?>
                                    <?= date('M j, g:i A', strtotime($mapping['last_synced_at'])) ?>
                                <?php else: ?>
                                    Never
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
 * Trigger a manual sync of all active shipment statuses
 */
function syncStatus() {
    var btn = document.getElementById('btnSyncNow');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Syncing...';

    fetch('api/sync-status.php?shop=<?= urlencode($shop) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Sync Now';
        if (data.success) {
            var msg = 'Sync complete. Updated ' + (data.updated || 0) + ' of ' + (data.total || 0) + ' shipment(s).';
            showBanner('success', msg, 'shipmentsBanner');
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            showBanner('error', data.error || 'Sync failed.', 'shipmentsBanner');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Sync Now';
        showBanner('error', 'Network error: ' + err.message, 'shipmentsBanner');
    });
}
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Shipments - FreshButchers SuiteFleet';
include __DIR__ . '/layout.php';
?>
