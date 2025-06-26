<?php

namespace App\Console\Commands;

use App\Services\OCRService;
use App\Services\ReceiptParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestOCRUrl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:ocr-url {url? : URL of the image to test} {--parse : Parse as receipt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test OCR functionality with an image from URL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Sample receipt URLs for testing
        $sampleUrls = [
            'simple' => 'https://i.imgur.com/sample-receipt.jpg',
            'mexican' => 'https://i.imgur.com/mexican-receipt.jpg',
            // You can add actual receipt image URLs here for testing
        ];
        
        $url = $this->argument('url');
        
        if (!$url) {
            $this->info('No URL provided. Using a test receipt image...');
            
            // Create a simple test receipt image
            $this->createTestReceiptImage();
            $imagePath = storage_path('app/test-receipt.png');
        } else {
            // Download image from URL
            $this->info("Downloading image from: {$url}");
            
            try {
                $response = Http::timeout(30)->get($url);
                
                if (!$response->successful()) {
                    $this->error('Failed to download image');
                    return Command::FAILURE;
                }
                
                $extension = $this->getExtensionFromUrl($url, $response->header('Content-Type'));
                $imagePath = storage_path('app/temp-receipt.' . $extension);
                
                file_put_contents($imagePath, $response->body());
                
                $this->info('✅ Image downloaded successfully');
                
            } catch (\Exception $e) {
                $this->error('Download failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }
        
        // Run OCR test
        $this->call('test:ocr', [
            'image' => $imagePath,
            '--parse' => $this->option('parse')
        ]);
        
        // Clean up
        if (file_exists($imagePath) && str_contains($imagePath, 'temp-')) {
            unlink($imagePath);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Create a test receipt image
     */
    private function createTestReceiptImage(): void
    {
        $width = 400;
        $height = 600;
        
        // Create image
        $image = imagecreatetruecolor($width, $height);
        
        // Colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 128, 128, 128);
        
        // Fill background
        imagefilledrectangle($image, 0, 0, $width, $height, $white);
        
        // Font settings
        $fontBold = 5; // Built-in font
        $fontNormal = 3;
        
        // Receipt content
        $y = 20;
        $lineHeight = 20;
        
        // Header
        $this->drawCenteredText($image, "OXXO", $fontBold, $black, $y, $width);
        $y += $lineHeight;
        
        $this->drawCenteredText($image, "SUCURSAL #1234", $fontNormal, $gray, $y, $width);
        $y += $lineHeight;
        
        $this->drawCenteredText($image, "AV. REFORMA 123", $fontNormal, $gray, $y, $width);
        $y += $lineHeight * 2;
        
        // Date and time
        imagestring($image, $fontNormal, 20, $y, "FECHA: 26/12/2024", $black);
        imagestring($image, $fontNormal, 250, $y, "HORA: 14:35", $black);
        $y += $lineHeight * 2;
        
        // Line
        imageline($image, 20, $y, $width - 20, $y, $gray);
        $y += $lineHeight;
        
        // Items
        $items = [
            ["COCA COLA 600ML", "$18.00"],
            ["SABRITAS ORIGINAL", "$15.50"],
            ["PAN BIMBO", "$32.00"],
            ["LECHE LALA 1L", "$28.00"]
        ];
        
        foreach ($items as $item) {
            imagestring($image, $fontNormal, 20, $y, $item[0], $black);
            imagestring($image, $fontNormal, $width - 80, $y, $item[1], $black);
            $y += $lineHeight;
        }
        
        // Line
        $y += 10;
        imageline($image, 20, $y, $width - 20, $y, $gray);
        $y += $lineHeight;
        
        // Totals
        imagestring($image, $fontNormal, 20, $y, "SUBTOTAL:", $black);
        imagestring($image, $fontNormal, $width - 80, $y, "$93.50", $black);
        $y += $lineHeight;
        
        imagestring($image, $fontNormal, 20, $y, "IVA 16%:", $black);
        imagestring($image, $fontNormal, $width - 80, $y, "$14.96", $black);
        $y += $lineHeight;
        
        imagestring($image, $fontBold, 20, $y, "TOTAL:", $black);
        imagestring($image, $fontBold, $width - 90, $y, "$108.46", $black);
        $y += $lineHeight * 2;
        
        // Payment method
        $this->drawCenteredText($image, "PAGO CON TARJETA", $fontNormal, $black, $y, $width);
        $y += $lineHeight;
        
        $this->drawCenteredText($image, "VISA ****1234", $fontNormal, $gray, $y, $width);
        $y += $lineHeight * 2;
        
        // Footer
        $this->drawCenteredText($image, "GRACIAS POR SU COMPRA", $fontNormal, $black, $y, $width);
        
        // Save image
        imagepng($image, storage_path('app/test-receipt.png'));
        imagedestroy($image);
        
        $this->info('✅ Test receipt image created');
    }
    
    /**
     * Draw centered text
     */
    private function drawCenteredText($image, $text, $font, $color, $y, $width): void
    {
        $textWidth = imagefontwidth($font) * strlen($text);
        $x = ($width - $textWidth) / 2;
        imagestring($image, $font, $x, $y, $text, $color);
    }
    
    /**
     * Get extension from URL or content type
     */
    private function getExtensionFromUrl($url, $contentType): string
    {
        // Try from URL
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            if ($extension && in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
                return $extension;
            }
        }
        
        // Try from content type
        $mimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/webp' => 'webp'
        ];
        
        if (isset($mimeTypes[$contentType])) {
            return $mimeTypes[$contentType];
        }
        
        return 'jpg'; // Default
    }
}