SET XACT_ABORT ON;
BEGIN TRANSACTION;

DECLARE @StartDate date = '2026-06-01';
DECLARE @EndDate date = EOMONTH(@StartDate);
DECLARE @Prefix nvarchar(60) = N'GP-JUN2026-MASTER';
DECLARE @DeleteMfgPrefix nvarchar(60) = N'FG-JUN-202606';

IF OBJECT_ID('dbo.grating_daily_entries', 'U') IS NULL
    THROW 51001, 'Missing table dbo.grating_daily_entries', 1;
IF OBJECT_ID('dbo.grating_employees', 'U') IS NULL
    THROW 51002, 'Missing table dbo.grating_employees', 1;
IF OBJECT_ID('dbo.grating_steps', 'U') IS NULL
    THROW 51003, 'Missing table dbo.grating_steps', 1;
IF OBJECT_ID('dbo.grating_entry_employees', 'U') IS NULL
    THROW 51004, 'Missing table dbo.grating_entry_employees', 1;
IF OBJECT_ID('dbo.grating_entry_steps', 'U') IS NULL
    THROW 51005, 'Missing table dbo.grating_entry_steps', 1;

IF COL_LENGTH('dbo.grating_daily_entries', 'is_field_work') IS NULL
BEGIN
    ALTER TABLE dbo.grating_daily_entries
    ADD is_field_work bit NOT NULL CONSTRAINT DF_grating_daily_entries_is_field_work DEFAULT (0);
END;

IF COL_LENGTH('dbo.grating_daily_entries', 'field_activity') IS NULL
BEGIN
    ALTER TABLE dbo.grating_daily_entries
    ADD field_activity nvarchar(120) NULL;
END;

IF COL_LENGTH('dbo.grating_daily_entries', 'field_details') IS NULL
BEGIN
    ALTER TABLE dbo.grating_daily_entries
    ADD field_details nvarchar(max) NULL;
END;

DELETE ges
FROM dbo.grating_entry_steps AS ges
JOIN dbo.grating_daily_entries AS e ON e.id = ges.entry_id
WHERE e.notes LIKE N'GP-JUN2026%'
   OR e.mfg_no LIKE @DeleteMfgPrefix + N'%';

DELETE gee
FROM dbo.grating_entry_employees AS gee
JOIN dbo.grating_daily_entries AS e ON e.id = gee.entry_id
WHERE e.notes LIKE N'GP-JUN2026%'
   OR e.mfg_no LIKE @DeleteMfgPrefix + N'%';

DELETE FROM dbo.grating_daily_entries
WHERE notes LIKE N'GP-JUN2026%'
   OR mfg_no LIKE @DeleteMfgPrefix + N'%';

CREATE TABLE #flow (
    seq int NOT NULL PRIMARY KEY,
    step_code nvarchar(60) NOT NULL,
    start_min int NOT NULL,
    duration_min int NOT NULL,
    day_offset int NOT NULL
);

INSERT INTO #flow (seq, step_code, start_min, duration_min, day_offset)
VALUES
    (1, N'WELD_ASSEMBLY', 480, 45, 0),
    (2, N'CLEAN_WELD', 535, 35, 0),
    (3, N'PASSIVATE', 580, 40, 0),
    (4, N'EPQ', 480, 30, 1),
    (5, N'SPOT_GRATING', 520, 60, 1),
    (6, N'CUT_GRIND_GRATING', 590, 80, 1),
    (7, N'CUT_GRIND_FLATBAR', 480, 55, 2),
    (8, N'LASER', 545, 45, 2),
    (9, N'PACK', 600, 45, 2);

IF EXISTS (
    SELECT 1
    FROM #flow AS f
    LEFT JOIN dbo.grating_steps AS s ON s.step_code = f.step_code
    WHERE s.id IS NULL
)
BEGIN
    THROW 51006, 'Missing required production step in dbo.grating_steps', 1;
END;

IF NOT EXISTS (SELECT 1 FROM dbo.grating_steps WHERE step_code = N'FIELD')
BEGIN
    THROW 51007, 'Missing FIELD step in dbo.grating_steps', 1;
END;

CREATE TABLE #step_employees (
    step_id bigint NOT NULL,
    step_code nvarchar(60) NOT NULL,
    employee_id bigint NOT NULL,
    rn int NOT NULL,
    cnt int NOT NULL
);

WITH direct_map AS (
    SELECT DISTINCT
        s.id AS step_id,
        s.step_code,
        e.id AS employee_id
    FROM dbo.grating_steps AS s
    JOIN dbo.grating_employees AS e ON e.active = 1
    WHERE s.active = 1
      AND (
            UPPER(ISNULL(e.responsible_work, N'')) LIKE N'%' + UPPER(s.step_code) + N'%'
         OR ISNULL(e.responsible_work, N'') LIKE N'%' + s.step_name + N'%'
         OR (s.step_code = N'FIELD' AND ISNULL(e.responsible_work, N'') LIKE N'%ออกหน้างาน%')
         OR (s.step_code = N'PACK' AND ISNULL(e.responsible_work, N'') LIKE N'%แพ็ค%')
         OR (s.step_code = N'LASER' AND ISNULL(e.responsible_work, N'') LIKE N'%เลเซอร์%')
         OR (s.step_code = N'SPOT_GRATING' AND ISNULL(e.responsible_work, N'') LIKE N'%spot%')
         OR (s.step_code = N'CUT_GRIND_GRATING' AND (ISNULL(e.responsible_work, N'') LIKE N'%เกรตติ้ง%' OR ISNULL(e.responsible_work, N'') LIKE N'%Hair line%'))
         OR (s.step_code = N'CUT_GRIND_FLATBAR' AND ISNULL(e.responsible_work, N'') LIKE N'%แฟลตบาร์%')
         OR (s.step_code = N'CLEAN_WELD' AND ISNULL(e.responsible_work, N'') LIKE N'%ล้าง%')
         OR (s.step_code = N'PASSIVATE' AND ISNULL(e.responsible_work, N'') LIKE N'%passivate%')
         OR (s.step_code = N'WELD_ASSEMBLY' AND ISNULL(e.responsible_work, N'') LIKE N'%เชื่อม%')
      )
),
fallback_map AS (
    SELECT
        s.id AS step_id,
        s.step_code,
        e.id AS employee_id
    FROM dbo.grating_steps AS s
    CROSS APPLY (
        SELECT TOP (2) ge.id
        FROM dbo.grating_employees AS ge
        WHERE ge.active = 1
        ORDER BY CHECKSUM(CONCAT(s.step_code, ge.id))
    ) AS e
    WHERE s.active = 1
      AND NOT EXISTS (
          SELECT 1
          FROM direct_map AS dm
          WHERE dm.step_id = s.id
      )
),
all_map AS (
    SELECT step_id, step_code, employee_id FROM direct_map
    UNION
    SELECT step_id, step_code, employee_id FROM fallback_map
),
numbered AS (
    SELECT
        step_id,
        step_code,
        employee_id,
        ROW_NUMBER() OVER (PARTITION BY step_id ORDER BY employee_id) AS rn,
        COUNT(*) OVER (PARTITION BY step_id) AS cnt
    FROM all_map
)
INSERT INTO #step_employees (step_id, step_code, employee_id, rn, cnt)
SELECT step_id, step_code, employee_id, rn, cnt
FROM numbered;

IF NOT EXISTS (SELECT 1 FROM #step_employees)
BEGIN
    THROW 51008, 'No active employee can be mapped to grating steps', 1;
END;

CREATE TABLE #entries (
    row_no int IDENTITY(1,1) NOT NULL PRIMARY KEY,
    work_date date NOT NULL,
    mfg_no nvarchar(80) NOT NULL,
    step_code nvarchar(60) NOT NULL,
    started_at datetime2 NOT NULL,
    finished_at datetime2 NOT NULL,
    duration_minutes int NOT NULL,
    good_qty_kg decimal(14,3) NOT NULL,
    bad_qty_kg decimal(14,3) NOT NULL,
    is_finished bit NOT NULL,
    is_field_work bit NOT NULL,
    field_activity nvarchar(120) NULL,
    field_details nvarchar(1000) NULL,
    entry_notes nvarchar(1000) NOT NULL,
    primary_employee_id bigint NOT NULL,
    second_employee_id bigint NULL
);

DECLARE @d date = @StartDate;
DECLARE @dayNo int;
DECLARE @qty decimal(14,3);
DECLARE @workDate date;
DECLARE @stepCode nvarchar(60);
DECLARE @stepId bigint;
DECLARE @seq int;
DECLARE @startMin int;
DECLARE @durationMin int;
DECLARE @cnt int;
DECLARE @primaryRn int;
DECLARE @secondRn int;
DECLARE @primaryEmployeeId bigint;
DECLARE @secondEmployeeId bigint;
DECLARE @mfg nvarchar(80);
DECLARE @note nvarchar(1000);

WHILE @d <= DATEADD(DAY, -2, @EndDate)
BEGIN
    SET @dayNo = DAY(@d);
    SET @qty = 110 + (@dayNo * 5);
    SET @mfg = CONCAT(N'FG-JUN-', FORMAT(@d, 'yyyyMMdd'), N'-FULL');

    IF NOT EXISTS (
        SELECT 1
        FROM #flow
        WHERE DATEDIFF(DAY, '19000107', DATEADD(DAY, day_offset, @d)) % 7 = 0
    )
    BEGIN
        DECLARE flow_cursor CURSOR LOCAL FAST_FORWARD FOR
            SELECT seq, step_code, DATEADD(DAY, day_offset, @d), start_min, duration_min
            FROM #flow
            ORDER BY seq;

        OPEN flow_cursor;
        FETCH NEXT FROM flow_cursor INTO @seq, @stepCode, @workDate, @startMin, @durationMin;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            SELECT TOP (1)
                @stepId = step_id,
                @cnt = cnt
            FROM #step_employees
            WHERE step_code = @stepCode;

            SET @primaryRn = ((@dayNo + @seq) % @cnt) + 1;
            SET @secondRn = CASE WHEN @stepCode IN (N'SPOT_GRATING', N'CUT_GRIND_GRATING') AND @cnt > 1 AND @dayNo % 3 = 0
                                 THEN ((@primaryRn % @cnt) + 1)
                                 ELSE NULL
                            END;
            SET @primaryEmployeeId = NULL;
            SET @secondEmployeeId = NULL;

            SELECT @primaryEmployeeId = employee_id
            FROM #step_employees
            WHERE step_id = @stepId AND rn = @primaryRn;

            SELECT @secondEmployeeId = employee_id
            FROM #step_employees
            WHERE step_id = @stepId AND rn = @secondRn;

            SET @note = CONCAT(@Prefix, N' FULL ', FORMAT(@d, 'yyyyMMdd'), N' ', @stepCode);

            INSERT INTO #entries (
                work_date, mfg_no, step_code, started_at, finished_at, duration_minutes,
                good_qty_kg, bad_qty_kg, is_finished, is_field_work,
                field_activity, field_details, entry_notes, primary_employee_id, second_employee_id
            )
            VALUES (
                @workDate,
                @mfg,
                @stepCode,
                DATEADD(MINUTE, @startMin, CAST(@workDate AS datetime2)),
                DATEADD(MINUTE, @startMin + @durationMin + CASE WHEN @stepCode = N'CUT_GRIND_GRATING' AND @dayNo % 5 = 0 THEN 35 ELSE 0 END, CAST(@workDate AS datetime2)),
                @durationMin + CASE WHEN @stepCode = N'CUT_GRIND_GRATING' AND @dayNo % 5 = 0 THEN 35 ELSE 0 END,
                @qty,
                CASE WHEN @stepCode IN (N'CLEAN_WELD', N'CUT_GRIND_GRATING') THEN @dayNo % 3 ELSE 0 END,
                CASE WHEN @stepCode = N'PACK' THEN 1 ELSE 0 END,
                0,
                NULL,
                NULL,
                @note,
                @primaryEmployeeId,
                @secondEmployeeId
            );

            FETCH NEXT FROM flow_cursor INTO @seq, @stepCode, @workDate, @startMin, @durationMin;
        END;

        CLOSE flow_cursor;
        DEALLOCATE flow_cursor;
    END;

    SET @d = DATEADD(DAY, 1, @d);
END;

SET @d = DATEADD(DAY, -5, @EndDate);
WHILE @d <= @EndDate
BEGIN
    SET @dayNo = DAY(@d);
    SET @qty = 150 + (@dayNo * 4);
    SET @mfg = CONCAT(N'FG-JUN-', FORMAT(@d, 'yyyyMMdd'), N'-WIP');

    IF DATEDIFF(DAY, '19000107', @d) % 7 <> 0
    BEGIN
        INSERT INTO #entries (
            work_date, mfg_no, step_code, started_at, finished_at, duration_minutes,
            good_qty_kg, bad_qty_kg, is_finished, is_field_work,
            field_activity, field_details, entry_notes, primary_employee_id, second_employee_id
        )
        SELECT
            @d,
            @mfg,
            f.step_code,
            DATEADD(MINUTE, f.start_min, CAST(@d AS datetime2)),
            DATEADD(MINUTE, f.start_min + f.duration_min, CAST(@d AS datetime2)),
            f.duration_min,
            @qty,
            CASE WHEN f.seq = 2 THEN 1 ELSE 0 END,
            0,
            0,
            NULL,
            NULL,
            CONCAT(@Prefix, N' WIP ', FORMAT(@d, 'yyyyMMdd'), N' ', f.step_code),
            primary_map.employee_id,
            NULL
        FROM #flow AS f
        CROSS APPLY (
            SELECT TOP (1) se.employee_id
            FROM #step_employees AS se
            WHERE se.step_code = f.step_code
            ORDER BY ABS(CHECKSUM(CONCAT(FORMAT(@d, 'yyyyMMdd'), f.step_code, se.employee_id)))
        ) AS primary_map
        WHERE f.seq <= CASE WHEN @dayNo % 2 = 0 THEN 5 ELSE 3 END;
    END;

    SET @d = DATEADD(DAY, 1, @d);
END;

SET @d = @StartDate;
WHILE @d <= @EndDate
BEGIN
    SET @dayNo = DAY(@d);

    IF DATEDIFF(DAY, '19000107', @d) % 7 <> 0 AND @dayNo % 2 = 0
    BEGIN
        SET @stepCode = N'LASER';
        SELECT TOP (1) @primaryEmployeeId = employee_id
        FROM #step_employees
        WHERE step_code = @stepCode
        ORDER BY ABS(CHECKSUM(CONCAT(FORMAT(@d, 'yyyyMMdd'), employee_id)));

        INSERT INTO #entries (
            work_date, mfg_no, step_code, started_at, finished_at, duration_minutes,
            good_qty_kg, bad_qty_kg, is_finished, is_field_work,
            field_activity, field_details, entry_notes, primary_employee_id, second_employee_id
        )
        VALUES
            (@d, CONCAT(N'FG-JUN-', FORMAT(@d, 'yyyyMMdd'), N'-LASER-A'), @stepCode, DATEADD(MINUTE, 850, CAST(@d AS datetime2)), DATEADD(MINUTE, 890, CAST(@d AS datetime2)), 40, 45 + @dayNo, 0, 0, 0, NULL, NULL, CONCAT(@Prefix, N' MULTI LASER A ', FORMAT(@d, 'yyyyMMdd')), @primaryEmployeeId, NULL),
            (@d, CONCAT(N'FG-JUN-', FORMAT(@d, 'yyyyMMdd'), N'-LASER-B'), @stepCode, DATEADD(MINUTE, 900, CAST(@d AS datetime2)), DATEADD(MINUTE, 945, CAST(@d AS datetime2)), 45, 55 + @dayNo, 0, 0, 0, NULL, NULL, CONCAT(@Prefix, N' MULTI LASER B ', FORMAT(@d, 'yyyyMMdd')), @primaryEmployeeId, NULL);
    END;

    IF DATEDIFF(DAY, '19000107', @d) % 7 <> 0 AND @dayNo % 3 = 0
    BEGIN
        SELECT TOP (1) @primaryEmployeeId = employee_id
        FROM #step_employees
        WHERE step_code = N'FIELD'
        ORDER BY ABS(CHECKSUM(CONCAT(FORMAT(@d, 'yyyyMMdd'), employee_id)));

        SELECT TOP (1) @secondEmployeeId = employee_id
        FROM #step_employees
        WHERE step_code = N'FIELD' AND employee_id <> @primaryEmployeeId
        ORDER BY ABS(CHECKSUM(CONCAT(FORMAT(@d, 'yyyyMMdd'), employee_id, 'S')));

        INSERT INTO #entries (
            work_date, mfg_no, step_code, started_at, finished_at, duration_minutes,
            good_qty_kg, bad_qty_kg, is_finished, is_field_work,
            field_activity, field_details, entry_notes, primary_employee_id, second_employee_id
        )
        VALUES (
            @d,
            N'FIELD',
            N'FIELD',
            DATEADD(MINUTE, 780, CAST(@d AS datetime2)),
            DATEADD(MINUTE, 960, CAST(@d AS datetime2)),
            180,
            0,
            0,
            1,
            1,
            CASE WHEN @dayNo % 9 = 0 THEN N'ติดตั้งงาน รวมถึงการแก้ไขงาน'
                 WHEN @dayNo % 6 = 0 THEN N'วัดงาน'
                 ELSE N'ดูหน้างาน Survey' END,
            N'ออกหน้างานตามจริง มีทั้งไปคนเดียวและไปช่วยกัน',
            CONCAT(@Prefix, N' FIELD ', FORMAT(@d, 'yyyyMMdd')),
            @primaryEmployeeId,
            CASE WHEN @dayNo % 6 = 0 THEN @secondEmployeeId ELSE NULL END
        );
    END;

    SET @d = DATEADD(DAY, 1, @d);
END;

INSERT INTO dbo.grating_daily_entries (
    work_date, mfg_no, step_id, started_at, finished_at, duration_minutes,
    good_qty_kg, bad_qty_kg, is_finished, is_field_work,
    field_activity, field_details, notes, created_at, updated_at
)
SELECT
    e.work_date,
    e.mfg_no,
    s.id,
    e.started_at,
    e.finished_at,
    e.duration_minutes,
    e.good_qty_kg,
    e.bad_qty_kg,
    e.is_finished,
    e.is_field_work,
    e.field_activity,
    e.field_details,
    e.entry_notes,
    SYSDATETIME(),
    SYSDATETIME()
FROM #entries AS e
JOIN dbo.grating_steps AS s ON s.step_code = e.step_code;

CREATE TABLE #inserted (
    row_no int NOT NULL PRIMARY KEY,
    entry_id bigint NOT NULL
);

INSERT INTO #inserted (row_no, entry_id)
SELECT e.row_no, target.id
FROM #entries AS e
JOIN dbo.grating_daily_entries AS target ON target.notes = e.entry_notes;

INSERT INTO dbo.grating_entry_steps (entry_id, step_id, created_at, updated_at)
SELECT i.entry_id, s.id, SYSDATETIME(), SYSDATETIME()
FROM #inserted AS i
JOIN #entries AS e ON e.row_no = i.row_no
JOIN dbo.grating_steps AS s ON s.step_code = e.step_code
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.grating_entry_steps AS x
    WHERE x.entry_id = i.entry_id
      AND x.step_id = s.id
);

INSERT INTO dbo.grating_entry_employees (entry_id, employee_id, created_at, updated_at)
SELECT i.entry_id, e.primary_employee_id, SYSDATETIME(), SYSDATETIME()
FROM #inserted AS i
JOIN #entries AS e ON e.row_no = i.row_no
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.grating_entry_employees AS x
    WHERE x.entry_id = i.entry_id
      AND x.employee_id = e.primary_employee_id
);

INSERT INTO dbo.grating_entry_employees (entry_id, employee_id, created_at, updated_at)
SELECT i.entry_id, e.second_employee_id, SYSDATETIME(), SYSDATETIME()
FROM #inserted AS i
JOIN #entries AS e ON e.row_no = i.row_no
WHERE e.second_employee_id IS NOT NULL
  AND e.second_employee_id <> e.primary_employee_id
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.grating_entry_employees AS x
      WHERE x.entry_id = i.entry_id
        AND x.employee_id = e.second_employee_id
  );

SELECT
    s.step_code,
    COUNT(DISTINCT ge.employee_id) AS mapped_employees
FROM dbo.grating_steps AS s
LEFT JOIN #step_employees AS ge ON ge.step_id = s.id
WHERE s.active = 1
GROUP BY s.step_code
ORDER BY s.step_code;

SELECT
    COUNT(*) AS inserted_entries,
    COUNT(DISTINCT NULLIF(mfg_no, 'FIELD')) AS mfg_count,
    SUM(CASE WHEN is_finished = 1 AND mfg_no <> 'FIELD' THEN good_qty_kg ELSE 0 END) AS finished_output_kg,
    SUM(CASE WHEN is_field_work = 1 THEN 1 ELSE 0 END) AS field_entries,
    SUM(CASE WHEN DATEDIFF(DAY, '19000107', work_date) % 7 = 0 THEN 1 ELSE 0 END) AS sunday_entries,
    MIN(work_date) AS date_from,
    MAX(work_date) AS date_to
FROM dbo.grating_daily_entries
WHERE notes LIKE @Prefix + N'%';

DROP TABLE #inserted;
DROP TABLE #entries;
DROP TABLE #step_employees;
DROP TABLE #flow;

COMMIT TRANSACTION;
