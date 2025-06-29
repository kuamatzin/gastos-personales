<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ExportService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateExpenseExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public string $format,
        public Carbon $startDate,
        public Carbon $endDate
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(ExportService $exportService, TelegramService $telegramService): void
    {
        try {
            Log::info('Generating expense export', [
                'user_id' => $this->user->id,
                'format' => $this->format,
                'start' => $this->startDate->toDateString(),
                'end' => $this->endDate->toDateString(),
            ]);

            // Generate the export file
            $filePath = $exportService->generateExport(
                $this->user,
                $this->format,
                $this->startDate,
                $this->endDate
            );

            if (!$filePath || !Storage::exists($filePath)) {
                throw new \Exception('Export file generation failed');
            }

            // Send the file via Telegram
            $telegramService->sendDocument(
                $this->user->telegram_id,
                Storage::path($filePath),
                $this->getFileCaption()
            );

            // Clean up the file after sending
            Storage::delete($filePath);

            Log::info('Export sent successfully', [
                'user_id' => $this->user->id,
                'file' => $filePath,
            ]);
        } catch (\Exception $e) {
            Log::error('Export generation failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Notify user of the error
            $telegramService->sendMessage(
                $this->user->telegram_id,
                trans('telegram.export_failed', [], $this->user->language ?? 'es')
            );
        }
    }

    /**
     * Get caption for the exported file
     */
    private function getFileCaption(): string
    {
        $periodName = $this->getPeriodName();
        
        return trans('telegram.export_file_caption', [
            'format' => strtoupper($this->format),
            'period' => $periodName,
            'count' => $this->getExpenseCount(),
            'total' => number_format($this->getTotalAmount(), 2),
            'generated_at' => now($this->user->getTimezone())->format('d/m/Y H:i')
        ], $this->user->language ?? 'es');
    }

    /**
     * Get period name for the export
     */
    private function getPeriodName(): string
    {
        if ($this->startDate->isSameDay($this->endDate)) {
            return $this->startDate->format('d/m/Y');
        }
        
        if ($this->startDate->isSameMonth($this->endDate)) {
            return $this->startDate->format('F Y');
        }
        
        return $this->startDate->format('d/m/Y') . ' - ' . $this->endDate->format('d/m/Y');
    }

    /**
     * Get total number of expenses in the period
     */
    private function getExpenseCount(): int
    {
        return $this->user->expenses()
            ->whereBetween('expense_date', [$this->startDate, $this->endDate])
            ->where('status', 'confirmed')
            ->count();
    }

    /**
     * Get total amount of expenses in the period
     */
    private function getTotalAmount(): float
    {
        return $this->user->expenses()
            ->whereBetween('expense_date', [$this->startDate, $this->endDate])
            ->where('status', 'confirmed')
            ->sum('amount');
    }
}