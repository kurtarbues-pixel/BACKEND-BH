<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->date('desired_start_date')->nullable()->after('message');
            $table->date('desired_end_date')->nullable()->after('desired_start_date');
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->string('agreement_status', 20)->default('legacy')->after('status');
            $table->timestamp('agreement_accepted_at')->nullable()->after('agreement_status');
            $table->string('agreement_accepted_ip', 45)->nullable()->after('agreement_accepted_at');
            $table->json('agreement_snapshot')->nullable()->after('agreement_accepted_ip');
        });

        Schema::create('room_availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index(['room_id', 'start_date', 'end_date'], 'room_availability_lookup');
        });

        Schema::create('lease_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->enum('kind', ['move_in', 'move_out']);
            $table->date('inspection_date');
            $table->text('condition');
            $table->text('notes')->nullable();
            $table->decimal('damages_amount', 10, 2)->default(0);
            $table->string('damage_description')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('completed');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['lease_id', 'kind'], 'lease_inspection_once_per_kind');
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_inspections');
        Schema::dropIfExists('room_availability_blocks');

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['agreement_status', 'agreement_accepted_at', 'agreement_accepted_ip', 'agreement_snapshot']);
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn(['desired_start_date', 'desired_end_date']);
        });
    }
};
