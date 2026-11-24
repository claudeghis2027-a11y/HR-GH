-- GHIS HR — unified schema (MariaDB 10.5+ / MySQL 8+)
-- Merges "GHIS V2 Database.xlsx" (HR admin) and "GHIS Attendance V2.xlsx" (attendance app).
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ───────────────────────── Security / access ─────────────────────────
CREATE TABLE IF NOT EXISTS roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(50)  NOT NULL UNIQUE,
  name          VARCHAR(100) NOT NULL,
  is_super      TINYINT(1)   NOT NULL DEFAULT 0,   -- bypasses every check
  is_staff      TINYINT(1)   NOT NULL DEFAULT 1,   -- 0 = self-service (employee/teacher)
  dept_scope    VARCHAR(500) NULL,                 -- comma-separated department ids; NULL = all
  is_system     TINYINT(1)   NOT NULL DEFAULT 0,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id    INT UNSIGNED NOT NULL,
  module     VARCHAR(40)  NOT NULL,
  can_view   TINYINT(1) NOT NULL DEFAULT 0,
  can_create TINYINT(1) NOT NULL DEFAULT 0,
  can_update TINYINT(1) NOT NULL DEFAULT 0,
  can_delete TINYINT(1) NOT NULL DEFAULT 0,
  can_export TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, module),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(64)  NOT NULL UNIQUE,
  display_name         VARCHAR(150) NULL,
  password_hash        VARCHAR(255) NULL,           -- argon2id / bcrypt
  legacy_hash          VARCHAR(128) NULL,           -- SHA-256 from Google Sheets, upgraded on first login
  legacy_salt          VARCHAR(64)  NULL,
  role_id              INT UNSIGNED NOT NULL,
  employee_id          INT UNSIGNED NULL UNIQUE,
  active               TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  failed_logins        INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until         DATETIME     NULL,
  session_epoch        INT UNSIGNED NOT NULL DEFAULT 1, -- bump to kill all sessions of the user
  last_login_at        DATETIME     NULL,
  password_changed_at  DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_emp  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  username   VARCHAR(64) NOT NULL,
  success    TINYINT(1)  NOT NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_time (ip, created_at),
  KEY idx_user_time (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id    INT UNSIGNED NULL,
  username   VARCHAR(64)  NULL,
  action     VARCHAR(80)  NOT NULL,
  target     VARCHAR(255) NULL,
  details    TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  KEY idx_time (created_at),
  KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k     VARCHAR(80)  PRIMARY KEY,
  v     VARCHAR(500) NULL,
  label VARCHAR(255) NULL,
  note  VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────── Organisation / employees ─────────────────────────
CREATE TABLE IF NOT EXISTS departments (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(150) NOT NULL UNIQUE,
  active            TINYINT(1) NOT NULL DEFAULT 1,
  attendance_exempt TINYINT(1) NOT NULL DEFAULT 0,   -- الأقسام المعفاة من البصمة
  summer_rule       VARCHAR(20) NULL,                -- تصنيف أقسام خصم الصيف (Rule 1 / Rule 2)
  created_at        DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_titles (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL UNIQUE,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
  id                       INT UNSIGNED PRIMARY KEY,          -- كود الموظف
  name_ar                  VARCHAR(150) NOT NULL,
  name_en                  VARCHAR(150) NULL,
  department_id            INT UNSIGNED NULL,
  job_title_id             INT UNSIGNED NULL,
  hire_date                DATE NULL,
  end_date                 DATE NULL,
  attendance_exempt        TINYINT(1) NOT NULL DEFAULT 0,
  nationality              VARCHAR(80) NULL,
  phone                    VARCHAR(30) NULL,
  pay_method               ENUM('cash','visa') NULL,
  papers_received          TINYINT(1) NOT NULL DEFAULT 0,
  photo_ref                VARCHAR(255) NULL,                  -- Drive file id (legacy) or local file
  summer_deduction         VARCHAR(50) NULL,
  first_year_deduction     VARCHAR(50) NULL,
  first_raise_month        DATE NULL,
  first_year_exception     VARCHAR(50) NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dept (department_id),
  CONSTRAINT fk_emp_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_job  FOREIGN KEY (job_title_id)  REFERENCES job_titles(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_change_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  field       VARCHAR(80) NOT NULL,
  old_value   TEXT NULL,
  new_value   TEXT NULL,
  reason      VARCHAR(500) NULL,
  changed_by  VARCHAR(64) NULL,
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  op_id       VARCHAR(64) NULL,
  KEY idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_field_permissions (
  field       VARCHAR(40) PRIMARY KEY,
  label       VARCHAR(100) NOT NULL,
  visible     TINYINT(1) NOT NULL DEFAULT 1,
  editable    TINYINT(1) NOT NULL DEFAULT 0,
  required    TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_update_requests (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT UNSIGNED NOT NULL,
  fields_json  TEXT NOT NULL,
  photo_ref    VARCHAR(255) NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewer     VARCHAR(64) NULL,
  reviewed_at  DATETIME NULL,
  reject_reason VARCHAR(500) NULL,
  legacy_id    VARCHAR(64) NULL,
  KEY idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_review_state (           -- مراجعة الصور + مطابقة الوجه + استكمال البيانات
  employee_id           INT UNSIGNED PRIMARY KEY,
  photo_pending_ref     VARCHAR(255) NULL,
  photo_review_status   VARCHAR(30) NULL,
  photo_upload_used     TINYINT(1) NOT NULL DEFAULT 0,
  face_fail_streak      INT NOT NULL DEFAULT 0,
  face_review_status    VARCHAR(30) NULL,
  profile_status        VARCHAR(30) NULL,
  profile_submitted_at  DATETIME NULL,
  profile_approved_at   DATETIME NULL,
  profile_reviewer      VARCHAR(64) NULL,
  profile_reject_reason VARCHAR(500) NULL,
  updated_at            DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_years (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  date_from  DATE NOT NULL,
  date_to    DATE NOT NULL,
  active     TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────── Attendance ─────────────────────────
CREATE TABLE IF NOT EXISTS attendance_points (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(100) NOT NULL UNIQUE,
  lat       DECIMAL(10,7) NULL,
  lng       DECIMAL(10,7) NULL,
  radius_m  INT UNSIGNED NOT NULL DEFAULT 100,
  active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id        INT UNSIGNED NOT NULL,
  work_date          DATE NOT NULL,
  check_in_at        DATETIME NULL,
  check_in_point     VARCHAR(100) NULL,
  check_in_distance  DECIMAL(10,1) NULL,
  check_in_lat       DECIMAL(10,7) NULL,
  check_in_lng       DECIMAL(10,7) NULL,
  check_in_photo     VARCHAR(255) NULL,
  check_out_at       DATETIME NULL,
  check_out_point    VARCHAR(100) NULL,
  check_out_distance DECIMAL(10,1) NULL,
  check_out_lat      DECIMAL(10,7) NULL,
  check_out_lng      DECIMAL(10,7) NULL,
  check_out_photo    VARCHAR(255) NULL,
  source             VARCHAR(20) NOT NULL DEFAULT 'web',
  UNIQUE KEY uq_emp_day (employee_id, work_date),
  KEY idx_date (work_date),
  CONSTRAINT fk_att_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS face_match_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  at          DATETIME NOT NULL,
  result      VARCHAR(30) NOT NULL,
  distance    DECIMAL(8,5) NULL,
  streak      INT NULL,
  counted     TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS identity_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NULL,
  op_type     VARCHAR(60) NOT NULL,
  at          DATETIME NOT NULL,
  result      VARCHAR(60) NULL,
  details     VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_rules (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20) NULL UNIQUE,
  date_from  DATE NOT NULL,
  date_to    DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_rules_archive (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  archive_code VARCHAR(30) NULL,
  rule_code  VARCHAR(20) NULL,
  operation  VARCHAR(30) NULL,
  date_from  DATE NULL,
  date_to    DATE NULL,
  start_time TIME NULL,
  end_time   TIME NULL,
  done_by    VARCHAR(64) NULL,
  done_at    DATETIME NULL,
  reason     VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_schedules (           -- مواعيد خاصة لموظفين
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deduction_rules (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  date_from             DATE NOT NULL,
  date_to               DATE NOT NULL,
  late_unit_min         INT NOT NULL DEFAULT 25,
  late_unit_days        DECIMAL(5,2) NOT NULL DEFAULT 0.25,
  absence_days          DECIMAL(5,2) NOT NULL DEFAULT 1,
  missing_checkout_days DECIMAL(5,2) NOT NULL DEFAULT 0.25,
  early_leave_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  early_unit_min        INT NOT NULL DEFAULT 25,
  early_unit_days       DECIMAL(5,2) NOT NULL DEFAULT 0.25,
  perm1_days            DECIMAL(5,2) NOT NULL DEFAULT 0,
  perm2_days            DECIMAL(5,2) NOT NULL DEFAULT 0.25,
  perm3_days            DECIMAL(5,2) NOT NULL DEFAULT 0.5,
  perm4_days            DECIMAL(5,2) NOT NULL DEFAULT 0.75,
  perm5_days            DECIMAL(5,2) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date  DATE NOT NULL,
  title         VARCHAR(255) NOT NULL,
  htype         VARCHAR(40) NOT NULL DEFAULT 'OFFICIAL_HOLIDAY',
  source        VARCHAR(255) NULL,
  source_date   DATE NULL,
  original_date DATE NULL,
  actual_date   DATE NULL,
  status        ENUM('ACTIVE','SUPERSEDED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  synced_at     DATETIME NULL,
  note          VARCHAR(500) NULL,
  KEY idx_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leaves (                       -- الإجازات الشخصية المعتمدة
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  reason      VARCHAR(500) NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_adjustments (       -- تعديلات الحضور
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  adj_date    DATE NOT NULL,
  adj_type    ENUM('set_check_in','set_check_out','waive_day','other') NOT NULL,
  value       VARCHAR(100) NULL,
  reason      VARCHAR(500) NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp_date (employee_id, adj_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_requests (          -- سجل الأذونات
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  perm_date   DATE NOT NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp_date (employee_id, perm_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forgot_punch_log (             -- سجل نسيان تسجيل الحضور/الانصراف
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  punch_date  DATE NOT NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp_date (employee_id, punch_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS extra_deductions (             -- الخصومات الإضافية والجزاءات
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  ded_date    DATE NOT NULL,
  reason      VARCHAR(500) NULL,
  days        DECIMAL(5,2) NOT NULL DEFAULT 0,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp_date (employee_id, ded_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS late_exemptions (              -- إعفاءات التأخير المعتمدة
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  ex_date     DATE NOT NULL,
  minutes     INT NOT NULL,
  reason      VARCHAR(500) NULL,
  approver    VARCHAR(150) NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  legacy_id   VARCHAR(64) NULL,
  KEY idx_emp_date (employee_id, ex_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkout_exemptions (          -- استثناءات إلزام الانصراف
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope       ENUM('all','department','employee') NOT NULL,
  scope_value VARCHAR(150) NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────── Payroll ─────────────────────────
CREATE TABLE IF NOT EXISTS salary_tx_types (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(100) NOT NULL UNIQUE,
  direction ENUM('add','deduct') NOT NULL,
  category  VARCHAR(40) NULL,                   -- basic / allowance / ...
  active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salary_tx (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tx_code     VARCHAR(30) NULL UNIQUE,
  employee_id INT UNSIGNED NOT NULL,
  type_id     INT UNSIGNED NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NULL,                        -- NULL = مستمرة
  amount      DECIMAL(12,2) NOT NULL,
  reason      VARCHAR(500) NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp (employee_id),
  CONSTRAINT fk_tx_type FOREIGN KEY (type_id) REFERENCES salary_tx_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advances (                     -- سلف الموظفين
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  legacy_id         VARCHAR(64) NULL,
  employee_id       INT UNSIGNED NOT NULL,
  advance_date      DATE NOT NULL,
  total             DECIMAL(12,2) NOT NULL,
  installments      INT NOT NULL DEFAULT 1,
  deduct_from       DATE NOT NULL,
  installment_value DECIMAL(12,2) NOT NULL,
  status            ENUM('active','paid','cancelled') NOT NULL DEFAULT 'active',
  notes             VARCHAR(500) NULL,
  created_by        VARCHAR(64) NULL,
  created_at        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_periods (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NULL,
  date_from  DATE NOT NULL,
  date_to    DATE NOT NULL,
  status     ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_by VARCHAR(64) NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by  VARCHAR(64) NULL,
  closed_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_period_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id  INT UNSIGNED NOT NULL,
  action     VARCHAR(40) NOT NULL,
  done_by    VARCHAR(64) NULL,
  done_at    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  reason     VARCHAR(500) NULL,
  gap_warning TINYINT(1) NOT NULL DEFAULT 0,
  gap_details TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_snapshots (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id   INT UNSIGNED NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  employees   INT NOT NULL DEFAULT 0,
  closed_by   VARCHAR(64) NULL,
  closed_at   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  diagnostics MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_snapshot_rows (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  name_ar     VARCHAR(150) NULL,
  department  VARCHAR(150) NULL,
  data_json   MEDIUMTEXT NOT NULL,
  KEY idx_snap (snapshot_id),
  CONSTRAINT fk_snaprow FOREIGN KEY (snapshot_id) REFERENCES payroll_snapshots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_year_settings (        -- إعدادات المرتبات للسنة
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academic_year_id   INT UNSIGNED NULL,
  raise_month        TINYINT NULL,
  quarter_ded_months VARCHAR(100) NULL,
  quarter_refund_month TINYINT NULL,
  quarter_ded_departments VARCHAR(500) NULL,
  created_by         VARCHAR(64) NULL,
  created_at         DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quarter_deduction_policy (     -- سياسة خصم الربعين
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academic_year_id INT UNSIGNED NULL,
  department_id    INT UNSIGNED NULL,
  month_no         TINYINT NOT NULL,
  applied          TINYINT(1) NOT NULL DEFAULT 1,
  created_by       VARCHAR(64) NULL,
  created_at       DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS summer_deduction_log (         -- سجل خصم واسترداد الصيف
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  month_no       TINYINT NOT NULL,
  year_no        SMALLINT NOT NULL,
  basic_salary   DECIMAL(12,2) NULL,
  amount         DECIMAL(12,2) NOT NULL,
  refund_status  VARCHAR(30) NULL,
  refunded_at    DATE NULL,
  ded_type       VARCHAR(40) NULL,
  created_by     VARCHAR(64) NULL,
  created_at     DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────── Documents / investigations ─────────────────────────
CREATE TABLE IF NOT EXISTS document_types (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL UNIQUE,
  has_number   TINYINT(1) NOT NULL DEFAULT 0,
  required     TINYINT(1) NOT NULL DEFAULT 0,
  has_expiry   TINYINT(1) NOT NULL DEFAULT 0,
  alert_days   INT NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_documents (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  type_id     INT UNSIGNED NOT NULL,
  doc_number  VARCHAR(100) NULL,
  is_original ENUM('original','copy') NULL,
  issue_date  DATE NULL,
  expiry_date DATE NULL,
  file_ref    VARCHAR(255) NULL,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp (employee_id),
  CONSTRAINT fk_doc_type FOREIGN KEY (type_id) REFERENCES document_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investigations (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  legacy_id   VARCHAR(64) NULL,
  inv_date    DATE NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  decision    VARCHAR(1000) NULL,
  notes       TEXT NULL,
  cancelled   TINYINT(1) NOT NULL DEFAULT 0,
  created_by  VARCHAR(64) NULL,
  created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investigation_employees (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  investigation_id INT UNSIGNED NOT NULL,
  employee_id      INT UNSIGNED NOT NULL,
  cancelled        TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp (employee_id),
  CONSTRAINT fk_inv_emp FOREIGN KEY (investigation_id) REFERENCES investigations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investigation_attachments (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  investigation_id INT UNSIGNED NOT NULL,
  file_ref         VARCHAR(255) NULL,
  file_name        VARCHAR(255) NULL,
  file_url         VARCHAR(500) NULL,
  cancelled        TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_at      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_by      VARCHAR(64) NULL,
  CONSTRAINT fk_inv_att FOREIGN KEY (investigation_id) REFERENCES investigations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────── Notifications ─────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          VARCHAR(40) NULL UNIQUE,
  ntype         VARCHAR(60) NOT NULL DEFAULT 'عام',
  period_month  DATE NULL,
  title         VARCHAR(255) NOT NULL,
  body          TEXT NULL,
  show_at       DATETIME NOT NULL,
  duration_min  INT NOT NULL DEFAULT 120,
  scope         ENUM('all','department','employee') NOT NULL DEFAULT 'all',
  scope_value   VARCHAR(150) NULL,
  status        VARCHAR(30) NOT NULL DEFAULT 'scheduled',
  report_from   DATE NULL,
  report_to     DATE NULL,
  created_by    VARCHAR(64) NULL,
  created_at    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_targets (
  notification_id INT UNSIGNED NOT NULL,
  employee_id     INT UNSIGNED NOT NULL,
  show_at         DATETIME NULL,
  read_at         DATETIME NULL,
  hide_at         DATETIME NULL,
  PRIMARY KEY (notification_id, employee_id),
  KEY idx_emp (employee_id),
  CONSTRAINT fk_nt_n FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at          DATETIME NULL,
  description VARCHAR(255) NULL,
  source      VARCHAR(100) NULL,
  size_text   VARCHAR(50) NULL,
  file_ref    VARCHAR(255) NULL,
  done_by     VARCHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- ───────────────────────── Face verification (v2) ─────────────────────────
ALTER TABLE settings MODIFY v TEXT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS official_photo VARCHAR(255) NULL AFTER photo_ref;

CREATE TABLE IF NOT EXISTS face_consents (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NULL,
  consent_version INT UNSIGNED NOT NULL,
  consent_text    TEXT NOT NULL,          -- exact text the employee agreed to
  accepted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip              VARCHAR(45) NULL,
  user_agent      VARCHAR(255) NULL,
  KEY idx_emp (employee_id, consent_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS face_profiles (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  status         ENUM('pending','approved','rejected','replaced','deleted') NOT NULL DEFAULT 'pending',
  photo_file     VARCHAR(255) NULL,       -- encrypted reference photo under storage/faces (deleted when not needed)
  embedding_enc  VARBINARY(1024) NULL,    -- encrypted SFace embedding (128 x float32)
  metrics_json   VARCHAR(1000) NULL,
  submitted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by    VARCHAR(64) NULL,
  reviewed_at    DATETIME NULL,
  reject_reason  VARCHAR(500) NULL,
  deleted_at     DATETIME NULL,
  delete_reason  VARCHAR(100) NULL,
  KEY idx_emp_status (employee_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_face (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id BIGINT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NOT NULL,
  kind          ENUM('in','out') NOT NULL,
  profile_id    INT UNSIGNED NOT NULL,
  score         DECIMAL(6,4) NOT NULL,
  flag          ENUM('ok','unapproved_photo','hr_review','hr_ok','hr_invalid') NOT NULL DEFAULT 'ok',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   VARCHAR(64) NULL,
  reviewed_at   DATETIME NULL,
  review_note   VARCHAR(500) NULL,
  KEY idx_flag (flag),
  KEY idx_profile (profile_id),
  KEY idx_att (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Face attempt measurements (used to tune thresholds from real phones)
ALTER TABLE face_match_log ADD COLUMN IF NOT EXISTS kind VARCHAR(10) NULL;
ALTER TABLE face_match_log ADD COLUMN IF NOT EXISTS device VARCHAR(40) NULL;
ALTER TABLE face_match_log ADD COLUMN IF NOT EXISTS metrics VARCHAR(600) NULL;

-- UI language per user
ALTER TABLE users ADD COLUMN IF NOT EXISTS locale VARCHAR(5) NULL;
-- English consent text (shown to users who chose English)

-- ───────────────────────── Phase 1: old-GHIS payroll & attendance parity ─────────────────────────
-- Attendance adjustments: the five old types (manual present/absent added; waive_day = exclude the day)
ALTER TABLE attendance_adjustments MODIFY adj_type ENUM('set_check_in','set_check_out','force_present','force_absent','waive_day','other') NOT NULL;
-- Per-department salary rates (old settings «نسبة العلاوة — قسم» / «نسبة خصم الربع — قسم»; NULL quarter = 25%)
ALTER TABLE departments ADD COLUMN IF NOT EXISTS allowance_rate DECIMAL(6,2) NULL;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS quarter_rate DECIMAL(6,2) NULL;
-- Year settings keep months as YYYY-MM like the old sheet
ALTER TABLE payroll_year_settings MODIFY raise_month VARCHAR(7) NULL;
ALTER TABLE payroll_year_settings MODIFY quarter_refund_month VARCHAR(7) NULL;
ALTER TABLE payroll_year_settings ADD UNIQUE KEY IF NOT EXISTS uq_year (academic_year_id);
ALTER TABLE quarter_deduction_policy ADD COLUMN IF NOT EXISTS month_key VARCHAR(7) NULL;
ALTER TABLE quarter_deduction_policy MODIFY month_no TINYINT NULL;
ALTER TABLE quarter_deduction_policy ADD UNIQUE KEY IF NOT EXISTS uq_policy (academic_year_id, department_id, month_key);
-- Summer deduction: one row per employee / month / year (the old calculation is idempotent on that key)
ALTER TABLE summer_deduction_log ADD UNIQUE KEY IF NOT EXISTS uq_emp_month (employee_id, month_no, year_no);

-- ───────────────────────── Phase 2: closing, reopen, advances ─────────────────────────
-- Closing snapshots: building → ready → superseded (old ClosingSnapshots.state); the period name as it was when closed
ALTER TABLE payroll_snapshots ADD COLUMN IF NOT EXISTS period_name VARCHAR(100) NULL;
ALTER TABLE payroll_snapshots ADD COLUMN IF NOT EXISTS state ENUM('building','ready','superseded') NOT NULL DEFAULT 'ready';
ALTER TABLE payroll_snapshots ADD KEY IF NOT EXISTS idx_period_state (period_id, state);
-- Advances are cancelled with a reason (old advanceCancel)
ALTER TABLE advances ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500) NULL;
ALTER TABLE advances ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(64) NULL;
ALTER TABLE advances ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL;

-- ───────────────────────── Phase 3: employees, documents, investigations, notifications, holidays ─────────────────────────
-- Documents: soft delete keeps the record and its file (old «محذوف؟»)
ALTER TABLE employee_documents ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE employee_documents ADD COLUMN IF NOT EXISTS deleted_by VARCHAR(64) NULL;
ALTER TABLE employee_documents ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
-- Investigations: INV-000001 numbers; editing saves a new version (the old one is kept, superseded); cancel with a reason
ALTER TABLE investigations ADD COLUMN IF NOT EXISTS inv_no VARCHAR(20) NULL;
ALTER TABLE investigations ADD COLUMN IF NOT EXISTS superseded_by INT UNSIGNED NULL;
ALTER TABLE investigations ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500) NULL;
ALTER TABLE investigations ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(64) NULL;
ALTER TABLE investigations ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL;
ALTER TABLE investigations ADD KEY IF NOT EXISTS idx_inv_no (inv_no);
UPDATE investigations SET inv_no = legacy_id WHERE inv_no IS NULL AND legacy_id REGEXP '^INV-[0-9]+$';
-- Notifications are cancelled with a reason (old notifications.cancel)
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500) NULL;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(64) NULL;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL;
-- a notification can target several departments / employees ("3,7,12")
ALTER TABLE notifications MODIFY scope_value TEXT NULL;

-- ───────── Old employee app parity: notification content items, remember-me ─────────
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS contents VARCHAR(200) NULL;
CREATE TABLE IF NOT EXISTS remember_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  selector   CHAR(18) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  epoch      INT NOT NULL,
  ua_hash    CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rt_selector (selector),
  KEY ix_rt_user (user_id),
  CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────── Banks: managed by admin / super admin; an employee paid by card (فيزا) picks one ─────────
CREATE TABLE IF NOT EXISTS banks (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL UNIQUE,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_id INT UNSIGNED NULL AFTER pay_method;
ALTER TABLE employees ADD KEY IF NOT EXISTS idx_bank (bank_id);

-- ───────── Specialization (التخصص): what the employee does inside the department (subject taught, HR, IT, cleaning...) ─────────
ALTER TABLE employees ADD COLUMN IF NOT EXISTS specialization VARCHAR(150) NULL AFTER department_id;

-- ───────── Specializations list (التخصصات): managed like departments / job titles; the employee picks one ─────────
CREATE TABLE IF NOT EXISTS specializations (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL UNIQUE,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- starter list, only while the table is still empty (so a renamed or deactivated one never comes back)
INSERT INTO specializations (name) SELECT n FROM (SELECT 'عربي' AS n UNION ALL SELECT 'إنجليزي' UNION ALL SELECT 'فرنساوي' UNION ALL SELECT 'ألماني' UNION ALL SELECT 'رياضيات' UNION ALL SELECT 'علوم' UNION ALL SELECT 'فيزياء' UNION ALL SELECT 'كيمياء' UNION ALL SELECT 'أحياء' UNION ALL SELECT 'دراسات اجتماعية' UNION ALL SELECT 'دين' UNION ALL SELECT 'حاسب آلي' UNION ALL SELECT 'تربية رياضية' UNION ALL SELECT 'تربية فنية' UNION ALL SELECT 'موسيقى' UNION ALL SELECT 'رياض أطفال' UNION ALL SELECT 'حسابات' UNION ALL SELECT 'HR' UNION ALL SELECT 'Admission' UNION ALL SELECT 'IT' UNION ALL SELECT 'شؤون طلاب' UNION ALL SELECT 'سكرتارية' UNION ALL SELECT 'مشتريات' UNION ALL SELECT 'مخازن' UNION ALL SELECT 'نظافة' UNION ALL SELECT 'زراعة' UNION ALL SELECT 'أمن' UNION ALL SELECT 'صيانة' UNION ALL SELECT 'سواق' UNION ALL SELECT 'مشرف باص' UNION ALL SELECT 'عيادة') d WHERE NOT EXISTS (SELECT 1 FROM specializations);
ALTER TABLE employees ADD COLUMN IF NOT EXISTS specialization_id INT UNSIGNED NULL AFTER department_id;
ALTER TABLE employees ADD KEY IF NOT EXISTS idx_spec (specialization_id);
-- move the free-text specializations typed before the list existed into the list, then link them
INSERT IGNORE INTO specializations (name) SELECT DISTINCT TRIM(specialization) FROM employees WHERE specialization_id IS NULL AND TRIM(COALESCE(specialization, '')) <> '';
UPDATE employees e JOIN specializations s ON s.name = TRIM(e.specialization) SET e.specialization_id = s.id WHERE e.specialization_id IS NULL AND TRIM(COALESCE(e.specialization, '')) <> '';
UPDATE employees SET specialization = NULL WHERE specialization_id IS NOT NULL AND specialization IS NOT NULL;
-- the profile field is now specialization_id (seed.sql adds it with the same defaults)
DELETE FROM employee_field_permissions WHERE field = 'specialization';

-- Coordinator (المنسق): the employee responsible for coordinating this one, by employee code.
ALTER TABLE employees ADD COLUMN IF NOT EXISTS coordinator_id INT UNSIGNED NULL AFTER job_title_id;
ALTER TABLE employees ADD KEY IF NOT EXISTS idx_coordinator (coordinator_id);
ALTER TABLE employees ADD CONSTRAINT fk_emp_coordinator FOREIGN KEY IF NOT EXISTS (coordinator_id) REFERENCES employees(id) ON DELETE SET NULL;
