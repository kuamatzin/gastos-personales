<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessExpenseText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    protected string $userId;
    protected string $text;
    protected int $messageId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $text, int $messageId)
    {
        $this->userId = $userId;
        $this->text = $text;
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // TODO: This will be implemented in Task #5
        // For now, just log the expense
        \Log::info('Processing expense text', [
            'user_id' => $this->userId,
            'text' => $this->text,
            'message_id' => $this->messageId,
        ]);
    }
}
