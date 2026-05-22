<?php
/**
 * genhash.php — Admin password hash generator
 * သုံးနည်း: http://localhost/noodlehaus/genhash.php ကိုသွားပါ
 * ပြီးရင် ဒီဖိုင်ကို server ပေါ်မှ ဖျက်ပစ်ပါ
 */
session_start();

$msg   = '';
$hash  = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass    = $_POST['pass']    ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $current = $_POST['current'] ?? '';

    if (strlen($pass) < 8) {
        $error = 'Password အနည်းဆုံး 8 လုံးရှိရမည်';
    } elseif ($pass !== $confirm) {
        $error = 'Password နှစ်ခု မတူညီပါ';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $msg  = 'Hash generated! admin.php ထဲ ADMIN_PASS_HASH ကို အောက်ပါ value နဲ့ အစားထိုးပါ';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Generate Admin Hash</title>
<style>
body{font-family:system-ui,sans-serif;background:#fdf6ec;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#fff;border-radius:16px;padding:2rem;max-width:480px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.1);border:1px solid #e2d5c3;}
h2{font-size:1.2rem;margin-bottom:1.2rem;color:#1a1209;}
label{display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;color:#1a1209;}
input{width:100%;border:1.5px solid #e2d5c3;border-radius:8px;padding:.6rem .9rem;font-size:.9rem;margin-bottom:.8rem;outline:none;font-family:inherit;}
input:focus{border-color:#1a1209;}
button{width:100%;background:#1a1209;color:#fff;border:none;border-radius:8px;padding:.75rem;font-size:.95rem;cursor:pointer;font-family:inherit;font-weight:600;}
button:hover{background:#333;}
.hash-box{background:#1a1209;color:#f0a500;border-radius:8px;padding:1rem;margin-top:1rem;font-family:monospace;font-size:.8rem;word-break:break-all;line-height:1.6;}
.msg{background:#d1fae5;color:#065f46;border-radius:8px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.85rem;}
.err{background:#fee2e2;color:#991b1b;border-radius:8px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.85rem;}
.step{background:#fef3c7;border-radius:8px;padding:.8rem 1rem;margin-top:1rem;font-size:.82rem;color:#92400e;line-height:1.7;}
.copy-btn{margin-top:.6rem;background:#f0a500;color:#000;border:none;border-radius:6px;padding:.4rem .9rem;font-size:.8rem;font-weight:600;cursor:pointer;font-family:inherit;}
.copy-btn:hover{background:#d4930a;}
.warn{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.7rem 1rem;margin-top:1rem;font-size:.8rem;color:#991b1b;}
</style>
</head>
<body>
<div class="box">
  <h2>🔐 Admin Password Hash Generator</h2>

  <?php if ($error): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($msg): ?>
    <div class="msg">✓ <?= $msg ?></div>
    <div class="hash-box" id="hashval"><?= htmlspecialchars($hash) ?></div>
    <button class="copy-btn" onclick="navigator.clipboard.writeText(document.getElementById('hashval').textContent).then(()=>this.textContent='Copied!')">Copy Hash</button>
    <div class="step">
      <strong>နောက်ထပ်လုပ်ရမည့်အဆင့်:</strong><br>
      1. admin.php ကိုဖွင့်ပါ<br>
      2. <code>define('ADMIN_PASS_HASH', '');</code> ကိုရှာပါ<br>
      3. <code>define('ADMIN_PASS_HASH', '<strong>ဒီနေရာ hash ထည့်</strong>');</code> လို့ပြောင်းပါ<br>
      4. Save လုပ်ပါ
    </div>
  <?php else: ?>
    <form method="POST">
      <label>Password အသစ်</label>
      <input type="password" name="pass" placeholder="အနည်းဆုံး 8 လုံး" required minlength="8">
      <label>Password အသစ် ထပ်ရိုက်</label>
      <input type="password" name="confirm" placeholder="ထပ်ရိုက်ပါ" required>
      <button type="submit">Hash Generate လုပ်မည်</button>
    </form>
  <?php endif; ?>

  <div class="warn">
    ⚠️ Hash copy ပြီးနောက် ဒီဖိုင် (<code>genhash.php</code>) ကို server မှ ဖျက်ပစ်ပါ
  </div>
</div>
</body>
</html>
