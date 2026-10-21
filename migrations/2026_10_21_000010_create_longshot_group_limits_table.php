<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Per-group overrides of the forum-wide limits. Empty means "the default".
 * A member in several groups gets the most generous value of each.
 */
return Migration::createTable('longshot_group_limits', function (Blueprint $table) {
    $table->unsignedInteger('group_id')->primary();
    $table->bigInteger('max_stake')->nullable();
    $table->bigInteger('daily_limit')->nullable();
    $table->bigInteger('allowance')->nullable();

    $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
});
