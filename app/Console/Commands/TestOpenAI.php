<?php

namespace App\Console\Commands;

use App\Services\OpenAIService;
use Illuminate\Console\Command;

class TestOpenAI extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openai:test {text? : The expense text to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test OpenAI expense extraction';

    /**
     * Execute the console command.
     */
    public function handle(OpenAIService $openAI)
    {
        $text = $this->argument('text') ?? '150 tacos en el restaurante de la esquina';

        $this->info("Testing OpenAI with: \"{$text}\"");
        $this->info('Processing...');

        try {
            // Test expense extraction
            $result = $openAI->extractExpenseData($text);

            $this->info("\n✅ Expense Data Extracted:");

            // Get category name if ID exists
            $categoryDisplay = 'Not inferred';
            if (isset($result['category_id'])) {
                $category = \App\Models\Category::find($result['category_id']);
                if ($category) {
                    $categoryDisplay = ($category->icon ?? '📋').' '.$category->name;
                    if ($category->parent) {
                        $categoryDisplay = $category->parent->icon.' '.$category->parent->name.' > '.$category->name;
                    }
                }
            }

            $this->table(
                ['Field', 'Value'],
                [
                    ['Amount', '$'.number_format($result['amount'], 2).' '.$result['currency']],
                    ['Description', $result['description']],
                    ['Date', $result['date']],
                    ['Merchant', $result['merchant_name'] ?? 'N/A'],
                    ['Category', $categoryDisplay],
                    ['Category Confidence', isset($result['category_confidence']) ? round($result['category_confidence'] * 100).'%' : 'N/A'],
                    ['Overall Confidence', round($result['confidence'] * 100).'%'],
                ]
            );

            // Test category inference if no category was found
            if (! isset($result['category_id'])) {
                $this->info("\n🏷️ Testing Category Inference...");
                $categoryResult = $openAI->inferCategory($result['description'], $result['amount']);

                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Category ID', $categoryResult['category_id'] ?? 'None'],
                        ['Confidence', round($categoryResult['confidence'] * 100).'%'],
                        ['Reasoning', $categoryResult['reasoning']],
                    ]
                );
            }

        } catch (\Exception $e) {
            $this->error('❌ Error: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
        }
    }
}
