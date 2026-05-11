<?php

declare(strict_types=1);

namespace Atwx\ISR\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;

class ISRStatsTask extends BuildTask
{
    protected static string $commandName = 'isr-stats';

    protected string $title = 'ISR: Cache statistics';

    protected static string $description = 'Shows the size of the filesystem ISR cache (only meaningful with the filesystem backend).';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $dir = defined('TEMP_FOLDER') ? TEMP_FOLDER . '/isr' : sys_get_temp_dir() . '/isr';
        if (!is_dir($dir)) {
            $output->writeln('No ISR cache directory found at ' . $dir);
            return 0;
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
        $output->writeln(sprintf('ISR cache: %d files, %s bytes', $files, number_format($bytes)));
        return 0;
    }
}
