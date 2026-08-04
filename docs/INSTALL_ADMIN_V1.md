# MerdPOS Admin v1 installation

1. Back up the live database and server `/admin/` directory if it exists.
2. Import `backend/sql/011_admin_platform.sql` in phpMyAdmin, after `010_retail_platform.sql`.
3. Upload the entire local `backend/admin/` folder to the server as `/admin/`.
4. Do not upload or overwrite `backend/api/config.php` or `.deployed_version`.
5. Mark your employee record as ADMIN using phpMyAdmin:

```sql
UPDATE employees
SET role_name='ADMIN', employee_type='ADMIN'
WHERE client_id=YOUR_CLIENT_ID AND user_id='YOUR_USER_ID';
```

6. Open `https://app.merdpos.com/admin/` and sign in with Client ID, numeric User ID and PIN.

## Included functions

- ADMIN-only employee login and secure PHP sessions
- Dashboard metrics
- Employees and ADMIN role creation
- Stores
- Categories
- Products and barcodes
- Store inventory, reorder levels and store prices
- Suppliers
- Purchase orders and receiving into stock
- Sales browser
- Store reports
- Devices browser
- Audit logs
- Settings/status page

## Important schema assumption

The existing project records support these employee fields: `id`, `client_id`, `store_id`, `full_name`, `user_id`, `login_password`, `pin_code`, `employee_type`, `role_name`, `hourly_rate`, and `status`. The stores screen detects `store_name` or `name` automatically. If the production schema differs, do not guess—capture the phpMyAdmin error and adjust the package against the real schema.
