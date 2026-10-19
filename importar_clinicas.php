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

                $clave         = trim($fila[0] ?? '');
                $nombre        = trim($fila[1] ?? '');
                $nucleo        = trim($fila[2] ?? '');
                $municipio     = trim($fila[3] ?? '');
                $localidad     = trim($fila[4] ?? '');
                $domicilio     = trim($fila[5] ?? '');
                $estatus       = trim($fila[6] ?? 'Activa');

                if (!empty($clave) && !empty($nombre)) {
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM clinicas WHERE clave_clinica = :clave");
                    $stmtCheck->execute(['clave' => $clave]);

                    if ($stmtCheck->fetchColumn() == 0) {
                        $stmtInsert = $pdo->prepare("
                            INSERT INTO clinicas (clave_clinica, nombre_clinica, nucleo_basico, municipio, localidad, domicilio, estatus) 
                            VALUES (:clave, :nombre, :nucleo, :mun, :loc, :dom, :est)
                        ");
                        $stmtInsert->execute([
                            'clave'  => $clave,
                            'nombre' => $nombre,
                            'nucleo' => $nucleo,
                            'mun'    => $municipio,
                            'loc'    => $localidad,
                            'dom'    => $domicilio,
                            'est'    => $estatus
                        ]);
                        $registrosNuevos++;
                    } else {
                        $duplicados++;
                    }
                }
            }

            echo "<script>alert('Importación de clínicas finalizada. Nuevos: $registrosNuevos, Duplicados omitidos: $duplicados'); window.location.href='admin_clinicas.php';</script>";
            exit();

        } catch (Exception $e) {
            echo "<script>alert('Error al procesar el archivo: " . addslashes($e->getMessage()) . "'); window.location.href='admin_clinicas.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('Formato de archivo inválido.'); window.location.href='admin_clinicas.php';</script>";
        exit();
    }
}
?>