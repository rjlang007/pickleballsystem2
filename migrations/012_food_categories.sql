-- ============================================================
--  MIGRATION: 012_food_categories.sql
--  Turns food_items.category (free text) into a managed,
--  admin-editable list — no more duplicate categories from typos
--  like "Drinks" / "drinks " / "Drink".
--
--  - falcon.food_categories   (id, name, sort_order)
--  - falcon.food_items.category_id → falcon.food_categories(id)
--
--  Existing distinct category strings already in food_items are
--  auto-imported as categories and items are linked to them, so
--  this is safe to run on an install that already has menu data.
--  The old `category` text column is left in place (unused by
--  new code) so nothing breaks if you need to roll back.
--
--  Safe to re-run: every statement is guarded with IF NOT EXISTS /
--  DO $$ ... $$ blocks, matching the style of the other migrations.
-- ============================================================

-- ── Managed category list ──────────────────────────────────
CREATE TABLE IF NOT EXISTS falcon.food_categories (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(100) NOT NULL UNIQUE,
    sort_order INTEGER      NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Link food_items → food_categories ──────────────────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'falcon' AND table_name = 'food_items' AND column_name = 'category_id'
    ) THEN
        ALTER TABLE falcon.food_items
            ADD COLUMN category_id INTEGER REFERENCES falcon.food_categories(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_food_items_category_id ON falcon.food_items (category_id);

-- ── Seed categories from whatever distinct text values are
--    already sitting in food_items.category (dedupes case/whitespace
--    variants by trimming; first-seen casing wins) ──
INSERT INTO falcon.food_categories (name, sort_order)
SELECT DISTINCT ON (LOWER(TRIM(category))) TRIM(category), 0
  FROM falcon.food_items
 WHERE category IS NOT NULL AND TRIM(category) <> ''
   AND NOT EXISTS (
        SELECT 1 FROM falcon.food_categories fc
         WHERE LOWER(fc.name) = LOWER(TRIM(falcon.food_items.category))
   )
 ORDER BY LOWER(TRIM(category)), category;

-- ── Make sure a sensible default category always exists,
--    even on a brand-new install with no items yet ──
INSERT INTO falcon.food_categories (name, sort_order)
SELECT 'Main', 0
 WHERE NOT EXISTS (SELECT 1 FROM falcon.food_categories);

-- ── Give the seeded categories a stable, friendly display order ──
UPDATE falcon.food_categories SET sort_order = 1 WHERE LOWER(name) = 'drinks'  AND sort_order = 0;
UPDATE falcon.food_categories SET sort_order = 2 WHERE LOWER(name) = 'main'    AND sort_order = 0;
UPDATE falcon.food_categories SET sort_order = 3 WHERE LOWER(name) = 'snacks'  AND sort_order = 0;

-- ── Backfill: link every existing item to its matching category ──
UPDATE falcon.food_items fi
   SET category_id = fc.id
  FROM falcon.food_categories fc
 WHERE fi.category_id IS NULL
   AND fi.category IS NOT NULL
   AND LOWER(fc.name) = LOWER(TRIM(fi.category));

-- ── Anything still unlinked (e.g. category was blank) falls back
--    to "Main" so it doesn't silently disappear from the menu ──
UPDATE falcon.food_items fi
  SET category_id = (SELECT MIN(id) FROM falcon.food_categories WHERE LOWER(name) = 'main')
 WHERE fi.category_id IS NULL;
