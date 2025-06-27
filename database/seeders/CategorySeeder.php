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
                'icon' => '🍽️',
                'color' => '#FF6B6B',
                'keywords' => ['comida', 'restaurante', 'food', 'dinner', 'lunch', 'breakfast', 'desayuno', 'almuerzo', 'cena'],
                'children' => [
                    ['name' => 'Restaurants', 'slug' => 'restaurants', 'icon' => '🍽️', 'keywords' => ['restaurante', 'restaurant', 'cena', 'dinner', 'almuerzo', 'lunch']],
                    ['name' => 'Fast Food', 'slug' => 'fast_food', 'icon' => '🍔', 'keywords' => ['mcdonalds', 'burger', 'pizza', 'kfc', 'subway', 'burger king', 'dominos', 'little caesars']],
                    ['name' => 'Groceries', 'slug' => 'groceries', 'icon' => '🛒', 'keywords' => ['soriana', 'walmart', 'supermarket', 'groceries', 'supermercado', 'chedraui', 'oxxo', 'seven eleven', '7-eleven']],
                    ['name' => 'Coffee Shops', 'slug' => 'coffee_shops', 'icon' => '☕', 'keywords' => ['starbucks', 'cafe', 'coffee', 'café', 'punta del cielo', 'italian coffee', 'tim hortons']],
                    ['name' => 'Delivery', 'slug' => 'delivery', 'icon' => '🚚', 'keywords' => ['uber eats', 'rappi', 'didi food', 'delivery', 'entrega', 'a domicilio']],
                    ['name' => 'Alcohol', 'slug' => 'alcohol', 'icon' => '🍺', 'keywords' => ['cerveza', 'beer', 'vino', 'wine', 'licor', 'alcohol', 'bar', 'cantina']],
                ],
            ],
            [
                'name' => 'Transportation',
                'slug' => 'transportation',
                'icon' => '🚗',
                'color' => '#4ECDC4',
                'keywords' => ['transport', 'transporte', 'taxi', 'uber', 'metro', 'camion', 'autobus'],
                'children' => [
                    ['name' => 'Public Transport', 'slug' => 'public_transport', 'icon' => '🚌', 'keywords' => ['metro', 'bus', 'metrobus', 'public transport', 'camion', 'autobus', 'ado']],
                    ['name' => 'Ride Sharing', 'slug' => 'ride_sharing', 'icon' => '🚕', 'keywords' => ['uber', 'didi', 'taxi', 'ride', 'cabify', 'beat', 'indriver']],
                    ['name' => 'Fuel', 'slug' => 'fuel', 'icon' => '⛽', 'keywords' => ['gasolina', 'gas', 'fuel', 'pemex', 'shell', 'mobil', 'bp', 'oxxo gas']],
                    ['name' => 'Parking', 'slug' => 'parking', 'icon' => '🅿️', 'keywords' => ['parking', 'estacionamiento', 'valet', 'parquímetro']],
                    ['name' => 'Vehicle Maintenance', 'slug' => 'vehicle_maintenance', 'icon' => '🔧', 'keywords' => ['mecanico', 'mechanic', 'service', 'servicio', 'oil change', 'cambio de aceite', 'llantas', 'tires']],
                    ['name' => 'Tolls', 'slug' => 'tolls', 'icon' => '🛣️', 'keywords' => ['caseta', 'toll', 'peaje', 'autopista', 'highway']],
                ],
            ],
            [
                'name' => 'Shopping',
                'slug' => 'shopping',
                'icon' => '🛍️',
                'color' => '#45B7D1',
                'keywords' => ['shopping', 'compras', 'tienda', 'store', 'shop'],
                'children' => [
                    ['name' => 'Clothing', 'slug' => 'clothing', 'icon' => '👕', 'keywords' => ['ropa', 'clothing', 'zara', 'h&m', 'nike', 'adidas', 'liverpool', 'palacio de hierro', 'coppel']],
                    ['name' => 'Electronics', 'slug' => 'electronics', 'icon' => '📱', 'keywords' => ['electronics', 'phone', 'computer', 'laptop', 'apple', 'best buy', 'steren', 'telcel', 'at&t', 'samsung']],
                    ['name' => 'Home Goods', 'slug' => 'home_goods', 'icon' => '🏠', 'keywords' => ['home depot', 'ikea', 'home', 'casa', 'muebles', 'furniture', 'decoracion']],
                    ['name' => 'Personal Care', 'slug' => 'personal_care', 'icon' => '🧴', 'keywords' => ['pharmacy', 'farmacia', 'shampoo', 'soap', 'farmacias del ahorro', 'farmacias guadalajara', 'benavides']],
                    ['name' => 'Gifts', 'slug' => 'gifts', 'icon' => '🎁', 'keywords' => ['regalo', 'gift', 'present', 'cumpleaños', 'birthday']],
                    ['name' => 'Online Shopping', 'slug' => 'online_shopping', 'icon' => '🖥️', 'keywords' => ['amazon', 'mercado libre', 'online', 'internet', 'ebay', 'aliexpress', 'shein']],
                ],
            ],
            [
                'name' => 'Entertainment',
                'slug' => 'entertainment',
                'icon' => '🎬',
                'color' => '#96CEB4',
                'keywords' => ['entertainment', 'entretenimiento', 'movie', 'cinema', 'pelicula', 'diversion'],
                'children' => [
                    ['name' => 'Movies', 'slug' => 'movies', 'icon' => '🎥', 'keywords' => ['cinema', 'cine', 'movie', 'cinemex', 'cinepolis', 'pelicula', 'film']],
                    ['name' => 'Concerts', 'slug' => 'concerts', 'icon' => '🎵', 'keywords' => ['concierto', 'concert', 'music', 'musica', 'ticketmaster', 'boletia']],
                    ['name' => 'Streaming Services', 'slug' => 'streaming_services', 'icon' => '📺', 'keywords' => ['netflix', 'spotify', 'amazon prime', 'disney plus', 'hbo max', 'paramount', 'apple tv', 'youtube premium']],
                    ['name' => 'Games', 'slug' => 'games', 'icon' => '🎮', 'keywords' => ['videogame', 'videojuego', 'playstation', 'xbox', 'nintendo', 'steam', 'gaming']],
                    ['name' => 'Sports', 'slug' => 'sports', 'icon' => '⚽', 'keywords' => ['deporte', 'sport', 'futbol', 'football', 'gym', 'gimnasio', 'estadio']],
                    ['name' => 'Hobbies', 'slug' => 'hobbies', 'icon' => '🎨', 'keywords' => ['hobby', 'pasatiempo', 'arte', 'art', 'craft', 'manualidades']],
                ],
            ],
            [
                'name' => 'Health & Wellness',
                'slug' => 'health_wellness',
                'icon' => '🏥',
                'color' => '#FFEAA7',
                'keywords' => ['health', 'salud', 'doctor', 'medical', 'medico', 'hospital'],
                'children' => [
                    ['name' => 'Medical', 'slug' => 'medical', 'icon' => '⚕️', 'keywords' => ['doctor', 'medico', 'hospital', 'clinic', 'clinica', 'consulta', 'appointment']],
                    ['name' => 'Pharmacy', 'slug' => 'pharmacy', 'icon' => '💊', 'keywords' => ['farmacia', 'pharmacy', 'medicine', 'medicina', 'medicamento', 'pills', 'pastillas']],
                    ['name' => 'Fitness', 'slug' => 'fitness', 'icon' => '💪', 'keywords' => ['gym', 'gimnasio', 'fitness', 'sports club', 'smart fit', 'sport city', 'yoga', 'pilates']],
                    ['name' => 'Dental', 'slug' => 'dental', 'icon' => '🦷', 'keywords' => ['dentista', 'dentist', 'dental', 'teeth', 'dientes', 'ortodoncista']],
                    ['name' => 'Mental Health', 'slug' => 'mental_health', 'icon' => '🧠', 'keywords' => ['psicologo', 'psychologist', 'terapia', 'therapy', 'psiquiatra', 'psychiatrist']],
                    ['name' => 'Supplements', 'slug' => 'supplements', 'icon' => '💊', 'keywords' => ['supplement', 'suplemento', 'vitamins', 'vitaminas', 'gnc', 'protein', 'proteina']],
                ],
            ],
            [
                'name' => 'Bills & Utilities',
                'slug' => 'bills_utilities',
                'icon' => '📄',
                'color' => '#DDA0DD',
                'keywords' => ['bill', 'recibo', 'utility', 'servicio', 'pago', 'payment'],
                'children' => [
                    ['name' => 'Rent/Mortgage', 'slug' => 'rent_mortgage', 'icon' => '🏠', 'keywords' => ['renta', 'rent', 'hipoteca', 'mortgage', 'casa', 'departamento', 'apartment']],
                    ['name' => 'Electricity', 'slug' => 'electricity', 'icon' => '⚡', 'keywords' => ['cfe', 'electricity', 'luz', 'electric', 'electricidad']],
                    ['name' => 'Water', 'slug' => 'water', 'icon' => '💧', 'keywords' => ['agua', 'water', 'sacmex', 'servicio de agua']],
                    ['name' => 'Internet', 'slug' => 'internet', 'icon' => '🌐', 'keywords' => ['internet', 'wifi', 'telmex', 'izzi', 'totalplay', 'megacable', 'axtel']],
                    ['name' => 'Phone', 'slug' => 'phone', 'icon' => '📞', 'keywords' => ['phone', 'telefono', 'telcel', 'movistar', 'at&t', 'cellular', 'celular']],
                    ['name' => 'Insurance', 'slug' => 'insurance', 'icon' => '🛡️', 'keywords' => ['seguro', 'insurance', 'gnp', 'axa', 'metlife', 'seguros']],
                ],
            ],
            [
                'name' => 'Education',
                'slug' => 'education',
                'icon' => '📚',
                'color' => '#74B9FF',
                'keywords' => ['education', 'educacion', 'school', 'escuela', 'learning', 'aprendizaje'],
                'children' => [
                    ['name' => 'Courses', 'slug' => 'courses', 'icon' => '📖', 'keywords' => ['curso', 'course', 'class', 'clase', 'udemy', 'coursera', 'platzi']],
                    ['name' => 'Books', 'slug' => 'books', 'icon' => '📚', 'keywords' => ['libro', 'book', 'libreria', 'bookstore', 'gandhi', 'sanborns', 'amazon books']],
                    ['name' => 'Certifications', 'slug' => 'certifications', 'icon' => '🎓', 'keywords' => ['certificacion', 'certification', 'exam', 'examen', 'titulo', 'degree']],
                    ['name' => 'Conferences', 'slug' => 'conferences', 'icon' => '🎤', 'keywords' => ['conferencia', 'conference', 'seminario', 'seminar', 'workshop', 'taller']],
                    ['name' => 'Subscriptions', 'slug' => 'education_subscriptions', 'icon' => '💻', 'keywords' => ['subscription', 'suscripcion', 'membership', 'membresia', 'learning platform']],
                ],
            ],
            [
                'name' => 'Services',
                'slug' => 'services',
                'icon' => '🔧',
                'color' => '#A29BFE',
                'keywords' => ['service', 'servicio', 'professional', 'profesional'],
                'children' => [
                    ['name' => 'Professional Services', 'slug' => 'professional_services', 'icon' => '💼', 'keywords' => ['abogado', 'lawyer', 'contador', 'accountant', 'consultor', 'consultant']],
                    ['name' => 'Home Services', 'slug' => 'home_services', 'icon' => '🏠', 'keywords' => ['plomero', 'plumber', 'electricista', 'electrician', 'limpieza', 'cleaning', 'jardinero']],
                    ['name' => 'Financial Services', 'slug' => 'financial_services', 'icon' => '💰', 'keywords' => ['banco', 'bank', 'financial', 'financiero', 'inversion', 'investment']],
                    ['name' => 'Subscription Services', 'slug' => 'subscription_services', 'icon' => '📱', 'keywords' => ['subscription', 'suscripcion', 'monthly', 'mensual', 'anual', 'yearly']],
                ],
            ],
            [
                'name' => 'Travel',
                'slug' => 'travel',
                'icon' => '✈️',
                'color' => '#55A3FF',
                'keywords' => ['travel', 'viaje', 'vacation', 'vacaciones', 'trip'],
                'children' => [
                    ['name' => 'Flights', 'slug' => 'flights', 'icon' => '✈️', 'keywords' => ['vuelo', 'flight', 'aeromexico', 'volaris', 'viva aerobus', 'airplane', 'avion']],
                    ['name' => 'Hotels', 'slug' => 'hotels', 'icon' => '🏨', 'keywords' => ['hotel', 'airbnb', 'booking', 'hospedaje', 'accommodation', 'alojamiento']],
                    ['name' => 'Vacation Expenses', 'slug' => 'vacation_expenses', 'icon' => '🏖️', 'keywords' => ['vacation', 'vacaciones', 'turismo', 'tourism', 'excursion', 'tour']],
                    ['name' => 'Travel Insurance', 'slug' => 'travel_insurance', 'icon' => '🛡️', 'keywords' => ['travel insurance', 'seguro de viaje', 'seguro viajero']],
                ],
            ],
            [
                'name' => 'Other',
                'slug' => 'other',
                'icon' => '📋',
                'color' => '#B8B8B8',
                'keywords' => ['other', 'otro', 'misc', 'miscellaneous', 'varios'],
                'children' => [
                    ['name' => 'Miscellaneous', 'slug' => 'miscellaneous', 'icon' => '🔸', 'keywords' => ['misc', 'miscellaneous', 'varios', 'other', 'otro']],
                    ['name' => 'Uncategorized', 'slug' => 'uncategorized', 'icon' => '❓', 'keywords' => ['uncategorized', 'sin categoria', 'unknown', 'desconocido']],
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
