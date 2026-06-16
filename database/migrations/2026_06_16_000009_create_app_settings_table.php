<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id(); $table->string('key', 128)->unique(); $table->text('value')->nullable(); $table->string('value_type', 32)->default('string'); $table->string('group', 32)->nullable()->index(); $table->string('label')->nullable(); $table->text('description')->nullable(); $table->boolean('is_editable')->default(true)->index(); $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
