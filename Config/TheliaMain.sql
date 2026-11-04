
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- jsonl_import
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `jsonl_import`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `author` VARCHAR(255),
    `status` VARCHAR(20) DEFAULT 'uploaded' NOT NULL,
    `parameters` TEXT,
    `line_count` INTEGER DEFAULT 0,
    `valid_count` INTEGER DEFAULT 0,
    `invalid_count` INTEGER DEFAULT 0,
    `created_count` INTEGER DEFAULT 0,
    `updated_count` INTEGER DEFAULT 0,
    `error_count` INTEGER DEFAULT 0,
    `excluded_count` INTEGER DEFAULT 0,
    `analysis_offset` INTEGER DEFAULT 0,
    `cursor_line` INTEGER DEFAULT 0,
    `phase` VARCHAR(20),
    `current_ref` VARCHAR(255),
    `last_batch_at` DATETIME,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- jsonl_import_line
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `jsonl_import_line`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `import_id` INTEGER NOT NULL,
    `line_number` INTEGER NOT NULL,
    `ref` VARCHAR(255),
    `valid` TINYINT DEFAULT 0,
    `excluded` TINYINT DEFAULT 0,
    `action` VARCHAR(10),
    `result` VARCHAR(10) DEFAULT 'pending' NOT NULL,
    `payload` LONGTEXT,
    `errors` TEXT,
    `warnings` TEXT,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uk_jsonl_import_line_import_number` (`import_id`, `line_number`),
    INDEX `idx_jsonl_import_line_import_ref` (`import_id`, `ref`),
    CONSTRAINT `fk_jsonl_import_line_import_id`
        FOREIGN KEY (`import_id`)
        REFERENCES `jsonl_import` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- jsonl_import_mapping
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `jsonl_import_mapping`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(20) NOT NULL,
    `parent_id` INTEGER DEFAULT 0 NOT NULL,
    `source_title` VARCHAR(255) NOT NULL,
    `target_id` INTEGER,
    `ignored` TINYINT DEFAULT 0,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uk_jsonl_import_mapping` (`type`, `parent_id`, `source_title`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- jsonl_import_media
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `jsonl_import_media`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `media_type` VARCHAR(20) NOT NULL,
    `product_id` INTEGER DEFAULT 0 NOT NULL,
    `object_id` INTEGER NOT NULL,
    `source` TEXT NOT NULL,
    `source_hash` CHAR(40) NOT NULL,
    `file` VARCHAR(255),
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uk_jsonl_import_media` (`media_type`, `product_id`, `source_hash`)
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
