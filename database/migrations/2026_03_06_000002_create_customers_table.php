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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->string('national_id', 60)->unique();
            $table->string('phone_number', 30);
            $table->string('email_address', 120)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['Female', 'Male', 'Other'])->nullable();
            $table->string('occupation', 120)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('registration_date');
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
        Schema::dropIfExists('customers');
    }
};
