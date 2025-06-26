<?php

namespace App\Telegram\Commands;

use Carbon\Carbon;

class ExportCommand extends Command
{
    protected string $name = 'export';
    
    public function handle(array $message, string $params = ''): void
    {
        $this->sendTyping();
        
        // Parse parameters for direct export
        if (!empty($params)) {
            $this->handleDirectExport($params);
            return;
        }
        
        // Show export options
        $message = "📤 *Export Expenses*\n\n";
        $message .= "Select export format and period:\n\n";
        $message .= "*Formats available:*\n";
        $message .= "• 📊 Excel - Detailed spreadsheet with charts\n";
        $message .= "• 📄 PDF - Formatted report with summaries\n";
        $message .= "• 💾 CSV - Raw data for analysis\n\n";
        $message .= "_Choose an option below:_";
        
        // Format selection keyboard
        $keyboard = [
            [
                ['text' => '📊 Excel', 'callback_data' => 'export_format_excel'],
                ['text' => '📄 PDF', 'callback_data' => 'export_format_pdf'],
                ['text' => '💾 CSV', 'callback_data' => 'export_format_csv']
            ],
            [
                ['text' => '⚡ Quick Export (This Month)', 'callback_data' => 'export_quick_month']
            ],
            [
                ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']
            ]
        ];
        
        $this->replyWithKeyboard($message, $keyboard, ['parse_mode' => 'Markdown']);
        
        $this->logExecution('menu_shown');
    }
    
    /**
     * Handle direct export with parameters
     */
    private function handleDirectExport(string $params): void
    {
        // Parse format and period from params
        // Examples: "excel month", "csv today", "pdf week"
        $parts = explode(' ', strtolower(trim($params)));
        
        $format = $parts[0] ?? 'excel';
        $period = $parts[1] ?? 'month';
        
        // Validate format
        if (!in_array($format, ['excel', 'pdf', 'csv'])) {
            $this->reply("❌ Invalid format. Use: excel, pdf, or csv");
            return;
        }
        
        // Determine date range
        $dateRange = $this->getDateRange($period);
        
        if (!$dateRange) {
            $this->reply("❌ Invalid period. Use: today, week, month, or year");
            return;
        }
        
        // Check if user has expenses in this period
        $expenseCount = $this->user->expenses()
            ->whereBetween('expense_date', [$dateRange['start'], $dateRange['end']])
            ->where('status', 'confirmed')
            ->count();
        
        if ($expenseCount === 0) {
            $this->reply("📊 No expenses found for {$dateRange['name']}");
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
        switch ($period) {
            case 'today':
            case 'hoy':
                return [
                    'start' => Carbon::today(),
                    'end' => Carbon::today()->endOfDay(),
                    'name' => 'today'
                ];
                
            case 'week':
            case 'semana':
                return [
                    'start' => Carbon::now()->startOfWeek(),
                    'end' => Carbon::now()->endOfWeek(),
                    'name' => 'this week'
                ];
                
            case 'month':
            case 'mes':
                return [
                    'start' => Carbon::now()->startOfMonth(),
                    'end' => Carbon::now()->endOfMonth(),
                    'name' => Carbon::now()->format('F Y')
                ];
                
            case 'year':
            case 'año':
                return [
                    'start' => Carbon::now()->startOfYear(),
                    'end' => Carbon::now()->endOfYear(),
                    'name' => 'year ' . Carbon::now()->year
                ];
                
            case 'all':
            case 'todo':
                $firstExpense = $this->user->expenses()->min('expense_date');
                return [
                    'start' => $firstExpense ? Carbon::parse($firstExpense) : Carbon::now()->subYear(),
                    'end' => Carbon::now(),
                    'name' => 'all time'
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
            "🔄 Generating your {$format} export for {$dateRange['name']}...\n\n" .
            "_This may take a few moments. I'll send you the file when it's ready._",
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
            'period' => $dateRange['name']
        ]);
    }
}