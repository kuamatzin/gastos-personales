<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $apiUrl;
    private string $token;
    private ?string $webhookSecret;

    public function __construct()
    {
        $this->token = config('services.telegram.token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
        $this->webhookSecret = config('services.telegram.webhook_secret');
    }

    /**
     * Set webhook URL for the bot
     */
    public function setWebhook(string $url): array
    {
        $response = Http::post("{$this->apiUrl}/setWebhook", [
            'url' => $url,
            'secret_token' => $this->webhookSecret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        return $response->json();
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook(): array
    {
        $response = Http::post("{$this->apiUrl}/deleteWebhook");
        return $response->json();
    }

    /**
     * Get webhook info
     */
    public function getWebhookInfo(): array
    {
        $response = Http::get("{$this->apiUrl}/getWebhookInfo");
        return $response->json();
    }

    /**
     * Send a text message
     */
    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ], $options);

        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", $params);
            
            $result = $response->json();
            
            if (!$response->successful()) {
                Log::error('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'response' => $result,
                    'params' => $params,
                    'text_length' => strlen($text),
                ]);
                
                // Throw exception so caller can handle it
                throw new \Exception($result['description'] ?? 'Unknown Telegram error');
            }

            return $result;
            
        } catch (\Exception $e) {
            Log::error('Telegram sendMessage exception', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
                'text_preview' => substr($text, 0, 100) . '...',
            ]);
            
            throw $e;
        }
    }

    /**
     * Send a message with inline keyboard
     */
    public function sendMessageWithKeyboard(string $chatId, string $text, array $keyboard, array $options = []): array
    {
        $options['reply_markup'] = json_encode([
            'inline_keyboard' => $keyboard
        ]);

        return $this->sendMessage($chatId, $text, $options);
    }

    /**
     * Edit an existing message
     */
    public function editMessage(string $chatId, int $messageId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ], $options);

        $response = Http::post("{$this->apiUrl}/editMessageText", $params);
        return $response->json();
    }

    /**
     * Delete a message
     */
    public function deleteMessage(string $chatId, int $messageId): array
    {
        $response = Http::post("{$this->apiUrl}/deleteMessage", [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);

        return $response->json();
    }

    /**
     * Answer callback query
     */
    public function answerCallbackQuery(string $callbackId, array $options = []): array
    {
        $params = array_merge([
            'callback_query_id' => $callbackId,
        ], $options);

        $response = Http::post("{$this->apiUrl}/answerCallbackQuery", $params);
        return $response->json();
    }

    /**
     * Send expense confirmation with category
     */
    public function sendExpenseConfirmationWithCategory(string $chatId, array $expenseData): array
    {
        $category = \App\Models\Category::find($expenseData['category_id']);
        $confidence = round($expenseData['category_confidence'] * 100);

        $message = "💰 *Expense detected!*\n\n";
        $message .= "💵 *Amount:* $" . number_format($expenseData['amount'], 2) . " " . $expenseData['currency'] . "\n";
        $message .= "📝 *Description:* " . $expenseData['description'] . "\n";
        $message .= "📅 *Date:* " . $expenseData['date'] . "\n";
        $message .= "🏷️ *Category:* " . ($category->icon ?? '📋') . " " . $category->name;

        if ($expenseData['category_confidence'] < 0.9) {
            $message .= " *(Confidence: {$confidence}%)*";
        }

        if (isset($expenseData['merchant_name'])) {
            $message .= "\n🏪 *Merchant:* " . $expenseData['merchant_name'];
        }

        $message .= "\n\nIs this correct?";

        $keyboard = [
            [
                ['text' => '✅ Confirm', 'callback_data' => 'confirm_expense'],
                ['text' => '✏️ Edit Category', 'callback_data' => 'edit_category']
            ],
            [
                ['text' => '📝 Edit Description', 'callback_data' => 'edit_description'],
                ['text' => '❌ Cancel', 'callback_data' => 'cancel_expense']
            ]
        ];

        return $this->sendMessageWithKeyboard($chatId, $message, $keyboard);
    }

    /**
     * Send category selection
     */
    public function sendCategorySelection(string $chatId, ?int $currentCategoryId = null): array
    {
        $categories = \App\Models\Category::parents()->where('is_active', true)->get();
        $keyboard = [];

        foreach ($categories->chunk(2) as $chunk) {
            $row = [];
            foreach ($chunk as $category) {
                $icon = $category->icon ?? '📋';
                $text = $icon . ' ' . $category->name;

                if ($category->id == $currentCategoryId) {
                    $text = '✅ ' . $text;
                }

                $row[] = [
                    'text' => $text,
                    'callback_data' => 'select_category_' . $category->id
                ];
            }
            $keyboard[] = $row;
        }

        if ($currentCategoryId) {
            $keyboard[] = [
                ['text' => '🔍 View Subcategories', 'callback_data' => 'show_subcategories_' . $currentCategoryId],
                ['text' => '↩️ Back', 'callback_data' => 'back_to_expense']
            ];
        }

        return $this->sendMessageWithKeyboard($chatId, "🏷️ *Select a category:*", $keyboard);
    }

    /**
     * Get file from Telegram
     */
    public function getFile(string $fileId): ?array
    {
        $response = Http::get("{$this->apiUrl}/getFile", [
            'file_id' => $fileId
        ]);

        if ($response->successful()) {
            $result = $response->json();
            if ($result['ok']) {
                return $result['result'];
            }
        }

        return null;
    }

    /**
     * Download file from Telegram
     */
    public function downloadFile(string $filePath): ?string
    {
        $fileUrl = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        $response = Http::get($fileUrl);

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }
    
    /**
     * Get bot token (for file downloads)
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Send chat action (typing indicator)
     */
    public function sendChatAction(string $chatId, string $action = 'typing'): array
    {
        $response = Http::post("{$this->apiUrl}/sendChatAction", [
            'chat_id' => $chatId,
            'action' => $action
        ]);

        return $response->json();
    }
}