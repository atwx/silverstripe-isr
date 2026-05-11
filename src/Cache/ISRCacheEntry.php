<?php

declare(strict_types=1);

namespace Atwx\ISR\Cache;

final class ISRCacheEntry
{
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
        public readonly int $statusCode,
        public readonly int $createdAt,
        public readonly int $ttl,
        public readonly array $tags = [],
    ) {
    }

    public function age(int $now = 0): int
    {
        return max(0, ($now ?: time()) - $this->createdAt);
    }

    public function isStale(int $now = 0): bool
    {
        return $this->age($now) > $this->ttl;
    }

    public function isExpired(int $hardMaxAge, int $now = 0): bool
    {
        return $this->age($now) > $hardMaxAge;
    }
}
