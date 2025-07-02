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
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('installment_plan_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('installment_number')->nullable(); // Which installment this is (1, 2, 3, etc.)
            
            // Index for performance
            $table->index(['installment_plan_id', 'installment_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['installment_plan_id']);
            $table->dropColumn(['installment_plan_id', 'installment_number']);
        });
    }
};