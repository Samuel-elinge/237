-- Sprint 7: Review system enhancements
-- Run once via: mysql -u USER -p DBNAME < migrations/sprint7_reviews.sql

-- Add owner reply columns to reviews table
ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS owner_reply TEXT NULL AFTER comment,
    ADD COLUMN IF NOT EXISTS owner_reply_at DATETIME NULL AFTER owner_reply,
    ADD COLUMN IF NOT EXISTS flagged TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_reply_at;

-- Create review_votes table for helpfulness voting
CREATE TABLE IF NOT EXISTS review_votes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    vote        TINYINT NOT NULL DEFAULT 1 COMMENT '1=helpful, -1=not helpful',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_user (review_id, user_id),
    INDEX idx_review (review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
