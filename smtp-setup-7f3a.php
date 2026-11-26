<?php
// ============================================================
// EdgeBeyond — Instalación ÚNICA del SMTP de la bienvenida.
// Guarda las constantes EB_SMTP_* en chatbot-config.php (fuera de
// la raíz web), prueba el login contra Spacemail y SE BORRA SOLA
// cuando el login es correcto. Acceso con clave en la URL.
// ============================================================
declare(strict_types=1);

if (($_GET['k'] ?? '') !== 'eb-smtp-26' && ($_POST['k'] ?? '') !== 'eb-smtp-26') {
    http_response_code(404);
    exit;
}

$configFile = __DIR__ . '/../chatbot-config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/chatbot-config.php';
}

function eb_cfg_set(string $file, string $name, string $value): void
{
    $line = "define('" . $name . "', " . var_export($value, true) . ");";
    $src = (string)file_get_contents($file);
    if (preg_match("/define\\('" . $name . "',.*\\);/", $src) === 1) {
        $src = preg_replace("/define\\('" . $name . "',.*\\);/", $line, $src);
    } else {
        $src = rtrim($src);
        if (substr($src, -2) === '?>') {
            $src = substr($src, 0, -2) . "\n" . $line . "\n?>";
        } else {
            $src .= "\n" . $line . "\n";
        }
    }
    file_put_contents($file, $src);
}

function eb_smtp_login_ok(string $user, string $pass): string
{
    $fp = @fsockopen('ssl://smtp.spacemail.com', 465, $errno, $errstr, 20);
    if ($fp === false) {
        return 'conexion_fallida: ' . $errstr;
    }
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 512)) !== false) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $send = function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return substr($read(), 0, 3);
    };
    $r = substr($read(), 0, 3);
    if ($r === '220' && $send('EHLO edgebeyond.solutions') === '250' && $send('AUTH LOGIN') === '334'
        && $send(base64_encode($user)) === '334') {
        $auth = $send(base64_encode($pass));
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return $auth === '235' ? 'ok' : 'auth_rechazada_' . $auth;
    }
    fclose($fp);
    return 'flujo_inesperado';
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string)($_POST['pass'] ?? '');
    if ($pass === '') {
        $msg = 'Escribe la contraseña del buzón.';
    } else {
        $check = eb_smtp_login_ok('contact@edgebeyond.solutions', $pass);
        if ($check === 'ok') {
            eb_cfg_set($configFile, 'EB_SMTP_HOST', 'smtp.spacemail.com');
            eb_cfg_set($configFile, 'EB_SMTP_USER', 'contact@edgebeyond.solutions');
            eb_cfg_set($configFile, 'EB_SMTP_PASS', $pass);
            @unlink(__FILE__);
            echo '<!DOCTYPE html><html><body style="font-family:Arial;padding:40px;background:#141b24;color:#fff;">'
                . '<h2 style="color:#c0f447;">¡SMTP configurado!</h2>'
                . '<p>El login con contact@edgebeyond.solutions funcionó y las constantes quedaron guardadas en chatbot-config.php. Esta página se borró sola.</p>'
                . '</body></html>';
            exit;
        }
        $msg = 'El servidor de correo rechazó ese login (' . htmlspecialchars($check, ENT_QUOTES, 'UTF-8')
            . '). Revisa la contraseña del buzón contact@edgebeyond.solutions y vuelve a intentarlo — no se guardó nada nuevo.';
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>EdgeBeyond — SMTP</title></head>
<body style="font-family:Arial;padding:40px;background:#141b24;color:#fff;">
<h2 style="color:#c0f447;">EdgeBeyond — Correo de bienvenida (SMTP)</h2>
<p>Buzón remitente: <strong>contact@edgebeyond.solutions</strong> (Spacemail, smtp.spacemail.com:465).</p>
<?php if ($msg !== '') { echo '<p style="color:#ffb3b3;">' . $msg . '</p>'; } ?>
<form method="post">
<input type="hidden" name="k" value="eb-smtp-26">
<label>Contraseña del buzón contact@:<br>
<input type="password" name="pass" style="padding:10px;width:320px;margin:8px 0;" autocomplete="off"></label><br>
<button type="submit" style="padding:12px 24px;background:#c0f447;border:0;border-radius:100px;font-weight:700;cursor:pointer;">Probar y guardar</button>
</form>
<p style="color:#9aa3ab;font-size:13px;">La contraseña se guarda solo en chatbot-config.php, en tu servidor, fuera de la web. Esta página se borra sola cuando el login funciona.</p>
</body></html>
