<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A market on an event: moneyline, spread, total, or a custom one (props,
 * outrights) whose winner an admin names.
 *
 * `line` is the HOME team's handicap for a spread and the points line for a
 * total. A bet stores the line it was placed at, so a line that moves later
 * never regrades a bet already made.
 */
return Migration::createTable('longshot_markets', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('event_id');
    $table->string('type', 16);
    $table->string('label', 150)->nullable();
    $table->decimal('line', 7, 1)->nullable();
    $table->boolean('feed')->default(false);
    // Who priced it, as a member reads it: "DraftKings (via ESPN)".
    $table->string('odds_provider', 60)->nullable();
    $table->string('status', 16)->default('open');
    $table->unsignedSmallInteger('sort')->default(0);
    $table->dateTime('settled_at')->nullable();
    $table->timestamps();

    $table->index('event_id');
    $table->foreign('event_id')->references('id')->on('longshot_events')->cascadeOnDelete();
});
