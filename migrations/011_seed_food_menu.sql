-- ============================================================
--  MIGRATION: 011_seed_food_menu.sql
--  OPTIONAL: seeds a starter food menu so there's something to
--  test with right away. Safe to re-run: only inserts if
--  falcon.food_items is empty. Edit prices/names freely afterward
--  in Admin → Food → Manage Menu — this is just a starting point.
-- ============================================================

INSERT INTO falcon.food_items (name, description, price, category, sort_order, is_available)
SELECT * FROM (VALUES
    ('Bottled Water',       'Chilled 500ml bottled water.',                    30.00, 'Drinks', 1, TRUE),
    ('Gatorade',            'Assorted flavors, 500ml.',                        60.00, 'Drinks', 2, TRUE),
    ('Iced Coffee',         'House-blend iced coffee.',                        70.00, 'Drinks', 3, TRUE),
    ('Chicken Sandwich',    'Grilled chicken, lettuce, and mayo on toast.',    120.00, 'Main',   1, TRUE),
    ('Beef Burger',         'Quarter-pound beef patty with cheese.',           150.00, 'Main',   2, TRUE),
    ('French Fries',        'Crispy salted fries, regular size.',               80.00, 'Snacks', 1, TRUE),
    ('Chicken Wings (6pc)', 'Fried wings tossed in your choice of sauce.',     160.00, 'Snacks', 2, TRUE),
    ('Banana Chips',        'Locally made, individually packed.',               40.00, 'Snacks', 3, TRUE)
) AS seed(name, description, price, category, sort_order, is_available)
WHERE NOT EXISTS (SELECT 1 FROM falcon.food_items);
