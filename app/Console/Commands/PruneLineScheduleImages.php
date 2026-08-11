<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;

class PruneLineScheduleImages extends Command
{
    protected $signature = 'line:schedule-images-prune {--dry-run : List expired images without deleting them}';

    protected $description = 'Delete LINE schedule images from S3 after their retention period';

    public function handle(): int
    {
        $diskName = (string) config('services.line.schedule_image_disk', 's3');

        if ($diskName !== 's3') {
            $this->error('Refusing to prune: LINE schedule images are not configured to use the s3 disk.');

            return CommandAlias::FAILURE;
        }

        $retentionDays = max(1, (int) config('services.line.schedule_image_retention_days', 7));
        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $disk = Storage::disk($diskName);
        $expired = [];

        foreach ($disk->allFiles('line-schedules') as $path) {
            if ($disk->lastModified($path) <= $cutoff) {
                $expired[] = $path;
            }
        }

        if ($this->option('dry-run')) {
            foreach ($expired as $path) {
                $this->line($path);
            }
        } else {
            foreach (array_chunk($expired, 1000) as $paths) {
                if (! $disk->delete($paths)) {
                    throw new RuntimeException('Unable to delete one or more expired LINE schedule images.');
                }
            }
        }

        $action = $this->option('dry-run') ? 'found' : 'deleted';
        $this->info(sprintf('%d expired LINE schedule image(s) %s.', count($expired), $action));

        return CommandAlias::SUCCESS;
    }
}
