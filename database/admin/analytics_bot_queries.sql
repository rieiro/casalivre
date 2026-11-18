-- Admin Bot Analytics Queries (docs/ai/ARCHITECTURE_AI_PLAN.md §5.4)

-- 1. Traffic by bot, last 24 hours
SELECT bot_name,
       count(*) AS hits,
       count(*) FILTER (WHERE status_code >= 400) AS errors,
       round(avg(latency_ms)) AS avg_ms
FROM bot_action_logs
WHERE created_at >= now() - interval '24 hours'
GROUP BY bot_name
ORDER BY hits DESC;

-- 2. Top cities, date pairs, and amenities from agent searches, last 7 days
SELECT COALESCE(search_params->>'localidade', '') AS city,
       COALESCE(search_params->>'checkin', '') AS checkin,
       COALESCE(search_params->>'checkout', '') AS checkout,
       COALESCE(amenity, '') AS amenity,
       count(*) AS hits
FROM bot_action_logs
LEFT JOIN LATERAL jsonb_array_elements_text(
    COALESCE(search_params->'amenidades', '[]'::jsonb)
) AS amenity ON true
WHERE action_name = 'search_properties'
  AND channel IN ('rest', 'mcp')
  AND created_at >= now() - interval '7 days'
GROUP BY GROUPING SETS (
    (search_params->>'localidade'),
    (search_params->>'checkin', search_params->>'checkout'),
    (amenity)
)
ORDER BY hits DESC
LIMIT 100;

-- 3. Quote to hold conversion, last 7 days
SELECT bot_name,
       count(*) FILTER (
           WHERE action_name = 'check_availability_and_quote' AND status_code = 200
       ) AS quotes,
       count(*) FILTER (
           WHERE action_name = 'create_booking_intent' AND status_code = 201
       ) AS intents,
       round(
           100.0 * count(*) FILTER (
               WHERE action_name = 'create_booking_intent' AND status_code = 201
           ) / NULLIF(count(*) FILTER (
               WHERE action_name = 'check_availability_and_quote' AND status_code = 200
           ), 0),
           1
       ) AS conversion_pct
FROM bot_action_logs
WHERE created_at >= now() - interval '7 days'
GROUP BY bot_name
ORDER BY intents DESC;
