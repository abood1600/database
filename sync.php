<?php
// sync.php
// RUN THIS ON ONLINE SERVER
// This script receives data from the Local Server and updates the Online DB.

// !IMPORTANT: Change this secret key!
define('SYNC_SECRET_KEY', 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING_12345');

header('Content-Type: application/json');

// 1. Security Check
$headers = getallheaders();
$auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if ($auth !== 'Bearer ' . SYNC_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 2. Load DB Connection
require_once 'db.php'; // Ensure this file exists on online server or adjust path

// 🔍 Connection Check (Ping)
if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    echo json_encode(['status' => 'connected', 'message' => 'Online Server is Ready 🚀']);
    exit;
}

// 3. Get Input
$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input['table_name']) || !isset($input['operation']) || !isset($input['row_data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Input']);
    exit;
}

$table = preg_replace('/[^a-zA-Z0-9_]/', '', $input['table_name']); // Sanitize table name manually
$op = strtoupper($input['operation']);
$data = $input['row_data'];

// Whitelist tables to prevent SQL injection or unwanted modifications
$allowed_tables = [
    'users', 'customers', 'customer_transactions', 'models', 'tailors',
    'invoices', 'items', 'sales', 'movements', 'expenses',
    'tailor_payments', 'staff', 'staff_payments', 'settings'
];

if (!in_array($table, $allowed_tables)) {
    http_response_code(400);
    echo json_encode(['error' => 'Table not allowed']);
    exit;
}

try {
    if ($op === 'INSERT' || $op === 'UPDATE') {
        // UPSERT (Insert or Update) based on ID
        if (!isset($data['id'])) {
            // For tables without ID or custom logic, handle accordingly. 
            // Assuming all tables have 'id' as primary key for this system.
            throw new Exception("Row data missing ID");
        }

        $keys = array_keys($data);
        $fields = implode(", ", $keys);

        // Prepare placeholders ($1, $2, ...)
        $placeholders = [];
        $params = [];
        $i = 1;
        foreach ($data as $value) {
            $placeholders[] = "$" . $i++;
            $params[] = $value === '' ? null : $value; // Handle empty strings as NULL if needed, or keep as is.
        }
        $placeholders_str = implode(", ", $placeholders);

        // Prepare UPDATE part for ON CONFLICT
        $update_parts = [];
        foreach ($keys as $key) {
            if ($key === 'id')
                continue; // Don't update ID
            $update_parts[] = "$key = EXCLUDED.$key";
        }
        $update_clause = implode(", ", $update_parts);

        if (empty($update_clause)) {
            // It might be a table with only ID? Unlikely, but handle gracefully
            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders_str) ON CONFLICT (id) DO NOTHING";
        }
        else {
            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders_str) 
                    ON CONFLICT (id) DO UPDATE SET $update_clause";
        }

        $db->prepare($sql)->execute($params);

        // 🔔 Add Notification
        $db->prepare("INSERT INTO sync_notifications (message) VALUES (?)")
            ->execute(["تم تحديث جدول $table"]);

        echo json_encode(['status' => 'success', 'message' => "Upserted row {$data['id']} into $table"]);

    }
    elseif ($op === 'DELETE') {
        if (!isset($data['id'])) {
            throw new Exception("Delete requises ID");
        }

        $sql = "DELETE FROM $table WHERE id = $1";
        $db->prepare($sql)->execute([$data['id']]);

        // 🔔 Add Notification
        $db->prepare("INSERT INTO sync_notifications (message) VALUES (?)")
            ->execute(["تم حذف عنصر من جدول $table"]);

        echo json_encode(['status' => 'success', 'message' => "Deleted row {$data['id']} from $table"]);
    }
    else {
        throw new Exception("Unknown operation");
    }

}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
