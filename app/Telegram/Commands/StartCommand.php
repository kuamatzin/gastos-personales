<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Api;
use App\Models\User;

class StartCommand extends Command
{
    protected string $name = 'start';

    protected bool $requiresAuth = false;

    public function handle(array $message, string $params = ''): void
    {
        $this->sendTyping();

        $welcomeMessage = $this->trans('telegram.welcome');

        $this->replyWithMarkdown($welcomeMessage);

        // Update user's started_at if first time
        $isFirstTime = ! $this->user->started_at;
        if ($isFirstTime) {
            $this->user->update(['started_at' => now()]);
            
            // Send timezone setup prompt for new users
            $this->promptTimezoneSetup();
        }

        $this->logExecution('started', ['first_time' => $isFirstTime]);
    }
    
    /**
     * Prompt user to set up their timezone
     */
    private function promptTimezoneSetup(): void
    {
        $keyboard = [
            [
                ['text' => $this->trans('telegram.timezone_mexico_city'), 'callback_data' => 'set_timezone:America/Mexico_City'],
            ],
            [
                ['text' => $this->trans('telegram.timezone_tijuana'), 'callback_data' => 'set_timezone:America/Tijuana'],
            ],
            [
                ['text' => $this->trans('telegram.timezone_cancun'), 'callback_data' => 'set_timezone:America/Cancun'],
            ],
            [
                ['text' => $this->trans('telegram.timezone_configure_later'), 'callback_data' => 'cancel'],
            ],
        ];
        
        $message = $this->trans('telegram.timezone_setup_prompt');
        
        $this->replyWithKeyboard($message, $keyboard, ['parse_mode' => 'Markdown']);
    }
}
