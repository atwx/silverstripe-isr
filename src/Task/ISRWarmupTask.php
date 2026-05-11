<?php

declare(strict_types=1);

namespace Atwx\ISR\Task;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;

class ISRWarmupTask extends BuildTask
{
    protected static string $commandName = 'isr-warmup';

    protected string $title = 'ISR: Warm up cache by hitting published pages';

    protected static string $description = 'Iterates published SiteTree URLs and renders them via Director::test so the ISR middleware fills the cache.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $count = 0;
        foreach (SiteTree::get() as $page) {
            if (!$page->isPublished()) {
                continue;
            }
            $link = (string)$page->Link();
            if ($link === '') {
                continue;
            }
            $output->writeln(' - ' . $link);
            Director::test($link);
            $count++;
        }
        $output->writeln(sprintf('Warmed %d pages.', $count));
        return 0;
    }
}
