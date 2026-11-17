<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('reference_number', 48)->nullable()->unique()->after('invoice_id');
            $table->uuid('client_uuid')->nullable()->unique()->after('reference_number');
            $table->enum('transaction_status', ['succeeded', 'failed'])->default('succeeded')->after('amount');
            $table->string('failure_reason')->nullable()->after('notes');
            $table->index(['invoice_id', 'transaction_status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['invoice_id', 'transaction_status']);
            $table->dropUnique(['reference_number']);
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['reference_number', 'client_uuid', 'transaction_status', 'failure_reason']);
        });
    }
};
