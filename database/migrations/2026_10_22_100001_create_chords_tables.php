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
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->string('artist')->default('');
            $table->string('key')->default('');
            $table->unsignedTinyInteger('capo')->default(0);
            $table->json('tags')->nullable();
            $table->string('source')->nullable();
            $table->longText('body');
            $table->date('added');
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index('user_id');
        });

        Schema::create('song_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->longText('body');
            $table->string('key')->default('');
            $table->unsignedTinyInteger('capo')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('setlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->date('created');
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('setlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->tinyInteger('t')->default(0);
            $table->unsignedTinyInteger('c')->default(0);

            $table->index('setlist_id');
        });

        Schema::create('medleys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('target_key')->nullable()->default('');
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('medley_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medley_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->tinyInteger('t')->default(0);
            $table->unsignedTinyInteger('c')->default(0);
            $table->json('sections')->nullable();
            $table->longText('body')->nullable();
        });

        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('type');
            $table->unsignedBigInteger('target_id');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->tinyInteger('t')->nullable();
            $table->unsignedTinyInteger('c')->nullable();
            $table->boolean('flats')->nullable();
            $table->unsignedTinyInteger('speed')->nullable();
            $table->unsignedTinyInteger('size')->nullable();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('song_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_opened');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['user_id', 'song_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('song_opens');
        Schema::dropIfExists('preferences');
        Schema::dropIfExists('shares');
        Schema::dropIfExists('medley_items');
        Schema::dropIfExists('medleys');
        Schema::dropIfExists('setlist_items');
        Schema::dropIfExists('setlists');
        Schema::dropIfExists('song_revisions');
        Schema::dropIfExists('songs');
    }
};
