<?php
declare(strict_types=1);

/**
 * Central MERDPOS beta portal permission catalogue.
 *
 * GENERAL BETA RULE:
 * Every protected portal capability must declare a permission here and the
 * backend endpoint/action must enforce that permission. UI/menu/button/widget
 * visibility may mirror permissions but is never the security boundary.
 *
 * - min_loa is the default client-configurable threshold for delegable access.
 * - dev_only permissions are fixed at DEV and can never be delegated by LOA.
 * - category/order are presentation metadata used by the DEV Roles screen.
 */
function merd_portal_permission_catalog(): array
{
    return [
        'dashboard.view' => ['label'=>'View dashboard','category'=>'Dashboard','min_loa'=>1,'dev_only'=>false,'order'=>10],
        'dashboard.configure' => ['label'=>'Configure role dashboards','category'=>'Dashboard','min_loa'=>1000,'dev_only'=>true,'order'=>20],

        'dashboard.widget.my_shift' => ['label'=>'Widget: My shift','category'=>'Dashboard widgets','min_loa'=>1,'dev_only'=>false,'order'=>10],
        'dashboard.widget.my_disputes' => ['label'=>'Widget: My disputes','category'=>'Dashboard widgets','min_loa'=>1,'dev_only'=>false,'order'=>20],
        'dashboard.widget.recent_attendance' => ['label'=>'Widget: Recent attendance','category'=>'Dashboard widgets','min_loa'=>1,'dev_only'=>false,'order'=>30],
        'dashboard.widget.working_now_count' => ['label'=>'Widget: Working now count','category'=>'Dashboard widgets','min_loa'=>50,'dev_only'=>false,'order'=>40],
        'dashboard.widget.active_employees' => ['label'=>'Widget: Active employees','category'=>'Dashboard widgets','min_loa'=>50,'dev_only'=>false,'order'=>50],
        'dashboard.widget.working_now' => ['label'=>'Widget: Who is working now','category'=>'Dashboard widgets','min_loa'=>50,'dev_only'=>false,'order'=>60],
        'dashboard.widget.workforce_by_store' => ['label'=>'Widget: Workforce by store','category'=>'Dashboard widgets','min_loa'=>50,'dev_only'=>false,'order'=>70],
        'dashboard.widget.pending_disputes' => ['label'=>'Widget: Pending disputes','category'=>'Dashboard widgets','min_loa'=>90,'dev_only'=>false,'order'=>80],
        'dashboard.widget.sync_attention' => ['label'=>'Widget: Sync attention','category'=>'Dashboard widgets','min_loa'=>90,'dev_only'=>false,'order'=>90],
        'dashboard.widget.store_cash_position' => ['label'=>'Widget: Store cash position','category'=>'Dashboard widgets','min_loa'=>50,'dev_only'=>false,'order'=>100],
        'dashboard.widget.cash_mix' => ['label'=>'Widget: Register vs Petty Cash','category'=>'Dashboard widgets','min_loa'=>50,'dev_only'=>false,'order'=>110],
        'dashboard.widget.today_sales_by_store' => ['label'=>'Widget: Today sales by store','category'=>'Dashboard widgets','min_loa'=>50,'dev_only'=>false,'order'=>120],

        'attendance.scan' => ['label'=>'Use QR attendance','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>10],
        'timesheets.view_own' => ['label'=>'View own timesheet','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>20],
        'timesheets.view_all' => ['label'=>'View all employee timesheets','category'=>'Attendance','min_loa'=>50,'dev_only'=>false,'order'=>30],
        'timesheets.view_pay' => ['label'=>'View payroll / wage values','category'=>'Attendance','min_loa'=>50,'dev_only'=>false,'order'=>40],
        'disputes.view_own' => ['label'=>'View own disputes','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>50],
        'disputes.submit_own' => ['label'=>'Submit / cancel own disputes','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>60],
        'disputes.review' => ['label'=>'Approve / reject disputes','category'=>'Attendance','min_loa'=>90,'dev_only'=>false,'order'=>70],
        'attendance_flags.resolve' => ['label'=>'Resolve attendance security flags','category'=>'Attendance','min_loa'=>90,'dev_only'=>false,'order'=>80],

        'stores.view' => ['label'=>'View stores','category'=>'Operations','min_loa'=>50,'dev_only'=>false,'order'=>10],
        'stores.manage' => ['label'=>'Add / edit store name and status','category'=>'Operations','min_loa'=>50,'dev_only'=>false,'order'=>20],
        'stores.timings.manage' => ['label'=>'Manage store weekly timings','category'=>'Operations','min_loa'=>50,'dev_only'=>false,'order'=>30],
        'stores.profile.manage' => ['label'=>'Manage store code, address and Maps profile','category'=>'Operations','min_loa'=>1000,'dev_only'=>true,'order'=>40],
        'stores.logo.manage' => ['label'=>'Upload / remove store logos','category'=>'Operations','min_loa'=>1000,'dev_only'=>true,'order'=>50],
        'workforce.view' => ['label'=>'View workforce directory','category'=>'Operations','min_loa'=>50,'dev_only'=>false,'order'=>60],
        'workforce.manage' => ['label'=>'Add / edit employees and assignments','category'=>'Operations','min_loa'=>50,'dev_only'=>false,'order'=>70],
        'workforce.payrates.manage' => ['label'=>'View / manage employee pay rates','category'=>'Operations','min_loa'=>50,'dev_only'=>false,'order'=>80],
        'workforce.credentials.reset' => ['label'=>'Reset another employee password','category'=>'Operations','min_loa'=>90,'dev_only'=>false,'order'=>90],

        // Finance remains available to a clocked-in staff member at their store.
        // Higher LOA separately grants cross-store / management capabilities.
        'finance.view' => ['label'=>'Use financial screen at permitted store','category'=>'Finance','min_loa'=>1,'dev_only'=>false,'order'=>10],
        'finance.submit' => ['label'=>'Submit register / petty cash entries','category'=>'Finance','min_loa'=>1,'dev_only'=>false,'order'=>20],
        'finance.open_day' => ['label'=>'Set opening register / petty cash balances','category'=>'Finance','min_loa'=>50,'dev_only'=>false,'order'=>30],
        'finance.cross_store' => ['label'=>'View/manage finance without being clocked in at that store','category'=>'Finance','min_loa'=>50,'dev_only'=>false,'order'=>40],
        'finance.management_summary' => ['label'=>'View cross-store financial dashboard summaries','category'=>'Finance','min_loa'=>50,'dev_only'=>false,'order'=>50],

        'system.sync_status' => ['label'=>'View sync / outbox attention status','category'=>'System','min_loa'=>90,'dev_only'=>false,'order'=>10],
        'roles.manage' => ['label'=>'Create, edit and delete roles','category'=>'System','min_loa'=>1000,'dev_only'=>true,'order'=>20],
        'permissions.manage' => ['label'=>'Configure permission LOA thresholds','category'=>'System','min_loa'=>1000,'dev_only'=>true,'order'=>30],
        'defaults.manage' => ['label'=>'Manage client / store currency and timezone defaults','category'=>'System','min_loa'=>1000,'dev_only'=>true,'order'=>40],
        'clients.manage' => ['label'=>'Add / edit clients','category'=>'System','min_loa'=>1000,'dev_only'=>true,'order'=>50],
        'client_context.switch' => ['label'=>'Switch global working client','category'=>'System','min_loa'=>1000,'dev_only'=>true,'order'=>60],
        'dev.status' => ['label'=>'View DEV diagnostics','category'=>'System','min_loa'=>1000,'dev_only'=>true,'order'=>70],
        'password.change_own' => ['label'=>'Change own password','category'=>'Account','min_loa'=>1,'dev_only'=>false,'order'=>10],
    ];
}

function merd_portal_permission_default_level(string $key): int
{
    $catalog = merd_portal_permission_catalog();
    return isset($catalog[$key]) ? (int)$catalog[$key]['min_loa'] : 1000;
}

function merd_portal_permission_is_dev_only(string $key): bool
{
    $catalog = merd_portal_permission_catalog();
    return !empty($catalog[$key]['dev_only']);
}

/** Widget -> [visibility permission, underlying data permission]. */
function merd_portal_dashboard_widget_permissions(): array
{
    return [
        'my_shift' => ['dashboard.widget.my_shift','timesheets.view_own'],
        'my_disputes' => ['dashboard.widget.my_disputes','disputes.view_own'],
        'recent_attendance' => ['dashboard.widget.recent_attendance','timesheets.view_own'],
        'working_now_count' => ['dashboard.widget.working_now_count','workforce.view'],
        'working_now' => ['dashboard.widget.working_now','workforce.view'],
        'workforce_by_store' => ['dashboard.widget.workforce_by_store','workforce.view'],
        'active_employees' => ['dashboard.widget.active_employees','workforce.view'],
        'pending_disputes' => ['dashboard.widget.pending_disputes','disputes.review'],
        'sync_attention' => ['dashboard.widget.sync_attention','system.sync_status'],
        'store_cash_position' => ['dashboard.widget.store_cash_position','finance.management_summary'],
        'cash_mix' => ['dashboard.widget.cash_mix','finance.management_summary'],
        'today_sales_by_store' => ['dashboard.widget.today_sales_by_store','finance.management_summary'],
    ];
}
