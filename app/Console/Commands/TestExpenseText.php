<?php

namespace App\Console\Commands;

use App\Services\OpenAIService;
use App\Services\DateParserService;
use Illuminate\Console\Command;

class TestExpenseText extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:expense-text {text : The expense text to process} {--mock : Use mock OpenAI response}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test expense text processing with date parsing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $text = $this->argument('text');
        $this->info("Testing expense text: \"{$text}\"");
        $this->line('');
        
        // Test date parsing first
        $dateParser = new DateParserService();
        $parsedDate = $dateParser->extractDateFromText($text);
        
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
            $expenseData = $this->getMockResponse($text);
        } else {
            try {
                $this->info('🤖 OpenAI Extraction:');
                $openAI = new OpenAIService();
                $expenseData = $openAI->extractExpenseData($text);
            } catch (\Exception $e) {
                $this->error('OpenAI failed: ' . $e->getMessage());
                $this->line('Using mock response instead...');
                $expenseData = $this->getMockResponse($text);
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
        
        // Show date comparison
        $this->line('');
        $this->info('📊 Date Resolution:');
        
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
        if (str_contains(strtolower($text), 'ayer')) {
            $this->line('');
            $this->info('✅ "ayer" (yesterday) detected correctly!');
            $yesterday = now()->subDay()->toDateString();
            if ($finalDate === $yesterday) {
                $this->info('   Date correctly set to yesterday: ' . $yesterday);
            } else {
                $this->error('   ❌ Date not set to yesterday. Got: ' . $finalDate);
            }
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Get mock response for testing
     */
    private function getMockResponse(string $text): array
    {
        // Extract amount from text
        preg_match('/(\d+(?:\.\d{2})?)\s*(?:pesos|mxn|peso)?/i', $text, $matches);
        $amount = isset($matches[1]) ? (float)$matches[1] : 0;
        
        // Simple category inference
        $category = 'otros';
        if (str_contains(strtolower($text), 'crepa') || str_contains(strtolower($text), 'comida')) {
            $category = 'comida';
        } elseif (str_contains(strtolower($text), 'uber') || str_contains(strtolower($text), 'taxi')) {
            $category = 'transporte';
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