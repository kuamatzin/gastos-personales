<?php

namespace App\Telegram\Commands;

use App\Services\TelegramService;

class StartCommand
{
    private TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    public function handle(string $chatId, string $userId, array $params, array $message): void
    {
        $firstName = $message['from']['first_name'] ?? 'there';
        
        $welcomeMessage = "🎉 Welcome to ExpenseBot, {$firstName}!\n\n";
        $welcomeMessage .= "I'm here to help you track your expenses easily and efficiently.\n\n";
        $welcomeMessage .= "📝 *How to use me:*\n";
        $welcomeMessage .= "• Send a text message with your expense (e.g., \"50 tacos\")\n";
        $welcomeMessage .= "• Send a voice message describing your expense\n";
        $welcomeMessage .= "• Send a photo of your receipt\n\n";
        $welcomeMessage .= "💡 *Examples:*\n";
        $welcomeMessage .= "• `150 uber to airport`\n";
        $welcomeMessage .= "• `$45.50 lunch at restaurant`\n";
        $welcomeMessage .= "• `200 pesos groceries at walmart`\n\n";
        $welcomeMessage .= "🤖 I'll automatically categorize your expenses and sync them to your Google Sheets!\n\n";
        $welcomeMessage .= "Type /help to see all available commands.";

        $keyboard = [
            [
                ['text' => '📖 View Help', 'callback_data' => 'show_help'],
                ['text' => '🚀 Send First Expense', 'callback_data' => 'start_expense']
            ]
        ];

        $this->telegram->sendMessageWithKeyboard($chatId, $welcomeMessage, $keyboard);
    }
}