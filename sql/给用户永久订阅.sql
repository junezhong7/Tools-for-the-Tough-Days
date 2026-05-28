INSERT INTO subscriptions
(user_id, product_key, plan_type, status, current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at)
VALUES
(14, 'individual_monthly', 'individual', 'active', NOW(), '2099-12-31 23:59:59', 0, NOW(), NOW());