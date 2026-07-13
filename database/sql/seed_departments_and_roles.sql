-- ============================================================
--  INCREMENTAL: เติมแผนก + ตำแหน่ง ที่ยังขาด (อ้างอิง DB ปัจจุบัน)
--  Target : SQL Server (connection: sqlsrv / DB menam)
--  Tables : dbo.departments , dbo.department_roles
--  Safe   : Idempotent — รันซ้ำได้ ไม่ซ้ำข้อมูล
--            - แผนกใหม่ match ด้วย code
--            - ตำแหน่ง match ด้วย (department_id, code)
--  ขอบเขต : เติมเฉพาะ "แผนกใหม่ 13" + "แผนกเดิมที่ยังไม่มีตำแหน่ง"
--            ไม่แตะแผนกที่ curate ตำแหน่งไว้แล้ว (QA/ขาย/HR/จัดซื้อ/วางแผน/BD/IT/สต๊อก/จัดส่ง)
--  level_no: 4=บอร์ด, 3=ผู้จัดการ/หัวหน้าแผนก, 2=หัวหน้ากะ/ผู้ช่วยผจก./Leader, 1=พนักงาน
-- ============================================================
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRAN;

-- ------------------------------------------------------------
-- 1) แผนกใหม่ 13 แผนก  (code, name, parent_code)
--    - สายผลิต -> ใต้ Production (PD)
--    - วิศวกรรมไฟฟ้า/เครื่องกล -> top-level (NULL)
--    - บ่อต้ม/บ่อล้าง/บ่อบำบัด -> ใต้ Production (PD)
-- ------------------------------------------------------------
DECLARE @newdept TABLE (
    code        NVARCHAR(50),
    name        NVARCHAR(200),
    parent_code NVARCHAR(50) NULL
);

INSERT INTO @newdept (code, name, parent_code) VALUES
 (N'ROLL', N'รีด',                 N'PD'),
 (N'RLSQ', N'รีดเหลี่ยม',         N'PD'),
 (N'SFT1', N'เพลา1',               N'PD'),
 (N'SFT2', N'เพลา2',               N'PD'),
 (N'ANNL', N'อันนีล',              N'PD'),
 (N'CG',   N'CG',                  N'PD'),
 (N'CO2',  N'CO2',                 N'PD'),
 (N'GRAT', N'Grating',             N'PD'),
 (N'ENGE', N'วิศวกรรมไฟฟ้า',      NULL),
 (N'ENGM', N'วิศวกรรมเครื่องกล',  NULL),
 (N'BOIL', N'บ่อต้ม',              N'PD'),
 (N'WASH', N'บ่อล้าง',             N'PD'),
 (N'WWTP', N'บ่อบำบัด',            N'PD');

INSERT INTO departments (code, name, parent_id, site_code, is_active, created_at, updated_at)
SELECT nd.code, nd.name, p.id, N'Wire', 1, SYSDATETIME(), SYSDATETIME()
FROM @newdept nd
LEFT JOIN departments p ON p.code = nd.parent_code
WHERE NOT EXISTS (SELECT 1 FROM departments x WHERE x.code = nd.code);

-- ------------------------------------------------------------
-- 2) ย้าย "ความปลอดภัยและสิ่งแวดล้อม" (SE) -> ใต้ HR
-- ------------------------------------------------------------
UPDATE departments
SET parent_id  = (SELECT id FROM departments WHERE code = N'HR'),
    updated_at = SYSDATETIME()
WHERE code = N'SE'
  AND ISNULL(parent_id, 0) <> (SELECT id FROM departments WHERE code = N'HR');

-- ------------------------------------------------------------
-- 3) ตำแหน่ง  (dept_code = code ของแผนกปลายทาง)
-- ------------------------------------------------------------
DECLARE @role TABLE (
    dept_code NVARCHAR(50),
    code      NVARCHAR(50),
    name      NVARCHAR(150),
    level_no  SMALLINT
);

INSERT INTO @role (dept_code, code, name, level_no) VALUES
 -- ===== แผนกใหม่ =====
 -- รีด (ROLL)
 (N'ROLL', N'DEPTHEAD',  N'หัวหน้าแผนก',    3),
 (N'ROLL', N'SHIFTLEAD', N'หัวหน้ากะ',      2),
 (N'ROLL', N'STAFFSK1',  N'พนักงาน Skill1', 1),
 (N'ROLL', N'STAFFSK2',  N'พนักงาน Skill2', 1),
 (N'ROLL', N'STAFF',     N'พนักงาน',        1),
 -- รีดเหลี่ยม (RLSQ)
 (N'RLSQ', N'SHIFTLEAD',    N'หัวหน้ากะ',         2),
 (N'RLSQ', N'ASSTDEPTHEAD', N'ผู้ช่วยหัวหน้าแผนก', 2),
 (N'RLSQ', N'OFFICERSK3',   N'เจ้าหน้าที่Skill3',  1),
 (N'RLSQ', N'STAFFSK1',     N'พนักงาน Skill1',     1),
 (N'RLSQ', N'STAFFSK2',     N'พนักงาน Skill2',     1),
 (N'RLSQ', N'STAFFSK3',     N'พนักงาน Skill3',     1),
 (N'RLSQ', N'LATHETECH',    N'ช่างกลึง',           1),
 (N'RLSQ', N'STAFF',        N'พนักงาน',            1),
 -- เพลา1 (SFT1)
 (N'SFT1', N'DEPTHEAD',  N'หัวหน้าแผนก',    3),
 (N'SFT1', N'SHIFTLEAD', N'หัวหน้ากะ',      2),
 (N'SFT1', N'STAFFSK1',  N'พนักงาน Skill1', 1),
 (N'SFT1', N'STAFFSK2',  N'พนักงาน Skill2', 1),
 (N'SFT1', N'STAFFSK3',  N'พนักงาน Skill3', 1),
 (N'SFT1', N'STAFF',     N'พนักงาน',        1),
 -- เพลา2 (SFT2)
 (N'SFT2', N'DEPTHEAD',  N'หัวหน้าแผนก',    3),
 (N'SFT2', N'SHIFTLEAD', N'หัวหน้ากะ',      2),
 (N'SFT2', N'STAFFSK2',  N'พนักงาน Skill2', 1),
 (N'SFT2', N'STAFF',     N'พนักงาน',        1),
 -- อันนีล (ANNL)
 (N'ANNL', N'DEPTHEAD',  N'หัวหน้าแผนก',    3),
 (N'ANNL', N'SHIFTLEAD', N'หัวหน้ากะ',      2),
 (N'ANNL', N'STAFFSK1',  N'พนักงาน Skill1', 1),
 (N'ANNL', N'STAFFSK2',  N'พนักงาน Skill2', 1),
 (N'ANNL', N'STAFFSK3',  N'พนักงาน Skill3', 1),
 (N'ANNL', N'STAFF',     N'พนักงาน',        1),
 -- CG
 (N'CG', N'DEPTHEAD',  N'หัวหน้าแผนก',    3),
 (N'CG', N'SHIFTLEAD', N'หัวหน้ากะ',      2),
 (N'CG', N'STAFFSK2',  N'พนักงาน Skill2', 1),
 (N'CG', N'STAFF',     N'พนักงาน',        1),
 -- CO2
 (N'CO2', N'STAFFSK2', N'พนักงาน Skill2', 1),
 (N'CO2', N'STAFFSK3', N'พนักงาน Skill3', 1),
 (N'CO2', N'STAFF',    N'พนักงาน',        1),
 -- Grating (GRAT)
 (N'GRAT', N'GRATMGR',      N'Grating Manager',     3),
 (N'GRAT', N'PRODENGINEER', N'Production Engineer', 1),
 (N'GRAT', N'WELDER',       N'พนักงานเชื่อม',        1),
 (N'GRAT', N'STAFF',        N'พนักงาน',              1),
 -- วิศวกรรมไฟฟ้า (ENGE)
 (N'ENGE', N'SRENGMGR',     N'ผู้จัดการอาวุโสฝ่ายวิศวกรรม', 3),
 (N'ENGE', N'ENGMGR',       N'ผู้จัดการฝ่ายวิศวกรรม',       3),
 (N'ENGE', N'ASSTHEADELEC', N'ผช.หัวหน้าแผนก(ไฟฟ้า)',       2),
 (N'ENGE', N'STAFFSK4',     N'พนักงาน Skill4 ชำนาญการ',     1),
 (N'ENGE', N'ELECTECH',     N'ช่างไฟฟ้า',                   1),
 (N'ENGE', N'ELECENG',      N'วิศวกรไฟฟ้า',                 1),
 -- วิศวกรรมเครื่องกล (ENGM)
 (N'ENGM', N'DEPTHEAD',     N'หัวหน้าแผนก',              3),
 (N'ENGM', N'ASSTHEADMECH', N'ผช.หัวหน้าแผนก(เครื่องกล)', 2),
 (N'ENGM', N'CRAFTSMAN',    N'ช่างฝีมือ',                1),
 (N'ENGM', N'SKILLTECH',    N'ช่าง Skill',               1),
 (N'ENGM', N'TECHNICIAN',   N'ช่างเทคนิค',               1),
 (N'ENGM', N'MAINTTECH',    N'ช่างซ่อมบำรุง',            1),
 (N'ENGM', N'OFFICER3',     N'เจ้าหน้าที่3',             1),
 -- บ่อต้ม / บ่อล้าง / บ่อบำบัด
 (N'BOIL', N'STAFF', N'พนักงาน', 1),
 (N'WASH', N'STAFF', N'พนักงาน', 1),
 (N'WWTP', N'STAFF', N'พนักงาน', 1),
 -- ===== แผนกเดิมที่ยังว่าง =====
 -- Production (PD)
 (N'PD', N'SRPRODMGR',    N'Senior Production Manager', 3),
 (N'PD', N'PRODMGR',      N'ผู้จัดการฝ่ายผลิต',         3),
 (N'PD', N'SRPLANTMGR',   N'ผู้จัดการฝ่ายโรงงานอาวุโส', 3),
 (N'PD', N'ASSTPRODMGR',  N'ผู้ช่วยผู้จัดการฝ่ายผลิต',  2),
 (N'PD', N'PRODTRAIN',    N'Production Training',       1),
 (N'PD', N'PRODENG1',     N'วิศวกรผลิต 1',             1),
 (N'PD', N'PRODENGINEER', N'Production Engineer',       1),
 (N'PD', N'FORKLIFTDRV',  N'พนักงานขับรถโฟล์คลิพท์',   1),
 -- แต่งไดร์ (DD)
 (N'DD', N'SHIFTLEAD', N'หัวหน้ากะ',  2),
 (N'DD', N'CRAFTSMAN', N'ช่างฝีมือ',  1),
 (N'DD', N'STAFF',     N'พนักงาน',    1),
 -- R&D (R)
 (N'R', N'SRRNDMGR',    N'ผู้จัดการอาวุโสฝ่ายR&D',      3),
 (N'R', N'SRDEPTHEAD',  N'หัวหน้าแผนกอาวุโส',           3),
 (N'R', N'MACHINNOMGR', N'Machine Innovation Manager',  3),
 (N'R', N'OFFICEREXP1', N'เจ้าหน้าที่ชำนาญการ1',        1),
 (N'R', N'OFFICEREXP2', N'เจ้าหน้าที่ชำนาญการ2',        1),
 (N'R', N'ENGINEER1',   N'วิศวกร1',                     1),
 (N'R', N'ELECENG',     N'วิศวกรไฟฟ้า',                 1),
 (N'R', N'MACHDESENG',  N'วิศวกรออกแบบเครื่องจักร',     1),
 -- QC
 (N'QC', N'SHIFTLEAD',  N'หัวหน้ากะ',      2),
 (N'QC', N'TECHNICIAN', N'ช่างเทคนิค',     1),
 (N'QC', N'QCOFF',      N'เจ้าหน้าที่QC',  1),
 -- QMS
 (N'QMS', N'QMSOFF', N'เจ้าหน้าที่ QMS', 1),
 -- Warehouse / คลังสินค้า (WH)
 (N'WH', N'LEADER',  N'Leader',      2),
 (N'WH', N'OFFICER', N'เจ้าหน้าที่', 1),
 (N'WH', N'STAFF',   N'พนักงาน',     1),
 -- แพ็คกิ้ง (PK)
 (N'PK', N'STAFF', N'พนักงาน', 1),
 -- ยานยนต์ (AM)
 (N'AM', N'EXECDRIVER', N'พนักงานขับรถผู้บริหาร', 1),
 (N'AM', N'DRIVER',     N'พนักงานขับรถ',          1),
 -- ความปลอดภัยและสิ่งแวดล้อม (SE)
 (N'SE', N'ENGINEER1', N'วิศวกร1', 1),
 -- Director (D)  = บริหาร/บอร์ด
 (N'D', N'CHAIRMAN', N'ประธานกรรมการบริหาร', 4),
 (N'D', N'MD',       N'กรรมการผู้จัดการ',    4),
 (N'D', N'EXEDIR',   N'กรรมการบริหาร',       4),
 (N'D', N'FOUNDER',  N'ประธานผู้ก่อตั้งบริษัท', 4),
 -- การเงิน (FN)
 (N'FN', N'OFFICER1', N'เจ้าหน้าที่1', 1),
 (N'FN', N'OFFICER2', N'เจ้าหน้าที่2', 1),
 (N'FN', N'OFFICER3', N'เจ้าหน้าที่3', 1),
 -- IT Support (ITH)
 (N'ITH', N'ITSUPMGR', N'ผู้จัดการฝ่าย IT Support', 3),
 (N'ITH', N'ITSPV',    N'IT Supervisor',           2),
 -- IT Solution (ITS)
 (N'ITS', N'DATAANALYST', N'Data Analyst', 1),
 (N'ITS', N'PROGRAMMER',  N'Programmer',   1),
 -- Store (S)
 (N'S', N'STOREOFF', N'เจ้าหน้าที่สโตร์', 1);

INSERT INTO department_roles (department_id, code, name, level_no, is_active, created_at, updated_at)
SELECT d.id, r.code, r.name, r.level_no, 1, SYSDATETIME(), SYSDATETIME()
FROM @role r
JOIN departments d ON d.code = r.dept_code
WHERE NOT EXISTS (
    SELECT 1 FROM department_roles x
    WHERE x.department_id = d.id AND x.code = r.code
);

COMMIT;

-- ============================================================
--  ตรวจผลลัพธ์ (ไม่แก้ไขข้อมูล)
-- ============================================================
-- แผนกใหม่ + parent
SELECT c.code, c.name AS department, p.name AS parent
FROM departments c
LEFT JOIN departments p ON p.id = c.parent_id
WHERE c.code IN (N'ROLL',N'RLSQ',N'SFT1',N'SFT2',N'ANNL',N'CG',N'CO2',N'GRAT',N'ENGE',N'ENGM',N'BOIL',N'WASH',N'WWTP',N'SE')
ORDER BY p.name, c.code;

-- ตำแหน่งที่เพิ่งเติม (เฉพาะแผนกเป้าหมาย)
SELECT d.code AS dept_code, d.name AS department, dr.level_no, dr.code AS role_code, dr.name AS role
FROM department_roles dr
JOIN departments d ON d.id = dr.department_id
WHERE d.code IN (N'ROLL',N'RLSQ',N'SFT1',N'SFT2',N'ANNL',N'CG',N'CO2',N'GRAT',N'ENGE',N'ENGM',
                 N'BOIL',N'WASH',N'WWTP',N'PD',N'DD',N'R',N'QC',N'QMS',N'WH',N'PK',N'AM',N'SE',N'D',N'FN',N'ITH',N'ITS',N'S')
ORDER BY d.code, dr.level_no DESC, dr.code;
