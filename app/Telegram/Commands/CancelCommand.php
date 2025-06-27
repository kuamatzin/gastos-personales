<?php

namespace App\Telegram\Commands;

class CancelCommand extends Command
{
    protected string $name = 'cancel';
    
    public function handle(array $message, string $params = ''): void
    {
        try {
            // Clear any user state/session
            $this->clearUserState();
            
            $this->removeKeyboard("✅ Operation cancelled.\n\nWhat would you like to do next?");
            
            // Show main menu options
            $keyboard = [
                [
                    ['text' => '📊 Today\'s Expenses', 'callback_data' => 'cmd_expenses_today'],
                    ['text' => '📅 This Month', 'callback_data' => 'cmd_expenses_month']
                ],
                [
                    ['text' => '📈 Statistics', 'callback_data' => 'cmd_stats'],
                    ['text' => '❓ Help', 'callback_data' => 'cmd_help']
                ]
            ];
            
            $this->replyWithKeyboard(
                "You can send me an expense or use one of these commands:",
                $keyboard
            );
            
            $this->logExecution('cancelled');
            
        } catch (\Exception $e) {
            $this->logError('Failed to cancel operation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Send a simple fallback message
            $this->reply("✅ Operation cancelled.");
        }
    }
    
    /**
     * Clear any pending user state
     */
    private function clearUserState(): void
    {
        // Clear any session data or pending operations
        // This would be implemented based on your session management
        
        // For now, just clear cache keys related to user operations
        $cacheKeys = [
            "user_{$this->user->id}_pending_expense",
            "user_{$this->user->id}_export_settings",
            "user_{$this->user->id}_current_operation"
        ];
        
        foreach ($cacheKeys as $key) {
            \Cache::forget($key);
        }
    }
}