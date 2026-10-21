-- ============================================================
-- fix-review-votes.sql
-- Creates the missing review_votes table
-- Run once via phpMyAdmin on biz97h_app
-- Safe to re-run (IF NOT EXISTS)
-- ============================================================

CREATE TABLE IF NOT EXISTS review_votes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    vote        TINYINT NOT NULL COMMENT '1=helpful, -1=not_helpful',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_user (review_id, user_id),
    KEY idx_review_id (review_id),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
