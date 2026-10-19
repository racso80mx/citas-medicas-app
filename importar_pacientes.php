<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

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

            // Omitimos fila 0 (Cabeceras)
            for ($i = 1; $i < count($filas); $i++) {
                $fila = $filas[$i];

                $expediente     = trim($fila[0] ?? '');
                $nombre         = trim($fila[1] ?? '');
                $apaterno       = trim($fila[2] ?? '');
                $amaterno       = trim($fila[3] ?? '');
                $fnacimiento    = trim($fila[4] ?? '');
                $year_nac       = trim($fila[5] ?? '');
                $edad           = intval($fila[6] ?? 0);
                $edad_actual    = intval($fila[7] ?? 0);
                $sexo           = trim($fila[8] ?? '');
                $estado         = trim($fila[9] ?? '');
                $domicilio      = trim($fila[10] ?? '');
                $colonia        = trim($fila[11] ?? '');
                $nombre_padre   = trim($fila[12] ?? '');
                $nombre_madre   = trim($fila[13] ?? '');
                $edad_padre     = trim($fila[14] ?? '');
                $edad_madre     = trim($fila[15] ?? '');
                $fecha_apertura = trim($fila[16] ?? '');
                $estatus        = trim($fila[17] ?? '');
                $seguro_popular = trim($fila[18] ?? '');
                $telefono       = trim($fila[19] ?? '');
                $nombre_completo= trim($fila[20] ?? '');
                $curp           = trim($fila[21] ?? '');
                $nombre_apell   = trim($fila[22] ?? '');

                if (!empty($expediente)) {
                    // Verificar si el expediente ya existe
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE expediente = :exp");
                    $stmtCheck->execute(['exp' => $expediente]);

                    if ($stmtCheck->fetchColumn() == 0) {
                        $stmtInsert = $pdo->prepare("
                            INSERT INTO pacientes (
                                expediente, nombre, apaterno, amaterno, fnacimiento, year_nacimiento, 
                                edad, edad_actual, sexo, estado, domicilio, colonia, nombre_padre, 
                                nombre_madre, edad_padre, edad_madre, fecha_apertura, estatus, 
                                seguro_popular, telefono, nombre_completo, curp, nombre_apellidos
                            ) VALUES (
                                :exp, :nom, :apat, :amat, :fnac, :ynac, :edad, :eadact, :sex, 
                                :est, :dom, :col, :npad, :nmad, :epad, :emad, :fap, :estat, 
                                :seg, :tel, :ncomp, :curp, :napell
                            )
                        ");
                        
                        $stmtInsert->execute([
                            'exp'    => $expediente,
                            'nom'    => $nombre,
                            'apat'   => $apaterno,
                            'amat'   => $amaterno,
                            'fnac'   => $fnacimiento,
                            'ynac'   => $year_nac,
                            'edad'   => $edad,
                            'eadact' => $edad_actual,
                            'sex'    => $sexo,
                            'est'    => $estado,
                            'dom'    => $domicilio,
                            'col'    => $colonia,
                            'npad'   => $nombre_padre,
                            'nmad'   => $nombre_madre,
                            'epad'   => $edad_padre,
                            'emad'   => $edad_madre,
                            'fap'    => $fecha_apertura,
                            'estat'  => $estatus,
                            'seg'    => $seguro_popular,
                            'tel'    => $telefono,
                            'ncomp'  => $nombre_completo,
                            'curp'   => $curp,
                            'napell' => $nombre_apell
                        ]);
                        $registrosNuevos++;
                    } else {
                        $duplicados++;
                    }
                }
            }

            echo "<script>alert('Migración de pacientes finalizada. Nuevos: $registrosNuevos, Duplicados omitidos: $duplicados'); window.location.href='admin_pacientes.php';</script>";
            exit();

        } catch (Exception $e) {
            echo "<script>alert('Error crítico al procesar el archivo: " . addslashes($e->getMessage()) . "'); window.location.href='admin_pacientes.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('Formato no válido. Asegúrate de subir un archivo .xlsx'); window.location.href='admin_pacientes.php';</script>";
        exit();
    }
}
?>