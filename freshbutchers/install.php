<?php
/**
 * FreshButchers SuiteFleet - Install / OAuth Start
 *
 * Simple form to enter shop domain and initiate Shopify OAuth flow.
 * This file is included by index.php when no shop is in session,
 * or can be accessed directly.
 */

// If accessed directly (not included from index.php), load config
if (!defined('SHOPIFY_API_KEY')) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/database.php';
    require_once __DIR__ . '/includes/shopify.php';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['shop'])) {
    $shop = trim($_POST['shop']);

    // Normalize: strip protocol and trailing slashes
    $shop = preg_replace('#^https?://#', '', $shop);
    $shop = rtrim($shop, '/');

    // Ensure it ends with .myshopify.com
    if (strpos($shop, '.myshopify.com') === false) {
        $shop .= '.myshopify.com';
    }

    // Validate format
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop)) {
        $error = 'Invalid shop domain. Please enter a valid .myshopify.com URL.';
    } else {
        // Redirect to Shopify OAuth authorization
        $installUrl = ShopifyAPI::getInstallUrl($shop);
        header('Location: ' . $installUrl);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install FreshButchers SuiteFleet</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f6f6f7;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .install-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.04);
            padding: 40px;
            max-width: 440px;
            width: 100%;
        }
        .install-card h1 {
            font-size: 22px;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        .install-card p {
            color: #6d7175;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #1a1a1a;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #c9cccf;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.15s;
        }
        .form-group input:focus {
            border-color: #2c6ecb;
            box-shadow: 0 0 0 1px #2c6ecb;
        }
        .form-group .hint {
            font-size: 12px;
            color: #8c9196;
            margin-top: 4px;
        }
        .btn-install {
            display: block;
            width: 100%;
            padding: 12px;
            background: #008060;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-install:hover { background: #006e52; }
        .error {
            background: #fbeae5;
            color: #d72c0d;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="install-card">
        <h1>FreshButchers SuiteFleet</h1>
        <p>Connect your Shopify store to SuiteFleet (Transcorp Logistics) for automated order fulfillment and delivery tracking.</p>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="shop">Shop Domain</label>
                <input
                    type="text"
                    id="shop"
                    name="shop"
                    placeholder="your-shop.myshopify.com"
                    value="<?php echo htmlspecialchars($_POST['shop'] ?? ''); ?>"
                    required
                    autocomplete="off"
                    autofocus
                />
                <div class="hint">Enter your Shopify store domain (e.g., freshbutchers.myshopify.com)</div>
            </div>

            <button type="submit" class="btn-install">Install App</button>
        </form>
    </div>
</body>
</html>
