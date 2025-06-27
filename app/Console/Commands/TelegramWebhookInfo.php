<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramWebhookInfo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:webhook:info';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get current Telegram webhook information';

    /**
     * Execute the console command.
     */
    public function handle(TelegramService $telegram)
    {
        $this->info('Fetching webhook information...');

        $result = $telegram->getWebhookInfo();

        if (! $result['ok'] ?? false) {
            $this->error('Failed to get webhook info');

            return;
        }

        $info = $result['result'];

        $this->info('📡 Webhook Information:');
        $this->table(
            ['Property', 'Value'],
            [
                ['URL', $info['url'] ?? 'Not set'],
                ['Has custom certificate', $info['has_custom_certificate'] ? 'Yes' : 'No'],
                ['Pending update count', $info['pending_update_count'] ?? 0],
                ['IP address', $info['ip_address'] ?? 'N/A'],
                ['Last error date', isset($info['last_error_date']) ? date('Y-m-d H:i:s', $info['last_error_date']) : 'N/A'],
                ['Last error message', $info['last_error_message'] ?? 'N/A'],
                ['Last sync error date', isset($info['last_synchronization_error_date']) ? date('Y-m-d H:i:s', $info['last_synchronization_error_date']) : 'N/A'],
                ['Max connections', $info['max_connections'] ?? 40],
                ['Allowed updates', implode(', ', $info['allowed_updates'] ?? [])],
            ]
        );
    }
}
