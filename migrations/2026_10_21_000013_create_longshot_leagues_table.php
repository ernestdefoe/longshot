<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/* A league's badge, cached like team logos. */
return Migration::createTable('longshot_leagues', function (Blueprint $table) {
    $table->string('key', 20)->primary();
    $table->string('badge_path', 190)->nullable();
    $table->string('badge_source', 255)->nullable();
    $table->dateTime('branded_at')->nullable();
});
