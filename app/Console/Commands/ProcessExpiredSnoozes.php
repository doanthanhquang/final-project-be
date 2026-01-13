<?php

namespace App\Console\Commands;

use App\Services\Workflow\SnoozeService;
use Illuminate\Console\Command;

class ProcessExpiredSnoozes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snooze:process-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process expired snoozed emails and restore them to their original columns';

    /**
     * Execute the console command.
     */
    public function handle(SnoozeService $snoozeService): int
    {
        $this->info('Processing expired snoozes...');

        try {
            $count = $snoozeService->processExpiredSnoozes();

            if ($count > 0) {
                $this->info("Restored {$count} expired snoozed email(s)");
            } else {
                $this->info('No expired snoozes to process');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to process expired snoozes: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
