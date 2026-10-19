<?php
// ===================================================================
// ARCHIVO: actualizar_citas_medico.php
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cita     = $_POST['id_cita'] ?? '';
    $expediente  = $_POST['expediente'] ?? '';
    $fecha_cita  = $_POST['fecha_cita'] ?? '';
    $hora_cita   = $_POST['hora_cita'] ?? '';

    // Capturar y convertir estrictamente todo a MAYÚSCULAS
    $nombre      = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
    $apaterno    = mb_strtoupper(trim($_POST['apaterno'] ?? ''), 'UTF-8');
    $amaterno    = mb_strtoupper(trim($_POST['amaterno'] ?? ''), 'UTF-8');
    $domicilio   = mb_strtoupper(trim($_POST['domicilio'] ?? ''), 'UTF-8');
    $municipio   = mb_strtoupper(trim($_POST['municipio'] ?? ''), 'UTF-8');
    $colonia     = mb_strtoupper(trim($_POST['colonia'] ?? ''), 'UTF-8');

    // Construir nombre completo en mayúsculas para la tabla citas
    $nombre_completo = trim("$nombre $apaterno $amaterno");

    // Capturar filtros actuales para mantener la vista en citas_clinica.php
    $filtro_fecha = $_POST['filtro_fecha'] ?? '';
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin    = $_POST['fecha_fin'] ?? '';
    $limit        = $_POST['limit'] ?? '50';

    if (!empty($id_cita) && !empty($fecha_cita) && !empty($hora_cita)) {
        try {
            // 1. Actualizar la cita y el nombre del paciente en la tabla citas
            $sql_cita = "UPDATE citas SET fecha_cita = :fecha_cita, hora_cita = :hora_cita, nombre_paciente = :nombre_paciente WHERE id_cita = :id_cita";
            $stmt_cita = $pdo->prepare($sql_cita);
            $stmt_cita->execute([
                'fecha_cita'      => $fecha_cita,
                'hora_cita'       => $hora_cita,
                'nombre_paciente' => $nombre_completo,
                'id_cita'         => $id_cita
            ]);

            // 2. Actualizar los datos oficiales en la tabla pacientes (si tiene expediente)
            if (!empty($expediente)) {
                $sql_pac = "UPDATE pacientes SET nombre = :nombre, apaterno = :apaterno, amaterno = :amaterno, domicilio = :domicilio, municipio = :municipio, colonia = :colonia WHERE expediente = :expediente";
                $stmt_pac = $pdo->prepare($sql_pac);
                $stmt_pac->execute([
                    'nombre'     => $nombre,
                    'apaterno'   => $apaterno,
                    'amaterno'   => $amaterno,
                    'domicilio'  => $domicilio,
                    'municipio'  => $municipio,
                    'colonia'    => $colonia,
                    'expediente' => $expediente
                ]);
            }
        } catch (Exception $e) {
            die("Error al actualizar el registro: " . $e->getMessage());
        }
    }
    
    // Construir los parámetros de retorno para citas_clinica.php
    $queryParams = [];
    if (!empty($filtro_fecha)) $queryParams['filtro_fecha'] = $filtro_fecha;
    if (!empty($fecha_inicio)) $queryParams['fecha_inicio'] = $fecha_inicio;
    if (!empty($fecha_fin))    $queryParams['fecha_fin'] = $fecha_fin;
    if (!empty($limit))        $queryParams['limit'] = $limit;

    $urlRedireccion = "citas_clinica.php";
    if (!empty($queryParams)) {
        $urlRedireccion .= "?" . http_build_query($queryParams);
    }

    header("Location: " . $urlRedireccion);
    exit();
} else {
    header("Location: citas_clinica.php");
    exit();
}
?>