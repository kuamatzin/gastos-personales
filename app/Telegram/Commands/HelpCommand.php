<?php

namespace App\Telegram\Commands;

use App\Services\TelegramService;

class HelpCommand
{
    private TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    public function handle(string $chatId, string $userId, array $params, array $message): void
    {
        $helpMessage = "📚 *ExpenseBot Help*\n\n";
        
        $helpMessage .= "*📝 Recording Expenses:*\n";
        $helpMessage .= "Simply send me a message with your expense details:\n";
        $helpMessage .= "• Text: `amount description`\n";
        $helpMessage .= "• Voice: Record a voice message\n";
        $helpMessage .= "• Photo: Send a receipt photo\n\n";
        
        $helpMessage .= "*💰 Format Examples:*\n";
        $helpMessage .= "• `50 tacos`\n";
        $helpMessage .= "• `$120.50 uber to airport`\n";
        $helpMessage .= "• `200 pesos starbucks coffee`\n";
        $helpMessage .= "• `lunch 85.50 mxn`\n\n";
        
        $helpMessage .= "*🏷️ Categories:*\n";
        $helpMessage .= "I'll automatically categorize your expenses:\n";
        $helpMessage .= "• 🍽️ Food & Dining\n";
        $helpMessage .= "• 🚗 Transportation\n";
        $helpMessage .= "• 🛍️ Shopping\n";
        $helpMessage .= "• 🎬 Entertainment\n";
        $helpMessage .= "• 🏥 Health & Wellness\n";
        $helpMessage .= "• And more!\n\n";
        
        $helpMessage .= "*📊 Commands:*\n";
        $helpMessage .= "/start - Welcome message\n";
        $helpMessage .= "/help - Show this help\n";
        $helpMessage .= "/expenses_today - Today's expenses *(coming soon)*\n";
        $helpMessage .= "/expenses_month - Monthly summary *(coming soon)*\n\n";
        
        $helpMessage .= "*💡 Tips:*\n";
        $helpMessage .= "• Be specific in descriptions for better categorization\n";
        $helpMessage .= "• Include merchant names when possible\n";
        $helpMessage .= "• Default currency is MXN\n";
        $helpMessage .= "• I learn from your corrections!\n\n";
        
        $helpMessage .= "Questions? Just ask! I'm here to help 🤖";

        $this->telegram->sendMessage($chatId, $helpMessage);
    }
}