<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(400, ['error' => 'JSON invalido']);
    }

    return $data;
}

function config(): array
{
    $path = __DIR__ . '/db.json';
    if (!is_file($path)) {
        respond(500, ['error' => 'No se encontro db.json']);
    }

    $config = json_decode((string) file_get_contents($path), true);
    if (!is_array($config)) {
        respond(500, ['error' => 'db.json no es JSON valido']);
    }

    foreach (['host', 'usr', 'db_name'] as $key) {
        if (!array_key_exists($key, $config)) {
            respond(500, ['error' => "Falta {$key} en db.json"]);
        }
    }

    return $config;
}

function db(): PDO
{
    $config = config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['db_name']
    );

    try {
        return new PDO($dsn, $config['usr'], $config['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $exception) {
        respond(500, ['error' => 'No se pudo conectar a la base de datos', 'detail' => $exception->getMessage()]);
    }
}

function table_name(PDO $pdo): string
{
    foreach (['partidos', 'pardidos'] as $candidate) {
        $statement = $pdo->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$candidate]);
        if ($statement->fetchColumn()) {
            return $candidate;
        }
    }

    respond(500, ['error' => 'No existe la tabla partidos ni pardidos']);
}

function next_id(PDO $pdo, string $table): int
{
    $statement = $pdo->prepare(
        'SELECT AUTO_INCREMENT
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);
    $value = $statement->fetchColumn();

    if ($value !== false && $value !== null) {
        return (int) $value;
    }

    $statement = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$table}`");
    return (int) $statement->fetchColumn();
}

function normalize_segment(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $converted === false ? $value : $converted;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');

    return $value === '' ? 'sin_nombre' : $value;
}

function id_partido(int $id, string $equipo1, string $equipo2, string $fechaHora): string
{
    $date = str_replace('-', '', substr($fechaHora, 0, 10));
    return implode('_', [
        $id,
        normalize_segment($equipo1),
        normalize_segment($equipo2),
        $date,
    ]);
}

function score(array $data, string $key): ?int
{
    if (!array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
        return null;
    }

    if (!is_numeric($data[$key]) || (int) $data[$key] < 0) {
        respond(422, ['error' => "{$key} debe ser un entero mayor o igual a 0"]);
    }

    return (int) $data[$key];
}

function clean_match(array $data): array
{
    $fechaHora = trim((string) ($data['fecha_hora'] ?? ''));
    $equipo1 = trim((string) ($data['equipo1'] ?? ''));
    $equipo2 = trim((string) ($data['equipo2'] ?? ''));

    if ($fechaHora === '' || $equipo1 === '' || $equipo2 === '') {
        respond(422, ['error' => 'fecha_hora, equipo1 y equipo2 son obligatorios']);
    }

    $fechaHora = str_replace('T', ' ', $fechaHora);
    if (strlen($fechaHora) === 16) {
        $fechaHora .= ':00';
    }

    return [
        'fecha_hora' => $fechaHora,
        'equipo1' => $equipo1,
        'equipo2' => $equipo2,
        'result_eq1' => score($data, 'result_eq1'),
        'result_eq2' => score($data, 'result_eq2'),
    ];
}

$pdo = db();
$table = table_name($pdo);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $statement = $pdo->query(
            "SELECT id, id_partido, fecha_hora, equipo1, equipo2, result_eq1, result_eq2, created_at, updated_at
             FROM `{$table}`
             ORDER BY fecha_hora DESC, id DESC"
        );
        respond(200, [
            'table' => $table,
            'next_id' => next_id($pdo, $table),
            'data' => $statement->fetchAll(),
        ]);
    }

    if ($method === 'POST') {
        $match = clean_match(input_json());
        $pdo->beginTransaction();

        $placeholder = 'pending_' . bin2hex(random_bytes(6));
        $statement = $pdo->prepare(
            "INSERT INTO `{$table}` (id_partido, fecha_hora, equipo1, equipo2, result_eq1, result_eq2)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $placeholder,
            $match['fecha_hora'],
            $match['equipo1'],
            $match['equipo2'],
            $match['result_eq1'],
            $match['result_eq2'],
        ]);

        $id = (int) $pdo->lastInsertId();
        $generatedId = id_partido($id, $match['equipo1'], $match['equipo2'], $match['fecha_hora']);
        $statement = $pdo->prepare("UPDATE `{$table}` SET id_partido = ? WHERE id = ?");
        $statement->execute([$generatedId, $id]);
        $pdo->commit();

        respond(201, ['id' => $id, 'id_partido' => $generatedId]);
    }

    if ($method === 'PUT') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            respond(400, ['error' => 'Falta id valido']);
        }

        $match = clean_match(input_json());
        $generatedId = id_partido($id, $match['equipo1'], $match['equipo2'], $match['fecha_hora']);
        $statement = $pdo->prepare(
            "UPDATE `{$table}`
             SET id_partido = ?, fecha_hora = ?, equipo1 = ?, equipo2 = ?, result_eq1 = ?, result_eq2 = ?
             WHERE id = ?"
        );
        $statement->execute([
            $generatedId,
            $match['fecha_hora'],
            $match['equipo1'],
            $match['equipo2'],
            $match['result_eq1'],
            $match['result_eq2'],
            $id,
        ]);

        respond(200, ['id' => $id, 'id_partido' => $generatedId]);
    }

    if ($method === 'DELETE') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            respond(400, ['error' => 'Falta id valido']);
        }

        $statement = $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?");
        $statement->execute([$id]);
        respond(200, ['deleted' => $id]);
    }

    respond(405, ['error' => 'Metodo no permitido']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['error' => 'Error en la operacion', 'detail' => $exception->getMessage()]);
}
