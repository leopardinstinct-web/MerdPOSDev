# New Chat Starter Prompt

Copy/paste this at the start of a new chat inside the POS LATEST project.

```text
This is the continuation of my Android/POS project.

Before doing anything:
1. Read PROJECT_CONTEXT.md.
2. Read FILE_MANIFEST.md.
3. Use the connected Google Drive source folder and latest source files only.
4. Do not guess previous code.
5. If a required file is missing or inaccessible, tell me exactly which file is missing.
6. When making changes, give complete replacement files as downloads, not snippets.
7. Keep a changelog of what changed.
8. Preserve API Timesheet v2 and payroll/timesheet logic unless I explicitly ask to change it.
9. USER_ID and PASSWORD are numeric. Do not assume email login.
10. Before code changes, inspect:
   - merdpos_staff/lib/main.dart
   - backend/api/get_timesheet.php
   - backend/api/config.php

Now continue from the latest pending task.
```
