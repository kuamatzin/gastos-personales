<?php

namespace App\Strategies;

interface ProcessingStrategy
{
    /**
     * Check if this strategy can process the given file type
     */
    public function canProcess(string $mimeType): bool;

    /**
     * Process the file and extract expense data
     */
    public function process(string $filePath): array;

    /**
     * Get supported MIME types for this strategy
     */
    public function getSupportedMimeTypes(): array;

    /**
     * Get strategy name for logging/debugging
     */
    public function getName(): string;
}
