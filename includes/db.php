<?php
require_once __DIR__ . '/config.php';

function get_db() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die(json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function db_fetch_all($sql, $params = [], $types = '') {
    $db = get_db();
    if (empty($params)) {
        $result = $db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    if (empty($types)) $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function db_fetch_one($sql, $params = [], $types = '') {
    $rows = db_fetch_all($sql, $params, $types);
    return $rows[0] ?? null;
}

function db_insert($sql, $params = [], $types = '') {
    $db = get_db();
    if (empty($params)) { $db->query($sql); return $db->insert_id; }
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    if (empty($types)) $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $db->insert_id;
}

function db_execute($sql, $params = [], $types = '') {
    $db = get_db();
    if (empty($params)) return $db->query($sql) !== false;
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    if (empty($types)) $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    return $stmt->execute();
}
