<?php
// Sistema de feedback (F1) — exportación de los datos recogidos por
// feedback.php, para descargarlos con scripts/36-pull-feedback.mjs.
//
//   GET /api/feedback-export.php[?since=YYYY-MM-DD]
//   Authorization: Bearer <EXPORT_TOKEN>
//
// Sin token válido (o sin configurar) responde 404, no 401: así no revela ni
// siquiera que el endpoint existe. El token se compara con hash_equals()
// (tiempo constante) y se exige un mínimo de 32 caracteres en config.

declare(strict_types=1);

const FEEDBACK_ENTRY = true;
require __DIR__ . '/_feedback-common.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !feedback_load_config() || strlen(EXPORT_TOKEN) < 32) {
    feedback_finish(404);
}

// Apache con PHP-FPM/CGI no pasa Authorization a $_SERVER por defecto:
// public/api/.htaccess la copia a HTTP_AUTHORIZATION (y, tras una
// redirección interna, llega como REDIRECT_HTTP_AUTHORIZATION).
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $auth = $value;
        }
    }
}
if (preg_match('/^Bearer\s+(\S+)$/', $auth, $m) !== 1 || !hash_equals(EXPORT_TOKEN, $m[1])) {
    feedback_finish(404);
}

$since = '0000-00-00';
if (isset($_GET['since'])) {
    if (!is_string($_GET['since']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['since']) !== 1) {
        feedback_finish(400);
    }
    $since = $_GET['since'];
}

try {
    $pdo = feedback_db();
    feedback_purge_old($pdo);
    $pdo->prepare('DELETE FROM rate WHERE day < ?')->execute([gmdate('Y-m-d')]);
    $select = function (string $sql) use ($pdo, $since): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$since]);
        return $stmt->fetchAll();
    };
    $out = [
        'generatedAt' => gmdate('c'),
        'since' => $since === '0000-00-00' ? null : $since,
        'flags' => $select('SELECT * FROM flags WHERE created_at >= ? ORDER BY id'),
        'ratings' => $select('SELECT * FROM ratings WHERE created_at >= ? ORDER BY id'),
        'rounds' => $select('SELECT * FROM rounds WHERE created_at >= ? ORDER BY id'),
        'round_answers' => $select('SELECT ra.* FROM round_answers ra JOIN rounds r ON r.id = ra.round_id
            WHERE r.created_at >= ? ORDER BY ra.round_id, ra.position'),
        'reports' => $select('SELECT * FROM reports WHERE created_at >= ? ORDER BY id'),
    ];
} catch (Throwable $e) {
    feedback_finish(503);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
