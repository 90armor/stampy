<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            // One row per actual date, no recurrence — several Cambodian
            // public holidays (Khmer New Year, Pchum Ben, the Water Festival)
            // have no fixed date and are announced by the government each
            // year, so they aren't computable. Also covers an ad-hoc single
            // day off with no extra mechanism.
            $table->date('date')->unique();
            $table->string('name');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
