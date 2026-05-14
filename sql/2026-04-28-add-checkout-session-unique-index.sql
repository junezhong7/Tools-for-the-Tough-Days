-- Normalize historical duplicate checkout session references before adding a unique key.
-- Run this once on an existing database after taking a backup.

UPDATE subscriptions s
JOIN (
  SELECT stripe_checkout_session_id,
         COALESCE(MAX(CASE WHEN stripe_subscription_id IS NOT NULL THEN id END), MAX(id)) AS keep_id
  FROM subscriptions
  WHERE stripe_checkout_session_id IS NOT NULL
    AND stripe_checkout_session_id <> ''
  GROUP BY stripe_checkout_session_id
  HAVING COUNT(*) > 1
) dup ON dup.stripe_checkout_session_id = s.stripe_checkout_session_id
SET s.stripe_checkout_session_id = NULL
WHERE s.id <> dup.keep_id;

ALTER TABLE subscriptions
  ADD UNIQUE KEY uq_sub_checkout_session (stripe_checkout_session_id);