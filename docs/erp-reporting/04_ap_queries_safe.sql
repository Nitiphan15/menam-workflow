-- ============================================================================
-- AP Expense Queries โ€” Privacy-Safe Version
-- ============================================================================
-- ๐”’ PRIVACY POLICY:
--   โ เธซเนเธฒเธกเนเธชเธ”เธ: vendor.name, customer.name
--   โ… เนเธเนเนเธ”เน:    vendor_id, customer_id (เน€เธเนเธเธ•เธฑเธงเน€เธฅเธ reference)
--   โ… เนเธเนเนเธ”เน:    notes, invnumber, ordnumber (เธญเธฒเธเธกเธตเธเธทเนเธญเนเธ text เนเธ•เนเธญเธเธธเธเธฒเธ•)
--   โ… เนเธเนเนเธ”เน:    parts.description, employee.name, classinfo.description
-- ============================================================================


-- ----------------------------------------------------------------------------
-- V1: AP Expense Report เธเธทเนเธเธเธฒเธ (เน€เธ—เธตเธขเธเน€เธ—เนเธฒ rp-ap_expense.php)
-- ----------------------------------------------------------------------------
SELECT
    ap.invnumber                            AS "เนเธเธเธณเธเธฑเธเน€เธฅเธเธ—เธตเน",
    ap.transdate                            AS "เธงเธฑเธเธ—เธตเน",
    ap.amount                               AS "เธขเธญเธ”เธฃเธงเธก",
    ap.notes                                AS "เธซเธกเธฒเธขเน€เธซเธ•เธธ",
    c.accno                                 AS "เธฃเธซเธฑเธชเธเธฑเธเธเธต",
    c.description                           AS "เธเธทเนเธญเธเธฑเธเธเธต",
    ABS(at.amount)                          AS "เธเธณเธเธงเธเน€เธเธดเธ"
FROM ap
JOIN acc_trans at ON at.trans_id = ap.id
JOIN chart c      ON c.id = at.chart_id
WHERE ap.transdate BETWEEN '2026-04-01' AND '2026-04-30'
  AND c.category = 'E'
ORDER BY ap.transdate, ap.invnumber, c.accno;


-- ----------------------------------------------------------------------------
-- V2: AP Expense + เธฃเธฒเธขเธเธฒเธฃเธชเธดเธเธเนเธฒ + เธเธนเนเธเธฑเธเธ—เธถเธ (เนเธกเนเธกเธตเธเธทเนเธญ vendor)
-- ----------------------------------------------------------------------------
SELECT
    ap.transdate                                                  AS "เธงเธฑเธเธ—เธตเน",
    ap.invnumber                                                  AS "เนเธเธเธณเธเธฑเธเน€เธฅเธเธ—เธตเน",
    ap.ordnumber                                                  AS "เนเธเธชเธฑเนเธเธเธทเนเธญเน€เธฅเธเธ—เธตเน",
    (c.accno || ' ' || c.description)                             AS "เธเธฑเธเธเธต",
    COALESCE(p.description, '')                                   AS "เธเธทเนเธญเธชเธดเธเธเนเธฒ",
    i.qty                                                         AS "เธเธณเธเธงเธ",
    i.sellprice                                                   AS "เธฃเธฒเธเธฒ/เธ•เนเธญเธซเธเนเธงเธข",
    ABS(at.amount)                                                AS "เธฃเธฒเธเธฒเธฃเธงเธก",
    (ap.amount - ap.paid)                                         AS "เธเธเน€เธซเธฅเธทเธญ",
    ap.notes                                                      AS "เธซเธกเธฒเธขเน€เธซเธ•เธธ",
    ap.f1 AS "เธเนเธญเธกเธนเธฅเน€เธเธดเนเธกเน€เธ•เธดเธก 1",  ap.f2 AS "เธเนเธญเธกเธนเธฅเน€เธเธดเนเธกเน€เธ•เธดเธก 2",
    ap.f3 AS "เธเนเธญเธกเธนเธฅเน€เธเธดเนเธกเน€เธ•เธดเธก 3",  ap.f4 AS "เธเนเธญเธกเธนเธฅเน€เธเธดเนเธกเน€เธ•เธดเธก 4",
    ap.f5 AS "เธเนเธญเธกเธนเธฅเน€เธเธดเนเธกเน€เธ•เธดเธก 5",
    cls.description                                               AS "เนเธเธเธ",
    e_emp.name                                                    AS "เธเธนเนเธเธฑเธเธ—เธถเธ",
    e_req.name                                                    AS "เธเธนเนเธเธญเน€เธเธดเธ",
    ap.curr                                                       AS "เธชเธเธธเธฅเน€เธเธดเธ",
    ap.vendor_id                                                  AS "vendor_id"
FROM ap
    JOIN acc_trans at         ON at.trans_id = ap.id
    JOIN chart c              ON c.id        = at.chart_id
    LEFT JOIN classinfo cls   ON cls.id      = at.class_id
    LEFT JOIN invoice i       ON i.trans_id  = ap.id AND i.parts_id IS NOT NULL
    LEFT JOIN parts p         ON p.id        = i.parts_id
    LEFT JOIN employee e_emp  ON e_emp.id    = ap.employee_id
    LEFT JOIN employee e_req  ON e_req.id    = ap.requester_id
WHERE ap.transdate BETWEEN '2026-04-01' AND '2026-04-30'
  AND c.category = 'E'
ORDER BY cls.description, ap.transdate, ap.invnumber, c.accno;


-- ----------------------------------------------------------------------------
-- V3: เธชเธฃเธธเธเธขเธญเธ”เธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเธ•เธฒเธกเนเธเธเธ (เนเธเน classinfo)
-- ----------------------------------------------------------------------------
SELECT
    COALESCE(cls.description, 'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ')        AS "เนเธเธเธ",
    COUNT(DISTINCT ap.id)                            AS "เธเธณเธเธงเธเธเธดเธฅ",
    COUNT(*)                                         AS "เธเธณเธเธงเธเธฃเธฒเธขเธเธฒเธฃ",
    SUM(ABS(at.amount))                              AS "เธขเธญเธ”เธฃเธงเธก",
    ROUND(AVG(ABS(at.amount)), 2)                    AS "เน€เธเธฅเธตเนเธข"
FROM ap
JOIN acc_trans at      ON at.trans_id = ap.id
JOIN chart c           ON c.id = at.chart_id
LEFT JOIN classinfo cls ON cls.id = at.class_id
WHERE ap.transdate BETWEEN '2026-04-01' AND '2026-04-30'
  AND c.category = 'E'
GROUP BY cls.description
ORDER BY SUM(ABS(at.amount)) DESC;


-- ----------------------------------------------------------------------------
-- V4: เน€เธเธฃเธตเธขเธเน€เธ—เธตเธขเธเธฃเธฒเธขเน€เธ”เธทเธญเธ (Pivot)
-- ----------------------------------------------------------------------------
SELECT
    COALESCE(cls.description, 'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ')                            AS "เนเธเธเธ",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=1  THEN ABS(at.amount) ELSE 0 END) AS "เธก.เธ.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=2  THEN ABS(at.amount) ELSE 0 END) AS "เธ.เธ.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=3  THEN ABS(at.amount) ELSE 0 END) AS "เธกเธต.เธ.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=4  THEN ABS(at.amount) ELSE 0 END) AS "เน€เธก.เธข.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=5  THEN ABS(at.amount) ELSE 0 END) AS "เธ.เธ.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=6  THEN ABS(at.amount) ELSE 0 END) AS "เธกเธด.เธข.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=7  THEN ABS(at.amount) ELSE 0 END) AS "เธ.เธ.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=8  THEN ABS(at.amount) ELSE 0 END) AS "เธช.เธ.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=9  THEN ABS(at.amount) ELSE 0 END) AS "เธ.เธข.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=10 THEN ABS(at.amount) ELSE 0 END) AS "เธ•.เธ.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=11 THEN ABS(at.amount) ELSE 0 END) AS "เธ.เธข.",
    SUM(CASE WHEN EXTRACT(MONTH FROM ap.transdate)=12 THEN ABS(at.amount) ELSE 0 END) AS "เธ.เธ.",
    SUM(ABS(at.amount))                                                  AS "เธฃเธงเธกเธ—เธฑเนเธเธเธต"
FROM ap
JOIN acc_trans at      ON at.trans_id = ap.id
JOIN chart c           ON c.id = at.chart_id
LEFT JOIN classinfo cls ON cls.id = at.class_id
WHERE ap.transdate BETWEEN '2026-01-01' AND '2026-12-31'
  AND c.category = 'E'
GROUP BY cls.description
ORDER BY SUM(ABS(at.amount)) DESC;


-- ----------------------------------------------------------------------------
-- V5: Trend เธฃเธฒเธขเน€เธ”เธทเธญเธ (เธฃเธงเธกเธ—เธธเธเนเธเธเธ)
-- ----------------------------------------------------------------------------
SELECT
    DATE_TRUNC('month', ap.transdate)::date     AS "เน€เธ”เธทเธญเธ",
    COUNT(DISTINCT ap.id)                       AS "เธเธณเธเธงเธเธเธดเธฅ",
    SUM(ABS(at.amount))                         AS "เธขเธญเธ”เธฃเธงเธก"
FROM ap
JOIN acc_trans at ON at.trans_id = ap.id
JOIN chart c      ON c.id = at.chart_id
WHERE c.category = 'E'
  AND ap.transdate >= CURRENT_DATE - INTERVAL '12 months'
GROUP BY DATE_TRUNC('month', ap.transdate)
ORDER BY "เน€เธ”เธทเธญเธ";


-- ----------------------------------------------------------------------------
-- V6: AP Aging โ€” เนเธเน vendor_id เนเธ—เธเธเธทเนเธญ
-- ----------------------------------------------------------------------------
-- ๐”’ เนเธเน vendor_id เน€เธเนเธ reference เน€เธ—เนเธฒเธเธฑเนเธ (เนเธกเน join เน€เธเธทเนเธญเธ”เธถเธเธเธทเนเธญ)
SELECT
    ap.vendor_id                                AS "vendor_id",
    COUNT(*)                                    AS "เธเธณเธเธงเธเธเธดเธฅเธเนเธฒเธ",
    SUM(ap.amount - ap.paid)                    AS "เธขเธญเธ”เธเนเธฒเธเธฃเธงเธก",
    MIN(ap.duedate)                             AS "เธเธฃเธเธเธณเธซเธเธ”เน€เธเนเธฒเธชเธธเธ”",
    MAX(CURRENT_DATE - ap.duedate)              AS "เน€เธเธดเธเธเธณเธซเธเธ” (เธงเธฑเธ)"
FROM ap
WHERE (ap.amount - ap.paid) > 0
GROUP BY ap.vendor_id
ORDER BY SUM(ap.amount - ap.paid) DESC
LIMIT 50;


-- ============================================================================
-- โ Queries เธ—เธตเนเธซเนเธฒเธกเนเธเนเนเธฅเนเธง (เธ•เธฒเธกเธเนเธขเธเธฒเธข Privacy)
-- ============================================================================
--
-- DON'T:
--   SELECT v.name FROM vendor v ...
--   SELECT c.name FROM customer c ...
--   JOIN vendor v ON ...
--   JOIN customer c ON ...
--
-- DO INSTEAD:
--   SELECT vendor_id FROM ap ...   (เนเธเนเนเธเน id)
--   GROUP BY vendor_id              (เธชเธฃเธธเธเนเธเธเนเธกเนเธฃเธฐเธเธธเธเธทเนเธญ)
-- ============================================================================
