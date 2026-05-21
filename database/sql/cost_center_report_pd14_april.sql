/*
Cost Center/Class report for PD14 - Welding and Fabrication, April 2026.

Date convention:
  date_from is inclusive.
  date_to is exclusive.
  April 2026 = 2026-04-01 <= transdate < 2026-05-01.

Encoding:
  Some ERP text may be stored as WIN874/TIS-620/SQL_ASCII bytes. The helper below
  tries WIN874 conversion first and falls back to the original text. If the
  database already stores valid UTF-8, the fallback keeps the original value.
*/

CREATE OR REPLACE FUNCTION public.erp_safe_text(value text)
RETURNS text
LANGUAGE plpgsql
IMMUTABLE
AS $$
DECLARE
    converted text;
BEGIN
    IF value IS NULL THEN
        RETURN NULL;
    END IF;

    BEGIN
        converted := convert_from(value::bytea, 'WIN874');
        RETURN NULLIF(TRIM(converted), '');
    EXCEPTION WHEN OTHERS THEN
        BEGIN
            RETURN NULLIF(TRIM(value), '');
        EXCEPTION WHEN OTHERS THEN
            RETURN NULL;
        END;
    END;
END;
$$;

WITH params AS (
    SELECT
        DATE '2026-04-01' AS date_from,
        DATE '2026-05-01' AS date_to,
        'PD14'::text AS classnumber
),

/*
Base accounting rows:
  acc_trans.id = ccalloc.acc_trans_id
  ccalloc.chart_id = chart.id
  ccalloc.class_id = classinfo.id

ccalloc is the authoritative cost center allocation source.
*/
base_alloc AS (
    SELECT
        at.id AS acc_trans_id,
        at.trans_id,
        at.transdate,
        public.erp_safe_text(at.source) AS acc_source,
        ca.id AS ccalloc_id,
        ca.chart_id,
        ca.class_id,
        ABS(ca.amount)::numeric AS class_amount,
        c.accno AS account_no,
        public.erp_safe_text(c.description) AS account_name,
        ci.classnumber,
        public.erp_safe_text(ci.description) AS class_name
    FROM acc_trans at
    JOIN ccalloc ca
        ON ca.acc_trans_id = at.id
    JOIN chart c
        ON c.id = ca.chart_id
    JOIN classinfo ci
        ON ci.id = ca.class_id
    CROSS JOIN params p
    WHERE at.transdate >= p.date_from
      AND at.transdate < p.date_to
      AND (p.classnumber IS NULL OR ci.classnumber = p.classnumber)
),

/*
Document/header detection:
  acc_trans.trans_id can point to multiple document tables. There are no enforced
  foreign keys, so joins are inferred and parent_type follows the ERP precedence.
*/
document_rows AS (
    SELECT
        ba.*,
        ap.id AS ap_id,
        ar.id AS ar_id,
        gl.id AS gl_id,
        ct.trans_id AS cashtrans_id,
        rt.id AS return_id,
        CASE
            WHEN gl.id IS NOT NULL THEN 'GL'
            WHEN ap.id IS NOT NULL THEN 'AP'
            WHEN ar.id IS NOT NULL THEN 'AR'
            WHEN ct.trans_id IS NOT NULL THEN 'PAYMENT/CASHTRANS'
            WHEN rt.id IS NOT NULL THEN 'RETURN'
            ELSE 'UNKNOWN'
        END AS parent_type,
        CASE
            WHEN gl.id IS NOT NULL THEN public.erp_safe_text(gl.reference)
            WHEN ap.id IS NOT NULL THEN public.erp_safe_text(ap.invnumber)
            WHEN ar.id IS NOT NULL THEN public.erp_safe_text(ar.invnumber)
            WHEN ct.trans_id IS NOT NULL THEN COALESCE(public.erp_safe_text(ap_pay.invnumber), public.erp_safe_text(ar_pay.invnumber))
            WHEN rt.id IS NOT NULL THEN public.erp_safe_text(rt.returnnumber)
            ELSE ba.trans_id::text
        END AS invoice_no,
        CASE
            WHEN ap.id IS NOT NULL THEN public.erp_safe_text(ap.ordnumber)
            WHEN ar.id IS NOT NULL THEN public.erp_safe_text(ar.ordnumber)
            WHEN ct.trans_id IS NOT NULL THEN COALESCE(public.erp_safe_text(ap_pay.ordnumber), public.erp_safe_text(ar_pay.ordnumber))
            WHEN rt.id IS NOT NULL THEN COALESCE(public.erp_safe_text(ar_ret.ordnumber), public.erp_safe_text(rt.invnumber))
            ELSE ''
        END AS order_no,
        COALESCE(
            public.erp_safe_text(ap.notes),
            public.erp_safe_text(ar.notes),
            public.erp_safe_text(gl.notes),
            ba.acc_source
        ) AS notes,
        COALESCE(public.erp_safe_text(ap.f1), public.erp_safe_text(ar.f1), '') AS extra_2,
        COALESCE(public.erp_safe_text(ap.f2), public.erp_safe_text(ar.f2), '') AS extra_3,
        COALESCE(public.erp_safe_text(ap.f3), public.erp_safe_text(ar.f3), '') AS extra_4,
        COALESCE(public.erp_safe_text(ap.f4), public.erp_safe_text(ar.f4), '') AS extra_5
    FROM base_alloc ba
    LEFT JOIN ap
        ON ap.id = ba.trans_id
    LEFT JOIN ar
        ON ar.id = ba.trans_id
    LEFT JOIN gl
        ON gl.id = ba.trans_id
    LEFT JOIN cashtrans ct
        ON ct.trans_id = ba.trans_id
    LEFT JOIN ap ap_pay
        ON ap_pay.id = ct.ap_ar_id
    LEFT JOIN ar ar_pay
        ON ar_pay.id = ct.ap_ar_id
    LEFT JOIN public."return" rt
        ON rt.id = ba.trans_id
    LEFT JOIN LATERAL (
        SELECT ar_match.*
        FROM ar ar_match
        WHERE ar_match.id = rt.ap_ar_id
           OR ar_match.invnumber = rt.invnumber
        ORDER BY
            CASE WHEN ar_match.id = rt.ap_ar_id THEN 0 ELSE 1 END,
            ar_match.id
        LIMIT 1
    ) ar_ret
        ON rt.id IS NOT NULL
),

/*
Invoice candidates:
  invoice.trans_id = AP/AR/GL/Return id.
  Prefer detail lines matching both account and class, then class, then account.
  If no line carries chart/class information, only use invoice fallback lines
  that have a usable description. Blank invoice lines are ignored so import
  duty/shipping rows do not get split into artificial positive/negative rows.
*/
invoice_candidates AS (
    SELECT
        dr.*,
        i.id AS invoice_line_id,
        public.erp_safe_text(i.description) AS invoice_description,
        public.erp_safe_text(i.ref1) AS invoice_ref1,
        public.erp_safe_text(i.ref2) AS invoice_ref2,
        public.erp_safe_text(i.f1) AS invoice_f1,
        public.erp_safe_text(i.f2) AS invoice_f2,
        public.erp_safe_text(i.f3) AS invoice_f3,
        public.erp_safe_text(i.f4) AS invoice_f4,
        public.erp_safe_text(i.f5) AS invoice_f5,
        i.qty,
        i.sellprice,
        i.unitcost,
        i.chart_id AS invoice_chart_id,
        i.class_id AS invoice_class_id,
        CASE
            WHEN i.id IS NULL THEN -1
            WHEN i.chart_id = dr.chart_id AND i.class_id = dr.class_id THEN 3
            WHEN i.class_id = dr.class_id THEN 2
            WHEN i.chart_id = dr.chart_id THEN 1
            WHEN i.chart_id IS NULL
                AND i.class_id IS NULL
                AND public.erp_safe_text(i.description) IS NOT NULL THEN 0
            ELSE -1
        END AS match_rank
    FROM document_rows dr
    LEFT JOIN invoice i
        ON i.trans_id = dr.trans_id
       AND (
            (i.chart_id = dr.chart_id AND i.class_id = dr.class_id)
            OR i.class_id = dr.class_id
            OR i.chart_id = dr.chart_id
            OR (
                i.chart_id IS NULL
                AND i.class_id IS NULL
                AND public.erp_safe_text(i.description) IS NOT NULL
            )
       )
),

best_invoice_lines AS (
    SELECT *
    FROM (
        SELECT
            ic.*,
            MAX(ic.match_rank) OVER (PARTITION BY ic.ccalloc_id) AS best_match_rank
        FROM invoice_candidates ic
    ) ranked
    WHERE ranked.invoice_line_id IS NULL
       OR ranked.match_rank = ranked.best_match_rank
),

detail_calc AS (
    SELECT
        bil.*,
        CASE
            WHEN bil.invoice_line_id IS NULL THEN 1::numeric
            WHEN ABS(COALESCE(bil.qty, 0)) = 0 THEN 1::numeric
            ELSE ROUND(ABS(bil.qty))::numeric
        END AS display_qty,
        CASE
            WHEN bil.invoice_line_id IS NULL THEN bil.class_amount
            ELSE ABS(COALESCE(bil.sellprice, bil.unitcost, 0))::numeric
        END AS raw_unit_price
    FROM best_invoice_lines bil
),

detail_reconciled AS (
    SELECT
        dc.*,
        dc.display_qty * dc.raw_unit_price AS raw_line_amount,
        COUNT(dc.invoice_line_id) OVER (PARTITION BY dc.ccalloc_id) AS invoice_line_count,
        SUM(dc.display_qty * dc.raw_unit_price) OVER (PARTITION BY dc.ccalloc_id) AS raw_invoice_total,
        ROW_NUMBER() OVER (
            PARTITION BY dc.ccalloc_id
            ORDER BY dc.invoice_line_id NULLS LAST
        ) AS invoice_line_seq
    FROM detail_calc dc
),

detail_rows AS (
    SELECT
        dr.*,
        CASE
            WHEN dr.invoice_line_id IS NULL THEN dr.class_amount
            WHEN dr.invoice_line_seq = dr.invoice_line_count THEN
                dr.raw_line_amount + (dr.class_amount - COALESCE(dr.raw_invoice_total, 0))
            ELSE dr.raw_line_amount
        END AS line_amount
    FROM detail_reconciled dr
),

ordered_rows AS (
    SELECT
        dr.*,
        ROW_NUMBER() OVER (
            PARTITION BY dr.classnumber
            ORDER BY dr.account_no, dr.transdate, dr.invoice_no, dr.order_no, dr.ccalloc_id, dr.invoice_line_id NULLS LAST
        ) AS class_sort_seq
    FROM detail_rows dr
),

final_detail AS (
    SELECT
        'DETAIL'::text AS row_type,
        classnumber || ' - ' || COALESCE(class_name, '') AS class_group,
        classnumber,
        class_name,
        transdate AS report_date,
        invoice_no,
        order_no,
        account_no,
        account_no || ' ' || COALESCE(account_name, '') AS account_label,
        COALESCE(NULLIF(invoice_description, ''), NULLIF(acc_source, ''), '-') AS item_name,
        display_qty AS qty,
        CASE WHEN display_qty = 0 THEN line_amount ELSE line_amount / display_qty END AS unit_price,
        line_amount,
        SUM(line_amount) OVER (
            PARTITION BY classnumber
            ORDER BY class_sort_seq
            ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
        ) AS running_balance,
        notes,
        class_name AS extra_1,
        COALESCE(NULLIF(invoice_ref1, ''), NULLIF(extra_2, ''), '') AS extra_2,
        COALESCE(NULLIF(invoice_ref2, ''), NULLIF(extra_3, ''), '') AS extra_3,
        COALESCE(NULLIF(invoice_f1, ''), NULLIF(extra_4, ''), '') AS extra_4,
        COALESCE(NULLIF(invoice_f2, ''), NULLIF(extra_5, ''), '') AS extra_5,
        parent_type,
        acc_trans_id,
        ccalloc_id,
        invoice_line_id,
        class_sort_seq::numeric AS display_sort_seq
    FROM ordered_rows
),

final_subtotal AS (
    SELECT
        'SUBTOTAL'::text AS row_type,
        MAX(class_group) AS class_group,
        classnumber,
        MAX(class_name) AS class_name,
        NULL::date AS report_date,
        ''::text AS invoice_no,
        ''::text AS order_no,
        account_no,
        ''::text AS account_label,
        'TOTAL ' || COUNT(*) || ' ROWS' AS item_name,
        NULL::numeric AS qty,
        NULL::numeric AS unit_price,
        SUM(line_amount) AS line_amount,
        MAX(running_balance) AS running_balance,
        ''::text AS notes,
        ''::text AS extra_1,
        ''::text AS extra_2,
        ''::text AS extra_3,
        ''::text AS extra_4,
        ''::text AS extra_5,
        ''::text AS parent_type,
        NULL::int AS acc_trans_id,
        NULL::int AS ccalloc_id,
        NULL::int AS invoice_line_id,
        (MAX(display_sort_seq) + 0.5)::numeric AS display_sort_seq
    FROM final_detail
    GROUP BY classnumber, account_no
)

SELECT
    row_type,
    class_group,
    report_date,
    invoice_no,
    order_no,
    account_label,
    item_name,
    qty,
    unit_price,
    line_amount,
    running_balance,
    notes,
    extra_1,
    extra_2,
    extra_3,
    extra_4,
    extra_5,
    parent_type,
    acc_trans_id,
    ccalloc_id,
    invoice_line_id
FROM (
    SELECT * FROM final_detail
    UNION ALL
    SELECT * FROM final_subtotal
) report_rows
ORDER BY
    classnumber,
    account_no,
    display_sort_seq;

/*
Validation query:
Compare report detail totals with the authoritative ccalloc total by class/account.
Run this after the report query if totals look wrong.
*/

WITH params AS (
    SELECT
        DATE '2026-04-01' AS date_from,
        DATE '2026-05-01' AS date_to,
        'PD14'::text AS classnumber
),
ccalloc_totals AS (
    SELECT
        ci.classnumber,
        public.erp_safe_text(ci.description) AS class_name,
        c.accno AS account_no,
        public.erp_safe_text(c.description) AS account_name,
        COUNT(DISTINCT ca.id) AS allocation_rows,
        SUM(ABS(ca.amount)) AS ccalloc_total
    FROM acc_trans at
    JOIN ccalloc ca
        ON ca.acc_trans_id = at.id
    JOIN chart c
        ON c.id = ca.chart_id
    JOIN classinfo ci
        ON ci.id = ca.class_id
    CROSS JOIN params p
    WHERE at.transdate >= p.date_from
      AND at.transdate < p.date_to
      AND (p.classnumber IS NULL OR ci.classnumber = p.classnumber)
    GROUP BY ci.classnumber, public.erp_safe_text(ci.description), c.accno, public.erp_safe_text(c.description)
)
SELECT
    classnumber || ' - ' || COALESCE(class_name, '') AS class_group,
    account_no || ' ' || COALESCE(account_name, '') AS account_label,
    allocation_rows,
    ccalloc_total
FROM ccalloc_totals
ORDER BY classnumber, account_no;

/*
Optional source-report comparison template:
Paste expected Excel totals by class/account into expected_source, then run.
*/

WITH expected_source(classnumber, account_no, expected_total) AS (
    VALUES
        ('PD14'::text, '5210330'::text, NULL::numeric)
),
params AS (
    SELECT
        DATE '2026-04-01' AS date_from,
        DATE '2026-05-01' AS date_to
),
ccalloc_totals AS (
    SELECT
        ci.classnumber,
        c.accno AS account_no,
        SUM(ABS(ca.amount)) AS ccalloc_total
    FROM acc_trans at
    JOIN ccalloc ca
        ON ca.acc_trans_id = at.id
    JOIN chart c
        ON c.id = ca.chart_id
    JOIN classinfo ci
        ON ci.id = ca.class_id
    CROSS JOIN params p
    WHERE at.transdate >= p.date_from
      AND at.transdate < p.date_to
    GROUP BY ci.classnumber, c.accno
)
SELECT
    es.classnumber,
    es.account_no,
    es.expected_total,
    ct.ccalloc_total,
    COALESCE(ct.ccalloc_total, 0) - COALESCE(es.expected_total, 0) AS diff
FROM expected_source es
LEFT JOIN ccalloc_totals ct
    ON ct.classnumber = es.classnumber
   AND ct.account_no = es.account_no
ORDER BY es.classnumber, es.account_no;
