<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->boolean('active')->default(true);
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('payload');
            $t->integer('last_activity')->index();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('value');
        });
        DB::table('settings')->insert(['key' => 'session_idle_minutes', 'value' => '15']);
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('actor_id')->nullable()->index();
            $t->string('action')->index();
            $t->text('details');
            $t->string('occurred_at', 32)->index();
            $t->char('previous_hash', 64);
            $t->char('hash', 64);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'settings', 'sessions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
