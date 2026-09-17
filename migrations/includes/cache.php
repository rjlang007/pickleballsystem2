<?php
// ============================================================
//  FILE: includes/cache.php
//
//  Redis-based caching helpers for performance optimization.
//
//  Usage:
//    $cache = getCache();
//    $cache->set('key', 'value', 300); // 5 min TTL
//    $value = $cache->get('key');
// ============================================================
require_once __DIR__ . '/../config/db.php';

class Cache {
    private $redis;
    private $prefix = 'falcon:';

    public function __construct() {
        $this->redis = getRedis();
    }

    public function isAvailable(): bool {
        return $this->redis !== null;
    }

    public function get(string $key) {
        if (!$this->isAvailable()) return null;
        $value = $this->redis->get($this->prefix . $key);
        return $value ? json_decode($value, true) : null;
    }

    public function set(string $key, $value, int $ttl = 300): bool {
        if (!$this->isAvailable()) return false;
        return $this->redis->setex($this->prefix . $key, $ttl, json_encode($value));
    }

    public function delete(string $key): bool {
        if (!$this->isAvailable()) return false;
        return $this->redis->del($this->prefix . $key) > 0;
    }

    public function clear(): bool {
        if (!$this->isAvailable()) return false;
        $keys = $this->redis->keys($this->prefix . '*');
        if (empty($keys)) return true;
        return $this->redis->del($keys) > 0;
    }

    // Court-specific cache keys
    public function getCourtStatusKey(int $courtId): string {
        return "court_status:{$courtId}";
    }

    public function getQueueStatusKey(int $courtId): string {
        return "queue_status:{$courtId}";
    }

    public function getUserBalanceKey(int $userId): string {
        return "user_balance:{$userId}";
    }

    // Cache court status
    public function cacheCourtStatus(int $courtId, array $status, int $ttl = 60): bool {
        return $this->set($this->getCourtStatusKey($courtId), $status, $ttl);
    }

    public function getCachedCourtStatus(int $courtId) {
        return $this->get($this->getCourtStatusKey($courtId));
    }

    // Cache queue status
    public function cacheQueueStatus(int $courtId, array $queue, int $ttl = 30): bool {
        return $this->set($this->getQueueStatusKey($courtId), $queue, $ttl);
    }

    public function getCachedQueueStatus(int $courtId) {
        return $this->get($this->getQueueStatusKey($courtId));
    }

    // Cache user balance
    public function cacheUserBalance(int $userId, float $balance, int $ttl = 300): bool {
        return $this->set($this->getUserBalanceKey($userId), $balance, $ttl);
    }

    public function getCachedUserBalance(int $userId) {
        return $this->get($this->getUserBalanceKey($userId));
    }

    public function invalidateUserBalance(int $userId): bool {
        return $this->delete($this->getUserBalanceKey($userId));
    }
}

function getCache(): Cache {
    static $cache = null;
    if ($cache === null) {
        $cache = new Cache();
    }
    return $cache;
}