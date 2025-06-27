<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class BaseExpenseProcessor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 120;

    public $backoff = [30, 60, 120]; // Exponential backoff

    protected string $userId;

    protected int $messageId;

    protected ?User $user = null;

    protected ?TelegramService $telegram = null;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return $this->backoff;
    }

    /**
     * Get the user for this expense
     */
    protected function getUser(): User
    {
        if (! $this->user) {
            $this->user = User::where('telegram_id', $this->userId)->first();

            if (! $this->user) {
                throw new \Exception("User not found: {$this->userId}");
            }
        }

        return $this->user;
    }

    /**
     * Get Telegram service instance
     */
    protected function getTelegram(): TelegramService
    {
        if (! $this->telegram) {
            $this->telegram = app(TelegramService::class);
        }

        return $this->telegram;
    }

    /**
     * Store expense context in Redis for later retrieval
     */
    protected function storeContext(array $data): void
    {
        $key = $this->getContextKey();

        Cache::put($key, $data, now()->addHour()); // 1 hour TTL

        Log::info('Stored expense context', [
            'key' => $key,
            'user_id' => $this->userId,
            'message_id' => $this->messageId,
        ]);
    }

    /**
     * Retrieve expense context from Redis
     */
    protected function getContext(): ?array
    {
        $key = $this->getContextKey();

        return Cache::get($key);
    }

    /**
     * Clear expense context from Redis
     */
    protected function clearContext(): void
    {
        Cache::forget($this->getContextKey());
    }

    /**
     * Get Redis key for context storage
     */
    protected function getContextKey(): string
    {
        return "expense_context:{$this->userId}:{$this->messageId}";
    }

    /**
     * Send error message to user
     */
    protected function sendErrorMessage(string $message): void
    {
        try {
            $this->getTelegram()->sendMessage(
                $this->userId,
                "❌ {$message}\n\nPlease try again or contact support if the issue persists."
            );
        } catch (\Exception $e) {
            Log::error('Failed to send error message to user', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log job processing start
     */
    protected function logStart(string $type, array $context = []): void
    {
        Log::info("Processing {$type} expense", array_merge([
            'user_id' => $this->userId,
            'message_id' => $this->messageId,
            'attempt' => $this->attempts(),
        ], $context));
    }

    /**
     * Log job processing completion
     */
    protected function logComplete(string $type, array $context = []): void
    {
        Log::info("{$type} expense processed successfully", array_merge([
            'user_id' => $this->userId,
            'message_id' => $this->messageId,
            'processing_time' => $this->getProcessingTime(),
        ], $context));
    }

    /**
     * Get processing time in seconds
     */
    protected function getProcessingTime(): float
    {
        if (property_exists($this, 'job') && $this->job) {
            $payload = $this->job->payload();
            if (isset($payload['pushedAt'])) {
                return round(microtime(true) - $payload['pushedAt'], 2);
            }
        }

        return 0;
    }

    /**
     * Handle a job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Expense processing job failed', [
            'user_id' => $this->userId,
            'message_id' => $this->messageId,
            'job_class' => get_class($this),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Clear any stored context
        $this->clearContext();

        // Notify user of failure
        $this->sendErrorMessage(
            "Sorry, I couldn't process your expense. ".$this->getFailureMessage()
        );
    }

    /**
     * Get user-friendly failure message
     */
    abstract protected function getFailureMessage(): string;

    /**
     * Download file from Telegram
     */
    protected function downloadTelegramFile(string $fileId): ?string
    {
        $telegram = $this->getTelegram();

        // Get file info
        $fileInfo = $telegram->getFile($fileId);
        if (! $fileInfo) {
            throw new \Exception('Failed to get file info from Telegram');
        }

        // Download file content
        $fileContent = $telegram->downloadFile($fileInfo['file_path']);
        if (! $fileContent) {
            throw new \Exception('Failed to download file from Telegram');
        }

        // Save to temporary file
        $tempDir = storage_path('app/telegram');
        if (! file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $extension = pathinfo($fileInfo['file_path'], PATHINFO_EXTENSION);
        $tempFile = $tempDir.'/'.uniqid('telegram_').'.'.$extension;

        file_put_contents($tempFile, $fileContent);

        return $tempFile;
    }

    /**
     * Clean up temporary file
     */
    protected function cleanupFile(?string $filePath): void
    {
        if ($filePath && file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
