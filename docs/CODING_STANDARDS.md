# Coding Standards

## Laravel Structure

Preferred responsibility split:

| Layer | Responsibility |
|---|---|
| Controller | Request handling and response |
| Service | Business logic |
| Model | Relationships and simple scopes |
| Request | Validation |
| Blade | Display only |
| Command | Scheduled/background jobs |

## General Rules

- Keep controllers readable.
- Move complex logic into services.
- Do not put heavy logic inside Blade.
- Validate user input.
- Check authorization server-side.
- Use transactions for multi-table updates.
- Preserve route names and Blade variable names unless required.
- Avoid unrelated refactor.
- Avoid duplicate business logic.
- Prefer small safe changes.

## Naming

- Use clear method names.
- Use existing project naming style.
- Do not rename files/classes unless necessary.
- Keep route names stable when possible.

## Blade

- Keep UI clean and practical.
- Preserve form IDs, route names, and JS hooks.
- Tables should be readable.
- Modals should scroll when content is long.
- Use Thai labels when UI is Thai.
- Avoid heavy data processing in Blade.

## SQL

- Use query builder or parameter bindings.
- Avoid unsafe raw string concatenation.
- Add comments only for complex business logic.
- Check performance for heavy ERP queries.

## Security

- Never expose .env secrets.
- Never hardcode credentials.
- Check permissions in controller/service.
- Validate uploads.
- Avoid IDOR by checking ownership/department/division access.

## After Changes

Always provide:
- Files changed
- Summary
- Test steps
- Warnings/assumptions