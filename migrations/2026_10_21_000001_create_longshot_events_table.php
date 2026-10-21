<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * An event members can bet on: a game, or anything an admin sets up.
 *
 * 🚨 `picks_event_id` is a plain column, not a foreign key: Picks is optional,
 * and its table may not exist. A game imported from Picks keeps the id so its
 * kickoff, scores and status are read from Picks rather than stored twice.
 *
 * `source` is where it came from (manual, picks, espn, apifootball, …) and
 * `external_id` that provider's own id, so a sync updates the game it imported
 * instead of adding it again. `locked` is staff saying "hands off": no sync
 * changes its sides, kickoff or status. A `hidden` event is kept, so it is not
 * imported again, and shown to nobody.
 */
return Migration::createTable('longshot_events', function (Blueprint $table) {
    $table->increments('id');
    $table->string('title', 200)->nullable();
    $table->string('league', 60)->default('');
    $table->string('home_name', 100)->nullable();
    $table->string('away_name', 100)->nullable();
    $table->string('home_logo', 255)->nullable();
    $table->string('away_logo', 255)->nullable();
    $table->string('home_color', 7)->nullable();
    $table->string('away_color', 7)->nullable();
    $table->dateTime('starts_at');
    $table->string('status', 16)->default('open');
    $table->integer('home_score')->nullable();
    $table->integer('away_score')->nullable();
    $table->unsignedInteger('picks_event_id')->nullable()->unique();
    $table->string('source', 16)->default('manual');
    $table->string('external_id', 64)->nullable();
    $table->string('league_key', 20)->nullable();
    $table->boolean('locked')->default(false);
    $table->unsignedInteger('home_team_id')->nullable();
    $table->unsignedInteger('away_team_id')->nullable();
    $table->unsignedInteger('created_by')->nullable();
    $table->dateTime('settled_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'starts_at']);
    $table->unique(['source', 'external_id']);
    $table->index('league_key');
    $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
});
