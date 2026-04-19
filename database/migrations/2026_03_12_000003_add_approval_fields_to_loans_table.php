<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('requires_manager_approval')->default(false)->after('status');
            $table->date('next_due_date')->nullable()->after('requires_manager_approval');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('next_due_date');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
        });
    }

    public function down()
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['requires_manager_approval', 'next_due_date', 'approved_at', 'rejected_at']);
        });
    }
};
