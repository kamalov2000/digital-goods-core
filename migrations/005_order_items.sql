-- Stage 2, task 1: an order is a set of line items, each delivered by its own supplier and
-- each able to fail on its own. The unit of delivery stops being the order and becomes the item.

-- Which supplier owns a sku. The other one stays available as a fallback, exactly as before.
ALTER TABLE products ADD COLUMN supplier text NOT NULL DEFAULT 'A';

-- orders.sku survives as a convenience for single-item orders (the stage 1 API still works and
-- still answers with it); it is NULL once an order has more than one line. The money total stays
-- in orders.amount, the breakdown moves to order_items.
ALTER TABLE orders ALTER COLUMN sku DROP NOT NULL;

-- Two new terminal states. An order that delivered part of its lines and refunded the rest is
-- not a failure and not a success - it needs its own name.
ALTER TABLE orders DROP CONSTRAINT orders_status_check;
ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
    'created', 'paid', 'delivering', 'delivered',
    'payment_failed', 'out_of_stock', 'delivery_failed',
    'partially_delivered', 'refunded'
));

CREATE TABLE order_items (
    id         text PRIMARY KEY,
    order_id   text NOT NULL REFERENCES orders (id),
    sku        text NOT NULL REFERENCES products (sku),
    amount     integer NOT NULL CHECK (amount >= 0),
    currency   text NOT NULL,
    supplier   text NOT NULL,
    status     text NOT NULL CHECK (status IN (
                   'pending', 'delivering', 'delivered',
                   'out_of_stock', 'delivery_failed', 'refunded'
               )),
    code       text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX order_items_order_id_idx ON order_items (order_id);
-- the worker scans by status; updated_at bounds the recovery and refund windows
CREATE INDEX order_items_status_updated_at_idx ON order_items (status, updated_at);
-- one code can never sit on two line items, whatever a supplier claims
CREATE UNIQUE INDEX order_items_code_idx ON order_items (code) WHERE code IS NOT NULL;

-- Delivery is now tracked per line item, so request_id becomes req_{item_id}_{supplier}.
ALTER TABLE issue_requests ADD COLUMN item_id text REFERENCES order_items (id);

-- Backfill: every existing order becomes a single-line order. The id is derived from the order
-- id so a re-run cannot produce a second line for the same order.
INSERT INTO order_items (id, order_id, sku, amount, currency, supplier, status, code, created_at, updated_at)
SELECT 'itm_' || substr(md5(o.id), 1, 16),
       o.id,
       o.sku,
       o.amount,
       o.currency,
       'A',
       CASE o.status
           WHEN 'delivered'       THEN 'delivered'
           WHEN 'delivering'      THEN 'delivering'
           WHEN 'out_of_stock'    THEN 'out_of_stock'
           WHEN 'delivery_failed' THEN 'delivery_failed'
           ELSE 'pending'
       END,
       (SELECT r.code FROM issue_requests r
        WHERE r.order_id = o.id AND r.status = 'issued' LIMIT 1),
       o.created_at,
       o.updated_at
FROM orders o
WHERE o.sku IS NOT NULL;

UPDATE issue_requests r
SET item_id = i.id
FROM order_items i
WHERE i.order_id = r.order_id AND r.item_id IS NULL;

ALTER TABLE issue_requests ALTER COLUMN item_id SET NOT NULL;
CREATE INDEX issue_requests_item_id_idx ON issue_requests (item_id);
