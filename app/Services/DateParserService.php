<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

class DateParserService
{
    /**
     * Spanish relative date mappings
     */
    private const SPANISH_RELATIVE_DATES = [
        'hoy' => 'today',
        'ayer' => 'yesterday',
        'antier' => '-2 days',
        'anteayer' => '-2 days',
        'mañana' => 'tomorrow',
        'pasado mañana' => '+2 days',
        'esta semana' => 'this week',
        'la semana pasada' => 'last week',
        'semana pasada' => 'last week',
        'este mes' => 'this month',
        'el mes pasado' => 'last month',
        'mes pasado' => 'last month',
        'este año' => 'this year',
        'el año pasado' => 'last year',
        'año pasado' => 'last year',
    ];
    
    /**
     * Spanish day names
     */
    private const SPANISH_DAYS = [
        'lunes' => 'monday',
        'martes' => 'tuesday',
        'miércoles' => 'wednesday',
        'miercoles' => 'wednesday',
        'jueves' => 'thursday',
        'viernes' => 'friday',
        'sábado' => 'saturday',
        'sabado' => 'saturday',
        'domingo' => 'sunday',
    ];
    
    /**
     * Spanish month names
     */
    private const SPANISH_MONTHS = [
        'enero' => 'january',
        'febrero' => 'february',
        'marzo' => 'march',
        'abril' => 'april',
        'mayo' => 'may',
        'junio' => 'june',
        'julio' => 'july',
        'agosto' => 'august',
        'septiembre' => 'september',
        'octubre' => 'october',
        'noviembre' => 'november',
        'diciembre' => 'december',
    ];
    
    /**
     * Parse date from text, handling relative dates
     */
    public function parseDate(string $text): ?Carbon
    {
        $text = Str::lower(trim($text));
        
        // Try exact date formats first
        $date = $this->tryExactDateFormats($text);
        if ($date) {
            return $date;
        }
        
        // Try relative dates
        $date = $this->tryRelativeDates($text);
        if ($date) {
            return $date;
        }
        
        // Try extracting date from larger text
        $date = $this->extractDateFromText($text);
        if ($date) {
            return $date;
        }
        
        return null;
    }
    
    /**
     * Extract date reference from text
     */
    public function extractDateFromText(string $text): ?Carbon
    {
        $text = Str::lower($text);
        
        // Look for relative date keywords
        foreach (self::SPANISH_RELATIVE_DATES as $spanish => $english) {
            if (str_contains($text, $spanish)) {
                return $this->parseRelativeDate($english);
            }
        }
        
        // Look for day names
        foreach (self::SPANISH_DAYS as $spanish => $english) {
            if (str_contains($text, $spanish)) {
                // Check if it's "last" or "next"
                if (str_contains($text, 'pasad')) {
                    return Carbon::parse("last {$english}");
                } elseif (str_contains($text, 'próxim') || str_contains($text, 'proxim')) {
                    return Carbon::parse("next {$english}");
                } else {
                    // Assume current week
                    $date = Carbon::parse($english);
                    // If the day has passed this week, assume last week
                    if ($date->isPast() && !$date->isToday()) {
                        return $date;
                    }
                    return $date;
                }
            }
        }
        
        // Look for date patterns
        $patterns = [
            // DD/MM/YYYY or DD-MM-YYYY
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/' => function($matches) {
                return Carbon::createFromFormat('d/m/Y', "{$matches[1]}/{$matches[2]}/{$matches[3]}");
            },
            // DD/MM/YY or DD-MM-YY
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})/' => function($matches) {
                $year = '20' . $matches[3];
                return Carbon::createFromFormat('d/m/Y', "{$matches[1]}/{$matches[2]}/{$year}");
            },
            // DD de MONTH
            '/(\d{1,2})\s+de\s+(\w+)/' => function($matches) {
                $month = $this->translateMonth($matches[2]);
                if ($month) {
                    $year = Carbon::now()->year;
                    $date = Carbon::parse("{$matches[1]} {$month} {$year}");
                    // If date is in future, assume last year
                    if ($date->isFuture()) {
                        $date->subYear();
                    }
                    return $date;
                }
                return null;
            },
            // "hace X días"
            '/hace\s+(\d+)\s+días?/' => function($matches) {
                return Carbon::now()->subDays((int)$matches[1]);
            },
            // "hace X semanas"
            '/hace\s+(\d+)\s+semanas?/' => function($matches) {
                return Carbon::now()->subWeeks((int)$matches[1]);
            },
        ];
        
        foreach ($patterns as $pattern => $handler) {
            if (preg_match($pattern, $text, $matches)) {
                $date = $handler($matches);
                if ($date instanceof Carbon) {
                    return $date;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Try exact date formats
     */
    private function tryExactDateFormats(string $text): ?Carbon
    {
        $formats = [
            'd/m/Y',
            'd-m-Y',
            'Y-m-d',
            'd/m/y',
            'd-m-y',
        ];
        
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $text);
            } catch (\Exception $e) {
                continue;
            }
        }
        
        return null;
    }
    
    /**
     * Try relative date parsing
     */
    private function tryRelativeDates(string $text): ?Carbon
    {
        // Direct relative date mapping
        if (isset(self::SPANISH_RELATIVE_DATES[$text])) {
            return $this->parseRelativeDate(self::SPANISH_RELATIVE_DATES[$text]);
        }
        
        // Try English relative dates
        try {
            return Carbon::parse($text);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Parse relative date string
     */
    private function parseRelativeDate(string $relative): Carbon
    {
        switch ($relative) {
            case 'today':
                return Carbon::today();
            case 'yesterday':
                return Carbon::yesterday();
            case 'tomorrow':
                return Carbon::tomorrow();
            case '-2 days':
                return Carbon::today()->subDays(2);
            case '+2 days':
                return Carbon::today()->addDays(2);
            case 'this week':
                return Carbon::now()->startOfWeek();
            case 'last week':
                return Carbon::now()->subWeek()->startOfWeek();
            case 'this month':
                return Carbon::now()->startOfMonth();
            case 'last month':
                return Carbon::now()->subMonth()->startOfMonth();
            case 'this year':
                return Carbon::now()->startOfYear();
            case 'last year':
                return Carbon::now()->subYear()->startOfYear();
            default:
                return Carbon::parse($relative);
        }
    }
    
    /**
     * Translate Spanish month to English
     */
    private function translateMonth(string $month): ?string
    {
        $month = Str::lower(trim($month));
        return self::SPANISH_MONTHS[$month] ?? null;
    }
    
    /**
     * Get relative date description in Spanish
     */
    public function getRelativeDateDescription(Carbon $date): string
    {
        $now = Carbon::now();
        
        if ($date->isToday()) {
            return 'hoy';
        } elseif ($date->isYesterday()) {
            return 'ayer';
        } elseif ($date->isTomorrow()) {
            return 'mañana';
        } elseif ($date->isSameDay($now->copy()->subDays(2))) {
            return 'antier';
        } elseif ($date->isSameWeek($now)) {
            return 'esta semana';
        } elseif ($date->isSameWeek($now->copy()->subWeek())) {
            return 'la semana pasada';
        } elseif ($date->isSameMonth($now)) {
            return 'este mes';
        } elseif ($date->isSameMonth($now->copy()->subMonth())) {
            return 'el mes pasado';
        } else {
            return $date->format('d/m/Y');
        }
    }
}