<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExpenseText;
use App\Models\User;
use App\Models\Expense;
use App\Services\TelegramService;
use App\Services\CategoryLearningService;
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
        $userId = $callbackQuery['from']['id'];

        // Answer the callback to remove loading state
        $this->telegram->answerCallbackQuery($callbackId);

        // Handle different callback data
        if (str_starts_with($data, 'confirm_expense_')) {
            $expenseId = str_replace('confirm_expense_', '', $data);
            $this->confirmExpense($chatId, $messageId, $expenseId, $userId);
        } elseif ($data === 'confirm_expense') {
            // Handle old format without expense ID - find the latest pending expense
            $this->confirmLatestPendingExpense($chatId, $messageId, $userId);
        } elseif (str_starts_with($data, 'cancel_expense_')) {
            $expenseId = str_replace('cancel_expense_', '', $data);
            $this->cancelExpense($chatId, $messageId, $expenseId, $userId);
        } elseif ($data === 'cancel_expense') {
            // Handle old format without expense ID
            $this->cancelLatestPendingExpense($chatId, $messageId, $userId);
        } elseif (str_starts_with($data, 'select_category_')) {
            $categoryId = str_replace('select_category_', '', $data);
            $this->selectCategory($chatId, $messageId, $categoryId);
        } elseif (str_starts_with($data, 'edit_category_')) {
            $expenseId = str_replace('edit_category_', '', $data);
            $this->editCategory($chatId, $messageId, $expenseId);
        } elseif ($data === 'edit_category') {
            // Handle old format without expense ID
            $this->editCategoryForLatestPendingExpense($chatId, $messageId, $userId);
        } elseif (str_starts_with($data, 'edit_description_')) {
            $expenseId = str_replace('edit_description_', '', $data);
            $this->editDescription($chatId, $messageId, $expenseId);
        } elseif ($data === 'edit_description') {
            // Handle old format without expense ID
            $this->editDescriptionForLatestPendingExpense($chatId, $messageId, $userId);
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
        Log::info('Processing voice expense', ['chatId' => $chatId, 'userId' => $userId, 'voice' => $voice]);
        
        $fileId = $voice['file_id'];
        
        // Send processing message
        $processingMessage = $this->telegram->sendMessage($chatId, "🎤 Processing voice message...");
        Log::info('Processing message sent', ['result' => $processingMessage]);

        // Queue the job
        try {
            \App\Jobs\ProcessExpenseVoice::dispatch($userId, $fileId, $messageId)
                ->onQueue('high');
            Log::info('Voice processing job dispatched successfully', ['file_id' => $fileId]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch voice processing job', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "❌ Failed to process voice message. Please try again.");
        }

        // Delete processing message after a delay
        \App\Jobs\ProcessExpenseText::dispatch($userId, 'delete_message', $processingMessage['result']['message_id'])
            ->delay(now()->addSeconds(2))
            ->onQueue('low');
    }

    private function processPhotoExpense(string $chatId, string $userId, array $photos, int $messageId)
    {
        Log::info('Processing photo expense', ['chatId' => $chatId, 'userId' => $userId, 'photos' => count($photos)]);
        
        // Get the largest photo (Telegram sends multiple sizes)
        $largestPhoto = end($photos);
        $fileId = $largestPhoto['file_id'];
        
        // Send processing message
        $processingMessage = $this->telegram->sendMessage($chatId, "📷 Processing receipt image...");
        Log::info('Processing message sent', ['result' => $processingMessage]);

        // Queue the job
        try {
            \App\Jobs\ProcessExpenseImage::dispatch($userId, $fileId, $messageId)
                ->onQueue('high');
            Log::info('Photo processing job dispatched successfully', ['file_id' => $fileId]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch photo processing job', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "❌ Failed to process image. Please try again.");
        }

        // Delete processing message after a delay
        \App\Jobs\ProcessExpenseText::dispatch($userId, 'delete_message', $processingMessage['result']['message_id'])
            ->delay(now()->addSeconds(2))
            ->onQueue('low');
    }

    private function confirmExpense(string $chatId, int $messageId, string $expenseId, string $userId)
    {
        try {
            // Find the expense
            $expense = \App\Models\Expense::where('id', $expenseId)
                ->where('user_id', function($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->where('status', 'pending')
                ->first();

            if (!$expense) {
                $this->telegram->editMessage($chatId, $messageId, "❌ Expense not found or already processed.");
                return;
            }

            // Update expense status to confirmed
            $expense->status = 'confirmed';
            $expense->save();

            // If user selected a different category than suggested, learn from it
            if ($expense->category_id != $expense->suggested_category_id) {
                $learningService = new \App\Services\CategoryLearningService();
                $learningService->learn(
                    $expense->user_id,
                    $expense->description,
                    $expense->category_id,
                    $expense->amount
                );
            }

            // TODO: Sync to Google Sheets if configured
            // if (config('services.google_sheets.enabled')) {
            //     \App\Jobs\SyncExpenseToGoogleSheets::dispatch($expense);
            // }

            $this->telegram->editMessage($chatId, $messageId, 
                "✅ Expense confirmed and saved!\n\n" .
                "💰 Amount: $" . number_format($expense->amount, 2) . "\n" .
                "🏷️ Category: " . $expense->category->name
            );

            Log::info('Expense confirmed', [
                'expense_id' => $expense->id,
                'user_id' => $expense->user_id,
                'amount' => $expense->amount
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to confirm expense', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage()
            ]);
            
            $this->telegram->editMessage($chatId, $messageId, "❌ Failed to confirm expense. Please try again.");
        }
    }

    private function cancelExpense(string $chatId, int $messageId, string $expenseId, string $userId)
    {
        try {
            // Find and delete the expense
            $expense = \App\Models\Expense::where('id', $expenseId)
                ->where('user_id', function($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->where('status', 'pending')
                ->first();

            if ($expense) {
                $expense->delete();
                $this->telegram->editMessage($chatId, $messageId, "❌ Expense cancelled.");
            } else {
                $this->telegram->editMessage($chatId, $messageId, "❌ Expense not found or already processed.");
            }

        } catch (\Exception $e) {
            Log::error('Failed to cancel expense', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage()
            ]);
            
            $this->telegram->editMessage($chatId, $messageId, "❌ Failed to cancel expense.");
        }
    }

    private function selectCategory(string $chatId, int $messageId, string $categoryId)
    {
        // TODO: Implement category selection
        $this->telegram->editMessage($chatId, $messageId, "✅ Category updated!");
    }

    private function editCategory(string $chatId, int $messageId, string $expenseId)
    {
        $this->telegram->sendCategorySelection($chatId);
    }

    private function editDescription(string $chatId, int $messageId, string $expenseId)
    {
        // TODO: Implement description editing
        $this->telegram->editMessage($chatId, $messageId, "📝 To edit description, please cancel and create a new expense.");
    }

    /**
     * Confirm the latest pending expense (for backward compatibility)
     */
    private function confirmLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        try {
            // Find the latest pending expense for this user
            $expense = Expense::where('user_id', function($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$expense) {
                $this->telegram->editMessage($chatId, $messageId, "❌ Expense not found. It may have been already processed or expired.");
                return;
            }

            // Update expense status to confirmed
            $expense->status = 'confirmed';
            $expense->confirmed_at = now();
            $expense->save();

            // If user selected a different category than suggested, learn from it
            if ($expense->category_id != $expense->suggested_category_id) {
                $learningService = new CategoryLearningService();
                $learningService->learn(
                    $expense->user_id,
                    $expense->description,
                    $expense->category_id,
                    $expense->amount
                );
            }

            $this->telegram->editMessage($chatId, $messageId, 
                "✅ Expense confirmed and saved!\n\n" .
                "💰 Amount: $" . number_format($expense->amount, 2) . "\n" .
                "🏷️ Category: " . $expense->category->name
            );

            Log::info('Expense confirmed (legacy format)', [
                'expense_id' => $expense->id,
                'user_id' => $expense->user_id,
                'amount' => $expense->amount
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to confirm expense (legacy format)', [
                'error' => $e->getMessage(),
                'user_telegram_id' => $userId
            ]);
            
            $this->telegram->editMessage($chatId, $messageId, "❌ Failed to confirm expense. Please try creating a new expense.");
        }
    }

    /**
     * Cancel the latest pending expense (for backward compatibility)
     */
    private function cancelLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        try {
            // Find the latest pending expense for this user
            $expense = Expense::where('user_id', function($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($expense) {
                $expense->delete();
                $this->telegram->editMessage($chatId, $messageId, "❌ Expense cancelled.");
                
                Log::info('Expense cancelled (legacy format)', [
                    'expense_id' => $expense->id,
                    'user_id' => $expense->user_id
                ]);
            } else {
                $this->telegram->editMessage($chatId, $messageId, "❌ No pending expense found to cancel.");
            }

        } catch (\Exception $e) {
            Log::error('Failed to cancel expense (legacy format)', [
                'error' => $e->getMessage(),
                'user_telegram_id' => $userId
            ]);
            
            $this->telegram->editMessage($chatId, $messageId, "❌ Failed to cancel expense.");
        }
    }

    /**
     * Edit category for the latest pending expense (for backward compatibility)
     */
    private function editCategoryForLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        $this->telegram->sendCategorySelection($chatId);
    }

    /**
     * Edit description for the latest pending expense (for backward compatibility)
     */
    private function editDescriptionForLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        $this->telegram->editMessage($chatId, $messageId, "📝 To edit description, please cancel and create a new expense.");
    }
}
