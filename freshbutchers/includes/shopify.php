<?php
/**
 * Shopify API Client - OAuth + GraphQL
 */

class ShopifyAPI {
    private $shop;
    private $accessToken;

    public function __construct($shop, $accessToken = null) {
        $this->shop = $shop;
        $this->accessToken = $accessToken;

        if (!$accessToken) {
            $db = Database::getInstance();
            $session = $db->getSessionByShop($shop);
            if ($session) {
                $this->accessToken = $session['access_token'];
            }
        }
    }

    // ═══════════════════════════════════════
    // OAuth Flow
    // ═══════════════════════════════════════

    /**
     * Generate OAuth install URL
     */
    public static function getInstallUrl($shop) {
         // Create self-verifying state: base64(JSON) + HMAC signature
        $nonce = bin2hex(random_bytes(16));
        $statePayload = base64_encode(json_encode(['shop' => $shop, 'nonce' => $nonce, 'ts' => time()]));
        $stateHmac = hash_hmac('sha256', $statePayload, SHOPIFY_API_SECRET);
        $state = $statePayload . '.' . $stateHmac;
        $params = http_build_query([
            'client_id' => SHOPIFY_API_KEY,
            'scope' => SHOPIFY_SCOPES,
            'redirect_uri' => APP_URL . '/auth/callback.php',
            'state' => $state,
        ]);

        return "https://{$shop}/admin/oauth/authorize?{$params}";
    }

    /**
     * Exchange code for access token
     */
    public static function exchangeToken($shop, $code) {
        $url = "https://{$shop}/admin/oauth/access_token";
        $payload = [
            'client_id' => SHOPIFY_API_KEY,
            'client_secret' => SHOPIFY_API_SECRET,
            'code' => $code,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Token exchange failed: $httpCode - $response");
        }

        $data = json_decode($response, true);
        return $data['access_token'];
    }

    /**
     * Verify HMAC from Shopify request
     */
    public static function verifyHmac($queryParams) {
        $hmac = $queryParams['hmac'] ?? '';
        unset($queryParams['hmac']);
        ksort($queryParams);

        $message = http_build_query($queryParams);
        $calculatedHmac = hash_hmac('sha256', $message, SHOPIFY_API_SECRET);

        return hash_equals($calculatedHmac, $hmac);
    }

    /**
     * Verify webhook HMAC
     */
    public static function verifyWebhookHmac($body, $hmacHeader) {
        $calculatedHmac = base64_encode(hash_hmac('sha256', $body, SHOPIFY_API_SECRET, true));
        return hash_equals($calculatedHmac, $hmacHeader);
    }

    // ═══════════════════════════════════════
    // GraphQL API
    // ═══════════════════════════════════════

    /**
     * Execute GraphQL query
     */
    public function graphql($query, $variables = []) {
        if (!$this->accessToken) {
            throw new Exception('No access token available for shop: ' . $this->shop);
        }

        $url = "https://{$this->shop}/admin/api/" . SHOPIFY_API_VERSION . "/graphql.json";

        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $this->accessToken,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('Shopify GraphQL cURL error: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception('Shopify GraphQL failed: ' . $httpCode . ' - ' . $response);
        }

        return json_decode($response, true);
    }

    // ═══════════════════════════════════════
    // Order Operations
    // ═══════════════════════════════════════

    /**
     * Fetch orders from Shopify
     */
    public function fetchOrders($status = 'unfulfilled', $first = 50) {
        $fulfillmentQuery = $status === 'all' ? '' : "fulfillment_status:$status";

        $query = '
            query getOrders($first: Int!, $query: String) {
                orders(first: $first, query: $query, sortKey: CREATED_AT, reverse: true) {
                    edges {
                        node {
                            id name displayFulfillmentStatus displayFinancialStatus createdAt
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

        $result = $this->graphql($query, ['first' => $first, 'query' => $fulfillmentQuery]);
        return $result['data']['orders'] ?? ['edges' => [], 'pageInfo' => ['hasNextPage' => false]];
    }

    /**
     * Fetch single order by ID
     */
    public function fetchOrderById($orderId) {
        $query = '
            query getOrder($id: ID!) {
                order(id: $id) {
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
        ';

        $result = $this->graphql($query, ['id' => $orderId]);
        return $result['data']['order'] ?? null;
    }

    /**
     * Add tags to an order
     */
    public function addOrderTags($orderId, $tags) {
        $query = '
            mutation addTags($id: ID!, $tags: [String!]!) {
                tagsAdd(id: $id, tags: $tags) {
                    node { ... on Order { id tags } }
                    userErrors { message }
                }
            }
        ';
        return $this->graphql($query, ['id' => $orderId, 'tags' => $tags]);
    }

    /**
     * Remove tags from an order
     */
    public function removeOrderTags($orderId, $tags) {
        $query = '
            mutation removeTags($id: ID!, $tags: [String!]!) {
                tagsRemove(id: $id, tags: $tags) {
                    node { ... on Order { id tags } }
                    userErrors { message }
                }
            }
        ';
        return $this->graphql($query, ['id' => $orderId, 'tags' => $tags]);
    }

    /**
     * Set metafields on an order
     */
    public function setOrderMetafields($orderId, $metafields) {
        $query = '
            mutation setMetafields($metafields: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $metafields) {
                    metafields { id key value }
                    userErrors { field message }
                }
            }
        ';

        $mfInput = [];
        foreach ($metafields as $key => $value) {
            $mfInput[] = [
                'ownerId' => $orderId,
                'namespace' => 'suitefleet',
                'key' => $key,
                'value' => (string)$value,
                'type' => 'single_line_text_field',
            ];
        }

        return $this->graphql($query, ['metafields' => $mfInput]);
    }

    /**
     * Create fulfillment with tracking
     */
    public function createFulfillment($order, $trackingNumber, $trackingUrl = null) {
        $fulfillmentOrders = $order['fulfillmentOrders']['edges'] ?? [];
        $openFO = null;

        foreach ($fulfillmentOrders as $fo) {
            if (in_array($fo['node']['status'], ['OPEN', 'IN_PROGRESS'])) {
                $openFO = $fo['node'];
                break;
            }
        }

        if (!$openFO) return null;

        $lineItems = [];
        foreach ($openFO['lineItems']['edges'] as $li) {
            $lineItems[] = [
                'id' => $li['node']['id'],
                'quantity' => $li['node']['remainingQuantity'],
            ];
        }

        $query = '
            mutation fulfillmentCreateV2($fulfillment: FulfillmentV2Input!) {
                fulfillmentCreateV2(fulfillment: $fulfillment) {
                    fulfillment { id status trackingInfo { number url } }
                    userErrors { field message }
                }
            }
        ';

        $finalTrackingUrl = $trackingUrl ?: SUITEFLEET_PORTAL_URL . '/tracking/' . $trackingNumber;

        $result = $this->graphql($query, [
            'fulfillment' => [
                'lineItemsByFulfillmentOrder' => [[
                    'fulfillmentOrderId' => $openFO['id'],
                    'fulfillmentOrderLineItems' => $lineItems,
                ]],
                'trackingInfo' => [
                    'company' => 'Transcorp Logistics (SuiteFleet)',
                    'number' => $trackingNumber,
                    'url' => $finalTrackingUrl,
                ],
                'notifyCustomer' => true,
            ],
        ]);

        return $result['data']['fulfillmentCreateV2']['fulfillment'] ?? null;
    }
}
