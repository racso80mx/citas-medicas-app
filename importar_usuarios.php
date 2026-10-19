<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y SEGURIDAD (SOLO SUPERADMIN)
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

// Cargar la conexión a la base de datos y la librería PhpSpreadsheet (vía Composer)
require_once 'conexion.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ===================================================================
// 2. PROCESAMIENTO DEL ARCHIVO CARGADO POR MÉTODO POST
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    
    $archivoTmp = $_FILES['archivo_excel']['tmp_name'];
    $nombreArchivo = $_FILES['archivo_excel']['name'];
    $extension = pathinfo($nombreArchivo, PATHINFO_EXTENSION);

    // Validar que sea un archivo de Excel o CSV válido
    if (in_array($extension, ['xlsx', 'xls', 'csv'])) {
        try {
            // Cargar el archivo utilizando PhpSpreadsheet
            $spreadsheet = IOFactory::load($archivoTmp);
            $sheet = $spreadsheet->getActiveSheet();
            $filas = $sheet->toArray();

            $registrosImportados = 0;
            $erroresImportacion = 0;

            // Recorrer las filas del Excel (Omitimos la fila 0 de encabezados)
            for ($i = 1; $i < count($filas); $i++) {
                $fila = $filas[$i];

                // Mapeo actualizado según la plantilla de usuarios:
                // [0] Usuario/Matrícula, [1] Contraseña, [2] Nombre Completo, 
                // [3] Correo, [4] ID Rol, [5] Clave Clínica, [6] Permisos (ej: registro_citas,citas_medicas)
                $username      = trim($fila[0] ?? '');
                $password_plano = trim($fila[1] ?? '');
                $nombre        = trim($fila[2] ?? '');
                $correo        = trim($fila[3] ?? '');
                $id_rol        = intval($fila[4] ?? 2); // Por defecto rol 2 (Operativo)
                $clinica       = trim($fila[5] ?? '');
                $permText      = trim($fila[6] ?? 'registro_citas');

                // Convertir la cadena de permisos separados por comas en un arreglo y luego a JSONB
                $permisosArray = array_map('trim', explode(',', $permText));
                $permisosJson  = json_encode($permisosArray);

                // Validar que los campos obligatorios principales no estén vacíos
                if (!empty($username) && !empty($password_plano) && !empty($nombre)) {
                    
                    // Verificar si el usuario ya existe para evitar duplicados
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE username = :username");
                    $stmtCheck->execute(['username' => $username]);
                    
                    if ($stmtCheck->fetchColumn() == 0) {
                        $password_hash = $password_plano; // Conserva compatibilidad con el sistema actual

                        $stmtInsert = $pdo->prepare("
                            INSERT INTO usuarios (username, password_hash, nombre_completo, correo, id_rol, clave_clinica, permisos, activo) 
                            VALUES (:username, :password_hash, :nombre, :correo, :id_rol, :clinica, :permisos, TRUE)
                        ");
                        
                        $stmtInsert->execute([
                            'username'      => $username,
                            'password_hash' => $password_hash,
                            'nombre'        => $nombre,
                            'correo'        => $correo,
                            'id_rol'        => $id_rol,
                            'clinica'       => $clinica,
                            'permisos'      => $permisosJson
                        ]);

                        $registrosImportados++;
                    } else {
                        $erroresImportacion++; // Usuario duplicado omitido
                    }
                }
            }

            // Redirección con mensaje de éxito detallado
            echo "<script>alert('Importación finalizada. Registros nuevos: $registrosImportados. Omitidos/Duplicados: $erroresImportacion'); window.location.href='admin_usuarios.php';</script>";
            exit();

        } catch (Exception $e) {
            echo "<script>alert('Error al procesar el archivo: " . addslashes($e->getMessage()) . "'); window.location.href='admin_usuarios.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('Formato de archivo no válido. Suba un archivo .xlsx o .csv'); window.location.href='admin_usuarios.php';</script>";
        exit();
    }
}
?>