<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('loan_product', 100);
            $table->decimal('requested_amount', 12, 2);
            $table->unsignedInteger('term_months');
            $table->decimal('interest_rate', 5, 2)->default(8.50);
            $table->date('application_date');
            $table->enum('repayment_frequency', ['Monthly', 'Bi-Weekly', 'Weekly'])->default('Monthly');
            $table->text('purpose');
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Closed'])->default('Pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('loans');
    }
};
