<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A side as the book shows it: name, colours and a logo cached on this forum
 * (resized, never hotlinked). `aliases` are the other names providers use for
 * it ("Man United"), learned as games are matched. `locked` keeps staff's own
 * branding from being overwritten by a sync.
 */
return Migration::createTable('longshot_teams', function (Blueprint $table) {
    $table->increments('id');
    $table->string('league_key', 20);
    $table->string('name', 100);
    $table->string('name_key', 100);
    $table->string('abbreviation', 10)->nullable();
    $table->string('color', 7)->nullable();
    $table->string('alt_color', 7)->nullable();
    $table->string('logo_path', 190)->nullable();
    $table->string('logo_source', 255)->nullable();
    $table->dateTime('logo_failed_at')->nullable();
    $table->text('aliases')->nullable();
    $table->text('external_ids')->nullable();
    $table->boolean('locked')->default(false);
    $table->dateTime('branded_at')->nullable();
    $table->timestamps();

    $table->unique(['league_key', 'name_key']);
});
