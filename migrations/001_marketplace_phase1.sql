-- ============================================================
-- 237Biz Marketplace Phase 1 — Migration 001
-- Run once on live DB. Safe to re-run: all statements are
-- guarded with IF NOT EXISTS / IGNORE where possible.
-- ============================================================

-- ── Offers (Deals & Special Offers) ─────────────────────────
CREATE TABLE IF NOT EXISTS offers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id        INT UNSIGNED NOT NULL,
  title             VARCHAR(200) NOT NULL,
  title_fr          VARCHAR(200) DEFAULT NULL,
  slug              VARCHAR(220) NOT NULL,
  description       TEXT         DEFAULT NULL,
  description_fr    TEXT         DEFAULT NULL,
  offer_type        ENUM('percent_off','fixed_off','bogo','free_item','bundle') NOT NULL DEFAULT 'percent_off',
  discount_value    DECIMAL(10,2) DEFAULT NULL,   -- % or XAF flat amount
  original_price    DECIMAL(10,2) DEFAULT NULL,
  promo_code        VARCHAR(50)   DEFAULT NULL,
  image             VARCHAR(255)  DEFAULT NULL,
  starts_at         DATETIME     NOT NULL,
  expires_at        DATETIME     DEFAULT NULL,
  status            ENUM('draft','pending','active','expired','rejected') NOT NULL DEFAULT 'draft',
  terms             TEXT         DEFAULT NULL,
  max_redemptions   INT          DEFAULT NULL,
  redemption_count  INT          NOT NULL DEFAULT 0,
  admin_notes       TEXT         DEFAULT NULL,    -- internal moderation note
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE  KEY ux_offers_slug (slug),
  KEY     idx_offers_listing (listing_id),
  KEY     idx_offers_status_expires (status, expires_at),
  KEY     idx_offers_starts (starts_at),

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Events ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS events (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id        INT UNSIGNED DEFAULT NULL,    -- NULL = platform event
  organiser_id      INT UNSIGNED NOT NULL,        -- user_id
  title             VARCHAR(200) NOT NULL,
  title_fr          VARCHAR(200) DEFAULT NULL,
  slug              VARCHAR(220) NOT NULL,
  description       TEXT         DEFAULT NULL,
  description_fr    TEXT         DEFAULT NULL,
  event_type        ENUM('workshop','networking','exhibition','conference','social','other') NOT NULL DEFAULT 'other',
  venue_name        VARCHAR(200) DEFAULT NULL,
  venue_address     TEXT         DEFAULT NULL,
  location_id       INT UNSIGNED DEFAULT NULL,
  lat               DECIMAL(10,7) DEFAULT NULL,
  lng               DECIMAL(10,7) DEFAULT NULL,
  starts_at         DATETIME     NOT NULL,
  ends_at           DATETIME     DEFAULT NULL,
  is_online         TINYINT(1)   NOT NULL DEFAULT 0,
  online_link       VARCHAR(500)  DEFAULT NULL,
  capacity          INT          DEFAULT NULL,    -- NULL = unlimited
  registered_count  INT          NOT NULL DEFAULT 0,
  is_free           TINYINT(1)   NOT NULL DEFAULT 1,
  ticket_price      DECIMAL(10,2) DEFAULT NULL,
  image             VARCHAR(255)  DEFAULT NULL,
  status            ENUM('draft','pending','approved','cancelled','ended') NOT NULL DEFAULT 'draft',
  featured          TINYINT(1)   NOT NULL DEFAULT 0,
  admin_notes       TEXT         DEFAULT NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE  KEY ux_events_slug (slug),
  KEY     idx_events_status_starts (status, starts_at),
  KEY     idx_events_location (location_id),
  KEY     idx_events_organiser (organiser_id),
  KEY     idx_events_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Event Registrations ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS event_registrations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  email       VARCHAR(200) NOT NULL,
  phone       VARCHAR(30)  DEFAULT NULL,
  lang        ENUM('en','fr') NOT NULL DEFAULT 'en',
  status      ENUM('registered','attended','cancelled','no_show') NOT NULL DEFAULT 'registered',
  qr_token    VARCHAR(64)  DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE  KEY ux_reg_event_email (event_id, email),
  UNIQUE  KEY ux_reg_qr (qr_token),
  KEY     idx_reg_event (event_id),

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Marketplace Impressions (analytics) ─────────────────────
CREATE TABLE IF NOT EXISTS marketplace_impressions (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  entity_type   ENUM('listing','offer','event') NOT NULL,
  entity_id     INT UNSIGNED NOT NULL,
  page_context  VARCHAR(100) DEFAULT NULL,   -- 'marketplace','offers','listing_page' etc.
  visitor_hash  VARCHAR(64)  DEFAULT NULL,   -- SHA256(ip+ua) for dedup, no PII stored
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_imp_entity  (entity_type, entity_id),
  KEY idx_imp_date    (created_at),
  KEY idx_imp_context (page_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Extend listing_bookings with booking_type ────────────────
-- Add only if column does not already exist
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'listing_bookings'
    AND COLUMN_NAME  = 'booking_type'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE listing_bookings
     ADD COLUMN booking_type     ENUM('appointment','table','service','experience') NOT NULL DEFAULT 'appointment' AFTER status,
     ADD COLUMN party_size       TINYINT UNSIGNED DEFAULT NULL AFTER booking_type,
     ADD COLUMN special_requests TEXT             DEFAULT NULL AFTER party_size",
  "SELECT 'booking_type already exists, skipping' AS info"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Extend listings with booking_types JSON ──────────────────
SET @col2_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'listings'
    AND COLUMN_NAME  = 'booking_types'
);
SET @sql2 = IF(@col2_exists = 0,
  "ALTER TABLE listings
     ADD COLUMN booking_types  JSON DEFAULT NULL COMMENT 'enabled types: appointment|table|service|experience'
     AFTER booking_enabled",
  "SELECT 'booking_types already exists, skipping' AS info"
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
