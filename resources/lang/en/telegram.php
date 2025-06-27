<?php

return [
    'welcome' => "Welcome to ExpenseBot! 🎉\n\nI'll help you track your expenses. Just send me:\n• Text with the amount and description\n• Voice notes\n• Photos of receipts\n\nUse /help to see all available commands.",

    'help' => "Available commands:\n\n📊 *Reports*\n/expenses_today - Today's expenses\n/expenses_week - This week's expenses\n/expenses_month - This month's expenses\n/category_spending - Spending by category\n/top_categories - Top spending categories\n/stats - Statistics and insights\n\n📤 *Export*\n/export - Export your expenses\n\n⚙️ *Settings*\n/language - Change language\n/cancel - Cancel current operation\n\n*How to add expenses:*\nJust send me a message with the amount and description. For example:\n• \"50 coffee\"\n• \"$120 groceries\"\n• Voice note describing your expense\n• Photo of a receipt",

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

    'error_processing' => '❌ Error processing your request. Please try again.',
    'error_invalid_amount' => '❌ Could not detect a valid amount. Please include a number in your message.',
    'error_voice_processing' => '❌ Error processing voice message. Please try again.',
    'error_image_processing' => '❌ Error processing image. Please try again.',

    'confirm_expense' => "Please confirm this expense:\n\n💰 Amount: $:amount\n📝 Description: :description\n🏷 Category: :category",
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
    'button_this_month' => '📅 This Month',
    'button_statistics' => '📈 Statistics',
    'button_help' => '❓ Help',
];
