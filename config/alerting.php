<?php
// ============================================================
//  FILE: config/alerting.php
//
//  Pings ALERT_WEBHOOK_URL (Slack/Discord/Teams/custom — anything
//  accepting a POST of {"text": "..."}) when something serious
//  breaks, so you find out from an alert instead of a customer.
//
//  Rate-limited per error signature (file+line+message) so one
//  broken booking flow throwing on every request sends one alert
//  per window, not one per request.
// ============================================================

function alertOps(string $message, string $signature): void
{
    $webhook = getenv('ALERT_WEBHOOK_URL');
    if (!$webhook) return; // alerting not configured — errors still hit the log file

    // De-dupe: skip if we've already alerted on this exact signature recently.
    $cacheDir = (defined('APP_ROOT') ? APP_ROOT : __DIR__ . '/..') . '/storage/logs/.alert_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $markerFile = $cacheDir . '/' . hash('sha256', $signature) . '.marker';
    $windowSeconds = (int)(getenv('ALERT_DEDUPE_WINDOW_SECONDS') ?: 600); // 10 min default

    if (is_file($markerFile) && (time() - filemtime($markerFile)) < $windowSeconds) {
        return; // already alerted recently for this exact error
    }
    @touch($markerFile);

    $env = defined('RAILWAY_ENVIRONMENT') ? RAILWAY_ENVIRONMENT : (getenv('RAILWAY_ENVIRONMENT') ?: 'unknown');
    $payload = json_encode([
        'text' => "🔴 [{$env}] Falcon error: {$message}",
    ]);

    // Fire-and-forget: short timeout, and a failure here must never break
    // the actual request the user is waiting on.
    try {
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        // swallow — alerting must never be the thing that takes the site down
    }
}
