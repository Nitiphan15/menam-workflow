-- ============================================================================
-- AP Expense Dashboard - เธชเธฃเธธเธเธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเธ•เธฒเธกเนเธเธเธ + เน€เธเธฃเธตเธขเธเน€เธ—เธตเธขเธเธฃเธฒเธขเน€เธ”เธทเธญเธ
-- ============================================================================
--
-- เธซเธฅเธฑเธเธเธฒเธฃเธ”เธถเธเนเธเธเธ (department):
--   เธเธฒเธ ap.f1 เธกเธต pattern เนเธเธ "AC01 - ACCOUNT", "AD00 - ADMIN", "PD01 - DIE"
--   เนเธเน regex เธ”เธถเธเน€เธเธเธฒเธฐเธชเนเธงเธ department code (2-4 เธ•เธฑเธงเธญเธฑเธเธฉเธฃ + เธ•เธฑเธงเน€เธฅเธ)
--
-- โ ๏ธ IMPORTANT: เธเนเธญเธกเธนเธฅเนเธเธเธเธญเธฒเธเธญเธขเธนเนเนเธ f1 เธซเธฃเธทเธญ f2 เธเธถเนเธเธเธฑเธเธเธฒเธฃเธ•เธฑเนเธเธเนเธฒ
--    เธฅเธญเธเธ—เธฑเนเธเธชเธญเธเนเธฅเธฐเนเธเน COALESCE
-- ============================================================================


-- ============================================================================
-- View เธซเธฅเธฑเธ: เธเนเธฒเธขเธเธทเนเธญเนเธเธเธเธ—เธตเน clean เนเธฅเนเธง (เนเธเนเน€เธเนเธ base เธเธญเธเธ—เธธเธ dashboard)
-- ============================================================================
-- เธ—เธณเน€เธเนเธ CTE/Subquery เธเนเนเธ”เน เนเธกเนเธเธณเน€เธเนเธเธ•เนเธญเธเธชเธฃเนเธฒเธ view เธเธฃเธดเธ

-- เธซเธฅเธฑเธเธเธฒเธฃเนเธเธฐ department code:
--   "AC01 - ACCOUNT"          โ’ AC01 / ACCOUNT
--   "เธเธฑเธเธเธต - k.เธเธฃเธฃเธ“เธดเธเธฒเธฃเน"        โ’ เนเธกเน match (เธญเธขเธนเนเธเธญเธ dept)
--   "ADMIN"                   โ’ ADMIN (no code prefix)
--   "COMMISSION"              โ’ COMMISSION
--   ""                        โ’ "เนเธกเนเธฃเธฐเธเธธเนเธเธเธ"

-- เนเธเน regexp_match() เธเธญเธ PostgreSQL


-- ----------------------------------------------------------------------------
-- Query 1: เธชเธฃเธธเธเธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเนเธเธเธ (เธ•เธฒเธกเธเนเธงเธเธงเธฑเธเธ—เธตเน)
-- ----------------------------------------------------------------------------
WITH expense_base AS (
    SELECT
        ap.id AS ap_id,
        ap.transdate,
        ap.f1,
        ap.f2,
        -- เนเธเธฐ department: เธฅเธญเธเธซเธฒ pattern "XX## - NAME" เธเนเธญเธ
        -- เธ–เนเธฒเนเธกเนเน€เธเธญ เนเธเนเธเนเธฒเน€เธ•เนเธกเธเธญเธ f1 (เน€เธเนเธ ADMIN, COMMISSION)
        -- เธ–เนเธฒ f1 เธงเนเธฒเธ เธฅเธญเธ f2
        COALESCE(
            (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            (regexp_match(ap.f2, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            NULLIF(TRIM(ap.f1), ''),
            NULLIF(TRIM(ap.f2), ''),
            'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ'
        ) AS department_name,
        COALESCE(
            (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[1],
            (regexp_match(ap.f2, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[1],
            ''
        ) AS department_code,
        c.accno,
        c.description AS account_name,
        ABS(at.amount) AS expense_amount
    FROM ap
    JOIN acc_trans at ON at.trans_id = ap.id
    JOIN chart c ON c.id = at.chart_id
    WHERE c.category = 'E'
      AND ap.transdate BETWEEN '2026-04-01' AND '2026-04-30'
)
SELECT
    department_code                   AS "เธฃเธซเธฑเธชเนเธเธเธ",
    department_name                   AS "เนเธเธเธ",
    COUNT(DISTINCT ap_id)             AS "เธเธณเธเธงเธเธเธดเธฅ",
    COUNT(*)                          AS "เธเธณเธเธงเธเธฃเธฒเธขเธเธฒเธฃ",
    SUM(expense_amount)               AS "เธขเธญเธ”เธฃเธงเธก",
    ROUND(AVG(expense_amount), 2)     AS "เน€เธเธฅเธตเนเธขเธ•เนเธญเธฃเธฒเธขเธเธฒเธฃ",
    MIN(expense_amount)               AS "เธ•เนเธณเธชเธธเธ”",
    MAX(expense_amount)               AS "เธชเธนเธเธชเธธเธ”"
FROM expense_base
GROUP BY department_code, department_name
ORDER BY SUM(expense_amount) DESC;


-- ----------------------------------------------------------------------------
-- Query 2: เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”เธเนเธฒเนเธเนเธเนเธฒเธขเนเธ•เนเธฅเธฐเนเธเธเธ เนเธขเธเธ•เธฒเธกเธเธฑเธเธเธต
-- ----------------------------------------------------------------------------
WITH expense_base AS (
    SELECT
        ap.id AS ap_id,
        ap.transdate,
        COALESCE(
            (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            (regexp_match(ap.f2, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            NULLIF(TRIM(ap.f1), ''),
            NULLIF(TRIM(ap.f2), ''),
            'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ'
        ) AS department_name,
        COALESCE(
            (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[1],
            (regexp_match(ap.f2, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[1],
            ''
        ) AS department_code,
        c.accno,
        c.description AS account_name,
        ABS(at.amount) AS expense_amount
    FROM ap
    JOIN acc_trans at ON at.trans_id = ap.id
    JOIN chart c ON c.id = at.chart_id
    WHERE c.category = 'E'
      AND ap.transdate BETWEEN '2026-04-01' AND '2026-04-30'
)
SELECT
    department_code                   AS "เธฃเธซเธฑเธชเนเธเธเธ",
    department_name                   AS "เนเธเธเธ",
    accno                             AS "เน€เธฅเธเธเธฑเธเธเธต",
    account_name                      AS "เธเธทเนเธญเธเธฑเธเธเธต",
    COUNT(*)                          AS "เธเธณเธเธงเธ",
    SUM(expense_amount)               AS "เธขเธญเธ”เธฃเธงเธก"
FROM expense_base
GROUP BY department_code, department_name, accno, account_name
ORDER BY department_code, SUM(expense_amount) DESC;


-- ----------------------------------------------------------------------------
-- Query 3: เน€เธเธฃเธตเธขเธเน€เธ—เธตเธขเธเธเนเธฒเนเธเนเธเนเธฒเธขเนเธ•เนเธฅเธฐเนเธเธเธ เธฃเธฒเธขเน€เธ”เธทเธญเธ (Pivot)
-- ----------------------------------------------------------------------------
-- เนเธเนเธชเธณเธซเธฃเธฑเธเธ”เธนเนเธเธงเนเธเนเธกเนเธฅเธฐเน€เธเธฃเธตเธขเธเน€เธ—เธตเธขเธ - เนเธชเธ”เธเน€เธเนเธเธ•เธฒเธฃเธฒเธ: เนเธเธเธ ร— เน€เธ”เธทเธญเธ
WITH expense_base AS (
    SELECT
        COALESCE(
            (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            (regexp_match(ap.f2, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            NULLIF(TRIM(ap.f1), ''),
            NULLIF(TRIM(ap.f2), ''),
            'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ'
        ) AS department_name,
        EXTRACT(YEAR FROM ap.transdate) AS yr,
        EXTRACT(MONTH FROM ap.transdate) AS mn,
        ABS(at.amount) AS expense_amount
    FROM ap
    JOIN acc_trans at ON at.trans_id = ap.id
    JOIN chart c ON c.id = at.chart_id
    WHERE c.category = 'E'
      AND ap.transdate BETWEEN '2026-01-01' AND '2026-12-31'
)
SELECT
    department_name                                                          AS "เนเธเธเธ",
    SUM(CASE WHEN mn = 1  THEN expense_amount ELSE 0 END)                    AS "เธก.เธ.",
    SUM(CASE WHEN mn = 2  THEN expense_amount ELSE 0 END)                    AS "เธ.เธ.",
    SUM(CASE WHEN mn = 3  THEN expense_amount ELSE 0 END)                    AS "เธกเธต.เธ.",
    SUM(CASE WHEN mn = 4  THEN expense_amount ELSE 0 END)                    AS "เน€เธก.เธข.",
    SUM(CASE WHEN mn = 5  THEN expense_amount ELSE 0 END)                    AS "เธ.เธ.",
    SUM(CASE WHEN mn = 6  THEN expense_amount ELSE 0 END)                    AS "เธกเธด.เธข.",
    SUM(CASE WHEN mn = 7  THEN expense_amount ELSE 0 END)                    AS "เธ.เธ.",
    SUM(CASE WHEN mn = 8  THEN expense_amount ELSE 0 END)                    AS "เธช.เธ.",
    SUM(CASE WHEN mn = 9  THEN expense_amount ELSE 0 END)                    AS "เธ.เธข.",
    SUM(CASE WHEN mn = 10 THEN expense_amount ELSE 0 END)                    AS "เธ•.เธ.",
    SUM(CASE WHEN mn = 11 THEN expense_amount ELSE 0 END)                    AS "เธ.เธข.",
    SUM(CASE WHEN mn = 12 THEN expense_amount ELSE 0 END)                    AS "เธ.เธ.",
    SUM(expense_amount)                                                      AS "เธฃเธงเธกเธ—เธฑเนเธเธเธต",
    ROUND(SUM(expense_amount) / NULLIF(COUNT(DISTINCT mn), 0), 2)            AS "เน€เธเธฅเธตเนเธขเธ•เนเธญเน€เธ”เธทเธญเธ"
FROM expense_base
GROUP BY department_name
ORDER BY SUM(expense_amount) DESC;


-- ----------------------------------------------------------------------------
-- Query 4: เธฃเธงเธกเธ—เธธเธเนเธเธเธ เนเธขเธเธฃเธฒเธขเน€เธ”เธทเธญเธ (Total Trend)
-- ----------------------------------------------------------------------------
SELECT
    DATE_TRUNC('month', ap.transdate)::date     AS "เน€เธ”เธทเธญเธ",
    TO_CHAR(ap.transdate, 'YYYY-MM')            AS "เน€เธ”เธทเธญเธ (Label)",
    COUNT(DISTINCT ap.id)                       AS "เธเธณเธเธงเธเธเธดเธฅ",
    COUNT(*)                                    AS "เธเธณเธเธงเธเธฃเธฒเธขเธเธฒเธฃ",
    SUM(ABS(at.amount))                         AS "เธขเธญเธ”เธฃเธงเธก",
    ROUND(AVG(ABS(at.amount)), 2)               AS "เน€เธเธฅเธตเนเธขเธ•เนเธญเธฃเธฒเธขเธเธฒเธฃ"
FROM ap
JOIN acc_trans at ON at.trans_id = ap.id
JOIN chart c      ON c.id = at.chart_id
WHERE c.category = 'E'
  AND ap.transdate >= CURRENT_DATE - INTERVAL '12 months'
GROUP BY DATE_TRUNC('month', ap.transdate), TO_CHAR(ap.transdate, 'YYYY-MM')
ORDER BY "เน€เธ”เธทเธญเธ";


-- ----------------------------------------------------------------------------
-- Query 5: เน€เธเธฃเธตเธขเธเน€เธ—เธตเธขเธเน€เธ”เธทเธญเธเธเธตเนเธเธฑเธเน€เธ”เธทเธญเธเธ—เธตเนเนเธฅเนเธง (MoM Comparison)
-- ----------------------------------------------------------------------------
WITH expense_base AS (
    SELECT
        COALESCE(
            (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            NULLIF(TRIM(ap.f1), ''),
            'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ'
        ) AS department_name,
        DATE_TRUNC('month', ap.transdate)::date AS month_date,
        ABS(at.amount) AS expense_amount
    FROM ap
    JOIN acc_trans at ON at.trans_id = ap.id
    JOIN chart c ON c.id = at.chart_id
    WHERE c.category = 'E'
      AND ap.transdate >= CURRENT_DATE - INTERVAL '3 months'
),
monthly AS (
    SELECT
        department_name,
        month_date,
        SUM(expense_amount) AS total
    FROM expense_base
    GROUP BY department_name, month_date
),
pivoted AS (
    SELECT
        department_name,
        SUM(CASE WHEN month_date = DATE_TRUNC('month', CURRENT_DATE)::date
                 THEN total ELSE 0 END) AS this_month,
        SUM(CASE WHEN month_date = DATE_TRUNC('month', CURRENT_DATE - INTERVAL '1 month')::date
                 THEN total ELSE 0 END) AS last_month,
        SUM(CASE WHEN month_date = DATE_TRUNC('month', CURRENT_DATE - INTERVAL '2 months')::date
                 THEN total ELSE 0 END) AS two_months_ago
    FROM monthly
    GROUP BY department_name
)
SELECT
    department_name                                          AS "เนเธเธเธ",
    two_months_ago                                           AS "2 เน€เธ”เธทเธญเธเธเนเธญเธ",
    last_month                                               AS "เน€เธ”เธทเธญเธเธ—เธตเนเนเธฅเนเธง",
    this_month                                               AS "เน€เธ”เธทเธญเธเธเธตเน",
    (this_month - last_month)                                AS "เน€เธเธฅเธตเนเธขเธเนเธเธฅเธ (เธเธฒเธ—)",
    CASE WHEN last_month = 0 THEN NULL
         ELSE ROUND(((this_month - last_month) / last_month * 100)::numeric, 1)
    END                                                      AS "เน€เธเธฅเธตเนเธขเธเนเธเธฅเธ (%)"
FROM pivoted
WHERE (this_month + last_month + two_months_ago) > 0
ORDER BY this_month DESC;


-- ----------------------------------------------------------------------------
-- Query 6: Top 10 เธเนเธฒเนเธเนเธเนเธฒเธขเนเธ•เนเธฅเธฐเนเธเธเธ (เธชเธณเธซเธฃเธฑเธ drill down)
-- ----------------------------------------------------------------------------
WITH ranked AS (
    SELECT
        COALESCE(
            (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
            NULLIF(TRIM(ap.f1), ''),
            'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ'
        ) AS department_name,
        ap.transdate,
        ap.invnumber,
        ap.notes,
        c.description AS account_name,
        ABS(at.amount) AS expense_amount,
        ROW_NUMBER() OVER (
            PARTITION BY
                COALESCE(
                    (regexp_match(ap.f1, '^([A-Z]{2,4}\d{0,2})\s*-\s*(.+)$'))[2],
                    NULLIF(TRIM(ap.f1), ''),
                    'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ'
                )
            ORDER BY ABS(at.amount) DESC
        ) AS rank_in_dept
    FROM ap
    JOIN acc_trans at ON at.trans_id = ap.id
    JOIN chart c ON c.id = at.chart_id
    WHERE c.category = 'E'
      AND ap.transdate BETWEEN '2026-04-01' AND '2026-04-30'
)
SELECT
    department_name                  AS "เนเธเธเธ",
    rank_in_dept                     AS "เธญเธฑเธเธ”เธฑเธ",
    transdate                        AS "เธงเธฑเธเธ—เธตเน",
    invnumber                        AS "เนเธเธเธณเธเธฑเธ",
    account_name                     AS "เธเธฑเธเธเธต",
    LEFT(notes, 50)                  AS "เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”",
    expense_amount                   AS "เธเธณเธเธงเธเน€เธเธดเธ"
FROM ranked
WHERE rank_in_dept <= 10
ORDER BY department_name, rank_in_dept;
