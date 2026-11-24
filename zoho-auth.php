<?php
// ============================================================
// EdgeBeyond — Conexión ÚNICA con Zoho CRM (archivo temporal)
// Enrique pega aquí el Client ID, Client Secret y el código de
// autorización de su Self Client de Zoho. Este archivo:
//  1. Canjea el código por un refresh token.
//  2. Guarda las constantes EB_ZOHO_* en chatbot-config.php
//     (fuera de la raíz web, junto a la llave de Gemini).
//  3. SE BORRA A SÍ MISMO al terminar con éxito.
// ============================================================
declare(strict_types=1);

$configFile = __DIR__ . '/../chatbot-config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/chatbot-config.php';
}

function eb_cfg_has_zoho(string $file): bool
{
    if (!file_exists($file)) {
        return false;
    }
    $c = (string)file_get_contents($file);
    return strpos($c, 'EB_ZOHO_REFRESH_TOKEN') !== false;
}

$done  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId     = trim((string)($_POST['client_id'] ?? ''));
    $clientSecret = trim((string)($_POST['client_secret'] ?? ''));
    $code         = trim((string)($_POST['code'] ?? ''));
    if ($clientId === '' || $clientSecret === '' || $code === '') {
        $error = 'Faltan datos: pega el Client ID, el Client Secret y el código.';
    } elseif (eb_cfg_has_zoho($configFile)) {
        $error = 'Zoho ya estaba conectado en chatbot-config.php. No se cambió nada.';
    } else {
        $ch = curl_init('https://accounts.zoho.com/oauth/v2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_POSTFIELDS => http_build_query([
                'code'          => $code,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'grant_type'    => 'authorization_code',
            ]),
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        if ($http === 200 && is_array($json) && !empty($json['refresh_token'])) {
            $esc = function (string $v): string {
                return str_replace(['\\', "'"], ['\\\\', "\\'"], $v);
            };
            $block = "\n// Zoho CRM (agregado por zoho-auth.php el " . date('Y-m-d H:i:s') . ")\n"
                . "define('EB_ZOHO_CLIENT_ID', '" . $esc($clientId) . "');\n"
                . "define('EB_ZOHO_CLIENT_SECRET', '" . $esc($clientSecret) . "');\n"
                . "define('EB_ZOHO_REFRESH_TOKEN', '" . $esc((string)$json['refresh_token']) . "');\n";
            if (file_put_contents($configFile, $block, FILE_APPEND | LOCK_EX) !== false) {
                $done = true;
                @unlink(__FILE__); // este archivo se borra solo
            } else {
                $error = 'Zoho aceptó el código, pero no pude escribir chatbot-config.php. Revisa los permisos del archivo.';
            }
        } else {
            $zohoErr = is_array($json) ? (string)($json['error'] ?? '') : '';
            $error = 'Zoho no aceptó el canje' . ($zohoErr !== '' ? " (error: {$zohoErr})" : '')
                . '. El código vence a los 10 minutos y solo sirve una vez: genera uno nuevo e inténtalo de inmediato.';
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conectar Zoho CRM — EdgeBeyond</title>
<style>
  body { font-family: Arial, sans-serif; background: #06090D; color: #fff; margin: 0; padding: 24px; }
  .card { max-width: 560px; margin: 24px auto; background: #141B24; border: 1px solid rgba(192,244,71,.35); border-radius: 16px; padding: 24px; }
  h1 { font-size: 20px; margin: 0 0 8px; }
  p { color: #B9C6D2; font-size: 14px; line-height: 1.5; }
  label { display: block; font-size: 13px; margin: 14px 0 6px; color: #C0F447; font-weight: bold; }
  input { width: 100%; box-sizing: border-box; background: #0E1620; border: 1px solid rgba(255,255,255,.2); color: #fff; border-radius: 10px; padding: 11px; font-size: 14px; }
  button { margin-top: 20px; width: 100%; background: #C0F447; color: #0B1118; border: 0; font-weight: bold; font-size: 15px; padding: 13px; border-radius: 12px; cursor: pointer; }
  .err { background: rgba(255,80,80,.12); border: 1px solid rgba(255,80,80,.5); color: #ffb3b3; padding: 12px; border-radius: 10px; font-size: 14px; }
  .ok { background: rgba(192,244,71,.12); border: 1px solid rgba(192,244,71,.5); color: #dff5a8; padding: 12px; border-radius: 10px; font-size: 14px; }
</style>
</head>
<body>
<div class="card">
  <h1>Conectar Zoho CRM</h1>
  <?php if ($done): ?>
    <div class="ok"><strong>¡Zoho CRM quedó conectado!</strong><br>
    El refresh token se guardó en chatbot-config.php y esta página se borró sola del servidor.
    Ya puedes cerrar esta pestaña y avisarle a Muse para que haga la prueba con un lead.</div>
  <?php else: ?>
    <p>Pega los tres datos de tu Self Client de Zoho (API Console). El código vence a los
    <strong>10 minutos</strong> de generarlo y solo sirve una vez, así que genera el código al final y envía este formulario enseguida.</p>
    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
      <label>Client ID</label>
      <input name="client_id" autocomplete="off" required>
      <label>Client Secret</label>
      <input name="client_secret" autocomplete="off" required>
      <label>Código de autorización (Generate Code)</label>
      <input name="code" autocomplete="off" required>
      <button type="submit">Conectar Zoho</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
