<?php

namespace App\Console\Commands;

use App\Services\ReceiptParserService;
use Illuminate\Console\Command;

class TestReceiptParser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:receipt-parser {--file= : Text file with receipt content}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test receipt parser with sample text';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $parser = new ReceiptParserService();
        
        // Get receipt text
        if ($file = $this->option('file')) {
            if (!file_exists($file)) {
                $this->error("File not found: {$file}");
                return Command::FAILURE;
            }
            $receiptText = file_get_contents($file);
            $this->info("Testing with file: {$file}");
        } else {
            // Use sample receipt text
            $receiptText = $this->getSampleReceiptText();
            $this->info("Testing with sample receipt text");
        }
        
        $this->line('');
        $this->info('📄 Receipt Text:');
        $this->line($receiptText);
        $this->line('');
        
        // Parse receipt
        $this->info('🔍 Parsing receipt...');
        $result = $parser->parseReceipt($receiptText);
        
        $this->info('✅ Parsing complete!');
        $this->line('');
        
        // Display results
        $this->info('📋 Parsed Data:');
        $this->table(
            ['Field', 'Value', 'Status'],
            [
                ['Merchant', $result['merchant'] ?? '-', $result['merchant'] ? '✅' : '❌'],
                ['Total', $result['total'] ? '$' . number_format($result['total'], 2) : '-', $result['total'] ? '✅' : '❌'],
                ['Subtotal', $result['subtotal'] ? '$' . number_format($result['subtotal'], 2) : '-', $result['subtotal'] ? '✅' : '❌'],
                ['Tax', $result['tax'] ? '$' . number_format($result['tax'], 2) : '-', $result['tax'] ? '✅' : '❌'],
                ['Date', $result['date'] ?? '-', $result['date'] ? '✅' : '❌'],
                ['Time', $result['time'] ?? '-', $result['time'] ? '✅' : '❌'],
                ['Payment Method', $result['payment_method'] ?? '-', $result['payment_method'] ? '✅' : '❌'],
                ['Reference', $result['reference_number'] ?? '-', $result['reference_number'] ? '✅' : '❌'],
                ['Cashier', $result['cashier'] ?? '-', $result['cashier'] ? '✅' : '❌'],
                ['Confidence', number_format($result['confidence'] * 100, 1) . '%', $result['confidence'] > 0.7 ? '✅' : '⚠️'],
            ]
        );
        
        if (!empty($result['items'])) {
            $this->line('');
            $this->info('🛒 Line Items:');
            $this->table(
                ['Description', 'Quantity', 'Price'],
                array_map(function($item) {
                    return [
                        $item['description'],
                        $item['quantity'],
                        '$' . number_format($item['price'], 2)
                    ];
                }, $result['items'])
            );
        }
        
        // Show validation
        $this->line('');
        $this->info('🔢 Validation:');
        
        if ($result['total'] && $result['subtotal'] && $result['tax']) {
            $calculatedTotal = $result['subtotal'] + $result['tax'];
            $difference = abs($result['total'] - $calculatedTotal);
            
            if ($difference < 0.01) {
                $this->info('✅ Total matches (Subtotal + Tax = Total)');
            } else {
                $this->warn('⚠️  Total mismatch: $' . number_format($difference, 2) . ' difference');
            }
        }
        
        // Test different receipt formats
        if (!$this->option('file')) {
            $this->line('');
            $this->info('Testing other receipt formats...');
            $this->testOtherFormats($parser);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Get sample receipt text
     */
    private function getSampleReceiptText(): string
    {
        return <<<EOT
OXXO S.A. DE C.V.
SUCURSAL #1234
AV. REFORMA 123, COL. CENTRO
CIUDAD DE MEXICO, CDMX
RFC: OXX123456ABC

FECHA: 26/12/2024
HORA: 14:35

------------------------
COCA COLA 600ML        $18.00
SABRITAS ORIGINAL      $15.50
PAN BIMBO BLANCO       $32.00
LECHE LALA 1L          $28.00
------------------------
SUBTOTAL:              $93.50
IVA 16%:               $14.96
TOTAL:                $108.46

PAGO CON TARJETA
VISA ****1234

FOLIO: 789456
CAJERO: MARIA GARCIA

GRACIAS POR SU COMPRA
CONSERVE SU TICKET
EOT;
    }
    
    /**
     * Test other receipt formats
     */
    private function testOtherFormats(ReceiptParserService $parser): void
    {
        $formats = [
            'Walmart' => <<<EOT
WALMART DE MEXICO
26/12/2024 15:45
SUBTOTAL: $250.00
IVA: $40.00
TOTAL: $290.00
EFECTIVO
EOT,
            'Restaurant' => <<<EOT
LA CASA DE TOÑO
Mesa: 5
Mesero: Juan
2 Pozole Grande  $180.00
1 Quesadilla     $45.00
3 Refrescos      $90.00
Subtotal        $315.00
IVA             $50.40
TOTAL          $365.40
Propina sugerida: $54.81
EOT,
            'Gas Station' => <<<EOT
PEMEX
GASOLINA MAGNA
LITROS: 40.00
PRECIO/L: $22.50
TOTAL: $900.00
26-12-2024 10:30
EOT
        ];
        
        foreach ($formats as $name => $text) {
            $this->line('');
            $this->info("Testing {$name} format:");
            
            $result = $parser->parseReceipt($text);
            
            $this->line("Merchant: " . ($result['merchant'] ?? 'Not found'));
            $this->line("Total: " . ($result['total'] ? '$' . number_format($result['total'], 2) : 'Not found'));
            $this->line("Date: " . ($result['date'] ?? 'Not found'));
            $this->line("Confidence: " . number_format($result['confidence'] * 100, 1) . '%');
        }
    }
}