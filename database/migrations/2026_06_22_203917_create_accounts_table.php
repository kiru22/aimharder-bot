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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('email');
            $table->text('password');                 // cifrada por el cast
            $table->string('fingerprint', 64)->nullable();
            $table->string('subdomain')->default('hybridboxgrau');
            $table->unsignedInteger('box_id')->default(8244);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
