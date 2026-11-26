<?php
// ============================================================
// EdgeBeyond — Instalación ÚNICA de la llave API de Brevo.
// Valida la llave contra la API de Brevo, la guarda como
// EB_BREVO_API_KEY en chatbot-config.php (fuera de la raíz web)
// y SE BORRA SOLA cuando la llave es válida. Clave en la URL.
// ============================================================
declare(strict_types=1);

if (($_GET['k'] ?? '') !== 'eb-brevo-26' && ($_POST['k'] ?? '') !== 'eb-brevo-26') {
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

function eb_brevo_key_ok(string $key): int
{
    $ch = curl_init('https://api.brevo.com/v3/account');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['api-key: ' . $key, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim((string)($_POST['key'] ?? ''));
    if ($key === '') {
        $msg = 'Pega la llave API de Brevo.';
    } else {
        $code = eb_brevo_key_ok($key);
        if ($code === 200) {
            eb_cfg_set($configFile, 'EB_BREVO_API_KEY', $key);
            @unlink(__FILE__);
            echo '<!DOCTYPE html><html><body style="font-family:Arial;padding:40px;background:#141b24;color:#fff;">'
                . '<h2 style="color:#c0f447;">¡Brevo conectado!</h2>'
                . '<p>La llave API es válida y quedó guardada en chatbot-config.php. Esta página se borró sola.</p>'
                . '</body></html>';
            exit;
        }
        $msg = 'Brevo respondió HTTP ' . $code . ' a esa llave — no se guardó nada. Genera una llave API v3 en Brevo (SMTP & API → API keys) y pégala tal cual.';
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>EdgeBeyond — Brevo</title></head>
<body style="font-family:Arial;padding:40px;background:#141b24;color:#fff;">
<h2 style="color:#c0f447;">EdgeBeyond — Bienvenida por Brevo</h2>
<p>Pega la <strong>llave API v3</strong> de Brevo (empieza con <em>xkeysib-</em>). Se valida contra Brevo antes de guardarse.</p>
<?php if ($msg !== '') { echo '<p style="color:#ffb3b3;">' . $msg . '</p>'; } ?>
<form method="post">
<input type="hidden" name="k" value="eb-brevo-26">
<label>Llave API de Brevo:<br>
<input type="password" name="key" style="padding:10px;width:420px;margin:8px 0;" autocomplete="off"></label><br>
<button type="submit" style="padding:12px 24px;background:#c0f447;border:0;border-radius:100px;font-weight:700;cursor:pointer;">Validar y guardar</button>
</form>
<p style="color:#9aa3ab;font-size:13px;">La llave se guarda solo en chatbot-config.php, en tu servidor, fuera de la web. Esta página se borra sola cuando la llave es válida.</p>
</body></html>
