-- Read-only verification after running 01_create_production_balance_history.sql
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'pp_production_balance_runs',
    'pp_production_balance_snapshots',
    'pp_production_balance_alerts',
    'pp_production_balance_mail_logs'
  )
ORDER BY table_name;

SELECT
    r.id,
    r.run_uuid,
    r.status,
    r.source_as_of,
    r.total_count,
    r.excess_count,
    r.short_count,
    r.complete_count,
    r.duration_ms,
    COUNT(s.id) AS snapshot_rows
FROM pp_production_balance_runs r
LEFT JOIN pp_production_balance_snapshots s ON s.run_id = r.id
GROUP BY r.id, r.run_uuid, r.status, r.source_as_of, r.total_count,
         r.excess_count, r.short_count, r.complete_count, r.duration_ms
ORDER BY r.id DESC
LIMIT 10;

SELECT id, run_id, mail_status, row_count, sent_at, created_at,
       LEFT(error_message, 500) AS error_preview
FROM pp_production_balance_mail_logs
ORDER BY id DESC
LIMIT 20;
