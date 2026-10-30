# Wire Rod Dead Stock background export

## Database

Run manually in SQL Server, in order:

1. `08_add_wr_ds_customer_additions.sql`
2. `09_add_wr_ds_background_exports.sql`
3. `05_verify_wr_ds_schema.sql` (read-only verification)

The scripts are additive and do not assign users or remove data.

## Worker

Keep one worker running on the application server:

```powershell
C:\xampp\php\php.exe artisan queue:work wr-deadstock-export --queue=wr-deadstock-export --tries=2 --timeout=900
```

Use Task Scheduler or the existing process supervisor. Restart the worker after application deployment:

```powershell
C:\xampp\php\php.exe artisan queue:restart
```

Generated files are private under `storage/app/wr-deadstock-exports`; download always checks the requesting user.
Export request history is retained. No automatic deletion is included.
