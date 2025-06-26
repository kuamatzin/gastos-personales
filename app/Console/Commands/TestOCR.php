<?php

namespace App\Console\Commands;

use App\Services\OCRService;
use App\Services\ReceiptParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestOCR extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:ocr {image : Path to the image file} {--parse : Parse as receipt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test OCR functionality with an image file';

    private OCRService $ocrService;
    private ReceiptParserService $parserService;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $imagePath = $this->argument('image');
        
        // Check if file exists
        if (!file_exists($imagePath)) {
            $this->error("File not found: {$imagePath}");
            return Command::FAILURE;
        }
        
        // Check if it's an image
        $mimeType = mime_content_type($imagePath);
        if (!str_starts_with($mimeType, 'image/')) {
            $this->error("File is not an image: {$mimeType}");
            return Command::FAILURE;
        }
        
        $this->info("Testing OCR on: {$imagePath}");
        $this->info("File type: {$mimeType}");
        $this->info("File size: " . $this->formatBytes(filesize($imagePath)));
        $this->line('');
        
        try {
            // Initialize services
            $this->ocrService = new OCRService();
            $this->parserService = new ReceiptParserService();
            
            // Test basic text extraction
            $this->info('🔍 Extracting text from image...');
            $result = $this->ocrService->extractTextFromImage($imagePath);
            
            if (empty($result['text'])) {
                $this->warn('No text found in image');
                return Command::SUCCESS;
            }
            
            // Display results
            $this->info('✅ Text extraction successful!');
            $this->line('');
            
            $this->info('📝 Extracted Text:');
            $this->line($result['text']);
            $this->line('');
            
            $this->info('📊 OCR Metrics:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Confidence', number_format($result['confidence'] * 100, 1) . '%'],
                    ['Language', $result['language'] ?? 'Unknown'],
                    ['Words Found', count($result['words'] ?? [])],
                ]
            );
            
            // If parse option is set, try parsing as receipt
            if ($this->option('parse')) {
                $this->line('');
                $this->info('🧾 Parsing as receipt...');
                
                $receiptData = $this->parserService->parseReceipt($result['text']);
                
                $this->info('✅ Receipt parsing complete!');
                $this->line('');
                
                $this->info('📋 Receipt Data:');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Merchant', $receiptData['merchant'] ?? 'Not found'],
                        ['Total', $receiptData['total'] ? '$' . number_format($receiptData['total'], 2) : 'Not found'],
                        ['Subtotal', $receiptData['subtotal'] ? '$' . number_format($receiptData['subtotal'], 2) : 'Not found'],
                        ['Tax', $receiptData['tax'] ? '$' . number_format($receiptData['tax'], 2) : 'Not found'],
                        ['Date', $receiptData['date'] ?? 'Not found'],
                        ['Time', $receiptData['time'] ?? 'Not found'],
                        ['Payment Method', $receiptData['payment_method'] ?? 'Not found'],
                        ['Reference', $receiptData['reference_number'] ?? 'Not found'],
                        ['Items Found', count($receiptData['items'] ?? [])],
                        ['Parse Confidence', number_format($receiptData['confidence'] * 100, 1) . '%'],
                    ]
                );
                
                if (!empty($receiptData['items'])) {
                    $this->line('');
                    $this->info('🛒 Line Items:');
                    foreach ($receiptData['items'] as $item) {
                        $this->line(sprintf(
                            "  • %s (Qty: %d) - $%.2f",
                            $item['description'],
                            $item['quantity'],
                            $item['price']
                        ));
                    }
                }
            }
            
            // Test receipt-specific extraction
            if (!$this->option('parse')) {
                $this->line('');
                $this->info('💡 Tip: Use --parse option to parse as receipt');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('OCR failed: ' . $e->getMessage());
            
            if (str_contains($e->getMessage(), 'credentials')) {
                $this->line('');
                $this->warn('Make sure Google Cloud credentials are configured:');
                $this->line('1. Create a service account in Google Cloud Console');
                $this->line('2. Enable Cloud Vision API');
                $this->line('3. Download credentials JSON');
                $this->line('4. Save to: ' . config('services.google_cloud.credentials_path'));
            }
            
            return Command::FAILURE;
        }
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}