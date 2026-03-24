-- Migration: Rename columns in foundation_needlist to match Data Dictionary
-- Date: 2026-03-24

-- Rename qty_needed → quantity_required
ALTER TABLE foundation_needlist CHANGE COLUMN qty_needed quantity_required INT NOT NULL;

-- Rename price_estimate → item_price
ALTER TABLE foundation_needlist CHANGE COLUMN price_estimate item_price DECIMAL(10,2) NOT NULL;

-- Rename item_image → photo_item
ALTER TABLE foundation_needlist CHANGE COLUMN item_image photo_item VARCHAR(255) NULL;

-- Verification
DESCRIBE foundation_needlist;
