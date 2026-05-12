<?php

declare(strict_types=1);

namespace Atwx\ISR\Job;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Http\InternalHttpClient;
use Psr\Log\LoggerInterface;
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
        $logger = Injector::inst()->get(LoggerInterface::class . '.isr');
        try {
            InternalHttpClient::fetch((string)$this->url, $logger);
        } catch (\Throwable $e) {
            $logger->warning('Job revalidation failed', ['exception' => $e]);
        } finally {
            $cache->unlock((string)$this->cacheKey);
            $this->currentStep = 1;
            $this->isComplete = true;
        }
    }
}
