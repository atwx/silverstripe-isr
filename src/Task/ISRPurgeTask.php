<?php

declare(strict_types=1);

namespace Atwx\ISR\Task;

use Atwx\ISR\Cache\ISRCache;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;

class ISRPurgeTask extends BuildTask
{
    protected static string $commandName = 'isr-purge';

    protected string $title = 'ISR: Purge complete cache';

    protected static string $description = 'Clears the entire ISR cache store.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $cache = Injector::inst()->get(ISRCache::class);
        $cache->clear();
        $output->writeln('ISR cache cleared.');
        return 0;
    }
}
