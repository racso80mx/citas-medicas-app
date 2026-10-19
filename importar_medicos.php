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

            for ($i = 1; $i < count($filas); $i++) {
                $fila = $filas[$i];

                $cedula      = trim($fila[0] ?? '');
                $nombre      = trim($fila[1] ?? '');
                $apaterno    = trim($fila[2] ?? '');
                $amaterno    = trim($fila[3] ?? '');
                $especialidad= trim($fila[4] ?? '');
                $telefono    = trim($fila[5] ?? '');
                $correo      = trim($fila[6] ?? '');
                $estatus     = trim($fila[7] ?? 'Activo');

                if (!empty($cedula) && !empty($nombre)) {
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM medicos WHERE cedula_profesional = :cedula");
                    $stmtCheck->execute(['cedula' => $cedula]);

                    if ($stmtCheck->fetchColumn() == 0) {
                        $stmtInsert = $pdo->prepare("
                            INSERT INTO medicos (cedula_profesional, nombre, apaterno, amaterno, especialidad, telefono, correo, estatus) 
                            VALUES (:cedula, :nombre, :apat, :amat, :esp, :tel, :correo, :est)
                        ");
                        $stmtInsert->execute([
                            'cedula' => $cedula,
                            'nombre' => $nombre,
                            'apat'   => $apaterno,
                            'amat'   => $amaterno,
                            'esp'    => $especialidad,
                            'tel'    => $telefono,
                            'correo' => $correo,
                            'est'    => $estatus
                        ]);
                        $registrosNuevos++;
                    } else {
                        $duplicados++;
                    }
                }
            }

            echo "<script>alert('Importación de médicos finalizada. Nuevos: $registrosNuevos, Duplicados omitidos: $duplicados'); window.location.href='admin_medicos.php';</script>";
            exit();

        } catch (Exception $e) {
            echo "<script>alert('Error al procesar el archivo: " . addslashes($e->getMessage()) . "'); window.location.href='admin_medicos.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('Formato de archivo inválido.'); window.location.href='admin_medicos.php';</script>";
        exit();
    }
}
?>