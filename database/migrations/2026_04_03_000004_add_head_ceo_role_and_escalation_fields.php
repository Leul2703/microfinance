<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Add head_ceo to the role enum if not already present
            // Note: This is handled in the application logic since MySQL doesn't support enum modification easily
        });

        Schema::create('loan_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete(); // Branch Manager who escalated
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); // Head CEO who reviews
            $table->text('recommendation_note'); // Branch Manager's recommendation
            $table->text('review_note')->nullable(); // Head CEO's review note
            $table->enum('status', ['pending_ceo_review', 'ceo_approved', 'ceo_rejected'])->default('pending_ceo_review');
            $table->timestamp('escalated_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'escalated_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('loan_escalations');
    }
};
