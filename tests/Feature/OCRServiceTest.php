<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\OCRService;
use App\Services\ReceiptParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;

class OCRServiceTest extends TestCase
{
    use RefreshDatabase;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Google Cloud credentials
        config([
            'services.google_cloud.vision_enabled' => true,
            'services.google_cloud.credentials_path' => storage_path('app/test-credentials.json')
        ]);
    }
    
    public function test_ocr_service_requires_credentials()
    {
        config(['services.google_cloud.credentials_path' => '/non/existent/path.json']);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Google Cloud credentials file not found');
        
        new OCRService();
    }
    
    public function test_receipt_parser_extracts_merchant_name()
    {
        $parser = new ReceiptParserService();
        
        $ocrText = "OXXO S.A. DE C.V.\nSUCURSAL #1234\nFecha: 15/12/2024\nTotal: $150.00";
        
        $result = $parser->parseReceipt($ocrText);
        
        $this->assertEquals('OXXO', $result['merchant']);
        $this->assertEquals(150.00, $result['total']);
        $this->assertEquals('15/12/2024', $result['date']);
    }
    
    public function test_receipt_parser_extracts_total_amount()
    {
        $parser = new ReceiptParserService();
        
        $testCases = [
            "TOTAL: $250.50" => 250.50,
            "TOTAL A PAGAR: $1,234.56" => 1234.56,
            "IMPORTE TOTAL: MXN 99.99" => 99.99,
            "Total    $  45.00" => 45.00,
        ];
        
        foreach ($testCases as $text => $expectedAmount) {
            $result = $parser->parseReceipt($text);
            $this->assertEquals($expectedAmount, $result['total'], "Failed to parse: {$text}");
        }
    }
    
    public function test_receipt_parser_extracts_tax_information()
    {
        $parser = new ReceiptParserService();
        
        $ocrText = "SUBTOTAL: $100.00\nIVA 16%: $16.00\nTOTAL: $116.00";
        
        $result = $parser->parseReceipt($ocrText);
        
        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(16.00, $result['tax']);
        $this->assertEquals(116.00, $result['total']);
    }
    
    public function test_receipt_parser_extracts_payment_method()
    {
        $parser = new ReceiptParserService();
        
        $testCases = [
            "PAGO CON TARJETA DE CREDITO" => 'tarjeta_credito',
            "VISA ****1234" => 'visa',
            "EFECTIVO" => 'efectivo',
            "TRANSFERENCIA SPEI" => 'transferencia',
        ];
        
        foreach ($testCases as $text => $expectedMethod) {
            $result = $parser->parseReceipt($text);
            $this->assertEquals($expectedMethod, $result['payment_method'], "Failed to parse: {$text}");
        }
    }
    
    public function test_receipt_parser_validates_totals()
    {
        $parser = new ReceiptParserService();
        
        // Test with only total (should calculate subtotal and tax)
        $result = $parser->parseReceipt("TOTAL: $116.00");
        
        $this->assertEquals(116.00, $result['total']);
        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(16.00, $result['tax']);
    }
    
    public function test_receipt_parser_extracts_line_items()
    {
        $parser = new ReceiptParserService();
        
        $ocrText = <<<EOT
OXXO
COCA COLA 600ML $15.00
SABRITAS $12.50
GALLETAS MARIAS $8.00
SUBTOTAL: $35.50
IVA: $5.68
TOTAL: $41.18
EOT;
        
        $result = $parser->parseReceipt($ocrText);
        
        $this->assertCount(3, $result['items']);
        $this->assertEquals('COCA COLA 600ML', $result['items'][0]['description']);
        $this->assertEquals(15.00, $result['items'][0]['price']);
    }
    
    public function test_receipt_parser_handles_mexican_date_formats()
    {
        $parser = new ReceiptParserService();
        
        $testCases = [
            "15/ENE/2024" => '15/ENE/2024',
            "31/DIC/2023" => '31/DIC/2023',
            "01-03-2024" => '01-03-2024',
            "2024/12/25" => '2024/12/25',
        ];
        
        foreach ($testCases as $text => $expectedDate) {
            $result = $parser->parseReceipt("Fecha: {$text}");
            $this->assertNotNull($result['date'], "Failed to parse date: {$text}");
        }
    }
    
    public function test_receipt_parser_calculates_confidence_score()
    {
        $parser = new ReceiptParserService();
        
        // Full receipt should have high confidence
        $fullReceipt = <<<EOT
WALMART DE MEXICO
RFC: WME991231ABC
Fecha: 15/12/2024
Hora: 14:30
FOLIO: 12345
LECHE LALA 1L $25.00
PAN BIMBO $35.50
SUBTOTAL: $60.50
IVA: $9.68
TOTAL: $70.18
PAGO CON TARJETA CREDITO
EOT;
        
        $result = $parser->parseReceipt($fullReceipt);
        $this->assertGreaterThan(0.8, $result['confidence']);
        
        // Minimal receipt should have lower confidence
        $minimalReceipt = "TOTAL: $50.00";
        $result = $parser->parseReceipt($minimalReceipt);
        $this->assertLessThan(0.5, $result['confidence']);
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}