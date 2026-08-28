<?php

declare(strict_types=1);

/**
 * Background worker. Stage 1 applies payments inline inside the webhook request, so there
 * is nothing to run yet.
 *
 * Stage 2 moves the work here: the webhook endpoint only commits the payment_events row and
 * returns 200, and this loop claims unapplied events (FOR UPDATE SKIP LOCKED), applies them
 * and drives delivery. That is also where out-of-order webhooks - events whose order did not
 * exist yet - get picked up on a later pass.
 */

require __DIR__ . '/../vendor/autoload.php';

fwrite(STDERR, "worker: nothing to do in stage 1, payments are applied inline by the webhook\n");
exit(0);
