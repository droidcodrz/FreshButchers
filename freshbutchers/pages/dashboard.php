<?php
/**
 * FreshButchers SuiteFleet - Dashboard Page
 *
 * Shows stats overview, recent shipments, and quick actions.
 * Included by index.php - $shop, $db, $session are already available.
 */

$shop = $_SESSION['shop'] ?? '';
$db   = Database::getInstance();

// Fetch stats and recent mappings
$stats    = $db->getStats($shop);
$recent   = $db->getOrderMappings($shop, null, 10);

// Start output buffering to capture page content
ob_start();
?>

<div class="page-header flex-between">
    <div>
        <h1>Dashboard</h1>
        <p>Overview of your SuiteFleet shipments for <?= htmlspecialchars($shop) ?></p>
    </div>
    <div class="btn-group">
        <button class="btn btn-primary" id="btnProcessPending" onclick="processPendingOrders()">
            Process Pending Orders
        </button>
        <a href="<?= htmlspecialchars(buildPortalUrl()) ?>" target="_blank" class="btn btn-secondary">
            SuiteFleet Portal &rarr;
        </a>
    </div>
</div>

<!-- Banner for feedback messages -->
<div id="dashboardBanner" class="banner"></div>

<!-- Stat Cards -->
<div class="stats-grid">
    <div class="stat-card stat-total">
        <div class="stat-value"><?= (int)$stats['total'] ?></div>
        <div class="stat-label">Total Shipments</div>
    </div>
    <div class="stat-card stat-transit">
        <div class="stat-value"><?= (int)$stats['in_transit'] ?></div>
        <div class="stat-label">In Transit</div>
    </div>
    <div class="stat-card stat-delivered">
        <div class="stat-value"><?= (int)$stats['delivered'] ?></div>
        <div class="stat-label">Delivered</div>
    </div>
    <div class="stat-card stat-failed">
        <div class="stat-value"><?= (int)$stats['failed'] ?></div>
        <div class="stat-label">Failed</div>
    </div>
</div>

<!-- Recent Shipments -->
<div class="card">
    <div class="card-header flex-between">
        <span>Recent Shipments</span>
        <a href="index.php?shop=<?= urlencode($shop) ?>&page=shipments" class="text-sm">View All &rarr;</a>
    </div>

    <?php if (empty($recent)): ?>
        <div class="empty-state">
            <p>No shipments yet. Assign orders from the <a href="index.php?shop=<?= urlencode($shop) ?>&page=orders">Orders</a> page to get started.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>SF Reference</th>
                        <th>Tracking</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $mapping): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($mapping['shopify_order_number'] ?? '-') ?></strong>
                            </td>
                            <td>
                                <?= htmlspecialchars($mapping['suitefleet_order_ref'] ?? '-') ?>
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
                            <td><?= htmlspecialchars($mapping['shipping_method'] ?? 'Standard') ?></td>
                            <td><?= statusBadge($mapping['shipment_status'] ?? 'pending') ?></td>
                            <td class="text-muted text-sm">
                                <?= $mapping['updated_at'] ? date('M j, g:i A', strtotime($mapping['updated_at'])) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
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
            showBanner('success', 'Processed ' + (data.processed || 0) + ' pending order(s).', 'dashboardBanner');
            // Reload after a short delay to show updated stats
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            showBanner('error', data.error || 'Failed to process pending orders.', 'dashboardBanner');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Process Pending Orders';
        showBanner('error', 'Network error: ' + err.message, 'dashboardBanner');
    });
}
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Dashboard - FreshButchers SuiteFleet';
include __DIR__ . '/layout.php';
?>
