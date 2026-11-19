<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A "grant" is one authorization: the code issued at /oauth/authorize and
 * every access and refresh token that descends from it. Tracking the family
 * lets the provider revoke all of it when a code or refresh token is reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('openid_provider_grants', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->string('client_id', 100)->index();
            $table->string('user_id', 100)->nullable()->index();
            $table->text('nonce')->nullable();
            $table->unsignedBigInteger('auth_time')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('openid_provider_grant_tokens', function (Blueprint $table) {
            $table->string('access_token_id', 100)->primary();
            $table->string('grant_id', 100)->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openid_provider_grant_tokens');
        Schema::dropIfExists('openid_provider_grants');
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
