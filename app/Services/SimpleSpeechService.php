<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class SimpleSpeechService
{
    /**
     * Transcribe audio using Google Cloud Speech API via HTTP
     */
    public function transcribeAudio(string $audioPath, string $languageCode = 'es-MX'): array
    {
        try {
            $apiKey = env('GOOGLE_CLOUD_API_KEY');
            if (! $apiKey) {
                throw new Exception('Google Cloud API key not configured. Set GOOGLE_CLOUD_API_KEY in .env');
            }

            // Read audio content
            $audioContent = file_get_contents($audioPath);
            if (! $audioContent) {
                throw new Exception('Failed to read audio file');
            }

            // Encode audio content as base64
            $audioBase64 = base64_encode($audioContent);

            // Determine audio encoding based on file extension
            $extension = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));
            $encoding = $this->getEncoding($extension);

            // Build request
            $request = [
                'config' => [
                    'encoding' => $encoding,
                    'sampleRateHertz' => 48000,
                    'languageCode' => $languageCode,
                    'enableAutomaticPunctuation' => true,
                    'model' => 'default',
                    'alternativeLanguageCodes' => $languageCode === 'es-MX' ? ['es-ES', 'en-US'] : ['en-GB', 'es-MX'],
                ],
                'audio' => [
                    'content' => $audioBase64,
                ],
            ];

            // Make API request
            $url = 'https://speech.googleapis.com/v1/speech:recognize?key='.$apiKey;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception('cURL error: '.$error);
            }

            if ($httpCode !== 200) {
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
                throw new Exception("API error (HTTP $httpCode): $errorMessage");
            }

            $data = json_decode($response, true);

            // Process results
            $transcript = '';
            $confidence = 0;
            $resultCount = 0;

            if (isset($data['results'])) {
                foreach ($data['results'] as $result) {
                    if (isset($result['alternatives'][0])) {
                        $alternative = $result['alternatives'][0];
                        $transcript .= $alternative['transcript'].' ';
                        $confidence += $alternative['confidence'] ?? 0.9;
                        $resultCount++;
                    }
                }
            }

            $averageConfidence = $resultCount > 0 ? $confidence / $resultCount : 0;

            return [
                'transcript' => trim($transcript),
                'confidence' => $averageConfidence,
                'language' => $languageCode,
                'words' => [],
                'duration' => null,
            ];

        } catch (Exception $e) {
            Log::error('Speech transcription failed', [
                'error' => $e->getMessage(),
                'audio_path' => $audioPath,
            ]);
            throw $e;
        }
    }

    /**
     * Get encoding string for API based on file extension
     */
    private function getEncoding(string $extension): string
    {
        $encodings = [
            'ogg' => 'OGG_OPUS',
            'mp3' => 'MP3',
            'wav' => 'LINEAR16',
            'flac' => 'FLAC',
            'm4a' => 'MP3',
            'opus' => 'OGG_OPUS',
        ];

        return $encodings[$extension] ?? 'MP3';
    }

    /**
     * Convert audio format using FFmpeg
     */
    public function convertAudioFormat(string $inputPath, string $outputPath): bool
    {
        try {
            $ffmpegPath = config('services.ffmpeg_path', 'ffmpeg');

            $command = sprintf(
                '%s -i %s -acodec pcm_s16le -ac 1 -ar 16000 %s 2>&1',
                escapeshellcmd($ffmpegPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath)
            );

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
     * Process Telegram audio (OGG format)
     */
    public function processTelegramAudio(string $oggPath): array
    {
        try {
            // First, try direct transcription
            $result = $this->transcribeAudio($oggPath, 'es-MX');

            if (empty($result['transcript'])) {
                // If direct transcription fails, try converting to WAV
                $wavPath = str_replace('.ogg', '.wav', $oggPath);

                if ($this->convertAudioFormat($oggPath, $wavPath)) {
                    $result = $this->transcribeAudio($wavPath, 'es-MX');

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
}
