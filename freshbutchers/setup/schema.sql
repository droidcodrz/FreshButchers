-- FreshButchers SuiteFleet - MySQL Schema
-- Run this in your hosting's phpMyAdmin or MySQL console

CREATE DATABASE IF NOT EXISTS freshbutchers_suitefleet
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE freshbutchers_suitefleet;

-- Shopify sessions (for OAuth)
CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(255) PRIMARY KEY,
  shop VARCHAR(255) NOT NULL,
  state VARCHAR(255),
  is_online TINYINT(1) DEFAULT 0,
  scope VARCHAR(1000),
  access_token VARCHAR(255),
  expires_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_shop (shop)
) ENGINE=InnoDB;

-- Order mappings (Shopify <-> SuiteFleet)
CREATE TABLE IF NOT EXISTS order_mappings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop VARCHAR(255) NOT NULL,
  shopify_order_id VARCHAR(255) NOT NULL,
  shopify_order_number VARCHAR(100),
  suitefleet_order_ref VARCHAR(100),
  suitefleet_shipment_id VARCHAR(255),
  suitefleet_task_id VARCHAR(255),
  tracking_number VARCHAR(255),
  tracking_url VARCHAR(500),
  shipment_status VARCHAR(50) DEFAULT 'pending',
  shipping_method VARCHAR(50) DEFAULT 'Standard',
  customer_name VARCHAR(255),
  customer_email VARCHAR(255),
  customer_phone VARCHAR(100),
  delivery_address TEXT,
  order_items TEXT,
  shopify_fulfillment_id VARCHAR(255),
  notes TEXT,
  error_message TEXT,
  assigned_at DATETIME,
  last_synced_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_shop_order (shop, shopify_order_id),
  INDEX idx_status (shipment_status),
  INDEX idx_shop (shop)
) ENGINE=InnoDB;

-- SuiteFleet config per shop
CREATE TABLE IF NOT EXISTS suitefleet_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop VARCHAR(255) NOT NULL UNIQUE,
  base_url VARCHAR(500) DEFAULT 'https://api.suitefleet.com',
  client_id VARCHAR(100) DEFAULT 'transcorpsb',
  username VARCHAR(255),
  password VARCHAR(255),
  customer_id VARCHAR(50) DEFAULT '578',
  order_suffix VARCHAR(20) DEFAULT 'FBC',
  timezone VARCHAR(50) DEFAULT 'Asia/Dubai',
  access_token TEXT,
  token_expiry DATETIME,
  last_token_refresh DATETIME,
  sync_enabled TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Sync logs
CREATE TABLE IF NOT EXISTS sync_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop VARCHAR(255),
  action VARCHAR(100),
  order_mapping_id INT,
  request_payload TEXT,
  response_payload TEXT,
  status_code INT,
  success TINYINT(1) DEFAULT 0,
  error_message TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_shop_action (shop, action),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;
