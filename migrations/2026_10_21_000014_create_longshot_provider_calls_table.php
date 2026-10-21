<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Calls made to each data provider per budget period ("2026-10-21" for a
 * daily budget, "2026-10" for a monthly one). A call is only made after
 * this count was raised under its budget, in one conditional UPDATE, so no
 * number of workers or retries can spend past it.
 */
return Migration::createTable('longshot_provider_calls', function (Blueprint $table) {
    $table->string('provider', 20);
    $table->string('period', 10);
    $table->unsignedInteger('calls')->default(0);

    $table->primary(['provider', 'period']);
});
