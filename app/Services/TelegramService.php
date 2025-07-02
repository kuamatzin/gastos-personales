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

            if (! $response->successful()) {
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
                'text_preview' => substr($text, 0, 100).'...',
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
            'inline_keyboard' => $keyboard,
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
    public function sendExpenseConfirmationWithCategory(string $chatId, array $expenseData, string $userLanguage = 'es'): array
    {
        $category = \App\Models\Category::find($expenseData['category_id']);
        $confidence = round($expenseData['category_confidence'] * 100);

        $message = trans('telegram.expense_detected', [], $userLanguage)."\n\n";
        $message .= trans('telegram.expense_amount', [
            'amount' => number_format($expenseData['amount'], 2),
            'currency' => $expenseData['currency'],
        ], $userLanguage)."\n";
        $message .= trans('telegram.expense_description', [
            'description' => $expenseData['description'],
        ], $userLanguage)."\n";
        $message .= trans('telegram.expense_date', [
            'date' => $expenseData['date'],
        ], $userLanguage)."\n";

        // Handle case where category is not found
        if (!$category) {
            // Use a default uncategorized category or create appropriate message
            $message .= trans('telegram.expense_category', [
                'icon' => '❓',
                'category' => trans('telegram.categories.uncategorized', [], $userLanguage),
            ], $userLanguage);
        } elseif ($expenseData['category_confidence'] < 0.9) {
            $message .= trans('telegram.expense_category_confidence', [
                'icon' => $category->icon ?? '📋',
                'category' => $category->getTranslatedName($userLanguage),
                'confidence' => $confidence,
            ], $userLanguage);
        } else {
            $message .= trans('telegram.expense_category', [
                'icon' => $category->icon ?? '📋',
                'category' => $category->getTranslatedName($userLanguage),
            ], $userLanguage);
        }

        if (isset($expenseData['merchant_name'])) {
            $message .= "\n".trans('telegram.expense_merchant', [
                'merchant' => $expenseData['merchant_name'],
            ], $userLanguage);
        }

        $message .= "\n\n".trans('telegram.expense_confirm_question', [], $userLanguage);

        $keyboard = [
            [
                ['text' => trans('telegram.button_confirm', [], $userLanguage), 'callback_data' => 'confirm_expense_'.$expenseData['expense_id']],
                ['text' => trans('telegram.button_edit_category', [], $userLanguage), 'callback_data' => 'edit_category_'.$expenseData['expense_id']],
            ],
            [
                ['text' => trans('telegram.button_edit_description', [], $userLanguage), 'callback_data' => 'edit_description_'.$expenseData['expense_id']],
                ['text' => trans('telegram.button_cancel', [], $userLanguage), 'callback_data' => 'cancel_expense_'.$expenseData['expense_id']],
            ],
        ];

        return $this->sendMessageWithKeyboard($chatId, $message, $keyboard);
    }

    /**
     * Send installment confirmation message
     */
    public function sendInstallmentConfirmation(string $chatId, array $expenseData, array $installmentData, string $userLanguage = 'es'): array
    {
        $message = trans('telegram.installment_detected', [], $userLanguage)."\n\n";
        
        // Show installment details
        $message .= trans('telegram.installment_total_amount', [
            'amount' => number_format($installmentData['total_amount'], 2),
            'currency' => $expenseData['currency'] ?? 'MXN',
        ], $userLanguage)."\n";
        
        $message .= trans('telegram.installment_monthly_payment', [
            'amount' => number_format($installmentData['monthly_amount'], 2),
            'currency' => $expenseData['currency'] ?? 'MXN',
        ], $userLanguage)."\n";
        
        $message .= trans('telegram.installment_months', [
            'months' => $installmentData['months'],
        ], $userLanguage)."\n";
        
        $interestType = $installmentData['has_interest'] 
            ? trans('telegram.installment_with_interest', [], $userLanguage)
            : trans('telegram.installment_without_interest', [], $userLanguage);
        
        $message .= trans('telegram.installment_interest', [
            'type' => $interestType,
        ], $userLanguage)."\n\n";
        
        // Show expense details
        $message .= trans('telegram.expense_description', [
            'description' => $expenseData['description'],
        ], $userLanguage)."\n";
        
        $category = \App\Models\Category::find($expenseData['category_id']);
        if ($category) {
            $message .= trans('telegram.expense_category', [
                'icon' => $category->icon ?? '📋',
                'category' => $category->getTranslatedName($userLanguage),
            ], $userLanguage)."\n";
        }
        
        $message .= "\n".trans('telegram.installment_confirm_question', [], $userLanguage);
        
        $keyboard = [
            [
                ['text' => trans('telegram.button_create_installment', [], $userLanguage), 
                 'callback_data' => 'create_installment_'.$expenseData['expense_id']],
            ],
            [
                ['text' => trans('telegram.button_reject_installment', [], $userLanguage), 
                 'callback_data' => 'reject_installment_'.$expenseData['expense_id']],
            ],
            [
                ['text' => trans('telegram.button_cancel', [], $userLanguage), 
                 'callback_data' => 'cancel_expense_'.$expenseData['expense_id']],
            ],
        ];
        
        return $this->sendMessageWithKeyboard($chatId, $message, $keyboard);
    }

    /**
     * Send category selection
     */
    public function sendCategorySelection(string $chatId, ?int $currentCategoryId = null, string $userLanguage = 'es'): array
    {
        $categories = \App\Models\Category::parents()->where('is_active', true)->get();
        $keyboard = [];

        foreach ($categories->chunk(2) as $chunk) {
            $row = [];
            foreach ($chunk as $category) {
                $icon = $category->icon ?? '📋';
                $text = $icon.' '.$category->getTranslatedName($userLanguage);

                if ($category->id == $currentCategoryId) {
                    $text = '✅ '.$text;
                }

                $row[] = [
                    'text' => $text,
                    'callback_data' => 'select_category_'.$category->id,
                ];
            }
            $keyboard[] = $row;
        }

        if ($currentCategoryId) {
            $keyboard[] = [
                ['text' => trans('telegram.button_view_subcategories', [], $userLanguage), 'callback_data' => 'show_subcategories_'.$currentCategoryId],
                ['text' => trans('telegram.button_back', [], $userLanguage), 'callback_data' => 'back_to_expense'],
            ];
        }

        return $this->sendMessageWithKeyboard($chatId, trans('telegram.button_select_category', [], $userLanguage), $keyboard);
    }

    /**
     * Get file from Telegram
     */
    public function getFile(string $fileId): ?array
    {
        $response = Http::get("{$this->apiUrl}/getFile", [
            'file_id' => $fileId,
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
            'action' => $action,
        ]);

        return $response->json();
    }

    /**
     * Send document to user
     */
    public function sendDocument(string $chatId, string $filePath, string $caption = '', array $options = []): array
    {
        try {
            $response = Http::attach(
                'document',
                file_get_contents($filePath),
                basename($filePath)
            )->post("{$this->apiUrl}/sendDocument", array_merge([
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'Markdown',
            ], $options));

            Log::info('Document sent via Telegram', [
                'chat_id' => $chatId,
                'file' => basename($filePath),
                'response' => $response->json(),
            ]);

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Failed to send document via Telegram', [
                'chat_id' => $chatId,
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
