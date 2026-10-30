<?php

use App\Core\Database;

// Core platform tables: accounts, roles, security and site-wide settings.
// Catalog, orders, vendors etc. arrive with their own phases.

return [
    'up' => function (Database $db): void {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->statement("CREATE TABLE users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(191) NOT NULL,
            password_hash VARCHAR(255) NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            display_name VARCHAR(191) NOT NULL DEFAULT '',
            phone VARCHAR(40) NULL,
            status ENUM('active','pending','suspended') NOT NULL DEFAULT 'active',
            email_verified_at DATETIME NULL,
            two_factor_secret VARBINARY(255) NULL,
            two_factor_enabled_at DATETIME NULL,
            last_login_at DATETIME NULL,
            last_login_ip VARCHAR(45) NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY users_email_unique (email),
            UNIQUE KEY users_legacy_wp_id_unique (legacy_wp_id),
            KEY users_status_index (status)
        ) {$o}");

        // One account can hold several roles (e.g. customer + stylist, vendor + customer).
        $db->statement("CREATE TABLE user_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('admin','staff','vendor','vendor_staff','stylist','customer') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role),
            KEY user_roles_role_index (role),
            CONSTRAINT user_roles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY password_resets_token_unique (token_hash),
            KEY password_resets_user_index (user_id),
            CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE remember_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            selector CHAR(24) NOT NULL,
            validator_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY remember_tokens_selector_unique (selector),
            KEY remember_tokens_user_index (user_id),
            CONSTRAINT remember_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE rate_limits (
            k VARCHAR(191) NOT NULL PRIMARY KEY,
            hits INT UNSIGNED NOT NULL,
            reset_at INT UNSIGNED NOT NULL,
            KEY rate_limits_reset_index (reset_at)
        ) {$o}");

        $db->statement("CREATE TABLE settings (
            name VARCHAR(191) NOT NULL PRIMARY KEY,
            value JSON NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) {$o}");

        $db->statement("CREATE TABLE audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            subject_type VARCHAR(50) NULL,
            subject_id BIGINT UNSIGNED NULL,
            meta JSON NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY audit_log_user_index (user_id),
            KEY audit_log_subject_index (subject_type, subject_id),
            KEY audit_log_created_index (created_at)
        ) {$o}");

        $db->statement("CREATE TABLE redirects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            from_path VARCHAR(191) NOT NULL,
            to_url VARCHAR(500) NOT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            last_hit_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY redirects_from_unique (from_path)
        ) {$o}");

        $db->statement("CREATE TABLE not_found_log (
            path VARCHAR(191) NOT NULL PRIMARY KEY,
            hits INT UNSIGNED NOT NULL DEFAULT 1,
            referrer VARCHAR(500) NULL,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) {$o}");

        $db->statement("CREATE TABLE email_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            to_email VARCHAR(191) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            html MEDIUMTEXT NOT NULL,
            text MEDIUMTEXT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            last_error VARCHAR(500) NULL,
            send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY email_queue_status_index (status, send_after)
        ) {$o}");
    },

    'down' => function (Database $db): void {
        foreach (['email_queue', 'not_found_log', 'redirects', 'audit_log', 'settings', 'rate_limits',
                  'remember_tokens', 'password_resets', 'user_roles', 'users'] as $table) {
            $db->statement("DROP TABLE IF EXISTS {$table}");
        }
    },
];
