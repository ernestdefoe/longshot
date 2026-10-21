<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's balance, when Longshot keeps its own rather than spending
 * FoF Gamification points, and whether they stay off leaderboards.
 */
return Migration::createTable('longshot_wallets', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->bigInteger('balance')->default(0);
    $table->boolean('private')->default(false);
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
