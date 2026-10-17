-- 237Biz Blog System — Migration
-- Run once on the live database

CREATE TABLE IF NOT EXISTS blog_posts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(255) NOT NULL UNIQUE,
    title_en    VARCHAR(255) NOT NULL,
    title_fr    VARCHAR(255) NOT NULL DEFAULT '',
    excerpt_en  TEXT,
    excerpt_fr  TEXT,
    body_en     LONGTEXT NOT NULL,
    body_fr     LONGTEXT NOT NULL DEFAULT '',
    category    ENUM('tips','news','spotlight','event') NOT NULL DEFAULT 'tips',
    cover_url   VARCHAR(512) DEFAULT NULL,
    author_name VARCHAR(120) NOT NULL DEFAULT '237Biz Team',
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    views       INT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_pub (status, published_at),
    INDEX idx_category   (category),
    INDEX idx_slug       (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
