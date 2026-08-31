-- Materialised availability counter for the storefront (stage 5).
--
-- available = number of FREE keys bound to this sku. The assignment's original 50-key pool is
-- generic (sku IS NULL) and is counted separately, once per request, as a shared bucket -
-- see Stock::sharedAvailable(). Keeping the two apart is what makes this counter exactly
-- verifiable: reserving a generic key cannot make a per-sku counter wrong.
CREATE TABLE stock (
    sku        text PRIMARY KEY REFERENCES products (sku),
    available  integer NOT NULL DEFAULT 0 CHECK (available >= 0),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- storefront filter "only what is actually buyable"
CREATE INDEX stock_available_idx ON stock (sku) WHERE available > 0;

INSERT INTO stock (sku, available)
SELECT p.sku, count(k.code)
FROM products p
LEFT JOIN key_pool k ON k.sku = p.sku AND k.order_id IS NULL
GROUP BY p.sku
ON CONFLICT (sku) DO NOTHING;
