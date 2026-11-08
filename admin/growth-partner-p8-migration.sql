-- Add per-partner marketplace commission rate
-- Default 10% if not set by admin
ALTER TABLE partner_profiles
  ADD COLUMN IF NOT EXISTS marketplace_commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00
  COMMENT 'Default commission % for marketplace orders. Admin can override per partner.';
