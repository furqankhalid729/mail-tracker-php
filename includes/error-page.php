<?php
/** Friendly error page. Technical details are only logged, except in APP_ENV=local. */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Something went wrong</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;color:#0f172a;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:32px 36px;max-width:440px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05)}
h1{font-size:20px;margin:0 0 8px}p{color:#64748b;margin:0 0 20px}a{color:#4f46e5;text-decoration:none;font-weight:600}
pre{text-align:left;font-size:12px;background:#f1f5f9;padding:12px;border-radius:8px;white-space:pre-wrap;color:#b91c1c}
</style>
</head>
<body>
<div class="box">
    <h1>Something went wrong.</h1>
    <p>Please try again. If the problem continues, contact your administrator.</p>
    <?php if (!empty($detail)): ?><pre><?= htmlspecialchars($detail, ENT_QUOTES) ?></pre><?php endif; ?>
    <a href="javascript:history.back()">Go back</a>
</div>
</body>
</html>
