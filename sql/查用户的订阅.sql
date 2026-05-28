SELECT id, user_id, product_key, plan_type, status, current_period_start, current_period_end
FROM subscriptions
WHERE user_id = 2
ORDER BY created_at DESC;