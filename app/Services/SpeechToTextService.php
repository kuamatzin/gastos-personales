<?php

namespace App\Services;

use Exception;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding;
use Google\Cloud\Speech\V1\SpeechClient;
use Illuminate\Support\Facades\Log;

class SpeechToTextService
{
    private ?SpeechClient $speech = null;

    // Supported audio formats and their configurations
    private const AUDIO_CONFIGS = [
        'ogg' => [
            'encoding' => AudioEncoding::OGG_OPUS,
            'sampleRate' => 48000,
        ],
        'oga' => [  // Telegram sometimes uses .oga extension
            'encoding' => AudioEncoding::OGG_OPUS,
            'sampleRate' => 48000,
        ],
        'mp3' => [
            'encoding' => AudioEncoding::MP3,
            'sampleRate' => 48000,
        ],
        'wav' => [
            'encoding' => AudioEncoding::LINEAR16,
            'sampleRate' => 48000,
        ],
        'flac' => [
            'encoding' => AudioEncoding::FLAC,
            'sampleRate' => 48000,
        ],
    ];

    public function __construct()
    {
        if (config('services.google_cloud.speech_enabled')) {
            $this->initializeClient();
        }
    }

    /**
     * Initialize Google Cloud Speech client
     */
    private function initializeClient(): void
    {
        try {
            $credentials = config('services.google_cloud.credentials_path');

            if (! file_exists($credentials)) {
                throw new Exception('Google Cloud credentials file not found');
            }

            $this->speech = new SpeechClient([
                'credentials' => $credentials,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to initialize Cloud Speech client', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Transcribe audio file to text
     */
    public function transcribeAudio(string $audioPath, string $languageCode = 'es-MX'): array
    {
        if (! $this->speech) {
            throw new Exception('Speech-to-Text service is not initialized');
        }

        try {
            // Get file info
            $fileInfo = pathinfo($audioPath);
            $extension = strtolower($fileInfo['extension'] ?? '');

            // Get audio configuration
            $audioConfig = $this->getAudioConfig($extension);

            // Read audio content
            $audioContent = file_get_contents($audioPath);
            if (! $audioContent) {
                throw new Exception('Failed to read audio file');
            }

            // Create audio object
            $audio = new RecognitionAudio;
            $audio->setContent($audioContent);

            // Configure recognition
            $config = new RecognitionConfig;
            $config->setEncoding($audioConfig['encoding']);
            $config->setSampleRateHertz($audioConfig['sampleRate']);
            $config->setLanguageCode($languageCode);
            $config->setEnableAutomaticPunctuation(true);
            $config->setModel('latest_long');
            $config->setUseEnhanced(true);

            // Add alternative language codes for better recognition
            if ($languageCode === 'es-MX') {
                $config->setAlternativeLanguageCodes(['es-ES', 'en-US']);
            } elseif ($languageCode === 'en-US') {
                $config->setAlternativeLanguageCodes(['en-GB', 'es-MX']);
            }

            // Perform the transcription
            $response = $this->speech->recognize($config, $audio);

            // Process results
            $transcript = '';
            $confidence = 0;
            $words = [];
            $resultCount = 0;

            foreach ($response->getResults() as $result) {
                $alternatives = $result->getAlternatives();
                if (count($alternatives) > 0) {
                    $alternative = $alternatives[0];
                    $transcript .= $alternative->getTranscript().' ';
                    $confidence += $alternative->getConfidence();
                    $resultCount++;

                    // Extract word-level information if available
                    foreach ($alternative->getWords() as $wordInfo) {
                        $words[] = [
                            'word' => $wordInfo->getWord(),
                            'startTime' => $wordInfo->getStartTime()->getSeconds() +
                                         ($wordInfo->getStartTime()->getNanos() / 1000000000),
                            'endTime' => $wordInfo->getEndTime()->getSeconds() +
                                       ($wordInfo->getEndTime()->getNanos() / 1000000000),
                            'confidence' => $wordInfo->getConfidence(),
                        ];
                    }
                }
            }

            // Calculate average confidence
            $averageConfidence = $resultCount > 0 ? $confidence / $resultCount : 0;

            return [
                'transcript' => trim($transcript),
                'confidence' => $averageConfidence,
                'language' => $languageCode,
                'words' => $words,
                'duration' => $this->getAudioDuration($audioPath),
            ];

        } catch (Exception $e) {
            Log::error('Audio transcription failed', [
                'audio_path' => $audioPath,
                'language' => $languageCode,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Detect language from audio
     */
    public function detectLanguage(string $audioPath): string
    {
        try {
            // Try transcribing with both languages and compare confidence
            $spanishResult = $this->transcribeAudio($audioPath, 'es-MX');
            $englishResult = $this->transcribeAudio($audioPath, 'en-US');

            // Return language with higher confidence
            return $spanishResult['confidence'] > $englishResult['confidence'] ? 'es-MX' : 'en-US';

        } catch (Exception $e) {
            Log::warning('Language detection failed, defaulting to Spanish', [
                'error' => $e->getMessage(),
            ]);

            return 'es-MX'; // Default to Spanish for Mexican context
        }
    }

    /**
     * Transcribe audio with word-level timestamps
     */
    public function transcribeWithTimestamps(string $audioPath, string $languageCode = 'es-MX'): array
    {
        if (! $this->speech) {
            throw new Exception('Speech-to-Text service is not initialized');
        }

        try {
            // Get file info
            $fileInfo = pathinfo($audioPath);
            $extension = strtolower($fileInfo['extension'] ?? '');

            // Get audio configuration
            $audioConfig = $this->getAudioConfig($extension);

            // Read audio content
            $audioContent = file_get_contents($audioPath);
            if (! $audioContent) {
                throw new Exception('Failed to read audio file');
            }

            // Create audio object
            $audio = new RecognitionAudio;
            $audio->setContent($audioContent);

            // Configure recognition with word time offsets
            $config = new RecognitionConfig;
            $config->setEncoding($audioConfig['encoding']);
            $config->setSampleRateHertz($audioConfig['sampleRate']);
            $config->setLanguageCode($languageCode);
            $config->setEnableAutomaticPunctuation(true);
            $config->setEnableWordTimeOffsets(true);
            $config->setModel('latest_long');

            // Perform the transcription
            $response = $this->speech->recognize($config, $audio);

            // Process results with timestamps
            $segments = [];

            foreach ($response->getResults() as $result) {
                $alternatives = $result->getAlternatives();
                if (count($alternatives) > 0) {
                    $alternative = $alternatives[0];

                    $segment = [
                        'transcript' => $alternative->getTranscript(),
                        'confidence' => $alternative->getConfidence(),
                        'words' => [],
                    ];

                    foreach ($alternative->getWords() as $wordInfo) {
                        $segment['words'][] = [
                            'word' => $wordInfo->getWord(),
                            'startTime' => $wordInfo->getStartTime()->getSeconds() +
                                         ($wordInfo->getStartTime()->getNanos() / 1000000000),
                            'endTime' => $wordInfo->getEndTime()->getSeconds() +
                                       ($wordInfo->getEndTime()->getNanos() / 1000000000),
                        ];
                    }

                    $segments[] = $segment;
                }
            }

            return [
                'segments' => $segments,
                'fullTranscript' => implode(' ', array_column($segments, 'transcript')),
                'language' => $languageCode,
            ];

        } catch (Exception $e) {
            Log::error('Timestamped transcription failed', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Convert audio format for compatibility
     */
    public function convertAudioFormat(string $inputPath, string $outputPath): bool
    {
        try {
            // Ensure FFmpeg is available
            $ffmpegPath = config('services.ffmpeg_path', 'ffmpeg');

            // Get output format
            $outputInfo = pathinfo($outputPath);
            $outputFormat = strtolower($outputInfo['extension'] ?? 'wav');

            // Build FFmpeg command
            $command = sprintf(
                '%s -i %s -acodec pcm_s16le -ac 1 -ar 16000 %s 2>&1',
                escapeshellcmd($ffmpegPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath)
            );

            // Execute conversion
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception('FFmpeg conversion failed: '.implode("\n", $output));
            }

            return file_exists($outputPath);

        } catch (Exception $e) {
            Log::error('Audio format conversion failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get audio configuration based on file extension
     */
    private function getAudioConfig(string $extension): array
    {
        if (! isset(self::AUDIO_CONFIGS[$extension])) {
            throw new Exception("Unsupported audio format: {$extension}");
        }

        return self::AUDIO_CONFIGS[$extension];
    }

    /**
     * Get audio duration in seconds
     */
    private function getAudioDuration(string $audioPath): ?float
    {
        try {
            $ffprobePath = config('services.ffprobe_path', 'ffprobe');

            $command = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellcmd($ffprobePath),
                escapeshellarg($audioPath)
            );

            $duration = trim(shell_exec($command));

            return is_numeric($duration) ? (float) $duration : null;

        } catch (Exception $e) {
            Log::warning('Failed to get audio duration', [
                'path' => $audioPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Process Telegram audio format (usually OGG)
     */
    public function processTelegramAudio(string $oggPath): array
    {
        // Telegram usually sends voice messages as OGG files
        // They typically use the OPUS codec at 48kHz

        try {
            // First, try direct transcription in Spanish
            $result = $this->transcribeAudio($oggPath, 'es-MX');

            // If Spanish fails, try English
            if (empty($result['transcript'])) {
                Log::info('Spanish transcription failed, trying English');
                $result = $this->transcribeAudio($oggPath, 'en-US');
            }

            if (empty($result['transcript'])) {
                // If direct transcription fails, try converting to WAV
                $wavPath = str_replace('.ogg', '.wav', $oggPath);
                $wavPath = str_replace('.oga', '.wav', $wavPath); // Handle .oga extension

                if ($this->convertAudioFormat($oggPath, $wavPath)) {
                    $result = $this->transcribeAudio($wavPath, 'es-MX');

                    // If Spanish fails, try English
                    if (empty($result['transcript'])) {
                        $result = $this->transcribeAudio($wavPath, 'en-US');
                    }

                    // Clean up converted file
                    if (file_exists($wavPath)) {
                        unlink($wavPath);
                    }
                }
            }

            return $result;

        } catch (Exception $e) {
            Log::error('Telegram audio processing failed', [
                'path' => $oggPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Clean up resources
     */
    public function __destruct()
    {
        if ($this->speech) {
            $this->speech->close();
        }
    }
}
