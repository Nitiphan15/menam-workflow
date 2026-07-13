# Offline vendor assets

Copy this whole folder to the production public folder:

`C:\xampp\htdocs\menam-workflow\public\vendor`

Production target:

`M:\htdocs\menam-online\public\vendor`

Also copy these updated Blade files to production:

`resources\views\layouts\layout.blade.php`
`resources\views\formpa\dashboard.blade.php`
`resources\views\formwocr\originator2.blade.php`

Also copy this local layout stylesheet:

`public\css\layout-base.css`

The main layout now loads these local files instead of CDN URLs:

- `vendor/bootstrap/css/bootstrap.min.css`
- `vendor/bootstrap/js/bootstrap.bundle.min.js`
- `vendor/fontawesome/css/all.min.css`
- `vendor/fontawesome/webfonts/*`
- `vendor/flatpickr/flatpickr.min.css`
- `vendor/flatpickr/flatpickr.min.js`
- `vendor/tom-select/css/tom-select.bootstrap5.min.css`
- `vendor/tom-select/js/tom-select.complete.min.js`
- `vendor/frappe-gantt/frappe-gantt.css`
- `vendor/frappe-gantt/frappe-gantt.min.js`
- `vendor/dhtmlxgantt/dhtmlxgantt.css`
- `vendor/dhtmlxgantt/dhtmlxgantt.js`
- `vendor/dhtmlxgantt/fonts/*`
- `vendor/jquery/jquery-3.6.0.min.js`
- `vendor/sweetalert2/sweetalert2.min.js`
- `vendor/chartjs/chart.umd.min.js`
- `vendor/chartjs-plugin-datalabels/chartjs-plugin-datalabels.min.js`

After copying to production, clear Laravel view cache:

`php artisan view:clear`
