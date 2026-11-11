-- Optional GST % on each expense line item. The website's Expenses page
-- adds this itself the first time it opens; run it by hand only if you
-- prefer to. Existing lines read as 0% GST, so nothing changes for them.
ALTER TABLE expense_items
  ADD COLUMN gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER unit_cost;
