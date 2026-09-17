<?php
/**
 * FILE: includes/ux_helpers.php
 * 
 * Helper functions for using new UX components throughout the application
 * Provides easier ways to use breadcrumbs, alerts, confirmations, etc.
 */

/**
 * Helper: Render breadcrumb navigation for a page
 * Usage: echo breadcrumb(['Home' => '/', 'Players', 'Edit']);
 */
function breadcrumb($crumbs = []) {
    return renderBreadcrumb($crumbs);
}

/**
 * Helper: Add a toast notification to be shown on next page
 * Usage: toastNotify('success', 'Reservation confirmed!');
 */
function toastNotify($type = 'info', $message = '') {
    toastFlash($type, $message);
}

/**
 * Helper: Create an alert box HTML
 * Usage: echo alert('error', 'Something went wrong!');
 */
function alert($type, $message, $dismissible = true) {
    $icons = [
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️'
    ];
    
    $icon = $icons[$type] ?? '📢';
    $closeBtn = $dismissible ? '<button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">✕</button>' : '';
    
    return <<<HTML
<div class="alert alert-{$type}" role="alert">
    <div class="alert-icon">{$icon}</div>
    <div class="alert-content">{$message}</div>
    {$closeBtn}
</div>
HTML;
}

/**
 * Helper: Create empty state HTML
 * Usage: echo emptyState('🏐', 'No reservations', 'Book a court to get started!', '/player/schedule.php', 'Book Now');
 */
function emptyState($icon, $title, $message = '', $actionUrl = null, $actionLabel = 'Get Started') {
    $action = '';
    if ($actionUrl) {
        $action = <<<HTML
<div class="empty-state-action">
    <a href="{$actionUrl}" class="btn btn-primary">{$actionLabel}</a>
</div>
HTML;
    }
    
    return <<<HTML
<div class="empty-state">
    <div class="empty-state-icon">{$icon}</div>
    <h2 class="empty-state-title">{$title}</h2>
    <p class="empty-state-message">{$message}</p>
    {$action}
</div>
HTML;
}

/**
 * Helper: Create a status badge
 * Usage: echo badge('success', 'Active');
 */
function badge($type, $label) {
    return <<<HTML
<span class="badge badge-{$type}">{$label}</span>
HTML;
}

/**
 * Helper: Create a status indicator dot
 * Usage: echo statusIndicator('active');
 */
function statusIndicator($status) {
    return <<<HTML
<span class="status-indicator {$status}"></span>
HTML;
}

/**
 * Helper: Create form validation markup
 * Usage in HTML: <input type="email" data-validate-type="email" />
 * Then validation happens automatically via form-validation.js
 */
function formFieldValidation($type, $minValue = null, $maxValue = null, $matchId = null) {
    $attrs = "data-validate-type=\"{$type}\"";
    if ($minValue !== null) $attrs .= " data-validate-min=\"{$minValue}\"";
    if ($maxValue !== null) $attrs .= " data-validate-max=\"{$maxValue}\"";
    if ($matchId !== null) $attrs .= " data-validate-match=\"{$matchId}\"";
    return $attrs;
}

/**
 * Helper: Create a confirmation button
 * Usage: echo confirmButton('Delete', 'Are you sure?', '/api/delete/123', 'DELETE');
 */
function confirmButton($label, $title, $url, $method = 'POST', $class = 'btn-danger') {
    $jsCall = "openConfirmModal('{$title}', 'This action cannot be undone.', '{$url}', '{$method}')";
    return <<<HTML
<button type="button" class="btn {$class}" onclick="{$jsCall}">
    {$label}
</button>
HTML;
}

/**
 * Helper: Create a help icon with tooltip
 * Usage: echo helpIcon('This helps you do something important');
 */
function helpIcon($tooltip) {
    $tooltip = htmlspecialchars($tooltip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return <<<HTML
<span class="help-icon" data-tooltip="{$tooltip}">?</span>
HTML;
}

/**
 * Helper: Loading spinner
 * Usage: echo spinner();
 */
function spinner() {
    return '<span class="spinner"></span>';
}

/**
 * Helper: Create a form group with validation
 * Usage: formGroup('email', 'Email', 'Email Address', 'email@example.com', 'email', 13, 100);
 */
function formGroup($name, $label, $placeholder = '', $value = '', $validateType = '', $minLen = null, $maxLen = null, $required = true) {
    $reqAttr = $required ? 'required' : '';
    $valAttrs = $validateType ? "data-validate-type=\"{$validateType}\"" : '';
    if ($minLen) $valAttrs .= " data-validate-min=\"{$minLen}\"";
    if ($maxLen) $valAttrs .= " data-validate-max=\"{$maxLen}\"";
    
    $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $req_label = $required ? '<span style="color: #ef4444;">*</span>' : '';
    
    return <<<HTML
<div class="form-group">
    <label for="{$name}" class="form-label">{$label} {$req_label}</label>
    <input type="text" id="{$name}" name="{$name}" placeholder="{$placeholder}" 
           value="{$value}" {$valAttrs} {$reqAttr} />
</div>
HTML;
}

/**
 * Helper: Create loading button with spinner
 * Usage: echo loadingButton('Save', 'btn-primary', 'submit');
 */
function loadingButton($label, $class = 'btn-primary', $type = 'button') {
    return <<<HTML
<button type="{$type}" class="btn {$class}" id="submit-btn">
    {$label}
</button>
<script nonce="<?= getCspNonce() ?>">
document.getElementById('submit-btn').addEventListener('click', function() {
    this.disabled = true;
    this.classList.add('loading');
    this.textContent = 'Processing...';
});
</script>
HTML;
}

/**
 * Helper: Show a server-side error as a styled alert
 * Usage: echo alertError('Email already in use');
 */
function alertError($message) {
    return alert('error', $message, true);
}

/**
 * Helper: Show a server-side success as a styled alert
 * Usage: echo alertSuccess('Saved successfully!');
 */
function alertSuccess($message) {
    return alert('success', $message, true);
}

/**
 * Helper: Show a server-side warning as a styled alert
 * Usage: echo alertWarning('This will expire soon');
 */
function alertWarning($message) {
    return alert('warning', $message, true);
}

/**
 * Helper: Show a server-side info as a styled alert
 * Usage: echo alertInfo('New feature available!');
 */
function alertInfo($message) {
    return alert('info', $message, true);
}
