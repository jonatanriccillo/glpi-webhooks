CREATE TABLE IF NOT EXISTS `glpi_plugin_webhooks_webhooks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_recursive` TINYINT NOT NULL DEFAULT 1,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `url` TEXT NOT NULL,
    `http_method` VARCHAR(10) NOT NULL DEFAULT 'POST',
    `headers` TEXT,
    `itemtypes` TEXT NOT NULL,
    `anticipation_days` INT NOT NULL DEFAULT 30,
    `payload_template` LONGTEXT,
    `is_active` TINYINT NOT NULL DEFAULT 1,
    `comment` TEXT,
    `last_sent_date` TIMESTAMP NULL DEFAULT NULL,
    `last_http_status` INT DEFAULT NULL,
    `last_error` TEXT,
    `last_test_date` TIMESTAMP NULL DEFAULT NULL,
    `last_test_status` INT DEFAULT NULL,
    `last_test_response` TEXT,
    `date_creation` TIMESTAMP NULL DEFAULT NULL,
    `date_mod` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `entities_id` (`entities_id`),
    KEY `is_active` (`is_active`),
    KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `glpi_plugin_webhooks_sent` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webhooks_id` INT UNSIGNED NOT NULL,
    `itemtype` VARCHAR(100) NOT NULL,
    `items_id` INT UNSIGNED NOT NULL,
    `expiration_date` DATE NOT NULL,
    `http_status` INT DEFAULT NULL,
    `response_excerpt` TEXT,
    `error` TEXT,
    `sent_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unicity` (`webhooks_id`, `itemtype`, `items_id`, `expiration_date`),
    KEY `itemtype` (`itemtype`, `items_id`),
    KEY `sent_date` (`sent_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `glpi_plugin_webhooks_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `profiles_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `right` CHAR(1) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `profiles_id` (`profiles_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
