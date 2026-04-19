<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->enum('savings_type', ['regular', 'fixed', 'voluntary', 'compulsory'])
                ->default('regular')
                ->after('status');
            $table->unsignedInteger('term_months')->nullable()->after('interest_rate');
            $table->date('maturity_date')->nullable()->after('opened_at');
        });
    }

    public function down()
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn(['savings_type', 'term_months', 'maturity_date']);
        });
    }
};
