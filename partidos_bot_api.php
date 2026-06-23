<?php
declare(strict_types=1);

require_once __DIR__ . '/quiniela_points.php';

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

function existing_table(PDO $pdo, array $candidates, string $label, bool $required = true): ?string
{
    foreach ($candidates as $candidate) {
        $statement = $pdo->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$candidate]);
        if ($statement->fetchColumn()) {
            return $candidate;
        }
    }

    if ($required) {
        respond(500, ['error' => "No existe la tabla {$label}"]);
    }

    return null;
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

function normalize_fecha_hora(string $value): string
{
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '') {
        return '';
    }

    if (strlen($value) === 16) {
        $value .= ':00';
    }

    return $value;
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

function has_match_identity(array $item): bool
{
    if (isset($item['id']) && is_numeric($item['id']) && (int) $item['id'] > 0) {
        return true;
    }

    $idPartido = trim((string) ($item['id_partido'] ?? ''));
    if ($idPartido !== '') {
        return true;
    }

    $fechaHora = normalize_fecha_hora((string) ($item['fecha_hora'] ?? ''));
    $equipo1 = trim((string) ($item['equipo1'] ?? ''));
    $equipo2 = trim((string) ($item['equipo2'] ?? ''));

    return $fechaHora !== '' && $equipo1 !== '' && $equipo2 !== '';
}

function find_match_by_identity(PDO $pdo, string $table, array $identity): ?array
{
    if (isset($identity['id']) && is_numeric($identity['id']) && (int) $identity['id'] > 0) {
        $statement = $pdo->prepare(
            "SELECT id, id_partido, fecha_hora, equipo1, equipo2, result_eq1, result_eq2
             FROM `{$table}`
             WHERE id = ?
             LIMIT 1"
        );
        $statement->execute([(int) $identity['id']]);
        $match = $statement->fetch();

        return is_array($match) ? $match : null;
    }

    $idPartido = trim((string) ($identity['id_partido'] ?? ''));
    if ($idPartido !== '') {
        $statement = $pdo->prepare(
            "SELECT id, id_partido, fecha_hora, equipo1, equipo2, result_eq1, result_eq2
             FROM `{$table}`
             WHERE id_partido = ?
             LIMIT 1"
        );
        $statement->execute([$idPartido]);
        $match = $statement->fetch();

        return is_array($match) ? $match : null;
    }

    $fechaHora = normalize_fecha_hora((string) ($identity['fecha_hora'] ?? ''));
    $equipo1 = trim((string) ($identity['equipo1'] ?? ''));
    $equipo2 = trim((string) ($identity['equipo2'] ?? ''));
    if ($fechaHora === '' || $equipo1 === '' || $equipo2 === '') {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT id, id_partido, fecha_hora, equipo1, equipo2, result_eq1, result_eq2
         FROM `{$table}`
         WHERE fecha_hora = ? AND equipo1 = ? AND equipo2 = ?
         LIMIT 1"
    );
    $statement->execute([$fechaHora, $equipo1, $equipo2]);
    $match = $statement->fetch();

    return is_array($match) ? $match : null;
}

function item_fields(array $item, ?array $current = null, bool $requireBaseFields = false): array
{
    $fechaHora = array_key_exists('fecha_hora', $item)
        ? normalize_fecha_hora((string) $item['fecha_hora'])
        : (string) ($current['fecha_hora'] ?? '');
    $equipo1 = array_key_exists('equipo1', $item)
        ? trim((string) $item['equipo1'])
        : (string) ($current['equipo1'] ?? '');
    $equipo2 = array_key_exists('equipo2', $item)
        ? trim((string) $item['equipo2'])
        : (string) ($current['equipo2'] ?? '');

    if ($requireBaseFields && ($fechaHora === '' || $equipo1 === '' || $equipo2 === '')) {
        respond(422, ['error' => 'fecha_hora, equipo1 y equipo2 son obligatorios']);
    }

    $resultEq1 = array_key_exists('result_eq1', $item)
        ? nullable_score($item, 'result_eq1')
        : ($current !== null && $current['result_eq1'] !== null ? (int) $current['result_eq1'] : null);
    $resultEq2 = array_key_exists('result_eq2', $item)
        ? nullable_score($item, 'result_eq2')
        : ($current !== null && $current['result_eq2'] !== null ? (int) $current['result_eq2'] : null);

    return [
        'fecha_hora' => $fechaHora,
        'equipo1' => $equipo1,
        'equipo2' => $equipo2,
        'result_eq1' => $resultEq1,
        'result_eq2' => $resultEq2,
    ];
}

function update_linked_quinielas(PDO $pdo, ?string $quinielasTable, string $oldIdPartido, string $newIdPartido): int
{
    if ($quinielasTable === null || $oldIdPartido === $newIdPartido) {
        return 0;
    }

    $statement = $pdo->prepare(
        "UPDATE `{$quinielasTable}`
         SET id_partido = ?
         WHERE id_partido = ?"
    );
    $statement->execute([$newIdPartido, $oldIdPartido]);

    return $statement->rowCount();
}

function recalculate_quiniela_points(PDO $pdo, ?string $quinielasTable, string $idPartido, ?int $actualEq1, ?int $actualEq2): int
{
    if ($quinielasTable === null) {
        return 0;
    }

    $statement = $pdo->prepare(
        "SELECT id, result_eq1, result_eq2
         FROM `{$quinielasTable}`
         WHERE id_partido = ?"
    );
    $statement->execute([$idPartido]);
    $quinielas = $statement->fetchAll();

    if (!$quinielas) {
        return 0;
    }

    $update = $pdo->prepare(
        "UPDATE `{$quinielasTable}`
         SET puntos = ?
         WHERE id = ?"
    );

    foreach ($quinielas as $quiniela) {
        $points = calculate_quiniela_points(
            $quiniela['result_eq1'] !== null ? (int) $quiniela['result_eq1'] : null,
            $quiniela['result_eq2'] !== null ? (int) $quiniela['result_eq2'] : null,
            $actualEq1,
            $actualEq2
        );
        $update->execute([$points, $quiniela['id']]);
    }

    return count($quinielas);
}

function payload_items(array $payload): array
{
    if (array_is_list($payload)) {
        return $payload;
    }

    if (isset($payload['matches']) && is_array($payload['matches'])) {
        return $payload['matches'];
    }

    return $payload === [] ? [] : [$payload];
}

function ensure_items(array $items): array
{
    if ($items === []) {
        respond(422, ['error' => 'Debes enviar al menos un partido']);
    }

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            respond(422, ['error' => "El elemento {$index} no es un objeto valido"]);
        }
    }

    return $items;
}

function insert_match(PDO $pdo, string $table, array $fields): array
{
    $placeholder = 'pending_' . bin2hex(random_bytes(6));
    $statement = $pdo->prepare(
        "INSERT INTO `{$table}` (id_partido, fecha_hora, equipo1, equipo2, result_eq1, result_eq2)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $statement->execute([
        $placeholder,
        $fields['fecha_hora'],
        $fields['equipo1'],
        $fields['equipo2'],
        $fields['result_eq1'],
        $fields['result_eq2'],
    ]);

    $id = (int) $pdo->lastInsertId();
    $generatedId = id_partido($id, $fields['equipo1'], $fields['equipo2'], $fields['fecha_hora']);

    $statement = $pdo->prepare("UPDATE `{$table}` SET id_partido = ? WHERE id = ?");
    $statement->execute([$generatedId, $id]);

    return ['id' => $id, 'id_partido' => $generatedId];
}

function update_match(PDO $pdo, string $table, int $id, string $idPartido, array $fields): void
{
    $statement = $pdo->prepare(
        "UPDATE `{$table}`
         SET id_partido = ?, fecha_hora = ?, equipo1 = ?, equipo2 = ?, result_eq1 = ?, result_eq2 = ?
         WHERE id = ?"
    );
    $statement->execute([
        $idPartido,
        $fields['fecha_hora'],
        $fields['equipo1'],
        $fields['equipo2'],
        $fields['result_eq1'],
        $fields['result_eq2'],
        $id,
    ]);
}

function handle_upsert_items(PDO $pdo, string $table, ?string $quinielasTable, array $items): array
{
    $created = 0;
    $updated = 0;
    $recalculated = 0;
    $results = [];

    foreach ($items as $index => $item) {
        $existing = has_match_identity($item) ? find_match_by_identity($pdo, $table, $item) : null;

        if ($existing !== null) {
            $fields = item_fields($item, $existing, true);
            $matchId = (int) $existing['id'];
            $newIdPartido = id_partido($matchId, $fields['equipo1'], $fields['equipo2'], $fields['fecha_hora']);
            update_match($pdo, $table, $matchId, $newIdPartido, $fields);

            $linkedQuinielas = update_linked_quinielas(
                $pdo,
                $quinielasTable,
                (string) $existing['id_partido'],
                $newIdPartido
            );
            $updatedQuinielas = recalculate_quiniela_points(
                $pdo,
                $quinielasTable,
                $newIdPartido,
                $fields['result_eq1'],
                $fields['result_eq2']
            );

            $updated++;
            $recalculated += $updatedQuinielas;
            $results[] = [
                'index' => $index,
                'action' => 'updated',
                'id' => $matchId,
                'id_partido' => $newIdPartido,
                'linked_quinielas' => $linkedQuinielas,
                'updated_quinielas' => $updatedQuinielas,
            ];
            continue;
        }

        $fields = item_fields($item, null, true);
        $inserted = insert_match($pdo, $table, $fields);
        $updatedQuinielas = recalculate_quiniela_points(
            $pdo,
            $quinielasTable,
            $inserted['id_partido'],
            $fields['result_eq1'],
            $fields['result_eq2']
        );

        $created++;
        $recalculated += $updatedQuinielas;
        $results[] = [
            'index' => $index,
            'action' => 'created',
            'id' => $inserted['id'],
            'id_partido' => $inserted['id_partido'],
            'updated_quinielas' => $updatedQuinielas,
        ];
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'recalculated_quinielas' => $recalculated,
        'results' => $results,
    ];
}

function handle_result_items(PDO $pdo, string $table, ?string $quinielasTable, array $items): array
{
    $updated = 0;
    $recalculated = 0;
    $results = [];

    foreach ($items as $index => $item) {
        if (!has_match_identity($item)) {
            respond(422, ['error' => "El elemento {$index} necesita id, id_partido o fecha_hora+equipo1+equipo2"]);
        }

        $existing = find_match_by_identity($pdo, $table, $item);
        if ($existing === null) {
            respond(404, ['error' => "No existe el partido solicitado en el elemento {$index}"]);
        }

        if (!array_key_exists('result_eq1', $item) && !array_key_exists('result_eq2', $item)) {
            respond(422, ['error' => "El elemento {$index} debe incluir result_eq1 o result_eq2"]);
        }

        $fields = item_fields($item, $existing, true);
        update_match($pdo, $table, (int) $existing['id'], (string) $existing['id_partido'], $fields);

        $updatedQuinielas = recalculate_quiniela_points(
            $pdo,
            $quinielasTable,
            (string) $existing['id_partido'],
            $fields['result_eq1'],
            $fields['result_eq2']
        );

        $updated++;
        $recalculated += $updatedQuinielas;
        $results[] = [
            'index' => $index,
            'action' => 'updated_results',
            'id' => (int) $existing['id'],
            'id_partido' => (string) $existing['id_partido'],
            'updated_quinielas' => $updatedQuinielas,
        ];
    }

    return [
        'updated' => $updated,
        'recalculated_quinielas' => $recalculated,
        'results' => $results,
    ];
}

$pdo = db();
$table = existing_table($pdo, ['partidos', 'pardidos'], 'partidos ni pardidos');
$quinielasTable = existing_table($pdo, ['quinielas'], 'quinielas', false);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        respond(200, [
            'endpoint' => 'partidos_bot_api.php',
            'table' => $table,
            'quinielas_table' => $quinielasTable,
            'security' => 'sin autenticacion temporalmente',
            'matching_priority' => [
                'id',
                'id_partido',
                'fecha_hora + equipo1 + equipo2',
            ],
            'supported_methods' => [
                'GET' => 'documentacion rapida del endpoint',
                'POST' => 'crea o actualiza partidos por lote',
                'PATCH' => 'actualiza resultados por lote',
            ],
            'next_id' => next_id($pdo, $table),
            'examples' => [
                'create_matches' => [
                    'matches' => [
                        [
                            'fecha_hora' => '2026-06-23 18:00:00',
                            'equipo1' => 'Argentina',
                            'equipo2' => 'Brasil',
                        ],
                        [
                            'fecha_hora' => '2026-06-23 20:00:00',
                            'equipo1' => 'Mexico',
                            'equipo2' => 'Colombia',
                        ],
                    ],
                ],
                'upsert_matches' => [
                    'matches' => [
                        [
                            'id_partido' => '12_argentina_brasil_20260623',
                            'fecha_hora' => '2026-06-23 18:30:00',
                            'equipo1' => 'Argentina',
                            'equipo2' => 'Brasil',
                            'result_eq1' => 2,
                            'result_eq2' => 1,
                        ],
                    ],
                ],
                'update_results' => [
                    'matches' => [
                        [
                            'id_partido' => '12_argentina_brasil_20260623',
                            'result_eq1' => 2,
                            'result_eq2' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    }

    if ($method === 'POST') {
        $items = ensure_items(payload_items(input_json()));
        $pdo->beginTransaction();
        $summary = handle_upsert_items($pdo, $table, $quinielasTable, $items);
        $pdo->commit();

        respond(200, $summary + [
            'ok' => true,
            'method' => 'POST',
            'message' => 'Partidos procesados correctamente',
        ]);
    }

    if ($method === 'PATCH') {
        $items = ensure_items(payload_items(input_json()));
        $pdo->beginTransaction();
        $summary = handle_result_items($pdo, $table, $quinielasTable, $items);
        $pdo->commit();

        respond(200, $summary + [
            'ok' => true,
            'method' => 'PATCH',
            'message' => 'Resultados actualizados correctamente',
        ]);
    }

    respond(405, ['error' => 'Metodo no permitido']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['error' => 'Error en la operacion', 'detail' => $exception->getMessage()]);
}
