<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExpenseText;
use App\Models\User;
use App\Services\TelegramService;
use App\Telegram\CommandRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    private TelegramService $telegram;
    private CommandRouter $commandRouter;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
        $this->commandRouter = new CommandRouter($telegram);
    }

    public function handle(Request $request)
    {
        // Add logging
        Log::info('Testing webhook');
        Log::info('Telegram webhook request', ['request' => $request->all()]);

        // Verify webhook secret
        if ($request->header('X-Telegram-Bot-Api-Secret-Token') !== config('services.telegram.webhook_secret')) {
            Log::warning('Invalid Telegram webhook secret');
            return response('Unauthorized', 401);
        }

        $update = $request->all();
        Log::info('Telegram webhook received', ['update' => $update]);

        // Handle different update types
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }

        return response('OK', 200);
    }

    private function handleMessage(array $message)
    {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];

        // Register or update user
        $user = $this->registerOrUpdateUser($message['from']);

        // Check if user is active
        if (!$user->is_active) {
            $this->telegram->sendMessage($chatId, "⚠️ Your account is currently inactive. Please contact support.");
            return;
        }

        // Handle different message types
        if (isset($message['text'])) {
            $text = $message['text'];

            // Handle commands
            if (str_starts_with($text, '/')) {
                $this->handleCommand($chatId, $userId, $text, $message, $user);
            } else {
                // Process as expense
                $this->processTextExpense($chatId, $userId, $text, $message['message_id']);
            }
        } elseif (isset($message['voice'])) {
            $this->processVoiceExpense($chatId, $userId, $message['voice'], $message['message_id']);
        } elseif (isset($message['photo'])) {
            $this->processPhotoExpense($chatId, $userId, $message['photo'], $message['message_id']);
        } else {
            $this->telegram->sendMessage($chatId, "❓ Sorry, I don't understand that type of message. Please send text, voice, or photo.");
        }
    }

    private function handleCommand(string $chatId, string $userId, string $command, array $message, User $user)
    {
        // Use the CommandRouter to handle all commands
        $handled = $this->commandRouter->route($message, $user);
        
        if (!$handled) {
            // Command not found
            $this->telegram->sendMessage($chatId, "❓ Unknown command. Type /help for available commands.");
        }
    }

    private function handleCallbackQuery(array $callbackQuery)
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $data = $callbackQuery['data'];
        $callbackId = $callbackQuery['id'];

        // Answer the callback to remove loading state
        $this->telegram->answerCallbackQuery($callbackId);

        // Handle different callback data
        if ($data === 'confirm_expense') {
            $this->confirmExpense($chatId, $messageId);
        } elseif ($data === 'cancel_expense') {
            $this->cancelExpense($chatId, $messageId);
        } elseif (str_starts_with($data, 'select_category_')) {
            $categoryId = str_replace('select_category_', '', $data);
            $this->selectCategory($chatId, $messageId, $categoryId);
        } elseif ($data === 'edit_category') {
            $this->editCategory($chatId, $messageId);
        }
        // Add more callback handlers as needed
    }

    private function registerOrUpdateUser(array $telegramUser): \App\Models\User
    {
        $user = \App\Models\User::firstOrNew(['telegram_id' => $telegramUser['id']]);

        $user->telegram_username = $telegramUser['username'] ?? null;
        $user->telegram_first_name = $telegramUser['first_name'] ?? null;
        $user->telegram_last_name = $telegramUser['last_name'] ?? null;

        if (!$user->exists) {
            $user->name = trim(($telegramUser['first_name'] ?? '') . ' ' . ($telegramUser['last_name'] ?? ''));
            $user->email = 'telegram_' . $telegramUser['id'] . '@expensebot.local';
            $user->password = bcrypt(str()->random(32));
            $user->is_active = true;
        }

        $user->save();

        return $user;
    }

    private function processTextExpense(string $chatId, string $userId, string $text, int $messageId)
    {
        Log::info('Processing text expense', ['chatId' => $chatId, 'userId' => $userId, 'text' => $text]);
        
        // Send processing message
        $processingMessage = $this->telegram->sendMessage($chatId, "🔄 Processing your expense...");
        Log::info('Processing message sent', ['result' => $processingMessage]);

        // Queue the job
        try {
            ProcessExpenseText::dispatch($userId, $text, $messageId)
                ->onQueue('high');
            Log::info('Job dispatched successfully');
        } catch (\Exception $e) {
            Log::error('Failed to dispatch job', ['error' => $e->getMessage()]);
        }

        // Delete processing message after a delay
        ProcessExpenseText::dispatch($userId, 'delete_message', $processingMessage['result']['message_id'])
            ->delay(now()->addSeconds(2))
            ->onQueue('low');
    }

    private function processVoiceExpense(string $chatId, string $userId, array $voice, int $messageId)
    {
        $this->telegram->sendMessage($chatId, "🎤 Voice processing will be implemented soon!");
        // TODO: Implement voice processing
    }

    private function processPhotoExpense(string $chatId, string $userId, array $photos, int $messageId)
    {
        $this->telegram->sendMessage($chatId, "📷 Photo processing will be implemented soon!");
        // TODO: Implement photo processing
    }

    private function confirmExpense(string $chatId, int $messageId)
    {
        // TODO: Implement expense confirmation
        $this->telegram->editMessage($chatId, $messageId, "✅ Expense confirmed and saved!");
    }

    private function cancelExpense(string $chatId, int $messageId)
    {
        $this->telegram->editMessage($chatId, $messageId, "❌ Expense cancelled.");
    }

    private function selectCategory(string $chatId, int $messageId, string $categoryId)
    {
        // TODO: Implement category selection
        $this->telegram->editMessage($chatId, $messageId, "✅ Category updated!");
    }

    private function editCategory(string $chatId, int $messageId)
    {
        $this->telegram->sendCategorySelection($chatId);
    }
}
