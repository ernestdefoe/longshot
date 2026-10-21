<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Every movement of points, in or out.
 *
 * 🚨 `ref` is unique and names the reason exactly once: "bet:12:payout",
 * "allowance:7:2026-W43". A retried settlement or a double-clicked button
 * finds its ref already written and moves nothing. It is the guard against a
 * double payout, so it is enforced by the database, not by code.
 */
return Migration::createTable('longshot_ledger', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('user_id');
    $table->bigInteger('amount');
    $table->string('kind', 16);
    $table->string('ref', 100)->unique();
    $table->dateTime('created_at');

    $table->index(['user_id', 'id']);
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
