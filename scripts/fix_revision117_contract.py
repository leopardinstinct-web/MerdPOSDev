from pathlib import Path

path = Path('namecheap_beta_live/backend/cli/validate_beta_runtime_contract.php')
text = path.read_text(encoding='utf-8-sig')


def replace_once(old: str, new: str, label: str) -> None:
    global text
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly 1 match, found {count}')
    text = text.replace(old, new, 1)


replace_once(
    "beta_contract_require_contains($accountMenuJs, 'rail-studio-metrics', 'DevStudio unresolved inbox account counters', $errors);",
    "beta_contract_require_contains($accountMenuJs, 'rail-studio-copy-metric', 'DevStudio ChatGPT-copy account control', $errors);\n"
    "beta_contract_require_absent($accountMenuJs, 'data-studio-metric=\\\"requests\\\"', 'retired DevStudio implementation-request counter', $errors);\n"
    "beta_contract_require_absent($accountMenuJs, 'data-studio-metric=\\\"patches\\\"', 'retired DevStudio global-patch counter', $errors);",
    'copy-only DevStudio account contract',
)

replace_once(
    "beta_contract_require_contains($accountMenuJs, 'assets/vendor/devstudio/create_new_folder_24dp.svg', 'DevStudio supplied folder counter icon', $errors);",
    "beta_contract_require_absent($accountMenuJs, 'assets/vendor/devstudio/create_new_folder_24dp.svg', 'retired DevStudio folder counter icon', $errors);",
    'retired DevStudio folder counter icon contract',
)

replace_once(
    "beta_contract_require_contains($accountMenuJs, 'merdpos-uistudio-patches', 'DevStudio account counter event bridge', $errors);",
    "beta_contract_require_contains($accountMenuJs, 'merdpos-uistudio-patches', 'DevStudio ChatGPT-copy count event bridge', $errors);",
    'DevStudio copy count event label',
)

path.write_text(text, encoding='utf-8')
