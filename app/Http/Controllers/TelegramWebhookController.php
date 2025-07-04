<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExpenseText;
use App\Models\Expense;
use App\Models\User;
use App\Services\CategoryLearningService;
use App\Services\TelegramService;
use App\Telegram\CommandRouter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        } elseif (strpos($data, 'export_') === 0) {
            // Handle export callbacks
            $this->handleExportCallback($chatId, $messageId, $userId, $data);
        } elseif (strpos($data, 'create_installment_') === 0) {
            // Handle installment creation
            $expenseId = str_replace('create_installment_', '', $data);
            $this->createInstallmentPlan($chatId, $messageId, $expenseId, $userId);
        } elseif (strpos($data, 'reject_installment_') === 0) {
            // Handle installment rejection (just confirm the expense)
            $expenseId = str_replace('reject_installment_', '', $data);
            $this->confirmExpense($chatId, $messageId, $expenseId, $userId);
        } elseif (strpos($data, 'subscription_yes_') === 0) {
            // Handle subscription confirmation
            $expenseId = str_replace('subscription_yes_', '', $data);
            $this->handleSubscriptionYes($chatId, $messageId, $expenseId, $userId);
        } elseif (strpos($data, 'subscription_no_') === 0) {
            // Handle subscription rejection
            $expenseId = str_replace('subscription_no_', '', $data);
            $this->confirmExpense($chatId, $messageId, $expenseId, $userId);
        } elseif (strpos($data, 'subscription_period_') === 0) {
            // Handle subscription periodicity selection
            $this->handleSubscriptionPeriodicity($chatId, $messageId, $userId, $data);
        } elseif (strpos($data, 'subscription_cancel_') === 0) {
            // Handle subscription creation cancellation
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.subscription_cancelled', [], $user->language ?? 'es'));
        } elseif (strpos($data, 'sub_pause_') === 0) {
            // Handle subscription pause
            $subscriptionId = str_replace('sub_pause_', '', $data);
            $this->handleSubscriptionPause($chatId, $messageId, $subscriptionId, $userId);
        } elseif (strpos($data, 'sub_resume_') === 0) {
            // Handle subscription resume
            $subscriptionId = str_replace('sub_resume_', '', $data);
            $this->handleSubscriptionResume($chatId, $messageId, $subscriptionId, $userId);
        } elseif (strpos($data, 'sub_cancel_') === 0) {
            // Handle subscription cancellation
            $subscriptionId = str_replace('sub_cancel_', '', $data);
            $this->handleSubscriptionCancel($chatId, $messageId, $subscriptionId, $userId);
        } elseif (strpos($data, 'sub_confirm_cancel_') === 0) {
            // Handle subscription cancellation confirmation
            $subscriptionId = str_replace('sub_confirm_cancel_', '', $data);
            $this->confirmSubscriptionCancel($chatId, $messageId, $subscriptionId, $userId);
        } elseif (strpos($data, 'notif_') === 0) {
            // Handle notification settings
            $this->handleNotificationSettings($chatId, $messageId, $userId, $data);
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

    /**
     * Handle export callbacks
     */
    private function handleExportCallback(string $chatId, int $messageId, string $userId, string $data)
    {
        try {
            // Get user
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.user_not_found', [], 'es'));
                return;
            }

            // Handle export format selection
            if (str_starts_with($data, 'export_format_')) {
                $format = str_replace('export_format_', '', $data);
                
                // Show period selection for the chosen format
                $keyboard = [
                    [
                        ['text' => trans('telegram.export_current_month', [], $user->language), 
                         'callback_data' => "export_period_{$format}_month"],
                    ],
                    [
                        ['text' => trans('telegram.export_last_month', [], $user->language), 
                         'callback_data' => "export_period_{$format}_lastmonth"],
                    ],
                    [
                        ['text' => trans('telegram.export_last_3_months', [], $user->language), 
                         'callback_data' => "export_period_{$format}_3months"],
                    ],
                    [
                        ['text' => trans('telegram.export_current_year', [], $user->language), 
                         'callback_data' => "export_period_{$format}_year"],
                    ],
                    [
                        ['text' => trans('telegram.export_all_time', [], $user->language), 
                         'callback_data' => "export_period_{$format}_all"],
                    ],
                    [
                        ['text' => trans('telegram.button_cancel', [], $user->language), 
                         'callback_data' => 'cancel'],
                    ],
                ];

                $this->telegram->editMessage(
                    $chatId, 
                    $messageId,
                    trans('telegram.export_period_selection', [], $user->language),
                    [
                        'parse_mode' => 'Markdown',
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                    ]
                );
                return;
            }

            // Handle quick export (this month)
            if ($data === 'export_quick_month') {
                $format = 'pdf';
                $period = 'month';
            } else if (str_starts_with($data, 'export_period_')) {
                // Extract format and period
                $parts = explode('_', str_replace('export_period_', '', $data));
                $format = $parts[0];
                $period = $parts[1] ?? 'month';
            } else {
                return;
            }

            // Determine date range
            $timezone = $user->getTimezone();
            $dateRange = $this->getExportDateRange($period, $timezone, $user);

            // Check if user has expenses in this period
            $expenseCount = $user->expenses()
                ->whereBetween('expense_date', [$dateRange['start'], $dateRange['end']])
                ->where('status', 'confirmed')
                ->count();

            if ($expenseCount === 0) {
                $this->telegram->editMessage(
                    $chatId, 
                    $messageId,
                    trans('telegram.export_no_expenses', ['period' => $dateRange['name']], $user->language)
                );
                return;
            }

            // Update message to show generating
            $this->telegram->editMessage(
                $chatId,
                $messageId,
                trans('telegram.export_generating', [
                    'format' => strtoupper($format),
                    'period' => $dateRange['name']
                ], $user->language),
                ['parse_mode' => 'Markdown']
            );

            // Queue export job
            \App\Jobs\GenerateExpenseExport::dispatch(
                $user,
                $format,
                $dateRange['start'],
                $dateRange['end']
            );

            Log::info('Export queued', [
                'user_id' => $user->id,
                'format' => $format,
                'period' => $period,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to handle export callback', [
                'error' => $e->getMessage(),
                'data' => $data,
                'user_telegram_id' => $userId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.export_error', [], $user->language ?? 'es'));
        }
    }

    /**
     * Get date range for export
     */
    private function getExportDateRange(string $period, string $timezone, ?User $user = null): array
    {
        switch ($period) {
            case 'month':
                return [
                    'start' => Carbon::now($timezone)->startOfMonth(),
                    'end' => Carbon::now($timezone)->endOfMonth(),
                    'name' => Carbon::now($timezone)->format('F Y'),
                ];

            case 'lastmonth':
                $lastMonth = Carbon::now($timezone)->subMonth();
                return [
                    'start' => $lastMonth->copy()->startOfMonth(),
                    'end' => $lastMonth->copy()->endOfMonth(),
                    'name' => $lastMonth->format('F Y'),
                ];

            case '3months':
                return [
                    'start' => Carbon::now($timezone)->subMonths(3)->startOfMonth(),
                    'end' => Carbon::now($timezone)->endOfMonth(),
                    'name' => trans('telegram.export_last_3_months'),
                ];

            case 'year':
                return [
                    'start' => Carbon::now($timezone)->startOfYear(),
                    'end' => Carbon::now($timezone)->endOfYear(),
                    'name' => Carbon::now($timezone)->year,
                ];

            case 'all':
                if ($user) {
                    $firstExpense = $user->expenses()->min('expense_date');
                    $startDate = $firstExpense ? Carbon::parse($firstExpense, $timezone) : Carbon::now($timezone)->subYear();
                } else {
                    $startDate = Carbon::now($timezone)->subYear();
                }
                return [
                    'start' => $startDate,
                    'end' => Carbon::now($timezone),
                    'name' => trans('telegram.export_all_time'),
                ];

            default:
                // Default to current month
                return [
                    'start' => Carbon::now($timezone)->startOfMonth(),
                    'end' => Carbon::now($timezone)->endOfMonth(),
                    'name' => Carbon::now($timezone)->format('F Y'),
                ];
        }
    }

    /**
     * Create installment plan from expense
     */
    private function createInstallmentPlan(string $chatId, int $messageId, string $expenseId, string $userId)
    {
        try {
            // Find the expense
            $expense = Expense::where('id', $expenseId)
                ->where('user_id', function ($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->where('status', 'pending')
                ->first();

            if (!$expense) {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_not_found', [], $user->language ?? 'es'));
                return;
            }

            // Get the stored context with installment data
            $context = Cache::get("expense_context_{$expenseId}");
            if (!$context || !isset($context['expense_data']['installment_data'])) {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_occurred', [], $user->language ?? 'es'));
                return;
            }

            $user = $expense->user;
            $installmentData = $context['expense_data']['installment_data'];
            $expenseData = $context['expense_data'];

            // Create the installment plan
            $installmentPlanService = app(\App\Services\InstallmentPlanService::class);
            $plan = $installmentPlanService->createPlan($user, $installmentData, $expenseData);

            // Update the expense to link it to the plan and confirm it
            $expense->installment_plan_id = $plan->id;
            $expense->installment_number = 1;
            $expense->status = 'confirmed';
            $expense->confirmed_at = now();
            $expense->save();

            // Activate the plan
            $plan->activate();

            // Send success message
            $successMessage = trans('telegram.installment_created', [], $user->language)."\n\n";
            $successMessage .= trans('telegram.installment_first_payment', [
                'amount' => number_format($expense->amount, 2)
            ], $user->language)."\n";
            $successMessage .= trans('telegram.installment_next_payment', [
                'date' => $plan->next_payment_date->format('d/m/Y')
            ], $user->language);

            $this->telegram->editMessage($chatId, $messageId, $successMessage);

            // Clean up context
            Cache::forget("expense_context_{$expenseId}");

            Log::info('Installment plan created via Telegram', [
                'plan_id' => $plan->id,
                'expense_id' => $expense->id,
                'user_id' => $user->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create installment plan', [
                'error' => $e->getMessage(),
                'expense_id' => $expenseId,
                'user_telegram_id' => $userId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_occurred', [], $user->language ?? 'es'));
        }
    }

    private function handleSubscriptionYes(string $chatId, int $messageId, string $expenseId, string $userId)
    {
        try {
            // Get the expense and context
            $expense = \App\Models\Expense::where('id', $expenseId)
                ->where('user_id', function ($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->first();

            if (!$expense) {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_not_found', [], $user->language ?? 'es'));
                return;
            }

            // Show periodicity selection
            $this->telegram->sendSubscriptionPeriodicitySelection($chatId, $expenseId, $expense->user->language ?? 'es');
            
            // Update the confirmation message to show it's being processed
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.processing_text', [], $expense->user->language ?? 'es'));
        } catch (\Exception $e) {
            Log::error('Error handling subscription confirmation', [
                'error' => $e->getMessage(),
                'expense_id' => $expenseId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
        }
    }

    private function handleSubscriptionPeriodicity(string $chatId, int $messageId, string $userId, string $data)
    {
        try {
            // Parse the callback data: subscription_period_{periodicity}_{expenseId}
            $parts = explode('_', $data);
            if (count($parts) < 4) {
                throw new \Exception('Invalid callback data format');
            }
            
            $periodicity = $parts[2];
            $expenseId = $parts[3];

            // Get the expense
            $expense = \App\Models\Expense::where('id', $expenseId)
                ->where('user_id', function ($query) use ($userId) {
                    $query->select('id')
                        ->from('users')
                        ->where('telegram_id', $userId)
                        ->limit(1);
                })
                ->first();

            if (!$expense) {
                $user = \App\Models\User::where('telegram_id', $userId)->first();
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.expense_not_found', [], $user->language ?? 'es'));
                return;
            }

            // Get the context data
            $context = Cache::get("expense_context_{$expenseId}");
            if (!$context) {
                $user = $expense->user;
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
                return;
            }

            // Create the subscription
            $subscription = \App\Models\Subscription::create([
                'user_id' => $expense->user_id,
                'name' => $expense->description,
                'description' => $expense->description,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'periodicity' => $periodicity,
                'next_charge_date' => Carbon::now()->addDay(), // First charge is tomorrow
                'last_charge_date' => Carbon::now(),
                'status' => 'active',
                'category_id' => $expense->category_id,
                'merchant_name' => $expense->merchant_name,
            ]);

            // Link the expense to the subscription
            \App\Models\SubscriptionExpense::create([
                'subscription_id' => $subscription->id,
                'expense_id' => $expense->id,
                'charge_date' => $expense->expense_date,
                'status' => 'processed',
            ]);

            // Confirm the expense
            $expense->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            // Send success message
            $user = $expense->user;
            $periodicityText = trans('telegram.periodicity.'.$periodicity, [], $user->language);
            $successMessage = trans('telegram.subscription_created', [
                'name' => $subscription->name,
                'amount' => number_format($subscription->amount, 2),
                'currency' => $subscription->currency,
                'periodicity' => $periodicityText,
                'next_charge' => $subscription->next_charge_date->format('d/m/Y'),
            ], $user->language);

            $this->telegram->editMessage($chatId, $messageId, $successMessage);

            // Clean up context
            Cache::forget("expense_context_{$expenseId}");

        } catch (\Exception $e) {
            Log::error('Error creating subscription', [
                'error' => $e->getMessage(),
                'data' => $data,
                'user_telegram_id' => $userId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
        }
    }

    private function handleSubscriptionPause(string $chatId, int $messageId, string $subscriptionId, string $userId)
    {
        try {
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                return;
            }

            $subscription = $user->subscriptions()->find($subscriptionId);
            if (!$subscription) {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.subscription_not_found', [], $user->language ?? 'es'));
                return;
            }

            $subscription->pause();

            $message = trans('telegram.subscription_paused_success', [
                'name' => $subscription->name,
            ], $user->language ?? 'es');

            $this->telegram->editMessage($chatId, $messageId, $message);

        } catch (\Exception $e) {
            Log::error('Error pausing subscription', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
        }
    }

    private function handleSubscriptionResume(string $chatId, int $messageId, string $subscriptionId, string $userId)
    {
        try {
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                return;
            }

            $subscription = $user->subscriptions()->find($subscriptionId);
            if (!$subscription) {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.subscription_not_found', [], $user->language ?? 'es'));
                return;
            }

            $subscription->resume();

            $message = trans('telegram.subscription_resumed_success', [
                'name' => $subscription->name,
                'next_charge' => $subscription->next_charge_date->format('d/m/Y'),
            ], $user->language ?? 'es');

            $this->telegram->editMessage($chatId, $messageId, $message);

        } catch (\Exception $e) {
            Log::error('Error resuming subscription', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
        }
    }

    private function handleSubscriptionCancel(string $chatId, int $messageId, string $subscriptionId, string $userId)
    {
        try {
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                return;
            }

            $subscription = $user->subscriptions()->find($subscriptionId);
            if (!$subscription) {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.subscription_not_found', [], $user->language ?? 'es'));
                return;
            }

            // Ask for confirmation
            $message = trans('telegram.subscription_cancel_confirm', [
                'name' => $subscription->name,
            ], $user->language ?? 'es');

            $keyboard = [
                [
                    [
                        'text' => trans('telegram.button_confirm_cancel', [], $user->language ?? 'es'),
                        'callback_data' => "sub_confirm_cancel_{$subscriptionId}",
                    ],
                    [
                        'text' => trans('telegram.button_keep_subscription', [], $user->language ?? 'es'),
                        'callback_data' => "cancel",
                    ],
                ],
            ];

            $this->telegram->editMessage($chatId, $messageId, $message, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);

        } catch (\Exception $e) {
            Log::error('Error cancelling subscription', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
        }
    }

    private function confirmSubscriptionCancel(string $chatId, int $messageId, string $subscriptionId, string $userId)
    {
        try {
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                return;
            }

            $subscription = $user->subscriptions()->find($subscriptionId);
            if (!$subscription) {
                $this->telegram->editMessage($chatId, $messageId, trans('telegram.subscription_not_found', [], $user->language ?? 'es'));
                return;
            }

            $subscription->cancel();

            $message = trans('telegram.subscription_cancelled_success', [
                'name' => $subscription->name,
            ], $user->language ?? 'es');

            $this->telegram->editMessage($chatId, $messageId, $message);

        } catch (\Exception $e) {
            Log::error('Error confirming subscription cancellation', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
        }
    }

    private function handleNotificationSettings(string $chatId, int $messageId, string $userId, string $data)
    {
        try {
            $user = \App\Models\User::where('telegram_id', $userId)->first();
            if (!$user) {
                return;
            }

            $preferences = $user->preferences ?? [];
            $notifications = $preferences['notifications'] ?? [];

            switch ($data) {
                case 'notif_daily_enable':
                    $notifications['daily_summary'] = true;
                    $notifications['daily_summary_time'] = 21; // Default to 9 PM
                    $preferences['notifications'] = $notifications;
                    $user->preferences = $preferences;
                    $user->save();

                    $this->telegram->editMessage($chatId, $messageId, 
                        trans('telegram.daily_summary_enabled_success', [], $user->language ?? 'es')
                    );
                    break;

                case 'notif_daily_disable':
                    $notifications['daily_summary'] = false;
                    $preferences['notifications'] = $notifications;
                    $user->preferences = $preferences;
                    $user->save();

                    $this->telegram->editMessage($chatId, $messageId, 
                        trans('telegram.daily_summary_disabled_success', [], $user->language ?? 'es')
                    );
                    break;

                case 'notif_daily_time':
                    // Show time selection
                    $this->showTimeSelection($chatId, $messageId, $user);
                    break;

                case 'notif_test_daily':
                    // Send test daily summary
                    $this->sendTestDailySummary($chatId, $messageId, $user);
                    break;

                case 'notif_weekly_enable':
                    $notifications['weekly_summary'] = true;
                    $preferences['notifications'] = $notifications;
                    $user->preferences = $preferences;
                    $user->save();

                    $this->telegram->editMessage($chatId, $messageId, 
                        trans('telegram.weekly_summary_enabled_success', [], $user->language ?? 'es')
                    );
                    break;

                case 'notif_weekly_disable':
                    $notifications['weekly_summary'] = false;
                    $preferences['notifications'] = $notifications;
                    $user->preferences = $preferences;
                    $user->save();

                    $this->telegram->editMessage($chatId, $messageId, 
                        trans('telegram.weekly_summary_disabled_success', [], $user->language ?? 'es')
                    );
                    break;

                default:
                    // Handle time selection (e.g., notif_time_20)
                    if (strpos($data, 'notif_time_') === 0) {
                        $hour = (int) str_replace('notif_time_', '', $data);
                        $notifications['daily_summary'] = true;
                        $notifications['daily_summary_time'] = $hour;
                        $preferences['notifications'] = $notifications;
                        $user->preferences = $preferences;
                        $user->save();

                        $this->telegram->editMessage($chatId, $messageId, 
                            trans('telegram.daily_summary_time_updated', [
                                'time' => $hour . ':00'
                            ], $user->language ?? 'es')
                        );
                    }
                    break;
            }

        } catch (\Exception $e) {
            Log::error('Error handling notification settings', [
                'error' => $e->getMessage(),
                'data' => $data,
                'user_telegram_id' => $userId,
            ]);

            $user = \App\Models\User::where('telegram_id', $userId)->first();
            $this->telegram->editMessage($chatId, $messageId, trans('telegram.error_processing', [], $user->language ?? 'es'));
        }
    }

    private function showTimeSelection(string $chatId, int $messageId, \App\Models\User $user)
    {
        $language = $user->language ?? 'es';
        $message = trans('telegram.select_notification_time', [], $language);
        
        // Create time selection keyboard (common notification times)
        $keyboard = [
            [
                ['text' => '🌅 6:00', 'callback_data' => 'notif_time_6'],
                ['text' => '☀️ 9:00', 'callback_data' => 'notif_time_9'],
                ['text' => '🌤️ 12:00', 'callback_data' => 'notif_time_12'],
            ],
            [
                ['text' => '🌇 18:00', 'callback_data' => 'notif_time_18'],
                ['text' => '🌆 20:00', 'callback_data' => 'notif_time_20'],
                ['text' => '🌙 21:00', 'callback_data' => 'notif_time_21'],
            ],
            [
                ['text' => '🌌 22:00', 'callback_data' => 'notif_time_22'],
                ['text' => '🌃 23:00', 'callback_data' => 'notif_time_23'],
            ],
            [
                ['text' => trans('telegram.button_cancel', [], $language), 'callback_data' => 'cancel'],
            ],
        ];

        $this->telegram->editMessage($chatId, $messageId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function sendTestDailySummary(string $chatId, int $messageId, \App\Models\User $user)
    {
        try {
            // Delete the original message
            $this->telegram->deleteMessage($chatId, $messageId);
            
            // Run the command for this specific user
            \Artisan::call('summaries:send-daily', [
                '--user' => $user->id,
            ]);
            
            // If no expenses today, inform the user
            $output = \Artisan::output();
            if (str_contains($output, 'No expenses today')) {
                $this->telegram->sendMessage($chatId, 
                    trans('telegram.no_expenses_for_summary', [], $user->language ?? 'es')
                );
            }
            
        } catch (\Exception $e) {
            Log::error('Error sending test daily summary', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);
            
            $this->telegram->sendMessage($chatId, 
                trans('telegram.error_sending_test_summary', [], $user->language ?? 'es')
            );
        }
    }
}
