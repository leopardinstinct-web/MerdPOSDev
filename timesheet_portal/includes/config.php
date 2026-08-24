<?php
/**
 * Timesheet Portal Configuration
 * Google Sheets remains the payroll/report mirror. Transactional beta actions
 * use the existing MerdPOS SQL connection and a durable Sheets outbox.
 */

// Your shared Google Sheet ID.
define('SPREADSHEET_ID', '1JyWMrqyRq3nh-uTpaVhd_XNyfeRFKrdQ09xMxRsGOQA');

// Google Sheet tab names.
define('SHEET_TIME_SHEET', 'Time Sheet');
define('SHEET_PAY_RATE', 'PayRate');
define('SHEET_START_TIME', 'Start Time');
define('SHEET_EMPLOYEE_SETUP', 'Employee Setup');

// Business timezone. Change only if your payroll week should use another timezone.
define('APP_TIMEZONE', 'Australia/Sydney');

// Cache Google Sheet CSV reads for this many seconds to reduce repeated downloads.
// Set to 0 while debugging if you want every page load to fetch fresh data.
define('CSV_CACHE_SECONDS', 60);

// Session settings.
define('SESSION_NAME', 'TIMESHEET_PORTAL_SESSION');
define('SESSION_IDLE_SECONDS', 1800);
define('BACKEND_CONFIG_PATH', __DIR__ . '/../../backend/api/config.php');
define('PORTAL_CLIENT_ID', 1);

// Employee Setup fixed column positions, 0-based.
// Column A = employee name, Column B = role, Column C = user id, Column D = password.
define('EMPLOYEE_COL_NAME', 0);
define('EMPLOYEE_COL_ROLE', 1);
define('EMPLOYEE_COL_USER_ID', 2);
define('EMPLOYEE_COL_PASSWORD', 3);

define('SUPER_ROLE_VALUE', 'SUPER');

// If true, normal employees can see wage rows.
define('SHOW_WAGES_TO_EMPLOYEES', true);
