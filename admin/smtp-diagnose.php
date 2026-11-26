<?php
/**
 * admin/smtp-diagnose.php — ONE-TIME SMTP diagnostic. DELETE AFTER USE.
 *
 * Super-admin only. Sends test mail ONLY to the logged-in admin's own address.
 * Shows the real PHPMailer/SMTP conversation (credentials redacted) so we can
 * see why sendMail() fails silently.
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
require_once __DIR__ . '/../includes/ops-admin-helpers.php';
if (!function_exists('isSuperAdmin') || !isSuperAdmin()) { http_response_code(403); exit('Super admin only.'); }

$me = currentUser();
$to = $me['email'] ?? '';

function diag_redact(string $line): string {
    // PHPMailer logs AUTH credentials as base64 — never show them.
    if (preg_match('#CLIENT -> SERVER:\s*[A-Za-z0-9+/=]{12,}\s*$#', $line)) return 'CLIENT -> SERVER: [credentials redacted]';
    if (preg_match('#AUTH (PLAIN|LOGIN|XOAUTH2)\s+[A-Za-z0-9+/=]{8,}#i', $line)) return preg_replace('#(AUTH (?:PLAIN|LOGIN|XOAUTH2)\s+)[A-Za-z0-9+/=]+#i', '$1[redacted]', $line);
    if (defined('SMTP_PASS') && SMTP_PASS !== '' && strpos($line, SMTP_PASS) !== false) return str_replace(SMTP_PASS, '[redacted]', $line);
    return $line;
}

$results = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $ran = true;

    // 1. Config sanity
    $cfg = [];
    foreach (['SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_FROM_NAME','SITE_EMAIL'] as $k) {
        $cfg[$k] = defined($k) ? (string)constant($k) : 'NOT DEFINED';
    }
    $cfg['SMTP_PASS'] = !defined('SMTP_PASS') ? 'NOT DEFINED' : (SMTP_PASS === '' ? 'EMPTY' : 'set (' . strlen(SMTP_PASS) . ' chars)');
    if (defined('SMTP_PASS') && SMTP_PASS !== '' && (SMTP_PASS !== trim(SMTP_PASS))) {
        $cfg['SMTP_PASS'] .= ' ⚠ has leading/trailing whitespace';
    }
    $results['config'] = $cfg;

    // 2. PHPMailer present?
    $pmPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
    $results['phpmailer'] = file_exists($pmPath . 'PHPMailer.php');

    // 3. Raw TCP reachability (catches host firewalls blocking outbound SMTP)
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    $results['dns'] = $host ? (gethostbyname($host) !== $host ? gethostbyname($host) : 'DNS lookup FAILED') : 'no host';
    $results['tcp'] = [];
    foreach ([587, 465, 25] as $port) {
        $t = microtime(true);
        $errno = 0; $errstr = '';
        $fp = $host ? @fsockopen($host, $port, $errno, $errstr, 8) : false;
        $results['tcp'][$port] = $fp
            ? 'OPEN (' . round((microtime(true)-$t)*1000) . ' ms)'
            : "BLOCKED/FAILED — {$errstr} ({$errno})";
        if ($fp) fclose($fp);
    }

    // 4. Authenticated send via PHPMailer with full debug transcript
    $results['smtp_debug'] = [];
    $results['smtp_error'] = null;
    $results['smtp_ok']    = false;
    if ($results['phpmailer'] && $to) {
        require_once $pmPath . 'Exception.php';
        require_once $pmPath . 'PHPMailer.php';
        require_once $pmPath . 'SMTP.php';
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
            $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
            $mail->Timeout    = 15;
            $mail->SMTPDebug  = 2;
            $mail->Debugoutput = function ($str, $level) use (&$results) {
                foreach (preg_split('/\r?\n/', trim($str)) as $l) {
                    if ($l !== '') $results['smtp_debug'][] = diag_redact($l);
                }
            };
            $mail->setFrom(SMTP_USER, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '237Biz');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'SMTP diagnostic — 237Biz ' . date('H:i:s');
            $mail->Body    = '<p>If you can read this, direct PHPMailer SMTP works.</p>';
            $mail->send();
            $results['smtp_ok'] = true;
        } catch (\Throwable $e) {
            $results['smtp_error'] = diag_redact($e->getMessage());
            if (isset($mail) && !empty($mail->ErrorInfo)) $results['smtp_error'] .= ' | ErrorInfo: ' . diag_redact($mail->ErrorInfo);
        }
    }

    // 5. The site's real sendMail() wrapper
    $results['wrapper_ok'] = null;
    $results['wrapper_error'] = null;
    if (function_exists('sendMail') && $to) {
        try {
            $results['wrapper_ok'] = (bool) sendMail($to, 'sendMail() wrapper test — 237Biz ' . date('H:i:s'), '<p>Sent through the site\'s own sendMail().</p>');
        } catch (\Throwable $e) {
            $results['wrapper_ok'] = false;
            $results['wrapper_error'] = diag_redact($e->getMessage());
        }
    }
}
?><!doctype html><html><head><meta charset="utf-8"><title>SMTP diagnostic</title>
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;max-width:900px;margin:30px auto;padding:0 16px;line-height:1.5}
pre{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:12px;overflow-x:auto;font-size:12.5px}
.ok{color:#3fb950;font-weight:700}.bad{color:#f85149;font-weight:700}.warn{color:#d29922}
button{background:#00A878;color:#fff;border:0;border-radius:8px;padding:10px 20px;font-size:15px;cursor:pointer}
table{border-collapse:collapse}td{padding:4px 14px 4px 0;vertical-align:top;font-size:14px}
</style></head><body>
<h1>SMTP diagnostic</h1>
<p>Sends a test only to <strong><?= htmlspecialchars($to) ?></strong>. Delete this file after use.</p>
<form method="POST"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button>Run diagnostic</button></form>

<?php if ($ran): ?>
<h2>1. Config</h2>
<table><?php foreach ($results['config'] as $k => $v): ?><tr><td><?= $k ?></td><td><code><?= htmlspecialchars($v) ?></code></td></tr><?php endforeach; ?></table>

<h2>2. PHPMailer library</h2>
<p class="<?= $results['phpmailer'] ? 'ok' : 'bad' ?>"><?= $results['phpmailer'] ? 'Found in vendor/' : 'NOT FOUND at vendor/phpmailer/phpmailer/src/' ?></p>

<h2>3. Network reachability from this server</h2>
<p>DNS for SMTP_HOST: <code><?= htmlspecialchars($results['dns']) ?></code></p>
<table><?php foreach ($results['tcp'] as $p => $r): ?><tr><td>Port <?= $p ?></td><td class="<?= strpos($r,'OPEN')===0?'ok':'bad' ?>"><?= htmlspecialchars($r) ?></td></tr><?php endforeach; ?></table>

<h2>4. Direct PHPMailer send (with transcript)</h2>
<p class="<?= $results['smtp_ok'] ? 'ok' : 'bad' ?>"><?= $results['smtp_ok'] ? 'SENT — check your inbox (and spam)' : 'FAILED' ?></p>
<?php if ($results['smtp_error']): ?><p class="bad">Error: <?= htmlspecialchars($results['smtp_error']) ?></p><?php endif; ?>
<pre><?= htmlspecialchars(implode("\n", $results['smtp_debug'])) ?></pre>

<h2>5. Site's sendMail() wrapper</h2>
<?php if ($results['wrapper_ok'] === null): ?><p class="warn">sendMail() not defined.</p>
<?php else: ?><p class="<?= $results['wrapper_ok'] ? 'ok' : 'bad' ?>"><?= $results['wrapper_ok'] ? 'Returned true' : 'Returned false / threw' ?></p>
<?php if ($results['wrapper_error']): ?><p class="bad"><?= htmlspecialchars($results['wrapper_error']) ?></p><?php endif; ?><?php endif; ?>
<?php endif; ?>
</body></html>
