-- page=accounts site=WIRE
SET jit = off;
SET work_mem = '128MB';
EXPLAIN (ANALYZE, BUFFERS)
WITH params AS (
    SELECT '2025-01-01'::date AS date_from, '2025-12-31'::date AS date_to
),
ap_scope AS (
    SELECT id
    FROM ap
    WHERE transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
),
acc AS (
    SELECT DISTINCT ON (at.trans_id)
        at.trans_id,
        ch.accno || ' ' || ch.description AS account
    FROM acc_trans at
    JOIN chart ch ON ch.id = at.chart_id
    WHERE at.amount < 0
      AND ch.accno NOT LIKE '2%'
      AND at.trans_id IN (SELECT id FROM ap_scope)
    ORDER BY at.trans_id,
        CASE WHEN ch.accno NOT LIKE '1%' THEN 0 ELSE 1 END,
        CASE WHEN ch.accno LIKE '111%' THEN 1 ELSE 0 END,
        ch.accno
),
cc_at AS (
    SELECT
        cc.trans_id AS ap_id,
        cc.class_id,
        at.id AS at_id,
        at.amount,
        at.chart_id,
        ROW_NUMBER() OVER (PARTITION BY cc.trans_id ORDER BY at.id) AS rn
    FROM ccalloc cc
    JOIN acc_trans at ON at.id = cc.acc_trans_id
    WHERE cc.class_id > 0
      AND at.amount < 0
      AND cc.trans_id IN (SELECT id FROM ap_scope)
),
inv_ranked AS (
    SELECT
        trans_id,
        description,
        qty,
        sellprice,
        ROW_NUMBER() OVER (PARTITION BY trans_id ORDER BY id) AS rn
    FROM invoice
    WHERE trans_id IN (SELECT id FROM ap_scope)
),
detail AS (
    SELECT
        'AP_INVOICE'::text AS source,
        ap.id AS source_id,
        inv.id AS line_seq,
        ci.classnumber,
        ci.description AS dept_desc,
        ap.transdate,
        ap.invnumber,
        ap.ordnumber,
        ap.apnumber,
        COALESCE(acc.account, ch.accno || ' ' || ch.description) AS account,
        inv.description AS item_desc,
        inv.qty * -1 AS qty,
        inv.sellprice * -1 AS unit_price,
        inv.sellprice * inv.qty * -1 AS amount,
        ap.notes,
        ap.f1,
        ap.f2,
        ap.f3,
        ap.f4,
        ap.f5
    FROM ap
    JOIN invoice inv ON inv.trans_id = ap.id
    JOIN classinfo ci ON ci.id = inv.class_id
    LEFT JOIN chart ch ON ch.id = inv.chart_id
    LEFT JOIN acc ON acc.trans_id = ap.id
    WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
      AND inv.class_id > 0

    UNION ALL

    SELECT
        'AP_CC'::text AS source,
        ap.id AS source_id,
        ca.at_id AS line_seq,
        ci.classnumber,
        ci.description,
        ap.transdate,
        ap.invnumber,
        ap.ordnumber,
        ap.apnumber,
        ch.accno || ' ' || ch.description,
        COALESCE(ir.description, ch.description),
        COALESCE(ir.qty, -1),
        ca.amount * -1,
        ca.amount * -1,
        ap.notes,
        ap.f1,
        ap.f2,
        ap.f3,
        ap.f4,
        ap.f5
    FROM ap
    JOIN cc_at ca ON ca.ap_id = ap.id
    JOIN classinfo ci ON ci.id = ca.class_id
    LEFT JOIN inv_ranked ir ON ir.trans_id = ap.id AND ir.rn = ca.rn
    LEFT JOIN chart ch ON ch.id = ca.chart_id
    WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
      AND NOT EXISTS (
          SELECT 1 FROM invoice inv2
          WHERE inv2.trans_id = ap.id AND inv2.class_id > 0
      )

    UNION ALL

    SELECT DISTINCT
        'AP_CASH_INVOICE'::text AS source,
        ap.id AS source_id,
        inv.id AS line_seq,
        ci.classnumber,
        ci.description,
        ap.transdate,
        ap.invnumber,
        ap.ordnumber,
        ap.apnumber,
        ch.accno || ' ' || ch.description,
        COALESCE(inv.description, ap.notes),
        inv.qty,
        inv.sellprice,
        inv.sellprice * inv.qty,
        ap.notes,
        ap.f1,
        ap.f2,
        ap.f3,
        ap.f4,
        ap.f5
    FROM ap
    JOIN cashtrans ct ON ct.ap_ar_id = ap.id
    JOIN cashout co ON co.id = ct.trans_id
    JOIN ccalloc cc ON cc.trans_id = co.id
    JOIN classinfo ci ON ci.id = cc.class_id
    JOIN invoice inv ON inv.trans_id = ap.id
    LEFT JOIN chart ch ON ch.id = inv.chart_id
    WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
      AND cc.class_id > 0
      AND NOT EXISTS (
          SELECT 1 FROM invoice inv2
          WHERE inv2.trans_id = ap.id AND inv2.class_id > 0
      )
      AND NOT EXISTS (
          SELECT 1 FROM ccalloc cc2
          WHERE cc2.trans_id = ap.id AND cc2.class_id > 0
      )
      AND NOT EXISTS (
          SELECT 1 FROM invoice inv3
          JOIN chart ch3 ON ch3.id = inv3.chart_id
          WHERE inv3.trans_id = ap.id
            AND ch3.accno LIKE '1%'
      )
      AND (
          SELECT SUM(inv4.sellprice * inv4.qty)
          FROM invoice inv4
          WHERE inv4.trans_id = ap.id
      ) = ap.amount
      AND (
          SELECT COUNT(DISTINCT cc3.class_id)
          FROM cashtrans ct3
          JOIN cashout co3 ON co3.id = ct3.trans_id
          JOIN ccalloc cc3 ON cc3.trans_id = co3.id
          WHERE ct3.ap_ar_id = ap.id
            AND cc3.class_id > 0
      ) = 1
      AND EXISTS (
          SELECT 1 FROM invoice inv_check
          WHERE inv_check.trans_id = ap.id
            AND inv_check.description IS NOT NULL
            AND inv_check.description != ''
      )

    UNION ALL

    SELECT DISTINCT
        'AP_CASH_ALLOC'::text AS source,
        ap.id AS source_id,
        cc.id AS line_seq,
        ci.classnumber,
        ci.description,
        ap.transdate,
        ap.invnumber,
        ap.ordnumber,
        ap.apnumber,
        ch.accno || ' ' || ch.description,
        ap.notes,
        -1,
        cc.amount * -1,
        cc.amount * -1,
        ap.notes,
        ap.f1,
        ap.f2,
        ap.f3,
        ap.f4,
        ap.f5
    FROM ap
    JOIN cashtrans ct ON ct.ap_ar_id = ap.id
    JOIN cashout co ON co.id = ct.trans_id
    JOIN ccalloc cc ON cc.trans_id = co.id
    JOIN classinfo ci ON ci.id = cc.class_id
    LEFT JOIN chart ch ON ch.id = cc.chart_id
    WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
      AND cc.class_id > 0
      AND NOT EXISTS (
          SELECT 1 FROM invoice inv2
          WHERE inv2.trans_id = ap.id AND inv2.class_id > 0
      )
      AND NOT EXISTS (
          SELECT 1 FROM ccalloc cc2
          WHERE cc2.trans_id = ap.id AND cc2.class_id > 0
      )
      AND (
          EXISTS (
              SELECT 1 FROM invoice inv3
              JOIN chart ch3 ON ch3.id = inv3.chart_id
              WHERE inv3.trans_id = ap.id
                AND ch3.accno LIKE '1%'
          )
          OR (
              SELECT COALESCE(SUM(inv4.sellprice * inv4.qty), 0)
              FROM invoice inv4
              WHERE inv4.trans_id = ap.id
          ) != ap.amount
          OR NOT EXISTS (
              SELECT 1 FROM invoice inv5
              WHERE inv5.trans_id = ap.id
          )
          OR (
              SELECT COUNT(DISTINCT cc3.class_id)
              FROM cashtrans ct3
              JOIN cashout co3 ON co3.id = ct3.trans_id
              JOIN ccalloc cc3 ON cc3.trans_id = co3.id
              WHERE ct3.ap_ar_id = ap.id
                AND cc3.class_id > 0
          ) > 1
          OR NOT EXISTS (
              SELECT 1 FROM invoice inv_desc
              WHERE inv_desc.trans_id = ap.id
                AND inv_desc.description IS NOT NULL
                AND inv_desc.description != ''
          )
      )
      AND NOT (
          NOT EXISTS (
              SELECT 1 FROM invoice inv6
              JOIN chart ch6 ON ch6.id = inv6.chart_id
              WHERE inv6.trans_id = ap.id
                AND ch6.accno LIKE '1%'
          )
          AND (
              SELECT SUM(inv7.sellprice * inv7.qty)
              FROM invoice inv7
              WHERE inv7.trans_id = ap.id
          ) = ap.amount
          AND (
              SELECT COUNT(DISTINCT cc4.class_id)
              FROM cashtrans ct4
              JOIN cashout co4 ON co4.id = ct4.trans_id
              JOIN ccalloc cc4 ON cc4.trans_id = co4.id
              WHERE ct4.ap_ar_id = ap.id
                AND cc4.class_id > 0
          ) = 1
          AND EXISTS (
              SELECT 1 FROM invoice inv8
              WHERE inv8.trans_id = ap.id
                AND inv8.description IS NOT NULL
                AND inv8.description != ''
          )
      )

    UNION ALL

    SELECT
        'GL_IU'::text AS source,
        g.id AS source_id,
        pm.id AS line_seq,
        ci.classnumber,
        ci.description,
        g.transdate,
        g.transnumber,
        NULL AS ordnumber,
        g.transnumber,
        ch.accno || ' ' || ch.description,
        g.description,
        pm.qty * -1,
        pm.unitcost,
        pm.qty * pm.unitcost * -1,
        g.notes,
        NULL,
        NULL,
        NULL,
        NULL,
        NULL
    FROM gl g
    JOIN partsmvmt pm ON pm.trans_id = g.id
    JOIN classinfo ci ON ci.id = pm.class_id
    LEFT JOIN parts p ON p.id = pm.parts_id
    LEFT JOIN chart ch ON ch.id = p.expense_accno_id
    WHERE g.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
      AND g.transnumber ILIKE 'IU%'
      AND NOT g.transnumber ILIKE 'IUB%'
      AND pm.class_id > 0
)
, filtered AS (
    SELECT * FROM detail
    WHERE split_part(COALESCE(account, ''), ' ', 1) NOT IN ('1110203','2020500') AND TRUE AND ('' = '' OR invnumber ILIKE '%' || '' || '%' OR apnumber ILIKE '%' || '' || '%' OR COALESCE(ordnumber, '') ILIKE '%' || '' || '%') AND ('' = '' OR COALESCE(notes, '') ILIKE '%' || '' || '%' OR COALESCE(item_desc, '') ILIKE '%' || '' || '%') AND TRUE
)
SELECT
    'overall'::text AS kind,
    ''::text AS classnumber,
    ''::text AS dept_desc,
    ''::text AS account,
    NULL::int AS month_no,
    ''::text AS month_key,
    COALESCE(SUM(amount), 0) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count,
    COUNT(DISTINCT NULLIF(COALESCE(classnumber, '') || '|' || COALESCE(dept_desc, ''), '|')) AS dept_count,
    COUNT(DISTINCT NULLIF(account, '')) AS account_count
FROM filtered
UNION ALL
SELECT
    'acc'::text,
    '',
    '',
    COALESCE(account, ''),
    NULL::int,
    '',
    SUM(amount),
    COUNT(*),
    0,
    0,
    0
FROM filtered
WHERE COALESCE(account, '') <> ''
GROUP BY account;