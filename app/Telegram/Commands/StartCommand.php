<?php

namespace App\Telegram\Commands;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected bool $requiresAuth = false;
    
    public function handle(array $message, string $params = ''): void
    {
        $this->sendTyping();
        
        $firstName = $this->user->first_name ?? 'there';
        
        $welcomeMessage = "👋 Welcome {$firstName} to ExpenseBot!\n\n";
        $welcomeMessage .= "I'm here to help you track your expenses easily and efficiently.\n\n";
        $welcomeMessage .= "📸 *Send me a photo* of a receipt\n";
        $welcomeMessage .= "🎤 *Send me a voice note* describing your expense\n";
        $welcomeMessage .= "💬 *Send me a text* with expense details\n\n";
        $welcomeMessage .= "I'll automatically categorize your expenses and keep track of your spending!\n\n";
        $welcomeMessage .= "📊 Use /help to see all available commands.";
        
        // Create quick action keyboard
        $keyboard = [
            [
                ['text' => '📊 Today\'s Expenses', 'callback_data' => 'cmd_expenses_today'],
                ['text' => '📅 This Month', 'callback_data' => 'cmd_expenses_month']
            ],
            [
                ['text' => '📈 Statistics', 'callback_data' => 'cmd_stats'],
                ['text' => '❓ Help', 'callback_data' => 'cmd_help']
            ]
        ];
        
        $this->replyWithKeyboard($welcomeMessage, $keyboard, ['parse_mode' => 'Markdown']);
        
        // Update user's started_at if first time
        if (!$this->user->started_at) {
            $this->user->update(['started_at' => now()]);
        }
        
        $this->logExecution('started');
    }
}