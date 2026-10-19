<?php
// ===================================================================
// 1. CONFIGURACIÓN DE ERRORES Y SEGURIDAD
// ===================================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$mensaje = "";
$error = "";

// ===================================================================
// 2. PROCESAMIENTO DE ACCIONES (RESPALDOS SEGUROS Y OPTIMIZADOS)
// ===================================================================
if (isset($_GET['accion'])) {
    $accion = $_GET['accion'];

    // --- RESPALDO DE BASE DE DATOS ---
    if ($accion === 'respaldo_bd') {
        try {
            set_time_limit(300);
            ini_set('memory_limit', '512M');

            $filename = "respaldo_bd_citas_medicas_" . date('Y-m-d_H-i-s') . ".sql";
            $filepath = sys_get_temp_dir() . '/' . $filename;
            
            $contenido_sql = "-- Respaldo de Base de Datos Generado Automáticamente\n";
            $contenido_sql .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n\n";

            $stmt_tablas = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $tablas = $stmt_tablas->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tablas as $tabla) {
                $contenido_sql .= "-- Estructura y datos de la tabla: $tabla\n";
                $stmt_datos = $pdo->query("SELECT * FROM \"$tabla\"");
                $filas = $stmt_datos->fetchAll(PDO::FETCH_ASSOC);

                foreach ($filas as $fila) {
                    $columnas = array_keys($fila);
                    $valores = array_values($fila);

                    $cols_str = '"' . implode('", "', $columnas) . '"';
                    $vals_str = implode(', ', array_map(function($val) use ($pdo) {
                        return $val === null ? 'NULL' : $pdo->quote($val);
                    }, $valores));

                    $contenido_sql .= "INSERT INTO \"$tabla\" ($cols_str) VALUES ($vals_str);\n";
                }
                $contenido_sql .= "\n";
            }

            file_put_contents($filepath, $contenido_sql);

            if (file_exists($filepath)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filepath));
                readfile($filepath);
                @unlink($filepath);
                exit();
            } else {
                $error = "No se pudo generar el archivo de respaldo SQL.";
            }
        } catch (Exception $e) {
            $error = "Error al generar respaldo de BD: " . $e->getMessage();
        }
    }

    // --- RESPALDO DE ARCHIVOS DEL SISTEMA (.ZIP OPTIMIZADO) ---
    if ($accion === 'respaldo_archivos') {
        try {
            set_time_limit(600);
            ini_set('memory_limit', '1024M');

            if (!class_exists('ZipArchive')) {
                throw new Exception("La extensión ZipArchive no está habilitada en este servidor.");
            }

            $rootPath = realpath(__DIR__);
            $zipName = "respaldo_sistema_archivos_" . date('Y-m-d_H-i-s') . ".zip";
            $zipPath = sys_get_temp_dir() . '/' . $zipName;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                // Carpetas o patrones a excluir para evitar congelamientos por archivos pesados
                $exclusiones = ['respaldo_', '.git', 'node_modules', 'vendor'];

                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($rootPath) + 1);
                        
                        // Validar si la ruta contiene alguna palabra excluida
                        $omitir = false;
                        foreach ($exclusiones as $ex) {
                            if (strpos($relativePath, $ex) !== false) {
                                $omitir = true;
                                break;
                            }
                        }

                        if (!$omitir) {
                            $zip->addFile($filePath, $relativePath);
                        }
                    }
                }
                $zip->close();

                if (file_exists($zipPath)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . basename($zipName) . '"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($zipPath));
                    readfile($zipPath);
                    @unlink($zipPath);
                    exit();
                } else {
                    throw new Exception("No se pudo crear el archivo ZIP temporal.");
                }
            } else {
                throw new Exception("No se pudo abrir el archivo ZIP para escritura.");
            }
        } catch (Exception $e) {
            $error = "Error al respaldar archivos: " . $e->getMessage();
        }
    }
}

// ===================================================================
// 3. PRUEBA DE CONEXIÓN Y MÉTRICAS
// ===================================================================
$estado_conexion = "Desconocido";
$version_pg = "";
try {
    $stmt_test = $pdo->query("SELECT version()");
    $version_pg = $stmt_test->fetchColumn();
    $estado_conexion = "Conectado Exitosamente";
} catch (Exception $e) {
    $estado_conexion = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Mantenimiento y Respaldos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-dorado: #9f2241; --imss-verde: #006847; --fondo: #f4f6f8; --borde: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-dorado); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 4px solid var(--imss-dorado); }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-warning { background-color: #d97706; }
        .btn:hover { opacity: 0.9; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px; }
        .info-box { background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid var(--borde); }
        .info-box h4 { margin: 0 0 5px 0; color: #637381; font-size: 12px; text-transform: uppercase; }
        .info-box p { margin: 0; font-size: 15px; font-weight: bold; color: #1c252e; }

        #loadingOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            color: white;
            flex-direction: column;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-bottom: 15px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <div id="loadingOverlay">
        <div class="spinner"></div>
        <h3 id="loadingText" style="margin: 0; font-weight: 600;">Generando archivo de respaldo...</h3>
        <p style="margin: 5px 0 0 0; font-size: 13px; color: #cbd5e1;">Por favor espere mientras se prepara la descarga.</p>
    </div>

    <div class="navbar">
        <div><i class="fa-solid fa-server"></i> <strong>Configuración y Mantenimiento del Sistema</strong></div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver al Panel Admin</a>
    </div>

    <div class="container">
        <?php if (!empty($error)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- ESTADO DEL SISTEMA -->
        <div class="card">
            <h3 style="margin-top: 0; color: var(--imss-dorado);"><i class="fa-solid fa-network-wired"></i> Estado de Conexión y Servidor</h3>
            <p style="color: #637381; font-size: 14px;">Parámetros actuales de la conexión PDO a la base de datos institucional y entorno PHP.</p>
            
            <div class="info-grid">
                <div class="info-box">
                    <h4>Estado de la Base de Datos</h4>
                    <p style="color: var(--imss-verde);"><i class="fa-solid fa-circle-check"></i> <?php echo $estado_conexion; ?></p>
                </div>
                <div class="info-box">
                    <h4>Motor de Base de Datos</h4>
                    <p><?php echo htmlspecialchars(substr($version_pg, 0, 45) . '...'); ?></p>
                </div>
                <div class="info-box">
                    <h4>Versión de PHP</h4>
                    <p><?php echo phpversion(); ?></p>
                </div>
                <div class="info-box">
                    <h4>Servidor Web / Host</h4>
                    <p><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido'); ?></p>
                </div>
            </div>
        </div>

        <!-- MÓDULO DE RESPALDOS -->
        <div class="card">
            <h3 style="margin-top: 0; color: var(--imss-dorado);"><i class="fa-solid fa-cloud-arrow-down"></i> Centro de Respaldos</h3>
            <p style="color: #637381; font-size: 14px;">Descargue copias de seguridad completas de la base de datos o de todos los archivos de código del sistema.</p>
            
            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                <a href="admin_mantenimiento.php?accion=respaldo_bd" class="btn btn-primary" onclick="mostrarCarga('Generando respaldo de Base de Datos...')">
                    <i class="fa-solid fa-database"></i> Descargar Respaldo de Base de Datos (.sql)
                </a>

                <a href="admin_mantenimiento.php?accion=respaldo_archivos" class="btn btn-warning" onclick="mostrarCarga('Comprimiendo archivos del sistema en ZIP...')">
                    <i class="fa-solid fa-file-zipper"></i> Descargar Respaldo de Archivos (.zip)
                </a>
            </div>
        </div>
    </div>

    <script>
        function mostrarCarga(mensaje) {
            document.getElementById('loadingText').innerText = mensaje;
            document.getElementById('loadingOverlay').style.display = 'flex';
            
            // Ocultar el cartel de carga automáticamente a los 5 segundos para permitir ver la descarga
            setTimeout(function() {
                document.getElementById('loadingOverlay').style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>