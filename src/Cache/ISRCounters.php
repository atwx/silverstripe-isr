<?php

declare(strict_types=1);

namespace Atwx\ISR\Cache;

use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Hourly hit/miss/stale/revalidate counters stored next to the ISR cache.
 *
 * Counts are read-modify-write on top of Symfony's cache adapter — not atomic.
 * Under high concurrency some increments may be lost. This is a soft observability
 * signal, not billing data. Operators who want exact counters can subclass and
 * override increment() to use Redis HINCRBY directly.
 */
class ISRCounters
{
    public const STATES = ['HIT', 'STALE', 'MISS', 'REVALIDATE'];

    private const TTL_SECONDS = 86400 * 7;

    private readonly TagAwareAdapterInterface $backend;

    public function __construct(ISRCache|TagAwareAdapterInterface $backend)
    {
        $this->backend = $backend instanceof ISRCache ? $backend->getBackend() : $backend;
    }

    public function increment(string $state): void
    {
        $state = strtoupper($state);
        if (!in_array($state, self::STATES, true)) {
            return;
        }
        $bucket = gmdate('YmdH');
        $key = 'isr_counters_' . $bucket . '_' . $state;

        $item = $this->backend->getItem($key);
        $current = (int)$item->get();
        $item->set($current + 1);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->backend->save($item);
    }

    /**
     * @return array<string, array<string,int>>  bucket (YmdH UTC) => [state => count]
     */
    public function read(int $hours = 24): array
    {
        $now = time();
        $out = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $bucket = gmdate('YmdH', $now - $i * 3600);
            $row = [];
            foreach (self::STATES as $state) {
                $item = $this->backend->getItem('isr_counters_' . $bucket . '_' . $state);
                $row[$state] = $item->isHit() ? (int)$item->get() : 0;
            }
            $out[$bucket] = $row;
        }
        return $out;
    }

    /**
     * @return array<string,int>  state => total across the window
     */
    public function totals(int $hours = 24): array
    {
        $rows = $this->read($hours);
        $sum = array_fill_keys(self::STATES, 0);
        foreach ($rows as $row) {
            foreach (self::STATES as $state) {
                $sum[$state] += $row[$state];
            }
        }
        return $sum;
    }
}
