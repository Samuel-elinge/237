-- ============================================================
-- 237Biz Phase 2 Sprint 1 — Migration 002
-- Run once on live DB. Safe to re-run: all statements are
-- guarded with IF NOT EXISTS / column-exists checks.
-- ============================================================

-- ── 1. Add user_id FK to listing_bookings ───────────────────
SET @b_uid = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'listing_bookings'
    AND COLUMN_NAME  = 'user_id'
);
SET @sql_b = IF(@b_uid = 0,
  "ALTER TABLE listing_bookings
     ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER listing_id,
     ADD KEY idx_bookings_user (user_id)",
  "SELECT 'listing_bookings.user_id already exists, skipping' AS info"
);
PREPARE stmt_b FROM @sql_b; EXECUTE stmt_b; DEALLOCATE PREPARE stmt_b;

-- Back-fill user_id where customer_email matches a registered user
-- COLLATE forces a common collation so utf8mb4_general_ci vs utf8mb4_unicode_ci doesn't clash
UPDATE listing_bookings b
  JOIN users u ON u.email COLLATE utf8mb4_unicode_ci = b.customer_email COLLATE utf8mb4_unicode_ci
  SET b.user_id = u.id
  WHERE b.user_id IS NULL AND b.customer_email IS NOT NULL AND b.customer_email != '';

-- ── 2. Add user_id FK to event_registrations ────────────────
SET @e_uid = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'event_registrations'
    AND COLUMN_NAME  = 'user_id'
);
SET @sql_e = IF(@e_uid = 0,
  "ALTER TABLE event_registrations
     ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER id,
     ADD KEY idx_evtreg_user (user_id)",
  "SELECT 'event_registrations.user_id already exists, skipping' AS info"
);
PREPARE stmt_e FROM @sql_e; EXECUTE stmt_e; DEALLOCATE PREPARE stmt_e;

-- Back-fill user_id where registration email matches a registered user
UPDATE event_registrations er
  JOIN users u ON u.email COLLATE utf8mb4_unicode_ci = er.email COLLATE utf8mb4_unicode_ci
  SET er.user_id = u.id
  WHERE er.user_id IS NULL AND er.email IS NOT NULL AND er.email != '';

-- ── 3. user_favourites table ─────────────────────────────────
CREATE TABLE IF NOT EXISTS user_favourites (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  item_type    ENUM('listing','offer','event') NOT NULL,
  item_id      INT UNSIGNED NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE  KEY ux_fav_user_item (user_id, item_type, item_id),
  KEY     idx_fav_user (user_id),
  KEY     idx_fav_item (item_type, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. notifications table (Sprint 2 prereq, created now) ───
CREATE TABLE IF NOT EXISTS notifications (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  type         VARCHAR(60)  NOT NULL,          -- 'booking_confirmed','event_reminder', etc.
  channel      ENUM('email','bell','whatsapp') NOT NULL DEFAULT 'bell',
  title_en     VARCHAR(200) NOT NULL,
  title_fr     VARCHAR(200) DEFAULT NULL,
  body_en      TEXT         DEFAULT NULL,
  body_fr      TEXT         DEFAULT NULL,
  link         VARCHAR(500) DEFAULT NULL,      -- relative URL to action
  is_read      TINYINT(1)   NOT NULL DEFAULT 0,
  delivery_status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  entity_type  VARCHAR(60)  DEFAULT NULL,      -- 'booking','event', etc.
  entity_id    INT UNSIGNED DEFAULT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_notif_user_read  (user_id, is_read),
  KEY idx_notif_user_date  (user_id, created_at),
  KEY idx_notif_entity     (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. notification_prefs table ──────────────────────────────
CREATE TABLE IF NOT EXISTS notification_prefs (
  user_id          INT UNSIGNED NOT NULL,
  booking_email    TINYINT(1) NOT NULL DEFAULT 1,
  booking_bell     TINYINT(1) NOT NULL DEFAULT 1,
  event_email      TINYINT(1) NOT NULL DEFAULT 1,
  event_bell       TINYINT(1) NOT NULL DEFAULT 1,
  follow_email     TINYINT(1) NOT NULL DEFAULT 1,
  follow_bell      TINYINT(1) NOT NULL DEFAULT 1,
  promo_email      TINYINT(1) NOT NULL DEFAULT 0,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. user_follows table (Sprint 2 ready) ───────────────────
CREATE TABLE IF NOT EXISTS user_follows (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  follow_type   ENUM('listing') NOT NULL DEFAULT 'listing',
  follow_id     INT UNSIGNED NOT NULL,
  notify        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unfollowed_at DATETIME     DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE  KEY ux_follow_active (user_id, follow_type, follow_id, unfollowed_at),
  KEY     idx_follow_user  (user_id),
  KEY     idx_follow_item  (follow_type, follow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Migration 002 complete' AS result;
