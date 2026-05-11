<?php

declare(strict_types=1);

namespace Atwx\ISR\Job;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Cache\ISRCacheEntry;
use Atwx\ISR\Middleware\ISRMiddleware;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

if (!class_exists(AbstractQueuedJob::class)) {
    return;
}

class ISRRevalidateJob extends AbstractQueuedJob
{
    public function __construct(string $url = '', string $cacheKey = '')
    {
        if ($url !== '') {
            $this->url = $url;
            $this->cacheKey = $cacheKey;
            $this->totalSteps = 1;
        }
    }

    public function getTitle(): string
    {
        return 'ISR Revalidate ' . ($this->url ?? '');
    }

    public function getJobType(): int
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $cache = Injector::inst()->get(ISRCache::class);
        try {
            $path = parse_url((string)$this->url, PHP_URL_PATH) ?? '/';
            $query = parse_url((string)$this->url, PHP_URL_QUERY);
            $target = $path . ($query ? '?' . $query : '');
            ISRMiddleware::resetTagCollector();
            $response = Director::test($target);
            if (!$response instanceof HTTPResponse) {
                return;
            }
            $ttl = (int)($response->getHeader('X-ISR-TTL') ?: ISRMiddleware::config()->get('default_ttl'));
            if ($response->getHeader('X-ISR-TTL')) {
                $response->removeHeader('X-ISR-TTL');
            }
            $tags = ISRMiddleware::tagCollector()->all();
            $entry = new ISRCacheEntry(
                body: (string)$response->getBody(),
                headers: $this->collectHeaders($response),
                statusCode: (int)$response->getStatusCode(),
                createdAt: time(),
                ttl: $ttl,
                tags: $tags,
            );
            $cache->set((string)$this->cacheKey, $entry, $tags);
        } catch (\Throwable $e) {
            error_log('[ISR] Job revalidate failed: ' . $e->getMessage());
        } finally {
            $cache->unlock((string)$this->cacheKey);
            $this->currentStep = 1;
            $this->isComplete = true;
        }
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
}
