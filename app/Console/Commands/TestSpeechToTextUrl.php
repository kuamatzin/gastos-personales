<?php

namespace App\Console\Commands;

use App\Services\SpeechToTextService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TestSpeechToTextUrl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:speech-url {url : URL of the audio file} {--timestamps : Include word timestamps} {--detect-language : Auto-detect language}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Speech-to-Text functionality with an audio URL';

    private SpeechToTextService $speechService;

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
        $audioUrl = $this->argument('url');
        
        // Validate URL
        if (!filter_var($audioUrl, FILTER_VALIDATE_URL)) {
            $this->error("Invalid URL: {$audioUrl}");
            return Command::FAILURE;
        }
        
        $this->info("Downloading audio from: {$audioUrl}");
        
        try {
            // Download the audio file
            $response = Http::timeout(60)->get($audioUrl);
            
            if (!$response->successful()) {
                $this->error("Failed to download audio: HTTP {$response->status()}");
                return Command::FAILURE;
            }
            
            // Determine file extension from URL or content type
            $extension = $this->getFileExtension($audioUrl, $response->header('Content-Type'));
            $supportedFormats = ['ogg', 'mp3', 'wav', 'flac', 'm4a', 'opus'];
            
            if (!in_array($extension, $supportedFormats)) {
                $this->error("Unsupported audio format: {$extension}");
                $this->info("Supported formats: " . implode(', ', $supportedFormats));
                return Command::FAILURE;
            }
            
            // Save to temporary file
            $tempPath = storage_path("app/temp-audio.{$extension}");
            file_put_contents($tempPath, $response->body());
            
            $this->info('✅ Audio downloaded successfully');
            
            // Ensure cleanup happens
            try {
                $this->info("Testing Speech-to-Text on: {$tempPath}");
                $this->info("File format: {$extension}");
                $this->info("File size: " . $this->formatBytes(filesize($tempPath)));
                $this->line('');
                
                // Initialize service
                $this->speechService = new SpeechToTextService();
                
                // Handle special formats
                if (in_array($extension, ['m4a', 'opus'])) {
                    $this->info('🔄 Converting audio format...');
                    $convertedPath = storage_path("app/temp-audio-converted.wav");
                    
                    if ($this->speechService->convertAudioFormat($tempPath, $convertedPath)) {
                        unlink($tempPath);
                        $tempPath = $convertedPath;
                        $extension = 'wav';
                        $this->info('✅ Audio converted to WAV format');
                    } else {
                        $this->error('Failed to convert audio format');
                        return Command::FAILURE;
                    }
                }
                
                // Auto-detect language if option is set
                if ($this->option('detect-language')) {
                    $this->info('🌐 Detecting language...');
                    $detectedLanguage = $this->speechService->detectLanguage($tempPath);
                    $this->info("✅ Detected language: {$detectedLanguage}");
                    $languageCode = $detectedLanguage;
                } else {
                    $languageCode = 'es-MX'; // Default to Spanish (Mexico)
                }
                
                // Test transcription
                $this->info('🎤 Transcribing audio...');
                
                if ($this->option('timestamps')) {
                    // Transcribe with word-level timestamps
                    $result = $this->speechService->transcribeWithTimestamps($tempPath, $languageCode);
                    
                    if (empty($result['segments'])) {
                        $this->warn('No speech detected in audio');
                        return Command::SUCCESS;
                    }
                    
                    $this->info('✅ Transcription successful!');
                    $this->line('');
                    
                    $this->info('📝 Full Transcript:');
                    $this->line($result['fullTranscript']);
                    $this->line('');
                    
                    $this->info('🕒 Segments with Timestamps:');
                    foreach ($result['segments'] as $index => $segment) {
                        $this->line('');
                        $this->comment("Segment " . ($index + 1) . " (Confidence: " . number_format($segment['confidence'] * 100, 1) . "%):");
                        $this->line($segment['transcript']);
                        
                        if (!empty($segment['words']) && count($segment['words']) <= 50) {
                            $this->line('');
                            $this->comment('Word timestamps:');
                            foreach ($segment['words'] as $word) {
                                $this->line(sprintf(
                                    "  [%s - %s] %s",
                                    $this->formatTime($word['startTime']),
                                    $this->formatTime($word['endTime']),
                                    $word['word']
                                ));
                            }
                        } elseif (!empty($segment['words'])) {
                            $this->line('');
                            $this->comment('(Word timestamps available for ' . count($segment['words']) . ' words - too many to display)');
                        }
                    }
                } else {
                    // Regular transcription
                    $result = $this->speechService->transcribeAudio($tempPath, $languageCode);
                    
                    if (empty($result['transcript'])) {
                        $this->warn('No speech detected in audio');
                        return Command::SUCCESS;
                    }
                    
                    $this->info('✅ Transcription successful!');
                    $this->line('');
                    
                    $this->info('📝 Transcript:');
                    $this->line($result['transcript']);
                    $this->line('');
                    
                    $this->info('📊 Transcription Metrics:');
                    $this->table(
                        ['Metric', 'Value'],
                        [
                            ['Confidence', number_format($result['confidence'] * 100, 1) . '%'],
                            ['Language', $result['language']],
                            ['Duration', $result['duration'] ? $this->formatDuration($result['duration']) : 'Unknown'],
                            ['Words Found', count($result['words'])],
                        ]
                    );
                    
                    if (!empty($result['words']) && count($result['words']) <= 20) {
                        $this->line('');
                        $this->info('📝 Word Details:');
                        foreach ($result['words'] as $word) {
                            $this->line(sprintf(
                                "  • %s (%.1f%% confidence)",
                                $word['word'],
                                ($word['confidence'] ?? 0) * 100
                            ));
                        }
                    }
                }
                
                // Test Telegram audio processing if it's an OGG file
                if ($extension === 'ogg') {
                    $this->line('');
                    $this->info('📱 Testing Telegram audio processing...');
                    $telegramResult = $this->speechService->processTelegramAudio($tempPath);
                    $this->info('✅ Telegram audio processing successful!');
                }
                
                // Provide tips
                $this->line('');
                if (!$this->option('timestamps')) {
                    $this->info('💡 Tip: Use --timestamps option to get word-level timing');
                }
                if (!$this->option('detect-language')) {
                    $this->info('💡 Tip: Use --detect-language option to auto-detect the audio language');
                }
                
                return Command::SUCCESS;
                
            } finally {
                // Clean up temporary files
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
                if (isset($convertedPath) && file_exists($convertedPath)) {
                    unlink($convertedPath);
                }
            }
            
        } catch (\Exception $e) {
            $this->error('Speech-to-Text failed: ' . $e->getMessage());
            
            if (str_contains($e->getMessage(), 'credentials')) {
                $this->line('');
                $this->warn('Make sure Google Cloud credentials are configured:');
                $this->line('1. Create a service account in Google Cloud Console');
                $this->line('2. Enable Cloud Speech-to-Text API');
                $this->line('3. Download credentials JSON');
                $this->line('4. Save to: ' . config('services.google_cloud.credentials_path'));
            } elseif (str_contains($e->getMessage(), 'Unsupported audio format')) {
                $this->line('');
                $this->warn('The audio format is not supported. Try a different format.');
                $this->line('Supported formats: ogg, mp3, wav, flac');
            } elseif (str_contains($e->getMessage(), 'FFmpeg')) {
                $this->line('');
                $this->warn('FFmpeg is required for audio format conversion.');
                $this->line('Install FFmpeg: brew install ffmpeg (macOS) or apt-get install ffmpeg (Linux)');
            }
            
            // Clean up on error
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }
            if (isset($convertedPath) && file_exists($convertedPath)) {
                unlink($convertedPath);
            }
            
            return Command::FAILURE;
        }
    }
    
    /**
     * Get file extension from URL or content type
     */
    private function getFileExtension(string $url, ?string $contentType): string
    {
        // First try to get from URL
        $urlPath = parse_url($url, PHP_URL_PATH);
        if ($urlPath) {
            $pathInfo = pathinfo($urlPath);
            if (isset($pathInfo['extension'])) {
                $extension = strtolower($pathInfo['extension']);
                // Map common audio extensions
                $extensionMap = [
                    'oga' => 'ogg',
                    'ogv' => 'ogg',
                    'opus' => 'opus',
                    'm4a' => 'm4a',
                    'mp4' => 'm4a',
                ];
                return $extensionMap[$extension] ?? $extension;
            }
        }
        
        // Fall back to content type
        if ($contentType) {
            $mimeToExt = [
                'audio/ogg' => 'ogg',
                'audio/opus' => 'opus',
                'audio/mpeg' => 'mp3',
                'audio/mp3' => 'mp3',
                'audio/wav' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/flac' => 'flac',
                'audio/x-flac' => 'flac',
                'audio/mp4' => 'm4a',
                'audio/m4a' => 'm4a',
                'audio/x-m4a' => 'm4a',
            ];
            
            foreach ($mimeToExt as $mime => $ext) {
                if (stripos($contentType, $mime) !== false) {
                    return $ext;
                }
            }
        }
        
        // Default to mp3 if we can't determine
        return 'mp3';
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
    
    /**
     * Format seconds to time string
     */
    private function formatTime(float $seconds): string
    {
        $minutes = floor($seconds / 60);
        $seconds = $seconds - ($minutes * 60);
        
        if ($minutes > 0) {
            return sprintf('%d:%02.1f', $minutes, $seconds);
        } else {
            return sprintf('%.1fs', $seconds);
        }
    }
    
    /**
     * Format duration in seconds to human readable
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . ' seconds';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return sprintf('%d:%02d', $minutes, $remainingSeconds);
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $remainingSeconds = $seconds % 60;
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }
    }
}