<?php

use App\Core\Database;

// Catalog: vendors, categories, tags, attributes, products + variants, images, reviews.
// Money is stored in cents. Every product has at least one variant; a simple
// product has exactly one variant with no attribute values.

return [
    'up' => function (Database $db): void {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->statement("CREATE TABLE vendors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            slug VARCHAR(191) NOT NULL,
            store_name VARCHAR(191) NOT NULL,
            status ENUM('pending','approved','suspended','disabled') NOT NULL DEFAULT 'pending',
            tagline VARCHAR(255) NULL,
            about TEXT NULL,
            phone VARCHAR(40) NULL,
            public_email VARCHAR(191) NULL,
            address JSON NULL,
            social JSON NULL,
            logo_media_id BIGINT UNSIGNED NULL,
            banner_media_id BIGINT UNSIGNED NULL,
            shipping_policy TEXT NULL,
            refund_policy TEXT NULL,
            is_premier_partner TINYINT(1) NOT NULL DEFAULT 0,
            premier_number SMALLINT UNSIGNED NULL,
            nica_score DECIMAL(4,1) NULL,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            vacation_mode TINYINT(1) NOT NULL DEFAULT 0,
            commission_rate DECIMAL(5,2) NULL COMMENT 'NULL = platform default',
            payout_threshold_cents INT UNSIGNED NULL COMMENT 'NULL = platform default',
            stripe_account_id VARCHAR(100) NULL,
            stripe_charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
            stripe_payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
            rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,
            rating_count INT UNSIGNED NOT NULL DEFAULT 0,
            legacy_wp_id BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY vendors_slug_unique (slug),
            UNIQUE KEY vendors_user_unique (user_id),
            UNIQUE KEY vendors_legacy_unique (legacy_wp_id),
            KEY vendors_status_index (status),
            CONSTRAINT vendors_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT vendors_logo_fk FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL,
            CONSTRAINT vendors_banner_fk FOREIGN KEY (banner_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$o}");

        // "path" is the full slug path used in /collection/<path>/ URLs.
        $db->statement("CREATE TABLE product_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED NULL,
            slug VARCHAR(191) NOT NULL,
            path VARCHAR(500) NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            image_media_id BIGINT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            show_on_home TINYINT(1) NOT NULL DEFAULT 0,
            seo_title VARCHAR(255) NULL,
            seo_description VARCHAR(500) NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            UNIQUE KEY product_categories_path_unique (path(191)),
            UNIQUE KEY product_categories_legacy_unique (legacy_wp_id),
            KEY product_categories_parent_index (parent_id),
            CONSTRAINT product_categories_parent_fk FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL,
            CONSTRAINT product_categories_image_fk FOREIGN KEY (image_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$o}");

        // Tags double as hair textures ("Shop by hair type", quiz step 2).
        $db->statement("CREATE TABLE product_tags (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(191) NOT NULL,
            name VARCHAR(191) NOT NULL,
            subtitle VARCHAR(191) NULL,
            description TEXT NULL,
            image_media_id BIGINT UNSIGNED NULL,
            is_hair_type TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            legacy_wp_id BIGINT UNSIGNED NULL,
            UNIQUE KEY product_tags_slug_unique (slug),
            UNIQUE KEY product_tags_legacy_unique (legacy_wp_id),
            CONSTRAINT product_tags_image_fk FOREIGN KEY (image_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$o}");

        $db->statement("CREATE TABLE attributes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) NOT NULL,
            name VARCHAR(100) NOT NULL,
            display ENUM('buttons','swatches','select') NOT NULL DEFAULT 'buttons',
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY attributes_slug_unique (slug)
        ) {$o}");

        $db->statement("CREATE TABLE attribute_values (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attribute_id INT UNSIGNED NOT NULL,
            slug VARCHAR(100) NOT NULL,
            name VARCHAR(100) NOT NULL,
            swatch VARCHAR(20) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            legacy_wp_id BIGINT UNSIGNED NULL,
            UNIQUE KEY attribute_values_slug_unique (attribute_id, slug),
            UNIQUE KEY attribute_values_legacy_unique (legacy_wp_id),
            CONSTRAINT attribute_values_attribute_fk FOREIGN KEY (attribute_id) REFERENCES attributes(id) ON DELETE CASCADE
        ) {$o}");

        // Listing fields (price_min/max, in_stock, on_sale) are denormalized from
        // variants on every save, so shop pages sort and filter on one table.
        $db->statement("CREATE TABLE products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(191) NOT NULL,
            name VARCHAR(255) NOT NULL,
            type ENUM('simple','variable') NOT NULL DEFAULT 'simple',
            status ENUM('draft','pending','published','rejected','archived') NOT NULL DEFAULT 'draft',
            short_description TEXT NULL,
            description MEDIUMTEXT NULL,
            featured_media_id BIGINT UNSIGNED NULL,
            primary_category_id INT UNSIGNED NULL,
            price_min_cents INT UNSIGNED NOT NULL DEFAULT 0,
            price_max_cents INT UNSIGNED NOT NULL DEFAULT 0,
            regular_min_cents INT UNSIGNED NOT NULL DEFAULT 0,
            regular_max_cents INT UNSIGNED NOT NULL DEFAULT 0,
            on_sale TINYINT(1) NOT NULL DEFAULT 0,
            in_stock TINYINT(1) NOT NULL DEFAULT 1,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            nica_score_verified TINYINT(1) NOT NULL DEFAULT 0,
            rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,
            rating_count INT UNSIGNED NOT NULL DEFAULT 0,
            sales_count INT UNSIGNED NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            review_note VARCHAR(500) NULL COMMENT 'admin feedback when rejected',
            seo_title VARCHAR(255) NULL,
            seo_description VARCHAR(500) NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY products_slug_unique (slug),
            UNIQUE KEY products_legacy_unique (legacy_wp_id),
            KEY products_listing_index (status, published_at),
            KEY products_vendor_index (vendor_id, status),
            KEY products_price_index (status, price_min_cents),
            FULLTEXT KEY products_search (name, short_description, description),
            CONSTRAINT products_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
            CONSTRAINT products_media_fk FOREIGN KEY (featured_media_id) REFERENCES media(id) ON DELETE SET NULL,
            CONSTRAINT products_category_fk FOREIGN KEY (primary_category_id) REFERENCES product_categories(id) ON DELETE SET NULL
        ) {$o}");

        $db->statement("CREATE TABLE product_variants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(100) NULL,
            regular_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
            sale_price_cents INT UNSIGNED NULL,
            sale_starts_at DATETIME NULL,
            sale_ends_at DATETIME NULL,
            manage_stock TINYINT(1) NOT NULL DEFAULT 0,
            stock_qty INT NULL,
            stock_status ENUM('instock','outofstock','onbackorder') NOT NULL DEFAULT 'instock',
            weight_grams INT UNSIGNED NULL,
            image_media_id BIGINT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            legacy_wp_id BIGINT UNSIGNED NULL,
            UNIQUE KEY product_variants_legacy_unique (legacy_wp_id),
            KEY product_variants_product_index (product_id, is_active),
            KEY product_variants_sku_index (sku),
            CONSTRAINT product_variants_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT product_variants_image_fk FOREIGN KEY (image_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$o}");

        // A variant without a value for an attribute matches any value of it.
        $db->statement("CREATE TABLE variant_values (
            variant_id BIGINT UNSIGNED NOT NULL,
            attribute_id INT UNSIGNED NOT NULL,
            attribute_value_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (variant_id, attribute_id),
            KEY variant_values_value_index (attribute_value_id),
            CONSTRAINT variant_values_variant_fk FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
            CONSTRAINT variant_values_attribute_fk FOREIGN KEY (attribute_id) REFERENCES attributes(id) ON DELETE CASCADE,
            CONSTRAINT variant_values_value_fk FOREIGN KEY (attribute_value_id) REFERENCES attribute_values(id) ON DELETE CASCADE
        ) {$o}");

        // Values a product offers (shown on the page, used by filters and the quiz).
        $db->statement("CREATE TABLE product_attribute_values (
            product_id BIGINT UNSIGNED NOT NULL,
            attribute_value_id INT UNSIGNED NOT NULL,
            used_for_variants TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (product_id, attribute_value_id),
            KEY product_attribute_values_value_index (attribute_value_id),
            CONSTRAINT pav_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT pav_value_fk FOREIGN KEY (attribute_value_id) REFERENCES attribute_values(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE product_images (
            product_id BIGINT UNSIGNED NOT NULL,
            media_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (product_id, media_id),
            CONSTRAINT product_images_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT product_images_media_fk FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE product_category (
            product_id BIGINT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (product_id, category_id),
            KEY product_category_category_index (category_id),
            CONSTRAINT product_category_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT product_category_category_fk FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE product_tag (
            product_id BIGINT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (product_id, tag_id),
            KEY product_tag_tag_index (tag_id),
            CONSTRAINT product_tag_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT product_tag_tag_fk FOREIGN KEY (tag_id) REFERENCES product_tags(id) ON DELETE CASCADE
        ) {$o}");

        $db->statement("CREATE TABLE reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            vendor_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            author_name VARCHAR(191) NOT NULL,
            author_email VARCHAR(191) NULL,
            rating TINYINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            status ENUM('pending','approved','spam','rejected') NOT NULL DEFAULT 'pending',
            is_verified_buyer TINYINT(1) NOT NULL DEFAULT 0,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            vendor_reply TEXT NULL,
            ip VARCHAR(45) NULL,
            legacy_wp_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY reviews_legacy_unique (legacy_wp_id),
            KEY reviews_product_index (product_id, status),
            KEY reviews_vendor_index (vendor_id, status),
            KEY reviews_status_index (status, rating, created_at),
            CONSTRAINT reviews_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT reviews_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            CONSTRAINT reviews_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CHECK (rating BETWEEN 1 AND 5)
        ) {$o}");
    },

    'down' => function (Database $db): void {
        foreach (['reviews', 'product_tag', 'product_category', 'product_images', 'product_attribute_values', 'variant_values',
                  'product_variants', 'products', 'attribute_values', 'attributes', 'product_tags', 'product_categories', 'vendors'] as $t) {
            $db->statement("DROP TABLE IF EXISTS {$t}");
        }
    },
];
