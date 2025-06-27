<?php

namespace App\Telegram;

use App\Models\User;
use App\Services\TelegramService;
use App\Telegram\Commands\CancelCommand;
use App\Telegram\Commands\CategorySpendingCommand;
use App\Telegram\Commands\ExpensesMonthCommand;
use App\Telegram\Commands\ExpensesTodayCommand;
use App\Telegram\Commands\ExpensesWeekCommand;
use App\Telegram\Commands\ExportCommand;
use App\Telegram\Commands\HelpCommand;
use App\Telegram\Commands\SetLanguageCommand;
use App\Telegram\Commands\StartCommand;
use App\Telegram\Commands\StatsCommand;
use App\Telegram\Commands\TopCategoriesCommand;
use Exception;
use Illuminate\Support\Facades\Log;

class CommandRouter
{
    /**
     * Available commands and their handlers
     */
    protected array $commands = [
        '/start' => StartCommand::class,
        '/help' => HelpCommand::class,
        '/expenses_today' => ExpensesTodayCommand::class,
        '/expenses_month' => ExpensesMonthCommand::class,
        '/expenses_week' => ExpensesWeekCommand::class,
        '/category_spending' => CategorySpendingCommand::class,
        '/top_categories' => TopCategoriesCommand::class,
        '/export' => ExportCommand::class,
        '/stats' => StatsCommand::class,
        '/cancel' => CancelCommand::class,
        '/language' => SetLanguageCommand::class,
    ];

    /**
     * Command aliases for user convenience
     */
    protected array $aliases = [
        '/hoy' => '/expenses_today',
        '/today' => '/expenses_today',
        '/mes' => '/expenses_month',
        '/month' => '/expenses_month',
        '/semana' => '/expenses_week',
        '/week' => '/expenses_week',
        '/categorias' => '/category_spending',
        '/categories' => '/category_spending',
        '/top' => '/top_categories',
        '/estadisticas' => '/stats',
        '/statistics' => '/stats',
        '/ayuda' => '/help',
        '/idioma' => '/language',
        '/lang' => '/language',
    ];

    private TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Route a message to the appropriate command handler
     */
    public function route(array $message, User $user): bool
    {
        try {
            $text = $message['text'] ?? '';

            // Extract command and parameters
            $parts = explode(' ', trim($text), 2);
            $command = strtolower($parts[0]);
            $params = isset($parts[1]) ? trim($parts[1]) : '';

            // Check for aliases
            if (isset($this->aliases[$command])) {
                $command = $this->aliases[$command];
            }

            // Check if command exists
            if (! isset($this->commands[$command])) {
                return false;
            }

            // Get command handler class
            $handlerClass = $this->commands[$command];

            // Create and execute command handler
            $handler = new $handlerClass($this->telegram, $user);
            $handler->handle($message, $params);

            Log::info('Command executed', [
                'command' => $command,
                'user_id' => $user->id,
                'params' => $params,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Command routing failed', [
                'message' => $message,
                'error' => $e->getMessage(),
            ]);

            // Send error message to user
            $this->telegram->sendMessage(
                $user->telegram_id,
                '❌ Sorry, there was an error processing your command. Please try again.'
            );

            return false;
        }
    }

    /**
     * Get list of available commands for help display
     */
    public function getAvailableCommands(): array
    {
        return [
            '/start' => 'Start using the bot',
            '/help' => 'Show help and available commands',
            '/expenses_today' => "Show today's expenses",
            '/expenses_month' => 'Show current month expenses',
            '/expenses_week' => 'Show current week expenses',
            '/category_spending' => 'Show spending by category',
            '/top_categories' => 'Show top spending categories',
            '/export' => 'Export expenses to file',
            '/stats' => 'Show expense statistics and insights',
            '/language' => 'Change language',
            '/cancel' => 'Cancel current operation',
        ];
    }

    /**
     * Get command suggestions based on partial input
     */
    public function getSuggestions(string $input): array
    {
        $input = strtolower(trim($input));
        if (empty($input) || $input === '/') {
            return array_keys($this->commands);
        }

        $suggestions = [];

        // Check main commands
        foreach ($this->commands as $command => $handler) {
            if (strpos($command, $input) === 0) {
                $suggestions[] = $command;
            }
        }

        // Check aliases
        foreach ($this->aliases as $alias => $command) {
            if (strpos($alias, $input) === 0) {
                $suggestions[] = $alias;
            }
        }

        return array_unique($suggestions);
    }

    /**
     * Check if a text is a command
     */
    public function isCommand(string $text): bool
    {
        $text = trim($text);

        if (empty($text) || $text[0] !== '/') {
            return false;
        }

        $command = explode(' ', $text)[0];
        $command = strtolower($command);

        return isset($this->commands[$command]) || isset($this->aliases[$command]);
    }

    /**
     * Parse command and parameters from text
     */
    public function parseCommand(string $text): array
    {
        $parts = explode(' ', trim($text), 2);
        $command = strtolower($parts[0]);
        $params = isset($parts[1]) ? trim($parts[1]) : '';

        // Resolve alias
        if (isset($this->aliases[$command])) {
            $command = $this->aliases[$command];
        }

        return [
            'command' => $command,
            'params' => $params,
            'isValid' => isset($this->commands[$command]),
        ];
    }
}
