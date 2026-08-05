# Handover Update Template

Use at the end of any heavy coding/debugging chat to refresh the pack.

text
Update the project handover files for the next chat.

Report:
1. Current app status (and update the CURRENT STATE block).
2. Latest working files (paths on GitHub).
3. Files modified in this chat.
4. Exact API versions/endpoints used (and update API_CONTRACT.md if changed).
5. Bugs fixed.
6. Bugs still open.
7. Test results.
8. Files that must not be overwritten.
   - backend/api/config.php
   - backend/api/.deployed_version
   - server api/config.php
9. The exact next step.
10. Assumptions made.

Confirm (security + process):
11. Were the real latest GitHub files inspected (not guessed)?
12. Prepared statements used and inputs validated?
13. No secrets committed; passwords hashed?
14. UI used Blue Ice tokens only (no invented colors/fonts)?
15. Complete replacement file(s) provided as downloads?

Full-Stack Delivery Check (for new features):
16. ( ) DB Schema changes provided as a raw `.sql` migration script.
17. ( ) PHP API endpoints updated (or created) as complete `.php` files.
18. ( ) Flutter UI screens provided as complete replacement `.dart` files.
19. ( ) `API_CONTRACT.md` updated to reflect the new endpoint/parameters.

Also update:
- PROJECT_CONTEXT.md (CURRENT STATE block)
- CHANGELOG.md (append a dated entry)
- BUGS_AND_FIXES.md
- API_CONTRACT.md (if APIs changed)
- FILE_MANIFEST.md (if files added/removed)
- SECURITY.md / DESIGN_TOKENS.md (if practices/tokens changed)
Source locations to preserve (never guess)
text
merdpos_staff/lib/main.dart
merdpos_staff/lib/db/local_db.dart
backend/api/get_timesheet.php
backend/api/config.php
backend/api/.deployed_version
Source of truth: GitHub (leopardinstinct-web/MerdPOSDev). Drive = backup only.

Current source reminder
text
Documentation was reconciled against repository commit 29de6f4.
Always record the actual branch and HEAD at handover.
Production deployment state/marker requires separate approved verification.
Never commit config.php, .deployed_version, APK build output, .env, or .dart_tool/.
Never inspect or modify timesheet_portal/.
