<?php

use App\Core\Database;

// Vendor payouts (manual withdraw requests) and payout details.

return [
    'up' => function (Database $db): void {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Bank/PayPal details are encrypted at rest (libsodium, key from APP_KEY).
        $db->statement("ALTER TABLE vendors
            ADD COLUMN payout_method ENUM('paypal','bank') NULL AFTER payout_threshold_cents,
            ADD COLUMN payout_details VARBINARY(2048) NULL AFTER payout_method,
            ADD COLUMN stripe_onboarded_at DATETIME NULL AFTER stripe_payouts_enabled");

        $db->statement("CREATE TABLE payout_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_id BIGINT UNSIGNED NOT NULL,
            amount_cents INT UNSIGNED NOT NULL,
            method ENUM('paypal','bank') NOT NULL,
            details_snapshot VARBINARY(2048) NULL COMMENT 'encrypted copy of the payout details at request time',
            status ENUM('pending','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
            vendor_note VARCHAR(500) NULL,
            admin_note VARCHAR(500) NULL,
            reference VARCHAR(100) NULL COMMENT 'bank/PayPal transaction id',
            processed_by BIGINT UNSIGNED NULL,
            processed_at DATETIME NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY payout_requests_vendor_index (vendor_id, status),
            KEY payout_requests_status_index (status, created_at),
            UNIQUE KEY payout_requests_legacy_unique (legacy_wp_id),
            CONSTRAINT payout_requests_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT
        ) {$o}");
    },

    'down' => function (Database $db): void {
        $db->statement('DROP TABLE IF EXISTS payout_requests');
        $db->statement('ALTER TABLE vendors DROP COLUMN payout_method, DROP COLUMN payout_details, DROP COLUMN stripe_onboarded_at');
    },
];
