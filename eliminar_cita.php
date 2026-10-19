<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_cita = $_GET['id'] ?? null;

if ($id_cita) {
    try {
        $stmt = $pdo->prepare("DELETE FROM citas WHERE id_cita = :id");
        $stmt->execute(['id' => $id_cita]);
    } catch (Exception $e) {
        // Manejo silencioso de error
    }
}

header("Location: admin_citas.php");
exit();