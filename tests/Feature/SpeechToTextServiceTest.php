<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\SpeechToTextService;
use App\Strategies\VoiceNoteStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class SpeechToTextServiceTest extends TestCase
{
    use RefreshDatabase;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Google Cloud credentials
        config([
            'services.google_cloud.speech_enabled' => true,
            'services.google_cloud.credentials_path' => storage_path('app/test-credentials.json')
        ]);
    }
    
    public function test_speech_service_requires_credentials()
    {
        config(['services.google_cloud.credentials_path' => '/non/existent/path.json']);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Google Cloud credentials file not found');
        
        new SpeechToTextService();
    }
    
    public function test_voice_strategy_extracts_amounts_in_spanish()
    {
        $mockSpeech = Mockery::mock(SpeechToTextService::class);
        $mockFile = Mockery::mock(\App\Services\FileProcessingService::class);
        
        $strategy = new VoiceNoteStrategy($mockSpeech, $mockFile);
        
        // Test various Spanish amount patterns
        $testCases = [
            "gasté cincuenta pesos en el oxxo" => [50.0],
            "fueron 150 pesos de gasolina" => [150.0],
            "pagué 25 con 50 centavos" => [25.50],
            "el total fue de 1,234.56 pesos" => [1234.56],
            "gasté 100 pesos en comida y 50 en transporte" => [100.0, 50.0],
        ];
        
        foreach ($testCases as $transcript => $expectedAmounts) {
            $mockSpeech->shouldReceive('processTelegramAudio')
                ->once()
                ->andReturn([
                    'transcript' => $transcript,
                    'confidence' => 0.9,
                    'language' => 'es-MX'
                ]);
            
            $result = $strategy->process('test.ogg');
            
            $this->assertTrue($result['success']);
            $this->assertEquals($transcript, $result['transcription']['text']);
            
            if (!empty($expectedAmounts)) {
                $this->assertEquals(max($expectedAmounts), $result['expense']['amount']);
                $this->assertEquals($expectedAmounts, $result['expense']['all_amounts']);
            }
        }
    }
    
    public function test_voice_strategy_extracts_merchants()
    {
        $mockSpeech = Mockery::mock(SpeechToTextService::class);
        $mockFile = Mockery::mock(\App\Services\FileProcessingService::class);
        
        $strategy = new VoiceNoteStrategy($mockSpeech, $mockFile);
        
        $testCases = [
            "compré en oxxo" => "Oxxo",
            "fui al restaurante la casa de toño" => "La Casa De",
            "en walmart gasté 500 pesos" => "Walmart Gasté 500",
            "pagué en el supermercado chedraui" => "Chedraui",
        ];
        
        foreach ($testCases as $transcript => $expectedMerchant) {
            $mockSpeech->shouldReceive('processTelegramAudio')
                ->once()
                ->andReturn([
                    'transcript' => $transcript,
                    'confidence' => 0.9,
                    'language' => 'es-MX'
                ]);
            
            $result = $strategy->process('test.ogg');
            
            $this->assertTrue($result['success']);
            $this->assertEquals($expectedMerchant, $result['expense']['merchant'] ?? null);
        }
    }
    
    public function test_voice_strategy_extracts_payment_methods()
    {
        $mockSpeech = Mockery::mock(SpeechToTextService::class);
        $mockFile = Mockery::mock(\App\Services\FileProcessingService::class);
        
        $strategy = new VoiceNoteStrategy($mockSpeech, $mockFile);
        
        $testCases = [
            "pagué en efectivo" => 'efectivo',
            "usé mi tarjeta de crédito" => 'tarjeta_credito',
            "pagué con débito" => 'tarjeta_debito',
            "hice una transferencia" => 'transferencia',
        ];
        
        foreach ($testCases as $transcript => $expectedMethod) {
            $mockSpeech->shouldReceive('processTelegramAudio')
                ->once()
                ->andReturn([
                    'transcript' => $transcript,
                    'confidence' => 0.9,
                    'language' => 'es-MX'
                ]);
            
            $result = $strategy->process('test.ogg');
            
            $this->assertTrue($result['success']);
            $this->assertEquals($expectedMethod, $result['expense']['payment_method'] ?? null);
        }
    }
    
    public function test_voice_strategy_handles_conversion_on_format_error()
    {
        $mockSpeech = Mockery::mock(SpeechToTextService::class);
        $mockFile = Mockery::mock(\App\Services\FileProcessingService::class);
        
        $strategy = new VoiceNoteStrategy($mockSpeech, $mockFile);
        
        // First attempt fails with format error
        $mockSpeech->shouldReceive('processTelegramAudio')
            ->once()
            ->andThrow(new \Exception('Unsupported audio format'));
        
        // Expect conversion attempt
        $mockFile->shouldReceive('convertAudioFormat')
            ->once()
            ->with('test.ogg', 'wav')
            ->andReturn('test.wav');
        
        // Second attempt succeeds after conversion
        $mockSpeech->shouldReceive('transcribeAudio')
            ->once()
            ->with('test.wav', 'es-MX')
            ->andReturn([
                'transcript' => 'gasté 100 pesos',
                'confidence' => 0.85,
                'language' => 'es-MX'
            ]);
        
        // Cleanup
        $mockFile->shouldReceive('cleanupTempFiles')
            ->once()
            ->with(['test.wav']);
        
        $result = $strategy->process('test.ogg');
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['transcription']['converted']);
        $this->assertEquals(100.0, $result['expense']['amount']);
    }
    
    public function test_voice_strategy_supports_correct_mime_types()
    {
        $mockSpeech = Mockery::mock(SpeechToTextService::class);
        $mockFile = Mockery::mock(\App\Services\FileProcessingService::class);
        
        $strategy = new VoiceNoteStrategy($mockSpeech, $mockFile);
        
        // Supported types
        $this->assertTrue($strategy->canProcess('audio/ogg'));
        $this->assertTrue($strategy->canProcess('audio/mpeg'));
        $this->assertTrue($strategy->canProcess('audio/mp3'));
        $this->assertTrue($strategy->canProcess('audio/wav'));
        $this->assertTrue($strategy->canProcess('audio/flac'));
        
        // Unsupported types
        $this->assertFalse($strategy->canProcess('video/mp4'));
        $this->assertFalse($strategy->canProcess('image/jpeg'));
        $this->assertFalse($strategy->canProcess('text/plain'));
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}