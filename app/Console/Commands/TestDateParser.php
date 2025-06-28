<?php

namespace App\Console\Commands;

use App\Services\DateParserService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class TestDateParser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:date-parser {text? : Text containing date reference}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test date parsing functionality';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $parser = new DateParserService();
        
        if ($text = $this->argument('text')) {
            // Test specific text
            $this->testText($parser, $text);
        } else {
            // Test common cases
            $this->testCommonCases($parser);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Test specific text
     */
    private function testText(DateParserService $parser, string $text): void
    {
        $this->info("Testing: \"{$text}\"");
        
        $date = $parser->extractDateFromText($text);
        
        if ($date) {
            $this->info("✅ Date found: " . $date->format('Y-m-d (l)'));
            $this->info("   Relative: " . $parser->getRelativeDateDescription($date));
        } else {
            $this->warn("❌ No date found");
        }
    }
    
    /**
     * Test common cases
     */
    private function testCommonCases(DateParserService $parser): void
    {
        $this->info('Testing common Spanish date references:');
        $this->line('');
        
        $testCases = [
            // Your specific case
            'Gasto por 79 pesos el día de ayer de una crepa',
            'Compré algo ayer',
            'La compra fue ayer por la tarde',
            
            // Other relative dates
            'Gasté 100 pesos hoy',
            'Compra de antier',
            'Cené anteayer',
            'Voy a comprar mañana',
            'Compré el lunes pasado',
            'Fui al doctor la semana pasada',
            'Pagué la renta el mes pasado',
            
            // Specific dates
            'Compra del 25/12/2024',
            'Gasto del día 15 de diciembre',
            'Pagué el 01-01-2024',
            
            // Time expressions
            'Hace 3 días compré medicinas',
            'Hace una semana fui al cine',
            'Gasté hace 2 semanas',
            
            // No date
            'Compré tacos',
            'Gasté 50 pesos en café',
        ];
        
        $results = [];
        
        foreach ($testCases as $text) {
            $date = $parser->extractDateFromText($text);
            
            $results[] = [
                'Text' => strlen($text) > 40 ? substr($text, 0, 37) . '...' : $text,
                'Date Found' => $date ? $date->format('Y-m-d') : '❌ Not found',
                'Relative' => $date ? $parser->getRelativeDateDescription($date) : '-',
                'Status' => $date ? '✅' : '❌'
            ];
        }
        
        $this->table(
            ['Text', 'Date Found', 'Relative', 'Status'],
            $results
        );
        
        // Test the specific problematic case
        $this->line('');
        $this->info('Detailed test of the reported issue:');
        $this->line('');
        
        $problemText = "Gasto por 79 pesos el día de ayer de una crepa";
        $this->info("Input: \"{$problemText}\"");
        
        $date = $parser->extractDateFromText($problemText);
        
        if ($date) {
            $this->info("✅ SUCCESS: Date correctly parsed as " . $date->format('Y-m-d'));
            $this->info("   Today is: " . Carbon::today()->format('Y-m-d'));
            $this->info("   Yesterday was: " . Carbon::yesterday()->format('Y-m-d'));
            $this->info("   Parsed date matches yesterday: " . ($date->isSameDay(Carbon::yesterday()) ? 'YES ✅' : 'NO ❌'));
        } else {
            $this->error("❌ FAILED: Could not parse date from text");
        }
    }
}