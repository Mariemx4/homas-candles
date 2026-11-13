-- Keep legacy timestamp columns in UTC and define business dates explicitly.
CREATE OR REPLACE FUNCTION homas_business_day_start(instant timestamptz)
RETURNS timestamp without time zone LANGUAGE sql STABLE AS $$
 SELECT ((instant AT TIME ZONE 'Africa/Tunis')::date::timestamp
          AT TIME ZONE 'Africa/Tunis') AT TIME ZONE 'UTC';
$$;

CREATE OR REPLACE FUNCTION guard_customer_daily_orders() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE day_start timestamp without time zone;
BEGIN
 IF NEW.customer_id IS NOT NULL THEN
  PERFORM id FROM customers_accounts WHERE id=NEW.customer_id FOR UPDATE;
  day_start := homas_business_day_start(statement_timestamp());
  IF (SELECT COUNT(*) FROM orders WHERE customer_id=NEW.customer_id
      AND created_at>=day_start AND created_at<day_start+INTERVAL '1 day')>=3 THEN
   RAISE EXCEPTION 'You may place at most three orders per day.' USING ERRCODE='23514', CONSTRAINT='customer_daily_order_limit';
  END IF;
 END IF;
 RETURN NEW;
END $$;
