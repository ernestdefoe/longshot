<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The exchange: a member BACKS a selection at odds they name, and other
 * members LAY it by matching part or all of the stake. A target user makes it
 * a direct challenge only that member can accept.
 *
 * `matched` is how much of `stake` has been matched. The rest is held until it
 * is matched, cancelled or the event starts, when it goes back to the backer.
 */
return Migration::createTable('longshot_offers', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('selection_id');
    $table->unsignedInteger('market_id');
    $table->unsignedInteger('event_id');
    $table->integer('odds');
    $table->decimal('line', 7, 1)->nullable();
    $table->bigInteger('stake');
    $table->bigInteger('matched')->default(0);
    $table->unsignedInteger('target_user_id')->nullable();
    $table->string('status', 8)->default('open');
    $table->timestamps();

    $table->index(['event_id', 'status']);
    $table->index(['status', 'user_id']);
    $table->index('target_user_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('target_user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('selection_id')->references('id')->on('longshot_selections');
    $table->foreign('market_id')->references('id')->on('longshot_markets');
    $table->foreign('event_id')->references('id')->on('longshot_events');
});
