<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_account_id')->constrained('savings_accounts')->cascadeOnDelete();
            $table->enum('type', ['deposit', 'withdrawal', 'interest']);
            $table->decimal('amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('posted_at');
            $table->string('reference', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('savings_transactions');
    }
};
