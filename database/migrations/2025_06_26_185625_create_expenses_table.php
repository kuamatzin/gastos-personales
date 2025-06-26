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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
