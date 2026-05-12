<?php

declare(strict_types=1);

namespace Atwx\ISR\Middleware;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Cache\ISRCacheEntry;
use Atwx\ISR\Job\ISRRevalidateJob;
use Atwx\ISR\Strategy\CacheKeyResolver;
use Atwx\ISR\Strategy\TagCollector;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;

class ISRMiddleware implements HTTPMiddleware
{
    use Configurable;

    /** @config */
    private static int $default_ttl = 300;

    /** @config */
    private static int $stale_grace = 3600;

    /** @config */
    private static int $hard_max_age = 86400;

    /** @config */
    private static array $cacheable_methods = ['GET', 'HEAD'];

    /** @config */
    private static array $cacheable_status_codes = [200, 404];

    /** @config */
    private static array $bypass_cookies = ['PHPSESSID', 'login_session', 'bypass-cache'];

    /** @config */
    private static array $bypass_query_params = ['flush', 'stage'];

    /** @config */
    private static array $excluded_paths = ['/admin', '/Security', '/dev', '/api'];

    /** @config */
    private static string $revalidation_mode = 'auto';

    /** @config */
    private static int $lock_ttl = 30;

    private static ?TagCollector $collector = null;

    public function __construct(
        private readonly ISRCache $cache,
        private readonly CacheKeyResolver $keyResolver,
    ) {
    }

    public static function tagCollector(): TagCollector
    {
        if (self::$collector === null) {
            self::$collector = new TagCollector();
        }
        return self::$collector;
    }

    public static function resetTagCollector(): void
    {
        self::$collector = new TagCollector();
    }

    public function process(HTTPRequest $request, callable $delegate)
    {
        if (!$this->isCacheableRequest($request)) {
            return $delegate($request);
        }

        $key = $this->keyResolver->keyFor($request);
        $now = time();
        $isInternal = $request->getHeader('X-ISR-Internal') === '1';

        if (!$isInternal) {
            $entry = $this->cache->get($key);
            if ($entry !== null && !$entry->isExpired((int)static::config()->get('hard_max_age'), $now)) {
                if (!$entry->isStale($now)) {
                    return $this->respondFromCache($entry, 'HIT', $now);
                }

                $graceLimit = $entry->createdAt + $entry->ttl + (int)static::config()->get('stale_grace');
                if ($now <= $graceLimit) {
                    $this->scheduleRevalidate($request, $key);
                    return $this->respondFromCache($entry, 'STALE', $now);
                }
            }
        }

        self::resetTagCollector();
        $response = $delegate($request);
        if ($response instanceof HTTPResponse) {
            $this->storeIfCacheable($request, $response, $key);
            $response->addHeader('X-ISR-Cache', $isInternal ? 'REVALIDATE' : 'MISS');
        }
        return $response;
    }

    private function isCacheableRequest(HTTPRequest $request): bool
    {
        $config = static::config();
        $method = strtoupper((string)$request->httpMethod());
        if (!in_array($method, (array)$config->get('cacheable_methods'), true)) {
            return false;
        }

        $url = '/' . ltrim($request->getURL(false), '/');
        foreach ((array)$config->get('excluded_paths') as $prefix) {
            if (str_starts_with($url, (string)$prefix)) {
                return false;
            }
        }

        $cookies = $_COOKIE ?? [];
        foreach ((array)$config->get('bypass_cookies') as $cookie) {
            if (array_key_exists($cookie, $cookies)) {
                return false;
            }
        }

        $vars = $request->getVars();
        foreach ((array)$config->get('bypass_query_params') as $param) {
            if (array_key_exists($param, $vars)) {
                return false;
            }
        }

        return true;
    }

    private function isCacheableResponse(HTTPResponse $response): bool
    {
        $codes = (array)static::config()->get('cacheable_status_codes');
        if (!in_array($response->getStatusCode(), array_map('intval', $codes), true)) {
            return false;
        }
        // Cache-Control no-store/private is a hint for downstream HTTP caches/browsers.
        // ISR is a server-side cache layer the operator opted into, so we ignore it here.
        // Set-Cookie however MUST bypass: caching would leak the cookie across users.
        if ($response->getHeader('Set-Cookie')) {
            return false;
        }
        if ($response->getHeader('X-ISR-Bypass')) {
            $response->removeHeader('X-ISR-Bypass');
            return false;
        }
        $body = (string)$response->getBody();
        if (str_contains($body, 'name="SecurityID"')) {
            error_log('[ISR] Skipping cache: response contains SecurityID form token');
            return false;
        }
        return true;
    }

    private function storeIfCacheable(HTTPRequest $request, HTTPResponse $response, string $key): void
    {
        if (!$this->isCacheableResponse($response)) {
            return;
        }
        $ttl = $this->resolveTTL($request, $response);
        if ($ttl < 0) {
            return;
        }
        $tags = array_values(array_unique(array_merge(
            $this->keyResolver->tagsFor($request),
            self::tagCollector()->all(),
        )));
        $entry = new ISRCacheEntry(
            body: (string)$response->getBody(),
            headers: $this->collectHeaders($response),
            statusCode: (int)$response->getStatusCode(),
            createdAt: time(),
            ttl: $ttl,
            tags: $tags,
        );
        $this->cache->set($key, $entry, $tags);
    }

    private function resolveTTL(HTTPRequest $request, HTTPResponse $response): int
    {
        $default = (int)static::config()->get('default_ttl');
        $pageTtl = $request->getSession() ? null : null;
        $controller = method_exists($response, 'getController') ? null : null;

        $hint = $response->getHeader('X-ISR-TTL');
        if ($hint !== null && $hint !== '') {
            $value = (int)$hint;
            $response->removeHeader('X-ISR-TTL');
            return $value;
        }
        return $default;
    }

    private function collectHeaders(HTTPResponse $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $value) {
            if (in_array(strtolower((string)$name), ['set-cookie', 'x-isr-cache', 'x-isr-age'], true)) {
                continue;
            }
            $headers[(string)$name] = $value;
        }
        return $headers;
    }

    private function respondFromCache(ISRCacheEntry $entry, string $state, int $now): HTTPResponse
    {
        $response = HTTPResponse::create($entry->body, $entry->statusCode);
        foreach ($entry->headers as $name => $value) {
            $response->addHeader((string)$name, (string)$value);
        }
        $response->addHeader('X-ISR-Cache', $state);
        $response->addHeader('X-ISR-Age', (string)$entry->age($now));
        return $response;
    }

    private function scheduleRevalidate(HTTPRequest $request, string $key): void
    {
        if (!$this->cache->lock($key, (int)static::config()->get('lock_ttl'))) {
            return;
        }
        $mode = (string)static::config()->get('revalidation_mode');
        $absoluteUrl = Director::absoluteURL((string)$request->getURL(true));

        if ($mode === 'queue' || (!$this->fpmAvailable() && $mode !== 'shutdown')) {
            $this->enqueueJob($absoluteUrl, $key);
            return;
        }

        if ($this->fpmAvailable()) {
            register_shutdown_function(function () use ($absoluteUrl, $key) {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                $this->revalidateNow($absoluteUrl, $key);
            });
            return;
        }

        $this->enqueueJob($absoluteUrl, $key);
    }

    private function fpmAvailable(): bool
    {
        return function_exists('fastcgi_finish_request');
    }

    private function enqueueJob(string $url, string $key): void
    {
        if (!class_exists(\Symbiote\QueuedJobs\Services\QueuedJobService::class)) {
            $this->cache->unlock($key);
            return;
        }
        $job = new ISRRevalidateJob($url, $key);
        Injector::inst()->get(\Symbiote\QueuedJobs\Services\QueuedJobService::class)
            ->queueJob($job);
    }

    /**
     * Fire an internal HTTP request via cURL so the full request pipeline (incl. SessionMiddleware
     * and our own ISRMiddleware-store path) handles the response. The internal request is marked
     * with X-ISR-Internal so it skips the cache lookup but still writes the rendered response back.
     */
    private function revalidateNow(string $url, string $key): void
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER => [
                    'X-ISR-Internal: 1',
                    'User-Agent: ISR-Revalidate/1.0',
                ],
            ]);
            $ok = @curl_exec($ch);
            if ($ok === false) {
                error_log('[ISR] Revalidate curl error: ' . curl_error($ch));
            }
            curl_close($ch);
        } catch (\Throwable $e) {
            error_log('[ISR] Revalidate failed: ' . $e->getMessage());
        } finally {
            $this->cache->unlock($key);
        }
    }
}
