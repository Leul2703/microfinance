<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDepositLimitsToBranchesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('max_deposit_limit', 12, 2)->default(1000000.00);
            $table->decimal('min_deposit_amount', 12, 2)->default(100.00);
            $table->decimal('max_withdrawal_limit', 12, 2)->default(50000.00);
            $table->decimal('daily_transaction_limit', 12, 2)->default(200000.00);
            $table->boolean('deposit_limits_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['max_deposit_limit', 'min_deposit_amount', 'max_withdrawal_limit', 'daily_transaction_limit', 'deposit_limits_enabled']);
        });
    }
}
