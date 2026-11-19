# AGENTS.md — menam-workflow

Instructions for coding agents (Codex and others). **Read `CLAUDE.md` too** — it holds the full project rules
(stack, Laravel structure, workflow/approval rules, ERP privacy policy, restricted files). This file adds the
operational rules learned from production and the branch lifecycle every agent must follow.

Communicate with the user in **Thai** unless asked otherwise.

---

## 1. Production is NOT the same as git

- Production: `M:\htdocs\menam-online` (mapped drive; on the server it is `E:\xampp\htdocs\menam-online`).
  There is no auto-deploy — files are copied up by hand.
- On 2026-11-19 production was found far ahead of git (~170 files only on prod). They were synced back in
  PRs #39–#49. Treat production as a possible source of truth: **before deploying any file from git, compare
  it with the production copy** (hash / diff). A newer git file may still lack something production has.
- Never `cd M:` and never run `config:cache` / `route:cache` from the dev machine (paths get baked in → 500).
  Run artisan against prod only as `C:\xampp\php\php.exe M:\htdocs\menam-online\artisan ...`.
- Read-only comparisons of production are fine. **Writing to production only when the user explicitly asks.**

### Deploy procedure (when the user asks you to deploy)
1. Build a package in `docs/deploy-patches/<version>-<topic>/`: `files/` (built from the **current production
   file**, patched in place — never overwrite prod with a local copy), `manifest.json` (sha256 before/after),
   `README.md` (script, rollback, deploy log). See `v1.10.2-dp-inquiry-swal` as the template.
2. Check every production file still matches `before_sha256`; stop if not.
3. Backup to `M:\htdocs\menam-online\deploy-backups\<topic>-v<version>-<yyyyMMdd-HHmmss>\` + manifest.
4. Copy, then **set LastWriteTime to now** (Copy-Item keeps the old time → Blade keeps the old compiled view).
5. Verify `after_sha256`. If a JS file changed, bump its `?v=` in the Blade (it is a fixed string, not filemtime).
6. Every production update also updates `resources/views/home.blade.php` (อัปเดตล่าสุด + ประวัติเวอร์ชัน) and the
   default in `config/app.php`; the user edits `APP_VERSION` in prod `.env` and clears cache via `/adminweb/cache/clear`.
   Production has no `bootstrap/cache/config.php` (config changes are live).

## 2. Database

- **Never run migrations** (`migrate`, `migrate:fresh`, `db:wipe`, `db:seed` are blocked by `AppServiceProvider`).
  Schema changes are delivered as guarded SQL scripts for the user to run. No `DROP` / `DELETE` / `TRUNCATE` scripts.
- Local dev switches SQL Server with `DB_PROFILE=dev|prod` (+ `DB_PROFILE_DEV_DATABASE`, `DB_PROFILE_PROD_DATABASE`)
  then `php artisan config:clear`; check with `php artisan db:which --ping`. `menam_workflow_dev` is a full copy of prod.
- `phpunit.xml` does **not** use sqlite, so `php artisan test` on the dev machine hits a real database
  (blocked automatically when pointing at prod). Prefer the sandbox below.

## 3. Test before every merge

Run the whole Unit suite **before** merging, on the exact tree that will be merged:

```bash
git archive <branch-or-merge-tree> | tar -x -C <sandbox>
# vendor/autoload.php in the sandbox: require the main repo's vendor/autoload.php,
# then prepend an autoloader that maps App\, Tests\, Database\ to the sandbox folders
APP_ENV=testing APP_KEY=base64:... DB_CONNECTION=sqlite DB_DATABASE=:memory: \
SQLSRV_HOST=invalid.local PGSQLW_HOST=invalid.local ... \
php <main>/vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Unit
```
Also `php -l` changed files, `php artisan route:list`, and check every route action method exists.
Baseline on 2026-11-19: **344 tests OK**.

When syncing production code into git: decide direction per file (prod mtime vs `git log`, token diff ignoring
whitespace, trace the source commit with `git log -S`). A "closest blob" ancestor can be wrong; prefer
`merge-base(base, commit that contains the prod blob)` or replay the original feature commits.

## 4. Branch lifecycle — every branch must END

Branches were left open for months (190 local branches on 2026-11-19, see `docs/branch-cleanup-2026-11-19.md`).
From now on:

1. **One task → one branch**, cut from the latest `origin/ai/menam-workflow`, named `codex/<topic>`.
   **Never stack a new branch on another unmerged branch.**
2. Commit per module; push and **open the PR the same day** the work is ready (draft PR if not finished).
3. A branch must end within **2 weeks** in exactly one of these ways:
   - **merged** via PR into `ai/menam-workflow`, or
   - **PR closed with a comment** saying why (superseded / dropped / moved to #NN).
4. **After it ends, delete the branch** — remote (`git push origin --delete <branch>` or the PR button) and local.
   If the history might be useful, keep a tag `archive/<branch>` instead of the branch.
5. **Code deployed to production must be in a PR the same day** and merged soon after. Never deploy from a branch
   that has no PR — that is how production drifted away from git.
6. No "backup" or "grouped" branches. Use tags (`backup/<date>`), not branches.
7. Before starting new work, run a quick check and report any branch older than 14 days without an open PR:
   ```bash
   git fetch --prune
   git for-each-ref --sort=committerdate --format='%(committerdate:short) %(refname:short)' refs/heads
   gh pr list --state open
   ```
8. Worktrees: remove a worktree when its branch ends. **Never `git worktree remove --force`** — on 2026-11-06 it
   deleted the main repo's `vendor` through a junction. Remove junctions first, then `git worktree remove`.

## 5. Git etiquette

- Commit, push, merge, or delete branches **only when the user asks**.
- The main working tree often has many uncommitted files; don't switch branches there. To commit to another
  branch without touching the working tree use a temporary index:
  `GIT_INDEX_FILE=<tmp> git read-tree <base>; git update-index --add --cacheinfo ...; git commit-tree ...; git update-ref ...`
- Never commit files under `docs/` blindly (`git add docs/`) — that folder also holds untracked Office files with
  credentials and temp files. Add docs files one by one.
