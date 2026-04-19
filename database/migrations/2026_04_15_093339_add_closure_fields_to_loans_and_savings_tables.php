<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClosureFieldsToLoansAndSavingsTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('rejected_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete()->after('rejected_by');
            $table->text('closure_reason')->nullable()->after('rejection_reason');
            $table->decimal('closing_balance', 12, 2)->nullable()->after('closure_reason');
        });

        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('last_interest_applied_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete()->after('approved_by');
            $table->text('closure_reason')->nullable()->after('approval_notes');
            $table->decimal('closing_balance', 12, 2)->nullable()->after('closure_reason');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closed_by', 'closure_reason', 'closing_balance']);
        });

        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closed_by', 'closure_reason', 'closing_balance']);
        });
    }
}
