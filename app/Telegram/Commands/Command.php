<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

abstract class Command
{
    protected TelegramService $telegram;

    protected User $user;

    /**
     * Command name for logging
     */
    protected string $name = 'command';

    /**
     * Whether command requires authentication
     */
    protected bool $requiresAuth = true;

    public function __construct(TelegramService $telegram, User $user)
    {
        $this->telegram = $telegram;
        $this->user = $user;
    }

    /**
     * Handle the command
     */
    abstract public function handle(array $message, string $params = ''): void;

    /**
     * Send a reply to the user
     */
    protected function reply(string $text, array $options = []): void
    {
        $this->telegram->sendMessage(
            $this->user->telegram_id,
            $text,
            $options
        );
    }

    /**
     * Translate a message key with the user's language
     */
    protected function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return trans($key, $replace, $locale ?? $this->user->language ?? 'es');
    }

    /**
     * Send a reply with safe Markdown parsing
     */
    protected function replyWithMarkdown(string $text, array $options = []): void
    {
        try {
            $options['parse_mode'] = 'Markdown';
            $this->reply($text, $options);
        } catch (\Exception $e) {
            $this->logError('Markdown parsing failed, sending plain text', ['error' => $e->getMessage()]);

            // Send without markdown
            $plainText = str_replace(['*', '`', '_'], '', $text);
            unset($options['parse_mode']);
            $this->reply($plainText, $options);
        }
    }

    /**
     * Escape special Markdown characters
     */
    protected function escapeMarkdown(string $text): string
    {
        return str_replace(['_', '*', '`', '[', ']'], ['\_', '\*', '\`', '\[', '\]'], $text);
    }

    /**
     * Send a reply with inline keyboard
     */
    protected function replyWithKeyboard(string $text, array $keyboard, array $options = []): void
    {
        $options['reply_markup'] = [
            'inline_keyboard' => $keyboard,
        ];

        $this->reply($text, $options);
    }

    /**
     * Send a reply with inline keyboard and safe Markdown parsing
     */
    protected function replyWithKeyboardMarkdown(string $text, array $keyboard, array $options = []): void
    {
        $options['reply_markup'] = [
            'inline_keyboard' => $keyboard,
        ];

        $this->replyWithMarkdown($text, $options);
    }

    /**
     * Send a reply with custom keyboard
     */
    protected function replyWithCustomKeyboard(string $text, array $keyboard, array $options = []): void
    {
        $options['reply_markup'] = [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => $options['one_time'] ?? false,
        ];

        $this->reply($text, $options);
    }

    /**
     * Remove custom keyboard
     */
    protected function removeKeyboard(string $text): void
    {
        $this->reply($text, [
            'reply_markup' => [
                'remove_keyboard' => true,
            ],
        ]);
    }

    /**
     * Send typing action
     */
    protected function sendTyping(): void
    {
        $this->telegram->sendChatAction($this->user->telegram_id, 'typing');
    }

    /**
     * Log command execution
     */
    protected function logExecution(string $action, array $context = []): void
    {
        Log::info("Command {$this->name} - {$action}", array_merge([
            'user_id' => $this->user->id,
            'telegram_id' => $this->user->telegram_id,
        ], $context));
    }

    /**
     * Log command error
     */
    protected function logError(string $error, array $context = []): void
    {
        Log::error("Command {$this->name} error", array_merge([
            'user_id' => $this->user->id,
            'telegram_id' => $this->user->telegram_id,
            'error' => $error,
        ], $context));
    }

    /**
     * Parse date from parameters
     */
    protected function parseDate(string $params): ?\Carbon\Carbon
    {
        if (empty($params)) {
            return null;
        }

        try {
            // Try various date formats with user's timezone
            $formats = [
                'd/m/Y',
                'd-m-Y',
                'Y-m-d',
                'd/m/y',
                'd-m-y',
            ];

            foreach ($formats as $format) {
                try {
                    return \Carbon\Carbon::createFromFormat($format, $params, $this->user->getTimezone());
                } catch (\Exception $e) {
                    continue;
                }
            }

            // Try natural language parsing with user's timezone
            return \Carbon\Carbon::parse($params, $this->user->getTimezone());

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse month from parameters
     */
    protected function parseMonth(string $params): ?\Carbon\Carbon
    {
        if (empty($params)) {
            return \Carbon\Carbon::now($this->user->getTimezone())->startOfMonth();
        }

        $params = strtolower(trim($params));

        // Month names in Spanish
        $spanishMonths = [
            'enero' => 'january',
            'febrero' => 'february',
            'marzo' => 'march',
            'abril' => 'april',
            'mayo' => 'may',
            'junio' => 'june',
            'julio' => 'july',
            'agosto' => 'august',
            'septiembre' => 'september',
            'octubre' => 'october',
            'noviembre' => 'november',
            'diciembre' => 'december',
        ];

        // Replace Spanish month names
        foreach ($spanishMonths as $spanish => $english) {
            $params = str_replace($spanish, $english, $params);
        }

        try {
            // Try parsing as month name with user's timezone
            if (in_array($params, array_values($spanishMonths))) {
                return \Carbon\Carbon::parse($params, $this->user->getTimezone())->startOfMonth();
            }

            // Try parsing as date
            $date = $this->parseDate($params);

            return $date ? $date->startOfMonth() : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get emoji for category
     */
    protected function getCategoryEmoji(string $category): string
    {
        $emojis = [
            'food' => '🍽️',
            'comida' => '🍽️',
            'transport' => '🚗',
            'transporte' => '🚗',
            'shopping' => '🛍️',
            'compras' => '🛍️',
            'entertainment' => '🎬',
            'entretenimiento' => '🎬',
            'health' => '💊',
            'salud' => '💊',
            'bills' => '📱',
            'servicios' => '📱',
            'education' => '📚',
            'educacion' => '📚',
            'other' => '📦',
            'otros' => '📦',
            'home' => '🏠',
            'hogar' => '🏠',
            'personal' => '👤',
            'travel' => '✈️',
            'viajes' => '✈️',
        ];

        $key = strtolower($category);

        return $emojis[$key] ?? '📌';
    }

    /**
     * Format currency amount
     */
    protected function formatMoney(float $amount, string $currency = 'MXN'): string
    {
        return '$'.number_format($amount, 2).' '.$currency;
    }

    /**
     * Format percentage
     */
    protected function formatPercentage(float $value): string
    {
        $formatted = number_format($value, 1);

        if ($value > 0) {
            return '+'.$formatted.'%';
        }

        return $formatted.'%';
    }

    /**
     * Check if user has expenses
     */
    protected function userHasExpenses(): bool
    {
        return $this->user->expenses()->confirmed()->exists();
    }

    /**
     * Send "no expenses" message
     */
    protected function sendNoExpensesMessage(string $period = ''): void
    {
        $message = "📊 You don't have any expenses recorded";

        if (! empty($period)) {
            $message .= " for {$period}";
        }

        $message .= ".\n\nStart by sending me a photo of a receipt, a voice note, or a text message with your expense!";

        $this->reply($message);
    }
}
