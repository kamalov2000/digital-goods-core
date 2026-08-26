-- Belongs to the supplier stubs, not to the shop core. Kept in the same database only because
-- the stubs are local processes; a real supplier would own this in its own storage.
--
-- request_id is the PRIMARY KEY, which is what makes "timeout != failure" work: the supplier
-- records the issued code against the request_id, so a retry with the same request_id replays
-- that code instead of pulling a second key out of the pool.
CREATE TABLE supplier_issues (
    request_id text PRIMARY KEY,
    supplier   text NOT NULL,
    order_id   text NOT NULL,
    sku        text NOT NULL,
    code       text,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX supplier_issues_code_idx ON supplier_issues (code) WHERE code IS NOT NULL;
