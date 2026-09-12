-- Stage 2, task 4: reconstructing the picture at any past moment.
--
-- Two append-only logs, deliberately kept apart:
--   order_events  every state an order or one of its lines has ever been in
--   ledger        every money fact (it already worked this way, it is only sealed here)
--
-- Nothing is ever corrected in place. A wrong state is followed by another event, not by an
-- UPDATE, which is what makes "what did this look like last Tuesday" answerable at all.

CREATE TABLE order_events (
    id          bigserial PRIMARY KEY,
    order_id    text NOT NULL,
    item_id     text,
    type        text NOT NULL CHECK (type IN ('order.created', 'order.status', 'item.status')),
    from_state  text,
    to_state    text NOT NULL,
    amount      integer,
    occurred_at timestamptz NOT NULL DEFAULT now()
);

-- the two shapes of question this table answers: "this order, over time" and "everything that
-- happened in this period"
CREATE INDEX order_events_order_idx ON order_events (order_id, occurred_at, id);
CREATE INDEX order_events_window_idx ON order_events (occurred_at, id);
CREATE INDEX order_events_item_idx ON order_events (item_id, occurred_at, id) WHERE item_id IS NOT NULL;

-- Append-only, enforced by the database rather than by good intentions. Row triggers do not
-- fire on TRUNCATE, which is intentional: tests/race_reset.php wipes the whole database between
-- runs, and that is a reset rather than a rewrite of history.
CREATE FUNCTION forbid_rewrite() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'table % is append-only, % is not allowed', TG_TABLE_NAME, TG_OP;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER order_events_append_only
    BEFORE UPDATE OR DELETE ON order_events
    FOR EACH ROW EXECUTE FUNCTION forbid_rewrite();

CREATE TRIGGER ledger_append_only
    BEFORE UPDATE OR DELETE ON ledger
    FOR EACH ROW EXECUTE FUNCTION forbid_rewrite();

-- Backfill so the history does not start empty for orders that already exist. There is only one
-- honest thing to say about them: this is the state they were in when the log was introduced.
INSERT INTO order_events (order_id, item_id, type, from_state, to_state, amount, occurred_at)
SELECT o.id, NULL, 'order.created', NULL, o.status, o.amount, o.created_at
FROM orders o;

INSERT INTO order_events (order_id, item_id, type, from_state, to_state, amount, occurred_at)
SELECT i.order_id, i.id, 'item.status', NULL, i.status, i.amount, i.created_at
FROM order_items i;
