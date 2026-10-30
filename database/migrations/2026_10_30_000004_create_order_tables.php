<?php

use App\Core\Database;

// Carts, coupons, orders split per vendor, payments, refunds and the vendor ledger.
// All money in cents. Orders snapshot names/prices so later catalog edits never change history.

return [
    'up' => function (Database $db): void {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Guest carts are found by a random cookie token (only its hash is stored).
        $db->statement("CREATE TABLE carts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            coupon_codes JSON NULL,
            shipping_choices JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY carts_token_unique (token_hash),
            UNIQUE KEY carts_user_unique (user_id),
            KEY carts_updated_index (updated_at),
            CONSTRAINT carts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE cart_items (
            cart_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            quantity SMALLINT UNSIGNED NOT NULL,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (cart_id, variant_id),
            CONSTRAINT cart_items_cart_fk FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
            CONSTRAINT cart_items_variant_fk FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE coupons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            description VARCHAR(255) NULL,
            type ENUM('percent','fixed_cart','fixed_product') NOT NULL DEFAULT 'percent',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'percent, or dollars for fixed types',
            vendor_id BIGINT UNSIGNED NULL COMMENT 'NULL = platform coupon',
            cost_bearer ENUM('shared','platform','vendor') NOT NULL DEFAULT 'shared',
            free_shipping TINYINT(1) NOT NULL DEFAULT 0,
            individual_use TINYINT(1) NOT NULL DEFAULT 0,
            exclude_sale_items TINYINT(1) NOT NULL DEFAULT 0,
            minimum_cents INT UNSIGNED NULL,
            maximum_cents INT UNSIGNED NULL,
            usage_limit INT UNSIGNED NULL,
            usage_limit_per_user INT UNSIGNED NULL,
            usage_count INT UNSIGNED NOT NULL DEFAULT 0,
            product_ids JSON NULL,
            excluded_product_ids JSON NULL,
            category_ids JSON NULL,
            excluded_category_ids JSON NULL,
            vendor_ids JSON NULL COMMENT 'platform coupon limited to these vendors',
            allowed_emails JSON NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY coupons_code_unique (code),
            UNIQUE KEY coupons_legacy_unique (legacy_wp_id),
            KEY coupons_vendor_index (vendor_id),
            CONSTRAINT coupons_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            number VARCHAR(32) NOT NULL,
            access_key CHAR(40) NOT NULL COMMENT 'lets a guest view their order confirmation',
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(191) NOT NULL,
            status ENUM('pending','processing','on-hold','completed','cancelled','refunded','failed') NOT NULL DEFAULT 'pending',
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
            discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
            shipping_cents INT UNSIGNED NOT NULL DEFAULT 0,
            tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
            points_discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
            total_cents INT UNSIGNED NOT NULL DEFAULT 0,
            refunded_cents INT UNSIGNED NOT NULL DEFAULT 0,
            billing_address JSON NOT NULL,
            shipping_address JSON NOT NULL,
            customer_note TEXT NULL,
            coupon_codes JSON NULL,
            payment_method VARCHAR(50) NULL,
            payment_method_title VARCHAR(100) NULL,
            stripe_payment_intent_id VARCHAR(100) NULL,
            stripe_charge_id VARCHAR(100) NULL,
            paid_at DATETIME NULL,
            completed_at DATETIME NULL,
            stock_reduced TINYINT(1) NOT NULL DEFAULT 0,
            referral_code VARCHAR(64) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY orders_number_unique (number),
            UNIQUE KEY orders_intent_unique (stripe_payment_intent_id),
            UNIQUE KEY orders_legacy_unique (legacy_wp_id),
            KEY orders_user_index (user_id, created_at),
            KEY orders_email_index (email),
            KEY orders_status_index (status, created_at),
            CONSTRAINT orders_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) {$o}");

        // One per vendor in the order: what the vendor ships and gets paid for.
        $db->statement("CREATE TABLE vendor_orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            vendor_id BIGINT UNSIGNED NOT NULL,
            status ENUM('pending','processing','on-hold','shipped','completed','cancelled','refunded','failed') NOT NULL DEFAULT 'pending',
            subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
            discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
            vendor_discount_cents INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'part of the discount the vendor bears',
            shipping_cents INT UNSIGNED NOT NULL DEFAULT 0,
            shipping_method VARCHAR(100) NULL,
            tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
            total_cents INT UNSIGNED NOT NULL DEFAULT 0,
            commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            commission_cents INT NOT NULL DEFAULT 0,
            vendor_earning_cents INT NOT NULL DEFAULT 0,
            refunded_cents INT UNSIGNED NOT NULL DEFAULT 0,
            payout_mode ENUM('manual','stripe_connect') NOT NULL DEFAULT 'manual',
            stripe_transfer_id VARCHAR(100) NULL,
            tracking_carrier VARCHAR(100) NULL,
            tracking_number VARCHAR(100) NULL,
            tracking_url VARCHAR(500) NULL,
            shipped_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY vendor_orders_unique (order_id, vendor_id),
            KEY vendor_orders_vendor_index (vendor_id, status, created_at),
            CONSTRAINT vendor_orders_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT vendor_orders_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT
        ) {$o}");

        $db->statement("CREATE TABLE order_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            vendor_order_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            variant_id BIGINT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            variant_label VARCHAR(255) NULL,
            sku VARCHAR(100) NULL,
            image_media_id BIGINT UNSIGNED NULL,
            quantity SMALLINT UNSIGNED NOT NULL,
            unit_price_cents INT UNSIGNED NOT NULL,
            subtotal_cents INT UNSIGNED NOT NULL,
            discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
            tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
            total_cents INT UNSIGNED NOT NULL,
            refunded_qty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            KEY order_items_order_index (order_id),
            KEY order_items_vendor_order_index (vendor_order_id),
            KEY order_items_product_index (product_id),
            CONSTRAINT order_items_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT order_items_vendor_order_fk FOREIGN KEY (vendor_order_id) REFERENCES vendor_orders(id) ON DELETE CASCADE,
            CONSTRAINT order_items_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
            CONSTRAINT order_items_variant_fk FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
        ) {$o}");

        $db->statement("CREATE TABLE order_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            vendor_order_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            note TEXT NOT NULL,
            is_customer_visible TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY order_notes_order_index (order_id),
            CONSTRAINT order_notes_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE coupon_usages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            coupon_id INT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(191) NOT NULL,
            discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY coupon_usages_unique (coupon_id, order_id),
            KEY coupon_usages_email_index (coupon_id, email),
            CONSTRAINT coupon_usages_coupon_fk FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
            CONSTRAINT coupon_usages_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE refunds (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            vendor_order_id BIGINT UNSIGNED NULL,
            amount_cents INT UNSIGNED NOT NULL,
            reason VARCHAR(500) NULL,
            stripe_refund_id VARCHAR(100) NULL,
            status ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
            created_by BIGINT UNSIGNED NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY refunds_stripe_unique (stripe_refund_id),
            UNIQUE KEY refunds_legacy_unique (legacy_wp_id),
            KEY refunds_order_index (order_id),
            CONSTRAINT refunds_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) {$o}");

        // Append-only money trail per vendor. Balance = SUM(amount_cents) where available.
        $db->statement("CREATE TABLE vendor_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_id BIGINT UNSIGNED NOT NULL,
            vendor_order_id BIGINT UNSIGNED NULL,
            type ENUM('earning','refund','payout','transfer','adjustment') NOT NULL,
            amount_cents INT NOT NULL,
            description VARCHAR(255) NOT NULL,
            available_at DATETIME NULL COMMENT 'NULL = not withdrawable yet (order not completed)',
            reference VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY vendor_ledger_vendor_index (vendor_id, created_at),
            KEY vendor_ledger_order_index (vendor_order_id),
            CONSTRAINT vendor_ledger_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
            CONSTRAINT vendor_ledger_vendor_order_fk FOREIGN KEY (vendor_order_id) REFERENCES vendor_orders(id) ON DELETE SET NULL
        ) {$o}");

        // Shipping class per product (flat-rate cost by class).
        $db->statement("ALTER TABLE products ADD COLUMN shipping_class VARCHAR(50) NULL AFTER primary_category_id");

        // Processed Stripe webhook events (idempotency).
        $db->statement("CREATE TABLE webhook_events (
            id VARCHAR(100) NOT NULL PRIMARY KEY,
            type VARCHAR(100) NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) {$o}");
    },

    'down' => function (Database $db): void {
        $db->statement('ALTER TABLE products DROP COLUMN shipping_class');
        foreach (['webhook_events', 'vendor_ledger', 'refunds', 'coupon_usages', 'order_notes', 'order_items', 'vendor_orders', 'orders', 'coupons', 'cart_items', 'carts'] as $t) {
            $db->statement("DROP TABLE IF EXISTS {$t}");
        }
    },
];
