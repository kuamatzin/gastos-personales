<?php

namespace App\Telegram\Commands;

class HelpCommand extends Command
{
    protected string $name = 'help';

    public function handle(array $message, string $params = ''): void
    {
        try {
            $this->sendTyping();

            // If specific command help requested
            if (! empty($params)) {
                $this->showCommandHelp($params);

                return;
            }

            // Show general help using localization
            $helpMessage = $this->trans('telegram.help');

            $this->replyWithMarkdown($helpMessage);

            $this->logExecution('general_help');

        } catch (\Exception $e) {
            $this->logError('Help command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply($this->trans('telegram.error_processing'));
        }
    }

    /**
     * Show help for specific command
     */
    private function showCommandHelp(string $command): void
    {
        // Remove leading slash if present
        $command = ltrim($command, '/');

        $helpTexts = [
            'expenses_today' => [
                'title' => '📊 Today\'s Expenses',
                'description' => 'Shows all expenses recorded today with totals by category.',
                'usage' => [
                    '/expenses_today - Show today\'s expenses',
                    '/hoy - Spanish alias',
                ],
                'examples' => [
                    '/expenses_today',
                    '/today',
                ],
            ],
            'expenses_month' => [
                'title' => '📅 Monthly Expenses',
                'description' => 'Shows expenses for the current or specified month with category breakdown and comparison to previous month.',
                'usage' => [
                    '/expenses_month - Current month',
                    '/expenses_month <month> - Specific month',
                    '/mes - Spanish alias',
                ],
                'examples' => [
                    '/expenses_month',
                    '/expenses_month january',
                    '/expenses_month enero',
                    '/mes marzo',
                ],
            ],
            'category_spending' => [
                'title' => '🏷️ Category Spending',
                'description' => 'Shows detailed spending breakdown by category with subcategories.',
                'usage' => [
                    '/category_spending - All categories',
                    '/category_spending <category> - Specific category details',
                ],
                'examples' => [
                    '/category_spending',
                    '/category_spending food',
                    '/categorias comida',
                ],
            ],
            'stats' => [
                'title' => '📈 Statistics',
                'description' => 'Shows spending trends, insights, and records.',
                'usage' => [
                    '/stats - General statistics',
                    '/stats <period> - Statistics for specific period',
                ],
                'examples' => [
                    '/stats',
                    '/stats week',
                    '/estadisticas',
                ],
            ],
            'export' => [
                'title' => '📤 Export Expenses',
                'description' => 'Export your expenses to Excel, PDF, or CSV format.',
                'usage' => [
                    '/export - Show export options',
                    '/export <format> <period> - Direct export',
                ],
                'examples' => [
                    '/export',
                    '/export excel month',
                    '/export csv today',
                ],
            ],
        ];

        $commandKey = str_replace('/', '', strtolower($command));

        if (isset($helpTexts[$commandKey])) {
            $help = $helpTexts[$commandKey];

            $message = "*{$help['title']}*\n\n";
            $message .= "{$help['description']}\n\n";

            $message .= "*Usage:*\n";
            foreach ($help['usage'] as $usage) {
                $message .= "• `{$usage}`\n";
            }
            $message .= "\n";

            $message .= "*Examples:*\n";
            foreach ($help['examples'] as $example) {
                $message .= "• `{$example}`\n";
            }

            // Try with Markdown first, fallback to plain text if it fails
            try {
                $this->reply($message, ['parse_mode' => 'Markdown']);
            } catch (\Exception $e) {
                $this->logError('Markdown parsing failed for command help', ['error' => $e->getMessage()]);

                // Send without markdown
                $plainMessage = str_replace(['*', '`', '_'], '', $message);
                $this->reply($plainMessage);
            }

        } else {
            $this->reply("❓ Unknown command: /{$command}\n\nType /help to see available commands.");
        }

        $this->logExecution('command_help', ['command' => $command]);
    }
}
