# MerdPOS Retail Upgrade v1 — Install and test

This ZIP is an overlay for the existing `C:\Dev\MerdPOSDev` repository. It preserves the existing modular login, setup, timesheet and employee files. It replaces `main.dart`, `home_page.dart`, `pos_side_rail.dart`, `theme.dart`, and `pubspec.yaml`, then adds the retail files.

## 1. Back up

```cmd
cd /d C:\Dev
xcopy /E /I /Y MerdPOSDev MerdPOSDev_backup_before_retail_v1
```

## 2. Copy the ZIP contents

Extract the ZIP. Copy these folders over the repo root:

```cmd
xcopy /E /I /Y merdpos_staff C:\Dev\MerdPOSDev\merdpos_staff
xcopy /E /I /Y backend C:\Dev\MerdPOSDev\backend
xcopy /E /I /Y docs C:\Dev\MerdPOSDev\docs
```

Do **not** replace or upload:

- `backend/api/config.php`
- `backend/api/.deployed_version`
- any `.env`
- `timesheet_portal/`

## 3. Database

In phpMyAdmin, select the MerdPOS database and run:

`backend/sql/010_retail_platform.sql`

## 4. Deploy the PHP endpoint

Upload only:

`backend/api/sync_retail.php` -> server `/api/sync_retail.php`

Do not overwrite server `config.php`.

## 5. Build locally

```cmd
cd /d C:\Dev\MerdPOSDev\merdpos_staff
flutter clean
flutter pub get
dart format lib
flutter analyze
flutter test
flutter build apk --release
```

APK output:

`build\app\outputs\flutter-apk\app-release.apk`

## 6. Functional test

1. Open the app and log in with the existing numeric USER_ID/PIN.
2. Home should replace Staff dashboard.
3. Sidebar should show Home, POS, Orders, Financials, Inventory.
4. POS: add seeded products and complete a Cash sale.
5. Orders: confirm the sale appears with Pending sync.
6. Inventory: confirm sold stock decreased; perform an adjustment.
7. Financials: confirm revenue, transaction count and margin.
8. Tap Sync. Confirm the order changes to synced after reopening Orders.
9. Open Time Sheet from the user menu and verify the existing API Timesheet v2 flow still works.

## Important

The local retail catalogue starts with six demonstration products. Replace/import real products after the application builds successfully. The retail data uses a separate SQLite database named `merdpos_retail.db`, so it does not alter the existing employee-log database.
