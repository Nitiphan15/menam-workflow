<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>กำลังเข้าสู่ Request Form System</title>
</head>
<body>
    <form id="request-form-sso" method="POST" action="{{ $destination }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <noscript>
            <p>กรุณากดปุ่มเพื่อเข้าสู่ Request Form System</p>
            <button type="submit">ดำเนินการต่อ</button>
        </noscript>
    </form>
    <script>
        document.getElementById('request-form-sso').submit();
    </script>
</body>
</html>
