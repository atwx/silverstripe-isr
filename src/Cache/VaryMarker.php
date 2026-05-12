<?php

declare(strict_types=1);

namespace Atwx\ISR\Cache;

/**
 * Placeholder stored at the bare cache key whenever a response declared a `Vary` header.
 * On lookup the middleware sees this marker, computes the variant key from the listed
 * request headers, and fetches the actual ISRCacheEntry from there.
 */
final class VaryMarker
{
    public function __construct(
        /** @var string[] normalised, lowercase, sorted header names */
        public readonly array $headers,
        public readonly int $createdAt,
        public readonly int $ttl,
    ) {
    }
}
