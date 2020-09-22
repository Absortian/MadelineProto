<?php

namespace danog\MadelineProto\Db;

use Amp\Loop;
use danog\MadelineProto\Logger;

trait ArrayCacheTrait
{
    /**
     * @var array<mixed>
     */
    protected array $cache = [];
    /**
     * @var array<int>
     */
    protected array $ttlValues = [];

    /**
     * TTL interval.
     */
    protected int $ttl = 5 * 60;
    /**
     * TTL cleanup interval.
     */
    private int $ttlCheckInterval = 60;

    /**
     * Cache cleanup watcher ID.
     */
    private ?string $cacheCleanupId = null;

    protected function getCache(string $key, $default = null)
    {
        if (!isset($this->ttlValues[$key])) {
            return $default;
        }
        $this->ttlValues[$key] = \strtotime($this->ttl);
        return $this->cache[$key];
    }

    /**
     * Save item in cache.
     *
     * @param string $key
     * @param $value
     */
    protected function setCache(string $key, $value): void
    {
        $this->cache[$key] = $value;
        $this->ttlValues[$key] = \strtotime($this->ttl);
    }

    /**
     * Remove key from cache.
     *
     * @param string $key
     */
    protected function unsetCache(string $key): void
    {
        unset($this->cache[$key], $this->ttlValues[$key]);
    }

    protected function startCacheCleanupLoop(): void
    {
        $this->cacheCleanupId = Loop::repeat($this->ttlCheckInterval * 1000, fn () => $this->cleanupCache());
    }
    protected function stopCacheCleanupLoop(): void
    {
        if ($this->cacheCleanupId) {
            Loop::cancel($this->cacheCleanupId);
            $this->cacheCleanupId = null;
        }
    }

    /**
     * Remove all keys from cache.
     */
    protected function cleanupCache(): void
    {
        $now = \time();
        $oldCount = 0;
        foreach ($this->ttlValues as $cacheKey => $ttl) {
            if ($ttl < $now) {
                $this->unsetCache($cacheKey);
                $oldCount++;
            }
        }

        Logger::log(
            \sprintf(
                "cache for table:%s; keys left: %s; keys removed: %s",
                $this->table,
                \count($this->cache),
                $oldCount
            ),
            Logger::VERBOSE
        );
    }
}
