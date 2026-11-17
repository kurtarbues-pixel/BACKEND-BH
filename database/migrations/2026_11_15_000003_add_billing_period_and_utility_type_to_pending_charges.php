<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_charges', function (Blueprint $table) {
            $table->char('billing_period', 7)->nullable();
            $table->string('utility_type', 24)->nullable();
            $table->index(['billing_period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('pending_charges', function (Blueprint $table) {
            $table->dropIndex(['billing_period', 'status']);
            $table->dropColumn(['billing_period', 'utility_type']);
        });
    }
};
