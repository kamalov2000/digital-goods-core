-- Core schema. Designed for the whole assignment (stages 1-5) so migrations stay append-only.

CREATE TABLE products (
    sku        text PRIMARY KEY,
    name       text NOT NULL,
    type       text NOT NULL,
    price      integer NOT NULL CHECK (price >= 0),
    currency   text NOT NULL,
    image      text,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE orders (
    id         text PRIMARY KEY,
    sku        text NOT NULL REFERENCES products (sku),
    amount     integer NOT NULL CHECK (amount >= 0),
    currency   text NOT NULL,
    status     text NOT NULL CHECK (status IN (
                   'created', 'paid', 'delivering', 'delivered',
                   'payment_failed', 'out_of_stock', 'delivery_failed'
               )),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- reconciliation and the stuck-order sweeper scan by status; updated_at bounds the "hanging" window
CREATE INDEX orders_status_updated_at_idx ON orders (status, updated_at);

-- Webhook idempotency ledger: event_id is the dedup key, the row is inserted before any
-- side effect. No FK on order_id on purpose - a webhook may legitimately arrive before
-- the order row exists, and we still want to keep the event.
CREATE TABLE payment_events (
    event_id    text PRIMARY KEY,
    order_id    text NOT NULL,
    status      text NOT NULL CHECK (status IN ('paid', 'failed')),
    amount      integer NOT NULL,
    currency    text,
    payload     jsonb NOT NULL,
    applied     boolean NOT NULL DEFAULT false,
    received_at timestamptz NOT NULL DEFAULT now(),
    applied_at  timestamptz
);

CREATE INDEX payment_events_order_id_idx ON payment_events (order_id);
-- the worker (stage 2) and the reconciler (stage 4) only ever look at the unapplied tail
CREATE INDEX payment_events_pending_idx ON payment_events (received_at) WHERE NOT applied;

-- One row per (order, supplier) attempt. request_id is deterministic - see Delivery::requestId() -
-- so a retry after a timeout reuses it and the supplier replays the same code instead of issuing a new one.
CREATE TABLE issue_requests (
    request_id text PRIMARY KEY,
    order_id   text NOT NULL REFERENCES orders (id),
    supplier   text NOT NULL,
    status     text NOT NULL CHECK (status IN ('pending', 'issued', 'out_of_stock', 'failed', 'unknown')),
    code       text,
    attempts   integer NOT NULL DEFAULT 0,
    last_error text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX issue_requests_order_id_idx ON issue_requests (order_id);
-- hard guard against a double issue: the same code can never land on two requests
CREATE UNIQUE INDEX issue_requests_code_idx ON issue_requests (code) WHERE code IS NOT NULL;

-- Inventory of deliverable codes. Owned by the supplier stubs: only suppliers/supplier.php
-- reserves from it, the shop core just reads it (stock display, reconciliation).
-- sku IS NULL means "fits any sku" - the pool from the assignment is a generic one.
CREATE TABLE key_pool (
    code        text PRIMARY KEY,
    sku         text REFERENCES products (sku),
    order_id    text,
    reserved_at timestamptz
);

-- the reservation query and the stock showcase both hit exactly this partial index
CREATE INDEX key_pool_available_idx ON key_pool (sku) WHERE order_id IS NULL;
CREATE INDEX key_pool_order_id_idx ON key_pool (order_id);

-- Money movement journal (stage 4). ref carries the originating event_id / request_id so that
-- replaying a webhook can never write the same entry twice.
CREATE TABLE ledger (
    id         bigserial PRIMARY KEY,
    order_id   text NOT NULL,
    type       text NOT NULL,
    amount     integer NOT NULL,
    ref        text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (order_id, type, ref)
);

CREATE INDEX ledger_order_id_idx ON ledger (order_id);
