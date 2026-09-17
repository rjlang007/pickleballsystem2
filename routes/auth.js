const express = require('express');
const router = express.Router();
const pool = require('../db/pool');
const {
  hashPassword,
  verifyPassword,
  signAccessToken,
  generateRefreshToken,
} = require('../utils/auth');
const { requireAuth, requireRole, numericParamGuard } = require('../middleware/auth');

router.param('id', numericParamGuard('User id'));

const failedAttempts = new Map();
const MAX_ATTEMPTS = 5;
const LOCK_MS = 15 * 60 * 1000;

function checkLock(email) {
  // TEMP: lockout disabled for local dev — re-enable before deploying
  return false;

  // const entry = failedAttempts.get(email);
  // if (entry && entry.lockedUntil && entry.lockedUntil > Date.now()) {
  //   return true;
  // }
  // return false;
}

function registerFailure(email) {
  const entry = failedAttempts.get(email) || { count: 0, lockedUntil: null };
  entry.count += 1;
  if (entry.count >= MAX_ATTEMPTS) {
    entry.lockedUntil = Date.now() + LOCK_MS;
    entry.count = 0;
  }
  failedAttempts.set(email, entry);
}

function clearFailures(email) {
  failedAttempts.delete(email);
}

// POST /auth/login
router.post('/login', async (req, res) => {
  const { email, password } = req.body;
  if (!email || !password) {
    return res.status(400).json({ error: 'email and password are required' });
  }

  if (checkLock(email)) {
    return res.status(429).json({ error: 'Too many failed attempts. Try again in 15 minutes.' });
  }

  try {
    const { rows } = await pool.query(
      'SELECT * FROM users WHERE email = $1 AND deleted_at IS NULL',
      [email]
    );
    const user = rows[0];

    if (!user || !(await verifyPassword(password, user.password_hash))) {
      registerFailure(email);
      return res.status(401).json({ error: 'Invalid email or password' });
    }

    clearFailures(email);

    const accessToken = signAccessToken(user);
    const { token: refreshToken, expiresAt } = generateRefreshToken();

    await pool.query(
      'INSERT INTO sessions (user_id, token, expires_at) VALUES ($1, $2, $3)',
      [user.id, refreshToken, expiresAt]
    );

    res.json({
      accessToken,
      refreshToken,
      user: {
        id: user.id,
        role: user.role,
        email: user.email,
        linked_owner_id: user.linked_owner_id,
        linked_staff_id: user.linked_staff_id,
        linked_customer_id: user.linked_customer_id,
      },
    });
  } catch (err) {
    console.error('LOGIN ERROR:', err);
    res.status(500).json({ error: 'Login failed' });
  }
});

// POST /auth/refresh — exchange a valid refresh token for a new access token
router.post('/refresh', async (req, res) => {
  const { refreshToken } = req.body;
  if (!refreshToken) return res.status(400).json({ error: 'refreshToken is required' });

  try {
    const { rows } = await pool.query(
      'SELECT s.*, u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = $1',
      [refreshToken]
    );
    const session = rows[0];

    if (!session || new Date(session.expires_at) < new Date()) {
      return res.status(401).json({ error: 'Refresh token invalid or expired' });
    }

    const accessToken = signAccessToken(session);
    res.json({ accessToken });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Refresh failed' });
  }
});

// POST /auth/logout — invalidate the refresh token (session)
router.post('/logout', async (req, res) => {
  const { refreshToken } = req.body;
  if (!refreshToken) return res.status(400).json({ error: 'refreshToken is required' });

  try {
    await pool.query('DELETE FROM sessions WHERE token = $1', [refreshToken]);
    res.status(204).send();
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Logout failed' });
  }
});

// POST /auth/users — admin-only account creation for all 4 role types.
router.post('/users', requireAuth, requireRole('admin'), async (req, res) => {
  const { role, email, password, linked_owner_id, linked_staff_id, linked_customer_id } = req.body;

  const validRoles = ['admin', 'owner', 'delivery_staff', 'customer'];
  if (!validRoles.includes(role)) {
    return res.status(400).json({ error: `role must be one of ${validRoles.join(', ')}` });
  }
  if (!email || !password) {
    return res.status(400).json({ error: 'email and password are required' });
  }
  if (password.length < 8) {
    return res.status(400).json({ error: 'password must be at least 8 characters' });
  }

  try {
    const password_hash = await hashPassword(password);
    const { rows } = await pool.query(
      `INSERT INTO users (role, email, password_hash, linked_owner_id, linked_staff_id, linked_customer_id)
       VALUES ($1, $2, $3, $4, $5, $6)
       RETURNING id, role, email, linked_owner_id, linked_staff_id, linked_customer_id, created_at`,
      [role, email, password_hash, linked_owner_id || null, linked_staff_id || null, linked_customer_id || null]
    );

    await pool.query(
      `INSERT INTO audit_log (user_id, action, target_table, target_id, details)
       VALUES ($1, 'created_user', 'users', $2, $3)`,
      [req.user.sub, rows[0].id, `role=${role}`]
    );

    res.status(201).json(rows[0]);
  } catch (err) {
    if (err.code === '23505') {
      return res.status(409).json({ error: 'Email already in use' });
    }
    console.error(err);
    res.status(500).json({ error: 'Could not create user' });
  }
});

// GET /auth/users — admin only: list all login accounts, with the
// linked owner/staff/customer's name resolved for display (the admin UI
// needs to show "who" a login belongs to, not just a bare linked id).
router.get('/users', requireAuth, requireRole('admin'), async (req, res) => {
  const { rows } = await pool.query(
    `SELECT u.id, u.role, u.email, u.created_at, u.deleted_at,
            u.linked_owner_id, u.linked_staff_id, u.linked_customer_id,
            COALESCE(o.name, s.name, c.name) AS linked_name
     FROM users u
     LEFT JOIN owners o ON o.id = u.linked_owner_id
     LEFT JOIN staff s ON s.id = u.linked_staff_id
     LEFT JOIN customers c ON c.id = u.linked_customer_id
     ORDER BY u.created_at DESC`
  );
  res.json(rows);
});

// POST /auth/accounts — admin only: the "create an owner / staff / customer
// / admin account" feature. Unlike POST /auth/users above (which only
// creates a *login* and requires the owners/staff/customers row to already
// exist), this creates the business record AND the login together in one
// transaction, since from the admin's point of view "add a new delivery
// crew member" is one action, not two. Existing POST /owners, POST /staff,
// and POST /auth/users are untouched and still work independently (e.g.
// record-keeping-only owners with no login at all).
router.post('/accounts', requireAuth, requireRole('admin'), async (req, res) => {
  const { role, email, password, name } = req.body;

  const validRoles = ['admin', 'owner', 'delivery_staff', 'customer'];
  if (!validRoles.includes(role)) {
    return res.status(400).json({ error: `role must be one of ${validRoles.join(', ')}` });
  }
  if (!email || !password) {
    return res.status(400).json({ error: 'email and password are required' });
  }
  if (password.length < 8) {
    return res.status(400).json({ error: 'password must be at least 8 characters' });
  }
  if (role !== 'admin' && !name) {
    return res.status(400).json({ error: 'name is required for owner, delivery_staff, and customer accounts' });
  }

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    let linked_owner_id = null;
    let linked_staff_id = null;
    let linked_customer_id = null;

    if (role === 'owner') {
      const { investment_percent, contact_info, runs_business } = req.body;
      if (investment_percent != null && (isNaN(investment_percent) || investment_percent < 0 || investment_percent > 100)) {
        throw Object.assign(new Error('investment_percent must be a number between 0 and 100'), { status: 400 });
      }
      const { rows } = await client.query(
        `INSERT INTO owners (name, investment_percent, contact_info, runs_business)
         VALUES ($1, COALESCE($2, 0), $3, COALESCE($4, false)) RETURNING id`,
        [name, investment_percent, contact_info || null, runs_business || false]
      );
      linked_owner_id = rows[0].id;
    } else if (role === 'delivery_staff') {
      const { contact_number, rate_per_job } = req.body;
      const { rows } = await client.query(
        `INSERT INTO staff (name, contact_number, rate_per_job) VALUES ($1, $2, $3) RETURNING id`,
        [name, contact_number || null, rate_per_job || null]
      );
      linked_staff_id = rows[0].id;
    } else if (role === 'customer') {
      const { contact_number, address } = req.body;
      const { rows } = await client.query(
        `INSERT INTO customers (name, contact_number, email, address) VALUES ($1, $2, $3, $4) RETURNING id`,
        [name, contact_number || null, email, address || null]
      );
      linked_customer_id = rows[0].id;
    }

    const password_hash = await hashPassword(password);
    const { rows: userRows } = await client.query(
      `INSERT INTO users (role, email, password_hash, linked_owner_id, linked_staff_id, linked_customer_id)
       VALUES ($1, $2, $3, $4, $5, $6)
       RETURNING id, role, email, linked_owner_id, linked_staff_id, linked_customer_id, created_at`,
      [role, email, password_hash, linked_owner_id, linked_staff_id, linked_customer_id]
    );

    await client.query(
      `INSERT INTO audit_log (user_id, action, target_table, target_id, details)
       VALUES ($1, 'created_account', 'users', $2, $3)`,
      [req.user.sub, userRows[0].id, `role=${role}${name ? `, name=${name}` : ''}`]
    );

    await client.query('COMMIT');
    res.status(201).json(userRows[0]);
  } catch (err) {
    await client.query('ROLLBACK');
    if (err.status === 400) {
      return res.status(400).json({ error: err.message });
    }
    if (err.code === '23505') {
      return res.status(409).json({ error: 'Email already in use' });
    }
    console.error(err);
    res.status(500).json({ error: 'Could not create account' });
  } finally {
    client.release();
  }
});

// PATCH /auth/users/:id/deactivate — admin only: revoke a login without
// deleting the underlying owner/staff/customer business record, and kill
// any active sessions so a deactivated account can't keep using an
// already-issued token.
router.patch('/users/:id/deactivate', requireAuth, requireRole('admin'), async (req, res) => {
  if (Number(req.params.id) === req.user.sub) {
    return res.status(400).json({ error: 'You cannot deactivate your own account.' });
  }

  const { rows } = await pool.query(
    `UPDATE users SET deleted_at = now() WHERE id = $1 AND deleted_at IS NULL RETURNING id, email, role`,
    [req.params.id]
  );
  if (!rows[0]) return res.status(404).json({ error: 'User not found or already deactivated' });

  await pool.query('DELETE FROM sessions WHERE user_id = $1', [req.params.id]);
  await pool.query(
    `INSERT INTO audit_log (user_id, action, target_table, target_id, details)
     VALUES ($1, 'deactivated_user', 'users', $2, NULL)`,
    [req.user.sub, req.params.id]
  );

  res.json({ success: true });
});

// PATCH /auth/users/:id/reactivate — admin only: undo a deactivation.
router.patch('/users/:id/reactivate', requireAuth, requireRole('admin'), async (req, res) => {
  const { rows } = await pool.query(
    `UPDATE users SET deleted_at = NULL WHERE id = $1 AND deleted_at IS NOT NULL RETURNING id, email, role`,
    [req.params.id]
  );
  if (!rows[0]) return res.status(404).json({ error: 'User not found or already active' });

  await pool.query(
    `INSERT INTO audit_log (user_id, action, target_table, target_id, details)
     VALUES ($1, 'reactivated_user', 'users', $2, NULL)`,
    [req.user.sub, req.params.id]
  );

  res.json({ success: true });
});

// POST /auth/register — public self-registration, customers only.
router.post('/register', async (req, res) => {
  const { name, email, password, contact_number, address } = req.body;
  if (!name || !email || !password) {
    return res.status(400).json({ error: 'name, email and password are required' });
  }
  if (password.length < 8) {
    return res.status(400).json({ error: 'password must be at least 8 characters' });
  }

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    const customerResult = await client.query(
      `INSERT INTO customers (name, contact_number, email, address)
       VALUES ($1, $2, $3, $4) RETURNING id`,
      [name, contact_number || null, email, address || null]
    );
    const customerId = customerResult.rows[0].id;

    const password_hash = await hashPassword(password);
    const userResult = await client.query(
      `INSERT INTO users (role, email, password_hash, linked_customer_id)
       VALUES ('customer', $1, $2, $3)
       RETURNING id, role, email, linked_customer_id, created_at`,
      [email, password_hash, customerId]
    );

    await client.query('COMMIT');
    res.status(201).json(userResult.rows[0]);
  } catch (err) {
    await client.query('ROLLBACK');
    if (err.code === '23505') {
      return res.status(409).json({ error: 'Email already in use' });
    }
    console.error(err);
    res.status(500).json({ error: 'Registration failed' });
  } finally {
    client.release();
  }
});

// PATCH /auth/password
router.patch('/password', requireAuth, async (req, res) => {
  const { currentPassword, newPassword } = req.body;
  if (!currentPassword || !newPassword) {
    return res.status(400).json({ error: 'currentPassword and newPassword are required' });
  }
  if (newPassword.length < 8) {
    return res.status(400).json({ error: 'newPassword must be at least 8 characters' });
  }

  try {
    const { rows } = await pool.query('SELECT * FROM users WHERE id = $1', [req.user.sub]);
    const user = rows[0];
    if (!user || !(await verifyPassword(currentPassword, user.password_hash))) {
      return res.status(401).json({ error: 'Current password is incorrect' });
    }

    const password_hash = await hashPassword(newPassword);
    await pool.query('UPDATE users SET password_hash = $1 WHERE id = $2', [password_hash, user.id]);

    await pool.query('DELETE FROM sessions WHERE user_id = $1', [user.id]);

    res.json({ success: true });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Password change failed' });
  }
});

module.exports = router;