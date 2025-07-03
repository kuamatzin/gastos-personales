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
        Schema::create('subscription_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->date('charge_date');
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->timestamps();
            
            $table->index(['subscription_id', 'charge_date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_expenses');
    }
};