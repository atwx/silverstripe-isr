<?php

declare(strict_types=1);

namespace Atwx\ISR\Task;

use Atwx\ISR\Cache\ISRCounters;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;

class ISRStatsTask extends BuildTask
{
    protected static string $commandName = 'isr-stats';

    protected string $title = 'ISR: Cache statistics';

    protected static string $description = 'Shows on-disk cache size and a per-state hit/miss/stale/revalidate breakdown for the last 24 hours.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->printDiskStats($output);
        $output->writeln('');
        $this->printCounters($output);
        return 0;
    }

    private function printDiskStats(PolyOutput $output): void
    {
        $dir = defined('TEMP_FOLDER') ? TEMP_FOLDER . '/isr' : sys_get_temp_dir() . '/isr';
        if (!is_dir($dir)) {
            $output->writeln('No ISR cache directory found at ' . $dir);
            return;
        }
        $bytes = 0;
        $files = 0;
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
                $files++;
            }
        }
        $output->writeln(sprintf('ISR cache on disk: %d files, %s bytes', $files, number_format($bytes)));
    }

    private function printCounters(PolyOutput $output): void
    {
        $counters = Injector::inst()->get(ISRCounters::class);
        $totals = $counters->totals(24);
        $rows = $counters->read(24);

        $output->writeln('Per-state totals (last 24h, UTC hour buckets):');
        $output->writeln('  Note: counters use read-modify-write on the cache adapter and are approximate under high concurrency.');
        $output->writeln('');

        $sumLine = [];
        foreach (ISRCounters::STATES as $state) {
            $sumLine[] = sprintf('%-10s %6d', $state, $totals[$state]);
        }
        $output->writeln('  ' . implode('   ', $sumLine));
        $output->writeln('');

        $hasAny = array_sum($totals) > 0;
        if (!$hasAny) {
            $output->writeln('  (no counter data yet — generate some traffic and re-run.)');
            return;
        }

        $output->writeln(sprintf('  %-14s %8s %8s %8s %12s', 'bucket', 'HIT', 'STALE', 'MISS', 'REVALIDATE'));
        foreach ($rows as $bucket => $row) {
            if (array_sum($row) === 0) {
                continue;
            }
            $output->writeln(sprintf(
                '  %-14s %8d %8d %8d %12d',
                $bucket,
                $row['HIT'],
                $row['STALE'],
                $row['MISS'],
                $row['REVALIDATE'],
            ));
        }
    }
}
