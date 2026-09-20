<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            // An admin-issued temporary password (Phase 2.4d) is a distinct
            // event from the user's own password_changed_at above — it's an
            // audit record of the reset itself, kept even after the user
            // changes it, not a live flag (must_change_password/
            // temporary_password_expires_at are the live flags, cleared on
            // change). Self-referencing FK, same pattern as
            // employees.manager_id in its own create migration.
            $table->foreignId('password_reset_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('password_reset_at')->nullable();
            // Checked at login against this stored value, not inferred from
            // updated_at (any unrelated row change would reset that).
            $table->timestamp('temporary_password_expires_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
