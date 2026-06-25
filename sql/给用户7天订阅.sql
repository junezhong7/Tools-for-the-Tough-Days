INSERT INTO subscriptions
(user_id, product_key, plan_type, status, current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at)
VALUES
(2, 'individual_monthly', 'individual', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, NOW(), NOW());