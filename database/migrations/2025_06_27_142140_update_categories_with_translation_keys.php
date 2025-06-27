<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update categories with their translation keys based on slug
        $categories = [
            'food_dining' => 'food_dining',
            'restaurants' => 'restaurants',
            'fast_food' => 'fast_food',
            'groceries' => 'groceries',
            'coffee_shops' => 'coffee_shops',
            'delivery' => 'delivery',
            'alcohol' => 'alcohol',
            
            'transportation' => 'transportation',
            'public_transport' => 'public_transport',
            'ride_sharing' => 'ride_sharing',
            'fuel' => 'fuel',
            'parking' => 'parking',
            'vehicle_maintenance' => 'vehicle_maintenance',
            'tolls' => 'tolls',
            
            'shopping' => 'shopping',
            'clothing' => 'clothing',
            'electronics' => 'electronics',
            'home_goods' => 'home_goods',
            'personal_care' => 'personal_care',
            'gifts' => 'gifts',
            'online_shopping' => 'online_shopping',
            
            'entertainment' => 'entertainment',
            'movies' => 'movies',
            'concerts' => 'concerts',
            'streaming_services' => 'streaming_services',
            'games' => 'games',
            'sports' => 'sports',
            'hobbies' => 'hobbies',
            
            'health_wellness' => 'health_wellness',
            'medical' => 'medical',
            'pharmacy' => 'pharmacy',
            'fitness' => 'fitness',
            'dental' => 'dental',
            'mental_health' => 'mental_health',
            'supplements' => 'supplements',
            
            'bills_utilities' => 'bills_utilities',
            'rent_mortgage' => 'rent_mortgage',
            'electricity' => 'electricity',
            'water' => 'water',
            'internet' => 'internet',
            'phone' => 'phone',
            'insurance' => 'insurance',
            
            'education' => 'education',
            'courses' => 'courses',
            'books' => 'books',
            'certifications' => 'certifications',
            'conferences' => 'conferences',
            'education_subscriptions' => 'education_subscriptions',
            
            'services' => 'services',
            'professional_services' => 'professional_services',
            'home_services' => 'home_services',
            'financial_services' => 'financial_services',
            'subscription_services' => 'subscription_services',
            
            'travel' => 'travel',
            'flights' => 'flights',
            'hotels' => 'hotels',
            'vacation_expenses' => 'vacation_expenses',
            'travel_insurance' => 'travel_insurance',
            
            'other' => 'other',
            'miscellaneous' => 'miscellaneous',
            'uncategorized' => 'uncategorized',
        ];

        foreach ($categories as $slug => $translationKey) {
            DB::table('categories')
                ->where('slug', $slug)
                ->update(['translation_key' => $translationKey]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('categories')->update(['translation_key' => null]);
    }
};