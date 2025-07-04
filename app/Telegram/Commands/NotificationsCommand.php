<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Log;

class NotificationsCommand extends Command
{
    protected string $name = 'notifications';

    public function handle(array $message, string $params = ''): void
    {
        $chatId = $message['chat']['id'];
        $user = $this->user;
        $language = $user->language ?? 'es';

        try {
            $preferences = $user->preferences ?? [];
            $notifications = $preferences['notifications'] ?? [];
            
            $dailySummaryEnabled = $notifications['daily_summary'] ?? false;
            $dailySummaryTime = $notifications['daily_summary_time'] ?? 21;
            $weeklySummaryEnabled = $notifications['weekly_summary'] ?? false;
            $weeklySummaryTime = $notifications['weekly_summary_time'] ?? 20;
            $monthlySummaryEnabled = $notifications['monthly_summary'] ?? false;
            $monthlySummaryTime = $notifications['monthly_summary_time'] ?? 20;
            
            $message = trans('telegram.notifications_title', [], $language) . "\n\n";
            
            // Daily summary status
            if ($dailySummaryEnabled) {
                $message .= "✅ " . trans('telegram.daily_summary_enabled', [
                    'time' => $dailySummaryTime . ':00',
                ], $language) . "\n";
            } else {
                $message .= "❌ " . trans('telegram.daily_summary_disabled', [], $language) . "\n";
            }
            
            // Weekly summary status
            if ($weeklySummaryEnabled) {
                $message .= "✅ " . trans('telegram.weekly_summary_enabled_with_time', [
                    'time' => $weeklySummaryTime . ':00',
                ], $language) . "\n";
            } else {
                $message .= "❌ " . trans('telegram.weekly_summary_disabled', [], $language) . "\n";
            }
            
            // Monthly summary status
            if ($monthlySummaryEnabled) {
                $message .= "✅ " . trans('telegram.monthly_summary_enabled_with_time', [
                    'time' => $monthlySummaryTime . ':00',
                ], $language) . "\n";
            } else {
                $message .= "❌ " . trans('telegram.monthly_summary_disabled', [], $language) . "\n";
            }
            
            // Create keyboard
            $keyboard = [];
            
            // Daily summary buttons
            if ($dailySummaryEnabled) {
                $keyboard[] = [
                    [
                        'text' => trans('telegram.button_disable_daily_summary', [], $language),
                        'callback_data' => 'notif_daily_disable',
                    ],
                    [
                        'text' => trans('telegram.button_change_time', [], $language),
                        'callback_data' => 'notif_daily_time',
                    ],
                ];
            } else {
                $keyboard[] = [
                    [
                        'text' => trans('telegram.button_enable_daily_summary', [], $language),
                        'callback_data' => 'notif_daily_enable',
                    ],
                ];
            }
            
            // Weekly summary buttons
            if ($weeklySummaryEnabled) {
                $keyboard[] = [
                    [
                        'text' => trans('telegram.button_disable_weekly_summary', [], $language),
                        'callback_data' => 'notif_weekly_disable',
                    ],
                    [
                        'text' => trans('telegram.button_change_weekly_time', [], $language),
                        'callback_data' => 'notif_weekly_time',
                    ],
                ];
            } else {
                $keyboard[] = [
                    [
                        'text' => trans('telegram.button_enable_weekly_summary', [], $language),
                        'callback_data' => 'notif_weekly_enable',
                    ],
                ];
            }
            
            // Monthly summary buttons
            if ($monthlySummaryEnabled) {
                $keyboard[] = [
                    [
                        'text' => trans('telegram.button_disable_monthly_summary', [], $language),
                        'callback_data' => 'notif_monthly_disable',
                    ],
                    [
                        'text' => trans('telegram.button_change_monthly_time', [], $language),
                        'callback_data' => 'notif_monthly_time',
                    ],
                ];
            } else {
                $keyboard[] = [
                    [
                        'text' => trans('telegram.button_enable_monthly_summary', [], $language),
                        'callback_data' => 'notif_monthly_enable',
                    ],
                ];
            }
            
            // Add test button
            $keyboard[] = [
                [
                    'text' => trans('telegram.button_test_daily_summary', [], $language),
                    'callback_data' => 'notif_test_daily',
                ],
            ];

            $this->telegram->sendMessageWithKeyboard($chatId, $message, $keyboard, [
                'parse_mode' => 'Markdown',
            ]);

        } catch (\Exception $e) {
            Log::error('Error in NotificationsCommand', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                trans('telegram.error_processing', [], $language)
            );
        }
    }
}