<?php

declare(strict_types=1);

namespace Atwx\ISR\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fires the X-ISR-Internal HTTP request used by background revalidation. Sits behind a
 * single chokepoint so the middleware and the QueuedJob fallback share one HTTP path.
 *
 * Uses Guzzle (transitive dependency of silverstripe/framework) rather than raw libcurl
 * so timeouts, retries, and TLS handling go through a maintained client.
 */
class InternalHttpClient
{
    public const HEADER = 'X-ISR-Internal';

    /**
     * Returns true on a successful response (any status, including 4xx/5xx — only network
     * failures count as failures here, since storing 4xx is allowed by config).
     */
    public static function fetch(string $url, ?LoggerInterface $logger = null): bool
    {
        $logger ??= new NullLogger();
        try {
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 5,
                'verify' => false,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
            $client->request('GET', $url, [
                'headers' => [
                    self::HEADER => '1',
                    'User-Agent' => 'ISR-Revalidate/1.0',
                ],
            ]);
            return true;
        } catch (GuzzleException $e) {
            $logger->warning('Revalidation HTTP error', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            return false;
        }
    }
}
