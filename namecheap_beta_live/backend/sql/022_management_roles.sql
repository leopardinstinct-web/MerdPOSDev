-- MERDPOS management roles
-- Idempotent migration for the reconciled beta.
-- USER = staff, ADMIN/SUPER/DEV = management access.

ALTER TABLE employees
  MODIFY COLUMN employee_type VARCHAR(20) NULL;

UPDATE employees
SET employee_type = 'DEV', role_name = 'Developer'
WHERE client_id = 1
  AND full_name = 'Imran'
  AND status = 'active';

-- Verification only.
SELECT id, full_name, user_id, employee_type, role_name, status
FROM employees
WHERE client_id = 1
  AND employee_type IN ('ADMIN','SUPER','DEV')
ORDER BY employee_type, full_name;
