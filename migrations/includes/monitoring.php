<?php
// ============================================================
//  FILE: includes/monitoring.php
//
//  Monitoring and alerting functions.
// ============================================================
require_once __DIR__ . '/../config/db.php';

class Monitoring {
    private static $logFile = __DIR__ . '/../logs/monitoring.log';

    public static function logEvent(string $event, array $data = [], string $level = 'info'): void {
        $timestamp = date('Y-m-d H:i:s');
        $message = json_encode([
            'timestamp' => $timestamp,
            'level' => $level,
            'event' => $event,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);

        file_put_contents(self::$logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Alert on critical events
        if ($level === 'critical') {
            self::sendAlert($event, $data);
        }
    }

    public static function sendAlert(string $event, array $data): void {
        // Send email alert (placeholder - integrate with email service)
        $subject = "CRITICAL: Padol System Alert - {$event}";
        $body = "Event: {$event}\nData: " . json_encode($data, JSON_PRETTY_PRINT);

        // mail(getenv('ALERT_EMAIL'), $subject, $body);

        // Log the alert
        error_log("[ALERT] {$subject}: {$body}");
    }

    public static function trackPerformance(string $operation, float $duration, array $metadata = []): void {
        if ($duration > 5.0) { // Log slow operations
            self::logEvent('slow_operation', [
                'operation' => $operation,
                'duration' => $duration,
                'metadata' => $metadata,
            ], 'warning');
        }

        // Store in database for analysis
        try {
            $db = getDB();
            $stmt = $db->prepare("
                INSERT INTO falcon.performance_logs
                    (operation, duration, metadata, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$operation, $duration, json_encode($metadata)]);
        } catch (Exception $e) {
            error_log('[Monitoring] Failed to log performance: ' . $e->getMessage());
        }
    }

    public static function getSystemMetrics(): array {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_load' => sys_getloadavg()[0] ?? null,
            'disk_free' => disk_free_space('/'),
            'disk_total' => disk_total_space('/'),
            'uptime' => time() - filemtime('/proc/uptime') ?? 0,
        ];
    }

    public static function checkSystemHealth(): array {
        $issues = [];

        // Check database connections
        try {
            $db = getDB();
            $stmt = $db->query('SELECT COUNT(*) FROM falcon.users');
            $userCount = $stmt->fetchColumn();
            if ($userCount < 1) {
                $issues[] = 'No users found in database';
            }
        } catch (Exception $e) {
            $issues[] = 'Database connection failed: ' . $e->getMessage();
        }

        // Check disk space
        $diskFree = disk_free_space('/');
        $diskTotal = disk_total_space('/');
        $freePercent = ($diskFree / $diskTotal) * 100;
        if ($freePercent < 10) {
            $issues[] = 'Low disk space: ' . round($freePercent, 1) . '% free';
        }

        // Check memory
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit && $memoryUsage > 0.8 * self::parseSize($memoryLimit)) {
            $issues[] = 'High memory usage';
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => self::getSystemMetrics(),
        ];
    }

    private static function parseSize(string $size): int {
        $unit = strtolower(substr($size, -1));
        $value = (int)$size;

        switch ($unit) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default: return $value;
        }
    }
}

// Helper function for timing operations
function startTiming(): float {
    return microtime(true);
}

function endTiming(float $start, string $operation, array $metadata = []): void {
    $duration = microtime(true) - $start;
    Monitoring::trackPerformance($operation, $duration, $metadata);
}