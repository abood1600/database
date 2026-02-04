<?php
// sync_packages.php
// RUN THIS ON ONLINE SERVER
// This script accepts and processes an ARRAY of rows to minimize HTTP overhead.

define('SYNC_SECRET_KEY', 'AboodKey2026Secure');
header('Content-Type: application/json');

$headers = getallheaders();
$auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';
if ($auth !== 'Bearer ' . SYNC_SECRET_KEY) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

require_once 'db.php';
$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !is_array($input))
    die(json_encode(['error' => 'Invalid Package Format']));

$results = ['success' => 0, 'fail' => 0, 'errors' => []];

try {
    $db->beginTransaction();
    foreach ($input as $item) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $item['table_name'] ?? '');
        $op = strtoupper($item['operation'] ?? '');
        $data = $item['row_data'] ?? null;
        $id = $data['id'] ?? null;

        if (!$table || !$op || !$data || !$id) {
            $results['fail']++;
            continue;
        }

        if ($op === 'UPDATE' || $op === 'INSERT') {
            $keys = array_keys($data);
            $fields = implode(", ", $keys);
            $placeholders = [];
            $params = [];
            $i = 1;
            foreach ($data as $val) {
                $placeholders[] = "$" . $i++;
                $params[] = ($val === '' || $val === null) ? null : $val;
            }

            $update_parts = [];
            foreach ($keys as $k) {
                if ($k !== 'id')
                    $update_parts[] = "$k = EXCLUDED.$k";
            }
            $update_clause = !empty($update_parts) ? "ON CONFLICT (id) DO UPDATE SET " . implode(", ", $update_parts) : "ON CONFLICT (id) DO NOTHING";

            $sql = "INSERT INTO $table ($fields) VALUES (" . implode(", ", $placeholders) . ") $update_clause";
            $db->prepare($sql)->execute($params);
            $results['success']++;
        }
    }
    $db->commit();
}
catch (Exception $e) {
    if ($db->inTransaction())
        $db->rollBack();
    die(json_encode(['error' => $e->getMessage()]));
}

echo json_encode(['status' => 'success', 'processed' => $results['success']]);
?>
