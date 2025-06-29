<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExpenseText;
use App\Models\Expense;
use App\Models\User;
use App\Services\CategoryLearningService;
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
        if (! $user->is_active) {
            $this->telegram->sendMessage($chatId, trans('telegram.account_inactive', [], $user->language ?? 'es'));

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
            $this->telegram->sendMessage($chatId, trans('telegram.unsupported_message_type', [], $user->language ?? 'es'));
        }
    }

    private function handleCommand(string $chatId, string $userId, string $command, array $message, User $user)
    {
        // Use the CommandRouter to handle all commands
        $handled = $this->commandRouter->route($message, $user);

        if (! $handled) {
            // Command not found
            $this->telegram->sendMessage($chatId, trans('telegram.unknown_command', [], $user->language ?? 'es'));
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
            $this->editCategory($chatId, $messageId, $expenseId, $userId);
        } elseif ($data === 'edit_category') {
            // Handle old format without expense ID
            $this->editCategoryForLatestPendingExpense($chatId, $messageId, $userId);
        } elseif (str_starts_with($data, 'edit_description_')) {
            $expenseId = str_replace('edit_description_', '', $data);
            $this->editDescription($chatId, $messageId, $expenseId);
        } elseif ($data === 'edit_description') {
            // Handle old format without expense ID
            $this->editDescriptionForLatestPendingExpense($chatId, $messageId, $userId);
        } elseif (strpos($data, 'language_') === 0) {
            // Handle language selection
            $this->handleLanguageSelection($chatId, $messageId, $userId, $data);
        } elseif (strpos($data, 'cmd_') === 0) {
            // Handle command callbacks
            $this->handleCommandCallback($chatId, $messageId, $userId, $data);
        } elseif (strpos($data, 'set_timezone:') === 0) {
            // Handle timezone selection
            $this->handleTimezoneSelection($chatId, $messageId, $userId, $data);
        } elseif ($data === 'cancel') {
            // Handle generic cancel
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.cancelled', [], $user->language ?? 'es'));
        }
        // Add more callback handlers as needed
    }

    private function registerOrUpdateUser(array $telegramUser): \App\Models\User
    {
        $user = \App\Models\User::firstOrNew(['telegram_id' => $telegramUser['id']]);

        $user->telegram_username = $telegramUser['username'] ?? null;
        $user->telegram_first_name = $telegramUser['first_name'] ?? null;
        $user->telegram_last_name = $telegramUser['last_name'] ?? null;

        if (! $user->exists) {
            $user->name = trim(($telegramUser['first_name'] ?? '').' '.($telegramUser['last_name'] ?? ''));
            $user->email = 'telegram_'.$telegramUser['id'].'@expensebot.local';
            $user->password = bcrypt(str()->random(32));
            $user->is_active = true;
        }

        $user->save();

        return $user;
    }

    private function processTextExpense(string $chatId, string $userId, string $text, int $messageId)
    {
        Log::info('Processing text expense', ['chatId' => $chatId, 'userId' => $userId, 'text' => $text]);

        // Get user language
        $user = \App\Models\User::where('telegram_id', $userId)->first();
        $language = $user ? $user->language : 'es';

        // Send processing message
        $processingMessage = $this->telegram->sendMessage($chatId, trans('telegram.processing_text', [], $language));
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

        // Get user language
        $user = \App\Models\User::where('telegram_id', $userId)->first();
        $language = $user ? $user->language : 'es';

        // Send processing message
        $processingMessage = $this->telegram->sendMessage($chatId, trans('telegram.processing_voice', [], $language));
        Log::info('Processing message sent', ['result' => $processingMessage]);

        // Queue the job
        try {
            \App\Jobs\ProcessExpenseVoice::dispatch($userId, $fileId, $messageId)
                ->onQueue('high');
            Log::info('Voice processing job dispatched successfully', ['file_id' => $fileId]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch voice processing job', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, trans('telegram.voice_processing_failed', [], $language));
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

        // Get user language
        $user = \App\Models\User::where('telegram_id', $userId)->first();
        $language = $user ? $user->language : 'es';

        // Send processing message
        $processingMessage = $this->telegram->sendMessage($chatId, trans('telegram.processing_image', [], $language));
        Log::info('Processing message sent', ['result' => $processingMessage]);

        // Queue the job
        try {
            \App\Jobs\ProcessExpenseImage::dispatch($userId, $fileId, $messageId)
                ->onQueue('high');
            Log::info('Photo processing job dispatched successfully', ['file_id' => $fileId]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch photo processing job', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, trans('telegram.image_processing_failed', [], $language));
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
                ->where('user_id', function ($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->where('status', 'pending')
                ->first();

            if (! $expense) {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_not_found', [], $user->language ?? 'es'));

                return;
            }

            // Update expense status to confirmed
            $expense->status = 'confirmed';
            $expense->save();

            // If user selected a different category than suggested, learn from it
            if ($expense->category_id != $expense->suggested_category_id) {
                $learningService = new \App\Services\CategoryLearningService;
                $learningService->learn(
                    $expense->user_id,
                    $expense->description,
                    $expense->category_id,
                    $expense->amount
                );
            }

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $categoryName = $expense->category->getTranslatedName($user->language ?? 'es');
            
            $this->telegram->editMessage($chatId, $messageId,
                trans('telegram.expense_confirmed', [
                    'amount' => number_format($expense->amount, 2),
                    'category' => $categoryName
                ], $user->language ?? 'es')
            );

            Log::info('Expense confirmed', [
                'expense_id' => $expense->id,
                'user_id' => $expense->user_id,
                'amount' => $expense->amount,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to confirm expense', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage(),
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_confirm_failed', [], $user->language ?? 'es'));
        }
    }

    private function cancelExpense(string $chatId, int $messageId, string $expenseId, string $userId)
    {
        try {
            // Find and delete the expense
            $expense = \App\Models\Expense::where('id', $expenseId)
                ->where('user_id', function ($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->where('status', 'pending')
                ->first();

            if ($expense) {
                $expense->delete();
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_cancelled', [], $user->language ?? 'es'));
            } else {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_not_found', [], $user->language ?? 'es'));
            }

        } catch (\Exception $e) {
            Log::error('Failed to cancel expense', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage(),
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_cancel_failed', [], $user->language ?? 'es'));
        }
    }

    private function selectCategory(string $chatId, int $messageId, string $categoryId)
    {
        // TODO: Implement category selection
        // For now, just close the message
        $this->telegram->editMessage($chatId, $messageId, '✅');
    }

    private function editCategory(string $chatId, int $messageId, string $expenseId, string $userId)
    {
        $user = \App\Models\User::where('telegram_id', $userId)->first();
        $language = $user ? $user->language : 'es';
        $this->telegram->sendCategorySelection($chatId, null, $language);
    }

    private function editDescription(string $chatId, int $messageId, string $expenseId)
    {
        // TODO: Implement description editing
        // For now, show not available message
        $this->telegram->editMessage($chatId, $messageId, '📝 To edit description, please cancel and create a new expense.');
    }

    /**
     * Confirm the latest pending expense (for backward compatibility)
     */
    private function confirmLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        try {
            // Find the latest pending expense for this user
            $expense = Expense::where('user_id', function ($query) use ($userId) {
                $query->select('id')
                    ->from('users')
                    ->where('telegram_id', $userId)
                    ->limit(1);
            })
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $expense) {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_not_found', [], $user->language ?? 'es'));

                return;
            }

            // Update expense status to confirmed
            $expense->status = 'confirmed';
            $expense->confirmed_at = now();
            $expense->save();

            // If user selected a different category than suggested, learn from it
            if ($expense->category_id != $expense->suggested_category_id) {
                $learningService = new CategoryLearningService;
                $learningService->learn(
                    $expense->user_id,
                    $expense->description,
                    $expense->category_id,
                    $expense->amount
                );
            }

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $categoryName = $expense->category->getTranslatedName($user->language ?? 'es');
            
            $this->telegram->editMessage($chatId, $messageId,
                trans('telegram.expense_confirmed', [
                    'amount' => number_format($expense->amount, 2),
                    'category' => $categoryName
                ], $user->language ?? 'es')
            );

            Log::info('Expense confirmed (legacy format)', [
                'expense_id' => $expense->id,
                'user_id' => $expense->user_id,
                'amount' => $expense->amount,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to confirm expense (legacy format)', [
                'error' => $e->getMessage(),
                'user_telegram_id' => $userId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_confirm_failed', [], $user->language ?? 'es'));
        }
    }

    /**
     * Cancel the latest pending expense (for backward compatibility)
     */
    private function cancelLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        try {
            // Find the latest pending expense for this user
            $expense = Expense::where('user_id', function ($query) use ($userId) {
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
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_cancelled', [], $user->language ?? 'es'));

                Log::info('Expense cancelled (legacy format)', [
                    'expense_id' => $expense->id,
                    'user_id' => $expense->user_id,
                ]);
            } else {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.no_pending_expense', [], $user->language ?? 'es'));
            }

        } catch (\Exception $e) {
            Log::error('Failed to cancel expense (legacy format)', [
                'error' => $e->getMessage(),
                'user_telegram_id' => $userId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_cancel_failed', [], $user->language ?? 'es'));
        }
    }

    /**
     * Edit category for the latest pending expense (for backward compatibility)
     */
    private function editCategoryForLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        $user = \App\Models\User::where('telegram_id', $userId)->first();
        $language = $user ? $user->language : 'es';
        $this->telegram->sendCategorySelection($chatId, null, $language);
    }

    /**
     * Edit description for the latest pending expense (for backward compatibility)
     */
    private function editDescriptionForLatestPendingExpense(string $chatId, int $messageId, string $userId)
    {
        $this->telegram->editMessage($chatId, $messageId, '📝 To edit description, please cancel and create a new expense.');
    }

    /**
     * Handle command callbacks (e.g., cmd_expenses_month)
     */
    private function handleCommandCallback(string $chatId, int $messageId, string $userId, string $data)
    {
        try {
            // Get user
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                $this->telegram->sendMessage($chatId, trans('telegram.user_not_found', [], 'es'));
                return;
            }

            // Extract command from callback data
            // Remove 'cmd_' prefix
            $callbackData = str_replace('cmd_', '', $data);
            
            // Define commands that accept parameters and their expected part count
            $commandsWithParams = [
                'expenses_month' => 2,       // expenses + month
                'expenses_week' => 2,        // expenses + week  
                'category_spending' => 2,    // category + spending
                'export_month' => 2,         // export + month
                'export_week' => 2,          // export + week
                'export_today' => 2,         // export + today
            ];
            
            $commandText = '';
            $callbackParts = explode('_', $callbackData);
            
            // Try to match known commands with parameters
            foreach ($commandsWithParams as $cmdName => $partCount) {
                $cmdParts = explode('_', $cmdName);
                $testParts = array_slice($callbackParts, 0, $partCount);
                
                if (implode('_', $testParts) === $cmdName && count($callbackParts) > $partCount) {
                    // Found a command with parameters
                    $command = '/' . $cmdName;
                    $params = implode('_', array_slice($callbackParts, $partCount));
                    // Convert underscores back to hyphens for dates
                    $params = str_replace('_', '-', $params);
                    $commandText = $command . ' ' . $params;
                    break;
                }
            }
            
            // If no match found, treat as simple command
            if (empty($commandText)) {
                $command = '/' . $callbackData;
                $commandText = $command;
            }
            
            // Create a fake message structure for the command router
            $fakeMessage = [
                'message_id' => $messageId,
                'from' => [
                    'id' => $userId,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
                'text' => $commandText,
                'date' => time(),
            ];
            
            // Route the command
            $handled = $this->commandRouter->route($fakeMessage, $user);
            
            if (!$handled) {
                $this->telegram->sendMessage($chatId, trans('telegram.unknown_command', [], $user->language ?? 'es'));
            }
            
            Log::info('Command callback handled', [
                'user_id' => $userId,
                'command' => $commandText,
                'original_data' => $data,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to handle command callback', [
                'error' => $e->getMessage(),
                'data' => $data,
                'user_telegram_id' => $userId,
            ]);
            
            $this->telegram->sendMessage($chatId, trans('telegram.command_failed', [], 'es'));
        }
    }

    /**
     * Handle language selection callback
     */
    private function handleLanguageSelection(string $chatId, int $messageId, string $userId, string $data)
    {
        try {
            // Extract language code from callback data
            $language = str_replace('language_', '', $data);

            // Validate language
            if (! in_array($language, ['en', 'es'])) {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.invalid_language', [], 'es'));

                return;
            }

            // Update user's language preference
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if ($user) {
                $user->language = $language;
                $user->save();

                // Send success message in the new language
                $message = trans('telegram.language_updated', [], $language);
                $this->telegram->editMessage($chatId, $messageId, $message);

                Log::info('User language updated', [
                    'user_id' => $user->id,
                    'language' => $language,
                ]);
            } else {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.user_not_found', [], 'es'));
            }
        } catch (\Exception $e) {
            Log::error('Failed to update language', [
                'error' => $e->getMessage(),
                'user_telegram_id' => $userId,
            ]);

            $this->telegram->editMessage($chatId, $messageId, trans('telegram.language_update_failed', [], 'es'));
        }
    }

    /**
     * Handle timezone selection callback
     */
    private function handleTimezoneSelection(string $chatId, int $messageId, string $userId, string $data)
    {
        try {
            // Extract timezone from callback data
            $timezone = str_replace('set_timezone:', '', $data);
            
            // Get user
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.user_not_found', [], 'es'));
                return;
            }
            
            // Update user's timezone
            $user->update(['timezone' => $timezone]);
            
            // Get current time in new timezone
            $currentTime = now($timezone)->format('H:i');
            
            $this->telegram->editMessage($chatId, $messageId,
                trans('telegram.timezone_updated', [
                    'timezone' => $timezone,
                    'current_time' => $currentTime
                ], $user->language ?? 'es'),
                ['parse_mode' => 'Markdown']
            );
            
            Log::info('User timezone updated', [
                'user_id' => $user->id,
                'timezone' => $timezone,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update timezone', [
                'error' => $e->getMessage(),
                'data' => $data,
                'user_telegram_id' => $userId,
            ]);
            
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.timezone_update_failed', [], $user->language ?? 'es'));
        }
    }
}
