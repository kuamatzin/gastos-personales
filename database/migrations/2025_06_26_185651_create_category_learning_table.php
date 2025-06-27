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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_learning');
    }
};
