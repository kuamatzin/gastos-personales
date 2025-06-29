<?php

return [
    'welcome' => "Welcome to ExpenseBot! 🎉\n\nI'll help you track your expenses. Just send me:\n• Text with the amount and description\n• Voice notes\n• Photos of receipts\n\nUse /help to see all available commands.",

    'help' => "Available commands:\n\n📊 *Reports*\n/expenses_today - Today's expenses\n/expenses_week - This week's expenses\n/expenses_month - This month's expenses\n/category_spending - Spending by category\n/top_categories - Top spending categories\n/stats - Statistics and insights\n\n📤 *Export*\n/export - Export your expenses\n\n⚙️ *Settings*\n/language - Change language\n/timezone - Set timezone\n/cancel - Cancel current operation\n\n*How to add expenses:*\nJust send me a message with the amount and description. For example:\n• \"50 coffee\"\n• \"$120 groceries\"\n• Voice note describing your expense\n• Photo of a receipt\n\n🌍 *Timezone:* Expenses are recorded according to your configured timezone. Use /timezone to change it.",

    'expense_saved' => "✅ Expense saved successfully!\n\n💰 Amount: $:amount\n📝 Description: :description\n🏷 Category: :category\n📅 Date: :date",

    'expense_today_header' => "📊 *Today's Expenses*\n:date\n\n",
    'expense_week_header' => "📊 *This Week's Expenses*\n:start_date - :end_date\n\n",
    'expense_month_header' => "📊 *This Month's Expenses*\n:month :year\n\n",

    'expense_item' => "• :description - $:amount (:category)\n",
    'total' => "\n💰 *Total: $:amount*",
    'no_expenses' => 'No expenses found for this period.',

    'category_spending_header' => "📊 *Spending by Category*\n:period\n\n",
    'category_item' => "• :category: $:amount (:percentage%)\n",

    'top_categories_header' => "🏆 *Top Spending Categories*\n:period\n\n",
    'top_category_item' => ":position. :category: $:amount (:percentage%)\n",

    'stats_header' => "📈 *Your Expense Statistics*\n\n",
    'stats_total' => "💰 *Total expenses:* $:amount\n",
    'stats_average_daily' => "📅 *Daily average:* $:amount\n",
    'stats_average_weekly' => "📅 *Weekly average:* $:amount\n",
    'stats_average_monthly' => "📅 *Monthly average:* $:amount\n",
    'stats_expense_count' => "🧾 *Number of expenses:* :count\n",
    'stats_average_expense' => "💵 *Average per expense:* $:amount\n",
    'stats_most_expensive' => "💸 *Most expensive:* :description ($:amount)\n",
    'stats_most_frequent' => "🏷 *Most frequent category:* :category (:count expenses)\n",

    'export_generating' => '📤 Generating your expense report...',
    'export_period_selection' => 'Please select the period you want to export:',
    'export_current_month' => 'Current Month',
    'export_last_month' => 'Last Month',
    'export_last_3_months' => 'Last 3 Months',
    'export_current_year' => 'Current Year',
    'export_all_time' => 'All Time',

    'cancel_no_operation' => 'No active operation to cancel.',
    'cancel_success' => 'Operation cancelled successfully.',

    'language_selection' => 'Please select your preferred language:',
    'language_updated' => '✅ Language updated successfully!',
    
    // Timezone messages
    'timezone_setup_prompt' => "🌍 *Timezone Setup*\n\nTo record your expenses with the correct date, please select your timezone:\n\n_You can change this later with the /timezone command_",
    'timezone_mexico_city' => '🇲🇽 Mexico City (Central)',
    'timezone_tijuana' => '🇲🇽 Tijuana (Pacific)',
    'timezone_cancun' => '🇲🇽 Cancun (Eastern)',
    'timezone_configure_later' => '⚙️ Configure later',

    'error_processing' => '❌ Error processing your request. Please try again.',
    'error_invalid_amount' => '❌ Could not detect a valid amount. Please include a number in your message.',
    'error_voice_processing' => '❌ Error processing voice message. Please try again.',
    'error_image_processing' => '❌ Error processing image. Please try again.',

    'confirm_expense' => "Please confirm this expense:\n\n💰 Amount: $:amount\n📝 Description: :description\n🏷 Category: :category",
    'expense_detected' => '💰 Expense detected!',
    'expense_amount' => '💵 Amount: $:amount :currency',
    'expense_description' => '📝 Description: :description',
    'expense_date' => '📅 Date: :date',
    'expense_category' => '🏷️ Category: :icon :category',
    'expense_category_confidence' => '🏷️ Category: :icon :category (Confidence: :confidence%)',
    'expense_merchant' => '🏪 Merchant: :merchant',
    'expense_confirm_question' => 'Is this correct?',
    'confirm_yes' => '✅ Confirm',
    'confirm_no' => '❌ Cancel',
    'expense_cancelled' => '❌ Expense cancelled.',

    'processing_voice' => '🎤 Processing voice message...',
    'processing_image' => '📸 Processing image...',
    'processing_text' => '💬 Processing expense...',

    // Keyboard buttons
    'button_confirm' => '✅ Confirm',
    'button_cancel' => '❌ Cancel',
    'button_edit_category' => '✏️ Edit Category',
    'button_edit_description' => '📝 Edit Description',
    'button_view_subcategories' => '🔍 View Subcategories',
    'button_back' => '↩️ Back',
    'button_select_category' => '🏷️ *Select a category:*',

    // Export buttons
    'button_excel' => '📊 Excel',
    'button_pdf' => '📄 PDF',
    'button_csv' => '💾 CSV',
    'button_quick_export' => '⚡ Quick Export (This Month)',

    // Period buttons
    'button_today' => "📊 Today's Expenses",
    'button_this_week' => '📊 This Week',
    'button_last_week' => '⬅️ Last Week',
    'button_this_month' => '📅 This Month',
    'button_previous_month' => '⬅️ Previous Month',
    'button_next_month' => '➡️ Next Month',
    'button_by_category' => '🏷️ By Category',
    'button_top_categories' => '🏆 Top Categories',
    'button_all_categories' => '⬅️ All Categories',
    'button_statistics' => '📈 Statistics',
    'button_help' => '❓ Help',
    'button_export' => '📤 Export',

    // Category names
    'categories' => [
        'food_dining' => 'Food & Dining',
        'restaurants' => 'Restaurants',
        'fast_food' => 'Fast Food',
        'groceries' => 'Groceries',
        'coffee_shops' => 'Coffee Shops',
        'delivery' => 'Delivery',
        'alcohol' => 'Alcohol',
        
        'transportation' => 'Transportation',
        'public_transport' => 'Public Transport',
        'ride_sharing' => 'Ride Sharing',
        'fuel' => 'Fuel',
        'parking' => 'Parking',
        'vehicle_maintenance' => 'Vehicle Maintenance',
        'tolls' => 'Tolls',
        
        'shopping' => 'Shopping',
        'clothing' => 'Clothing',
        'electronics' => 'Electronics',
        'home_goods' => 'Home Goods',
        'personal_care' => 'Personal Care',
        'gifts' => 'Gifts',
        'online_shopping' => 'Online Shopping',
        
        'entertainment' => 'Entertainment',
        'movies' => 'Movies',
        'concerts' => 'Concerts',
        'streaming_services' => 'Streaming Services',
        'games' => 'Games',
        'sports' => 'Sports',
        'hobbies' => 'Hobbies',
        
        'health_wellness' => 'Health & Wellness',
        'medical' => 'Medical',
        'pharmacy' => 'Pharmacy',
        'fitness' => 'Fitness',
        'dental' => 'Dental',
        'mental_health' => 'Mental Health',
        'supplements' => 'Supplements',
        
        'bills_utilities' => 'Bills & Utilities',
        'rent_mortgage' => 'Rent/Mortgage',
        'electricity' => 'Electricity',
        'water' => 'Water',
        'internet' => 'Internet',
        'phone' => 'Phone',
        'insurance' => 'Insurance',
        
        'education' => 'Education',
        'courses' => 'Courses',
        'books' => 'Books',
        'certifications' => 'Certifications',
        'conferences' => 'Conferences',
        'education_subscriptions' => 'Education Subscriptions',
        
        'services' => 'Services',
        'professional_services' => 'Professional Services',
        'home_services' => 'Home Services',
        'financial_services' => 'Financial Services',
        'subscription_services' => 'Subscription Services',
        
        'travel' => 'Travel',
        'flights' => 'Flights',
        'hotels' => 'Hotels',
        'vacation_expenses' => 'Vacation Expenses',
        'travel_insurance' => 'Travel Insurance',
        
        'other' => 'Other',
        'miscellaneous' => 'Miscellaneous',
        'uncategorized' => 'Uncategorized',
    ],
];
