-- Outcome of the last application attempt. applied=false now means exactly one thing:
-- "this event may still be needed" - and result says why it was not consumed.
--   applied  : the event moved the order
--   ignored  : the order exists but was past 'created' - a swallowed duplicate or a late
--              event; retrying it would spin the worker forever, so applied is set to true
--   order_missing : the order did not exist yet - the only retryable outcome, applied stays false
ALTER TABLE payment_events ADD COLUMN result text;

UPDATE payment_events SET result = 'applied' WHERE applied AND result IS NULL;
