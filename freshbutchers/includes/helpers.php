<?php
/**
 * Helper Functions
 */

/**
 * Format order reference: "1001" → "001001FBC"
 */
function formatOrderReference($orderNumber, $suffix = null) {
    $suffix = $suffix ?: ORDER_SUFFIX;
    $num = preg_replace('/[^0-9]/', '', $orderNumber);
    return str_pad($num, 6, '0', STR_PAD_LEFT) . $suffix;
}

/**
 * Resolve shipping method from Shopify title
 */
function resolveShippingMethod($title) {
    if (!$title) return 'Standard';
    $t = strtolower($title);

    if (strpos($t, 'same day') !== false || strpos($t, 'sameday') !== false) return 'SameDay';
    if (strpos($t, 'express') !== false || strpos($t, 'priority') !== false) return 'Express';
    if (strpos($t, 'on demand') !== false || strpos($t, 'ondemand') !== false) return 'OnDemand';
    if (strpos($t, 'next day') !== false) return 'Express';

    return 'Standard';
}

/**
 * Build SuiteFleet portal URL
 */
function buildPortalUrl($shipmentId = null) {
    $base = SUITEFLEET_PORTAL_URL;
    if ($shipmentId) {
        return $base . '/app/home/tasks/' . $shipmentId;
    }
    return $base . '/app/home/home';
}

/**
 * Get customer name with fallback chain
 */
function getCustomerName($order) {
    $c = $order['customer'] ?? [];
    $s = $order['shippingAddress'] ?? $order['shipping_address'] ?? [];

    // Try customer first+last
    $name = trim(($c['firstName'] ?? $c['first_name'] ?? '') . ' ' . ($c['lastName'] ?? $c['last_name'] ?? ''));
    if ($name) return $name;

    // displayName
    if (!empty($c['displayName'])) return $c['displayName'];

    // Shipping address name
    if (!empty($s['name'])) return $s['name'];

    // Shipping first+last
    $sName = trim(($s['firstName'] ?? $s['first_name'] ?? '') . ' ' . ($s['lastName'] ?? $s['last_name'] ?? ''));
    if ($sName) return $sName;

    // Email
    if (!empty($c['email'])) return $c['email'];

    return 'Customer';
}

/**
 * Prepare order data from Shopify GraphQL order for SuiteFleet
 */
function prepareOrderForSuiteFleet($order) {
    $orderNumber = str_replace('#', '', $order['name'] ?? '');
    $address = $order['shippingAddress'] ?? [];
    $lineItems = [];

    foreach (($order['lineItems']['edges'] ?? []) as $edge) {
        $item = $edge['node'];
        $lineItems[] = [
            'name' => $item['title'] ?? '',
            'quantity' => $item['quantity'] ?? 1,
            'sku' => $item['sku'] ?? '',
            'price' => (float)($item['variant']['price'] ?? 0),
            'weight' => ($item['variant']['weightUnit'] ?? '') === 'KILOGRAMS'
                ? ($item['variant']['weight'] ?? 0)
                : ($item['variant']['weight'] ?? 0) / 1000,
        ];
    }

    return [
        'orderReference' => formatOrderReference($orderNumber),
        'customerName' => getCustomerName($order),
        'customerEmail' => $order['customer']['email'] ?? '',
        'customerPhone' => $order['customer']['phone'] ?? '',
        'address1' => $address['address1'] ?? '',
        'address2' => $address['address2'] ?? '',
        'city' => $address['city'] ?? '',
        'province' => $address['province'] ?? '',
        'country' => $address['country'] ?? 'AE',
        'zip' => $address['zip'] ?? '',
        'items' => $lineItems,
        'shippingMethod' => resolveShippingMethod($order['shippingLine']['title'] ?? ''),
        'notes' => $order['note'] ?? '',
        'totalAmount' => $order['totalPriceSet']['shopMoney']['amount'] ?? '0',
        'currency' => $order['totalPriceSet']['shopMoney']['currencyCode'] ?? 'AED',
    ];
}

/**
 * Prepare order data from REST webhook payload
 */
function prepareOrderFromWebhook($payload) {
    $orderNumber = str_replace('#', '', $payload['name'] ?? '');
    $address = $payload['shipping_address'] ?? [];
    $customer = $payload['customer'] ?? [];
    $lineItems = [];

    foreach (($payload['line_items'] ?? []) as $item) {
        $lineItems[] = [
            'name' => $item['title'] ?? $item['name'] ?? '',
            'quantity' => $item['quantity'] ?? 1,
            'sku' => $item['sku'] ?? '',
            'price' => (float)($item['price'] ?? 0),
            'weight' => ($item['grams'] ?? 0) / 1000,
        ];
    }

    $shippingTitle = ($payload['shipping_lines'][0]['title'] ?? 'Standard');

    return [
        'orderReference' => formatOrderReference($orderNumber),
        'customerName' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))
            ?: ($address['name'] ?? $customer['email'] ?? 'Customer'),
        'customerEmail' => $customer['email'] ?? $payload['email'] ?? '',
        'customerPhone' => $customer['phone'] ?? $payload['phone'] ?? '',
        'address1' => $address['address1'] ?? '',
        'address2' => $address['address2'] ?? '',
        'city' => $address['city'] ?? '',
        'province' => $address['province'] ?? '',
        'country' => $address['country_code'] ?? $address['country'] ?? 'AE',
        'zip' => $address['zip'] ?? '',
        'items' => $lineItems,
        'shippingMethod' => resolveShippingMethod($shippingTitle),
        'notes' => $payload['note'] ?? '',
        'totalAmount' => $payload['total_price'] ?? '0',
        'currency' => $payload['currency'] ?? 'AED',
    ];
}

/**
 * JSON response helper
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get status badge HTML
 */
function statusBadge($status) {
    $badges = [
        'pending' => ['color' => '#f4a261', 'label' => 'Pending'],
        'created' => ['color' => '#457b9d', 'label' => 'Created'],
        'assigned' => ['color' => '#457b9d', 'label' => 'Assigned'],
        'in_transit' => ['color' => '#e9c46a', 'label' => 'In Transit'],
        'out_for_delivery' => ['color' => '#e9c46a', 'label' => 'Out for Delivery'],
        'delivered' => ['color' => '#2a9d8f', 'label' => 'Delivered'],
        'failed' => ['color' => '#e76f51', 'label' => 'Failed'],
        'cancelled' => ['color' => '#e76f51', 'label' => 'Cancelled'],
    ];

    $badge = $badges[$status] ?? ['color' => '#999', 'label' => ucfirst($status ?? 'Unknown')];
    return '<span style="background:' . $badge['color'] . ';color:#fff;padding:2px 8px;border-radius:12px;font-size:12px;">' . $badge['label'] . '</span>';
}
