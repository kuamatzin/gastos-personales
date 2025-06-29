<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Telegram\Contracts\Command;
use Telegram\Bot\Api;

class SetTimezoneCommand extends Command
{
    protected string $name = 'timezone';
    protected string $description = 'Configura tu zona horaria';
    protected array $aliases = ['tz', 'zona_horaria', 'set_timezone'];

    // Common Mexican timezones
    private const TIMEZONES = [
        'Mexico/General' => 'Ciudad de México, Guadalajara, Monterrey',
        'Mexico/BajaNorte' => 'Tijuana, Mexicali',
        'Mexico/BajaSur' => 'La Paz, Cabo San Lucas',
        'America/Cancun' => 'Cancún, Playa del Carmen',
    ];

    public function handle(Api $telegram, User $user, array $arguments): void
    {
        if (empty($arguments)) {
            $this->showTimezoneMenu($telegram, $user);
            return;
        }

        // If user provided a timezone argument
        $timezone = trim(implode(' ', $arguments));
        
        // Map common names to actual timezone IDs
        $timezoneMap = [
            'cdmx' => 'America/Mexico_City',
            'ciudad de mexico' => 'America/Mexico_City',
            'mexico' => 'America/Mexico_City',
            'tijuana' => 'Mexico/BajaNorte',
            'cancun' => 'America/Cancun',
            'la paz' => 'Mexico/BajaSur',
        ];

        $normalizedInput = strtolower($timezone);
        $actualTimezone = $timezoneMap[$normalizedInput] ?? $timezone;

        // Validate timezone
        if (!in_array($actualTimezone, timezone_identifiers_list())) {
            $telegram->sendMessage([
                'chat_id' => $user->telegram_id,
                'text' => "❌ Zona horaria inválida: *{$timezone}*\n\n" .
                         "Por favor selecciona una de las opciones o escribe una zona horaria válida.",
                'parse_mode' => 'Markdown',
            ]);
            $this->showTimezoneMenu($telegram, $user);
            return;
        }

        // Update user's timezone
        $user->update(['timezone' => $actualTimezone]);

        // Get current time in new timezone
        $currentTime = now($actualTimezone)->format('H:i');
        
        $telegram->sendMessage([
            'chat_id' => $user->telegram_id,
            'text' => "✅ Zona horaria actualizada a: *{$actualTimezone}*\n\n" .
                     "🕐 Hora actual en tu zona: {$currentTime}\n\n" .
                     "Todos tus gastos se registrarán con la fecha correcta según tu zona horaria.",
            'parse_mode' => 'Markdown',
        ]);
    }

    private function showTimezoneMenu(Api $telegram, User $user): void
    {
        $currentTimezone = $user->getTimezone();
        $currentTime = now($currentTimezone)->format('H:i (l, F j)');
        
        $keyboard = [];
        
        // Add common Mexican timezones as buttons
        foreach (self::TIMEZONES as $tz => $description) {
            $keyboard[] = [[
                'text' => $description,
                'callback_data' => "set_timezone:{$tz}"
            ]];
        }
        
        // Add current timezone info
        $keyboard[] = [[
            'text' => '❌ Cancelar',
            'callback_data' => 'cancel'
        ]];

        $telegram->sendMessage([
            'chat_id' => $user->telegram_id,
            'text' => "🌍 *Configuración de Zona Horaria*\n\n" .
                     "Zona horaria actual: *{$currentTimezone}*\n" .
                     "Hora actual: {$currentTime}\n\n" .
                     "Selecciona tu zona horaria:",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ]);
    }
}