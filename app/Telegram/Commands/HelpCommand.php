<?php

namespace App\Telegram\Commands;

use App\Telegram\CommandRouter;

class HelpCommand extends Command
{
    protected string $name = 'help';
    
    public function handle(array $message, string $params = ''): void
    {
        $this->sendTyping();
        
        // If specific command help requested
        if (!empty($params)) {
            $this->showCommandHelp($params);
            return;
        }
        
        // Show general help
        $helpMessage = "📚 *ExpenseBot Commands*\n\n";
        $helpMessage .= "*Basic Usage:*\n";
        $helpMessage .= "• Send text: `50 coffee at starbucks`\n";
        $helpMessage .= "• Send voice note with expense details\n";
        $helpMessage .= "• Send photo of receipt\n\n";
        
        $helpMessage .= "*📊 Expense Commands:*\n";
        $helpMessage .= "/expenses_today - Today's expenses\n";
        $helpMessage .= "/expenses_week - This week's expenses\n";
        $helpMessage .= "/expenses_month - This month's expenses\n\n";
        
        $helpMessage .= "*📈 Analytics Commands:*\n";
        $helpMessage .= "/category_spending - Spending by category\n";
        $helpMessage .= "/top_categories - Top spending categories\n";
        $helpMessage .= "/stats - Statistics and insights\n\n";
        
        $helpMessage .= "*📤 Other Commands:*\n";
        $helpMessage .= "/export - Export expenses to file\n";
        $helpMessage .= "/help - Show this help\n";
        $helpMessage .= "/cancel - Cancel current operation\n\n";
        
        $helpMessage .= "*💡 Tips:*\n";
        $helpMessage .= "• Use natural language for expenses\n";
        $helpMessage .= "• Include merchant names for better categorization\n";
        $helpMessage .= "• Voice notes work in Spanish and English\n\n";
        
        $helpMessage .= "Type `/help <command>` for detailed help on any command.";
        
        $this->reply($helpMessage, ['parse_mode' => 'Markdown']);
        
        $this->logExecution('general_help');
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
                    '/hoy - Spanish alias'
                ],
                'examples' => [
                    '/expenses_today',
                    '/today'
                ]
            ],
            'expenses_month' => [
                'title' => '📅 Monthly Expenses',
                'description' => 'Shows expenses for the current or specified month with category breakdown and comparison to previous month.',
                'usage' => [
                    '/expenses_month - Current month',
                    '/expenses_month <month> - Specific month',
                    '/mes - Spanish alias'
                ],
                'examples' => [
                    '/expenses_month',
                    '/expenses_month january',
                    '/expenses_month enero',
                    '/mes marzo'
                ]
            ],
            'category_spending' => [
                'title' => '🏷️ Category Spending',
                'description' => 'Shows detailed spending breakdown by category with subcategories.',
                'usage' => [
                    '/category_spending - All categories',
                    '/category_spending <category> - Specific category details'
                ],
                'examples' => [
                    '/category_spending',
                    '/category_spending food',
                    '/categorias comida'
                ]
            ],
            'stats' => [
                'title' => '📈 Statistics',
                'description' => 'Shows spending trends, insights, and records.',
                'usage' => [
                    '/stats - General statistics',
                    '/stats <period> - Statistics for specific period'
                ],
                'examples' => [
                    '/stats',
                    '/stats week',
                    '/estadisticas'
                ]
            ],
            'export' => [
                'title' => '📤 Export Expenses',
                'description' => 'Export your expenses to Excel, PDF, or CSV format.',
                'usage' => [
                    '/export - Show export options',
                    '/export <format> <period> - Direct export'
                ],
                'examples' => [
                    '/export',
                    '/export excel month',
                    '/export csv today'
                ]
            ]
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
            
            $this->reply($message, ['parse_mode' => 'Markdown']);
            
        } else {
            $this->reply("❓ Unknown command: /{$command}\n\nType /help to see available commands.");
        }
        
        $this->logExecution('command_help', ['command' => $command]);
    }
}