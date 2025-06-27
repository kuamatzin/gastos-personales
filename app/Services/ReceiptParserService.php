<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;

class ReceiptParserService
{
    // Common merchant name patterns to clean up
    private const MERCHANT_CLEANUP_PATTERNS = [
        '/S\.A\.\s*DE\s*C\.V\.?$/i',
        '/S\.A\.?$/i',
        '/C\.V\.?$/i',
        '/RFC\s*:?\s*[A-Z0-9]+/i',
        '/\b(SUCURSAL|SUC|TIENDA|STORE)\s*#?\s*\d+/i',
    ];

    // Mexican tax rates
    private const IVA_RATE = 0.16; // 16% IVA

    private const IEPS_RATES = [0.08, 0.25, 0.30, 0.50]; // Common IEPS rates

    /**
     * Parse receipt data from OCR text
     */
    public function parseReceipt(string $ocrText): array
    {
        $lines = $this->preprocessText($ocrText);

        $result = [
            'merchant' => $this->extractMerchant($lines),
            'total' => $this->extractTotal($lines),
            'subtotal' => $this->extractSubtotal($lines),
            'tax' => $this->extractTax($lines),
            'date' => $this->extractDate($lines),
            'time' => $this->extractTime($lines),
            'items' => $this->extractLineItems($lines),
            'payment_method' => $this->extractPaymentMethod($lines),
            'reference_number' => $this->extractReferenceNumber($lines),
            'cashier' => $this->extractCashier($lines),
            'raw_text' => $ocrText,
            'confidence' => $this->calculateConfidence($lines),
        ];

        // Validate and fix totals
        $this->validateTotals($result);

        return $result;
    }

    /**
     * Preprocess text for better parsing
     */
    private function preprocessText(string $text): array
    {
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Split into lines
        $lines = explode("\n", $text);

        // Clean each line
        $cleanedLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (! empty($line)) {
                $cleanedLines[] = $line;
            }
        }

        return $cleanedLines;
    }

    /**
     * Extract merchant name
     */
    public function extractMerchant(array $lines): ?string
    {
        // Usually merchant name is in first few lines
        $candidateLines = array_slice($lines, 0, 5);

        foreach ($candidateLines as $line) {
            // Skip lines with obvious non-merchant content
            if ($this->isReceiptMetadata($line)) {
                continue;
            }

            // Clean up common suffixes
            $cleaned = $line;
            foreach (self::MERCHANT_CLEANUP_PATTERNS as $pattern) {
                $cleaned = preg_replace($pattern, '', $cleaned);
            }

            $cleaned = trim($cleaned);

            // Check if it looks like a merchant name
            if ($this->looksLikeMerchantName($cleaned)) {
                return $cleaned;
            }
        }

        // Fallback: return first non-metadata line
        foreach ($lines as $line) {
            if (! $this->isReceiptMetadata($line) && strlen($line) > 3) {
                return $this->cleanMerchantName($line);
            }
        }

        return null;
    }

    /**
     * Extract total amount
     */
    public function extractTotal(array $lines): ?float
    {
        $patterns = [
            '/TOTAL\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/TOTAL\s+A\s+PAGAR\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/IMPORTE\s+TOTAL\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/GRAN\s+TOTAL\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/^TOTAL\s+\$?\s*([\d,]+\.?\d*)$/i',
        ];

        // Search from bottom up (total usually at the end)
        $reversedLines = array_reverse($lines);

        foreach ($reversedLines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return $this->parseAmount($matches[1]);
                }
            }
        }

        // Try to find largest amount in last 10 lines
        $lastLines = array_slice($lines, -10);
        $amounts = [];

        foreach ($lastLines as $line) {
            if (preg_match('/\$?\s*([\d,]+\.?\d{2})/', $line, $matches)) {
                $amount = $this->parseAmount($matches[1]);
                if ($amount > 0) {
                    $amounts[] = $amount;
                }
            }
        }

        return ! empty($amounts) ? max($amounts) : null;
    }

    /**
     * Extract subtotal
     */
    public function extractSubtotal(array $lines): ?float
    {
        $patterns = [
            '/SUBTOTAL\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/SUB\s*TOTAL\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/SUMA\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/IMPORTE\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return $this->parseAmount($matches[1]);
                }
            }
        }

        return null;
    }

    /**
     * Extract tax amount
     */
    public function extractTax(array $lines): ?float
    {
        $patterns = [
            '/IVA\s*(?:\d+%?)?\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/I\.V\.A\.\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/IMPUESTO\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/TAX\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
            '/IEPS\s*:?\s*\$?\s*([\d,]+\.?\d*)/i',
        ];

        $totalTax = 0;

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $totalTax += $this->parseAmount($matches[1]);
                }
            }
        }

        return $totalTax > 0 ? $totalTax : null;
    }

    /**
     * Extract date
     */
    public function extractDate(array $lines): ?string
    {
        $patterns = [
            // DD/MM/YYYY or DD-MM-YYYY
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/',
            // YYYY/MM/DD or YYYY-MM-DD
            '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/',
            // DD/MM/YY or DD-MM-YY
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})/',
            // Mexican format: 15/ENE/2024
            '/(\d{1,2})[\/\-](ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|OCT|NOV|DIC)[\/\-](\d{4})/i',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return $this->formatDate($matches[0]);
                }
            }
        }

        return null;
    }

    /**
     * Extract time
     */
    public function extractTime(array $lines): ?string
    {
        $patterns = [
            '/(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM|HRS)?/i',
            '/HORA\s*:?\s*(\d{1,2}):(\d{2})/i',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return $matches[0];
                }
            }
        }

        return null;
    }

    /**
     * Extract line items
     */
    public function extractLineItems(array $lines): array
    {
        $items = [];
        $inItemSection = false;

        foreach ($lines as $line) {
            // Skip header and footer sections
            if ($this->isReceiptHeader($line) || $this->isReceiptFooter($line)) {
                $inItemSection = false;

                continue;
            }

            // Look for item patterns
            if ($this->looksLikeLineItem($line)) {
                $inItemSection = true;
                $item = $this->parseLineItem($line);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Extract payment method
     */
    public function extractPaymentMethod(array $lines): ?string
    {
        $patterns = [
            '/TARJETA\s*(?:DE\s*)?(CREDITO|CRÉDITO|DEBITO|DÉBITO)/i' => function ($matches) {
                return 'tarjeta_'.strtolower($this->removeAccents($matches[1]));
            },
            '/VISA|MASTERCARD|AMEX|AMERICAN\s*EXPRESS/i' => function ($matches) {
                return strtolower($matches[0]);
            },
            '/EFECTIVO|CASH/i' => function () {
                return 'efectivo';
            },
            '/TRANSFERENCIA|SPEI/i' => function () {
                return 'transferencia';
            },
            '/\*{4,}\d{4}/' => function () {
                return 'tarjeta';
            },
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern => $handler) {
                if (preg_match($pattern, $line, $matches)) {
                    return $handler($matches);
                }
            }
        }

        return null;
    }

    /**
     * Extract reference number
     */
    public function extractReferenceNumber(array $lines): ?string
    {
        $patterns = [
            '/(?:FOLIO|TICKET|REFERENCIA|REF|NO\.?)\s*:?\s*([A-Z0-9\-]+)/i',
            '/(?:TRANS|TRANSACCION|OPERACION)\s*:?\s*(\d+)/i',
            '/AUT(?:ORIZACION)?\s*:?\s*(\d+)/i',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Extract cashier information
     */
    public function extractCashier(array $lines): ?string
    {
        $patterns = [
            '/(?:CAJERO|CAJA|ATENDIO|LE\s*ATENDIO)\s*:?\s*(.+)/i',
            '/(?:VENDEDOR|EMPLEADO)\s*:?\s*(.+)/i',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $cashier = trim($matches[1]);
                    // Remove common suffixes
                    $cashier = preg_replace('/\s*\d+$/', '', $cashier);

                    return $cashier;
                }
            }
        }

        return null;
    }

    /**
     * Parse amount string to float
     */
    private function parseAmount(string $amount): float
    {
        // Remove thousands separator and normalize decimal point
        $amount = str_replace(',', '', $amount);
        $amount = str_replace(' ', '', $amount);

        return (float) $amount;
    }

    /**
     * Format date string
     */
    private function formatDate(string $dateStr): string
    {
        try {
            // Try to parse the date
            $date = Carbon::parse($dateStr);

            return $date->format('Y-m-d');
        } catch (Exception $e) {
            // Return original if parsing fails
            return $dateStr;
        }
    }

    /**
     * Check if line is receipt metadata
     */
    private function isReceiptMetadata(string $line): bool
    {
        $metadata = [
            'RFC', 'C.P.', 'CP:', 'TEL', 'TELEFONO', 'FAX',
            'DIRECCION', 'CALLE', 'COLONIA', 'MUNICIPIO',
            'ESTADO', 'MEXICO', 'FACTURA', 'TICKET',
        ];

        $upperLine = strtoupper($line);
        foreach ($metadata as $meta) {
            if (strpos($upperLine, $meta) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if line looks like merchant name
     */
    private function looksLikeMerchantName(string $line): bool
    {
        // Too short or too long
        if (strlen($line) < 3 || strlen($line) > 50) {
            return false;
        }

        // Contains only numbers or special characters
        if (! preg_match('/[A-Za-z]/', $line)) {
            return false;
        }

        // Contains price patterns
        if (preg_match('/\$\s*[\d.,]+/', $line)) {
            return false;
        }

        // Contains date patterns
        if (preg_match('/\d{1,2}[\/\-]\d{1,2}/', $line)) {
            return false;
        }

        return true;
    }

    /**
     * Clean merchant name
     */
    private function cleanMerchantName(string $name): string
    {
        // Remove common suffixes
        foreach (self::MERCHANT_CLEANUP_PATTERNS as $pattern) {
            $name = preg_replace($pattern, '', $name);
        }

        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Check if line is receipt header
     */
    private function isReceiptHeader(string $line): bool
    {
        $upperLine = strtoupper($line);

        return strpos($upperLine, 'BIENVENID') !== false ||
               strpos($upperLine, 'GRACIAS') !== false ||
               $this->isReceiptMetadata($line);
    }

    /**
     * Check if line is receipt footer
     */
    private function isReceiptFooter(string $line): bool
    {
        $upperLine = strtoupper($line);

        $footerPatterns = [
            'GRACIAS POR SU COMPRA',
            'GRACIAS POR SU PREFERENCIA',
            'CONSERVE SU TICKET',
            'CAMBIOS Y DEVOLUCIONES',
            'QUEJAS Y SUGERENCIAS',
            'FACTURACION',
            'IVA INCLUIDO',
        ];

        foreach ($footerPatterns as $pattern) {
            if (strpos($upperLine, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if line looks like a line item
     */
    private function looksLikeLineItem(string $line): bool
    {
        // Contains price pattern
        if (! preg_match('/\$?\s*\d+\.?\d{0,2}/', $line)) {
            return false;
        }

        // Not a total/subtotal line
        if (preg_match('/TOTAL|SUBTOTAL|IVA|TAX|CAMBIO|EFECTIVO/i', $line)) {
            return false;
        }

        return true;
    }

    /**
     * Parse line item
     */
    private function parseLineItem(string $line): ?array
    {
        // Try different patterns
        $patterns = [
            // Item name followed by price
            '/^(.+?)\s+\$?\s*(\d+\.?\d{0,2})$/',
            // Quantity, item, price
            '/^(\d+)\s+(.+?)\s+\$?\s*(\d+\.?\d{0,2})$/',
            // Item code, description, price
            '/^([A-Z0-9]+)\s+(.+?)\s+\$?\s*(\d+\.?\d{0,2})$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                if (count($matches) === 3) {
                    return [
                        'description' => trim($matches[1]),
                        'quantity' => 1,
                        'price' => $this->parseAmount($matches[2]),
                    ];
                } elseif (count($matches) === 4) {
                    return [
                        'description' => trim($matches[2]),
                        'quantity' => (int) $matches[1],
                        'price' => $this->parseAmount($matches[3]),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Validate and fix totals
     */
    private function validateTotals(array &$result): void
    {
        $total = $result['total'];
        $subtotal = $result['subtotal'];
        $tax = $result['tax'];

        // If we have subtotal and tax but no total
        if ($subtotal && $tax && ! $total) {
            $result['total'] = round($subtotal + $tax, 2);
        }

        // If we have total and subtotal but no tax
        if ($total && $subtotal && ! $tax) {
            $result['tax'] = round($total - $subtotal, 2);
        }

        // If we have total and tax but no subtotal
        if ($total && $tax && ! $subtotal) {
            $result['subtotal'] = round($total - $tax, 2);
        }

        // If we only have total, estimate subtotal and tax (16% IVA)
        if ($total && ! $subtotal && ! $tax) {
            $result['subtotal'] = round($total / (1 + self::IVA_RATE), 2);
            $result['tax'] = round($total - $result['subtotal'], 2);
        }
    }

    /**
     * Calculate parsing confidence
     */
    private function calculateConfidence(array $lines): float
    {
        $score = 0;
        $maxScore = 6;

        // Check for key elements
        if ($this->extractMerchant($lines)) {
            $score++;
        }
        if ($this->extractTotal($lines)) {
            $score++;
        }
        if ($this->extractDate($lines)) {
            $score++;
        }
        if ($this->extractPaymentMethod($lines)) {
            $score++;
        }
        if (count($this->extractLineItems($lines)) > 0) {
            $score++;
        }
        if ($this->extractReferenceNumber($lines)) {
            $score++;
        }

        return round($score / $maxScore, 2);
    }

    /**
     * Remove accents from text
     */
    private function removeAccents(string $text): string
    {
        $search = ['á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ'];
        $replace = ['a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N'];

        return str_replace($search, $replace, $text);
    }
}
