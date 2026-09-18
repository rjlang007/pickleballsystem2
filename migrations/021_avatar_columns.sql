-- Keep avatar column names compatible with the current application code.
ALTER TABLE falcon.users
    ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255),
    ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(255);

UPDATE falcon.users
   SET avatar_path = COALESCE(avatar_path, avatar),
       avatar_url = COALESCE(avatar_url, avatar_path, avatar)
 WHERE avatar_path IS NULL OR avatar_url IS NULL;

-- Normalize legacy spellings so role guards recognize superadmins.
UPDATE falcon.users
    SET role = 'super_admin'
 WHERE LOWER(TRIM(role)) IN ('superadmin', 'super admin', 'super-admin');
