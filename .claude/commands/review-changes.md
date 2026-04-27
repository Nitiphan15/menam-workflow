Read CLAUDE.md first.

Use the code-reviewer agent to review the current changed files.

Also use security-reviewer if routes, permissions, workflow, uploads, SQL, email, or sensitive data are involved.

Review:
1. Logic correctness
2. Authorization
3. Validation
4. SQL safety
5. Workflow safety
6. Performance
7. UI/Blade risks
8. Regression risks
9. Test cases

Do not edit files. Only review and suggest fixes.

Scope:
$ARGUMENTS