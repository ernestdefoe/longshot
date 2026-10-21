<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One selection of a bet, with the price and line it was taken at.
 */
return Migration::createTable('longshot_bet_legs', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('bet_id');
    $table->unsignedInteger('selection_id');
    $table->unsignedInteger('market_id');
    $table->unsignedInteger('event_id');
    $table->integer('odds');
    $table->decimal('line', 7, 1)->nullable();
    $table->string('result', 8)->nullable();

    $table->index('bet_id');
    $table->index(['market_id', 'result']);
    $table->index('event_id');
    $table->foreign('bet_id')->references('id')->on('longshot_bets')->cascadeOnDelete();
    $table->foreign('selection_id')->references('id')->on('longshot_selections');
    $table->foreign('market_id')->references('id')->on('longshot_markets');
    $table->foreign('event_id')->references('id')->on('longshot_events');
});
