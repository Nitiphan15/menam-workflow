SET XACT_ABORT ON;
BEGIN TRANSACTION;

DECLARE @StartDate date = '2026-06-01';
DECLARE @EndDate date = EOMONTH(@StartDate);
DECLARE @Prefix nvarchar(50) = N'GP-JUN2026-FLOW';
DECLARE @DeletePrefix nvarchar(50) = N'GP-JUN2026';
DECLARE @DeleteMfgPrefix nvarchar(50) = N'FG-JUN-202606';

IF OBJECT_ID('dbo.grating_daily_entries', 'U') IS NULL
    THROW 50001, 'Missing table dbo.grating_daily_entries', 1;
IF OBJECT_ID('dbo.grating_employees', 'U') IS NULL
    THROW 50002, 'Missing table dbo.grating_employees', 1;
IF OBJECT_ID('dbo.grating_steps', 'U') IS NULL
    THROW 50003, 'Missing table dbo.grating_steps', 1;
IF OBJECT_ID('dbo.grating_entry_employees', 'U') IS NULL
    THROW 50004, 'Missing table dbo.grating_entry_employees', 1;
IF OBJECT_ID('dbo.grating_entry_steps', 'U') IS NULL
    THROW 50005, 'Missing table dbo.grating_entry_steps', 1;

IF COL_LENGTH('dbo.grating_daily_entries', 'is_field_work') IS NULL
    ALTER TABLE dbo.grating_daily_entries ADD is_field_work bit NOT NULL CONSTRAINT DF_grating_daily_entries_is_field_work DEFAULT (0);
IF COL_LENGTH('dbo.grating_daily_entries', 'field_activity') IS NULL
    ALTER TABLE dbo.grating_daily_entries ADD field_activity nvarchar(120) NULL;
IF COL_LENGTH('dbo.grating_daily_entries', 'field_details') IS NULL
    ALTER TABLE dbo.grating_daily_entries ADD field_details nvarchar(max) NULL;

DELETE ges
FROM dbo.grating_entry_steps ges
JOIN dbo.grating_daily_entries e ON e.id = ges.entry_id
WHERE e.notes LIKE @DeletePrefix + N'%'
   OR e.mfg_no LIKE @DeleteMfgPrefix + N'%';

DELETE gee
FROM dbo.grating_entry_employees gee
JOIN dbo.grating_daily_entries e ON e.id = gee.entry_id
WHERE e.notes LIKE @DeletePrefix + N'%'
   OR e.mfg_no LIKE @DeleteMfgPrefix + N'%';

DELETE FROM dbo.grating_daily_entries
WHERE notes LIKE @DeletePrefix + N'%'
   OR mfg_no LIKE @DeleteMfgPrefix + N'%';

DECLARE @employees table (
    slot int NOT NULL PRIMARY KEY,
    employee_id bigint NOT NULL
);

INSERT INTO @employees (slot, employee_id)
SELECT ROW_NUMBER() OVER (ORDER BY id), id
FROM dbo.grating_employees
WHERE active = 1;

DECLARE @EmployeeCount int = (SELECT COUNT(*) FROM @employees);
IF @EmployeeCount < 4
    THROW 50006, 'Need at least 4 active employees in dbo.grating_employees', 1;

DECLARE @flow table (
    seq int NOT NULL PRIMARY KEY,
    step_code nvarchar(60) NOT NULL,
    start_min int NOT NULL,
    duration_min int NOT NULL
);

INSERT INTO @flow (seq, step_code, start_min, duration_min)
VALUES
    (1, 'WELD_ASSEMBLY', 480, 45),
    (2, 'CLEAN_WELD', 530, 35),
    (3, 'PASSIVATE', 570, 40),
    (4, 'EPQ', 615, 30),
    (5, 'SPOT_GRATING', 650, 55),
    (6, 'CUT_GRIND_GRATING', 710, 75),
    (7, 'CUT_GRIND_FLATBAR', 790, 55),
    (8, 'LASER', 850, 45),
    (9, 'PACK', 900, 50);

IF EXISTS (
    SELECT 1
    FROM @flow f
    LEFT JOIN dbo.grating_steps s ON s.step_code = f.step_code
    WHERE s.id IS NULL
)
    THROW 50007, 'Missing required grating_steps master data', 1;
IF NOT EXISTS (SELECT 1 FROM dbo.grating_steps WHERE step_code = 'FIELD')
    THROW 50008, 'Missing FIELD step in grating_steps', 1;

DECLARE @entries table (
    row_no int IDENTITY(1,1) NOT NULL PRIMARY KEY,
    work_date date NOT NULL,
    mfg_no nvarchar(80) NOT NULL,
    step_code nvarchar(60) NOT NULL,
    start_min int NOT NULL,
    duration_min int NOT NULL,
    good_qty_kg decimal(14,3) NOT NULL,
    bad_qty_kg decimal(14,3) NOT NULL,
    is_finished bit NOT NULL,
    is_field_work bit NOT NULL,
    field_activity nvarchar(120) NULL,
    field_details nvarchar(1000) NULL,
    [notes] nvarchar(1000) NOT NULL,
    primary_slot int NOT NULL,
    second_slot int NULL
);

DECLARE @d date = @StartDate;
DECLARE @dayNo int;
DECLARE @qty decimal(14,3);

WHILE @d <= @EndDate
BEGIN
    SET @dayNo = DAY(@d);
    SET @qty = 95 + (@dayNo * 3);

    -- Completed MFG: same MFG passes every master step, finished only at PACK.
    INSERT INTO @entries (
        work_date, mfg_no, step_code, start_min, duration_min,
        good_qty_kg, bad_qty_kg, is_finished, is_field_work,
    field_activity, field_details, [notes], primary_slot, second_slot
    )
    SELECT
        @d,
        CONCAT('FG-JUN-', FORMAT(@d, 'yyyyMMdd'), '-FULL'),
        f.step_code,
        f.start_min,
        f.duration_min + CASE WHEN f.step_code = 'CUT_GRIND_GRATING' AND @dayNo % 6 = 0 THEN 55 ELSE 0 END,
        @qty,
        CASE WHEN f.seq IN (2, 6) THEN @dayNo % 3 ELSE 0 END,
        CASE WHEN f.step_code = 'PACK' THEN 1 ELSE 0 END,
        0,
        NULL,
        NULL,
        CONCAT(@Prefix, N' FULL ', FORMAT(@d, 'yyyyMMdd'), N' ', f.step_code),
        ((@dayNo + f.seq - 2) % @EmployeeCount) + 1,
        CASE WHEN f.step_code IN ('SPOT_GRATING', 'CUT_GRIND_GRATING') THEN ((@dayNo + f.seq) % @EmployeeCount) + 1 ELSE NULL END
    FROM @flow f;

    -- WIP MFG: passes only early steps, not finished yet.
    INSERT INTO @entries (
        work_date, mfg_no, step_code, start_min, duration_min,
        good_qty_kg, bad_qty_kg, is_finished, is_field_work,
    field_activity, field_details, [notes], primary_slot, second_slot
    )
    SELECT
        @d,
        CONCAT('FG-JUN-', FORMAT(@d, 'yyyyMMdd'), '-WIP'),
        f.step_code,
        f.start_min + 70,
        f.duration_min + CASE WHEN f.step_code = 'CLEAN_WELD' AND @dayNo % 4 = 0 THEN 35 ELSE 0 END,
        @qty + 40,
        CASE WHEN f.seq = 2 THEN 1 ELSE 0 END,
        0,
        0,
        NULL,
        NULL,
        CONCAT(@Prefix, N' WIP ', FORMAT(@d, 'yyyyMMdd'), N' ', f.step_code),
        ((@dayNo + 1) % @EmployeeCount) + 1,
        NULL
    FROM @flow f
    WHERE f.seq <= CASE WHEN @dayNo % 3 = 0 THEN 6 ELSE 4 END;

    -- One employee does several MFG in the same step on the same day.
    INSERT INTO @entries (
        work_date, mfg_no, step_code, start_min, duration_min,
        good_qty_kg, bad_qty_kg, is_finished, is_field_work,
    field_activity, field_details, [notes], primary_slot, second_slot
    )
    VALUES
        (@d, CONCAT('FG-JUN-', FORMAT(@d, 'yyyyMMdd'), '-LASER-A'), 'LASER', 960, 35, 45 + @dayNo, 0, 0, 0, NULL, NULL, CONCAT(@Prefix, N' MULTI LASER A ', FORMAT(@d, 'yyyyMMdd')), ((@dayNo + 2) % @EmployeeCount) + 1, NULL),
        (@d, CONCAT('FG-JUN-', FORMAT(@d, 'yyyyMMdd'), '-LASER-B'), 'LASER', 1000, 40, 55 + @dayNo, 0, 0, 0, NULL, NULL, CONCAT(@Prefix, N' MULTI LASER B ', FORMAT(@d, 'yyyyMMdd')), ((@dayNo + 2) % @EmployeeCount) + 1, NULL);

    -- Field work has no real MFG; backend/report keeps FIELD as a marker only.
    INSERT INTO @entries (
        work_date, mfg_no, step_code, start_min, duration_min,
        good_qty_kg, bad_qty_kg, is_finished, is_field_work,
    field_activity, field_details, [notes], primary_slot, second_slot
    )
    VALUES
        (@d, 'FIELD', 'FIELD', 570, 150, 0, 0, 1, 1,
            CASE WHEN @dayNo % 5 = 1 THEN N'ดูหน้างาน Survey'
                 WHEN @dayNo % 5 = 2 THEN N'วัดงาน'
                 WHEN @dayNo % 5 = 3 THEN N'ติดตั้งงาน รวมถึงการแก้ไขงาน'
                 WHEN @dayNo % 5 = 4 THEN N'ส่งงาน'
                 ELSE N'อื่นๆ' END,
            N'ออกหน้างานคนเดียว: สำรวจ วัดหน้างาน หรือเก็บรายละเอียดงาน',
            CONCAT(@Prefix, N' FIELD SINGLE ', FORMAT(@d, 'yyyyMMdd')),
            ((@dayNo + 3) % @EmployeeCount) + 1,
            NULL),
        (@d, 'FIELD', 'FIELD', 790, 210, 0, 0, 1, 1,
            CASE WHEN @dayNo % 3 = 1 THEN N'ติดตั้งงาน รวมถึงการแก้ไขงาน'
                 WHEN @dayNo % 3 = 2 THEN N'ส่งงาน'
                 ELSE N'อื่นๆ' END,
            N'ออกหน้างานหลายคน: ติดตั้ง แก้ไข หรือส่งงานร่วมกัน',
            CONCAT(@Prefix, N' FIELD TEAM ', FORMAT(@d, 'yyyyMMdd')),
            ((@dayNo + 1) % @EmployeeCount) + 1,
            ((@dayNo + 2) % @EmployeeCount) + 1);

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
    DATEADD(MINUTE, e.start_min, CAST(e.work_date AS datetime2)),
    DATEADD(MINUTE, e.start_min + e.duration_min, CAST(e.work_date AS datetime2)),
    e.duration_min,
    e.good_qty_kg,
    e.bad_qty_kg,
    e.is_finished,
    e.is_field_work,
    e.field_activity,
    e.field_details,
    e.[notes],
    SYSDATETIME(),
    SYSDATETIME()
FROM @entries e
JOIN dbo.grating_steps s ON s.step_code = e.step_code;

DECLARE @inserted table (
    row_no int NOT NULL PRIMARY KEY,
    entry_id bigint NOT NULL
);

INSERT INTO @inserted (row_no, entry_id)
SELECT source.row_no, target.id
FROM @entries source
JOIN dbo.grating_daily_entries target ON target.notes = source.[notes]
WHERE target.notes LIKE @Prefix + N'%';

INSERT INTO dbo.grating_entry_steps (entry_id, step_id, created_at, updated_at)
SELECT i.entry_id, s.id, SYSDATETIME(), SYSDATETIME()
FROM @inserted i
JOIN @entries e ON e.row_no = i.row_no
JOIN dbo.grating_steps s ON s.step_code = e.step_code
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.grating_entry_steps x
    WHERE x.entry_id = i.entry_id
      AND x.step_id = s.id
);

INSERT INTO dbo.grating_entry_employees (entry_id, employee_id, created_at, updated_at)
SELECT i.entry_id, emp.employee_id, SYSDATETIME(), SYSDATETIME()
FROM @inserted i
JOIN @entries e ON e.row_no = i.row_no
JOIN @employees emp ON emp.slot = e.primary_slot
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.grating_entry_employees x
    WHERE x.entry_id = i.entry_id
      AND x.employee_id = emp.employee_id
);

INSERT INTO dbo.grating_entry_employees (entry_id, employee_id, created_at, updated_at)
SELECT i.entry_id, emp.employee_id, SYSDATETIME(), SYSDATETIME()
FROM @inserted i
JOIN @entries e ON e.row_no = i.row_no
JOIN @employees emp ON emp.slot = e.second_slot
WHERE e.second_slot IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM dbo.grating_entry_employees x
    WHERE x.entry_id = i.entry_id
      AND x.employee_id = emp.employee_id
);

SELECT
    COUNT(*) AS inserted_entries,
    COUNT(DISTINCT NULLIF(mfg_no, 'FIELD')) AS mfg_count,
    SUM(CASE WHEN is_finished = 1 AND mfg_no <> 'FIELD' THEN good_qty_kg ELSE 0 END) AS finished_output_kg,
    SUM(good_qty_kg) AS step_good_kg,
    SUM(CASE WHEN is_field_work = 1 THEN 1 ELSE 0 END) AS field_entries,
    MIN(work_date) AS date_from,
    MAX(work_date) AS date_to
FROM dbo.grating_daily_entries
WHERE notes LIKE @Prefix + N'%';

COMMIT TRANSACTION;
