<?php
// Sistema de feedback (F1) — recibe eventos anónimos del quiz y los guarda
// en SQLite fuera de public_html. Tipos, todos con lista blanca estricta:
//   - flag:   un niño señala un problema en una pregunta (motivo cerrado,
//             nunca texto libre).
//   - rating: carita + dificultad al terminar una ronda.
//   - round:  qué opción (índice ORIGINAL en q.options) se eligió en cada
//             pregunta de una ronda, para las estadísticas por pregunta.
//   - report: valoración cerrada y, opcionalmente, rol y pregunta concreta.
//             Los avisos antiguos sin estos campos siguen siendo válidos.
//
// Privacidad (AVG/GDPR, público infantil): no se guarda IP, ni cookies, ni
// identificador de usuario, ni texto libre. La hora se redondea a la hora
// entera para no dejar huellas temporales finas. El límite de peticiones usa
// sha256(IP + día + secreto) como clave: no es reversible, cambia cada día y
// la fila se borra al día siguiente.
//
// Respuestas: 204 éxito · 400 petición inválida · 403 origen no permitido ·
// 405 método · 429 límite diario · 503 sin configurar. Nunca con detalles.

declare(strict_types=1);

const FEEDBACK_ENTRY = true;
require __DIR__ . '/_feedback-common.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_BODY_BYTES = 8192;
const FLAG_REASONS = ['wrong_answer', 'unclear', 'image', 'typo', 'other_answer_correct'];
const FLAG_CONTEXTS = ['question', 'review'];
const MOODS = ['happy', 'neutral', 'sad'];
const DIFFICULTIES = ['too_easy', 'just_right', 'too_hard'];
const REPORT_REASONS = ['positive', 'wrong_answer', 'unclear', 'too_easy', 'too_hard', 'typo', 'image'];
const REPORT_ROLES = ['parent', 'carer', 'teacher'];
const RE_QUIZ_ID = '/^[A-Za-z0-9_-]{1,60}$/';
const RE_QID = '/^[A-Za-z0-9_-]{1,60}-\d{1,4}$/';
const RE_QHASH = '/^[0-9a-f]{10}$/';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    feedback_finish(405);
}

if (!feedback_load_config()) {
    feedback_finish(503);
}

// --- Origen -----------------------------------------------------------------
// Solo el propio sitio (y localhost si DEV_ALLOW está activo en config).
// Los navegadores envían Origin en todo POST con fetch; Referer queda como
// respaldo para navegadores antiguos.
function origin_allowed(string $origin): bool
{
    if ($origin === 'https://primaryquizzes.com' || $origin === 'https://www.primaryquizzes.com') {
        return true;
    }
    return defined('DEV_ALLOW') && DEV_ALLOW === true
        && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d{1,5})?$#', $origin) === 1;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === '') {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (preg_match('#^(https?://[^/]+)(/|$)#', $referer, $m) === 1) {
        $origin = $m[1];
    }
}
if (!origin_allowed($origin)) {
    feedback_finish(403);
}

// --- Forma de la petición ---------------------------------------------------
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    feedback_finish(400);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY_BYTES) {
    feedback_finish(400);
}
// Se lee como mucho MAX_BODY_BYTES + 1 aunque Content-Length mienta.
$raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if ($raw === false || $raw === '' || strlen($raw) > MAX_BODY_BYTES) {
    feedback_finish(400);
}

try {
    $pdo = feedback_db();

    // --- Límite diario sin guardar la IP ------------------------------------
    // Detrás de un proxy/CDN, REMOTE_ADDR sería la IP del proxy y todos los
    // visitantes compartirían contador: TRUST_PROXY_HEADER (config) permite
    // leer la cabecera con la IP real, p. ej. 'HTTP_X_FORWARDED_FOR'.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (defined('TRUST_PROXY_HEADER') && is_string(TRUST_PROXY_HEADER) && !empty($_SERVER[TRUST_PROXY_HEADER])) {
        $ip = trim(explode(',', (string)$_SERVER[TRUST_PROXY_HEADER])[0]);
    }
    $today = gmdate('Y-m-d');
    $limit = defined('RATE_LIMIT_PER_DAY') && is_int(RATE_LIMIT_PER_DAY) ? RATE_LIMIT_PER_DAY : 120;
    $key = hash('sha256', $ip . '|' . $today . '|' . DAILY_SALT_SECRET);

    $pdo->prepare('DELETE FROM rate WHERE day < ?')->execute([$today]);
    $pdo->prepare('INSERT INTO rate(key, day, count) VALUES(?, ?, 1)
        ON CONFLICT(key) DO UPDATE SET count = count + 1')->execute([$key, $today]);
    $countStmt = $pdo->prepare('SELECT count FROM rate WHERE key = ?');
    $countStmt->execute([$key]);
    if ((int)$countStmt->fetchColumn() > $limit) {
        feedback_finish(429);
    }

    feedback_purge_old($pdo);
} catch (Throwable $e) {
    feedback_finish(503);
}

// --- Validación (lista blanca) ----------------------------------------------
$data = json_decode($raw, true, 8);
if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
    feedback_finish(400);
}

function v_str(array $d, string $k, string $re): ?string
{
    return (isset($d[$k]) && is_string($d[$k]) && preg_match($re, $d[$k]) === 1) ? $d[$k] : null;
}

function v_enum(array $d, string $k, array $allowed): ?string
{
    return (isset($d[$k]) && is_string($d[$k]) && in_array($d[$k], $allowed, true)) ? $d[$k] : null;
}

function v_int(array $d, string $k, int $min, int $max): ?int
{
    return (array_key_exists($k, $d) && is_int($d[$k]) && $d[$k] >= $min && $d[$k] <= $max) ? $d[$k] : null;
}

function v_bool(array $d, string $k): ?bool
{
    return (array_key_exists($k, $d) && is_bool($d[$k])) ? $d[$k] : null;
}

function bad(): never
{
    feedback_finish(400);
}

$createdAt = gmdate('Y-m-d H:00');
$quizId = v_str($data, 'quiz_id', RE_QUIZ_ID) ?? bad();

try {
    switch ($data['type']) {
        case 'flag':
            $qid = v_str($data, 'qid', RE_QID) ?? bad();
            $qhash = v_str($data, 'qhash', RE_QHASH) ?? bad();
            $reason = v_enum($data, 'reason', FLAG_REASONS) ?? bad();
            $context = v_enum($data, 'context', FLAG_CONTEXTS) ?? bad();
            $answeredCorrect = v_bool($data, 'answered_correct') ?? bad();
            $picked = null;
            if (array_key_exists('picked_index', $data) && $data['picked_index'] !== null) {
                $picked = v_int($data, 'picked_index', 0, 9) ?? bad();
            }
            $pdo->prepare('INSERT INTO flags(created_at, quiz_id, qid, qhash, reason, answered_correct, picked_index, context)
                VALUES(?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$createdAt, $quizId, $qid, $qhash, $reason, $answeredCorrect ? 1 : 0, $picked, $context]);
            break;

        case 'rating':
            $mood = v_enum($data, 'mood', MOODS) ?? bad();
            $difficulty = null;
            if (array_key_exists('difficulty', $data) && $data['difficulty'] !== null) {
                $difficulty = v_enum($data, 'difficulty', DIFFICULTIES) ?? bad();
            }
            $score = v_int($data, 'score', 0, 50) ?? bad();
            $total = v_int($data, 'total', 0, 50) ?? bad();
            $practice = v_bool($data, 'practice') ?? bad();
            if ($score > $total) {
                bad();
            }
            $pdo->prepare('INSERT INTO ratings(created_at, quiz_id, mood, difficulty, score, total, practice)
                VALUES(?, ?, ?, ?, ?, ?, ?)')
                ->execute([$createdAt, $quizId, $mood, $difficulty, $score, $total, $practice ? 1 : 0]);
            break;

        case 'round':
            $score = v_int($data, 'score', 0, 50) ?? bad();
            $total = v_int($data, 'total', 0, 50) ?? bad();
            $practice = v_bool($data, 'practice') ?? bad();
            $answers = $data['answers'] ?? null;
            if (!is_array($answers) || !array_is_list($answers) || count($answers) < 1 || count($answers) > 30 || $score > $total) {
                bad();
            }
            $rows = [];
            foreach ($answers as $a) {
                if (!is_array($a)) {
                    bad();
                }
                $rows[] = [
                    v_str($a, 'qid', RE_QID) ?? bad(),
                    v_str($a, 'qhash', RE_QHASH) ?? bad(),
                    v_int($a, 'picked_index', 0, 9) ?? bad(),
                    (v_bool($a, 'correct') ?? bad()) ? 1 : 0,
                    v_int($a, 'position', 0, 29) ?? bad(),
                ];
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO rounds(created_at, quiz_id, score, total, practice) VALUES(?, ?, ?, ?, ?)')
                ->execute([$createdAt, $quizId, $score, $total, $practice ? 1 : 0]);
            $roundId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO round_answers(round_id, qid, qhash, picked_index, correct, position)
                VALUES(?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute([$roundId, ...$r]);
            }
            $pdo->commit();
            break;

        case 'report':
            $reportReason = null;
            if (array_key_exists('reason', $data) && $data['reason'] !== null) {
                $reportReason = v_enum($data, 'reason', REPORT_REASONS) ?? bad();
            }
            $reportRole = null;
            if (array_key_exists('role', $data) && $data['role'] !== null) {
                $reportRole = v_enum($data, 'role', REPORT_ROLES) ?? bad();
            }
            $reportQid = null;
            $reportQhash = null;
            if (array_key_exists('qid', $data) || array_key_exists('qhash', $data)) {
                if (($data['qid'] ?? null) !== null || ($data['qhash'] ?? null) !== null) {
                    $reportQid = v_str($data, 'qid', RE_QID) ?? bad();
                    $reportQhash = v_str($data, 'qhash', RE_QHASH) ?? bad();
                    // AU content can retain question IDs from an adapted source set.
                }
            }
            $pdo->prepare('INSERT INTO reports(created_at, quiz_id, reason, role, qid, qhash)
                VALUES(?, ?, ?, ?, ?, ?)')
                ->execute([$createdAt, $quizId, $reportReason, $reportRole, $reportQid, $reportQhash]);
            break;

        default:
            bad();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    feedback_finish(503);
}

feedback_finish(204);
