<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Exception;

class FileProcessingService
{
    private TelegramService $telegram;
    private ImageManager $imageManager;
    
    // Maximum file sizes in bytes
    private const MAX_IMAGE_SIZE = 20 * 1024 * 1024; // 20MB
    private const MAX_AUDIO_SIZE = 10 * 1024 * 1024; // 10MB
    
    // Supported formats
    private const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    private const SUPPORTED_AUDIO_FORMATS = ['ogg', 'mp3', 'wav', 'flac', 'oga'];
    
    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
        $this->imageManager = new ImageManager(new Driver());
    }
    
    /**
     * Download file from Telegram
     */
    public function downloadTelegramFile(string $fileId): string
    {
        try {
            // Get file info from Telegram
            $response = Http::timeout(30)
                ->get("https://api.telegram.org/bot{$this->telegram->getToken()}/getFile", [
                    'file_id' => $fileId
                ]);
            
            if (!$response->successful()) {
                throw new Exception('Failed to get file info from Telegram');
            }
            
            $fileData = $response->json();
            
            if (!isset($fileData['result']['file_path'])) {
                throw new Exception('Invalid file data from Telegram');
            }
            
            $filePath = $fileData['result']['file_path'];
            $fileSize = $fileData['result']['file_size'] ?? 0;
            
            // Check file size
            $this->validateFileSize($filePath, $fileSize);
            
            // Download file
            $fileUrl = "https://api.telegram.org/file/bot{$this->telegram->getToken()}/{$filePath}";
            $fileContent = Http::timeout(60)->get($fileUrl)->body();
            
            if (empty($fileContent)) {
                throw new Exception('Downloaded file is empty');
            }
            
            // Generate local file path
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $localFileName = Str::uuid() . '.' . $extension;
            $localPath = 'telegram-downloads/' . date('Y/m/d') . '/' . $localFileName;
            
            // Save file
            Storage::disk('local')->put($localPath, $fileContent);
            
            // Return full path
            return Storage::disk('local')->path($localPath);
            
        } catch (Exception $e) {
            Log::error('Failed to download Telegram file', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Preprocess image for better OCR results
     */
    public function preprocessImage(string $imagePath): string
    {
        try {
            // Validate image format
            $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            if (!in_array($extension, self::SUPPORTED_IMAGE_FORMATS)) {
                throw new Exception("Unsupported image format: {$extension}");
            }
            
            // Load image
            $image = $this->imageManager->read($imagePath);
            
            // Get dimensions
            $width = $image->width();
            $height = $image->height();
            
            // Resize if too large (maintain aspect ratio)
            if ($width > 2048 || $height > 2048) {
                $image->scale(width: 2048, height: 2048);
            }
            
            // Convert to grayscale for better OCR
            $image->greyscale();
            
            // Increase contrast
            $image->contrast(20);
            
            // Sharpen slightly
            $image->sharpen(10);
            
            // Save processed image
            $processedPath = str_replace('.' . $extension, '_processed.' . $extension, $imagePath);
            $image->save($processedPath, quality: 95);
            
            return $processedPath;
            
        } catch (Exception $e) {
            Log::error('Image preprocessing failed', [
                'path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            
            // Return original path if preprocessing fails
            return $imagePath;
        }
    }
    
    /**
     * Convert audio format
     */
    public function convertAudioFormat(string $inputPath, string $outputFormat = 'wav'): string
    {
        try {
            // Validate input format
            $inputExtension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
            if (!in_array($inputExtension, self::SUPPORTED_AUDIO_FORMATS)) {
                throw new Exception("Unsupported audio format: {$inputExtension}");
            }
            
            // Generate output path
            $outputPath = str_replace('.' . $inputExtension, '.' . $outputFormat, $inputPath);
            
            // Check if FFmpeg is available
            $ffmpegPath = config('services.ffmpeg_path', 'ffmpeg');
            
            // Build conversion command
            $command = sprintf(
                '%s -i %s -acodec pcm_s16le -ac 1 -ar 16000 %s -y 2>&1',
                escapeshellcmd($ffmpegPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath)
            );
            
            // Execute conversion
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('Audio conversion failed: ' . implode("\n", $output));
            }
            
            if (!file_exists($outputPath)) {
                throw new Exception('Converted audio file not found');
            }
            
            return $outputPath;
            
        } catch (Exception $e) {
            Log::error('Audio format conversion failed', [
                'input' => $inputPath,
                'format' => $outputFormat,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Clean up temporary files
     */
    public function cleanupTempFiles(array $filePaths = []): void
    {
        try {
            // Clean specific files
            foreach ($filePaths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                    Log::debug('Deleted temp file', ['path' => $path]);
                }
            }
            
            // Clean old telegram downloads (older than 24 hours)
            $cutoffTime = now()->subDay();
            $directories = Storage::disk('local')->directories('telegram-downloads');
            
            foreach ($directories as $dir) {
                $files = Storage::disk('local')->files($dir);
                
                foreach ($files as $file) {
                    $lastModified = Storage::disk('local')->lastModified($file);
                    
                    if ($lastModified < $cutoffTime->timestamp) {
                        Storage::disk('local')->delete($file);
                        Log::debug('Deleted old telegram file', ['file' => $file]);
                    }
                }
                
                // Remove empty directories
                if (empty(Storage::disk('local')->files($dir))) {
                    Storage::disk('local')->deleteDirectory($dir);
                }
            }
            
        } catch (Exception $e) {
            Log::warning('Cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Validate file size
     */
    private function validateFileSize(string $filePath, int $fileSize): void
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if (in_array($extension, self::SUPPORTED_IMAGE_FORMATS)) {
            if ($fileSize > self::MAX_IMAGE_SIZE) {
                throw new Exception('Image file too large. Maximum size is 20MB.');
            }
        } elseif (in_array($extension, self::SUPPORTED_AUDIO_FORMATS)) {
            if ($fileSize > self::MAX_AUDIO_SIZE) {
                throw new Exception('Audio file too large. Maximum size is 10MB.');
            }
        }
    }
    
    /**
     * Extract metadata from image
     */
    public function extractImageMetadata(string $imagePath): array
    {
        try {
            $image = $this->imageManager->read($imagePath);
            
            $metadata = [
                'width' => $image->width(),
                'height' => $image->height(),
                'mime_type' => $image->mime(),
                'size' => filesize($imagePath),
                'orientation' => $this->getImageOrientation($image)
            ];
            
            // Try to extract EXIF data if available
            if (function_exists('exif_read_data') && in_array($image->mime(), ['image/jpeg', 'image/tiff'])) {
                $exif = @exif_read_data($imagePath);
                
                if ($exif) {
                    $metadata['exif'] = [
                        'datetime' => $exif['DateTime'] ?? null,
                        'camera' => $exif['Model'] ?? null,
                        'location' => $this->extractGPSLocation($exif)
                    ];
                }
            }
            
            return $metadata;
            
        } catch (Exception $e) {
            Log::warning('Failed to extract image metadata', [
                'path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            
            return [
                'width' => null,
                'height' => null,
                'mime_type' => mime_content_type($imagePath),
                'size' => filesize($imagePath)
            ];
        }
    }
    
    /**
     * Get image orientation
     */
    private function getImageOrientation($image): string
    {
        $width = $image->width();
        $height = $image->height();
        
        if ($width > $height) {
            return 'landscape';
        } elseif ($height > $width) {
            return 'portrait';
        } else {
            return 'square';
        }
    }
    
    /**
     * Extract GPS location from EXIF data
     */
    private function extractGPSLocation(array $exif): ?array
    {
        if (!isset($exif['GPSLatitude']) || !isset($exif['GPSLongitude'])) {
            return null;
        }
        
        $lat = $this->convertGPSToDecimal(
            $exif['GPSLatitude'],
            $exif['GPSLatitudeRef'] ?? 'N'
        );
        
        $lng = $this->convertGPSToDecimal(
            $exif['GPSLongitude'],
            $exif['GPSLongitudeRef'] ?? 'E'
        );
        
        return [
            'latitude' => $lat,
            'longitude' => $lng
        ];
    }
    
    /**
     * Convert GPS coordinates to decimal
     */
    private function convertGPSToDecimal(array $coordinate, string $hemisphere): float
    {
        $degrees = $this->gpsToNumber($coordinate[0]);
        $minutes = $this->gpsToNumber($coordinate[1]);
        $seconds = $this->gpsToNumber($coordinate[2]);
        
        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal *= -1;
        }
        
        return $decimal;
    }
    
    /**
     * Convert GPS fraction to number
     */
    private function gpsToNumber($value): float
    {
        $parts = explode('/', $value);
        
        if (count($parts) === 2) {
            return floatval($parts[0]) / floatval($parts[1]);
        }
        
        return floatval($value);
    }
    
    /**
     * Estimate processing time based on file
     */
    public function estimateProcessingTime(string $filePath): int
    {
        $size = filesize($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // Base processing time in seconds
        $baseTime = 2;
        
        if (in_array($extension, self::SUPPORTED_IMAGE_FORMATS)) {
            // Images: ~1 second per MB
            $estimatedTime = $baseTime + ceil($size / (1024 * 1024));
            return min($estimatedTime, 10); // Cap at 10 seconds
        }
        
        if (in_array($extension, self::SUPPORTED_AUDIO_FORMATS)) {
            // Audio: ~2 seconds per MB (includes transcription)
            $estimatedTime = $baseTime + ceil(($size / (1024 * 1024)) * 2);
            return min($estimatedTime, 30); // Cap at 30 seconds
        }
        
        return $baseTime;
    }
}