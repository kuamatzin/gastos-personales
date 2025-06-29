<?php

namespace App\Telegram\Commands;

use Carbon\Carbon;

class ExportCommand extends Command
{
    protected string $name = 'export';

    public function handle(array $message, string $params = ''): void
    {
        try {
            $this->sendTyping();

            // Parse parameters for direct export
            if (! empty($params)) {
                $this->handleDirectExport($params);

                return;
            }

            // Show export options
            $message = $this->trans('telegram.export_header') . "\n\n";
            $message .= $this->trans('telegram.export_select_format') . "\n\n";
            $message .= "*" . $this->trans('telegram.export_formats_available') . "*\n";
            $message .= "• 📄 PDF - " . $this->trans('telegram.export_pdf_description') . "\n";
            $message .= "• 💾 CSV - " . $this->trans('telegram.export_csv_description') . "\n\n";
            $message .= '_' . $this->trans('telegram.export_choose_option') . '_';

            // Format selection keyboard
            $keyboard = [
                [
                    ['text' => $this->trans('telegram.button_pdf'), 'callback_data' => 'export_format_pdf'],
                    ['text' => $this->trans('telegram.button_csv'), 'callback_data' => 'export_format_csv'],
                ],
                [
                    ['text' => $this->trans('telegram.button_quick_export'), 'callback_data' => 'export_quick_month'],
                ],
                [
                    ['text' => $this->trans('telegram.button_cancel'), 'callback_data' => 'cancel'],
                ],
            ];

            $this->replyWithKeyboardMarkdown($message, $keyboard);

            $this->logExecution('menu_shown');

        } catch (\Exception $e) {
            $this->logError('Failed to handle export command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply($this->trans('telegram.export_error'));
        }
    }

    /**
     * Handle direct export with parameters
     */
    private function handleDirectExport(string $params): void
    {
        // Parse format and period from params
        // Examples: "excel month", "csv today", "pdf week"
        $parts = explode(' ', strtolower(trim($params)));

        $format = $parts[0] ?? 'pdf';
        $period = $parts[1] ?? 'month';

        // Validate format
        if (! in_array($format, ['pdf', 'csv'])) {
            $this->reply($this->trans('telegram.export_invalid_format'));

            return;
        }

        // Determine date range
        $dateRange = $this->getDateRange($period);

        if (! $dateRange) {
            $this->reply($this->trans('telegram.export_invalid_period'));

            return;
        }

        // Check if user has expenses in this period
        $expenseCount = $this->user->expenses()
            ->whereBetween('expense_date', [$dateRange['start'], $dateRange['end']])
            ->where('status', 'confirmed')
            ->count();

        if ($expenseCount === 0) {
            $this->reply($this->trans('telegram.export_no_expenses', ['period' => $dateRange['name']]));

            return;
        }

        // Queue export job
        $this->queueExport($format, $dateRange);
    }

    /**
     * Get date range from period string
     */
    private function getDateRange(string $period): ?array
    {
        $timezone = $this->user->getTimezone();
        
        switch ($period) {
            case 'today':
            case 'hoy':
                return [
                    'start' => Carbon::today($timezone),
                    'end' => Carbon::today($timezone)->endOfDay(),
                    'name' => 'today',
                ];

            case 'week':
            case 'semana':
                return [
                    'start' => Carbon::now($timezone)->startOfWeek(),
                    'end' => Carbon::now($timezone)->endOfWeek(),
                    'name' => 'this week',
                ];

            case 'month':
            case 'mes':
                return [
                    'start' => Carbon::now($timezone)->startOfMonth(),
                    'end' => Carbon::now($timezone)->endOfMonth(),
                    'name' => Carbon::now($timezone)->format('F Y'),
                ];

            case 'year':
            case 'año':
                return [
                    'start' => Carbon::now($timezone)->startOfYear(),
                    'end' => Carbon::now($timezone)->endOfYear(),
                    'name' => 'year '.Carbon::now($timezone)->year,
                ];

            case 'all':
            case 'todo':
                $firstExpense = $this->user->expenses()->min('expense_date');

                return [
                    'start' => $firstExpense ? Carbon::parse($firstExpense, $timezone) : Carbon::now($timezone)->subYear(),
                    'end' => Carbon::now($timezone),
                    'name' => 'all time',
                ];

            default:
                return null;
        }
    }

    /**
     * Queue export job
     */
    private function queueExport(string $format, array $dateRange): void
    {
        $this->reply(
            $this->trans('telegram.export_generating', [
                'format' => strtoupper($format),
                'period' => $dateRange['name']
            ]),
            ['parse_mode' => 'Markdown']
        );

        // Dispatch export job
        \App\Jobs\GenerateExpenseExport::dispatch(
            $this->user,
            $format,
            $dateRange['start'],
            $dateRange['end']
        );

        $this->logExecution('export_queued', [
            'format' => $format,
            'period' => $dateRange['name'],
        ]);
    }
}
