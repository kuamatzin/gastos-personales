<?php

namespace App\Console\Commands;

use App\Services\SpeechToTextService;
use Illuminate\Console\Command;

class TestSpeechToText extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:speech {audio : Path to the audio file} {--timestamps : Include word timestamps} {--detect-language : Auto-detect language}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Speech-to-Text functionality with an audio file';

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
        $audioPath = $this->argument('audio');

        // Check if file exists
        if (! file_exists($audioPath)) {
            $this->error("File not found: {$audioPath}");

            return Command::FAILURE;
        }

        // Check if it's a supported audio format
        $fileInfo = pathinfo($audioPath);
        $extension = strtolower($fileInfo['extension'] ?? '');
        $supportedFormats = ['ogg', 'mp3', 'wav', 'flac'];

        if (! in_array($extension, $supportedFormats)) {
            $this->error("Unsupported audio format: {$extension}");
            $this->info('Supported formats: '.implode(', ', $supportedFormats));

            return Command::FAILURE;
        }

        $this->info("Testing Speech-to-Text on: {$audioPath}");
        $this->info("File format: {$extension}");
        $this->info('File size: '.$this->formatBytes(filesize($audioPath)));
        $this->line('');

        try {
            // Initialize service
            $this->speechService = new SpeechToTextService;

            // Auto-detect language if option is set
            if ($this->option('detect-language')) {
                $this->info('🌐 Detecting language...');
                $detectedLanguage = $this->speechService->detectLanguage($audioPath);
                $this->info("✅ Detected language: {$detectedLanguage}");
                $languageCode = $detectedLanguage;
            } else {
                $languageCode = 'es-MX'; // Default to Spanish (Mexico)
            }

            // Test transcription
            $this->info('🎤 Transcribing audio...');

            if ($this->option('timestamps')) {
                // Transcribe with word-level timestamps
                $result = $this->speechService->transcribeWithTimestamps($audioPath, $languageCode);

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
                    $this->comment('Segment '.($index + 1).' (Confidence: '.number_format($segment['confidence'] * 100, 1).'%):');
                    $this->line($segment['transcript']);

                    if (! empty($segment['words'])) {
                        $this->line('');
                        $this->comment('Word timestamps:');
                        foreach ($segment['words'] as $word) {
                            $this->line(sprintf(
                                '  [%s - %s] %s',
                                $this->formatTime($word['startTime']),
                                $this->formatTime($word['endTime']),
                                $word['word']
                            ));
                        }
                    }
                }
            } else {
                // Regular transcription
                $result = $this->speechService->transcribeAudio($audioPath, $languageCode);

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
                        ['Confidence', number_format($result['confidence'] * 100, 1).'%'],
                        ['Language', $result['language']],
                        ['Duration', $result['duration'] ? $this->formatDuration($result['duration']) : 'Unknown'],
                        ['Words Found', count($result['words'])],
                    ]
                );

                if (! empty($result['words']) && count($result['words']) <= 20) {
                    $this->line('');
                    $this->info('📝 Word Details:');
                    foreach ($result['words'] as $word) {
                        $this->line(sprintf(
                            '  • %s (%.1f%% confidence)',
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
                $telegramResult = $this->speechService->processTelegramAudio($audioPath);
                $this->info('✅ Telegram audio processing successful!');
            }

            // Provide tips
            $this->line('');
            if (! $this->option('timestamps')) {
                $this->info('💡 Tip: Use --timestamps option to get word-level timing');
            }
            if (! $this->option('detect-language')) {
                $this->info('💡 Tip: Use --detect-language option to auto-detect the audio language');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Speech-to-Text failed: '.$e->getMessage());

            if (str_contains($e->getMessage(), 'credentials')) {
                $this->line('');
                $this->warn('Make sure Google Cloud credentials are configured:');
                $this->line('1. Create a service account in Google Cloud Console');
                $this->line('2. Enable Cloud Speech-to-Text API');
                $this->line('3. Download credentials JSON');
                $this->line('4. Save to: '.config('services.google_cloud.credentials_path'));
            } elseif (str_contains($e->getMessage(), 'Unsupported audio format')) {
                $this->line('');
                $this->warn('The audio format is not supported. Try converting to WAV:');
                $this->line('ffmpeg -i input.ogg -acodec pcm_s16le -ac 1 -ar 16000 output.wav');
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

        return round($bytes, $precision).' '.$units[$pow];
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
            return round($seconds, 1).' seconds';
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
