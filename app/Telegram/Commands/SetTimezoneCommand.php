<?php

namespace App\Telegram\Commands;

class SetTimezoneCommand extends Command
{
    protected string $name = 'timezone';
    protected string $description = 'Configura tu zona horaria';
    protected array $aliases = ['tz', 'zona_horaria', 'set_timezone'];

    // Common Mexican timezones
    private const TIMEZONES = [
        'America/Mexico_City' => 'Ciudad de México, Guadalajara, Monterrey',
        'America/Tijuana' => 'Tijuana, Mexicali',
        'America/Mazatlan' => 'Mazatlán, La Paz, Cabo San Lucas',
        'America/Cancun' => 'Cancún, Playa del Carmen',
    ];

    public function handle(array $message, string $params = ''): void
    {
        if (empty($params)) {
            $this->showTimezoneMenu();
            return;
        }

        // If user provided a timezone argument
        $timezone = trim($params);
        
        // Map common names to actual timezone IDs
        $timezoneMap = [
            'cdmx' => 'America/Mexico_City',
            'ciudad de mexico' => 'America/Mexico_City',
            'mexico' => 'America/Mexico_City',
            'tijuana' => 'America/Tijuana',
            'cancun' => 'America/Cancun',
            'mazatlan' => 'America/Mazatlan',
            'la paz' => 'America/Mazatlan',
        ];

        $normalizedInput = strtolower($timezone);
        $actualTimezone = $timezoneMap[$normalizedInput] ?? $timezone;

        // Validate timezone
        if (!in_array($actualTimezone, timezone_identifiers_list())) {
            $this->reply(
                "❌ Zona horaria inválida: *{$timezone}*\n\n" .
                "Por favor selecciona una de las opciones o escribe una zona horaria válida.",
                ['parse_mode' => 'Markdown']
            );
            $this->showTimezoneMenu();
            return;
        }

        // Update user's timezone
        $this->user->update(['timezone' => $actualTimezone]);

        // Get current time in new timezone
        $currentTime = now($actualTimezone)->format('H:i');
        
        $this->reply(
            "✅ Zona horaria actualizada a: *{$actualTimezone}*\n\n" .
            "🕐 Hora actual en tu zona: {$currentTime}\n\n" .
            "Todos tus gastos se registrarán con la fecha correcta según tu zona horaria.",
            ['parse_mode' => 'Markdown']
        );
        
        $this->logExecution('timezone_updated', [
            'old_timezone' => $this->user->getOriginal('timezone'),
            'new_timezone' => $actualTimezone
        ]);
    }

    private function showTimezoneMenu(): void
    {
        $currentTimezone = $this->user->getTimezone();
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

        $this->replyWithKeyboard(
            "🌍 *Configuración de Zona Horaria*\n\n" .
            "Zona horaria actual: *{$currentTimezone}*\n" .
            "Hora actual: {$currentTime}\n\n" .
            "Selecciona tu zona horaria:",
            $keyboard,
            ['parse_mode' => 'Markdown']
        );
        
        $this->logExecution('show_menu', ['current_timezone' => $currentTimezone]);
    }
}