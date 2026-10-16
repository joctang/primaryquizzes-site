<?php
// Sistema de feedback (F1) — código compartido por feedback.php y
// feedback-export.php. No es un endpoint: public/api/.htaccess deniega su
// acceso directo por web y, por si ese .htaccess no se aplicara (p. ej. en
// `php -S`), la constante de abajo corta la ejecución si se pide suelto.
//
// Datos y configuración viven FUERA de public_html: public_html/api/ ->
// ../../feedback-data/ (dirname(__DIR__, 2)). Así ni la base SQLite ni el
// token de exportación pueden descargarse nunca por URL, aunque falle la
// configuración del servidor web. La variable de entorno FEEDBACK_DATA_DIR
// (solo la fija quien controla el servidor; nunca viene de la petición)
// permite apuntar a otro directorio en pruebas locales.

declare(strict_types=1);

if (!defined('FEEDBACK_ENTRY')) {
    http_response_code(404);
    exit;
}

// Feedback rows older than 90 days are removed automatically,
// como mucho una vez al día (ver feedback_purge_old()).
const FEEDBACK_RETENTION_DAYS = 90;

function feedback_data_dir(): string
{
    $env = getenv('FEEDBACK_DATA_DIR');
    $dir = ($env !== false && $env !== '') ? $env : dirname(__DIR__, 2) . '/feedback-data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

// Carga feedback-data/config.php. Devuelve false si no existe o si le faltan
// las constantes obligatorias: el llamador responde sin revelar el motivo.
function feedback_load_config(): bool
{
    $file = feedback_data_dir() . '/config.php';
    if (!is_file($file)) {
        return false;
    }
    require_once $file;
    return defined('EXPORT_TOKEN') && defined('DAILY_SALT_SECRET')
        && is_string(EXPORT_TOKEN) && is_string(DAILY_SALT_SECRET)
        && strlen(EXPORT_TOKEN) >= 32 && strlen(DAILY_SALT_SECRET) >= 32
        && !str_starts_with(EXPORT_TOKEN, 'CAMBIA-ESTO')
        && !str_starts_with(DAILY_SALT_SECRET, 'CAMBIA-ESTO');
}

function feedback_db(): PDO
{
    $pdo = new PDO('sqlite:' . feedback_data_dir() . '/feedback.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Enteros como enteros en la exportación (no "1" como texto).
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS flags (
            id INTEGER PRIMARY KEY,
            created_at TEXT NOT NULL,
            quiz_id TEXT NOT NULL,
            qid TEXT NOT NULL,
            qhash TEXT NOT NULL,
            reason TEXT NOT NULL,
            answered_correct INTEGER NOT NULL,
            picked_index INTEGER NULL,
            context TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS flags_qid ON flags(qid);
        CREATE INDEX IF NOT EXISTS flags_quiz_id ON flags(quiz_id);

        CREATE TABLE IF NOT EXISTS ratings (
            id INTEGER PRIMARY KEY,
            created_at TEXT NOT NULL,
            quiz_id TEXT NOT NULL,
            mood TEXT NOT NULL,
            difficulty TEXT NULL,
            score INTEGER NOT NULL,
            total INTEGER NOT NULL,
            practice INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS ratings_quiz_id ON ratings(quiz_id);

        CREATE TABLE IF NOT EXISTS rounds (
            id INTEGER PRIMARY KEY,
            created_at TEXT NOT NULL,
            quiz_id TEXT NOT NULL,
            score INTEGER NOT NULL,
            total INTEGER NOT NULL,
            practice INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS rounds_quiz_id ON rounds(quiz_id);

        CREATE TABLE IF NOT EXISTS round_answers (
            round_id INTEGER NOT NULL REFERENCES rounds(id) ON DELETE CASCADE,
            qid TEXT NOT NULL,
            qhash TEXT NOT NULL,
            picked_index INTEGER NOT NULL,
            correct INTEGER NOT NULL,
            position INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS round_answers_qid ON round_answers(qid);
        CREATE INDEX IF NOT EXISTS round_answers_round_id ON round_answers(round_id);

        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY,
            created_at TEXT NOT NULL,
            quiz_id TEXT NOT NULL,
            reason TEXT NULL,
            role TEXT NULL,
            qid TEXT NULL,
            qhash TEXT NULL
        );
        CREATE INDEX IF NOT EXISTS reports_quiz_id ON reports(quiz_id);

        CREATE TABLE IF NOT EXISTS rate (
            key TEXT PRIMARY KEY,
            day TEXT NOT NULL,
            count INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS meta (
            name TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        SQL);
    // Los avisos anteriores solo guardaban quiz_id y hora. Añadir columnas
    // opcionales conserva esos registros y permite que sigan llegando avisos
    // desde una página antigua que aún esté abierta en el navegador.
    $reportColumns = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(), 'name');
    foreach (['reason', 'role', 'qid', 'qhash'] as $column) {
        if (in_array($column, $reportColumns, true)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE reports ADD COLUMN $column TEXT NULL");
        } catch (PDOException $e) {
            // Otra petición pudo completar la misma migración entre la
            // consulta PRAGMA y ALTER TABLE.
            if (!str_contains($e->getMessage(), 'duplicate column name')) {
                throw $e;
            }
        }
    }
    return $pdo;
}

// Remove rows older than FEEDBACK_RETENTION_DAYS days. Runs
// como mucho una vez al día (marca en la tabla meta), así no añade coste a
// cada petición. created_at es texto "YYYY-MM-DD HH:00" en UTC, comparable
// lexicográficamente.
function feedback_purge_old(PDO $pdo): void
{
    $today = gmdate('Y-m-d');
    $last = $pdo->query("SELECT value FROM meta WHERE name = 'last_purge'")->fetchColumn();
    if ($last === $today) {
        return;
    }
    $cutoff = gmdate('Y-m-d', strtotime('-' . FEEDBACK_RETENTION_DAYS . ' days'));
    $pdo->beginTransaction();
    foreach (['flags', 'ratings', 'rounds', 'reports'] as $table) {
        // round_answers se borra en cascada con su ronda (ON DELETE CASCADE).
        $stmt = $pdo->prepare("DELETE FROM $table WHERE created_at < ?");
        $stmt->execute([$cutoff]);
    }
    $stmt = $pdo->prepare("INSERT INTO meta(name, value) VALUES('last_purge', ?)
        ON CONFLICT(name) DO UPDATE SET value = excluded.value");
    $stmt->execute([$today]);
    $pdo->commit();
}

function feedback_finish(int $status): never
{
    http_response_code($status);
    exit;
}
