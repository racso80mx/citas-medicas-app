<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN
// ===================================================================
session_start();

// Validar que el usuario haya iniciado sesión y tenga permisos (Rol 1 o Rol de Archivo, ej. 2)
// Cambia el '2' por el ID de rol que tenga tu cuenta de archivo si es diferente.
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol_id'] != 1 && $_SESSION['rol_id'] != 2)) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    try {
        $stmt = $pdo->prepare("DELETE FROM pacientes WHERE id_paciente = :id");
        $stmt->execute(['id' => $id]);

        // Redirigir de vuelta al módulo de archivo
        header("Location: archivo.php?eliminados=1");
        exit();
    } catch (PDOException $e) {
        // Si no se puede borrar físicamente por relaciones (llaves foráneas con citas), 
        // opcionalmente puedes cambiar su estatus a Baja:
        try {
            $stmt_baja = $pdo->prepare("UPDATE pacientes SET estatus = 'Baja' WHERE id_paciente = :id");
            $stmt_baja->execute(['id' => $id]);
            header("Location: archivo.php?eliminados=1");
            exit();
        } catch (Exception $ex) {
            echo "Error al eliminar el paciente: " . $e->getMessage();
        }
    }
} else {
    header("Location: archivo.php");
    exit();
}
?>