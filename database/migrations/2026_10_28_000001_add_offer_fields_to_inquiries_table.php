<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->timestamp('offer_expires_at')->nullable()->after('status');
            $table->text('decision_reason')->nullable()->after('offer_expires_at');
            $table->index(['room_id', 'status', 'offer_expires_at'], 'inquiry_room_offer_lookup');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->change();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->change();
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex('inquiry_room_offer_lookup');
            $table->dropColumn(['offer_expires_at', 'decision_reason']);
        });
    }
};
