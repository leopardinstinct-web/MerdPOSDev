# MERDPOS UI Studio

UI Studio is a DEV-only visual preview tool for fast Beta UI iteration.

## What it does

- Opens on top of the currently rendered authenticated MERDPOS page.
- Lets DEV select a rendered element without triggering its normal click action.
- Previews background/text colors from the five-color MERDPOS master palette.
- Previews padding, margin, gap, radius, width and font-size changes.
- Can hide an element or move it before, after, or inside another rendered element.
- Accumulates multiple edits into a human-readable and JSON change-set.
- Keeps the draft in browser local storage so a refresh does not lose the current preview.

## Safety boundary

UI Studio never writes source files, calls MERDPOS APIs, changes database state, or affects another user's browser. The tool is gated by the actual `is_dev` identity flag and labels its work `PREVIEW ONLY`.

A UI Studio change-set is design intent. It becomes part of MERDPOS only after the canonical owner files are edited, committed to `namecheap-beta-live`, deployed through the normal Namecheap process, and runtime-verified.

## Workflow

1. Open **DEV → UI Studio → Open UI Studio**.
2. Choose **Select element**, then click the UI element to change.
3. Make one or more preview changes.
4. For structural movement, choose **Move before…**, **Move after…**, or **Move inside…**, then click the destination.
5. Repeat across the current MERDPOS interface; the change-set grows as you work.
6. Use **Copy change-set**, or leave UI Studio open and ask ChatGPT to read the open draft through the browser connection.
7. Ask ChatGPT to apply the draft. Normal source/deploy/verify gates still apply.
