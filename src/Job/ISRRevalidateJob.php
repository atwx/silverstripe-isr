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
            $ch = curl_init((string)$this->url);
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
                error_log('[ISR] Job revalidate curl error: ' . curl_error($ch));
            }
            curl_close($ch);
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
