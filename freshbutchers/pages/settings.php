<?php
/**
 * FreshButchers SuiteFleet - Settings Page
 *
 * Display and update SuiteFleet configuration.
 * Test connection to SuiteFleet API.
 * Included by index.php - $shop, $db, $session are already available.
 */

$shop = $_SESSION['shop'] ?? '';
$db   = Database::getInstance();

// Handle form submission (save settings)
$saveMessage = null;
$saveError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_settings') {
        // In a production setup, these would be saved to a settings table in the database.
        // For now we show a confirmation since config.php defines are set at deploy time.
        try {
            $pdo = $db->getPdo();
            $stmt = $pdo->prepare("
                INSERT INTO app_settings (shop, setting_key, setting_value, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");

            $fields = [
                'suitefleet_base_url'   => $_POST['base_url'] ?? '',
                'suitefleet_client_id'  => $_POST['client_id'] ?? '',
                'suitefleet_username'   => $_POST['username'] ?? '',
                'suitefleet_password'   => $_POST['password'] ?? '',
                'suitefleet_customer_id'=> $_POST['customer_id'] ?? '',
                'order_suffix'          => $_POST['order_suffix'] ?? '',
            ];

            foreach ($fields as $key => $value) {
                if ($value !== '') {
                    $stmt->execute([$shop, $key, $value]);
                }
            }

            $saveMessage = 'Settings saved successfully.';
        } catch (Exception $e) {
            $saveError = 'Failed to save settings: ' . $e->getMessage();
        }
    }
}

// Load saved settings from database (if the table exists)
$savedSettings = [];
try {
    $pdo  = $db->getPdo();
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE shop = ?");
    $stmt->execute([$shop]);
    foreach ($stmt->fetchAll() as $row) {
        $savedSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table may not exist yet; fall back to config.php constants
}

// Resolve current values (saved settings override config defaults)
$currentBaseUrl    = $savedSettings['suitefleet_base_url']    ?? SUITEFLEET_BASE_URL;
$currentClientId   = $savedSettings['suitefleet_client_id']   ?? SUITEFLEET_CLIENT_ID;
$currentUsername    = $savedSettings['suitefleet_username']    ?? SUITEFLEET_USERNAME;
$currentPassword   = $savedSettings['suitefleet_password']    ?? SUITEFLEET_PASSWORD;
$currentCustomerId = $savedSettings['suitefleet_customer_id'] ?? SUITEFLEET_CUSTOMER_ID;
$currentSuffix     = $savedSettings['order_suffix']           ?? ORDER_SUFFIX;

// Start output buffering
ob_start();
?>

<div class="page-header">
    <h1>Settings</h1>
    <p>Configure your SuiteFleet integration for <?= htmlspecialchars($shop) ?></p>
</div>

<!-- Banner for feedback messages -->
<div id="settingsBanner" class="banner <?= $saveMessage ? 'banner-success show' : '' ?><?= $saveError ? 'banner-error show' : '' ?>">
    <?= htmlspecialchars($saveMessage ?? $saveError ?? '') ?>
</div>

<!-- SuiteFleet Configuration -->
<div class="card">
    <div class="card-header">SuiteFleet API Configuration</div>

    <form method="POST" action="index.php?shop=<?= urlencode($shop) ?>&page=settings" id="settingsForm">
        <input type="hidden" name="action" value="save_settings">

        <div class="form-row">
            <div class="form-group">
                <label for="base_url">API Base URL</label>
                <input type="url" name="base_url" id="base_url" class="form-control"
                       value="<?= htmlspecialchars($currentBaseUrl) ?>"
                       placeholder="https://api.suitefleet.com">
                <div class="form-hint">SuiteFleet API endpoint</div>
            </div>
            <div class="form-group">
                <label for="client_id">Client ID</label>
                <input type="text" name="client_id" id="client_id" class="form-control"
                       value="<?= htmlspecialchars($currentClientId) ?>"
                       placeholder="transcorpsb">
                <div class="form-hint">Provided by Transcorp / SuiteFleet</div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="email" name="username" id="username" class="form-control"
                       value="<?= htmlspecialchars($currentUsername) ?>"
                       placeholder="user@example.com">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control"
                       value="<?= htmlspecialchars($currentPassword) ?>"
                       placeholder="Enter password">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="customer_id">Customer ID</label>
                <input type="text" name="customer_id" id="customer_id" class="form-control"
                       value="<?= htmlspecialchars($currentCustomerId) ?>"
                       placeholder="578">
                <div class="form-hint">Your customer ID in SuiteFleet</div>
            </div>
            <div class="form-group">
                <label for="order_suffix">Order Reference Suffix</label>
                <input type="text" name="order_suffix" id="order_suffix" class="form-control"
                       value="<?= htmlspecialchars($currentSuffix) ?>"
                       placeholder="FBC" maxlength="10">
                <div class="form-hint">Appended to order numbers (e.g., 001001FBC)</div>
            </div>
        </div>

        <div class="mt-16 btn-group">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="button" class="btn btn-secondary" id="btnTestConnection" onclick="testConnection()">
                Test Connection
            </button>
        </div>
    </form>
</div>

<!-- Current Status -->
<div class="card">
    <div class="card-header">Connection Status</div>
    <div id="connectionStatus">
        <p class="text-muted">Click "Test Connection" to verify your SuiteFleet API credentials.</p>
    </div>
</div>

<!-- App Info -->
<div class="card">
    <div class="card-header">App Information</div>
    <div class="table-wrapper">
        <table>
            <tbody>
                <tr>
                    <td class="text-muted" style="width:200px;">Shop</td>
                    <td><?= htmlspecialchars($shop) ?></td>
                </tr>
                <tr>
                    <td class="text-muted">Shopify API Version</td>
                    <td><?= htmlspecialchars(SHOPIFY_API_VERSION) ?></td>
                </tr>
                <tr>
                    <td class="text-muted">SuiteFleet Portal</td>
                    <td><a href="<?= htmlspecialchars(SUITEFLEET_PORTAL_URL) ?>" target="_blank"><?= htmlspecialchars(SUITEFLEET_PORTAL_URL) ?></a></td>
                </tr>
                <tr>
                    <td class="text-muted">Timezone</td>
                    <td><?= htmlspecialchars(TIMEZONE) ?></td>
                </tr>
                <tr>
                    <td class="text-muted">Sync Interval</td>
                    <td>Every <?= (int)SYNC_INTERVAL_MINUTES ?> minutes</td>
                </tr>
                <tr>
                    <td class="text-muted">Order Reference Format</td>
                    <td>6-digit padded + suffix (e.g., 001001<?= htmlspecialchars($currentSuffix) ?>)</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
/**
 * Test the SuiteFleet API connection using current form values
 */
function testConnection() {
    var btn = document.getElementById('btnTestConnection');
    var statusDiv = document.getElementById('connectionStatus');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Testing...';
    statusDiv.innerHTML = '<p class="text-muted"><span class="spinner"></span> Connecting to SuiteFleet API...</p>';

    var payload = {
        base_url: document.getElementById('base_url').value,
        client_id: document.getElementById('client_id').value,
        username: document.getElementById('username').value,
        password: document.getElementById('password').value
    };

    fetch('api/test-connection.php?shop=<?= urlencode($shop) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        if (data.success) {
            statusDiv.innerHTML = '<div class="banner banner-success show" style="margin:0;">' +
                'Connection successful! Authenticated with SuiteFleet API.' +
                '</div>';
            showBanner('success', 'SuiteFleet connection test passed.', 'settingsBanner');
        } else {
            statusDiv.innerHTML = '<div class="banner banner-error show" style="margin:0;">' +
                'Connection failed: ' + (data.error || 'Unknown error') +
                '</div>';
            showBanner('error', 'Connection test failed: ' + (data.error || 'Unknown error'), 'settingsBanner');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        statusDiv.innerHTML = '<div class="banner banner-error show" style="margin:0;">' +
            'Network error: ' + err.message +
            '</div>';
        showBanner('error', 'Network error: ' + err.message, 'settingsBanner');
    });
}
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Settings - FreshButchers SuiteFleet';
include __DIR__ . '/layout.php';
?>
