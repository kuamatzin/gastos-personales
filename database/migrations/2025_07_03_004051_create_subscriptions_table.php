<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MXN');
            $table->enum('periodicity', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly']);
            $table->date('next_charge_date');
            $table->date('last_charge_date')->nullable();
            $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('merchant_name')->nullable();
            $table->integer('notification_days')->default(1); // Days before charge to notify
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index('next_charge_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};