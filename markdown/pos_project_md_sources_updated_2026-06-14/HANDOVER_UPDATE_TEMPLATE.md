# Handover Update Template

Use this at the end of any heavy coding/debugging chat.

```text
Update the project handover files for the next chat.

Include:
1. Current app status.
2. Latest working files.
3. Files modified in this chat.
4. Exact API versions/endpoints used.
5. Bugs fixed.
6. Bugs still open.
7. Test results.
8. Any files that must not be overwritten.
9. The exact next step.
10. Any assumptions that were made.
11. Whether the connected Google Drive source files were inspected.
12. Whether the latest replacement files were provided as downloads.

Also update:
- PROJECT_CONTEXT.md
- CHANGELOG.md
- BUGS_AND_FIXES.md
- FILE_MANIFEST.md if needed
- API_CONTRACT.md if APIs changed
```

## Current Source Locations To Preserve

```text
merdpos_staff/lib/main.dart
merdpos_staff/lib/db/local_db.dart
backend/api/get_timesheet.php
backend/api/config.php
```
