-- Stage 2, task 2: the supplier stops being trustworthy. It may hand back a code that was
-- already issued, a code that was never ours, or an error for a request it actually fulfilled.
-- Nothing it says is taken at face value any more.

-- "The supplier answered, but with something we refuse to give a customer."
ALTER TABLE issue_requests DROP CONSTRAINT issue_requests_status_check;
ALTER TABLE issue_requests ADD CONSTRAINT issue_requests_status_check CHECK (status IN (
    'pending', 'issued', 'out_of_stock', 'failed', 'unknown', 'invalid_code'
));

-- Audit trail of everything the supplier got wrong. Recording is idempotent, so a retry that
-- runs into the same lie does not pile up rows; resolved_at is filled by the automatic
-- resolution pass, never by a human.
CREATE TABLE supplier_discrepancies (
    id          bigserial PRIMARY KEY,
    request_id  text NOT NULL,
    order_id    text NOT NULL,
    item_id     text,
    supplier    text NOT NULL,
    kind        text NOT NULL CHECK (kind IN (
                    'duplicate_code', 'foreign_code', 'error_after_issue', 'orphaned_reservation'
                )),
    code        text,
    detail      text,
    detected_at timestamptz NOT NULL DEFAULT now(),
    resolved_at timestamptz,
    resolution  text,
    UNIQUE NULLS NOT DISTINCT (request_id, kind, code)
);

CREATE INDEX supplier_discrepancies_open_idx ON supplier_discrepancies (detected_at)
    WHERE resolved_at IS NULL;
CREATE INDEX supplier_discrepancies_order_idx ON supplier_discrepancies (order_id);
