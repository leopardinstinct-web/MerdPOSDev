from pathlib import Path

root=Path('.')
js_path=root/'namecheap_beta_live/timesheet_portal/assets/ui-studio-structure.js'
contract_path=root/'namecheap_beta_live/backend/cli/validate_beta_runtime_contract.php'
evidence_path=root/'.ai/evidence/devstudio-divi-canvas-study-20260903.md'

js=js_path.read_text()
old="    const arm=()=>{state.canvasInteraction=true;};"
new="    const arm=()=>{state.canvasInteraction=true;clearTimeout(state.refreshTimer);};"
assert old in js
js=js.replace(old,new,1)
old="  function refresh(immediate=false){clearTimeout(state.refreshTimer);const run=()=>{if(state.open)render();};if(immediate)run();else state.refreshTimer=setTimeout(run,80);}"
new="  const interactionActive=()=>!!(state.openActionsKey||(chooser&&!chooser.hidden)||(modulePicker&&!modulePicker.hidden)||state.canvasInteraction);\n  function refresh(immediate=false){clearTimeout(state.refreshTimer);const run=()=>{if(!state.open)return;if(interactionActive()){state.refreshTimer=setTimeout(run,80);return;}render();};if(immediate&&!interactionActive())run();else state.refreshTimer=setTimeout(run,80);}"
assert old in js
js=js.replace(old,new,1)
js_path.write_text(js)

contract=contract_path.read_text()
needle="beta_contract_require_contains($uiStudioStructureJs, \"(modulePicker&&!modulePicker.hidden)||state.canvasInteraction\", 'Structure active-interaction mutation guard', $errors);"
assert needle in contract
addition=needle+"\nbeta_contract_require_contains($uiStudioStructureJs, 'const interactionActive=', 'Structure queued-refresh interaction guard', $errors);\nbeta_contract_require_contains($uiStudioStructureJs, 'clearTimeout(state.refreshTimer);', 'Structure canvas cancels stale queued refresh', $errors);"
contract=contract.replace(needle,addition,1)
contract_path.write_text(contract)

evidence=evidence_path.read_text().rstrip()
evidence += "\n\n## Interaction scheduling finding\n\nThe canvas regression exposed a second-order race: guarding the MutationObserver is insufficient if a refresh timer was queued before pointerdown. Canvas pointerdown now cancels stale queued refresh work, and the refresh scheduler itself re-checks all active Structure interaction state before rendering. A pending timer may defer, but it may not replace controls underneath an active pointer interaction.\n"
evidence_path.write_text(evidence.rstrip()+"\n")
