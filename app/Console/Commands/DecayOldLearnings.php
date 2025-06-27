<?php

namespace App\Console\Commands;

use App\Services\CategoryLearningService;
use Illuminate\Console\Command;

class DecayOldLearnings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'learning:decay {--days=90 : Number of days after which to start decaying}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decay confidence weights for old learning entries';

    /**
     * Execute the console command.
     */
    public function handle(CategoryLearningService $learningService)
    {
        $days = $this->option('days');

        $this->info("Decaying learning entries older than {$days} days...");

        $learningService->decayOldLearnings($days);

        $this->info('✅ Learning decay completed!');
    }
}
