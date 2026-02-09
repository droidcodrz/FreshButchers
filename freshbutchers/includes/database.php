<?php
/**
 * Database Helper - MySQL PDO
 */

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    // ── Sessions ──

    public function getSession($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getSessionByShop($shop) {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE shop = ? AND access_token IS NOT NULL ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$shop]);
        return $stmt->fetch();
    }

    public function saveSession($id, $shop, $accessToken, $scope, $state = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (id, shop, access_token, scope, state, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), scope = VALUES(scope), state = VALUES(state), updated_at = NOW()
        ");
        $stmt->execute([$id, $shop, $accessToken, $scope, $state]);
    }

    public function deleteSessionsByShop($shop) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE shop = ?");
        $stmt->execute([$shop]);
    }

    // ── Order Mappings ──

    public function getOrderMapping($shop, $shopifyOrderId) {
        $stmt = $this->pdo->prepare("SELECT * FROM order_mappings WHERE shop = ? AND shopify_order_id = ?");
        $stmt->execute([$shop, $shopifyOrderId]);
        return $stmt->fetch();
    }

    public function getOrderMappings($shop, $status = null, $limit = 50) {
        $sql = "SELECT * FROM order_mappings WHERE shop = ?";
        $params = [$shop];

        if ($status) {
            $sql .= " AND shipment_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY updated_at DESC LIMIT ?";
        $params[] = (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getPendingMappings($shop, $limit = 100) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM order_mappings
            WHERE shop = ? AND shipment_status NOT IN ('delivered', 'cancelled') AND suitefleet_shipment_id IS NOT NULL
            ORDER BY last_synced_at ASC LIMIT ?
        ");
        $stmt->execute([$shop, (int)$limit]);
        return $stmt->fetchAll();
    }

    public function saveOrderMapping($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO order_mappings (shop, shopify_order_id, shopify_order_number, suitefleet_order_ref,
                suitefleet_shipment_id, suitefleet_task_id, tracking_number, tracking_url,
                shipment_status, shipping_method, customer_name, customer_email, customer_phone,
                delivery_address, order_items, assigned_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                suitefleet_shipment_id = VALUES(suitefleet_shipment_id),
                suitefleet_task_id = VALUES(suitefleet_task_id),
                tracking_number = VALUES(tracking_number),
                tracking_url = VALUES(tracking_url),
                shipment_status = VALUES(shipment_status),
                assigned_at = NOW(), updated_at = NOW()
        ");
        $stmt->execute([
            $data['shop'], $data['shopify_order_id'], $data['shopify_order_number'] ?? null,
            $data['suitefleet_order_ref'] ?? null, $data['suitefleet_shipment_id'] ?? null,
            $data['suitefleet_task_id'] ?? null, $data['tracking_number'] ?? null,
            $data['tracking_url'] ?? null, $data['shipment_status'] ?? 'created',
            $data['shipping_method'] ?? 'Standard', $data['customer_name'] ?? null,
            $data['customer_email'] ?? null, $data['customer_phone'] ?? null,
            $data['delivery_address'] ?? null, $data['order_items'] ?? null,
        ]);
        return $this->pdo->lastInsertId();
    }

    public function updateOrderMapping($id, $data) {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        $sets[] = "updated_at = NOW()";
        $params[] = $id;

        $stmt = $this->pdo->prepare("UPDATE order_mappings SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);
    }

    public function updateOrderMappingByOrder($shop, $shopifyOrderId, $data) {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        $sets[] = "updated_at = NOW()";
        $params[] = $shop;
        $params[] = $shopifyOrderId;

        $stmt = $this->pdo->prepare("UPDATE order_mappings SET " . implode(', ', $sets) . " WHERE shop = ? AND shopify_order_id = ?");
        $stmt->execute($params);
    }

    // ── Stats ──

    public function getStats($shop) {
        $stats = [];
        $stmt = $this->pdo->prepare("SELECT shipment_status, COUNT(*) as cnt FROM order_mappings WHERE shop = ? GROUP BY shipment_status");
        $stmt->execute([$shop]);
        $rows = $stmt->fetchAll();

        $stats = ['total' => 0, 'pending' => 0, 'in_transit' => 0, 'delivered' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $stats['total'] += $row['cnt'];
            $s = $row['shipment_status'];
            if (in_array($s, ['created', 'assigned', 'in_transit', 'out_for_delivery'])) {
                $stats['in_transit'] += $row['cnt'];
            } elseif ($s === 'delivered') {
                $stats['delivered'] += $row['cnt'];
            } elseif ($s === 'failed') {
                $stats['failed'] += $row['cnt'];
            } else {
                $stats['pending'] += $row['cnt'];
            }
        }
        return $stats;
    }

    // ── Sync Logs ──

    public function logSync($shop, $action, $request = null, $response = null, $statusCode = null, $success = false, $error = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sync_logs (shop, action, request_payload, response_payload, status_code, success, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $shop, $action,
            $request ? json_encode($request) : null,
            $response ? (is_string($response) ? $response : json_encode($response)) : null,
            $statusCode, $success ? 1 : 0, $error,
        ]);
    }
}
