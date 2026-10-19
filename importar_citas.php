<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y SEGURIDAD ESTRICTA
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ===================================================================
// 2. PROCESAMIENTO Y MAPEO DE COLUMNAS DE ACCESS
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    $archivoTmp = $_FILES['archivo_excel']['tmp_name'];
    $nombreArchivo = $_FILES['archivo_excel']['name'];
    $extension = pathinfo($nombreArchivo, PATHINFO_EXTENSION);

    if (in_array($extension, ['xlsx', 'xls', 'csv'])) {
        try {
            $spreadsheet = IOFactory::load($archivoTmp);
            $sheet = $spreadsheet->getActiveSheet();
            $filas = $sheet->toArray();

            $registrosNuevos = 0;
            $duplicados = 0;

            // Iterar filas omitiendo la cabecera (índice 0)
            for ($i = 1; $i < count($filas); $i++) {
                $fila = $filas[$i];

                // Mapeo exacto según las columnas de Access proporcionadas
                $id_paciente     = trim($fila[0] ?? '');
                $fecha_cita      = trim($fila[1] ?? '');
                $hora_cita       = trim($fila[2] ?? '');
                $tipo_clinica    = trim($fila[3] ?? '');
                $nombre_clinica  = trim($fila[4] ?? '');
                $colonia         = trim($fila[5] ?? '');
                $tipo_paciente   = trim($fila[6] ?? '');
                $nombre_paciente = trim($fila[7] ?? '');
                $no_expediente   = trim($fila[8] ?? '');
                $curp            = trim($fila[9] ?? '');
                $id_citas_calc   = trim($fila[10] ?? '');
                $telefono        = trim($fila[11] ?? '');
                $estatus         = trim($fila[12] ?? '');
                $fecha_creacion  = trim($fila[13] ?? '');

                // Validar que existan datos mínimos requeridos para evitar filas vacías
                if (!empty($no_expediente) && !empty($fecha_cita)) {
                    
                    // Verificar duplicidad usando el expediente, fecha y hora de la cita
                    $stmtCheck = $pdo->prepare("
                        SELECT COUNT(*) FROM citas 
                        WHERE no_expediente = :exp AND fecha_cita = :fcita AND hora_cita = :hcita
                    ");
                    $stmtCheck->execute([
                        'exp'   => $no_expediente,
                        'fcita' => $fecha_cita,
                        'hcita' => $hora_cita
                    ]);

                    if ($stmtCheck->fetchColumn() == 0) {
                        // Inserción de datos mapeados a PostgreSQL
                        $stmtInsert = $pdo->prepare("
                            INSERT INTO citas (
                                id_paciente, fecha_cita, hora_cita, tipo_clinica, 
                                nombre_clinica, colonia, tipo_paciente, nombre_paciente, 
                                no_expediente, curp, id_citas_calculado, telefono, estatus, fecha_creacion
                            ) VALUES (
                                :idp, :fcita, :hcita, :tclin, :nclin, :col, :tpac, :npac, 
                                :exp, :curp, :idcalc, :tel, :est, :fcrea
                            )
                        ");
                        
                        $stmtInsert->execute([
                            'idp'    => $id_paciente,
                            'fcita'  => $fecha_cita,
                            'hcita'  => $hora_cita,
                            'tclin'  => $tipo_clinica,
                            'nclin'  => $nombre_clinica,
                            'col'    => $colonia,
                            'tpac'   => $tipo_paciente,
                            'npac'   => $nombre_paciente,
                            'exp'    => $no_expediente,
                            'curp'   => $curp,
                            'idcalc' => $id_citas_calc,
                            'tel'    => $telefono,
                            'est'    => $estatus,
                            'fcrea'  => $fecha_creacion
                        ]);

                        $registrosNuevos++;
                    } else {
                        $duplicados++;
                    }
                }
            }

            echo "<script>alert('Importación de citas finalizada. Nuevos: $registrosNuevos, Duplicados omitidos: $duplicados'); window.location.href='admin_citas.php';</script>";
            exit();

        } catch (Exception $e) {
            echo "<script>alert('Error al procesar el archivo: " . addslashes($e->getMessage()) . "'); window.location.href='admin_citas.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('Formato de archivo no válido.'); window.location.href='admin_citas.php';</script>";
        exit();
    }
}
?>