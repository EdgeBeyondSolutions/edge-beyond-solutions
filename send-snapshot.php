<?php
/**
 * Snapshot request handler — receives the "Get My Snapshot" form submission
 * and emails the prospect's details to contact@edgebeyond.solutions.
 *
 * Drop this file at the root of the site on Spaceship (same place as
 * index.html). No setup needed beyond that — Spaceship's shared hosting
 * supports PHP's mail() function out of the box.
 *
 * Once this is live, switch ROADMAP_ENDPOINT in index.html and es/index.html
 * from the Formspree URL to "/send-snapshot.php" (one line each) and the
 * form will send through this script instead.
 */

header('Content-Type: application/json; charset=utf-8');

$TO_EMAIL = 'contact@edgebeyond.solutions';
$FROM_EMAIL = 'noreply@edgebeyond.solutions'; // must be a @edgebeyond.solutions address for best deliverability

function respond($ok, $message = '') {
    http_response_code($ok ? 200 : 400);
    echo json_encode(['success' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed');
}

// Accept either a JSON body (what the site sends) or classic form POST data.
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$name = isset($data['name']) ? trim($data['name']) : '';
$website = isset($data['website']) ? trim($data['website']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';

if ($name === '' || $website === '' || $email === '') {
    respond(false, 'Missing required fields');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Invalid email address');
}

// Strip line breaks from every field before they touch mail headers or the
// message body — this is what prevents header-injection / spam-relay abuse.
function clean($value) {
    return preg_replace('/[\r\n]+/', ' ', $value);
}

$name = clean($name);
$website = clean($website);
$email = clean($email);

$subject = 'Free AI Snapshot request — ' . $name;

$body = "New Snapshot request from edgebeyond.solutions\n\n"
      . "Name: {$name}\n"
      . "Website: {$website}\n"
      . "Email: {$email}\n";

$headers = [
    'From: EdgeBeyond Website <' . $FROM_EMAIL . '>',
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=utf-8',
];

$sent = mail($TO_EMAIL, $subject, $body, implode("\r\n", $headers));

if ($sent) {
    respond(true, 'Sent');
} else {
    respond(false, 'Could not send email');
}
