-- Stage 2, task 3: the supplier accepts only so many issue requests per minute.
--
-- Two separate ledgers on purpose. supplier_calls is the supplier's own bookkeeping of what it
-- accepted; delivery_slots is ours, of what we decided to send. Sharing one table would mean
-- reading the supplier's internals to decide our own pacing, which is not something a real
-- integration can do - and it would make the test that "the limit was never exceeded"
-- meaningless, since both sides would be counting the same rows.

CREATE TABLE supplier_calls (
    id        bigserial PRIMARY KEY,
    supplier  text NOT NULL,
    called_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX supplier_calls_window_idx ON supplier_calls (supplier, called_at);

CREATE TABLE delivery_slots (
    id       bigserial PRIMARY KEY,
    supplier text NOT NULL,
    taken_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX delivery_slots_window_idx ON delivery_slots (supplier, taken_at);
