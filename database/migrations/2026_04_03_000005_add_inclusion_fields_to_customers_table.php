<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->change();
            $table->boolean('is_woman')->default(false)->after('gender');
            $table->boolean('has_disability')->default(false)->after('is_woman');
            $table->string('disability_type')->nullable()->after('has_disability');
            $table->string('education_level', 50)->nullable()->after('disability_type');
            $table->string('marital_status', 20)->nullable()->after('education_level');
            $table->integer('dependents_count')->default(0)->after('marital_status');
            $table->string('employment_status', 50)->nullable()->after('dependents_count');
            $table->decimal('monthly_income', 12, 2)->nullable()->after('employment_status');
            
            // Add indexes for reporting
            $table->index(['is_woman']);
            $table->index(['has_disability']);
            $table->index(['gender']);
            $table->index(['education_level']);
        });
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['is_woman']);
            $table->dropIndex(['has_disability']);
            $table->dropIndex(['gender']);
            $table->dropIndex(['education_level']);
            
            $table->dropColumn([
                'is_woman',
                'has_disability', 
                'disability_type',
                'education_level',
                'marital_status',
                'dependents_count',
                'employment_status',
                'monthly_income'
            ]);
            
            // Revert gender to original enum
            $table->enum('gender', ['Female', 'Male', 'Other'])->nullable()->change();
        });
    }
};
