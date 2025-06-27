<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Services\TelegramService;

class SetLanguageCommand extends Command
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    public function handle(array $message, User $user): void
    {
        $chatId = $message['chat']['id'];

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🇬🇧 English', 'callback_data' => 'language_en'],
                    ['text' => '🇪🇸 Español', 'callback_data' => 'language_es'],
                ],
            ],
        ];

        $this->telegram->sendMessage(
            $chatId,
            trans('telegram.language_selection', [], $user->language),
            $keyboard
        );
    }
}
