<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Food & Dining',
                'slug' => 'food_dining',
                'translation_key' => 'food_dining',
                'icon' => '🍽️',
                'color' => '#FF6B6B',
                'keywords' => ['comida', 'restaurante', 'food', 'dinner', 'lunch', 'breakfast', 'desayuno', 'almuerzo', 'cena'],
                'children' => [
                    ['name' => 'Restaurants', 'slug' => 'restaurants', 'translation_key' => 'restaurants', 'icon' => '🍽️', 'keywords' => ['restaurante', 'restaurant', 'cena', 'dinner', 'almuerzo', 'lunch']],
                    ['name' => 'Fast Food', 'slug' => 'fast_food', 'translation_key' => 'fast_food', 'icon' => '🍔', 'keywords' => ['mcdonalds', 'burger', 'pizza', 'kfc', 'subway', 'burger king', 'dominos', 'little caesars']],
                    ['name' => 'Groceries', 'slug' => 'groceries', 'translation_key' => 'groceries', 'icon' => '🛒', 'keywords' => ['soriana', 'walmart', 'supermarket', 'groceries', 'supermercado', 'chedraui', 'oxxo', 'seven eleven', '7-eleven']],
                    ['name' => 'Coffee Shops', 'slug' => 'coffee_shops', 'translation_key' => 'coffee_shops', 'icon' => '☕', 'keywords' => ['starbucks', 'cafe', 'coffee', 'café', 'punta del cielo', 'italian coffee', 'tim hortons']],
                    ['name' => 'Delivery', 'slug' => 'delivery', 'translation_key' => 'delivery', 'icon' => '🚚', 'keywords' => ['uber eats', 'rappi', 'didi food', 'delivery', 'entrega', 'a domicilio']],
                    ['name' => 'Alcohol', 'slug' => 'alcohol', 'translation_key' => 'alcohol', 'icon' => '🍺', 'keywords' => ['cerveza', 'beer', 'vino', 'wine', 'licor', 'alcohol', 'bar', 'cantina']],
                ],
            ],
            [
                'name' => 'Transportation',
                'slug' => 'transportation',
                'translation_key' => 'transportation',
                'icon' => '🚗',
                'color' => '#4ECDC4',
                'keywords' => ['transport', 'transporte', 'taxi', 'uber', 'metro', 'camion', 'autobus'],
                'children' => [
                    ['name' => 'Public Transport', 'slug' => 'public_transport', 'translation_key' => 'public_transport', 'icon' => '🚌', 'keywords' => ['metro', 'bus', 'metrobus', 'public transport', 'camion', 'autobus', 'ado']],
                    ['name' => 'Ride Sharing', 'slug' => 'ride_sharing', 'translation_key' => 'ride_sharing', 'icon' => '🚕', 'keywords' => ['uber', 'didi', 'taxi', 'ride', 'cabify', 'beat', 'indriver']],
                    ['name' => 'Fuel', 'slug' => 'fuel', 'translation_key' => 'fuel', 'icon' => '⛽', 'keywords' => ['gasolina', 'gas', 'fuel', 'pemex', 'shell', 'mobil', 'bp', 'oxxo gas']],
                    ['name' => 'Parking', 'slug' => 'parking', 'translation_key' => 'parking', 'icon' => '🅿️', 'keywords' => ['parking', 'estacionamiento', 'valet', 'parquímetro']],
                    ['name' => 'Vehicle Maintenance', 'slug' => 'vehicle_maintenance', 'translation_key' => 'vehicle_maintenance', 'icon' => '🔧', 'keywords' => ['mecanico', 'mechanic', 'service', 'servicio', 'oil change', 'cambio de aceite', 'llantas', 'tires']],
                    ['name' => 'Tolls', 'slug' => 'tolls', 'translation_key' => 'tolls', 'icon' => '🛣️', 'keywords' => ['caseta', 'toll', 'peaje', 'autopista', 'highway']],
                ],
            ],
            [
                'name' => 'Shopping',
                'slug' => 'shopping',
                'translation_key' => 'shopping',
                'icon' => '🛍️',
                'color' => '#45B7D1',
                'keywords' => ['shopping', 'compras', 'tienda', 'store', 'shop'],
                'children' => [
                    ['name' => 'Clothing', 'slug' => 'clothing', 'translation_key' => 'clothing', 'icon' => '👕', 'keywords' => ['ropa', 'clothing', 'zara', 'h&m', 'nike', 'adidas', 'liverpool', 'palacio de hierro', 'coppel']],
                    ['name' => 'Electronics', 'slug' => 'electronics', 'translation_key' => 'electronics', 'icon' => '📱', 'keywords' => ['electronics', 'phone', 'computer', 'laptop', 'apple', 'best buy', 'steren', 'telcel', 'at&t', 'samsung']],
                    ['name' => 'Home Goods', 'slug' => 'home_goods', 'translation_key' => 'home_goods', 'icon' => '🏠', 'keywords' => ['home depot', 'ikea', 'home', 'casa', 'muebles', 'furniture', 'decoracion']],
                    ['name' => 'Personal Care', 'slug' => 'personal_care', 'translation_key' => 'personal_care', 'icon' => '🧴', 'keywords' => ['pharmacy', 'farmacia', 'shampoo', 'soap', 'farmacias del ahorro', 'farmacias guadalajara', 'benavides']],
                    ['name' => 'Gifts', 'slug' => 'gifts', 'translation_key' => 'gifts', 'icon' => '🎁', 'keywords' => ['regalo', 'gift', 'present', 'cumpleaños', 'birthday']],
                    ['name' => 'Online Shopping', 'slug' => 'online_shopping', 'translation_key' => 'online_shopping', 'icon' => '🖥️', 'keywords' => ['amazon', 'mercado libre', 'online', 'internet', 'ebay', 'aliexpress', 'shein']],
                ],
            ],
            [
                'name' => 'Entertainment',
                'slug' => 'entertainment',
                'translation_key' => 'entertainment',
                'icon' => '🎬',
                'color' => '#96CEB4',
                'keywords' => ['entertainment', 'entretenimiento', 'movie', 'cinema', 'pelicula', 'diversion'],
                'children' => [
                    ['name' => 'Movies', 'slug' => 'movies', 'translation_key' => 'movies', 'icon' => '🎥', 'keywords' => ['cinema', 'cine', 'movie', 'cinemex', 'cinepolis', 'pelicula', 'film']],
                    ['name' => 'Concerts', 'slug' => 'concerts', 'translation_key' => 'concerts', 'icon' => '🎵', 'keywords' => ['concierto', 'concert', 'music', 'musica', 'ticketmaster', 'boletia']],
                    ['name' => 'Streaming Services', 'slug' => 'streaming_services', 'translation_key' => 'streaming_services', 'icon' => '📺', 'keywords' => ['netflix', 'spotify', 'amazon prime', 'disney plus', 'hbo max', 'paramount', 'apple tv', 'youtube premium']],
                    ['name' => 'Games', 'slug' => 'games', 'translation_key' => 'games', 'icon' => '🎮', 'keywords' => ['videogame', 'videojuego', 'playstation', 'xbox', 'nintendo', 'steam', 'gaming']],
                    ['name' => 'Sports', 'slug' => 'sports', 'translation_key' => 'sports', 'icon' => '⚽', 'keywords' => ['deporte', 'sport', 'futbol', 'football', 'gym', 'gimnasio', 'estadio']],
                    ['name' => 'Hobbies', 'slug' => 'hobbies', 'translation_key' => 'hobbies', 'icon' => '🎨', 'keywords' => ['hobby', 'pasatiempo', 'arte', 'art', 'craft', 'manualidades']],
                ],
            ],
            [
                'name' => 'Health & Wellness',
                'slug' => 'health_wellness',
                'translation_key' => 'health_wellness',
                'icon' => '🏥',
                'color' => '#FFEAA7',
                'keywords' => ['health', 'salud', 'doctor', 'medical', 'medico', 'hospital'],
                'children' => [
                    ['name' => 'Medical', 'slug' => 'medical', 'translation_key' => 'medical', 'icon' => '⚕️', 'keywords' => ['doctor', 'medico', 'hospital', 'clinic', 'clinica', 'consulta', 'appointment']],
                    ['name' => 'Pharmacy', 'slug' => 'pharmacy', 'translation_key' => 'pharmacy', 'icon' => '💊', 'keywords' => ['farmacia', 'pharmacy', 'medicine', 'medicina', 'medicamento', 'pills', 'pastillas']],
                    ['name' => 'Fitness', 'slug' => 'fitness', 'translation_key' => 'fitness', 'icon' => '💪', 'keywords' => ['gym', 'gimnasio', 'fitness', 'sports club', 'smart fit', 'sport city', 'yoga', 'pilates']],
                    ['name' => 'Dental', 'slug' => 'dental', 'translation_key' => 'dental', 'icon' => '🦷', 'keywords' => ['dentista', 'dentist', 'dental', 'teeth', 'dientes', 'ortodoncista']],
                    ['name' => 'Mental Health', 'slug' => 'mental_health', 'translation_key' => 'mental_health', 'icon' => '🧠', 'keywords' => ['psicologo', 'psychologist', 'terapia', 'therapy', 'psiquiatra', 'psychiatrist']],
                    ['name' => 'Supplements', 'slug' => 'supplements', 'translation_key' => 'supplements', 'icon' => '💊', 'keywords' => ['supplement', 'suplemento', 'vitamins', 'vitaminas', 'gnc', 'protein', 'proteina']],
                ],
            ],
            [
                'name' => 'Bills & Utilities',
                'slug' => 'bills_utilities',
                'translation_key' => 'bills_utilities',
                'icon' => '📄',
                'color' => '#DDA0DD',
                'keywords' => ['bill', 'recibo', 'utility', 'servicio', 'pago', 'payment'],
                'children' => [
                    ['name' => 'Rent/Mortgage', 'slug' => 'rent_mortgage', 'translation_key' => 'rent_mortgage', 'icon' => '🏠', 'keywords' => ['renta', 'rent', 'hipoteca', 'mortgage', 'casa', 'departamento', 'apartment']],
                    ['name' => 'Electricity', 'slug' => 'electricity', 'translation_key' => 'electricity', 'icon' => '⚡', 'keywords' => ['cfe', 'electricity', 'luz', 'electric', 'electricidad']],
                    ['name' => 'Water', 'slug' => 'water', 'translation_key' => 'water', 'icon' => '💧', 'keywords' => ['agua', 'water', 'sacmex', 'servicio de agua']],
                    ['name' => 'Internet', 'slug' => 'internet', 'translation_key' => 'internet', 'icon' => '🌐', 'keywords' => ['internet', 'wifi', 'telmex', 'izzi', 'totalplay', 'megacable', 'axtel']],
                    ['name' => 'Phone', 'slug' => 'phone', 'translation_key' => 'phone', 'icon' => '📞', 'keywords' => ['phone', 'telefono', 'telcel', 'movistar', 'at&t', 'cellular', 'celular']],
                    ['name' => 'Insurance', 'slug' => 'insurance', 'translation_key' => 'insurance', 'icon' => '🛡️', 'keywords' => ['seguro', 'insurance', 'gnp', 'axa', 'metlife', 'seguros']],
                ],
            ],
            [
                'name' => 'Education',
                'slug' => 'education',
                'translation_key' => 'education',
                'icon' => '📚',
                'color' => '#74B9FF',
                'keywords' => ['education', 'educacion', 'school', 'escuela', 'learning', 'aprendizaje'],
                'children' => [
                    ['name' => 'Courses', 'slug' => 'courses', 'translation_key' => 'courses', 'icon' => '📖', 'keywords' => ['curso', 'course', 'class', 'clase', 'udemy', 'coursera', 'platzi']],
                    ['name' => 'Books', 'slug' => 'books', 'translation_key' => 'books', 'icon' => '📚', 'keywords' => ['libro', 'book', 'libreria', 'bookstore', 'gandhi', 'sanborns', 'amazon books']],
                    ['name' => 'Certifications', 'slug' => 'certifications', 'translation_key' => 'certifications', 'icon' => '🎓', 'keywords' => ['certificacion', 'certification', 'exam', 'examen', 'titulo', 'degree']],
                    ['name' => 'Conferences', 'slug' => 'conferences', 'translation_key' => 'conferences', 'icon' => '🎤', 'keywords' => ['conferencia', 'conference', 'seminario', 'seminar', 'workshop', 'taller']],
                    ['name' => 'Subscriptions', 'slug' => 'education_subscriptions', 'translation_key' => 'education_subscriptions', 'icon' => '💻', 'keywords' => ['subscription', 'suscripcion', 'membership', 'membresia', 'learning platform']],
                ],
            ],
            [
                'name' => 'Services',
                'slug' => 'services',
                'translation_key' => 'services',
                'icon' => '🔧',
                'color' => '#A29BFE',
                'keywords' => ['service', 'servicio', 'professional', 'profesional'],
                'children' => [
                    ['name' => 'Professional Services', 'slug' => 'professional_services', 'translation_key' => 'professional_services', 'icon' => '💼', 'keywords' => ['abogado', 'lawyer', 'contador', 'accountant', 'consultor', 'consultant']],
                    ['name' => 'Home Services', 'slug' => 'home_services', 'translation_key' => 'home_services', 'icon' => '🏠', 'keywords' => ['plomero', 'plumber', 'electricista', 'electrician', 'limpieza', 'cleaning', 'jardinero']],
                    ['name' => 'Financial Services', 'slug' => 'financial_services', 'translation_key' => 'financial_services', 'icon' => '💰', 'keywords' => ['banco', 'bank', 'financial', 'financiero', 'inversion', 'investment']],
                    ['name' => 'Subscription Services', 'slug' => 'subscription_services', 'translation_key' => 'subscription_services', 'icon' => '📱', 'keywords' => ['subscription', 'suscripcion', 'monthly', 'mensual', 'anual', 'yearly']],
                ],
            ],
            [
                'name' => 'Travel',
                'slug' => 'travel',
                'translation_key' => 'travel',
                'icon' => '✈️',
                'color' => '#55A3FF',
                'keywords' => ['travel', 'viaje', 'vacation', 'vacaciones', 'trip'],
                'children' => [
                    ['name' => 'Flights', 'slug' => 'flights', 'translation_key' => 'flights', 'icon' => '✈️', 'keywords' => ['vuelo', 'flight', 'aeromexico', 'volaris', 'viva aerobus', 'airplane', 'avion']],
                    ['name' => 'Hotels', 'slug' => 'hotels', 'translation_key' => 'hotels', 'icon' => '🏨', 'keywords' => ['hotel', 'airbnb', 'booking', 'hospedaje', 'accommodation', 'alojamiento']],
                    ['name' => 'Vacation Expenses', 'slug' => 'vacation_expenses', 'translation_key' => 'vacation_expenses', 'icon' => '🏖️', 'keywords' => ['vacation', 'vacaciones', 'turismo', 'tourism', 'excursion', 'tour']],
                    ['name' => 'Travel Insurance', 'slug' => 'travel_insurance', 'translation_key' => 'travel_insurance', 'icon' => '🛡️', 'keywords' => ['travel insurance', 'seguro de viaje', 'seguro viajero']],
                ],
            ],
            [
                'name' => 'Other',
                'slug' => 'other',
                'translation_key' => 'other',
                'icon' => '📋',
                'color' => '#B8B8B8',
                'keywords' => ['other', 'otro', 'misc', 'miscellaneous', 'varios'],
                'children' => [
                    ['name' => 'Miscellaneous', 'slug' => 'miscellaneous', 'translation_key' => 'miscellaneous', 'icon' => '🔸', 'keywords' => ['misc', 'miscellaneous', 'varios', 'other', 'otro']],
                    ['name' => 'Uncategorized', 'slug' => 'uncategorized', 'translation_key' => 'uncategorized', 'icon' => '❓', 'keywords' => ['uncategorized', 'sin categoria', 'unknown', 'desconocido']],
                ],
            ],
        ];

        foreach ($categories as $categoryData) {
            $children = $categoryData['children'] ?? [];
            unset($categoryData['children']);

            $parent = Category::create($categoryData);

            foreach ($children as $childData) {
                $childData['parent_id'] = $parent->id;
                $childData['color'] = $parent->color;
                Category::create($childData);
            }
        }
    }
}