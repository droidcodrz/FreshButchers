<?php
/**
 * PHP Diagnostic Test Script
 *
 * Open in browser: http://localhost/php/test.php
 * Tests: PHP version, extensions, MySQL, SuiteFleet API
 */

require_once __DIR__ . '/config.php';

echo "<html><head><title>FreshButchers SuiteFleet - Diagnostic</title>
<style>body{font-family:Arial,sans-serif;max-width:800px;margin:40px auto;padding:20px}
.pass{color:#2a9d8f;font-weight:bold}.fail{color:#e76f51;font-weight:bold}
.section{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:20px;margin:20px 0}
h2{margin-top:0}pre{background:#333;color:#0f0;padding:15px;border-radius:5px;overflow-x:auto}</style></head><body>";

echo "<h1>FreshButchers SuiteFleet - Diagnostic Test</h1>";

$allPassed = true;

// ═══════════════════════════════════════
// STEP 1: PHP VERSION & EXTENSIONS
// ═══════════════════════════════════════
echo '<div class="section"><h2>Step 1: PHP Environment</h2>';
echo "<p>PHP Version: <b>" . phpversion() . "</b> ";
echo (version_compare(phpversion(), '7.4.0', '>=')) ? '<span class="pass">✅ OK</span>' : '<span class="fail">❌ Need 7.4+</span>';
echo "</p>";

$requiredExtensions = ['curl', 'pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'];
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "<p>Extension <b>$ext</b>: ";
    echo $loaded ? '<span class="pass">✅ Loaded</span>' : '<span class="fail">❌ Missing</span>';
    echo "</p>";
    if (!$loaded) $allPassed = false;
}
echo '</div>';

// ═══════════════════════════════════════
// STEP 2: CONFIG CHECK
// ═══════════════════════════════════════
echo '<div class="section"><h2>Step 2: Configuration</h2>';
$configItems = [
    'SHOPIFY_API_KEY' => SHOPIFY_API_KEY,
    'SHOPIFY_API_SECRET' => substr(SHOPIFY_API_SECRET, 0, 8) . '***',
    'APP_URL' => APP_URL,
    'SUITEFLEET_BASE_URL' => SUITEFLEET_BASE_URL,
    'SUITEFLEET_CLIENT_ID' => SUITEFLEET_CLIENT_ID,
    'SUITEFLEET_USERNAME' => SUITEFLEET_USERNAME,
    'SUITEFLEET_PASSWORD' => '***SET***',
    'SUITEFLEET_CUSTOMER_ID' => SUITEFLEET_CUSTOMER_ID,
    'DB_HOST' => DB_HOST,
    'DB_NAME' => DB_NAME,
    'DB_USER' => DB_USER,
];

foreach ($configItems as $key => $val) {
    echo "<p><b>$key</b>: $val</p>";
}

if (APP_URL === 'https://your-domain.com/php') {
    echo '<p class="fail">⚠️ APP_URL is still default - update it in config.php!</p>';
}
if (DB_USER === 'your_db_username') {
    echo '<p class="fail">⚠️ DB credentials are still default - update config.php!</p>';
}
echo '</div>';

// ═══════════════════════════════════════
// STEP 3: MySQL CONNECTION
// ═══════════════════════════════════════
echo '<div class="section"><h2>Step 3: MySQL Connection</h2>';
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo '<p class="pass">✅ MySQL Connected!</p>';

    // Check tables
    $tables = ['sessions', 'order_mappings', 'suitefleet_config', 'sync_logs'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p>Table <b>$table</b>: ✅ exists ({$row['cnt']} rows)</p>";
        } catch (Exception $e) {
            echo "<p>Table <b>$table</b>: <span class='fail'>❌ NOT FOUND</span> - Run schema.sql first!</p>";
            $allPassed = false;
        }
    }
} catch (PDOException $e) {
    echo '<p class="fail">❌ MySQL Connection FAILED: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>Check DB_HOST, DB_NAME, DB_USER, DB_PASS in config.php</p>';
    $allPassed = false;
}
echo '</div>';

// ═══════════════════════════════════════
// STEP 4: SUITEFLEET API AUTH
// ═══════════════════════════════════════
echo '<div class="section"><h2>Step 4: SuiteFleet API Auth</h2>';

$authParams = http_build_query([
    'username' => SUITEFLEET_USERNAME,
    'password' => SUITEFLEET_PASSWORD,
]);
$authUrl = SUITEFLEET_BASE_URL . '/api/auth/authenticate?' . $authParams;
echo "<p>URL: " . SUITEFLEET_BASE_URL . "/api/auth/authenticate</p>";
echo "<p>ClientID: " . SUITEFLEET_CLIENT_ID . "</p>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $authUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'clientid: ' . SUITEFLEET_CLIENT_ID,
]);

$authResponse = curl_exec($ch);
$authHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$authError = curl_error($ch);
curl_close($ch);

if ($authError) {
    echo '<p class="fail">❌ cURL Error: ' . htmlspecialchars($authError) . '</p>';
    $allPassed = false;
} elseif ($authHttpCode === 200) {
    $authData = json_decode($authResponse, true);
    $token = $authData['token'] ?? $authData['accessToken'] ?? $authData['access_token'] ?? null;
    echo '<p class="pass">✅ Auth SUCCESS! Token: ' . ($token ? substr($token, 0, 30) . '...' : 'no token field') . '</p>';
    echo '<p>Response keys: ' . implode(', ', array_keys($authData ?? [])) . '</p>';

    // ═══════════════════════════════════════
    // STEP 5: CREATE TEST TASK
    // ═══════════════════════════════════════
    if ($token) {
        echo '</div><div class="section"><h2>Step 5: Create Test Task in SuiteFleet</h2>';

        $testPayload = json_encode([
            'customerId' => (int)SUITEFLEET_CUSTOMER_ID,
            'orderNumber' => '000001FBC',
            'type' => 'DELIVERY',
            'deliveryAfterTime' => date('Y-m-d\TH:i:s', strtotime('+1 day')),
            'deliveryBeforeTime' => date('Y-m-d\TH:i:s', strtotime('+2 days')),
            'consignee' => [
                'name' => 'Test Customer',
                'email' => 'test@freshbutchers.com',
                'phone' => '+971500000000',
                'address' => [
                    'addressLine1' => 'Test Address Line 1',
                    'city' => 'Dubai',
                    'state' => 'Dubai',
                    'country' => 'AE',
                    'postalCode' => '00000',
                ],
            ],
            'items' => [['name' => 'Test Product', 'quantity' => 1, 'sku' => 'TEST-001', 'price' => 100, 'weight' => 0.5]],
            'serviceType' => 'Standard',
            'notes' => 'TEST ORDER - Please ignore',
            'codAmount' => 0,
            'totalAmount' => 100,
            'currency' => 'AED',
        ]);

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, SUITEFLEET_BASE_URL . '/api/tasks');
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, $testPayload);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'clientid: ' . SUITEFLEET_CLIENT_ID,
        ]);

        $taskResponse = curl_exec($ch2);
        $taskHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $taskError = curl_error($ch2);
        curl_close($ch2);

        if ($taskError) {
            echo '<p class="fail">❌ cURL Error: ' . htmlspecialchars($taskError) . '</p>';
            $allPassed = false;
        } elseif ($taskHttpCode >= 200 && $taskHttpCode < 300) {
            $taskData = json_decode($taskResponse, true);
            echo '<p class="pass">✅ Task Created!</p>';
            echo '<pre>' . htmlspecialchars(json_encode($taskData, JSON_PRETTY_PRINT)) . '</pre>';
            echo '<p><b>Check portal:</b> <a href="' . SUITEFLEET_PORTAL_URL . '/app/home/home" target="_blank">' . SUITEFLEET_PORTAL_URL . '/app/home/home</a></p>';
        } else {
            echo '<p class="fail">❌ Task creation failed: HTTP ' . $taskHttpCode . '</p>';
            echo '<pre>' . htmlspecialchars($taskResponse) . '</pre>';
            $allPassed = false;
        }
    }
} else {
    echo '<p class="fail">❌ Auth FAILED! HTTP ' . $authHttpCode . '</p>';
    echo '<pre>' . htmlspecialchars($authResponse) . '</pre>';
    $allPassed = false;
}
echo '</div>';

// ═══════════════════════════════════════
// RESULT
// ═══════════════════════════════════════
echo '<div class="section" style="background:' . ($allPassed ? '#d4edda' : '#f8d7da') . '">';
echo '<h2>' . ($allPassed ? '✅ ALL TESTS PASSED!' : '❌ SOME TESTS FAILED') . '</h2>';
if ($allPassed) {
    echo '<p>SuiteFleet connection working. Check portal for test task.</p>';
    echo '<p><a href="' . SUITEFLEET_PORTAL_URL . '/app/home/home" target="_blank">Open SuiteFleet Portal →</a></p>';
} else {
    echo '<p>Fix the errors above and run this test again.</p>';
}
echo '</div>';

echo "</body></html>";
