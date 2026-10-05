-- 040 Roster planning: the plan side of attendance.
--
-- One row in roster_weeks is the paper page a manager writes today (one store,
-- one week). roster_shifts are the dated slots inside it - the note's "7 to 4"
-- and "4 to 12" columns - and roster_assignments are the employees written
-- against each slot.
--
-- ends_next_day exists because late shifts at late-trading stores finish after
-- midnight ("4 to 2 am"). Storing 02:00 with a flag keeps the real calendar date
-- of the shift start intact instead of inventing a next-day shift_date.
--
-- Planning is not attendance: nothing here writes timesheets or pay. The roster
-- is what the attendance side is intended to be compared against.

CREATE TABLE IF NOT EXISTS roster_weeks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT NOT NULL,
    store_id INT NOT NULL,
    week_start DATE NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    note VARCHAR(255) NULL,
    created_by_employee_id INT NULL,
    updated_by_employee_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_weeks_store_week (store_id, week_start),
    KEY idx_roster_weeks_client_week (client_id, week_start),
    KEY idx_roster_weeks_status (client_id, status, week_start),
    CONSTRAINT fk_roster_weeks_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_roster_weeks_store FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_roster_weeks_created_by FOREIGN KEY (created_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT fk_roster_weeks_updated_by FOREIGN KEY (updated_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT chk_roster_weeks_status CHECK (status IN ('draft','published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roster_shifts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    roster_week_id BIGINT UNSIGNED NOT NULL,
    client_id INT NOT NULL,
    store_id INT NOT NULL,
    shift_date DATE NOT NULL,
    slot_key VARCHAR(24) NOT NULL,
    label VARCHAR(48) NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    ends_next_day TINYINT(1) NOT NULL DEFAULT 0,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_shifts_slot (roster_week_id, shift_date, slot_key),
    KEY idx_roster_shifts_store_date (store_id, shift_date),
    KEY idx_roster_shifts_week (roster_week_id, shift_date, position),
    CONSTRAINT fk_roster_shifts_week FOREIGN KEY (roster_week_id) REFERENCES roster_weeks(id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_roster_shifts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_roster_shifts_store FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_roster_shifts_slot CHECK (slot_key REGEXP '^[a-z0-9_]{1,24}$'),
    CONSTRAINT chk_roster_shifts_ends_next_day CHECK (ends_next_day IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roster_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    roster_shift_id BIGINT UNSIGNED NOT NULL,
    client_id INT NOT NULL,
    store_id INT NOT NULL,
    employee_id INT NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    assigned_by_employee_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_assignments_shift_employee (roster_shift_id, employee_id),
    KEY idx_roster_assignments_employee (employee_id, created_at),
    KEY idx_roster_assignments_store (store_id, roster_shift_id),
    CONSTRAINT fk_roster_assignments_shift FOREIGN KEY (roster_shift_id) REFERENCES roster_shifts(id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_roster_assignments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_roster_assignments_store FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_roster_assignments_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_roster_assignments_actor FOREIGN KEY (assigned_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
