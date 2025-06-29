<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use League\Csv\Writer;

class ExportService
{
    /**
     * Generate export file based on format
     */
    public function generateExport(User $user, string $format, Carbon $startDate, Carbon $endDate): string
    {
        return match ($format) {
            'pdf' => $this->generatePdf($user, $startDate, $endDate),
            'csv' => $this->generateCsv($user, $startDate, $endDate),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    /**
     * Generate PDF export
     */
    private function generatePdf(User $user, Carbon $startDate, Carbon $endDate): string
    {
        // Get expenses data
        $expenses = $user->expenses()
            ->with(['category.parent'])
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->orderBy('expense_date', 'desc')
            ->get();

        // Calculate totals by category
        $categoryTotals = [];
        $grandTotal = 0;
        
        foreach ($expenses as $expense) {
            $category = $expense->category->parent ?? $expense->category;
            $categoryName = $category->getTranslatedName($user->language ?? 'es');
            
            if (!isset($categoryTotals[$categoryName])) {
                $categoryTotals[$categoryName] = [
                    'name' => $categoryName,
                    'total' => 0,
                    'count' => 0,
                ];
            }
            
            $categoryTotals[$categoryName]['total'] += $expense->amount;
            $categoryTotals[$categoryName]['count']++;
            $grandTotal += $expense->amount;
        }
        
        // Sort by total descending
        uasort($categoryTotals, fn($a, $b) => $b['total'] <=> $a['total']);

        // Calculate daily average
        $days = $startDate->diffInDays($endDate) + 1;
        $dailyAverage = $days > 0 ? $grandTotal / $days : 0;

        // Prepare data for PDF
        $data = [
            'user' => $user,
            'expenses' => $expenses,
            'categoryTotals' => $categoryTotals,
            'grandTotal' => $grandTotal,
            'dailyAverage' => $dailyAverage,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'generatedAt' => now($user->getTimezone()),
            'language' => $user->language ?? 'es',
        ];

        // Generate PDF
        $pdf = Pdf::loadView('exports.expenses-pdf', $data);
        $pdf->setPaper('letter', 'portrait');
        
        // Save to storage
        $filename = 'exports/expenses_' . $user->id . '_' . now()->timestamp . '.pdf';
        Storage::put($filename, $pdf->output());
        
        return $filename;
    }

    /**
     * Generate CSV export
     */
    private function generateCsv(User $user, Carbon $startDate, Carbon $endDate): string
    {
        // Get expenses data
        $expenses = $user->expenses()
            ->with(['category.parent'])
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->orderBy('expense_date', 'desc')
            ->get();

        // Create CSV
        $csv = Writer::createFromString('');
        
        // Add headers based on language
        $headers = $user->language === 'es' 
            ? ['Fecha', 'Monto', 'Descripción', 'Categoría', 'Comercio', 'Método de Pago']
            : ['Date', 'Amount', 'Description', 'Category', 'Merchant', 'Payment Method'];
        
        $csv->insertOne($headers);
        
        // Add data rows
        foreach ($expenses as $expense) {
            $category = $expense->category->parent ?? $expense->category;
            $csv->insertOne([
                $expense->expense_date,
                number_format($expense->amount, 2, '.', ''),
                $expense->description,
                $category->getTranslatedName($user->language ?? 'es'),
                $expense->merchant_name ?? '',
                $expense->payment_method ?? '',
            ]);
        }
        
        // Add summary rows
        $csv->insertOne([]); // Empty row
        $csv->insertOne([
            $user->language === 'es' ? 'TOTAL' : 'TOTAL',
            number_format($expenses->sum('amount'), 2, '.', ''),
            '',
            '',
            '',
            '',
        ]);
        
        // Save to storage
        $filename = 'exports/expenses_' . $user->id . '_' . now()->timestamp . '.csv';
        Storage::put($filename, $csv->toString());
        
        return $filename;
    }
}