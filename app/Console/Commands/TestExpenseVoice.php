<?php

namespace App\Console\Commands;

use App\Services\OpenAIService;
use App\Services\DateParserService;
use App\Services\SpeechToTextService;
use Illuminate\Console\Command;

class TestExpenseVoice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:expense-voice {text : Simulated voice transcription text} {--mock : Use mock responses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test voice expense processing with date parsing (simulates voice transcription)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $transcription = $this->argument('text');
        $this->info("Testing voice expense with transcription: \"{$transcription}\"");
        $this->line('');
        
        // Test date parsing first
        $dateParser = new DateParserService();
        $parsedDate = $dateParser->extractDateFromText($transcription);
        
        $this->info('📅 Date Parsing:');
        if ($parsedDate) {
            $this->line("   Date found: " . $parsedDate->format('Y-m-d (l)'));
            $this->line("   Relative: " . $dateParser->getRelativeDateDescription($parsedDate));
        } else {
            $this->line("   No date found - will use today");
        }
        $this->line('');
        
        // Test OpenAI extraction (or mock it)
        if ($this->option('mock')) {
            $this->info('🤖 Mock OpenAI Response:');
            $expenseData = $this->getMockResponse($transcription);
        } else {
            try {
                $this->info('🤖 OpenAI Extraction:');
                $openAI = new OpenAIService();
                $expenseData = $openAI->extractExpenseData($transcription);
            } catch (\Exception $e) {
                $this->error('OpenAI failed: ' . $e->getMessage());
                $this->line('Using mock response instead...');
                $expenseData = $this->getMockResponse($transcription);
            }
        }
        
        // Show extracted data
        $this->table(
            ['Field', 'Value'],
            [
                ['Amount', '$' . number_format($expenseData['amount'] ?? 0, 2)],
                ['Description', $expenseData['description'] ?? '-'],
                ['Category', $expenseData['category'] ?? '-'],
                ['Date (OpenAI)', $expenseData['date'] ?? 'Not found'],
                ['Merchant', $expenseData['merchant_name'] ?? '-'],
                ['Confidence', number_format(($expenseData['confidence'] ?? 0) * 100, 1) . '%'],
            ]
        );
        
        // Show date resolution
        $this->line('');
        $this->info('📊 Date Resolution (as would happen in ProcessExpenseVoice):');
        
        $openAIDate = $expenseData['date'] ?? now()->toDateString();
        $finalDate = $openAIDate;
        
        if ($parsedDate && ($openAIDate === now()->toDateString() || !isset($expenseData['date']))) {
            $finalDate = $parsedDate->toDateString();
            $this->line("   OpenAI date: {$openAIDate} (default)");
            $this->line("   Parser date: " . $parsedDate->toDateString() . " ✅ (using this)");
        } else {
            $this->line("   OpenAI date: {$openAIDate}");
            if ($parsedDate) {
                $this->line("   Parser date: " . $parsedDate->toDateString());
            }
        }
        
        $this->line("   Final date: {$finalDate}");
        
        // Test specific cases
        $this->line('');
        if (str_contains(strtolower($transcription), 'ayer')) {
            $this->info('✅ "ayer" (yesterday) detected in voice transcription!');
            $yesterday = now()->subDay()->toDateString();
            if ($finalDate === $yesterday) {
                $this->info('   Date correctly set to yesterday: ' . $yesterday);
            } else {
                $this->error('   ❌ Date not set to yesterday. Got: ' . $finalDate);
            }
        }
        
        if (str_contains(strtolower($transcription), 'antier') || str_contains(strtolower($transcription), 'anteayer')) {
            $this->info('✅ "antier/anteayer" (day before yesterday) detected!');
            $dayBeforeYesterday = now()->subDays(2)->toDateString();
            if ($finalDate === $dayBeforeYesterday) {
                $this->info('   Date correctly set to day before yesterday: ' . $dayBeforeYesterday);
            } else {
                $this->error('   ❌ Date not set correctly. Got: ' . $finalDate);
            }
        }
        
        // Simulate voice processing info
        $this->line('');
        $this->info('🎤 Simulated Voice Processing:');
        $this->line('   Input type: Voice message (OGG format)');
        $this->line('   Transcription confidence: ' . rand(85, 99) . '%');
        $this->line('   Language detected: Spanish');
        
        return Command::SUCCESS;
    }
    
    /**
     * Get mock response for testing
     */
    private function getMockResponse(string $text): array
    {
        // Extract amount from text
        preg_match('/(\d+(?:\.\d{2})?)(?:\s*(?:pesos|mxn|peso))?/i', $text, $matches);
        $amount = isset($matches[1]) ? (float)$matches[1] : 0;
        
        // Simple category inference
        $category = 'otros';
        $text_lower = strtolower($text);
        
        if (str_contains($text_lower, 'crepa') || str_contains($text_lower, 'comida') || 
            str_contains($text_lower, 'almuerzo') || str_contains($text_lower, 'desayuno') ||
            str_contains($text_lower, 'cena') || str_contains($text_lower, 'restaurante')) {
            $category = 'comida';
        } elseif (str_contains($text_lower, 'uber') || str_contains($text_lower, 'taxi') ||
                  str_contains($text_lower, 'transporte') || str_contains($text_lower, 'gasolina')) {
            $category = 'transporte';
        } elseif (str_contains($text_lower, 'super') || str_contains($text_lower, 'mercado') ||
                  str_contains($text_lower, 'despensa')) {
            $category = 'supermercado';
        }
        
        return [
            'amount' => $amount,
            'description' => trim(preg_replace('/\d+(?:\.\d{2})?\s*(?:pesos|mxn)?/i', '', $text)),
            'category' => $category,
            'category_confidence' => 0.8,
            'date' => now()->toDateString(), // Mock always returns today
            'confidence' => 0.9
        ];
    }
}