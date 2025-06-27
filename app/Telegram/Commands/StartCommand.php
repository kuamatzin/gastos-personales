<?php

namespace App\Telegram\Commands;

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
        if (! $this->user->started_at) {
            $this->user->update(['started_at' => now()]);
        }

        $this->logExecution('started');
    }
}
