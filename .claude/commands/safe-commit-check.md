Read CLAUDE.md first.

Perform a safe pre-commit check.

Do not commit or push.

Check:
1. git status
2. git diff summary
3. risky files changed
4. .env or secrets accidentally touched
5. destructive commands/scripts
6. missing authorization
7. missing validation
8. SQL injection risk
9. workflow approval risk
10. manual test checklist

Return:
- Safe to commit? yes/no
- Critical blockers
- Recommended fixes
- Suggested commit message

Scope:
$ARGUMENTS