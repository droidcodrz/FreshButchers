<?php
/**
 * SuiteFleet API Client
 *
 * Auth: POST /api/auth/authenticate?username=...&password=... (query params!)
 * Tasks: /api/tasks (CRUD)
 */

class SuiteFleetAPI {
    private $baseUrl;
    private $clientId;
    private $username;
    private $password;
    private $customerId;
    private $token = null;

    public function __construct($baseUrl = null, $clientId = null, $username = null, $password = null, $customerId = null) {
        $this->baseUrl = $baseUrl ?: SUITEFLEET_BASE_URL;
        $this->clientId = $clientId ?: SUITEFLEET_CLIENT_ID;
        $this->username = $username ?: SUITEFLEET_USERNAME;
        $this->password = $password ?: SUITEFLEET_PASSWORD;
        $this->customerId = $customerId ?: SUITEFLEET_CUSTOMER_ID;
    }

    /**
     * Authenticate and get token
     */
    public function authenticate() {
        if ($this->token) return $this->token;

        $params = http_build_query([
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $url = $this->baseUrl . '/api/auth/authenticate?' . $params;

        $response = $this->request('POST', $url, null, [
            'Content-Type: application/json',
            'clientid: ' . $this->clientId,
        ]);

        if ($response['http_code'] !== 200) {
            throw new Exception('SuiteFleet auth failed: ' . $response['http_code'] . ' - ' . $response['body']);
        }

        $data = json_decode($response['body'], true);
        $this->token = $data['token'] ?? $data['accessToken'] ?? $data['access_token'] ?? null;

        if (!$this->token) {
            throw new Exception('SuiteFleet auth: no token in response');
        }

        return $this->token;
    }

    /**
     * Create a task/shipment in SuiteFleet
     */
    public function createTask($orderData) {
        $token = $this->authenticate();

        $payload = [
            'customerId' => (int)$this->customerId,
            'orderNumber' => $orderData['orderReference'],
            'type' => 'DELIVERY',
            'deliveryAfterTime' => date('Y-m-d\TH:i:s'),
            'deliveryBeforeTime' => date('Y-m-d\TH:i:s', strtotime('+1 day')),
            'consignee' => [
                'name' => $orderData['customerName'] ?? 'Customer',
                'email' => $orderData['customerEmail'] ?? '',
                'phone' => $orderData['customerPhone'] ?? '',
                'address' => [
                    'addressLine1' => $orderData['address1'] ?? '',
                    'addressLine2' => $orderData['address2'] ?? '',
                    'city' => $orderData['city'] ?? '',
                    'state' => $orderData['province'] ?? '',
                    'country' => $orderData['country'] ?? 'AE',
                    'postalCode' => $orderData['zip'] ?? '',
                ],
            ],
            'items' => $orderData['items'] ?? [],
            'serviceType' => $orderData['shippingMethod'] ?? 'Standard',
            'notes' => $orderData['notes'] ?? '',
            'codAmount' => 0,
            'totalAmount' => (float)($orderData['totalAmount'] ?? 0),
            'currency' => $orderData['currency'] ?? 'AED',
            'requestedDate' => date('Y-m-d H:i:s'),
        ];

        $url = $this->baseUrl . '/api/tasks';
        $response = $this->request('POST', $url, $payload, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'clientid: ' . $this->clientId,
        ]);

        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            throw new Exception('SuiteFleet task creation failed: ' . $response['http_code'] . ' - ' . $response['body']);
        }

        $data = json_decode($response['body'], true);

        return [
            'shipmentId' => $data['shipmentId'] ?? $data['id'] ?? $data['primaryId'] ?? null,
            'taskId' => $data['taskId'] ?? ($data['task']['id'] ?? null),
            'trackingNumber' => $data['trackingNumber'] ?? $data['tracking_number'] ?? null,
            'trackingUrl' => $data['trackingUrl'] ?? $data['tracking_url'] ?? null,
            'status' => $data['status'] ?? 'created',
            'raw' => $data,
        ];
    }

    /**
     * Get shipment status
     */
    public function getShipmentStatus($shipmentId) {
        $token = $this->authenticate();

        $url = $this->baseUrl . '/api/tasks/' . $shipmentId;
        $response = $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'clientid: ' . $this->clientId,
        ]);

        if ($response['http_code'] !== 200) {
            throw new Exception('SuiteFleet status fetch failed: ' . $response['http_code']);
        }

        $data = json_decode($response['body'], true);

        return [
            'shipmentId' => $data['shipmentId'] ?? $data['id'] ?? null,
            'status' => $this->normalizeStatus($data['status'] ?? $data['taskStatus'] ?? ''),
            'trackingNumber' => $data['trackingNumber'] ?? $data['tracking_number'] ?? null,
            'trackingUrl' => $data['trackingUrl'] ?? $data['tracking_url'] ?? null,
            'driverName' => $data['driver']['name'] ?? $data['driverName'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * List all tasks
     */
    public function listTasks($params = []) {
        $token = $this->authenticate();

        $queryParams = array_merge(['customerId' => $this->customerId], $params);
        $url = $this->baseUrl . '/api/tasks?' . http_build_query($queryParams);

        $response = $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'clientid: ' . $this->clientId,
        ]);

        if ($response['http_code'] !== 200) {
            throw new Exception('SuiteFleet list tasks failed: ' . $response['http_code']);
        }

        return json_decode($response['body'], true);
    }

    /**
     * Get tracking info
     */
    public function getTracking($shipmentId) {
        $token = $this->authenticate();

        $url = $this->baseUrl . '/api/tasks/' . $shipmentId . '/tracking';
        $response = $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'clientid: ' . $this->clientId,
        ]);

        if ($response['http_code'] !== 200) {
            throw new Exception('SuiteFleet tracking fetch failed: ' . $response['http_code']);
        }

        return json_decode($response['body'], true);
    }

    /**
     * Normalize SuiteFleet status to internal format
     */
    private function normalizeStatus($status) {
        if (!$status) return 'pending';

        $s = strtolower(preg_replace('/[_\s-]/', '', $status));
        $map = [
            'created' => 'created', 'new' => 'created',
            'pending' => 'pending',
            'assigned' => 'assigned', 'accepted' => 'assigned',
            'pickedup' => 'in_transit', 'intransit' => 'in_transit', 'ontheway' => 'in_transit',
            'outfordelivery' => 'out_for_delivery', 'neardelivery' => 'out_for_delivery',
            'delivered' => 'delivered', 'completed' => 'delivered', 'success' => 'delivered',
            'failed' => 'failed', 'returned' => 'failed', 'undelivered' => 'failed',
            'cancelled' => 'cancelled', 'canceled' => 'cancelled',
        ];

        return $map[$s] ?? 'pending';
    }

    /**
     * cURL request helper
     */
    private function request($method, $url, $body = null, $headers = []) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }

        return ['http_code' => $httpCode, 'body' => $responseBody];
    }
}
