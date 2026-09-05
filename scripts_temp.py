import re

def resolve_conflicts(content, strategy_fn):
    pattern = re.compile(
        r'<<<<<<< Updated upstream\n(.*?)=======\n(.*?)>>>>>>> Stashed changes\n',
        re.DOTALL
    )
    def replacer(m):
        head = m.group(1)
        stash = m.group(2)
        return strategy_fn(head, stash)
    return pattern.sub(replacer, content)

# ─── 6. LawSearchTest.php ───────────────────────────────────────────────────
with open('apps/app-laravel/tests/Feature/LawSearchTest.php', encoding='utf-8') as f:
    src = f.read()

conflict_count = 0
def strategy_lawsearch(head, stash):
    global conflict_count
    conflict_count += 1
    
    if conflict_count == 1:
        # HEAD uses 'กฎหมายล่าสุด', stash uses 'กฎหมายใหม่' — keep HEAD (newer)
        return head
    elif conflict_count == 2:
        # HEAD: falls_back test name + กฎหมายล่าสุด filter. Keep HEAD.
        return head
    
    return head

resolved = resolve_conflicts(src, strategy_lawsearch)
print(f"LawSearchTest conflict markers remaining: {resolved.count('<<<<<<<')}")

with open('apps/app-laravel/tests/Feature/LawSearchTest.php', 'w', encoding='utf-8') as f:
    f.write(resolved)
print("Written LawSearchTest.php")

# ─── 7. DocumentExportServiceTest.php ────────────────────────────────────────
with open('apps/app-laravel/tests/Unit/DocumentExportServiceTest.php', encoding='utf-8') as f:
    src = f.read()

conflict_count2 = 0
def strategy_export_test(head, stash):
    global conflict_count2
    conflict_count2 += 1
    
    if conflict_count2 == 1:
        # HEAD: correct assertion (TH Sarabun New) + rowspan test method. Keep HEAD.
        return head
    elif conflict_count2 == 2:
        # HEAD adds test_inline_image_keeps_position. Keep HEAD.
        return head
    elif conflict_count2 == 3:
        # HEAD adds blank lines and line spacing tests. Keep HEAD.
        return head
    
    return head

resolved2 = resolve_conflicts(src, strategy_export_test)
print(f"DocumentExportServiceTest conflict markers remaining: {resolved2.count('<<<<<<<')}")
print(f"Test methods count: {resolved2.count('public function test_')}")

with open('apps/app-laravel/tests/Unit/DocumentExportServiceTest.php', 'w', encoding='utf-8') as f:
    f.write(resolved2)
print("Written DocumentExportServiceTest.php")
