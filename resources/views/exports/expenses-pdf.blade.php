<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ trans('telegram.export_title', [], $language) ?? 'Expense Report' }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 20px;
        }
        
        .header h1 {
            color: #2c3e50;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        
        .header .period {
            color: #7f8c8d;
            font-size: 16px;
            margin: 5px 0;
        }
        
        .header .generated {
            color: #95a5a6;
            font-size: 11px;
            margin-top: 10px;
        }
        
        .summary {
            background-color: #ecf0f1;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        
        .summary h2 {
            color: #2c3e50;
            font-size: 18px;
            margin: 0 0 15px 0;
        }
        
        .summary-grid {
            display: table;
            width: 100%;
        }
        
        .summary-item {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            padding: 10px;
        }
        
        .summary-value {
            font-size: 24px;
            font-weight: bold;
            color: #e74c3c;
            display: block;
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: #7f8c8d;
            font-size: 11px;
            text-transform: uppercase;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section h3 {
            color: #2c3e50;
            font-size: 16px;
            margin: 0 0 15px 0;
            border-bottom: 1px solid #bdc3c7;
            padding-bottom: 5px;
        }
        
        .category-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .category-table th {
            background-color: #34495e;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
        }
        
        .category-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .category-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .category-table .amount {
            text-align: right;
            font-weight: bold;
            color: #e74c3c;
        }
        
        .category-table .percentage {
            text-align: right;
            color: #7f8c8d;
            font-size: 11px;
        }
        
        .expenses-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        
        .expenses-table th {
            background-color: #34495e;
            color: white;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
        }
        
        .expenses-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .expenses-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .expenses-table .date {
            white-space: nowrap;
        }
        
        .expenses-table .amount {
            text-align: right;
            font-weight: bold;
            color: #e74c3c;
            white-space: nowrap;
        }
        
        .expenses-table .category {
            color: #3498db;
            font-size: 10px;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            color: #95a5a6;
            font-size: 10px;
            border-top: 1px solid #ecf0f1;
            padding-top: 20px;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        @media print {
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $language === 'es' ? 'Reporte de Gastos' : 'Expense Report' }}</h1>
        <div class="period">
            @if($startDate->isSameDay($endDate))
                {{ $startDate->format('d/m/Y') }}
            @else
                {{ $startDate->format('d/m/Y') }} - {{ $endDate->format('d/m/Y') }}
            @endif
        </div>
        <div class="generated">
            {{ $language === 'es' ? 'Generado el' : 'Generated on' }} {{ $generatedAt->format('d/m/Y H:i') }}
        </div>
    </div>

    <div class="summary">
        <h2>{{ $language === 'es' ? 'Resumen' : 'Summary' }}</h2>
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-value">${{ number_format($grandTotal, 2) }}</span>
                <span class="summary-label">{{ $language === 'es' ? 'Total' : 'Total' }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-value">{{ $expenses->count() }}</span>
                <span class="summary-label">{{ $language === 'es' ? 'Gastos' : 'Expenses' }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-value">${{ number_format($dailyAverage, 2) }}</span>
                <span class="summary-label">{{ $language === 'es' ? 'Promedio Diario' : 'Daily Average' }}</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h3>{{ $language === 'es' ? 'Gastos por Categoría' : 'Expenses by Category' }}</h3>
        <table class="category-table">
            <thead>
                <tr>
                    <th>{{ $language === 'es' ? 'Categoría' : 'Category' }}</th>
                    <th style="text-align: right;">{{ $language === 'es' ? 'Cantidad' : 'Count' }}</th>
                    <th style="text-align: right;">{{ $language === 'es' ? 'Total' : 'Total' }}</th>
                    <th style="text-align: right;">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach($categoryTotals as $category)
                <tr>
                    <td>{{ $category['name'] }}</td>
                    <td style="text-align: right;">{{ $category['count'] }}</td>
                    <td class="amount">${{ number_format($category['total'], 2) }}</td>
                    <td class="percentage">{{ number_format(($category['total'] / $grandTotal) * 100, 1) }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h3>{{ $language === 'es' ? 'Detalle de Gastos' : 'Expense Details' }}</h3>
        <table class="expenses-table">
            <thead>
                <tr>
                    <th>{{ $language === 'es' ? 'Fecha' : 'Date' }}</th>
                    <th>{{ $language === 'es' ? 'Descripción' : 'Description' }}</th>
                    <th>{{ $language === 'es' ? 'Categoría' : 'Category' }}</th>
                    <th style="text-align: right;">{{ $language === 'es' ? 'Monto' : 'Amount' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($expenses as $expense)
                <tr>
                    <td class="date">{{ \Carbon\Carbon::parse($expense->expense_date)->format('d/m/Y') }}</td>
                    <td>{{ $expense->description }}</td>
                    <td class="category">
                        {{ $expense->category->parent 
                            ? $expense->category->parent->getTranslatedName($language) 
                            : $expense->category->getTranslatedName($language) }}
                    </td>
                    <td class="amount">${{ number_format($expense->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="footer">
        <p>{{ $language === 'es' ? 'ExpenseBot - Tu asistente personal de gastos' : 'ExpenseBot - Your personal expense assistant' }}</p>
    </div>
</body>
</html>