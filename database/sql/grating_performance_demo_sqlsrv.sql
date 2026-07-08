SET XACT_ABORT ON;
BEGIN TRANSACTION;

DELETE gee
FROM dbo.grating_entry_employees AS gee
JOIN dbo.grating_daily_entries AS e ON e.id = gee.entry_id
WHERE e.notes LIKE 'GP-DEMO%';

DELETE ges
FROM dbo.grating_entry_steps AS ges
JOIN dbo.grating_daily_entries AS e ON e.id = ges.entry_id
WHERE e.notes LIKE 'GP-DEMO%';

DELETE FROM dbo.grating_daily_entries
WHERE notes LIKE 'GP-DEMO%';

MERGE dbo.grating_employees AS target
USING (VALUES
    ('GP-DEMO-A', N'สมชาย ใจดี', N'ชาย', N'ตัด-ขัด เกรตติ้ง / ขัด Hair line, ออกหน้างาน', 1),
    ('GP-DEMO-B', N'วิชัย งานไว', N'ชัย', N'spot แผ่นเกรตติ้ง', 1),
    ('GP-DEMO-C', N'มาลี ล้างงาน', N'ลี', N'ล้างรอยเชื่อม, แช่ passivate', 1),
    ('GP-DEMO-D', N'อนันต์ ส่งงาน', N'นัน', N'แพ็ค, ออกหน้างาน', 1)
) AS source (employee_code, name, nickname, responsible_work, active)
ON target.employee_code = source.employee_code
WHEN MATCHED THEN
    UPDATE SET
        name = source.name,
        nickname = source.nickname,
        responsible_work = source.responsible_work,
        active = source.active,
        updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (employee_code, name, nickname, responsible_work, active, created_at, updated_at)
    VALUES (source.employee_code, source.name, source.nickname, source.responsible_work, source.active, SYSUTCDATETIME(), SYSUTCDATETIME());

MERGE dbo.grating_steps AS target
USING (VALUES
    ('WELD_ASSEMBLY', N'เชื่อมประกอบ', 0, NULL, 10, 1),
    ('CLEAN_WELD', N'ล้างรอยเชื่อม', 0, NULL, 20, 1),
    ('PASSIVATE', N'แช่ passivate', 0, NULL, 30, 1),
    ('EPQ', N'EPQ', 0, NULL, 40, 1),
    ('SPOT_GRATING', N'spot แผ่นเกรตติ้ง', 0, NULL, 50, 1),
    ('CUT_GRIND_GRATING', N'ตัด-ขัด เกรตติ้ง / ขัด Hair line', 0, NULL, 60, 1),
    ('CUT_GRIND_FLATBAR', N'ตัด-ขัด แฟลตบาร์', 0, NULL, 70, 1),
    ('LASER', N'เลเซอร์', 0, NULL, 80, 1),
    ('PACK', N'แพ็ค', 0, NULL, 90, 1),
    ('FIELD', N'ออกหน้างาน', 1, NULL, 100, 1)
) AS source (step_code, step_name, is_field_work, target_kg_per_hour, sort_order, active)
ON target.step_code = source.step_code
WHEN MATCHED THEN
    UPDATE SET
        step_name = source.step_name,
        is_field_work = source.is_field_work,
        target_kg_per_hour = source.target_kg_per_hour,
        sort_order = source.sort_order,
        active = source.active,
        updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (step_code, step_name, is_field_work, target_kg_per_hour, sort_order, active, created_at, updated_at)
    VALUES (source.step_code, source.step_name, source.is_field_work, source.target_kg_per_hour, source.sort_order, source.active, SYSUTCDATETIME(), SYSUTCDATETIME());

IF OBJECT_ID('dbo.grating_field_activities', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.grating_field_activities (
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        activity_code nvarchar(40) NOT NULL,
        activity_name nvarchar(160) NOT NULL,
        sort_order smallint NOT NULL DEFAULT (100),
        active bit NOT NULL DEFAULT (1),
        created_at datetime2 NULL,
        updated_at datetime2 NULL,
        CONSTRAINT UQ_grating_field_activities_code UNIQUE (activity_code)
    );
END;

MERGE dbo.grating_field_activities AS target
USING (VALUES
    ('SURVEY', N'ดูหน้างาน Survey', 10, 1),
    ('MEASURE', N'วัดงาน', 20, 1),
    ('INSTALL', N'ติดตั้งงาน รวมถึงการแก้ไขงาน', 30, 1),
    ('DELIVER', N'ส่งงาน', 40, 1),
    ('OTHER', N'อื่นๆ', 50, 1)
) AS source (activity_code, activity_name, sort_order, active)
ON target.activity_code = source.activity_code
WHEN MATCHED THEN
    UPDATE SET
        activity_name = source.activity_name,
        sort_order = source.sort_order,
        active = source.active,
        updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (activity_code, activity_name, sort_order, active, created_at, updated_at)
    VALUES (source.activity_code, source.activity_name, source.sort_order, source.active, SYSUTCDATETIME(), SYSUTCDATETIME());

DECLARE @today date = CONVERT(date, SYSDATETIME());
DECLARE @yesterday date = DATEADD(day, -1, @today);

DECLARE @entries table (
    row_no int identity(1,1),
    work_date date,
    mfg_no varchar(80),
    step_code varchar(40),
    start_time time,
    finish_time time,
    good_qty_kg decimal(14,3),
    bad_qty_kg decimal(14,3),
    is_finished bit,
    is_field_work bit,
    field_activity nvarchar(120),
    field_details nvarchar(max),
    notes nvarchar(max)
);

INSERT INTO @entries (work_date, mfg_no, step_code, start_time, finish_time, good_qty_kg, bad_qty_kg, is_finished, is_field_work, field_activity, field_details, notes)
VALUES
    (@today, 'GP-DEMO-MFG-001', 'CUT_GRIND_GRATING', '08:00', '10:00', 120, 4, 0, 0, NULL, NULL, N'GP-DEMO งานเดี่ยว: สมชายขัด MFG-001'),
    (@today, 'GP-DEMO-MFG-002', 'SPOT_GRATING', '10:15', '12:15', 100, 2, 1, 0, NULL, NULL, N'GP-DEMO งานร่วม A+B ยอด 100kg ต้องนับครั้งเดียว'),
    (@today, 'GP-DEMO-MFG-003', 'CLEAN_WELD', '08:30', '09:15', 300, 0, 1, 0, NULL, NULL, N'GP-DEMO ล้างรอยเชื่อม'),
    (@yesterday, 'GP-DEMO-MFG-004', 'FIELD', '13:00', '15:30', 150, 6, 1, 1, N'ดูหน้างาน Survey', N'สำรวจพื้นที่ติดตั้งและถ่ายรูปจุดแก้ไขหน้างาน', N'GP-DEMO งานออกหน้างาน');

DECLARE @inserted table (row_no int, entry_id bigint);

MERGE dbo.grating_daily_entries AS target
USING (
    SELECT
        source.row_no,
        source.work_date,
        source.mfg_no,
        step.id AS step_id,
        DATEADD(minute, DATEDIFF(minute, CAST('00:00' AS time), source.start_time), CAST(source.work_date AS datetime2)) AS started_at,
        DATEADD(minute, DATEDIFF(minute, CAST('00:00' AS time), source.finish_time), CAST(source.work_date AS datetime2)) AS finished_at,
        DATEDIFF(minute, source.start_time, source.finish_time) AS duration_minutes,
        source.good_qty_kg,
        source.bad_qty_kg,
        source.is_finished,
        source.is_field_work,
        source.field_activity,
        source.field_details,
        source.notes
    FROM @entries AS source
    JOIN dbo.grating_steps AS step ON step.step_code = source.step_code
) AS source
ON 1 = 0
WHEN NOT MATCHED THEN
    INSERT (
        work_date,
        mfg_no,
        step_id,
        started_at,
        finished_at,
        duration_minutes,
        good_qty_kg,
        bad_qty_kg,
        is_finished,
        is_field_work,
        field_activity,
        field_details,
        notes,
        created_at,
        updated_at
    )
    VALUES (
        source.work_date,
        source.mfg_no,
        source.step_id,
        source.started_at,
        source.finished_at,
        source.duration_minutes,
        source.good_qty_kg,
        source.bad_qty_kg,
        source.is_finished,
        source.is_field_work,
        source.field_activity,
        source.field_details,
        source.notes,
        SYSUTCDATETIME(),
        SYSUTCDATETIME()
    )
OUTPUT source.row_no, inserted.id INTO @inserted (row_no, entry_id);

INSERT INTO dbo.grating_entry_employees (entry_id, employee_id, created_at, updated_at)
SELECT inserted.entry_id, employee.id, SYSUTCDATETIME(), SYSUTCDATETIME()
FROM @inserted AS inserted
JOIN (VALUES
    (1, 'GP-DEMO-A'),
    (2, 'GP-DEMO-A'),
    (2, 'GP-DEMO-B'),
    (3, 'GP-DEMO-C'),
    (4, 'GP-DEMO-A'),
    (4, 'GP-DEMO-D')
) AS map (row_no, employee_code) ON map.row_no = inserted.row_no
JOIN dbo.grating_employees AS employee ON employee.employee_code = map.employee_code;

INSERT INTO dbo.grating_entry_steps (entry_id, step_id, created_at, updated_at)
SELECT inserted.entry_id, step.id, SYSUTCDATETIME(), SYSUTCDATETIME()
FROM @inserted AS inserted
JOIN @entries AS entry_source ON entry_source.row_no = inserted.row_no
JOIN dbo.grating_steps AS step ON step.step_code = entry_source.step_code;

SELECT
    (SELECT COUNT(*) FROM dbo.grating_employees WHERE employee_code LIKE 'GP-DEMO-%') AS employees,
    (SELECT COUNT(*) FROM dbo.grating_steps WHERE step_code IN ('WELD_ASSEMBLY', 'CLEAN_WELD', 'PASSIVATE', 'EPQ', 'SPOT_GRATING', 'CUT_GRIND_GRATING', 'CUT_GRIND_FLATBAR', 'LASER', 'PACK', 'FIELD')) AS steps,
    (SELECT COUNT(*) FROM dbo.grating_daily_entries WHERE notes LIKE 'GP-DEMO%') AS demo_entries,
    (SELECT SUM(good_qty_kg) FROM dbo.grating_daily_entries WHERE notes LIKE 'GP-DEMO%') AS demo_good_kg,
    (SELECT SUM(good_qty_kg) FROM dbo.grating_daily_entries WHERE mfg_no = 'GP-DEMO-MFG-002') AS team_mfg_002_good_kg,
    (
        SELECT COUNT(*)
        FROM dbo.grating_entry_employees AS gee
        JOIN dbo.grating_daily_entries AS e ON e.id = gee.entry_id
        WHERE e.mfg_no = 'GP-DEMO-MFG-002'
    ) AS team_mfg_002_people;

COMMIT TRANSACTION;
