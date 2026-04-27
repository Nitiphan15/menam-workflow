<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>Standard Packaging – Code Package</title>
    <style>
        :root {
            --bg-page: #f4f5fb;
            --bg-card: #ffffff;
            --bg-header: #ffb74d;
            --bg-subheader: #ffe0b2;
            --bg-row-alt: #fff8e1;
            --border-soft: #e0e0e0;
            --text-main: #263238;
            --text-muted: #757575;
            --accent: #ff9800;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 24px;
            background: radial-gradient(circle at top left, #fff3e0 0, #f4f5fb 40%, #edf1fb 100%);
            color: var(--text-main);
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-title span.badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(255, 152, 0, 0.12);
            color: var(--accent);
            border: 1px solid rgba(255, 152, 0, 0.3);
        }

        .page-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .card {
            background: var(--bg-card);
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.10);
            padding: 18px 18px 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border-soft);
            margin-top: 6px;
        }

        table.pack-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
            font-size: 13px;
        }

        thead {
            background: linear-gradient(90deg, #ffb74d, #ffa726);
            color: #263238;
        }

        thead th {
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            border-right: 1px solid rgba(255, 255, 255, 0.35);
        }

        thead th:last-child {
            border-right: none;
        }

        thead tr:nth-child(2) th {
            background: var(--bg-subheader);
            font-weight: 500;
            border-top: 1px solid rgba(255, 255, 255, 0.6);
        }

        tbody tr {
            background: #ffffff;
            transition: background 0.18s ease, transform 0.12s ease;
        }

        tbody tr:nth-child(even) {
            background: var(--bg-row-alt);
        }

        tbody tr:hover {
            background: #fffde7;
            transform: translateY(-1px);
        }

        td {
            padding: 6px 10px;
            border-top: 1px solid var(--border-soft);
            white-space: nowrap;
        }

        td.code-cell {
            font-weight: 600;
            color: #f57c00;
        }

        td.text-right {
            text-align: right;
        }

        td.text-muted {
            color: var(--text-muted);
        }

        .chip-product {
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 11px;
            background: rgba(3, 155, 229, 0.09);
            color: #0277bd;
            border: 1px solid rgba(2, 119, 189, 0.18);
        }

        .footer-note {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
    </style>
</head>

<body>

    <div class="page-title">
        Standard Packaging – Code Package
        <span class="badge">Planning View</span>
    </div>
    <div class="page-subtitle">
        Mapping product → standard packaging → package code with weight usage (KG / 1 PACKAGE).
    </div>

    <div class="card">
        <div class="table-wrapper">
            <table class="pack-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Standard Packaging</th>
                        <th>Package Code</th>
                        <th>Usage (KG / 1 PACKAGE)</th>
                        <th>Order Qty (KG)</th>
                        <th>Required Packages</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>19</td>
                        <td><span class="chip-product">MIG</span></td>
                        <td>PS-001/MB-003</td>
                        <td class="code-cell">SP-001</td>
                        <td class="text-right">12.5</td>
                        <td class="text-right">21,260</td>
                        <td class="text-right">1,701</td>
                    </tr>
                    <tr>
                        <td>20</td>
                        <td><span class="chip-product">MIG</span></td>
                        <td>PS-001/MB-003</td>
                        <td class="code-cell">BO-018</td>
                        <td class="text-right">12.5</td>
                        <td class="text-right">21,260</td>
                        <td class="text-right">1,701</td>
                    </tr>
                    <tr>
                        <td>21</td>
                        <td><span class="chip-product">MIG</span></td>
                        <td>PS-001/MB-003</td>
                        <td class="code-cell">PB-002</td>
                        <td class="text-right">1,000</td>
                        <td class="text-right">21,260</td>
                        <td class="text-right">22</td>
                    </tr>
                    <tr>
                        <td>22</td>
                        <td><span class="chip-product">MIG</span></td>
                        <td>PS-001/MB-004</td>
                        <td class="code-cell">SP-001</td>
                        <td class="text-right">12.5</td>
                        <td class="text-right">15,000</td>
                        <td class="text-right">1,200</td>
                    </tr>
                    <tr>
                        <td>23</td>
                        <td><span class="chip-product">MIG</span></td>
                        <td>PS-001/MB-006</td>
                        <td class="code-cell">BO-007</td>
                        <td class="text-right">12.5</td>
                        <td class="text-right">8,500</td>
                        <td class="text-right">680</td>
                    </tr>
                    <!-- เพิ่ม row อื่นได้ตามต้องการ -->
                </tbody>
            </table>
        </div>

        <div class="footer-note">
            <span>• ตัวเลขเป็นตัวอย่าง mockup สำหรับหน้าวางแผนบรรจุภัณฑ์</span>
            <span>• ค่า <b>Required Packages</b> = ORDER ÷ Usage (KG/1 PACKAGE)</span>
        </div>
    </div>

</body>

</html>
