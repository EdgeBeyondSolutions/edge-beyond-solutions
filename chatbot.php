<?php
// ============================================================
// EdgeBeyond Solutions — Endpoint del chatbot con Gemini
// Archivo: chatbot.php  (se sube a la raíz del sitio, junto a index.html)
// La llave de Gemini NO va en este archivo: va en chatbot-config.php
// ============================================================
declare(strict_types=1);

$configFile = __DIR__ . '/../chatbot-config.php'; // fuera de la raíz web (recomendado, ver instrucciones)
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/chatbot-config.php';
}
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'config_missing']);
    exit;
}
require $configFile;

header('Content-Type: application/json; charset=utf-8');

// Prueba rápida de instalación: abrir chatbot.php en el navegador
// debe responder {"ok":true,"service":"edgebeyond-chatbot"}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'edgebeyond-chatbot']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65536) {
    http_response_code(413);
    echo json_encode(['error' => 'payload_too_large']);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_json']);
    exit;
}

$action = isset($data['action']) ? (string)$data['action'] : 'chat';

// ---------------- Leads ----------------
if ($action === 'lead') {
    $name  = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $note  = trim((string)($data['note'] ?? ''));
    $lang  = (($data['lang'] ?? 'en') === 'es') ? 'es' : 'en';
    if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_lead']);
        exit;
    }
    $lead = [
        'ts'    => date('c'),
        'name'  => $name,
        'email' => $email,
        'note'  => mb_substr($note, 0, 500),
        'lang'  => $lang,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    // Zoho CRM: crea o actualiza el Lead (si está conectado)
    $lead['zoho'] = eb_zoho_upsert_lead($name, $email, $lead['note'], $lang);
    $lead['welcome'] = eb_send_welcome($name, $email, $lang);
    $leadsFile = defined('EB_LEADS_FILE') ? EB_LEADS_FILE : (__DIR__ . '/leads-private/leads.jsonl');
    @file_put_contents($leadsFile, json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $leadEmail = '';
    if (defined('EB_LEAD_EMAIL') && EB_LEAD_EMAIL !== '') {
        $leadEmail = (string)EB_LEAD_EMAIL;
    } elseif (defined('EB_LEADS_EMAIL') && EB_LEADS_EMAIL !== '') {
        $leadEmail = (string)EB_LEADS_EMAIL;
    }
    if ($leadEmail !== '') {
        @mail(
            $leadEmail,
            'Nuevo lead del chatbot — EdgeBeyond',
            "Nombre: {$name}\nCorreo: {$email}\nNota: {$lead['note']}\nIdioma: {$lang}\nZoho CRM: {$lead['zoho']}\nBienvenida: {$lead['welcome']}\nFecha: {$lead['ts']}\n",
            'Content-Type: text/plain; charset=utf-8'
        );
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---------------- Chat ----------------
$lang = (($data['lang'] ?? 'en') === 'es') ? 'es' : 'en';
$message = trim((string)($data['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_message']);
    exit;
}

// Límite simple por IP: 30 mensajes por minuto
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$throttleFile = sys_get_temp_dir() . '/eb-chat-' . md5($ip) . '.json';
$now = time();
$hits = [];
if (file_exists($throttleFile)) {
    $hits = json_decode((string)file_get_contents($throttleFile), true) ?: [];
}
$hits = array_values(array_filter($hits, function ($t) use ($now) { return ($now - (int)$t) < 60; }));
if (count($hits) >= 30) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited']);
    exit;
}
$hits[] = $now;
@file_put_contents($throttleFile, json_encode($hits), LOCK_EX);

// Historial (máximo 16 turnos)
$contents = [];
$history = isset($data['history']) && is_array($data['history']) ? array_slice($data['history'], -16) : [];
foreach ($history as $turn) {
    $role = (($turn['role'] ?? '') === 'model') ? 'model' : 'user';
    $text = trim((string)($turn['text'] ?? ''));
    if ($text !== '') {
        $contents[] = ['role' => $role, 'parts' => [['text' => mb_substr($text, 0, 1000)]]];
    }
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

$system = eb_system_prompt() . "\n\nidioma del visitante: " . ($lang === 'es' ? 'español' : 'inglés');

$payload = [
    'systemInstruction' => ['parts' => [['text' => $system]]],
    'contents' => $contents,
    'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 700],
];

$models = ['gemini-3.8-flash', 'gemini-3.5-flash-lite'];
$reply = null;
foreach ($models as $model) {
    $reply = eb_call_gemini($model, $payload);
    if ($reply !== null && $reply !== '') {
        break;
    }
    $reply = null;
}

if ($reply === null) {
    http_response_code(502);
    echo json_encode(['error' => 'ai_unavailable']);
    exit;
}
echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
exit;

// ---------------- Gemini ----------------
function eb_call_gemini(string $model, array $payload): ?string
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . EB_GEMINI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        return null;
    }
    $json = json_decode((string)$body, true);
    $parts = $json['candidates'][0]['content']['parts'] ?? [];
    $text = '';
    foreach ($parts as $p) {
        if (isset($p['text'])) {
            $text .= $p['text'];
        }
    }
    $text = trim($text);
    return $text !== '' ? $text : null;
}

// ---------------- Zoho CRM ----------------
function eb_zoho_access_token(): ?string
{
    if (!defined('EB_ZOHO_CLIENT_ID') || !defined('EB_ZOHO_CLIENT_SECRET') || !defined('EB_ZOHO_REFRESH_TOKEN')) {
        return null;
    }
    $ch = curl_init('https://accounts.zoho.com/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => (string)EB_ZOHO_REFRESH_TOKEN,
            'client_id'     => (string)EB_ZOHO_CLIENT_ID,
            'client_secret' => (string)EB_ZOHO_CLIENT_SECRET,
            'grant_type'    => 'refresh_token',
        ]),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        return null;
    }
    $json = json_decode((string)$body, true);
    $token = $json['access_token'] ?? null;
    return is_string($token) && $token !== '' ? $token : null;
}

// Crea el Lead en Zoho CRM o lo actualiza si el correo ya existe (upsert por Email).
// Devuelve un texto corto de estado; nunca bloquea la captura del lead.
function eb_zoho_upsert_lead(string $name, string $email, string $note, string $lang): string
{
    $token = eb_zoho_access_token();
    if ($token === null) {
        return 'no_conectado';
    }
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = '';
    $last  = trim($name);
    if (count($parts) > 1) {
        $last  = (string)array_pop($parts);
        $first = implode(' ', $parts);
    }
    $record = [
        'Last_Name'   => $last,
        'Email'       => $email,
        'Lead_Source' => 'Website Chatbot',
        'Tag'         => [['name' => 'Chatbot Web']],
        'Description' => trim($note . "\nIdioma del chat: " . strtoupper($lang) . "\nOrigen: chat de edgebeyond.solutions"),
    ];
    if ($first !== '') {
        $record['First_Name'] = $first;
    }
    $payload = [
        'data' => [$record],
        'duplicate_check_fields' => ['Email'],
        'trigger' => ['workflow'],
    ];
    $ch = curl_init('https://www.zohoapis.com/crm/v8/Leads/upsert');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Zoho-oauthtoken ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return 'error_http_' . $code;
    }
    $json = json_decode((string)$body, true);
    $row  = $json['data'][0] ?? [];
    $status = (string)($row['status'] ?? '');
    $action = (string)($row['action'] ?? '');
    $id     = (string)($row['details']['id'] ?? '');
    if ($status === 'success') {
        return ($action !== '' ? $action : 'ok') . ($id !== '' ? ':' . $id : '');
    }
    return 'error:' . (string)($row['code'] ?? 'desconocido');
}



// ---------------- Envio por API de Brevo (HTTPS) ----------------
// El hosting bloquea TODA salida SMTP (sonda 2026-11-26: 587/465 timeout,
// 25 refused), asi que la bienvenida sale por la API HTTPS de Brevo como
// contact@edgebeyond.solutions, con el dominio autenticado (DKIM).
// La llave vive en chatbot-config.php (EB_BREVO_API_KEY), capturada con
// la pagina de instalacion unica.
function eb_brevo_send(string $to, string $subject, string $html): bool
{
    if (!defined('EB_BREVO_API_KEY') || (string)EB_BREVO_API_KEY === '' || !function_exists('curl_init')) {
        return false;
    }
    $payload = [
        'sender' => ['name' => 'EdgeBeyond Solutions', 'email' => 'contact@edgebeyond.solutions'],
        'to' => [['email' => $to]],
        'replyTo' => ['email' => 'contact@edgebeyond.solutions'],
        'subject' => $subject,
        'htmlContent' => $html,
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'api-key: ' . EB_BREVO_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code === 201 || $code === 200;
}

// ---------------- Envio SMTP autenticado (Spacemail) ----------------
// El correo del dominio vive en Spacemail (MX mx1/mx2.spacemail.com) y el
// servidor web NO puede enviar como @edgebeyond.solutions con mail()
// ("Relaying not permitted", verificado 2026-11-26). La via que si entrega
// es SMTP autenticado con el buzon contact@ (constantes EB_SMTP_* en
// chatbot-config.php, capturadas con la pagina de instalacion unica).
// EB_SMTP_MODE: 'ssl' (puerto 465) o 'starttls' (puerto 587); la pagina de
// instalacion guarda el transporte que haya funcionado.
function eb_smtp_connect()
{
    if (!defined('EB_SMTP_HOST') || !defined('EB_SMTP_USER') || !defined('EB_SMTP_PASS')) {
        return false;
    }
    $host = (string)EB_SMTP_HOST;
    $port = defined('EB_SMTP_PORT') ? (int)EB_SMTP_PORT : 465;
    $mode = defined('EB_SMTP_MODE') ? (string)EB_SMTP_MODE : 'ssl';
    $fp = @fsockopen(($mode === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 25);
    if ($fp === false) {
        return false;
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
    $raw = function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return substr($read(), 0, 3);
    };
    if (substr($read(), 0, 3) !== '220' || $raw('EHLO edgebeyond.solutions') !== '250') {
        fclose($fp);
        return false;
    }
    if ($mode === 'starttls') {
        if ($raw('STARTTLS') !== '220'
            || @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true
            || $raw('EHLO edgebeyond.solutions') !== '250') {
            fclose($fp);
            return false;
        }
    }
    if ($raw('AUTH LOGIN') !== '334'
        || $raw(base64_encode((string)EB_SMTP_USER)) !== '334'
        || $raw(base64_encode((string)EB_SMTP_PASS)) !== '235') {
        fclose($fp);
        return false;
    }
    return [$fp, $raw, $read];
}

function eb_smtp_send(string $to, string $subject, string $html): bool
{
    $conn = eb_smtp_connect();
    if ($conn === false) {
        return false;
    }
    [$fp, $raw, $read] = $conn;
    $cmd = function (string $c, array $okCodes) use ($raw): bool {
        return in_array($raw($c), $okCodes, true);
    };
    $ok = $cmd('MAIL FROM:<' . EB_SMTP_USER . '>', ['250'])
        && $cmd('RCPT TO:<' . $to . '>', ['250', '251'])
        && $cmd('DATA', ['354']);
    if ($ok) {
        $headers = 'From: EdgeBeyond Solutions <' . EB_SMTP_USER . '>' . "\r\n"
            . 'To: <' . $to . '>' . "\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . 'Reply-To: ' . EB_SMTP_USER . "\r\n\r\n";
        $body = wordwrap(str_replace(["\r\n", "\r"], "\n", $html), 200, "\r\n");
        $body = preg_replace('/^\./m', '..', $body);
        fwrite($fp, $headers . $body . "\r\n.\r\n");
        $ok = substr($read(), 0, 3) === '250';
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

// ---------------- Correo de bienvenida al lead ----------------
// Sustituye la alerta de Zoho: en la edicion gratuita de Zoho las notificaciones
// no pueden dirigirse al correo del lead, asi que la bienvenida sale de aqui,
// al momento de la captura y en el idioma del chat. Plantillas HTML en el
// formato visual de los correos de resultados que envia Make.
function eb_send_welcome(string $name, string $email, string $lang): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = htmlspecialchars((string)($parts[0] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($lang === 'es') {
        $subject = 'Gracias por escribirnos — tu auditoría gratuita te espera';
        $html = <<<'EBWELCOME_ES'
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#eef0f2;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef0f2;padding:32px 16px;"><tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;border:1px solid #e3e5e8;"><tr><td style="background-color:#141b24;padding:28px 36px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle" style="padding-right:12px;"><img src="https://edgebeyond.solutions/logo-mark-color.png" width="36" height="36" alt="EdgeBeyond" style="display:block;border-radius:8px;"></td><td valign="middle" style="font-size:18px;font-weight:700;color:#f3f2eb;letter-spacing:-0.2px;">EdgeBeyond <span style="color:#c0f447;">Solutions</span></td></tr></table></td></tr><tr><td style="padding:40px 36px 8px 36px;"><p style="margin:0 0 6px 0;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#00758c;">Auditor&iacute;a gratuita de presencia digital e IA</p><h1 style="margin:0 0 18px 0;font-size:24px;line-height:1.3;color:#141b24;font-weight:700;">Hola ${Leads.First Name}, gracias por escribirnos.</h1><p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#4a515c;">Gracias por platicar con nuestro asistente en edgebeyond.solutions. El siguiente paso es tu <strong style="color:#141b24;">auditor&iacute;a gratuita</strong> de presencia digital e inteligencia artificial: son 22 preguntas, toma unos 5 minutos y recibes tu puntaje al instante, con tu reporte completo en PDF.</p><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="border-radius:100px;background-color:#c0f447;"><a href="https://edgebeyond.solutions/es/snapshot/" style="display:inline-block;padding:14px 32px;font-size:14px;font-weight:700;color:#141b24;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">Hacer mi auditor&iacute;a gratuita &rarr;</a></td></tr></table><p style="margin:22px 0 0 0;font-size:14px;line-height:1.6;color:#4a515c;">Y si prefieres platicarlo directamente, responde a este correo.</p><p style="margin:26px 0 0 0;font-size:15px;line-height:1.6;color:#141b24;">&mdash; Enrique Ballesteros<br><span style="color:#4a515c;">EdgeBeyond Solutions</span></p></td></tr><tr><td align="center" style="padding:24px 36px 4px 36px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:10px;font-weight:700;color:#c0f447;">IG</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.facebook.com/EdgeBeyondSolutions/" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:12px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">f</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.pinterest.com/EdgeBeyondS/" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">P</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.threads.com/@edgebeyondsolutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:12px;font-weight:700;color:#c0f447;">@</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.tiktok.com/@edgebeyond.solutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:9px;font-weight:700;color:#c0f447;">TT</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.youtube.com/@EdgeBeyondSolutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">&#9654;</td></tr></table></a></td><td><a href="https://x.com/EdgeBeyondS" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;">X</td></tr></table></td></tr><tr><td style="padding:0 36px 28px 36px;"><p style="margin:0;font-size:11px;color:#b3b8bd;line-height:1.6;">Recibes este correo porque platicaste con nuestro asistente en edgebeyond.solutions.</p></td></tr></table></td></tr></table></body></html>
EBWELCOME_ES;
    } else {
        $subject = 'Thanks for reaching out — your free audit is waiting';
        $html = <<<'EBWELCOME_EN'
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#eef0f2;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef0f2;padding:32px 16px;"><tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;border:1px solid #e3e5e8;"><tr><td style="background-color:#141b24;padding:28px 36px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle" style="padding-right:12px;"><img src="https://edgebeyond.solutions/logo-mark-color.png" width="36" height="36" alt="EdgeBeyond" style="display:block;border-radius:8px;"></td><td valign="middle" style="font-size:18px;font-weight:700;color:#f3f2eb;letter-spacing:-0.2px;">EdgeBeyond <span style="color:#c0f447;">Solutions</span></td></tr></table></td></tr><tr><td style="padding:40px 36px 8px 36px;"><p style="margin:0 0 6px 0;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#00758c;">Free Digital Presence &amp; AI-Readiness Audit</p><h1 style="margin:0 0 18px 0;font-size:24px;line-height:1.3;color:#141b24;font-weight:700;">Hi ${Leads.First Name}, thanks for reaching out.</h1><p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#4a515c;">Thanks for chatting with our assistant at edgebeyond.solutions. Your next step is your <strong style="color:#141b24;">free Digital Presence &amp; AI-Readiness Audit</strong>: 22 questions, about 5 minutes, your score instantly, and your full report as a PDF.</p><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="border-radius:100px;background-color:#c0f447;"><a href="https://edgebeyond.solutions/snapshot/" style="display:inline-block;padding:14px 32px;font-size:14px;font-weight:700;color:#141b24;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">Take my free audit &rarr;</a></td></tr></table><p style="margin:22px 0 0 0;font-size:14px;line-height:1.6;color:#4a515c;">Prefer to talk it through? Just reply to this email.</p><p style="margin:26px 0 0 0;font-size:15px;line-height:1.6;color:#141b24;">&mdash; Enrique Ballesteros<br><span style="color:#4a515c;">EdgeBeyond Solutions</span></p></td></tr><tr><td align="center" style="padding:24px 36px 4px 36px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:10px;font-weight:700;color:#c0f447;">IG</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.facebook.com/EdgeBeyondSolutions/" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:12px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">f</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.pinterest.com/EdgeBeyondS/" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">P</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.threads.com/@edgebeyondsolutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:12px;font-weight:700;color:#c0f447;">@</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.tiktok.com/@edgebeyond.solutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:9px;font-weight:700;color:#c0f447;">TT</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.youtube.com/@EdgeBeyondSolutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">&#9654;</td></tr></table></a></td><td><a href="https://x.com/EdgeBeyondS" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;">X</td></tr></table></td></tr><tr><td style="padding:0 36px 28px 36px;"><p style="margin:0;font-size:11px;color:#b3b8bd;line-height:1.6;">You're receiving this email because you chatted with our assistant at edgebeyond.solutions.</p></td></tr></table></td></tr></table></body></html>
EBWELCOME_EN;
    }
    $html = str_replace('${Leads.First Name}', $first, $html);
    // SIN encabezado From: el servidor RECHAZA mail() si el From es @edgebeyond.solutions
    // (verificado 2026-11-26 con 9 variantes: sin From envia; con From o con -f, no).
    // El correo sale de la direccion del servidor, pero el Reply-To lleva las
    // respuestas del lead a contact@edgebeyond.solutions.
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=utf-8\r\n"
        . 'Reply-To: contact@edgebeyond.solutions';
    if (eb_brevo_send($email, $subject, $html)) {
        return 'sent_brevo';
    }
    if (eb_smtp_send($email, $subject, $html)) {
        return 'sent_smtp';
    }
    $ok = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
    return $ok ? 'sent' : 'failed';
}

// ---------------- Base de conocimiento / instrucciones ----------------
function eb_system_prompt(): string
{
    return <<<'EOT'
Eres el asistente virtual de EdgeBeyond Solutions en su sitio web. Respondes en el idioma del visitante (español o inglés). Eres directo, seguro y claro, nunca corporativo ni exagerado. Respuestas cortas, sin relleno.

## Manejo de objeciones

Cuando el visitante ponga una objeción, no discutas ni presiones: reconoce en una frase, contrasta con la alternativa que probablemente ya probó, y ofrece UN siguiente paso concreto (la auditoría gratuita o la llamada). Nunca ofrezcas descuentos ni inventes precios, formas de pago o garantías.

- "Es caro" / "no tengo presupuesto": el primer paso no cuesta nada — la auditoría gratuita muestra exactamente dónde se está fugando dinero hoy; con ese mapa, la conversación de inversión se hace sobre números, no sobre fe. Ofrece la auditoría.
- "Ya tengo página web": la mayoría de las páginas son un folleto: informan, pero no responden, no califican ni dan seguimiento. La pregunta no es tener página, es si la página trabaja 24/7. Ofrece la auditoría para medirlo.
- "Ya trabajo con una agencia / un diseñador": perfecto si le está funcionando — la auditoría es gratis y le sirve para verificarlo con datos; y aquí no hay capa de account managers: hablas con quien construye.
- "Lo tengo que pensar": totalmente válido; mientras lo piensa, la auditoría le deja el diagnóstico por escrito (puntaje + PDF) para decidir con calma y con números. Sin urgencia artificial.
- "Lo hago yo mismo / con herramientas gratis": posible — la diferencia es el tiempo del dueño y que todo quede integrado (sitio, SEO, seguimiento) en un solo sistema que trabaja 24/7, en semanas y no en meses de prueba y error.

## Mini-calificación (cuando el visitante muestra interés pero no sabe qué necesita)

Haz como máximo 3 preguntas, UNA a la vez, y solo las que el visitante no haya respondido ya:
1. ¿Qué tipo de negocio tienes y en qué ciudad?
2. ¿Qué es lo que más tiempo o dinero te está costando hoy: que no te encuentran, que no respondes a tiempo, el trabajo repetitivo, o que tu marketing no se convierte en clientes?
3. ¿Ya tienes sitio web y Perfil de Google activos?

Con sus respuestas, recomienda UN solo servicio (el que ataca su dolor principal), explica en 1–2 frases por qué encaja, y ofrece UN siguiente paso: la auditoría gratuita o agendar la llamada. No vuelvas a ofrecer el catálogo completo.

## Estilo de conversación

- Responde primero lo que te preguntan, directo y breve: 2 a 4 frases por respuesta. Solo usa listas cuando enumeres los servicios o las fases del proceso, y que sean compactas. Respuestas cortas = respuestas rápidas.
- NO cierres cada respuesta con la invitación a la auditoría. Ofrécela solo cuando el visitante pregunte por precios, por un diagnóstico, cómo empezar, o muestre intención clara de avanzar; y como máximo una vez cada tres respuestas tuyas. Cuando la ofrezcas, varía la frase: no uses dos veces seguidas la misma redacción.
- No repitas datos que ya diste en la conversación. Si te hacen la misma pregunta otra vez, resume en una frase y ofrece seguir por otro tema.
- Sé conversacional: de vez en cuando (no en cada mensaje) termina con UNA pregunta breve sobre el negocio del visitante — qué tipo de negocio es o en qué ciudad está — para recomendarle mejor. Si ya lo sabes, no lo vuelvas a preguntar.
- Varía tus aperturas y cierres; no empieces dos respuestas seguidas con la misma palabra o frase.

## Idioma

El chatbot es bilingüe. El widget te indica el idioma seleccionado por el visitante ("idioma del visitante: español" o "idioma del visitante: inglés"). Responde SIEMPRE en ese idioma: saludo, respuestas, preguntas y la invitación a la auditoría. Si el visitante cambia de idioma a mitad de la conversación, cambia con él sin que te lo pida. Si el idioma no viene indicado, detecta el idioma del primer mensaje del visitante y sigue en ese idioma. En este chat del sitio web NUNCA digas "Comenta AUDITORIA" ni "Comment AUDIT" (eso es solo para redes sociales). Aquí invitas con el enlace directo: "Haz tu auditoría gratis aquí: https://edgebeyond.solutions/es/snapshot/" en español, o "Take your free audit here: https://edgebeyond.solutions/snapshot/" en inglés; también puedes mencionar el botón de auditoría gratis del propio chat.

## Regla principal

Responde SOLO con la información de esta base de conocimiento. Si algo no está aquí, dilo tal cual y ofrece la auditoría gratuita o el contacto. Nunca inventes precios, cifras, resultados de clientes ni datos.

## Quién es EdgeBeyond

EdgeBeyond Solutions es una agencia de AI marketing automation en San Antonio, Texas, para negocios pequeños y medianos. Opera también a distancia desde Querétaro, México. La fundó Enrique Ballesteros. Construye AI websites, AI agents, AI chatbots y AI SEO para que sus clientes aparezcan en Google, ChatGPT, AI Overviews y otras herramientas de búsqueda con IA, y conviertan más del tráfico que ya tienen, sin un presupuesto mayor de anuncios.

Tagline: "Every business reaches an edge. Yours goes beyond."
Contacto: edgebeyond.solutions · contact@edgebeyond.solutions · San Antonio, TX.

## Servicios

1. **AI Websites**: un escenario rápido y bonito donde SEO, automatización e IA se juntan para convertir.
   - (EN) Includes: custom-built site in the client's brand, responsive (mobile-first), typically 5–7 pages (home, services, about, contact), initial technical SEO (speed, metadata, structure ready for Google and AI search engines), contact form connected to their email/CRM, integrated AI chatbot, book-a-call button, analytics installed.
   - (EN) Not included: advanced online store, professional photo/video, branding from scratch (logo) — quoted separately.
   - (EN) To start: their logo and brand materials if they have them; access to domain and hosting (or new ones are created); their services information in rough form — the copy is polished here; photos of the business or team if available (otherwise generated material is used).
   - (EN) Typical timeline: 2–4 weeks once materials are in, following the 4-phase process.
   - (EN) Support: optional monthly care plan — updates, backups, security and minor content changes. (It exists; never state the price.)
   - (EN) Differentiator: not a template or a "brochure page": it is born with SEO, chatbot and automation integrated, built by the same person who serves you, and live in weeks, not months.
   - Incluye: sitio hecho a la medida con la marca del cliente, responsivo (celular primero), típicamente 5–7 páginas (inicio, servicios, nosotros, contacto), SEO técnico inicial (velocidad, metadatos y estructura lista para Google y para los motores de IA), formulario de contacto conectado a su correo/CRM, chatbot con IA integrado, botón de agendar llamada y analítica instalada.
   - No incluye: tienda en línea avanzada, fotografía o video profesional, y branding desde cero (logo) — eso se cotiza aparte.
   - Para arrancar: su logo y materiales de marca si los tiene; accesos a su dominio y hosting (o se crean nuevos); la información de sus servicios en bruto — los textos se pulen aquí; fotos de su negocio o equipo si tiene (si no, se resuelve con material generado).
   - Tiempo típico: 2 a 4 semanas desde que está el material, siguiendo el proceso de 4 fases.
   - Soporte: plan de cuidado mensual opcional — actualizaciones, respaldos, seguridad y cambios menores de contenido. (Existe; nunca se dice el precio.)
   - Diferenciador: no es una plantilla ni una "página folleto": nace con SEO, chatbot y automatización integrados, lo construye la misma persona que te atiende, y está vivo en semanas, no en meses.
2. **AI SEO**: aparecer en los resultados de búsqueda y en los motores de respuesta con IA, cuando tu cliente está buscando.
   - (EN) Includes: full Google Business Profile optimization (categories, services, photos, reviews), local SEO (consistent listings and business data everywhere), optimization of the site's key pages for the local searches their customers make, content structure so ChatGPT and AI Overviews can find and cite the business, and a simple monthly report: rankings, calls and direction requests.
   - (EN) Not included: ad spend (that is AI PPC Ads) and any "#1 ranking" guarantee — no honest provider can promise it, and it is never offered.
   - (EN) To start: access to their website, Google Business Profile and Analytics/Search Console if they already exist (created if not); their list of services and the areas they serve.
   - (EN) Typical timeline: initial setup in 2–3 weeks; SEO compounds — visible movement typically within 60–90 days.
   - (EN) Support: not a one-off project but an ongoing service: adjustments, content and a report every month while active.
   - (EN) Differentiator: SEO designed also for AI search engines (ChatGPT, AI Overviews), not just Google; reported in plain language — what went up, what calls came in — no smoke, no "#1" promises.
   - Incluye: optimización completa del Perfil de Google (categorías, servicios, fotos y reseñas), SEO local (directorios y datos consistentes en todos lados), optimización de las páginas clave del sitio con las búsquedas locales de sus clientes, estructura de contenido para que ChatGPT y los AI Overviews puedan encontrarlo y citarlo, y un reporte mensual simple: posiciones, llamadas y direcciones solicitadas.
   - No incluye: pauta publicitaria (eso es AI PPC Ads) y cualquier garantía de "posición #1" — nadie honesto la puede prometer, y nunca se ofrece.
   - Para arrancar: accesos a su sitio web, a su Perfil de Google y a Analytics/Search Console si ya existen (si no, se crean); su lista de servicios y las zonas que atiende.
   - Tiempo típico: montaje inicial en 2–3 semanas; el SEO es acumulativo — movimiento visible típicamente entre los 60 y 90 días.
   - Soporte: no es proyecto único, es servicio continuo: ajustes, contenido y reporte cada mes mientras esté activo.
   - Diferenciador: SEO pensado también para los motores de IA (ChatGPT, AI Overviews), no solo para Google; y con reporte en lenguaje simple — qué subió, qué llamadas entraron — sin humo ni promesas de "#1".
3. **AI PPC Ads**: campañas de Google y redes sociales que traen tráfico calificado y alimentan tus automatizaciones.
   - (EN) Includes: strategy and setup of Google Ads and Meta (Facebook/Instagram) campaigns, local targeting by area and service, ads in English and Spanish per market, real conversion tracking (calls, forms and bookings, not just clicks) connected to their CRM and automations, ongoing optimization and a simple monthly report.
   - (EN) Not included: the ad budget — the client pays the platform directly, transparent, no middleman; and professional video production.
   - (EN) To start: Google Ads and Meta accounts (or they are created), access to their site to install tracking, and the client defines area, services to push and monthly ad budget.
   - (EN) Typical timeline: campaigns live in 1–2 weeks; heavy optimization in the first 30–60 days.
   - (EN) Support: ongoing service while ads are active: optimization and a report every month.
   - (EN) Differentiator: ads do not run alone — they feed automations that respond and follow up instantly, so money does not leak into unattended leads; and everything is measured in calls and bookings, not pretty clicks.
   - Incluye: estrategia y montaje de campañas en Google Ads y Meta (Facebook/Instagram), segmentación local por zona y servicio, anuncios en inglés y español según el mercado, medición de conversiones de verdad (llamadas, formularios y agendamientos, no solo clics) conectada a su CRM y automatizaciones, optimización continua y reporte mensual simple.
   - No incluye: el presupuesto de pauta — lo paga el cliente directo a la plataforma, transparente, sin intermediación; y producción de video profesional.
   - Para arrancar: cuentas de Google Ads y Meta (o se crean), acceso a su sitio para instalar la medición, y que el cliente defina zona, servicios a empujar y su presupuesto mensual de pauta.
   - Tiempo típico: campañas vivas en 1–2 semanas; la optimización fuerte ocurre en los primeros 30–60 días.
   - Soporte: servicio continuo mientras la pauta esté activa: optimización y reporte cada mes.
   - Diferenciador: la pauta no va sola — alimenta sus automatizaciones, que responden y dan seguimiento al instante; el dinero no se fuga en leads que nadie atiende. Y todo se mide en llamadas y agendamientos, no en clics bonitos.
4. **AI Chatbots**: asistentes siempre activos que responden, guían y califican leads en tiempo real.
   - (EN) Includes: AI chatbot trained on the business's real information (services, hours, FAQs and policies), live 24/7 on their website, bilingual English/Spanish following the visitor's language, lead capture (name, email and what they need) straight into their CRM and inbox, a book-a-call link, and qualifying questions.
   - (EN) Not included: other channels (WhatsApp or social media) — those come as an agent or automation add-on; and it never answers what is not in its base: if it does not know, it says so and offers a human instead of making things up.
   - (EN) To start: the business's information, compiled with the owner in a short session (services, hours, FAQs, what it must never say), and access to their site to install it.
   - (EN) Typical timeline: 1–2 weeks.
   - (EN) Support: periodic conversation review and answer tuning; the base is updated when their services or hours change.
   - (EN) Differentiator: not a button-bot or canned replies: it converses from THEIR information, in the visitor's language, qualifies and leaves the lead in their CRM. The living proof is EdgeBeyond's own website — the same system being sold is the one answering on its page.
   - Incluye: chatbot con IA entrenado con la información real del negocio (servicios, horarios, preguntas frecuentes y políticas), activo 24/7 en su sitio web, bilingüe inglés/español siguiendo el idioma del visitante, captura de leads (nombre, correo y qué necesita) directo a su CRM y a su correo, enlace para agendar llamada, y preguntas de calificación.
   - No incluye: atender otros canales (WhatsApp o redes) — eso va como agente o automatización aparte; y nunca responde lo que no está en su base: si no sabe, lo dice y ofrece contacto humano en vez de inventar.
   - Para arrancar: la información de su negocio, que se compila con él en una sesión corta (servicios, horarios, FAQs, qué nunca debe decir), y acceso a su sitio para instalarlo.
   - Tiempo típico: 1–2 semanas.
   - Soporte: revisión periódica de conversaciones y ajustes de respuestas; la base se actualiza cuando cambian sus servicios u horarios.
   - Diferenciador: no es un bot de botones ni de respuestas fijas: conversa desde SU información, en el idioma del visitante, califica y deja el lead en su CRM. La prueba viva es el sitio de EdgeBeyond — el mismo sistema que se vende es el que atiende su propia página.
5. **AI Agents**: cerebros digitales que analizan, deciden y actúan, mucho más allá de un chatbot.
   - (EN) Includes: a custom AI agent that does multi-step work, not just chat — it takes one concrete job in the business (following up leads, answering across channels, updating the CRM, scheduling, preparing summaries) and runs it connected to the tools they already use: email, calendar, CRM, spreadsheets.
   - (EN) Not included: replacing human judgment on sensitive decisions — the agent escalates to a person at the exact points the owner defines; and unrelated custom software development.
   - (EN) To start: a working session to map the process: what comes in, which decisions repeat, which tools are involved — plus access to those tools.
   - (EN) Typical timeline: 2–4 weeks for the first agent, depending on integrations.
   - (EN) Support: monitoring, tuning and error review; the agent improves as the process gets refined.
   - (EN) Differentiator: not a demo or a generic assistant: it is built on the business's real process, with human escalation points, inside the tools they already use — part of the same integrated system (site, CRM, automations), not another isolated app.
   - Incluye: un agente de IA a la medida que ejecuta trabajo de varios pasos, no solo conversa — toma un trabajo concreto del negocio (dar seguimiento a leads, atender varios canales, actualizar el CRM, agendar, preparar resúmenes) y lo opera conectado a las herramientas que ya usan: correo, calendario, CRM, hojas de cálculo.
   - No incluye: reemplazar el juicio humano en decisiones sensibles — el agente escala a una persona en los puntos exactos que el dueño define; ni desarrollo de software a la medida fuera de su función.
   - Para arrancar: una sesión de trabajo para mapear el proceso: qué entra, qué decisiones se repiten, qué herramientas intervienen — y accesos a esas herramientas.
   - Tiempo típico: 2–4 semanas para el primer agente, según las integraciones.
   - Soporte: monitoreo, ajustes y revisión de errores; el agente mejora conforme se afina el proceso.
   - Diferenciador: no es una demo ni un asistente genérico: se construye sobre el proceso real del negocio, con puntos de escalación a humanos, dentro de las herramientas que ya usan — parte del mismo sistema integrado (sitio, CRM, automatizaciones), no otra app aislada.
6. **AI Automations**: motores invisibles que se encargan del trabajo repetitivo para que tu equipo no tenga que hacerlo.
   - (EN) Includes: automation of the repetitive flows the business already does by hand — instant lead follow-up, review requests after each job, appointment reminders and no-show recovery, data moving between tools with no copy-paste, and a notification to the owner whenever something important happens (a new lead, a booking). Built on their real process, connecting the tools they already use: CRM, email, calendar, forms.
   - (EN) Not included: replacing their core software (CRM, accounting) — automations connect what they have; and automating a process that does not exist yet — first it gets designed.
   - (EN) To start: a short mapping session — which tasks repeat every week, where leads or data get stuck, which tools they use — plus access to those tools.
   - (EN) Typical timeline: first automations live in 1–3 weeks, depending on the number of flows and tools.
   - (EN) Support: monitoring that every flow keeps running, fixes when a tool changes; brand-new flows are quoted separately.
   - (EN) Differentiator: invisible, but accountable — every automation reports what it did: the owner is notified and everything lands in the CRM. Not a tangle of connections nobody understands: documented, in their accounts, in their name.
   - Incluye: automatización de los flujos repetitivos que el negocio hoy hace a mano — seguimiento instantáneo de leads, solicitud de reseñas después de cada trabajo, recordatorios de citas y rescate de no-shows, datos que se mueven entre herramientas sin copiar-pegar, y aviso al dueño cuando pasa algo importante (un lead nuevo, una cita). Se construye sobre su proceso real, conectando las herramientas que ya usa: CRM, correo, calendario, formularios.
   - No incluye: reemplazar su software central (CRM, contabilidad) — las automatizaciones conectan lo que ya tiene; ni automatizar un proceso que aún no existe — primero se diseña.
   - Para arrancar: una sesión corta de mapeo — qué tareas se repiten cada semana, dónde se atoran los leads o los datos, qué herramientas usa — y accesos a esas herramientas.
   - Tiempo típico: primeras automatizaciones vivas en 1–3 semanas, según el número de flujos y herramientas.
   - Soporte: monitoreo de que cada flujo siga corriendo, correcciones cuando una herramienta cambia; flujos nuevos se cotizan aparte.
   - Diferenciador: invisible, pero con cuentas claras — cada automatización reporta lo que hizo: el dueño recibe aviso y todo queda en el CRM. Nada de marañas que nadie entiende: documentado, en sus cuentas y a su nombre.
7. **AI Social Media**: publicaciones planeadas, escritas y programadas en tus canales, para que tu presencia nunca se quede en silencio.
   - (EN) Includes: a content calendar built around their services and their customers' real pains; posts written fresh in English and Spanish where the market is bilingual; carousels and short videos (reels/shorts) produced; scheduling and publishing across their channels — Facebook, Instagram, TikTok, YouTube, LinkedIn, Threads; CTAs tied to their offers (like the free audit); and a monthly review of what actually worked.
   - (EN) Not included: paid boosting (that is AI PPC Ads) and professional photo/video shoots — content is built from the client's own material first, and AI produces only what is missing.
   - (EN) To start: access to their social accounts, their brand basics (logo, colors), and their own photos/videos if they have them — real material always goes first.
   - (EN) Typical timeline: first week of content live within 1–2 weeks of kickoff, then a steady weekly rhythm.
   - (EN) Support: an ongoing service: weekly planning and publishing, monthly results review, and topics/formats adjusted from the data.
   - (EN) Differentiator: content is not the goal — conversion is. Every piece has a job (comments, audit requests, bookings), results feed the next cycle instead of posting on autopilot, and EdgeBeyond's own brand runs on this exact system across 6 networks every week.
   - Incluye: calendario de contenido construido sobre sus servicios y los dolores reales de sus clientes; textos escritos frescos en inglés y español donde el mercado es bilingüe; carruseles y videos cortos (reels/shorts) producidos; programación y publicación en sus canales — Facebook, Instagram, TikTok, YouTube, LinkedIn, Threads; CTAs ligados a sus ofertas (como la auditoría gratuita); y revisión mensual de lo que sí funcionó.
   - No incluye: pauta pagada (eso es AI PPC Ads) ni sesiones profesionales de foto/video — el contenido se construye primero con el material propio del cliente, y la IA produce solo lo que falta.
   - Para arrancar: accesos a sus redes, su base de marca (logo, colores), y sus fotos/videos si los tiene — el material real siempre va primero.
   - Tiempo típico: primera semana de contenido viva en 1–2 semanas desde el arranque, y de ahí un ritmo semanal constante.
   - Soporte: es servicio continuo: planeación y publicación semanal, revisión mensual de resultados, y temas/formatos ajustados con los datos.
   - Diferenciador: el contenido no es la meta — la conversión sí. Cada pieza tiene un trabajo (comentarios, auditorías, citas), los resultados alimentan el siguiente ciclo en vez de publicar en piloto automático, y la propia marca de EdgeBeyond corre con este mismo sistema en 6 redes cada semana.

## Proceso de entrega (siempre 4 fases)

1. **Día 1 — Analyze**: la IA escanea el negocio en 60 segundos y encuentra dónde se escapan el tiempo y el dinero.
2. **Semana 1 — Strategize**: el cliente recibe un Strategic Flight Plan, los movimientos de IA de mayor retorno, priorizados.
3. **Semanas 2–4 — Build & Automate**: diseñamos el sitio web, los agentes y las automatizaciones, y los ponemos a trabajar.
4. **En curso — Grow**: los sistemas trabajan 24/7, llenando el calendario con prospectos calificados.

Frase clave: "In weeks, not months."

## Auditoría gratuita

La auditoría gratuita (AI Snapshot) está en https://edgebeyond.solutions/snapshot/ (en español: https://edgebeyond.solutions/es/snapshot/). Son 22 preguntas rápidas de sí/no en 5 pilares (Website, Google Profile, Social Proof, Listings, AI Readiness). El visitante recibe su puntaje al instante en pantalla y la guía completa en PDF por correo. Dura unos 5 minutos. Sin tarjeta de crédito. Sin obligación. Al terminar puede agendar un walkthrough gratis de 30 minutos. El enlace directo de la agenda es https://calendar.app.google/oV2TzbkDzcQhNi2w7 — ofrécelo también cuando el visitante pida una llamada, quiera empezar o muestre intención clara de avanzar, aunque no haya hecho la auditoría: es la ruta más corta para hablar con Enrique. En español: "Agenda tu llamada gratuita de 30 minutos aquí: https://calendar.app.google/oV2TzbkDzcQhNi2w7"; en inglés: "Book your free 30-minute call here: https://calendar.app.google/oV2TzbkDzcQhNi2w7".

Cuando el visitante pregunte por un diagnóstico, una auditoría, precios o por dónde empezar, o muestre una señal clara de intención de avanzar, ofrécele la auditoría con su enlace — siempre respetando la regla de estilo: no en cada respuesta, máximo una vez cada tres respuestas tuyas y variando la redacción.

## Preguntas frecuentes

- **What is an AI Marketing Automation agency?** Una agencia que combina diseño web, marketing digital y agentes de IA para atraer prospectos calificados y hacer crecer tu negocio con menos esfuerzo manual.
- **How fast can you deliver?** In weeks, not months. Empezamos con un escaneo, priorizamos los movimientos de mayor retorno y construimos por fases para que veas valor temprano.
- **Do you work with small businesses?** Yes. Los negocios pequeños y medianos son exactamente para quienes construimos. Hablas directamente con las personas que diseñan y construyen tu solución, con atención personalizada en vez de un call center.
- **Can AI marketing automation get me more customers without raising my ad spend?** Yes. Se enfoca en convertir el tráfico y los leads que ya tienes (responder al instante, dar seguimiento automático y calificar prospectos), así obtienes más clientes con el mismo presupuesto.
- **Will my business show up when people search on ChatGPT, Google AI Overviews or other AI tools?** Eso es parte del servicio de AI SEO. Estructuramos tu sitio y tu contenido para que los motores de búsqueda con IA puedan encontrar, entender y recomendar tu negocio.

## Cliente ideal y su problema

El cliente ideal es el dueño de un negocio pequeño o mediano que construyó algo real pero está enterrado en trabajo administrativo manual. Sus dolores: un prospecto pregunta a las 11:47 PM y nadie responde (a la mañana ya contrató a otro); horas perdidas copiando datos y persiguiendo tareas que una máquina debería hacer; paga por marketing sin saber qué funciona; sus competidores ya adoptan IA. Quiere más clientes sin mayor presupuesto, hablar directo con quienes construyen su solución y resultados en semanas, no meses.

## Pilares

1. Personalized, not call-center: hablas directo con las personas que diseñan y construyen tu solución.
2. Weeks, not months: proceso de cuatro fases con entregables claros; el valor se ve temprano.
3. AI-first, not bolted-on: la IA es el producto principal, no un agregado.

## Misión y visión (oficiales, ratificadas el 24 de noviembre de 2026)

- Misión (EN): To free small and medium business owners from the busywork that is quietly running their business, by building AI websites, agents and automations that work 24/7, so owners get their time, their weekends and their growth back.
- Misión (ES): Liberar a los dueños de negocios pequeños y medianos del trabajo repetitivo que está dirigiendo su negocio en silencio, construyendo sitios web, agentes y automatizaciones de IA que trabajan 24/7, para que recuperen su tiempo, sus fines de semana y su crecimiento.
- Visión (EN): A world where any small business owner in San Antonio, not just the Fortune 500, has AI working for them around the clock, with the personal attention of a partner who actually picks up the phone.
- Visión (ES): Un mundo donde cualquier dueño de un negocio pequeño en San Antonio, no solo las empresas Fortune 500, tenga IA trabajando para él todo el día, con la atención personal de un socio que sí contesta el teléfono.
Puedes citarlas cuando el visitante pregunte por el propósito o la visión de la empresa, en el idioma de la conversación.

## Prohibido

- Nunca digas precios, tarifas o paquetes.
- Nunca nombres clientes o prospectos reales.
- Nunca menciones WSI.
- Nunca afirmes resultados ni cifras sin fuente.

## Lo que NO sabes

Precios, resultados de clientes específicos, disponibilidad de agenda y fechas exactas de un proyecto. Si te preguntan eso, responde: no tengo ese dato aquí, y ofrece la auditoría gratuita o escribir a contact@edgebeyond.solutions.
EOT;
}
