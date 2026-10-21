<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * An event members can bet on: a game, or anything an admin sets up.
 *
 * 🚨 `picks_event_id` is a plain column, not a foreign key: Picks is optional,
 * and its table may not exist. A game imported from Picks keeps the id so its
 * kickoff, scores and status are read from Picks rather than stored twice.
 */
return Migration::createTable('longshot_events', function (Blueprint $table) {
    $table->increments('id');
    $table->string('title', 200)->nullable();
    $table->string('league', 60)->default('');
    $table->string('home_name', 100)->nullable();
    $table->string('away_name', 100)->nullable();
    $table->string('home_logo', 255)->nullable();
    $table->string('away_logo', 255)->nullable();
    $table->dateTime('starts_at');
    $table->string('status', 16)->default('open');
    $table->integer('home_score')->nullable();
    $table->integer('away_score')->nullable();
    $table->unsignedInteger('picks_event_id')->nullable()->unique();
    $table->unsignedInteger('created_by')->nullable();
    $table->dateTime('settled_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'starts_at']);
    $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
});
