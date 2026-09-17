-- ============================================================
--  MIGRATION: 007_seed_training_shop.sql
--  Seeds falcon.training_programs and falcon.shop_items so the
--  homepage's Training and Shop sections have content to render.
--  Safe to re-run: only inserts if the tables are empty.
-- ============================================================

INSERT INTO falcon.training_programs (title, description, price_label, color, sort_order)
SELECT * FROM (VALUES
    ('Beginner Clinic', 'New to pickleball? Learn the fundamentals — grip, serve, dinking, and basic strategy — in a relaxed group setting.', '₱500 / session', 'green', 1),
    ('Intermediate Skills', 'Sharpen your third-shot drop, footwork, and court positioning with drills designed for players ready to level up.', '₱700 / session', 'blue', 2),
    ('Private Coaching', 'One-on-one sessions with a certified coach, tailored to your game and goals.', '₱1,200 / hour', 'orange', 3)
) AS seed(title, description, price_label, color, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM falcon.training_programs);

INSERT INTO falcon.shop_items (name, category, price, badge, sort_order)
SELECT * FROM (VALUES
    ('Falcon Pro Paddle', 'Paddles', 2500.00, 'New', 1),
    ('Court Grip Overwrap', 'Accessories', 250.00, NULL, 2),
    ('Falcon Team Jersey', 'Apparel', 850.00, NULL, 3),
    ('Outdoor Pickleball (3-pack)', 'Balls', 450.00, NULL, 4)
) AS seed(name, category, price, badge, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM falcon.shop_items);