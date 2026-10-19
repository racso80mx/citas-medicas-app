<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_cita = $_GET['id_cita'] ?? null;
$folio_cita = '';
$fecha_cita = '';
$hora_cita = '';
$tipo_clinica = '';
$nombre_clinica = '';
$nombre_completo = '';
$expediente = '';
$curp = '';
$telefono = '';
$telefono_limpio = '';
$url_whatsapp = '#';

try {
    if ($id_cita) {
        // Cargar cita existente para ver comprobante
        $stmt_cita = $pdo->prepare("SELECT * FROM citas WHERE id_cita = :id");
        $stmt_cita->execute(['id' => $id_cita]);
        $cita = $stmt_cita->fetch(PDO::FETCH_ASSOC);

        if (!$cita) {
            die("Cita no encontrada.");
        }

        $folio_cita = $cita['id_cita'];
        $fecha_cita = $cita['fecha_cita'];
        $hora_cita = $cita['hora_cita'];
        $tipo_clinica = $cita['tipo_clinica'];
        $nombre_clinica = $cita['nombre_clinica'];
        $nombre_completo = $cita['nombre_paciente'];
        $expediente = $cita['no_expediente'];
        $curp = trim($cita['curp'] ?? '');
        $telefono = $cita['telefono'];
        $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono ?? '');
    } else {
        // Procesar nueva cita (POST desde el modal de admin_citas o admin_pacientes)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: admin_citas.php");
            exit();
        }

        $paciente_id = $_POST['paciente_id'] ?? null;
        $clinica_id = $_POST['clinica_id'] ?? null;
        $tipo_clinica = $_POST['tipo_clinica'] ?? '';
        $nombre_clinica_post = $_POST['nombre_clinica'] ?? '';
        $fecha_cita = $_POST['fecha_cita'] ?? '';
        $hora_cita = $_POST['hora_cita'] ?? '';
        $usuario_registro = $_SESSION['usuario_id'];

        if (!$paciente_id || empty($fecha_cita) || empty($hora_cita)) {
            die("Faltan datos obligatorios para procesar la cita.");
        }

        // Obtener datos del paciente
        $stmt_p = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
        $stmt_p->execute(['id' => $paciente_id]);
        $paciente = $stmt_p->fetch(PDO::FETCH_ASSOC);

        if (!$paciente) {
            die("Paciente no encontrado.");
        }

        // Obtener datos de la clínica si se envió el ID
        if ($clinica_id) {
            $stmt_c = $pdo->prepare("SELECT * FROM clinicas WHERE id_clinica = :id");
            $stmt_c->execute(['id' => $clinica_id]);
            $clinica = $stmt_c->fetch(PDO::FETCH_ASSOC);

            if ($clinica) {
                $tipo_clinica = $clinica['tipo_clinica'];
                $nombre_clinica = $clinica['nombre_clinica'];
            }
        } else {
            $tipo_clinica = $_POST['tipo_clinica'] ?? 'GENERAL';
            $nombre_clinica = $_POST['nombre_clinica'] ?? 'Consulta Externa';
        }

        $nombre_completo = trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apaterno'] ?? '') . ' ' . ($paciente['amaterno'] ?? ''));
        $expediente = $paciente['expediente'] ?? '';
        $curp = trim($paciente['curp'] ?? '');
        $telefono = $paciente['telefono'] ?? '';
        $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);

        // Insertar la nueva cita en la base de datos
        $sql_insert = "INSERT INTO citas (
                        id_paciente, fecha_cita, hora_cita, tipo_clinica, nombre_clinica, 
                        nombre_paciente, no_expediente, curp, telefono, estatus, fecha_registro
                       ) VALUES (
                        :id_paciente, :fecha_cita, :hora_cita, :tipo_clinica, :nombre_clinica, 
                        :nombre_paciente, :no_expediente, :curp, :telefono, 'Vigente', NOW()
                       )";
        
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->execute([
            'id_paciente' => (string)$paciente_id,
            'fecha_cita' => $fecha_cita,
            'hora_cita' => $hora_cita,
            'tipo_clinica' => $tipo_clinica,
            'nombre_clinica' => $nombre_clinica,
            'nombre_paciente' => $nombre_completo,
            'no_expediente' => $expediente,
            'curp' => $curp,
            'telefono' => $telefono
        ]);

        $folio_cita = $pdo->lastInsertId();
    }

    // Formatear fecha en español
    $timestamp = strtotime($fecha_cita);
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    
    $nombre_dia = $dias[date('w', $timestamp)];
    $numero_dia = date('d', $timestamp);
    $nombre_mes = $meses[date('n', $timestamp)];
    $anio = date('Y', $timestamp);
    
    $fecha_formateada_es = "$nombre_dia, $numero_dia de $nombre_mes de $anio";

    // Enlace de WhatsApp
    $texto_whatsapp = "Hola $nombre_completo, le saludamos del Hospital General de Huimanguillo Dr. Adelfo S. Aguirre. Su cita ha sido programada con éxito:\n\n" .
                      "Folio: $folio_cita\n" .
                      "Servicio: $tipo_clinica - $nombre_clinica\n" .
                      "Fecha: $fecha_formateada_es\n" .
                      "Hora: $hora_cita HRS.\n\n" .
                      "Instrucciones: Recuerda estar 30 minutos antes de tu cita, usar cubrebocas y traer tu cartilla de vacunación.";
    
    $url_whatsapp = "https://api.whatsapp.com/send?phone=52$telefono_limpio&text=" . urlencode($texto_whatsapp);

} catch (Exception $e) {
    die("Error al procesar la cita: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante de Cita - Hospital General de Huimanguillo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #555; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; }
        .sheet { width: 210mm; min-height: 297mm; background: white; margin: 0 auto; padding: 20mm; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.3); position: relative; }
        
        .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 15px; margin-bottom: 20px; }
        .header-container img { max-height: 70px; }
        .header-title { text-align: right; color: #006847; font-size: 22px; font-weight: bold; line-height: 1.2; }

        .banner-verde { background-color: #006847; color: white; text-align: center; font-size: 18px; font-weight: bold; padding: 8px; margin-bottom: 20px; letter-spacing: 1px; }
        
        .fecha-box { background-color: #e8f5e9; border: 1px solid #c8e6c9; display: flex; justify-content: space-between; align-items: center; padding: 15px 25px; margin-bottom: 25px; border-radius: 4px; }
        .fecha-box .fecha-texto { color: #006847; font-size: 24px; font-weight: bold; text-transform: capitalize; }
        .fecha-box .hora-texto { color: #006847; font-size: 24px; font-weight: bold; }

        .info-grid { display: grid; grid-template-columns: 140px 1fr; gap: 15px 20px; font-size: 16px; margin-bottom: 30px; align-items: center; }
        .label-campo { font-weight: bold; color: #333; }
        .valor-campo { background: #f1f3f5; padding: 8px 12px; border-radius: 4px; color: #1c252e; font-weight: 500; }

        .instrucciones-box { border-top: 2px solid #006847; padding-top: 15px; margin-top: 40px; }
        .instrucciones-titulo { background-color: #006847; color: white; padding: 6px 12px; font-weight: bold; display: inline-block; margin-bottom: 10px; font-size: 14px; }
        .instrucciones-texto { font-size: 14px; color: #333; line-height: 1.5; font-weight: bold; text-transform: uppercase; }

        .no-print { text-align: center; margin-top: 25px; }
        .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 15px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; margin: 0 5px; }
        .btn-whatsapp { background-color: #25d366; }
        .btn:hover { opacity: 0.9; }

        @media print {
            body { background: none; padding: 0; }
            .sheet { box-shadow: none; padding: 10mm; width: 100%; margin: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="sheet">
        <div class="header-container">
            <img src="logo_hospital.png" alt="Logo Hospital General de Huimanguillo">
            <div class="header-title">
                HOSPITAL GENERAL DE<br>HUIMANGUILLO
            </div>
        </div>

        <div class="banner-verde">DETALLES DE LA CITA</div>

        <div class="fecha-box">
            <div class="fecha-texto"><i class="fa-solid fa-calendar-days"></i> <?= $fecha_formateada_es ?></div>
            <div class="hora-texto"><i class="fa-solid fa-clock"></i> <?= $hora_cita ?> HRS.</div>
        </div>

        <div class="info-grid">
            <div class="label-campo">FOLIO</div>
            <div class="valor-campo" style="font-weight: bold; color: #9f2241;"><?= $folio_cita ?></div>

            <div class="label-campo">CONSULTA</div>
            <div class="valor-campo"><?= htmlspecialchars($tipo_clinica) ?> — <?= htmlspecialchars($nombre_clinica) ?></div>

            <div class="label-campo">PACIENTE</div>
            <div class="valor-campo"><?= htmlspecialchars($nombre_completo) ?></div>

            <div class="label-campo">EXPEDIENTE</div>
            <div class="valor-campo"><?= htmlspecialchars($expediente ?: 'S/N') ?></div>

            <div class="label-campo">CURP</div>
            <div class="valor-campo"><?= htmlspecialchars($curp ?: 'S/N') ?></div>

            <div class="label-campo">TELÉFONO</div>
            <div class="valor-campo"><?= htmlspecialchars($telefono ?: 'S/N') ?></div>
        </div>

        <div class="instrucciones-box">
            <div class="instrucciones-titulo">INSTRUCCIONES IMPORTANTES:</div>
            <div class="instrucciones-texto">
                RECUERDA ESTAR 30 MINUTOS ANTES DE TU CITA, USAR CUBREBOCAS Y TRAER TU CARTILLA DE VACUNACIÓN.
            </div>
        </div>
    </div>

    <div class="no-print">
        <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir / Guardar PDF</button>
        <?php if (!empty($telefono_limpio)): ?>
            <a href="<?= $url_whatsapp ?>" target="_blank" class="btn btn-whatsapp"><i class="fa-brands fa-whatsapp"></i> Enviar por WhatsApp</a>
        <?php endif; ?>
        <a href="archivo.php" class="btn" style="background-color: #637381;"><i class="fa-solid fa-arrow-left"></i> Regresar a Citas</a>
    </div>

</body>
</html>