-- ============================================================
-- Migration 033: normalize legacy Open Play scheduler price
--
-- Migration 030 introduced the scheduler with a zero-price default.
-- Only rows still carrying that old default are changed; admins can
-- continue editing the scheduler price afterward.
-- ============================================================

UPDATE falcon.site_content
   SET value = '100', updated_at = NOW()
 WHERE section = 'open_play_schedule'
   AND key = 'price'
   AND TRIM(value) IN ('', '0', '0.00');
