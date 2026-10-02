-- ================================================================
-- 237Biz Growth Partner — Phase 3B Migration
-- AI Content enhancements: campaign_id + prompt_summary alias
-- Run after growth-partner-p3-migration.sql
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Add campaign_id to ai_generated_content (nullable, no FK constraint)
ALTER TABLE ai_generated_content
    ADD COLUMN IF NOT EXISTS campaign_id INT UNSIGNED NULL AFTER listing_id;

-- Add index for campaign lookups
ALTER TABLE ai_generated_content
    ADD INDEX IF NOT EXISTS idx_agc_campaign (campaign_id);

SET FOREIGN_KEY_CHECKS = 1;
