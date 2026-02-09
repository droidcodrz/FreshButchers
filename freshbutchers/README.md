# FreshButchers SuiteFleet Integration (PHP)

Shopify app integrating FreshButchers.com with SuiteFleet (Transcorp Logistics).
Runs on any shared hosting with PHP 7.4+ and MySQL.

## Folder Structure

```
/
├── config.php              # All credentials & settings (DO NOT commit)
├── config.example.php      # Example config (safe to commit)
├── index.php               # Main entry / router
├── install.php             # Shopify OAuth install page
├── webhooks.php            # Webhook handler (auto-assigns orders)
├── test.php                # Diagnostic test page
├── .htaccess               # Security & URL rules
├── auth/
│   └── callback.php        # Shopify OAuth callback
├── api/
│   ├── assign-order.php    # Assign single order to SuiteFleet
│   ├── process-pending.php # Process all pending orders
│   └── sync-status.php     # Sync statuses from SuiteFleet
├── includes/
│   ├── database.php        # MySQL PDO wrapper
│   ├── shopify.php         # Shopify OAuth + GraphQL client
│   ├── suitefleet.php      # SuiteFleet API client
│   └── helpers.php         # Utility functions
├── pages/
│   ├── layout.php          # HTML layout + CSS
│   ├── dashboard.php       # Stats dashboard
│   ├── orders.php          # Orders management
│   ├── shipments.php       # Shipment tracking
│   └── settings.php        # App settings
└── setup/
    └── schema.sql          # MySQL database tables
```

## Requirements

- PHP 7.4+ with extensions: curl, pdo, pdo_mysql, json, mbstring, openssl
- MySQL 5.7+
- HTTPS (required by Shopify)

## Setup

1. Upload all files to your hosting
2. Create MySQL database and run `setup/schema.sql`
3. Copy `config.example.php` to `config.php` and update credentials
4. Open `test.php` in browser to verify everything works
5. Register app URL in Shopify Partner Dashboard

## SuiteFleet API

- Auth: `POST /api/auth/authenticate?username=...&password=...`
- Tasks: `POST /api/tasks` (create), `GET /api/tasks/:id` (status)
- Portal: `https://transcorpsb.suitefleet.com`
