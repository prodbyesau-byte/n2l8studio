-- ============================================================
-- N2L8Studio — MySQL Schema
-- Run once via phpMyAdmin: https://mysql.simply.com
-- Database: n2l8studio_dk_db
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Users (admin auth)
CREATE TABLE IF NOT EXISTS `users` (
  `id`       INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50)  UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role`     VARCHAR(20)  NOT NULL DEFAULT 'admin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Products
CREATE TABLE IF NOT EXISTS `products` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `title`          VARCHAR(100)    NOT NULL,
  `type`           VARCHAR(50)     NOT NULL,
  `genre`          VARCHAR(50)     NOT NULL,
  `price`          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `original_price` DECIMAL(10,2)   NULL,
  `author`         VARCHAR(100)    NULL,
  `description`    TEXT            NULL,
  `bpm`            VARCHAR(20)     NULL,
  `key`            VARCHAR(20)     NULL,
  `cover_image`    VARCHAR(255)    NULL,
  `zip_file`       VARCHAR(255)    NULL,
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `position`       INT             NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preview tracks (per product)
CREATE TABLE IF NOT EXISTS `product_tracks` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT          NOT NULL,
  `title`      VARCHAR(150) NOT NULL,
  `filename`   VARCHAR(255) NOT NULL,
  `preview_start` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `preview_end`   DECIMAL(8,2) NULL,
  `position`   INT          NOT NULL DEFAULT 0,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orders
CREATE TABLE IF NOT EXISTS `orders` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `customer_email` VARCHAR(100) NOT NULL,
  `product_id`     INT          NULL,
  `status`         VARCHAR(50)  NOT NULL DEFAULT 'completed',
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Editable site content
CREATE TABLE IF NOT EXISTS `content` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `section_key` VARCHAR(100) UNIQUE NOT NULL,
  `label`       VARCHAR(150) NOT NULL,
  `page`        VARCHAR(50)  NOT NULL DEFAULT 'global',
  `text`        TEXT         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `action`     VARCHAR(255) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User saved kits and profile history
CREATE TABLE IF NOT EXISTS `user_saved_products` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT NOT NULL,
  `product_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_product` (`user_id`, `product_id`),
  INDEX `idx_user_saved` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_activity` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT NOT NULL,
  `product_id` INT NULL,
  `action`     VARCHAR(80) NOT NULL,
  `metadata`   VARCHAR(255) NOT NULL DEFAULT '',
  `page`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_activity` (`user_id`, `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- NOTE: After running this SQL, visit:
--   https://n2l8studio.dk/setup.php?token=n2l8setup2026
-- to create the admin user and seed all content blocks.
-- Delete setup.php from the server after running it.
-- ============================================================
