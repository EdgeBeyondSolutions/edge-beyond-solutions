<?php
// TEMPORAL: diagnóstico del envío de la bienvenida. Borrar al terminar.
declare(strict_types=1);
if (($_GET['t'] ?? '') !== 'ebdiag-7f3a') {
    http_response_code(404);
    exit;
}
$to = 'admin.edgebeyond@gmail.com';
$es = <<<'EBTPL'
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#eef0f2;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef0f2;padding:32px 16px;"><tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;border:1px solid #e3e5e8;"><tr><td style="background-color:#141b24;padding:28px 36px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle" style="padding-right:12px;"><img src="https://edgebeyond.solutions/logo-mark-color.png" width="36" height="36" alt="EdgeBeyond" style="display:block;border-radius:8px;"></td><td valign="middle" style="font-size:18px;font-weight:700;color:#f3f2eb;letter-spacing:-0.2px;">EdgeBeyond <span style="color:#c0f447;">Solutions</span></td></tr></table></td></tr><tr><td style="padding:40px 36px 8px 36px;"><p style="margin:0 0 6px 0;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#00758c;">Auditor&iacute;a gratuita de presencia digital e IA</p><h1 style="margin:0 0 18px 0;font-size:24px;line-height:1.3;color:#141b24;font-weight:700;">Hola ${Leads.First Name}, gracias por escribirnos.</h1><p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#4a515c;">Gracias por platicar con nuestro asistente en edgebeyond.solutions. El siguiente paso es tu <strong style="color:#141b24;">auditor&iacute;a gratuita</strong> de presencia digital e inteligencia artificial: son 22 preguntas, toma unos 5 minutos y recibes tu puntaje al instante, con tu reporte completo en PDF.</p><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="border-radius:100px;background-color:#c0f447;"><a href="https://edgebeyond.solutions/es/snapshot/" style="display:inline-block;padding:14px 32px;font-size:14px;font-weight:700;color:#141b24;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">Hacer mi auditor&iacute;a gratuita &rarr;</a></td></tr></table><p style="margin:22px 0 0 0;font-size:14px;line-height:1.6;color:#4a515c;">Y si prefieres platicarlo directamente, responde a este correo.</p><p style="margin:26px 0 0 0;font-size:15px;line-height:1.6;color:#141b24;">&mdash; Enrique Ballesteros<br><span style="color:#4a515c;">EdgeBeyond Solutions</span></p></td></tr><tr><td align="center" style="padding:24px 36px 4px 36px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:10px;font-weight:700;color:#c0f447;">IG</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.facebook.com/EdgeBeyondSolutions/" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:12px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">f</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.pinterest.com/EdgeBeyondS/" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">P</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.threads.com/@edgebeyondsolutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:12px;font-weight:700;color:#c0f447;">@</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.tiktok.com/@edgebeyond.solutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:9px;font-weight:700;color:#c0f447;">TT</td></tr></table></a></td><td style="padding-right:5px;"><a href="https://www.youtube.com/@EdgeBeyondSolutions" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;font-family:Georgia,serif;">&#9654;</td></tr></table></a></td><td><a href="https://x.com/EdgeBeyondS" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:24px;height:24px;background-color:#141b24;border-radius:6px;"><tr><td align="center" valign="middle" style="font-size:11px;font-weight:700;color:#c0f447;">X</td></tr></table></td></tr><tr><td style="padding:0 36px 28px 36px;"><p style="margin:0;font-size:11px;color:#b3b8bd;line-height:1.6;">Recibes este correo porque platicaste con nuestro asistente en edgebeyond.solutions.</p></td></tr></table></td></tr></table></body></html>
EBTPL;
$es = str_replace('${Leads.First Name}', 'Prueba', $es);
$subject = '=?UTF-8?B?' . base64_encode('EB mailtest') . '?=';
$results = [];

// V1: réplica exacta del envío actual (From + HTML en una sola línea)
$h1 = "MIME-Version: 1.0\r\n" . "Content-Type: text/html; charset=utf-8\r\n"
    . 'From: EdgeBeyond Solutions <contact@edgebeyond.solutions>' . "\r\n"
    . 'Reply-To: contact@edgebeyond.solutions';
$results['V1_from_html_linea_larga'] = @mail($to, $subject . ' V1', $es, $h1);

// V2: igual que V1 pero el cuerpo con líneas cortas (wordwrap)
$h2 = $h1;
$results['V2_from_html_wordwrap'] = @mail($to, $subject . ' V2', wordwrap($es, 180, "\r\n"), $h2);

// V3: From sin nombre visible
$h3 = "MIME-Version: 1.0\r\n" . "Content-Type: text/html; charset=utf-8\r\n"
    . 'From: contact@edgebeyond.solutions' . "\r\n"
    . 'Reply-To: contact@edgebeyond.solutions';
$results['V3_from_sin_nombre'] = @mail($to, $subject . ' V3', $es, $h3);

// V4: sin From (solo MIME + Content-Type), HTML completo
$h4 = "MIME-Version: 1.0\r\n" . "Content-Type: text/html; charset=utf-8";
$results['V4_sin_from'] = @mail($to, $subject . ' V4', $es, $h4);

// V5: con From pero cuerpo HTML corto
$results['V5_from_html_corto'] = @mail($to, $subject . ' V5', '<html><body><p>Hola Prueba, gracias por escribirnos.</p></body></html>', $h1);

// V6: con From y cuerpo texto plano
$h6 = 'From: EdgeBeyond Solutions <contact@edgebeyond.solutions>' . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
$results['V6_from_texto'] = @mail($to, $subject . ' V6', 'Hola Prueba, gracias por escribirnos.', $h6);

header('Content-Type: application/json');
echo json_encode($results);
