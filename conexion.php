<?php
// Configuración de conexión a PostgreSQL
$host     = 'localhost';
$port     = '5432';
$dbname   = 'citas_medicas';
$user     = 'postgres';
$password = 'root'; // O la contraseña que asignaste a PostgreSQL

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>