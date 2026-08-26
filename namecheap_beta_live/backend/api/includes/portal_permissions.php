<?php
declare(strict_types=1);

/**
 * Central MERDPOS portal permission catalogue.
 *
 * - min_loa is the default configurable threshold for delegable permissions.
 * - dev_only permissions are never delegable, regardless of LOA or DB values.
 * - category/order are presentation metadata used by the DEV Roles screen.
 */
function merd_portal_permission_catalog(): array
{
    return [
        'dashboard.view' => ['label'=>'View dashboard','category'=>'Dashboard','min_loa'=>1,'dev_only'=>false,'order'=>10],
        'dashboard.configure' => ['label'=>'Configure role dashboards','category'=>'Dashboard','min_loa'=>1000,'dev_only'=>true,'order'=>20],

        'attendance.scan' => ['label'=>'Use QR attendance','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>10],
        'timesheets.view_own' => ['label'=>'View own timesheet','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>20],
        'timesheets.view_all' => ['label'=>'View all employee timesheets','category'=>'Attendance','min_loa'=>50,'dev_only'=>false,'order'=>30],
        'timesheets.view_pay' => ['label'=>'View payroll / wage values','category'=>'Attendance','min_loa'=>50,'dev_only'=>false,'order'=>40],
        'disputes.view_own' => ['label'=>'View own disputes','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>50],
        'disputes.submit_own' => ['label'=>'Submit own disputes','category'=>'Attendance','min_loa'=>1,'dev_only'=>false,'order'=>60],
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

        'finance.view' => ['label'=>'View financial statements','category'=>'Finance','min_loa'=>50,'dev_only'=>false,'order'=>10],
        'finance.submit' => ['label'=>'Submit register / petty cash entries','category'=>'Finance','min_loa'=>50,'dev_only'=>false,'order'=>20],

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

/** Widget -> permission boundary. Data scope is still enforced by its endpoint. */
function merd_portal_dashboard_widget_permissions(): array
{
    return [
        'my_shift' => 'timesheets.view_own',
        'my_disputes' => 'disputes.view_own',
        'recent_attendance' => 'timesheets.view_own',
        'working_now_count' => 'workforce.view',
        'working_now' => 'workforce.view',
        'workforce_by_store' => 'workforce.view',
        'active_employees' => 'workforce.view',
        'pending_disputes' => 'disputes.review',
        'sync_attention' => 'system.sync_status',
        'store_cash_position' => 'finance.view',
        'cash_mix' => 'finance.view',
        'today_sales_by_store' => 'finance.view',
    ];
}
