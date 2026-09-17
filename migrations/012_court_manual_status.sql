ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS manual_status VARCHAR(20);
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS manual_status_set_by INTEGER REFERENCES falcon.users(id);
ALTER TABLE falcon.courts ADD COLUMN IF NOT EXISTS manual_status_set_at TIMESTAMP;
-- allowed values: NULL (auto), 'open_play', 'reserved', 'occupied', 'tournament'