<?php

use App\Core\Database;

// Stylist referral program: stylists, link clicks, attributed orders, and their
// own ledger + payout requests (same shape as the vendor ones).

return [
    'up' => function (Database $db): void {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->statement("CREATE TABLE stylists (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL COMMENT 'referral code, upper case',
            status ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
            salon_name VARCHAR(150) NULL,
            instagram VARCHAR(100) NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(50) NULL,
            about VARCHAR(1000) NULL,
            tier_override VARCHAR(30) NULL COMMENT 'NULL = tier from referred sales',
            payout_threshold_cents INT UNSIGNED NULL,
            payout_method ENUM('paypal','bank') NULL,
            payout_details VARBINARY(2048) NULL,
            admin_note VARCHAR(500) NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY stylists_user_unique (user_id),
            UNIQUE KEY stylists_code_unique (code),
            KEY stylists_status_index (status),
            CONSTRAINT stylists_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE referral_clicks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stylist_id BIGINT UNSIGNED NOT NULL,
            visitor_hash CHAR(64) NOT NULL COMMENT 'hash of IP + day: counts unique visits without storing IPs',
            landing_path VARCHAR(191) NOT NULL,
            referrer_host VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY referral_clicks_unique (stylist_id, visitor_hash),
            KEY referral_clicks_stylist_index (stylist_id, created_at),
            CONSTRAINT referral_clicks_stylist_fk FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE referrals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stylist_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            tier VARCHAR(30) NOT NULL,
            rate DECIMAL(5,2) NOT NULL,
            base_cents INT UNSIGNED NOT NULL COMMENT 'items after discounts (no tax/shipping)',
            commission_cents INT UNSIGNED NOT NULL,
            status ENUM('pending','earned','void') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY referrals_order_unique (order_id),
            KEY referrals_stylist_index (stylist_id, created_at),
            CONSTRAINT referrals_stylist_fk FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE RESTRICT,
            CONSTRAINT referrals_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE stylist_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stylist_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            vendor_order_id BIGINT UNSIGNED NULL,
            type ENUM('commission','refund','payout','adjustment') NOT NULL,
            amount_cents INT NOT NULL,
            description VARCHAR(255) NOT NULL,
            available_at DATETIME NULL COMMENT 'NULL = not withdrawable yet (order part not completed)',
            reference VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY stylist_ledger_stylist_index (stylist_id, created_at),
            KEY stylist_ledger_vo_index (vendor_order_id),
            CONSTRAINT stylist_ledger_stylist_fk FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE RESTRICT,
            CONSTRAINT stylist_ledger_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE stylist_payout_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stylist_id BIGINT UNSIGNED NOT NULL,
            amount_cents INT UNSIGNED NOT NULL,
            method ENUM('paypal','bank') NOT NULL,
            details_snapshot VARBINARY(2048) NULL,
            status ENUM('pending','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
            vendor_note VARCHAR(500) NULL COMMENT 'requester note (same name as payout_requests)',
            admin_note VARCHAR(500) NULL,
            reference VARCHAR(100) NULL,
            processed_by BIGINT UNSIGNED NULL,
            processed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY stylist_payouts_status_index (status, created_at),
            CONSTRAINT stylist_payouts_stylist_fk FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE RESTRICT
        ) {$o}");
    },

    'down' => function (Database $db): void {
        foreach (['stylist_payout_requests', 'stylist_ledger', 'referrals', 'referral_clicks', 'stylists'] as $table) {
            $db->statement("DROP TABLE IF EXISTS {$table}");
        }
    },
];
