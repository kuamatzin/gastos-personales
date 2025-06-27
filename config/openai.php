<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenAI API integration including model settings,
    | temperature controls, and prompt templates.
    |
    */

    'models' => [
        'expense_extraction' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
        'category_inference' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
    ],

    'max_tokens' => [
        'expense_extraction' => 250,
        'category_inference' => 150,
        'image_processing' => 350,
    ],

    'temperature' => [
        'expense_extraction' => 0.1,  // Low temperature for consistent extraction
        'category_inference' => 0.2,  // Slightly higher for category flexibility
    ],

    /*
    |--------------------------------------------------------------------------
    | Mexican Context Keywords
    |--------------------------------------------------------------------------
    |
    | Common Mexican stores, services, and terms to help with extraction
    |
    */
    'mexican_context' => [
        'stores' => [
            'oxxo', 'seven eleven', '7-eleven', 'soriana', 'walmart', 'chedraui',
            'liverpool', 'palacio de hierro', 'sanborns', 'vips', 'toks',
            'starbucks', 'italian coffee', 'punta del cielo',
        ],

        'transport' => [
            'uber', 'didi', 'cabify', 'metrobus', 'metro', 'ado', 'estrella roja',
            'pemex', 'shell', 'mobil', 'bp', 'oxxo gas',
        ],

        'food_places' => [
            'tacos', 'tortas', 'quesadillas', 'tamales', 'elotes',
            'mcdonalds', 'burger king', 'kfc', 'dominos', 'pizza hut',
            'little caesars', 'subway', 'carl\'s jr',
        ],

        'services' => [
            'telmex', 'telcel', 'at&t', 'movistar', 'izzi', 'totalplay',
            'cfe', 'sacmex', 'gas natural',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Common Spanish Number Words
    |--------------------------------------------------------------------------
    */
    'spanish_numbers' => [
        'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5,
        'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10,
        'veinte' => 20, 'treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50,
        'cien' => 100, 'ciento' => 100, 'doscientos' => 200, 'trescientos' => 300,
        'cuatrocientos' => 400, 'quinientos' => 500, 'mil' => 1000,
    ],
];
