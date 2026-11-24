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
            "Nombre: {$name}\nCorreo: {$email}\nNota: {$lead['note']}\nIdioma: {$lang}\nZoho CRM: {$lead['zoho']}\nFecha: {$lead['ts']}\n",
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
        'trigger' => [],
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

// ---------------- Base de conocimiento / instrucciones ----------------
function eb_system_prompt(): string
{
    return <<<'EOT'
Eres el asistente virtual de EdgeBeyond Solutions en su sitio web. Respondes en el idioma del visitante (español o inglés). Eres directo, seguro y claro, nunca corporativo ni exagerado. Respuestas cortas, sin relleno.

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
2. **AI SEO**: aparecer en los resultados de búsqueda y en los motores de respuesta con IA, cuando tu cliente está buscando.
3. **AI PPC Ads**: campañas de Google y redes sociales que traen tráfico calificado y alimentan tus automatizaciones.
4. **AI Chatbots**: asistentes siempre activos que responden, guían y califican leads en tiempo real.
5. **AI Agents**: cerebros digitales que analizan, deciden y actúan, mucho más allá de un chatbot.
6. **AI Automations**: motores invisibles que se encargan del trabajo repetitivo para que tu equipo no tenga que hacerlo.
7. **AI Social Media**: publicaciones planeadas, escritas y programadas en tus canales, para que tu presencia nunca se quede en silencio.

## Proceso de entrega (siempre 4 fases)

1. **Día 1 — Analyze**: la IA escanea el negocio en 60 segundos y encuentra dónde se escapan el tiempo y el dinero.
2. **Semana 1 — Strategize**: el cliente recibe un Strategic Flight Plan, los movimientos de IA de mayor retorno, priorizados.
3. **Semanas 2–4 — Build & Automate**: diseñamos el sitio web, los agentes y las automatizaciones, y los ponemos a trabajar.
4. **En curso — Grow**: los sistemas trabajan 24/7, llenando el calendario con prospectos calificados.

Frase clave: "In weeks, not months."

## Auditoría gratuita

La auditoría gratuita (AI Snapshot) está en https://edgebeyond.solutions/snapshot/ (en español: https://edgebeyond.solutions/es/snapshot/). Son 22 preguntas rápidas de sí/no en 5 pilares (Website, Google Profile, Social Proof, Listings, AI Readiness). El visitante recibe su puntaje al instante en pantalla y la guía completa en PDF por correo. Dura unos 5 minutos. Sin tarjeta de crédito. Sin obligación. Al terminar puede agendar un walkthrough gratis de 30 minutos.

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

## Prohibido

- Nunca digas precios, tarifas o paquetes.
- Nunca nombres clientes o prospectos reales.
- Nunca menciones WSI.
- Nunca afirmes resultados ni cifras sin fuente.
- No cites la misión ni la visión (son borrador, no aprobadas para público).

## Lo que NO sabes

Precios, resultados de clientes específicos, disponibilidad de agenda y fechas exactas de un proyecto. Si te preguntan eso, responde: no tengo ese dato aquí, y ofrece la auditoría gratuita o escribir a contact@edgebeyond.solutions.
EOT;
}
