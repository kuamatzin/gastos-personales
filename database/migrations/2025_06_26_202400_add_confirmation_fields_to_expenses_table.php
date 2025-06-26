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
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->timestamp('rejected_at')->nullable()->after('confirmed_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->json('metadata')->nullable()->after('merchant_name');
            
            // Add indexes for performance
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'rejected_at', 'rejection_reason', 'metadata']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['user_id', 'status']);
        });
    }
};