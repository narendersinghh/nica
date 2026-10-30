<?php

use App\Core\Database;

// Content: media library, pages, blog, FAQs, contact messages.

return [
    'up' => function (Database $db): void {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Files live in public/uploads/<path>. "sizes" maps size name => {path,width,height}.
        $db->statement("CREATE TABLE media (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(255) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            filesize INT UNSIGNED NULL,
            alt VARCHAR(255) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            sizes JSON NULL,
            uploaded_by BIGINT UNSIGNED NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY media_path_unique (path),
            UNIQUE KEY media_legacy_unique (legacy_wp_id),
            KEY media_mime_index (mime)
        ) {$o}");

        // Pages use a template. "content" is sanitized HTML (legal pages etc.),
        // "data" holds the structured sections of designed pages.
        $db->statement("CREATE TABLE pages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(191) NOT NULL,
            title VARCHAR(255) NOT NULL,
            template VARCHAR(50) NOT NULL DEFAULT 'default',
            content MEDIUMTEXT NULL,
            data JSON NULL,
            status ENUM('published','draft') NOT NULL DEFAULT 'published',
            seo_title VARCHAR(255) NULL,
            seo_description VARCHAR(500) NULL,
            seo_robots VARCHAR(100) NULL,
            og_image_id BIGINT UNSIGNED NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY pages_slug_unique (slug),
            UNIQUE KEY pages_legacy_unique (legacy_wp_id),
            CONSTRAINT pages_og_image_fk FOREIGN KEY (og_image_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$o}");

        $db->statement("CREATE TABLE post_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(191) NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            seo_title VARCHAR(255) NULL,
            seo_description VARCHAR(500) NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            UNIQUE KEY post_categories_slug_unique (slug)
        ) {$o}");

        $db->statement("CREATE TABLE posts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(191) NOT NULL,
            title VARCHAR(255) NOT NULL,
            excerpt TEXT NULL,
            content MEDIUMTEXT NOT NULL,
            featured_media_id BIGINT UNSIGNED NULL,
            author_id BIGINT UNSIGNED NULL,
            author_name VARCHAR(191) NOT NULL DEFAULT '',
            status ENUM('published','draft','scheduled') NOT NULL DEFAULT 'published',
            reading_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            seo_title VARCHAR(255) NULL,
            seo_description VARCHAR(500) NULL,
            seo_robots VARCHAR(100) NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY posts_slug_unique (slug),
            UNIQUE KEY posts_legacy_unique (legacy_wp_id),
            KEY posts_listing_index (status, published_at),
            FULLTEXT KEY posts_search (title, excerpt, content),
            CONSTRAINT posts_media_fk FOREIGN KEY (featured_media_id) REFERENCES media(id) ON DELETE SET NULL,
            CONSTRAINT posts_author_fk FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
        ) {$o}");

        $db->statement("CREATE TABLE post_category (
            post_id BIGINT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (post_id, category_id),
            KEY post_category_category_index (category_id),
            CONSTRAINT post_category_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            CONSTRAINT post_category_category_fk FOREIGN KEY (category_id) REFERENCES post_categories(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE faqs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_name VARCHAR(100) NOT NULL DEFAULT 'General',
            question VARCHAR(500) NOT NULL,
            answer TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            KEY faqs_group_index (group_name, sort_order)
        ) {$o}");

        $db->statement("CREATE TABLE contact_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(40) NULL,
            subject VARCHAR(255) NULL,
            message TEXT NOT NULL,
            status ENUM('new','read','replied','spam') NOT NULL DEFAULT 'new',
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY contact_messages_status_index (status, created_at)
        ) {$o}");
    },

    'down' => function (Database $db): void {
        foreach (['contact_messages', 'faqs', 'post_category', 'posts', 'post_categories', 'pages', 'media'] as $table) {
            $db->statement("DROP TABLE IF EXISTS {$table}");
        }
    },
];
