<?php

declare(strict_types=1);

namespace Atwx\ISR\Strategy;

use SilverStripe\Control\HTTPRequest;

class VaryKey
{
    /**
     * Normalise a Vary header value into a sorted, deduplicated, lowercase list.
     * Drops `*` (caller treats this as bypass) and `cookie` (per design choice — would
     * effectively disable caching since every session cookie would create a new variant).
     *
     * @return string[]
     */
    public static function normalize(string $varyHeader): array
    {
        if ($varyHeader === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', strtolower($varyHeader)));
        $parts = array_filter($parts, fn ($h) => $h !== '' && $h !== 'cookie');
        if (in_array('*', $parts, true)) {
            return ['*'];
        }
        $parts = array_values(array_unique($parts));
        sort($parts);
        return $parts;
    }

    /**
     * Produce a variant key suffix from the normalised header list and the current
     * request's header values. Missing headers fold in as empty strings so two
     * requests that genuinely differ only by presence still collide on the same key.
     *
     * @param string[] $headers
     */
    public static function expand(string $baseKey, array $headers, HTTPRequest $request): string
    {
        $material = [];
        foreach ($headers as $name) {
            $material[$name] = (string)$request->getHeader($name);
        }
        ksort($material);
        $hash = substr(hash('sha256', http_build_query($material)), 0, 16);
        return $baseKey . '__v_' . $hash;
    }
}
