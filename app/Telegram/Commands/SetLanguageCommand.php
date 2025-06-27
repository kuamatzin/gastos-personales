<?php

namespace App\Telegram\Commands;

class SetLanguageCommand extends Command
{
    protected string $name = 'language';

    public function handle(array $message, string $params = ''): void
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
            $this->trans('telegram.language_selection'),
            ['reply_markup' => $keyboard]
        );
    }
}
