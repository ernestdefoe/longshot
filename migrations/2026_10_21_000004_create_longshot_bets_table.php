<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A bet: a single, or a parlay of several legs.
 *
 * `potential` is what it returns if every leg wins, fixed when it is placed.
 * `payout` is what it actually returned (stake included), written once, by
 * settlement, under a row lock.
 */
return Migration::createTable('longshot_bets', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('kind', 8)->default('single');
    $table->bigInteger('stake');
    $table->bigInteger('potential');
    $table->bigInteger('payout')->nullable();
    $table->string('status', 8)->default('open');
    $table->dateTime('placed_at');
    $table->dateTime('settled_at')->nullable();

    $table->index(['user_id', 'status']);
    $table->index(['status', 'settled_at']);
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
