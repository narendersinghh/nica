<?php

use App\Core\Database;

// Customer accounts: usernames (WordPress logins), saved addresses, 2FA recovery codes, points.

return [
    'up' => function (Database $db): void {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->statement("ALTER TABLE users
            ADD COLUMN username VARCHAR(60) NULL AFTER email,
            ADD COLUMN billing_address JSON NULL AFTER phone,
            ADD COLUMN shipping_address JSON NULL AFTER billing_address,
            ADD COLUMN two_factor_recovery JSON NULL AFTER two_factor_enabled_at,
            ADD COLUMN points_balance INT NOT NULL DEFAULT 0 AFTER two_factor_recovery,
            ADD UNIQUE KEY users_username_unique (username)");

        // Append-only; users.points_balance is the running total kept in the same transaction.
        $db->statement("CREATE TABLE points_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            points INT NOT NULL,
            reason ENUM('signup','order','redeem','restore','admin','import','referral','review') NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY points_ledger_user_index (user_id, created_at),
            KEY points_ledger_order_index (order_id),
            CONSTRAINT points_ledger_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT points_ledger_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
        ) {$o}");

        $db->statement("ALTER TABLE carts ADD COLUMN points_to_redeem INT UNSIGNED NOT NULL DEFAULT 0 AFTER shipping_choices");
        $db->statement("ALTER TABLE orders ADD COLUMN points_redeemed INT UNSIGNED NOT NULL DEFAULT 0 AFTER points_discount_cents");

        // Password-reset rows double as "set your password" links for new accounts.
        $db->statement("ALTER TABLE password_resets ADD COLUMN purpose ENUM('reset','welcome') NOT NULL DEFAULT 'reset' AFTER token_hash");
    },

    'down' => function (Database $db): void {
        $db->statement('ALTER TABLE password_resets DROP COLUMN purpose');
        $db->statement('ALTER TABLE orders DROP COLUMN points_redeemed');
        $db->statement('ALTER TABLE carts DROP COLUMN points_to_redeem');
        $db->statement('DROP TABLE IF EXISTS points_ledger');
        $db->statement('ALTER TABLE users DROP INDEX users_username_unique, DROP COLUMN username, DROP COLUMN billing_address, DROP COLUMN shipping_address, DROP COLUMN two_factor_recovery, DROP COLUMN points_balance');
    },
];
