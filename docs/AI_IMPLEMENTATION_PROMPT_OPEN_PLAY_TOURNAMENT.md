# AI Implementation Prompt: Open Play and Tournament Management

You are working on an existing PHP/PostgreSQL pickleball court management system. Implement the following feature completely and safely. Do not only describe the solution: inspect the existing code, modify the required files, run validation, and report exactly what changed.

## Project Context

The application has these important areas:

- `config/app.php`: application bootstrap, roles, database session setup, shared helpers
- `config/security.php`: CSRF, CSP, session timeout, authorization helpers
- `staff/open_play_settings.php`: Open Play posting details module
- `staff/open_play_control.php`: Open Play live operations module
- `staff/tournament_queue.php`: tournament queue and approvals
- `admin/tournament_admin.php`: tournament creation and lifecycle management
- `admin/tournament_edit.php`: tournament posting editor
- `public/open_play.php`: player-facing Open Play page
- `public/tournaments.php`: player-facing tournament page
- `tournament/open_play_engine.php`: Open Play business logic
- `tournament/tournament_engine.php`: tournament business logic
- `api/open_play_payment_proof.php`: protected payment-proof viewer

Use the existing architecture, database schema, styling, session system, and helper functions. Do not create a separate authentication system.

## Roles and Permissions

There are four important roles:

- `player`: may browse postings, submit join requests, upload payment proof, leave their own request, and view their own request status.
- `staff`: may manage Open Play and tournament operations, review join requests, view payment proofs, approve requests, reject requests, manage queues, draw games, and enter/correct scores.
- `admin`: has all staff capabilities plus administrative posting management.
- `super_admin`: has all admin and staff capabilities across the entire system.

Use the existing role helpers:

- `requireLogin()` for authenticated pages
- `requireStaff()` for staff/admin/superadmin operational pages
- `requireAdmin()` for admin/superadmin administrative pages
- `isStaff()` when choosing player-facing versus management-facing UI

Players must never receive management controls, approval controls, payment-proof review links, queue controls, or score-management controls.

## Required Module Separation

Keep posting configuration separate from live operations.

### 1. Open Play Settings Module

Create or maintain a dedicated page at:

`staff/open_play_settings.php`

This page is only for creating and editing the Open Play posting details:

- Open Play name
- Public description
- Date and time
- Maximum number of players
- Entry price
- Singles or doubles format
- Game duration

It must:

- Be available to staff, admin, and superadmin.
- Use the existing CSRF helper with visible output: `<?= csrfField() ?>`.
- Use the existing `OpenPlayEngine::createEvent()` and `OpenPlayEngine::updateEvent()` methods where possible.
- Validate capacity, price, date, format, and duration server-side.
- Reject capacity values below the number of already-approved active players.
- Prevent editing completed or cancelled events.
- Show clear success and error messages.
- Link to the live Open Play Control module.

### 2. Open Play Control Module

Keep `staff/open_play_control.php` focused only on live operations:

- Select an event
- View pending requests
- View player names and skill levels
- Approve or reject requests
- Add players directly as staff
- Manage waiting, resting, queued, and playing states
- Draw/matchmake the next round
- View live courts and games
- Start, pause, resume, finish, and correct games
- Run tiebreakers or raffles when supported by the existing system
- View standings and recent results
- Link back to Open Play Settings for editing posting details

Do not place name, description, date, capacity, price, or format editing forms inside this live control page.

### 3. Tournament Posting Module

Tournament administrative pages must support editing:

- Tournament name
- Description
- Bracket type
- Maximum players
- Start date and time
- End date and time
- Registration price
- Featured status

Allow editing while the tournament is `draft` or `registration_open`. Do not allow unsafe edits after the tournament has started unless there is an explicit safe workflow.

Do not allow capacity to be reduced below the current approved/registered player count.

The public tournament page must display the configured registration price and current capacity.

## Join Request and Payment Workflow

### Player Flow

For a paid Open Play or tournament posting:

1. Player selects the posting.
2. Player submits a join request.
3. Player provides payment method.
4. Player provides payment reference.
5. Player uploads an image payment proof.
6. The request is stored as pending.
7. Player sees `Awaiting staff approval`.
8. Player cannot enter the approved queue until approved.

For free postings, payment proof may be skipped, but the request must still follow the approval workflow unless the product owner explicitly decides that free requests are auto-approved.

### Reviewer Flow

On the staff/admin/superadmin management page, reviewers must be able to see all pending requests for the selected posting, including:

- Player display name
- Username or full name
- Skill level where applicable
- Request date/time
- Request status
- Payment amount
- Payment method
- Payment reference
- A secure link to view the uploaded proof

Reviewers must have clear actions:

- Approve
- Reject/decline

Approval must:

- Change the request to an approved/active/registered state.
- Put Open Play players into the waiting queue when appropriate.
- Make approved tournament players eligible for check-in and bracket seeding.
- Record the reviewer and review time where the schema supports it.
- Write an audit entry where the existing system supports audit logging.

Rejection must:

- Remove the player from the active queue or approved roster.
- Preserve enough history to explain that the request was rejected.
- Not expose reviewer controls to players.

Use the existing protected payment-proof route or create an equivalent protected route. Payment proof files must not be publicly accessible by guessing a URL.

## Security Requirements

Do not weaken security to make the feature work.

- Use the shared DB-backed session system from `config/app.php`.
- Do not call bare `session_start()` before loading the application bootstrap.
- Every state-changing HTML form must include and echo `<?= csrfField() ?>`.
- Every state-changing handler must call `verifyCsrf()`.
- Keep CSRF protection valid across multiple tabs and background requests.
- Do not rotate the shared CSRF token in unrelated legacy handlers after successful requests.
- Do not use `javascript:` URLs.
- Do not add `unsafe-inline` to the CSP.
- Do not load libraries from a CSP-blocked host.
- Prefer existing local assets and nonce-protected scripts.
- Do not use external alert libraries that inject blocked inline styles unless the CSP-compatible integration is proven.
- Validate uploaded files by MIME type, size, extension, and safe storage path.
- Keep payment proof viewing behind `requireStaff()` or a stricter equivalent.
- Use prepared SQL statements everywhere.
- Do not trust role values sent by the browser.
- Enforce authorization server-side, not only by hiding buttons.

## UI Requirements

The UI should be direct and understandable.

Player-facing pages should show:

- Join/request action for players
- Pending request status
- Approved status
- Price and capacity
- Date/time and description

Staff/admin/superadmin viewing public posting pages should see:

- Manage Open Play
- Manage Tournament

They should not see player join buttons for their own management role.

Management pages should use clear sections and labels. Avoid one long mixed page. Keep configuration separate from live operations.

## Implementation Process

Follow this process:

1. Inspect the existing nearby implementation and schema before editing.
2. Identify the owning engine/service and existing authorization boundary.
3. Make the smallest complete change consistent with existing patterns.
4. Add or update migrations only when the existing schema cannot support the feature.
5. Keep active files and maintained migration/reference copies consistent when both exist.
6. Run PHP lint on every changed PHP file.
7. Run `git diff --check`.
8. Search for accidental insecure patterns, including:
   - `session_start()` before `config/app.php`
   - `csrfField();` without echoing its return value
   - `javascript:` URLs
   - blocked CDN hosts
   - unprotected payment proof paths
9. If tests exist, run the narrowest relevant tests.
10. Report deployment requirements and any remaining database migration steps.

## Acceptance Criteria

The implementation is complete only when all of these are true:

- Staff/admin/superadmin can create and edit Open Play posting details from a dedicated settings page.
- Staff/admin/superadmin can operate queues and games from a separate control page.
- Staff/admin/superadmin can create and edit tournament posting details.
- Players cannot access settings or control pages.
- Players can submit join requests and payment proof.
- All paid requests show proof, payment method, reference, amount, and status to authorized reviewers.
- Authorized reviewers can approve or reject Open Play requests.
- Authorized reviewers can approve or reject tournament requests.
- Players cannot approve, reject, edit, draw, or manage other players.
- Public pages show the correct action for the current role.
- CSRF validation works on every state-changing form.
- CSP has no new violations.
- Payment proofs are protected from unauthorized access.
- Changed PHP files pass syntax validation.
- The final response identifies changed files, validation results, migration requirements, and deployment steps.
