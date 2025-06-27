<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class SetupTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:webhook:set {url?} {--delete : Delete the webhook}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set or delete the Telegram webhook URL';

    /**
     * Execute the console command.
     */
    public function handle(TelegramService $telegram)
    {
        if ($this->option('delete')) {
            $this->deleteWebhook($telegram);
        } else {
            $this->setWebhook($telegram);
        }
    }

    private function setWebhook(TelegramService $telegram)
    {
        $url = $this->argument('url');

        if (! $url) {
            $appUrl = config('app.url');
            if (! $appUrl || $appUrl === 'http://localhost') {
                $this->error('Please provide a URL or set APP_URL in your .env file');
                $this->info('Example: php artisan telegram:webhook:set https://your-domain.com');

                return;
            }
            $url = rtrim($appUrl, '/').'/api/telegram/webhook';
        }

        $this->info("Setting webhook to: {$url}");

        $result = $telegram->setWebhook($url);

        if ($result['ok'] ?? false) {
            $this->info('✅ Webhook set successfully!');
            $this->table(
                ['Property', 'Value'],
                [
                    ['URL', $url],
                    ['Secret Token', config('services.telegram.webhook_secret') ? 'Set' : 'Not set'],
                ]
            );
        } else {
            $this->error('❌ Failed to set webhook');
            $this->error('Error: '.($result['description'] ?? 'Unknown error'));
        }
    }

    private function deleteWebhook(TelegramService $telegram)
    {
        $this->info('Deleting webhook...');

        $result = $telegram->deleteWebhook();

        if ($result['ok'] ?? false) {
            $this->info('✅ Webhook deleted successfully!');
        } else {
            $this->error('❌ Failed to delete webhook');
            $this->error('Error: '.($result['description'] ?? 'Unknown error'));
        }
    }
}
