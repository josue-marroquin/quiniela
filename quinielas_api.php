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

function existing_table(PDO $pdo, array $candidates, string $label): string
{
    foreach ($candidates as $candidate) {
        $statement = $pdo->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$candidate]);
        if ($statement->fetchColumn()) {
            return $candidate;
        }
    }

    respond(500, ['error' => "No existe la tabla {$label}"]);
}

function nullable_score(array $data, string $key): ?int
{
    if (!array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
        return null;
    }

    if (!is_numeric($data[$key]) || (int) $data[$key] < 0) {
        respond(422, ['error' => "{$key} debe ser un entero mayor o igual a 0"]);
    }

    return (int) $data[$key];
}

function calculate_points(array $quiniela): int
{
    return 0;
}

function clean_quiniela(array $data): array
{
    $participante = $data['participante'] ?? null;
    $idPartido = trim((string) ($data['id_partido'] ?? ''));

    if (!is_numeric($participante) || (int) $participante <= 0) {
        respond(422, ['error' => 'participante debe ser un id entero mayor a 0']);
    }

    if ($idPartido === '') {
        respond(422, ['error' => 'id_partido es obligatorio']);
    }

    $quiniela = [
        'participante' => (int) $participante,
        'id_partido' => $idPartido,
        'result_eq1' => nullable_score($data, 'result_eq1'),
        'result_eq2' => nullable_score($data, 'result_eq2'),
    ];
    $quiniela['puntos'] = calculate_points($quiniela);

    return $quiniela;
}

$pdo = db();
$quinielasTable = existing_table($pdo, ['quinielas'], 'quinielas');
$partidosTable = existing_table($pdo, ['partidos', 'pardidos'], 'partidos ni pardidos');
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $quinielas = $pdo->query(
            "SELECT id, participante, id_partido, result_eq1, result_eq2, puntos, updated_at
             FROM `{$quinielasTable}`
             ORDER BY updated_at DESC, id DESC"
        )->fetchAll();

        $partidos = $pdo->query(
            "SELECT id_partido, fecha_hora, equipo1, equipo2, result_eq1, result_eq2
             FROM `{$partidosTable}`
             ORDER BY fecha_hora DESC, id DESC"
        )->fetchAll();

        respond(200, [
            'table' => $quinielasTable,
            'partidos_table' => $partidosTable,
            'data' => $quinielas,
            'partidos' => $partidos,
        ]);
    }

    if ($method === 'POST') {
        $quiniela = clean_quiniela(input_json());
        $statement = $pdo->prepare(
            "INSERT INTO `{$quinielasTable}` (participante, id_partido, result_eq1, result_eq2, puntos)
             VALUES (?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $quiniela['participante'],
            $quiniela['id_partido'],
            $quiniela['result_eq1'],
            $quiniela['result_eq2'],
            $quiniela['puntos'],
        ]);

        respond(201, ['id' => (int) $pdo->lastInsertId(), 'puntos' => $quiniela['puntos']]);
    }

    if ($method === 'PUT') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            respond(400, ['error' => 'Falta id valido']);
        }

        $quiniela = clean_quiniela(input_json());
        $statement = $pdo->prepare(
            "UPDATE `{$quinielasTable}`
             SET participante = ?, id_partido = ?, result_eq1 = ?, result_eq2 = ?, puntos = ?
             WHERE id = ?"
        );
        $statement->execute([
            $quiniela['participante'],
            $quiniela['id_partido'],
            $quiniela['result_eq1'],
            $quiniela['result_eq2'],
            $quiniela['puntos'],
            $id,
        ]);

        respond(200, ['id' => $id, 'puntos' => $quiniela['puntos']]);
    }

    if ($method === 'DELETE') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            respond(400, ['error' => 'Falta id valido']);
        }

        $statement = $pdo->prepare("DELETE FROM `{$quinielasTable}` WHERE id = ?");
        $statement->execute([$id]);
        respond(200, ['deleted' => $id]);
    }

    respond(405, ['error' => 'Metodo no permitido']);
} catch (Throwable $exception) {
    respond(500, ['error' => 'Error en la operacion', 'detail' => $exception->getMessage()]);
}
