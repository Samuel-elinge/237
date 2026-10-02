-- ============================================================
-- 237biz Growth Partner Phase 6 Migration
-- Patch: add missing columns to fix partner dashboard 500
-- Safe to re-run (uses IF NOT EXISTS)
-- ============================================================

-- Add follow_up_date to partner_leads (referenced in dashboard.php lines 50, 420, 425, 434)
ALTER TABLE partner_leads
    ADD COLUMN IF NOT EXISTS follow_up_date DATE NULL;
