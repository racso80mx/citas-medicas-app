<?php
// ===================================================================
// ARCHIVO: actualizar_cita.php
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cita      = $_POST['id_cita'] ?? '';
    $fecha_cita   = $_POST['fecha_cita'] ?? '';
    $hora_cita    = $_POST['hora_cita'] ?? '';

    // Capturar los filtros actuales enviados para mantener la vista
    $tipo_clinica   = $_POST['tipo_clinica'] ?? '';
    $nombre_clinica = $_POST['nombre_clinica'] ?? '';
    $fecha_inicio   = $_POST['fecha_inicio'] ?? '';
    $fecha_fin      = $_POST['fecha_fin'] ?? '';
    $filtro_tiempo  = $_POST['filtro_tiempo'] ?? '';
    $limit          = $_POST['limit'] ?? '50';

    if (!empty($id_cita) && !empty($fecha_cita) && !empty($hora_cita)) {
        try {
            $sql = "UPDATE citas SET fecha_cita = :fecha_cita, hora_cita = :hora_cita WHERE id_cita = :id_cita";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'fecha_cita' => $fecha_cita,
                'hora_cita'  => $hora_cita,
                'id_cita'    => $id_cita
            ]);
        } catch (Exception $e) {
            die("Error al actualizar la cita: " . $e->getMessage());
        }
    }
    
    // Construir los parámetros de retorno para admin_citas.php
    $queryParams = [];
    if (!empty($tipo_clinica))   $queryParams['tipo_clinica'] = $tipo_clinica;
    if (!empty($nombre_clinica)) $queryParams['nombre_clinica'] = $nombre_clinica;
    if (!empty($fecha_inicio))   $queryParams['fecha_inicio'] = $fecha_inicio;
    if (!empty($fecha_fin))      $queryParams['fecha_fin'] = $fecha_fin;
    if (!empty($filtro_tiempo))  $queryParams['filtro_tiempo'] = $filtro_tiempo;
    if (!empty($limit))          $queryParams['limit'] = $limit;

    $urlRedireccion = "admin_citas.php";
    if (!empty($queryParams)) {
        $urlRedireccion .= "?" . http_build_query($queryParams);
    }

    header("Location: " . $urlRedireccion);
    exit();
} else {
    header("Location: admin_citas.php");
    exit();
}
?>