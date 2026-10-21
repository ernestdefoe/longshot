<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One outcome of a market and its price, in American odds (-110, +150).
 *
 * `result` is written by settlement: win, lose, push or void.
 */
return Migration::createTable('longshot_selections', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('market_id');
    $table->string('key', 20);
    $table->string('label', 150)->nullable();
    $table->integer('odds')->nullable();
    $table->string('result', 8)->nullable();
    $table->unsignedSmallInteger('sort')->default(0);

    $table->index('market_id');
    $table->foreign('market_id')->references('id')->on('longshot_markets')->cascadeOnDelete();
});
