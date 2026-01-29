<?php

require 'Database.php';

header('Content-Type: application/json');

$db = new Database();

$tenant = $_GET['tenant'] ?? null;
$limit  = min((int)($_GET['limit'] ?? 100), 1000);

$sql = "SELECT * FROM jobs";
$params = [];

if ($tenant) {
    $sql .= " WHERE tenant = ?";
    $params[] = $tenant;
}

$sql .= " ORDER BY scraped_at DESC LIMIT ?";

$params[] = $limit;

$stmt = $db->pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(
    $stmt->fetchAll(PDO::FETCH_ASSOC),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
