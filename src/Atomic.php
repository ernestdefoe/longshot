<?php

namespace ErnestDefoe\Longshot;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;

/**
 * A transaction that is tried again when the database turns it away for a
 * concurrency reason: a MySQL or PostgreSQL deadlock, a lock wait that timed
 * out, SQLite's "database is locked". Nothing it did was kept, so running it
 * again is safe, and the member gets an answer instead of a 500.
 *
 * 🚨 With a pause between tries. SQLite refuses a stale writer at once rather
 * than queueing it, so retries without one fail together: measured with 12
 * simultaneous bets from one member, two still failed after three instant
 * retries, and none with the back-off.
 *
 * Only an outermost transaction is retried: inside another, the failure goes
 * up to the one that owns it.
 */
trait Atomic
{
    use DetectsConcurrencyErrors;

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    protected function atomic(\Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->db->transaction($work);
            } catch (QueryException $e) {
                if ($attempt >= 6 || $this->db->transactionLevel() > 0 || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                usleep(random_int(10_000, 40_000) * $attempt);
            }
        }
    }
}
