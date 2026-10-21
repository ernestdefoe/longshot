<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A layer's match against part of an offer. `back_stake` is the backer's
 * stake it covers and `lay_stake` the layer's liability: the backer's profit
 * at the offer's odds. The winner takes both; a push or void returns each.
 */
return Migration::createTable('longshot_matches', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('offer_id');
    $table->unsignedInteger('user_id');
    $table->bigInteger('back_stake');
    $table->bigInteger('lay_stake');
    $table->string('result', 8)->nullable();
    $table->dateTime('created_at');
    $table->dateTime('settled_at')->nullable();

    $table->index('offer_id');
    $table->index(['user_id', 'result']);
    $table->foreign('offer_id')->references('id')->on('longshot_offers')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
