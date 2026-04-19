<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->enum('payment_type', ['regular', 'advance', 'bulk'])->default('regular')->after('installment_amount');
            $table->text('payment_note')->nullable()->after('payment_type');
            $table->json('installments_covered')->nullable()->after('payment_note');
            $table->decimal('excess_amount', 12, 2)->default(0)->after('installment_amount'); // Amount that goes beyond current due
        });
    }

    public function down()
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'payment_note', 'installments_covered', 'excess_amount']);
        });
    }
};
