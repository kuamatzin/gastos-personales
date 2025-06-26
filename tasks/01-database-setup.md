# Task 01: Database Setup

## Objective
Set up the database structure for the ExpenseBot application including all models, migrations, and seeders.

## Prerequisites
- Laravel 12 fresh installation ✓
- PostgreSQL installed

## Deliverables

### 1. Create Migrations
Create database migrations for the following tables:

#### categories table
```php
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->foreignId('parent_id')->nullable()->constrained('categories');
    $table->string('color', 7)->nullable();
    $table->string('icon', 20)->nullable();
    $table->json('keywords')->nullable();
    $table->boolean('is_active')->default(true);
    $table->integer('sort_order')->default(0);
    $table->timestamps();

    $table->index(['parent_id', 'is_active']);
});
```

#### expenses table
```php
Schema::create('expenses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->decimal('amount', 10, 2);
    $table->string('currency', 3)->default('MXN');
    $table->text('description');
    $table->foreignId('category_id')->nullable()->constrained();
    $table->foreignId('suggested_category_id')->nullable()->constrained('categories');
    $table->date('expense_date');
    $table->text('raw_input')->nullable();
    $table->float('confidence_score')->nullable();
    $table->float('category_confidence')->nullable();
    $table->enum('input_type', ['text', 'voice', 'image']);
    $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
    $table->string('merchant_name')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'expense_date']);
    $table->index(['category_id', 'expense_date']);
    $table->index('category_confidence');
});
```

#### category_learning table
```php
Schema::create('category_learning', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('keyword');
    $table->foreignId('category_id')->constrained();
    $table->float('confidence_weight')->default(1.0);
    $table->integer('usage_count')->default(1);
    $table->timestamp('last_used_at');
    $table->timestamps();

    $table->unique(['user_id', 'keyword', 'category_id']);
    $table->index(['user_id', 'keyword']);
});
```

#### Update users table
Add Telegram-specific fields to the existing users table:
```php
Schema::table('users', function (Blueprint $table) {
    $table->string('telegram_id')->unique()->nullable();
    $table->string('telegram_username')->nullable();
    $table->string('telegram_first_name')->nullable();
    $table->string('telegram_last_name')->nullable();
    $table->boolean('is_active')->default(true);
    $table->json('preferences')->nullable();
});
```

### 2. Create Eloquent Models

#### app/Models/Category.php
- Implement parent/child relationships
- Add keywords casting to array
- Create scope for parent categories
- Add relationship to expenses

#### app/Models/Expense.php
- Define all fillable fields
- Set up proper casting for dates and decimals
- Create relationships to user, category, and suggestedCategory

#### app/Models/CategoryLearning.php
- Define fillable fields
- Set up relationships to user and category
- Add casting for confidence_weight and last_used_at

#### Update app/Models/User.php
- Add telegram fields to fillable
- Add relationships to expenses and categoryLearning

### 3. Create Category Seeder

Create `database/seeders/CategorySeeder.php` with Mexican context categories:
- Food & Dining (restaurants, fast_food, groceries, coffee_shops, delivery)
- Transportation (public_transport, ride_sharing, fuel, parking)
- Shopping (clothing, electronics, personal_care)
- Entertainment (movies, streaming)
- Health & Wellness (medical, pharmacy, fitness)
- Bills & Utilities (electricity, internet, phone)

Include Spanish and English keywords for each category.

### 4. Testing
- Run `php artisan migrate:fresh`
- Run `php artisan db:seed --class=CategorySeeder`
- Verify all tables are created correctly
- Test model relationships

## Commands
```bash
# Create migrations
php artisan make:migration create_categories_table
php artisan make:migration create_expenses_table
php artisan make:migration create_category_learning_table
php artisan make:migration update_users_table_add_telegram_fields

# Create models
php artisan make:model Category
php artisan make:model Expense
php artisan make:model CategoryLearning

# Create seeder
php artisan make:seeder CategorySeeder

# Run migrations and seed
php artisan migrate:fresh --seed
```

## Success Criteria
- All migrations run without errors
- Models have proper relationships defined
- Category seeder creates hierarchical categories with keywords
- Database structure supports all features outlined in PRD
