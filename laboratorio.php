<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$mensaje = '';
$error = '';
$paciente_seleccionado_id = null;

// ===================================================================
// 2. PROCESAMIENTO DE ACCIONES (POST Y EXPORTACIONES GET)
// ===================================================================
$hoy_str = date('Y-m-d');
$accion = $_REQUEST['accion'] ?? '';

// Fecha seleccionada activa para la interfaz de agendamiento
$fecha_seleccionada = $_REQUEST['fecha_seleccionada'] ?? $_GET['fecha_cita'] ?? $hoy_str;

// Definición de filtros de fecha para Excel, PDF y grid inferior
$tipo_filtro = $_GET['tipo_filtro'] ?? 'dia';
$filtro_fecha = $_GET['filtro_fecha'] ?? $hoy_str;
$fecha_inicio = $_GET['fecha_inicio'] ?? $hoy_str;
$fecha_fin = $_GET['fecha_fin'] ?? $hoy_str;

if ($tipo_filtro === 'hoy') {
    $f_ini = $hoy_str;
    $f_fin = $hoy_str;
} elseif ($tipo_filtro === 'manana') {
    $f_ini = date('Y-m-d', strtotime('+1 day'));
    $f_fin = date('Y-m-d', strtotime('+1 day'));
} elseif ($tipo_filtro === 'semana') {
    $f_ini = date('Y-m-d', strtotime('monday this week'));
    $f_fin = date('Y-m-d', strtotime('sunday this week'));
} elseif ($tipo_filtro === 'mes') {
    $f_ini = date('Y-m-01');
    $f_fin = date('Y-m-t');
} elseif ($tipo_filtro === 'rango') {
    $f_ini = !empty($fecha_inicio) ? $fecha_inicio : $hoy_str;
    $f_fin = !empty($fecha_fin) ? $fecha_fin : $hoy_str;
} else {
    $f_ini = !empty($filtro_fecha) ? $filtro_fecha : $hoy_str;
    $f_fin = !empty($filtro_fecha) ? $filtro_fecha : $hoy_str;
}

// Función auxiliar para formatear fechas en español estilo "viernes 11 de septiembre del 2026"
function formatearFechaEnEspanol($fecha_str) {
    if (empty($fecha_str)) return '';
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    
    $timestamp = strtotime($fecha_str);
    if (!$timestamp) return $fecha_str;
    
    $nDiaSema = date('w', $timestamp);
    $nDiaMes  = date('j', $timestamp);
    $nMesNum  = date('n', $timestamp);
    $Anio     = date('Y', $timestamp);
    
    return $dias[$nDiaSema] . ' ' . $nDiaMes . ' de ' . $meses[$nMesNum] . ' del ' . $Anio;
}

// Endpoint AJAX para búsqueda de pacientes en tiempo real
if ($accion === 'buscar_pacientes_ajax') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $term = trim($_GET['term'] ?? '');
    try {
        $sql = "SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp FROM pacientes";
        $params = [];
        if (!empty($term)) {
            $sql .= " WHERE nombre ILIKE :q OR apaterno ILIKE :q OR amaterno ILIKE :q OR expediente ILIKE :q OR curp ILIKE :q";
            $params['q'] = '%' . $term . '%';
        }
        $sql .= " ORDER BY id_paciente DESC LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// Exportar Excel
if ($accion === 'exportar_excel') {
    if (ob_get_length()) ob_clean();
    try {
        $stmt_exp = $pdo->prepare("SELECT * FROM laboratorio_citas WHERE fecha_cita BETWEEN :fini AND :ffin ORDER BY fecha_cita DESC, id_cita_lab DESC");
        $stmt_exp->execute(['fini' => $f_ini, 'ffin' => $f_fin]);
        $citas_excel = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=reporte_citas_laboratorio_" . $f_ini . "_al_" . $f_fin . ".xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        echo "<table border='1'>";
        echo "<tr style='background-color:#006847; color:#ffffff; font-weight:bold;'>
                <th>ID Cita</th><th>Expediente</th><th>Paciente</th><th>Fecha Cita</th><th>Estudios Solicitados</th><th>Estatus</th>
              </tr>";
        foreach ($citas_excel as $c) {
            $st_det = $pdo->prepare("SELECT e.estudio, e.nombre_corto FROM laboratorio_cita_detalles d JOIN estudios_laboratorio e ON d.id_estudio = e.id_estudio WHERE d.id_cita_lab = :id");
            $st_det->execute(['id' => $c['id_cita_lab']]);
            $est_detalles = $st_det->fetchAll(PDO::FETCH_ASSOC);
            $str_estudios = "";
            foreach($est_detalles as $ed) {
                $str_estudios .= "[" . $ed['nombre_corto'] . "] " . $ed['estudio'] . "; ";
            }
            echo "<tr>";
            echo "<td>" . htmlspecialchars($c['id_cita_lab']) . "</td>";
            echo "<td>" . htmlspecialchars($c['no_expediente'] ?? 'S/N') . "</td>";
            echo "<td>" . htmlspecialchars($c['nombre_paciente']) . "</td>";
            echo "<td>" . htmlspecialchars(formatearFechaEnEspanol($c['fecha_cita'])) . "</td>";
            echo "<td>" . htmlspecialchars($str_estudios) . "</td>";
            echo "<td>" . htmlspecialchars($c['estatus']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "Error al exportar Excel: " . $e->getMessage();
    }
    exit();
}

// Exportar PDF en formato HORIZONTAL con logo_hospital.png
if ($accion === 'exportar_pdf') {
    if (ob_get_length()) ob_clean();
    try {
        $stmt_pdf = $pdo->prepare("SELECT * FROM laboratorio_citas WHERE fecha_cita BETWEEN :fini AND :ffin ORDER BY fecha_cita ASC, id_cita_lab ASC");
        $stmt_pdf->execute(['fini' => $f_ini, 'ffin' => $f_fin]);
        $citas_pdf = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);

        foreach ($citas_pdf as &$cp) {
            $st_det = $pdo->prepare("SELECT e.estudio, e.nombre_corto, e.tipo_muestra, e.requiere_ayuno FROM laboratorio_cita_detalles d JOIN estudios_laboratorio e ON d.id_estudio = e.id_estudio WHERE d.id_cita_lab = :id");
            $st_det->execute(['id' => $cp['id_cita_lab']]);
            $cp['estudios'] = $st_det->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($cp);
    } catch (Exception $e) {
        $citas_pdf = [];
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Listado de Citas de Laboratorio</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            @page { size: landscape; margin: 10mm; }
            body { background-color: #555; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; }
            .sheet { width: 100%; max-width: 277mm; min-height: 180mm; background: white; margin: 0 auto; padding: 15mm; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; justify-content: space-between; }
            .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 12px; margin-bottom: 15px; }
            .header-container img { max-height: 55px; }
            .header-title { text-align: right; color: #006847; font-size: 18px; font-weight: bold; line-height: 1.2; }
            .banner-verde { background-color: #006847; color: white; text-align: center; font-size: 14px; font-weight: bold; padding: 6px; margin-bottom: 15px; letter-spacing: 1px; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 20px; }
            th { background-color: #f1f3f5; color: #333; padding: 8px 6px; border: 1px solid #dfe3e8; text-align: left; }
            td { padding: 7px 6px; border: 1px solid #dfe3e8; vertical-align: top; }
            tr:nth-child(even) { background-color: #fafbfc; }
            .no-print { text-align: center; margin-top: 20px; }
            .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
            @media print { body { background: none; padding: 0; } .sheet { box-shadow: none; padding: 5mm; width: 100%; margin: 0; min-height: auto; } .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="sheet">
            <div>
                <div class="header-container">
                    <img src="logo_hospital.png" alt="Logo Hospital" onerror="this.style.display='none'">
                    <div class="header-title">
                        HOSPITAL GENERAL DE HUIMANGUILLO<br><span style="font-size: 12px; color: #637381; font-weight: normal;">MÓDULO DE LABORATORIO - CITAS PROGRAMADAS</span>
                    </div>
                </div>
                <div class="banner-verde">REPORTE DE CITAS Y ESTUDIOS (DEL <?php echo formatearFechaEnEspanol($f_ini); ?> AL <?php echo formatearFechaEnEspanol($f_fin); ?>)</div>
                <table>
                    <thead>
                        <tr>
                            <th>Expediente</th>
                            <th>Paciente</th>
                            <th>Fecha</th>
                            <th>Estudios y Requisitos</th>
                            <th>Estatus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($citas_pdf)): ?>
                            <?php foreach ($citas_pdf as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['no_expediente'] ?? 'S/N'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['nombre_paciente']); ?></td>
                                    <td><strong><?php echo htmlspecialchars(formatearFechaEnEspanol($row['fecha_cita'])); ?></strong></td>
                                    <td>
                                        <?php foreach ($row['estudios'] as $est): ?>
                                            <div style="margin-bottom: 4px;">
                                                <strong>[<?php echo htmlspecialchars($est['nombre_corto']); ?>]</strong> <?php echo htmlspecialchars($est['estudio']); ?><br>
                                                <span style="font-size: 9px; color: #555;">Muestra: <?php echo htmlspecialchars($est['tipo_muestra']); ?> | Ayuno: <?php echo htmlspecialchars($est['requiere_ayuno']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['estatus']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 20px; color: #637381;">No se encontraron registros de citas en el rango seleccionado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="no-print">
                <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir / Guardar PDF Horizontal</button>
                <a href="laboratorio.php" class="btn" style="background-color: #637381; margin-left: 10px;"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Generar Comprobante PDF Individual
if ($accion === 'comprobante_cita_pdf') {
    if (ob_get_length()) ob_clean();
    $id_cita = intval($_GET['id_cita'] ?? 0);
    try {
        $st_c = $pdo->prepare("SELECT * FROM laboratorio_citas WHERE id_cita_lab = :id");
        $st_c->execute(['id' => $id_cita]);
        $cita_info = $st_c->fetch(PDO::FETCH_ASSOC);

        $cfg_q = $pdo->query("SELECT horario_toma_muestra FROM config_laboratorio WHERE id_config = 1")->fetch(PDO::FETCH_ASSOC);
        $horario_toma = $cfg_q['horario_toma_muestra'] ?? '07:00 hrs';

        $st_d = $pdo->prepare("SELECT e.* FROM laboratorio_cita_detalles d JOIN estudios_laboratorio e ON d.id_estudio = e.id_estudio WHERE d.id_cita_lab = :id");
        $st_d->execute(['id' => $id_cita]);
        $estudios_cita = $st_d->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cita_info = null;
        $estudios_cita = [];
    }
    
    // Formato solicitado: "viernes 11 de septiembre del 2026 a las 07:00 hrs"
    $fecha_formateada_completa = '';
    if ($cita_info && !empty($cita_info['fecha_cita'])) {
        $fecha_formateada_completa = formatearFechaEnEspanol($cita_info['fecha_cita']);
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Comprobante de Cita de Laboratorio</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { background-color: #555; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; }
            .ticket { width: 200mm; background: white; margin: 0 auto; padding: 15mm; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.3); }
            .header-ticket { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 10px; margin-bottom: 15px; }
            .header-ticket img { max-height: 55px; }
            .header-title { text-align: right; color: #006847; font-size: 18px; font-weight: bold; }
            .info-box { background: #f8f9fa; border: 1px solid #dfe3e8; border-radius: 6px; padding: 12px; margin-bottom: 15px; font-size: 13px; }
            .info-box p { margin: 4px 0; }
            .aviso-alerta { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 10px; border-radius: 6px; font-size: 13px; font-weight: bold; text-align: center; margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 20px; }
            th { background-color: #006847; color: white; padding: 8px; text-align: left; }
            td { padding: 8px; border: 1px solid #dfe3e8; vertical-align: top; }
            .no-print { text-align: center; margin-top: 20px; }
            .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
            @media print { body { background: none; padding: 0; } .ticket { box-shadow: none; padding: 10mm; width: 100%; } .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="ticket">
            <div>
                <div class="header-ticket">
                    <img src="logo_hospital.png" alt="Logo Hospital" onerror="this.style.display='none'">
                    <div class="header-title">
                        HOSPITAL GENERAL DE HUIMANGUILLO<br><span style="font-size: 12px; color: #637381; font-weight: normal;">COMPROBANTE DE CITA DE LABORATORIO</span>
                    </div>
                </div>
                <?php if ($cita_info): ?>
                    <div class="info-box">
                        <p><strong>Folio de Cita:</strong> #<?php echo $cita_info['id_cita_lab']; ?></p>
                        <p><strong>Paciente:</strong> <?php echo htmlspecialchars($cita_info['nombre_paciente']); ?></p>
                        <p><strong>Número de Expediente:</strong> <?php echo htmlspecialchars($cita_info['no_expediente'] ?? 'S/N'); ?></p>
                        <p><strong>Fecha de Cita:</strong> <?php echo htmlspecialchars($fecha_formateada_completa); ?></p>
                        <p><strong>Horario de Toma de Muestra:</strong> <?php echo htmlspecialchars($horario_toma); ?></p>
                    </div>
                    <div class="aviso-alerta">
                        <i class="fa-solid fa-triangle-exclamation"></i> IMPORTANTE: Favor de presentarse 30 minutos antes de la hora programada (<?php echo htmlspecialchars($horario_toma); ?>) en el área de laboratorio.
                    </div>
                    <h4 style="color: #006847; margin-bottom: 8px;">Estudios Solicitados e Indicaciones:</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Estudio Clínico</th>
                                <th>Tipo de Muestra</th>
                                <th>Requisito de Ayuno / Preparación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudios_cita as $est): ?>
                                <tr>
                                    <td><strong>[<?php echo htmlspecialchars($est['nombre_corto']); ?>]</strong> <?php echo htmlspecialchars($est['estudio']); ?></td>
                                    <td><?php echo htmlspecialchars($est['tipo_muestra']); ?></td>
                                    <td><?php echo htmlspecialchars($est['requiere_ayuno']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #9f2241;">Cita no encontrada.</p>
                <?php endif; ?>
            </div>
            <div class="no-print">
                <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir Comprobante</button>
                <a href="laboratorio.php" class="btn" style="background-color: #637381; margin-left: 10px;"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($accion === 'actualizar_config') {
        $max_citas = intval($_POST['max_citas_dia'] ?? 20);
        $horario_toma = trim($_POST['horario_toma_muestra'] ?? '07:00 hrs');
        try {
            $stmt = $pdo->prepare("UPDATE config_laboratorio SET max_citas_dia = :max, horario_toma_muestra = :horario WHERE id_config = 1");
            $stmt->execute(['max' => $max_citas, 'horario' => $horario_toma]);
            $mensaje = "Configuración actualizada correctamente.";
        } catch (Exception $e) {
            $error = "Error al actualizar: " . $e->getMessage();
        }
    }

    if ($accion === 'guardar_estudio') {
        $id_estudio     = intval($_POST['id_estudio'] ?? 0);
        $tipo_estudio   = trim($_POST['tipo_estudio'] ?? '');
        $estudio        = trim($_POST['estudio'] ?? '');
        $nombre_corto   = trim($_POST['nombre_corto'] ?? '');
        $tipo_muestra   = trim($_POST['tipo_muestra'] ?? '');
        $requiere_ayuno = trim($_POST['requiere_ayuno'] ?? '');
        $horas_ayuno    = intval($_POST['horas_ayuno'] ?? 0);

        if (!empty($tipo_estudio) && !empty($estudio) && !empty($nombre_corto)) {
            try {
                if ($id_estudio > 0) {
                    $stmt = $pdo->prepare("UPDATE estudios_laboratorio SET tipo_estudio = :tipo, estudio = :est, nombre_corto = :nc, tipo_muestra = :tm, requiere_ayuno = :ra, horas_ayuno = :ha WHERE id_estudio = :id");
                    $stmt->execute(['tipo' => $tipo_estudio, 'est' => $estudio, 'nc' => $nombre_corto, 'tm' => $tipo_muestra, 'ra' => $requiere_ayuno, 'ha' => $horas_ayuno, 'id' => $id_estudio]);
                    $mensaje = "Estudio actualizado.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO estudios_laboratorio (tipo_estudio, estudio, nombre_corto, tipo_muestra, requiere_ayuno, horas_ayuno) VALUES (:tipo, :est, :nc, :tm, :ra, :ha)");
                    $stmt->execute(['tipo' => $tipo_estudio, 'est' => $estudio, 'nc' => $nombre_corto, 'tm' => $tipo_muestra, 'ra' => $requiere_ayuno, 'ha' => $horas_ayuno]);
                    $mensaje = "Estudio agregado.";
                }
            } catch (Exception $e) {
                $error = "Error al guardar estudio: " . $e->getMessage();
            }
        }
    }

    if ($accion === 'eliminar_estudio') {
        $id_estudio = intval($_POST['id_estudio'] ?? 0);
        if ($id_estudio > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM estudios_laboratorio WHERE id_estudio = :id");
                $stmt->execute(['id' => $id_estudio]);
                $mensaje = "Estudio eliminado.";
            } catch (Exception $e) {
                $error = "No se puede eliminar porque está vinculado a citas.";
            }
        }
    }

    if ($accion === 'registrar_paciente_rapido') {
        $curp        = trim(strtoupper($_POST['p_curp'] ?? ''));
        $nombre      = trim($_POST['p_nombre'] ?? '');
        $apaterno    = trim($_POST['p_apaterno'] ?? '');
        $amaterno    = trim($_POST['p_amaterno'] ?? '');
        $expediente  = trim($_POST['p_expediente'] ?? '');
        $telefono    = trim($_POST['p_telefono'] ?? '');
        $estado      = trim($_POST['p_estado'] ?? '');
        $fnacimiento = trim($_POST['p_fnacimiento'] ?? '');
        $edad        = intval($_POST['p_edad'] ?? 0);
        $sexo        = trim($_POST['p_sexo'] ?? '');
        $domicilio   = trim($_POST['p_domicilio'] ?? '');
        $es_rn       = isset($_POST['es_rn']) ? true : false;
        $fecha_sel   = $_POST['fecha_seleccionada'] ?? $hoy_str;

        if (empty($expediente)) $expediente = "SE-" . strval(rand(100000, 999999));
        if ($es_rn && empty($curp)) {
            $countRn = $pdo->query("SELECT COUNT(*) FROM pacientes WHERE curp LIKE 'RN-%'")->fetchColumn();
            $curp = "RN-" . date('Y') . "-" . str_pad($countRn + 1, 5, '0', STR_PAD_LEFT);
        }

        if (!empty($curp)) {
            try {
                $stmt_chk = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE curp = :curp LIMIT 1");
                $stmt_chk->execute(['curp' => $curp]);
                $pac_existente = $stmt_chk->fetch(PDO::FETCH_ASSOC);
                if ($pac_existente) {
                    $error = "La CURP ya está registrada en el sistema.";
                    $paciente_seleccionado_id = $pac_existente['id_paciente'];
                }
            } catch (Exception $e) {}
        }

        if (empty($error) && !empty($nombre)) {
            try {
                $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS estado VARCHAR(100)");
                $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS fnacimiento VARCHAR(50)");
                $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS edad INT");
                $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS sexo VARCHAR(10)");
                $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS domicilio TEXT");

                $stmt = $pdo->prepare("INSERT INTO pacientes (nombre, apaterno, amaterno, expediente, curp, telefono, estado, fnacimiento, edad, sexo, domicilio) VALUES (:nom, :ap, :am, :exp, :curp, :tel, :est, :fnac, :edad, :sexo, :dom) RETURNING id_paciente");
                $stmt->execute([
                    'nom' => $nombre, 'ap' => $apaterno, 'am' => $amaterno, 'exp' => $expediente, 
                    'curp' => $curp, 'tel' => $telefono, 'est' => $estado, 'fnac' => !empty($fnacimiento) ? $fnacimiento : null, 
                    'edad' => $edad, 'sexo' => $sexo, 'dom' => $domicilio
                ]);
                $paciente_seleccionado_id = $stmt->fetchColumn();
                $mensaje = "Paciente registrado correctamente.";
            } catch (Exception $e) {
                $error = "Error al registrar paciente: " . $e->getMessage();
            }
        }
    }

    if ($accion === 'registrar_cita_lab') {
        $id_paciente  = intval($_POST['id_paciente'] ?? 0);
        $fecha_cita   = trim($_POST['fecha_seleccionada'] ?? $hoy_str);
        $estudios_sel = $_POST['estudios'] ?? [];

        if ($id_paciente > 0 && !empty($fecha_cita) && !empty($estudios_sel)) {
            try {
                $cfg = $pdo->query("SELECT max_citas_dia FROM config_laboratorio WHERE id_config = 1")->fetch(PDO::FETCH_ASSOC);
                $max_cupo = $cfg ? intval($cfg['max_citas_dia']) : 20;

                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM laboratorio_citas WHERE fecha_cita = :f");
                $stmt_count->execute(['f' => $fecha_cita]);
                $ocupadas = intval($stmt_count->fetchColumn());

                if ($ocupadas >= $max_cupo) {
                    $error = "El cupo para la fecha {$fecha_cita} está completo ({$ocupadas}/{$max_cupo}).";
                } else {
                    $stmt_pac = $pdo->prepare("SELECT expediente, nombre, apaterno, amaterno FROM pacientes WHERE id_paciente = :id");
                    $stmt_pac->execute(['id' => $id_paciente]);
                    $pac = $stmt_pac->fetch(PDO::FETCH_ASSOC);

                    if ($pac) {
                        $nombre_completo = trim($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno']);
                        $expediente      = $pac['expediente'] ?? 'S/N';

                        $stmt_ins = $pdo->prepare("INSERT INTO laboratorio_citas (id_paciente, no_expediente, nombre_paciente, fecha_cita, estatus) VALUES (:id_pac, :exp, :nom, :f, 'PROGRAMADA')");
                        $stmt_ins->execute(['id_pac' => $id_paciente, 'exp' => $expediente, 'nom' => $nombre_completo, 'f' => $fecha_cita]);
                        $id_cita_lab = $pdo->lastInsertId();

                        $stmt_det = $pdo->prepare("INSERT INTO laboratorio_cita_detalles (id_cita_lab, id_estudio) VALUES (:cita, :est)");
                        foreach ($estudios_sel as $id_est) {
                            $stmt_det->execute(['cita' => $id_cita_lab, 'est' => intval($id_est)]);
                        }

                        header("Location: laboratorio.php?accion=comprobante_cita_pdf&id_cita=" . $id_cita_lab);
                        exit();
                    }
                }
            } catch (Exception $e) {
                $error = "Error al agendar cita: " . $e->getMessage();
            }
        } else {
            $error = "Debe seleccionar un paciente y al menos un estudio.";
        }
    }

    if ($accion === 'editar_cita_lab') {
        $id_cita_lab  = intval($_POST['id_cita_lab'] ?? 0);
        $fecha_cita   = trim($_POST['fecha_cita'] ?? '');
        $estudios_sel = $_POST['estudios'] ?? [];

        if ($id_cita_lab > 0 && !empty($fecha_cita) && !empty($estudios_sel)) {
            try {
                $stmt_up = $pdo->prepare("UPDATE laboratorio_citas SET fecha_cita = :f WHERE id_cita_lab = :id");
                $stmt_up->execute(['f' => $fecha_cita, 'id' => $id_cita_lab]);

                $pdo->prepare("DELETE FROM laboratorio_cita_detalles WHERE id_cita_lab = :id")->execute(['id' => $id_cita_lab]);
                $stmt_det = $pdo->prepare("INSERT INTO laboratorio_cita_detalles (id_cita_lab, id_estudio) VALUES (:cita, :est)");
                foreach ($estudios_sel as $id_est) {
                    $stmt_det->execute(['cita' => $id_cita_lab, 'est' => intval($id_est)]);
                }
                $mensaje = "Cita actualizada.";
            } catch (Exception $e) {
                $error = "Error al actualizar: " . $e->getMessage();
            }
        }
    }

    if ($accion === 'eliminar_cita_lab') {
        $id_cita_lab = intval($_POST['id_cita_lab'] ?? 0);
        if ($id_cita_lab > 0) {
            try {
                $pdo->prepare("DELETE FROM laboratorio_citas WHERE id_cita_lab = :id")->execute(['id' => $id_cita_lab]);
                $mensaje = "Cita eliminada.";
            } catch (Exception $e) {
                $error = "Error al eliminar cita.";
            }
        }
    }
}

// ===================================================================
// 3. DATOS PARA LA VISTA
// ===================================================================
$config_lab = $pdo->query("SELECT * FROM config_laboratorio WHERE id_config = 1")->fetch(PDO::FETCH_ASSOC);
$max_citas_dia = $config_lab ? intval($config_lab['max_citas_dia']) : 20;
$horario_toma_muestra = $config_lab['horario_toma_muestra'] ?? '07:00 hrs';

// Cupo del día seleccionado
$stmt_ocupadas = $pdo->prepare("SELECT COUNT(*) FROM laboratorio_citas WHERE fecha_cita = :f");
$stmt_ocupadas->execute(['f' => $fecha_seleccionada]);
$citas_ocupadas_dia = intval($stmt_ocupadas->fetchColumn());
$espacios_libres = max(0, $max_citas_dia - $citas_ocupadas_dia);

// Listado de citas para la columna derecha correspondientes al día seleccionado
$stmt_citas_dia = $pdo->prepare("SELECT * FROM laboratorio_citas WHERE fecha_cita = :f ORDER BY id_cita_lab ASC");
$stmt_citas_dia->execute(['f' => $fecha_seleccionada]);
$citas_del_dia = $stmt_citas_dia->fetchAll(PDO::FETCH_ASSOC);

$total_citas_dia = count($citas_del_dia);
$total_estudios_dia = 0;
foreach ($citas_del_dia as &$cd) {
    $st_det_dia = $pdo->prepare("SELECT e.estudio, e.nombre_corto FROM laboratorio_cita_detalles d JOIN estudios_laboratorio e ON d.id_estudio = e.id_estudio WHERE d.id_cita_lab = :id");
    $st_det_dia->execute(['id' => $cd['id_cita_lab']]);
    $cd['estudios'] = $st_det_dia->fetchAll(PDO::FETCH_ASSOC);
    $total_estudios_dia += count($cd['estudios']);
}
unset($cd);

// Listado inferior general de citas filtradas
$stmt_citas = $pdo->prepare("SELECT * FROM laboratorio_citas WHERE fecha_cita BETWEEN :fini AND :ffin ORDER BY fecha_cita DESC, id_cita_lab DESC");
$stmt_citas->execute(['fini' => $f_ini, 'ffin' => $f_fin]);
$citas_lista = $stmt_citas->fetchAll(PDO::FETCH_ASSOC);
foreach ($citas_lista as &$cita) {
    $stmt_est_cita = $pdo->prepare("SELECT e.id_estudio, e.estudio, e.nombre_corto, e.tipo_estudio, e.tipo_muestra, e.requiere_ayuno FROM laboratorio_cita_detalles d JOIN estudios_laboratorio e ON d.id_estudio = e.id_estudio WHERE d.id_cita_lab = :id");
    $stmt_est_cita->execute(['id' => $cita['id_cita_lab']]);
    $cita['estudios'] = $stmt_est_cita->fetchAll(PDO::FETCH_ASSOC);
}
unset($cita);

$estudios_catalogo = $pdo->query("SELECT * FROM estudios_laboratorio ORDER BY tipo_estudio ASC, estudio ASC")->fetchAll(PDO::FETCH_ASSOC);
$estados_mexico = ["AGUASCALIENTES", "BAJA CALIFORNIA", "BAJA CALIFORNIA SUR", "CAMPECHE", "CHIAPAS", "CHIHUAHUA", "CIUDAD DE MÉXICO", "COAHUILA", "COLIMA", "DURANGO", "ESTADO DE MÉXICO", "GUANAJUATO", "GUERRERO", "HIDALGO", "JALISCO", "MICHOACÁN", "MORELOS", "NAYARIT", "NUEVO LEÓN", "OAXACA", "PUEBLA", "QUERÉTARO", "QUINTANA ROO", "SAN LUIS POTOSÍ", "SINALOA", "SONORA", "TABASCO", "TAMAULIPAS", "TLAXCALA", "VERACRUZ", "YUCATÁN", "ZACATECAS"];

$filtro_busqueda_paciente = $_GET['q_paciente'] ?? '';
$sql_pac = "SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp FROM pacientes";
$params_pac = [];
if (!empty($filtro_busqueda_paciente)) {
    $sql_pac .= " WHERE nombre ILIKE :q OR apaterno ILIKE :q OR amaterno ILIKE :q OR expediente ILIKE :q OR curp ILIKE :q";
    $params_pac['q'] = '%' . $filtro_busqueda_paciente . '%';
} elseif (!empty($paciente_seleccionado_id)) {
    $sql_pac = "SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp FROM pacientes WHERE id_paciente = :id_sel UNION SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp FROM pacientes WHERE id_paciente <> :id_sel";
    $params_pac['id_sel'] = $paciente_seleccionado_id;
}
$sql_pac .= " ORDER BY id_paciente DESC LIMIT 50";
$stmt_p = $pdo->prepare($sql_pac);
$stmt_p->execute($params_pac);
$pacientes_grid = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

$expediente_aleatorio = "SE-" . strval(rand(100000, 999999));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Módulo de Laboratorio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-verde-hover: #004d34; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde-color: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: var(--texto-oscuro); }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: 700; font-size: 16px; }
        .container { max-width: 1550px; margin: 20px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 24px; margin: 0; color: var(--imss-verde); display: flex; align-items: center; gap: 10px; }
        
        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); margin-bottom: 25px; }
        .card-title { font-size: 16px; font-weight: 700; margin-top: 0; margin-bottom: 12px; color: var(--texto-oscuro); display: flex; align-items: center; gap: 8px; }
        
        /* 3 COLUMNAS: Calendario | Paciente y Estudios | Relación de Citas del Día */
        .layout-grid { display: grid; grid-template-columns: 300px 1fr 340px; gap: 20px; align-items: start; margin-bottom: 25px; }
        @media(max-width: 1200px) { .layout-grid { grid-template-columns: 1fr; } }

        /* CALENDARIO VISUAL */
        .calendar-box { background: white; border-radius: 10px; padding: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-weight: bold; color: var(--imss-verde); font-size: 15px; }
        .calendar-header button { background: none; border: none; font-size: 16px; cursor: pointer; color: var(--imss-verde); }
        .calendar-table { width: 100%; border-collapse: collapse; text-align: center; font-size: 12px; }
        .calendar-table th { padding: 6px; color: #637381; font-weight: 600; }
        .calendar-table td { padding: 6px; cursor: pointer; border-radius: 50%; transition: background 0.2s; }
        .calendar-table td:hover { background-color: #e1f5fe; }
        .calendar-table td.active-day { background-color: var(--imss-verde) !important; color: white; font-weight: bold; border-radius: 6px; }
        .calendar-table td.other-month { color: #b0bec5; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 9px 12px; font-size: 14px; border: 2px solid var(--borde-color); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; }
        .form-control:focus { border-color: var(--imss-verde); }

        .btn-action { background-color: var(--imss-verde); color: white; border: none; padding: 9px 16px; font-size: 14px; font-weight: 700; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-action:hover { background-color: var(--imss-verde-hover); }
        
        .btn-delete { background-color: var(--imss-dorado); color: white; border: none; padding: 5px 10px; font-size: 12px; border-radius: 4px; cursor: pointer; }
        .btn-edit { background-color: #0288d1; color: white; border: none; padding: 5px 10px; font-size: 12px; border-radius: 4px; cursor: pointer; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 10px; font-weight: 700; border-bottom: 2px solid var(--borde-color); white-space: nowrap; }
        table td { padding: 10px; border-bottom: 1px solid var(--borde-color); vertical-align: middle; }
        table tr:hover { background-color: #f8f9fa; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 750px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde-color); padding-bottom: 10px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: var(--imss-verde); font-size: 18px; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #637381; }

        .badge-cupo { display: inline-block; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 14px; background: #e3f2fd; color: #0288d1; }
        .badge-lleno { background: #ffebee; color: #c62828; }
        
        .estudio-chip { display: inline-flex; align-items: center; gap: 8px; background: #e1f5fe; color: #0288d1; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; margin: 4px; }
        .estudio-chip button { background: none; border: none; color: #c62828; cursor: pointer; font-size: 14px; font-weight: bold; }

        .cita-card-item { background: #fafbfc; border: 1px solid var(--borde-color); border-radius: 6px; padding: 10px; margin-bottom: 8px; font-size: 12px; }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-vials"></i> <strong>IMSS-BIENESTAR</strong> | Módulo de Laboratorio</div>
        <div style="display: flex; gap: 10px;">
            <button type="button" class="btn-action" style="background-color: var(--imss-dorado); padding: 6px 12px; font-size: 12px;" onclick="abrirModalConfig()">
                <i class="fa-solid fa-gear"></i> Configuración y Horario
            </button>
            <button type="button" class="btn-action" style="background-color: #0288d1; padding: 6px 12px; font-size: 12px;" onclick="abrirModalCatalogo()">
                <i class="fa-solid fa-book-medical"></i> Catálogo de Estudios
            </button>
            <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1><i class="fa-solid fa-microscope"></i> Asignación y Registro de Citas de Laboratorio</h1>
            <div class="badge-cupo <?php echo ($espacios_libres == 0) ? 'badge-lleno' : ''; ?>">
                Cupo Día (<?php echo formatearFechaEnEspanol($fecha_seleccionada); ?>): <?php echo $citas_ocupadas_dia; ?> / <?php echo $max_citas_dia; ?> | Toma: <strong><?php echo htmlspecialchars($horario_toma_muestra); ?></strong>
            </div>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- ESTRUCTURA DE 3 COLUMNAS -->
        <div class="layout-grid">
            
            <!-- COLUMNA 1: CALENDARIO -->
            <div>
                <div class="calendar-box">
                    <div class="calendar-header">
                        <button type="button" onclick="mesAnterior()"><i class="fa-solid fa-chevron-left"></i></button>
                        <span id="labelMesAnio">Septiembre 2026</span>
                        <button type="button" onclick="mesSiguiente()"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <table class="calendar-table">
                        <thead>
                            <tr>
                                <th>Lun</th><th>Mar</th><th>Mié</th><th>Jue</th><th>Vie</th><th>Sáb</th><th>Dom</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoCalendario"></tbody>
                    </table>
                </div>

                <div class="card" style="margin-top: 15px; padding: 15px;">
                    <div style="font-size: 13px; font-weight: bold; color: var(--imss-verde); margin-bottom: 5px;">Fecha Activa de Cita:</div>
                    <input type="text" id="inputFechaActivaVisual" class="form-control" value="<?php echo $fecha_seleccionada; ?>" readonly style="background: #e1f5fe; font-weight: bold; text-align: center;">
                </div>
            </div>

            <!-- COLUMNA 2: SELECCIÓN DE PACIENTE Y ESTUDIOS -->
            <div class="card" style="margin-top: 0;">
                <h2 class="card-title"><i class="fa-solid fa-calendar-plus"></i> Registrar Cita para el Día: <span style="color: var(--imss-verde);"><?php echo formatearFechaEnEspanol($fecha_seleccionada); ?></span></h2>
                
                <form action="laboratorio.php" method="POST" id="formCitaLab">
                    <input type="hidden" name="accion" value="registrar_cita_lab">
                    <input type="hidden" name="fecha_seleccionada" id="inputFechaCitaHidden" value="<?php echo $fecha_seleccionada; ?>">

                    <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
                        <button type="button" class="btn-action" style="background-color: var(--imss-dorado); padding: 7px 14px; font-size: 13px;" onclick="abrirModalPaciente()">
                            <i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente
                        </button>
                    </div>

                    <div class="form-group">
                        <label>Buscar Paciente (Resultados en tiempo real por nombre, expediente o CURP):</label>
                        <div style="position: relative; margin-bottom: 8px;">
                            <input type="text" id="inputFiltroPac" class="form-control" placeholder="Escriba para buscar..." value="<?php echo htmlspecialchars($filtro_busqueda_paciente); ?>" style="text-transform: uppercase; padding-right: 35px;" oninput="this.value = this.value.toUpperCase(); buscarPacientesEnVivo(this.value);">
                            <i id="btnLimpiarBusqueda" class="fa-solid fa-circle-xmark" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #919eab; cursor: pointer; display: <?php echo empty($filtro_busqueda_paciente) ? 'none' : 'block'; ?>;" onclick="limpiarBusquedaPaciente()" title="Borrar búsqueda"></i>
                        </div>

                        <div style="max-height: 220px; overflow-y: auto; border: 2px solid var(--borde-color); border-radius: 6px;" id="contenedorGridPacientes">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align: center;">Select</th>
                                        <th>Expediente</th>
                                        <th>Paciente</th>
                                        <th>CURP</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyPacientesAjax">
                                    <?php if(count($pacientes_grid) > 0): ?>
                                        <?php foreach($pacientes_grid as $pac): ?>
                                            <tr id="row_pac_<?php echo $pac['id_paciente']; ?>" <?php echo ($paciente_seleccionado_id == $pac['id_paciente']) ? 'style="background-color: #e4f8dd; font-weight: bold;"' : ''; ?>>
                                                <td style="text-align: center;">
                                                    <input type="radio" name="id_paciente" value="<?php echo $pac['id_paciente']; ?>" <?php echo ($paciente_seleccionado_id == $pac['id_paciente']) ? 'checked' : ''; ?> required style="cursor: pointer; width: 16px; height: 16px;">
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($pac['expediente'] ?? 'S/N'); ?></strong></td>
                                                <td><?php echo htmlspecialchars($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno']); ?></td>
                                                <td><?php echo htmlspecialchars($pac['curp'] ?? 'SIN CURP (RN)'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" style="text-align: center; color: #637381; padding: 15px;">No se encontraron pacientes.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Seleccionar o Escribir Estudio (Despliegue la lista o escriba y presione <strong>ENTER</strong>):</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="inputBuscaEstudio" class="form-control" list="listaEstudiosCatalogo" placeholder="Escriba abreviación..." autocomplete="off" style="text-transform: uppercase; flex: 1;" oninput="this.value = this.value.toUpperCase();">
                            <datalist id="listaEstudiosCatalogo">
                                <?php foreach($estudios_catalogo as $ec): ?>
                                    <option value="<?php echo htmlspecialchars($ec['nombre_corto']); ?>">[<?php echo htmlspecialchars($ec['nombre_corto']); ?>] <?php echo htmlspecialchars($ec['estudio']); ?></option>
                                <?php endforeach; ?>
                            </datalist>
                            <button type="button" class="btn-action" onclick="agregarEstudioPorInput()"><i class="fa-solid fa-plus"></i> Agregar</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Estudios Asignados a la Cita:</label>
                        <div id="contenedorEstudiosSeleccionados" style="min-height: 60px; border: 2px dashed var(--borde-color); border-radius: 6px; padding: 10px; background: #fafbfc; display: flex; flex-wrap: wrap; gap: 5px;">
                            <span id="sinEstudiosMsg" style="color: #919eab; font-size: 13px; margin: auto;">Ningún estudio agregado todavía.</span>
                        </div>
                    </div>

                    <div style="text-align: right; margin-top: 15px;">
                        <button type="submit" class="btn-action" style="font-size: 15px; padding: 12px 24px;"><i class="fa-solid fa-calendar-check"></i> Confirmar y Agendar Cita</button>
                    </div>
                </form>
            </div>

            <!-- COLUMNA 3: LISTADO DE PACIENTES CON CITA (MUESTRA CITAS OTORGADAS Y ESTUDIOS ASIGNADOS) -->
            <div class="card" style="margin-top: 0;">
                <h2 class="card-title" style="font-size: 13px;"><i class="fa-solid fa-list-check"></i> Citas del Día - Citas: <span style="color: var(--imss-verde);"><?php echo $total_citas_dia; ?></span> | Estudios: <span style="color: var(--imss-dorado);"><?php echo $total_estudios_dia; ?></span></h2>
                <div style="max-height: 480px; overflow-y: auto;">
                    <?php if(count($citas_del_dia) > 0): ?>
                        <?php foreach($citas_del_dia as $cd): ?>
                            <div class="cita-card-item">
                                <div style="display: flex; justify-content: space-between; font-weight: bold; color: var(--imss-verde);">
                                    <span>Exp: <?php echo htmlspecialchars($cd['no_expediente'] ?? 'S/N'); ?></span>
                                    <span>#<?php echo $cd['id_cita_lab']; ?></span>
                                </div>
                                <div style="font-weight: 700; color: var(--texto-oscuro); margin-top: 2px;"><?php echo htmlspecialchars($cd['nombre_paciente']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: #637381; padding: 30px 10px; font-size: 13px;">No hay pacientes con cita para este día.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- GRID INFERIOR: HISTORIAL Y FILTROS GENERALES -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h2 class="card-title" style="margin: 0;"><i class="fa-solid fa-calendar-days"></i> Historial y Citas Programadas (Filtros Generales)</h2>
                
                <form method="GET" id="formFiltrosCitas" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 0;">
                    <select name="tipo_filtro" id="selectTipoFiltro" class="form-control" style="width: auto; padding: 6px 10px;" onchange="cambiarTipoFiltro()">
                        <option value="hoy" <?php echo ($tipo_filtro === 'hoy') ? 'selected' : ''; ?>>Hoy</option>
                        <option value="manana" <?php echo ($tipo_filtro === 'manana') ? 'selected' : ''; ?>>Mañana</option>
                        <option value="dia" <?php echo ($tipo_filtro === 'dia') ? 'selected' : ''; ?>>Día Específico</option>
                        <option value="semana" <?php echo ($tipo_filtro === 'semana') ? 'selected' : ''; ?>>Esta Semana</option>
                        <option value="mes" <?php echo ($tipo_filtro === 'mes') ? 'selected' : ''; ?>>Este Mes</option>
                        <option value="rango" <?php echo ($tipo_filtro === 'rango') ? 'selected' : ''; ?>>Rango de Fechas</option>
                    </select>

                    <div id="filtroDiaContainer" style="display: <?php echo ($tipo_filtro === 'dia') ? 'block' : 'none'; ?>;">
                        <input type="date" name="filtro_fecha" class="form-control" value="<?php echo htmlspecialchars($filtro_fecha); ?>" style="padding: 6px 10px;" onchange="this.form.submit()">
                    </div>

                    <div id="filtroRangoContainer" style="display: <?php echo ($tipo_filtro === 'rango') ? 'flex' : 'none'; ?>; gap: 6px; align-items: center;">
                        <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($fecha_inicio); ?>" style="padding: 6px 10px;" title="Fecha Inicio">
                        <span style="font-size: 12px; font-weight: bold;">a</span>
                        <input type="date" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($fecha_fin); ?>" style="padding: 6px 10px;" title="Fecha Fin">
                        <button type="submit" class="btn-action" style="padding: 6px 12px; font-size: 12px;"><i class="fa-solid fa-filter"></i></button>
                    </div>

                    <div style="display: flex; gap: 8px; align-items: center; margin-left: 10px;">
                        <a href="laboratorio.php?accion=exportar_pdf&tipo_filtro=<?php echo $tipo_filtro; ?>&filtro_fecha=<?php echo $filtro_fecha; ?>&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" target="_blank" class="btn-action" style="background-color: var(--imss-dorado); padding: 8px 12px; font-size: 13px;">
                            <i class="fa-solid fa-file-pdf"></i> Descargar PDF Horizontal
                        </a>
                        <a href="laboratorio.php?accion=exportar_excel&tipo_filtro=<?php echo $tipo_filtro; ?>&filtro_fecha=<?php echo $filtro_fecha; ?>&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" class="btn-action" style="background-color: #217346; padding: 8px 12px; font-size: 13px;">
                            <i class="fa-solid fa-file-excel"></i> Exportar Excel
                        </a>
                    </div>
                </form>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Expediente</th>
                            <th>Paciente</th>
                            <th>Fecha Cita</th>
                            <th>Estudios Solicitados</th>
                            <th>Estatus</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($citas_lista) > 0): ?>
                            <?php foreach($citas_lista as $cita): ?>
                                <tr>
                                    <td><?php echo $cita['id_cita_lab']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($cita['no_expediente'] ?? 'S/N'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cita['nombre_paciente']); ?></td>
                                    <td><strong><?php echo htmlspecialchars(formatearFechaEnEspanol($cita['fecha_cita'])); ?></strong></td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <?php foreach($cita['estudios'] as $e): ?>
                                                <div style="background: #e1f5fe; color: #0288d1; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                    <strong>[<?php echo htmlspecialchars($e['nombre_corto']); ?>]</strong> <?php echo htmlspecialchars($e['estudio']); ?> 
                                                    <span style="color: #333; display: block; font-size: 11px; margin-top: 2px;">
                                                        <i class="fa-solid fa-vial"></i> Muestra: <?php echo htmlspecialchars($e['tipo_muestra']); ?> | 
                                                        <i class="fa-solid fa-clock"></i> Ayuno: <?php echo htmlspecialchars($e['requiere_ayuno']); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><span style="background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-size: 11px; font-weight: bold;"><?php echo htmlspecialchars($cita['estatus']); ?></span></td>
                                    <td style="text-align: center; display: flex; gap: 5px; justify-content: center;">
                                        <a href="laboratorio.php?accion=comprobante_cita_pdf&id_cita=<?php echo $cita['id_cita_lab']; ?>" target="_blank" class="btn-action" style="padding: 5px 8px; font-size: 11px; background-color: #0288d1;" title="Imprimir Comprobante"><i class="fa-solid fa-print"></i></a>
                                        <button type="button" class="btn-edit" onclick="abrirModalEditarCita(<?php echo $cita['id_cita_lab']; ?>, '<?php echo htmlspecialchars($cita['nombre_paciente'], ENT_QUOTES); ?>', '<?php echo $cita['fecha_cita']; ?>', <?php echo htmlspecialchars(json_encode(array_column($cita['estudios'], 'id_estudio'))); ?>)"><i class="fa-solid fa-pen"></i></button>
                                        <form action="laboratorio.php" method="POST" onsubmit="return confirm('¿Está seguro de eliminar esta cita?');" style="margin:0;">
                                            <input type="hidden" name="accion" value="eliminar_cita_lab">
                                            <input type="hidden" name="id_cita_lab" value="<?php echo $cita['id_cita_lab']; ?>">
                                            <button type="submit" class="btn-delete"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; color: #637381; padding: 25px;">No hay citas programadas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL CONFIGURACIÓN -->
    <div class="modal-overlay" id="modalConfig">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-gear"></i> Configuración de Cupo y Horario</h3>
                <button type="button" class="close-modal" onclick="cerrarModalConfig()">&times;</button>
            </div>
            <form action="laboratorio.php" method="POST">
                <input type="hidden" name="accion" value="actualizar_config">
                <div class="form-group"><label>Máximo de citas permitidas por día:</label><input type="number" name="max_citas_dia" class="form-control" value="<?php echo $max_citas_dia; ?>" required min="1" max="200"></div>
                <div class="form-group"><label>Horario de Toma de Muestra:</label><input type="text" name="horario_toma_muestra" class="form-control" value="<?php echo htmlspecialchars($horario_toma_muestra); ?>" required></div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalConfig()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL CATÁLOGO ESTUDIOS -->
    <div class="modal-overlay" id="modalCatalogo">
        <div class="modal-content" style="max-width: 850px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-book-medical"></i> Catálogo de Estudios de Laboratorio</h3>
                <button type="button" class="close-modal" onclick="cerrarModalCatalogo()">&times;</button>
            </div>
            <div style="margin-bottom: 15px; text-align: right;">
                <button type="button" class="btn-action" style="padding: 6px 12px; font-size: 12px;" onclick="abrirModalEstudioForm()"><i class="fa-solid fa-plus"></i> Nuevo Estudio</button>
            </div>
            <div style="max-height: 350px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr><th>Área / Tipo</th><th>Estudio</th><th>Abrev.</th><th>Muestra</th><th>Ayuno</th><th style="text-align: center;">Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($estudios_catalogo as $est): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($est['tipo_estudio']); ?></strong></td>
                                <td><?php echo htmlspecialchars($est['estudio']); ?></td>
                                <td><span style="background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-size:11px;"><?php echo htmlspecialchars($est['nombre_corto']); ?></span></td>
                                <td><?php echo htmlspecialchars($est['tipo_muestra']); ?></td>
                                <td><span style="font-size: 11px;"><?php echo htmlspecialchars($est['requiere_ayuno']); ?></span></td>
                                <td style="text-align: center; display: flex; gap: 4px; justify-content: center;">
                                    <button type="button" class="btn-edit" onclick="editarEstudio(<?php echo $est['id_estudio']; ?>, '<?php echo htmlspecialchars($est['tipo_estudio'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($est['estudio'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($est['nombre_corto'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($est['tipo_muestra'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($est['requiere_ayuno'], ENT_QUOTES); ?>', <?php echo $est['horas_ayuno']; ?>)"><i class="fa-solid fa-pen"></i></button>
                                    <form action="laboratorio.php" method="POST" onsubmit="return confirm('¿Eliminar estudio?');" style="margin:0;">
                                        <input type="hidden" name="accion" value="eliminar_estudio">
                                        <input type="hidden" name="id_estudio" value="<?php echo $est['id_estudio']; ?>">
                                        <button type="submit" class="btn-delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL ESTUDIO FORM -->
    <div class="modal-overlay" id="modalEstudioForm">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-book-medical"></i> Registrar / Editar Estudio</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEstudioForm()">&times;</button>
            </div>
            <form action="laboratorio.php" method="POST">
                <input type="hidden" name="accion" value="guardar_estudio">
                <input type="hidden" name="id_estudio" id="estudio_id">
                <div class="form-group"><label>Área:</label><input type="text" name="tipo_estudio" id="estudio_tipo" class="form-control" required style="text-transform: uppercase;"></div>
                <div class="form-group"><label>Estudio Clínico:</label><input type="text" name="estudio" id="estudio_nombre" class="form-control" required></div>
                <div class="form-group"><label>Abreviación:</label><input type="text" name="nombre_corto" id="estudio_corto" class="form-control" required style="text-transform: uppercase;"></div>
                <div class="form-group"><label>Muestra:</label><input type="text" name="tipo_muestra" id="estudio_muestra" class="form-control" required></div>
                <div class="form-group"><label>Ayuno:</label><input type="text" name="requiere_ayuno" id="estudio_ayuno_req" class="form-control" required></div>
                <div class="form-group"><label>Horas:</label><input type="number" name="horas_ayuno" id="estudio_horas" class="form-control" value="0" min="0"></div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalEstudioForm()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL PACIENTE RÁPIDO -->
    <div class="modal-overlay" id="modalPacienteRapido">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente</h3>
                <button type="button" class="close-modal" onclick="cerrarModalPaciente()">&times;</button>
            </div>
            <form action="laboratorio.php" method="POST">
                <input type="hidden" name="accion" value="registrar_paciente_rapido">
                <input type="hidden" name="fecha_seleccionada" id="inputFechaRegPac" value="<?php echo $fecha_seleccionada; ?>">
                
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                        <label style="margin:0;">CURP:</label>
                        <label style="font-weight: normal; font-size: 12px; cursor: pointer; color: var(--imss-dorado); display: flex; align-items: center; gap: 4px;">
                            <input type="checkbox" name="es_rn" id="chkEsRn" value="1" onchange="toggleRnMode()"> Es Recién Nacido (RN)
                        </label>
                    </div>
                    <input type="text" name="p_curp" id="inputCurpPaciente" class="form-control" placeholder="CURP a 18 caracteres..." maxlength="18" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase(); validarYAutocompletarCurp(this.value);" required>
                </div>

                <div class="form-group"><label>Nombre(s):</label><input type="text" name="p_nombre" class="form-control" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                <div class="form-group"><label>Apellido Paterno:</label><input type="text" name="p_apaterno" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                <div class="form-group"><label>Apellido Materno:</label><input type="text" name="p_amaterno" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group"><label>Fecha de Nacimiento:</label><input type="date" name="p_fnacimiento" id="inputFnac" class="form-control" required value="<?php echo $hoy_str; ?>" onchange="calcularEdadDesdeFecha(this.value)"></div>
                    <div class="form-group"><label>Edad (Años):</label><input type="number" name="p_edad" id="inputEdad" class="form-control" min="0" max="120" required></div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Sexo:</label>
                        <select name="p_sexo" id="selectSexo" class="form-control" required>
                            <option value="">Seleccione...</option>
                            <option value="H">HOMBRE (H)</option>
                            <option value="M">MUJER (M)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado de Nacimiento:</label>
                        <select name="p_estado" id="selectEstado" class="form-control" required>
                            <option value="">Seleccione estado...</option>
                            <?php foreach($estados_mexico as $est): ?>
                                <option value="<?php echo $est; ?>" <?php echo ($est === 'TABASCO') ? 'selected' : ''; ?>><?php echo $est; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group"><label>Domicilio:</label><textarea name="p_domicilio" class="form-control" rows="2" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();" required></textarea></div>
                <div class="form-group"><label>Expediente:</label><input type="text" name="p_expediente" class="form-control" value="<?php echo $expediente_aleatorio; ?>"></div>
                <div class="form-group"><label>Teléfono (10 dígitos):</label><input type="tel" name="p_telefono" class="form-control" required pattern="[0-9]{10}" maxlength="10" minlength="10"></div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalPaciente()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar Paciente</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR CITA -->
    <div class="modal-overlay" id="modalEditarCita">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Cita de Laboratorio</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditarCita()">&times;</button>
            </div>
            <form action="laboratorio.php" method="POST">
                <input type="hidden" name="accion" value="editar_cita_lab">
                <input type="hidden" name="id_cita_lab" id="edit_id_cita">
                <input type="hidden" name="fecha_seleccionada" value="<?php echo $fecha_seleccionada; ?>">
                
                <div class="form-group"><label>Paciente:</label><input type="text" id="edit_nombre_paciente" class="form-control" readonly style="background: #f1f3f5; font-weight: bold;"></div>
                <div class="form-group"><label>Fecha de la Cita:</label><input type="date" name="fecha_cita" id="edit_fecha_cita" class="form-control" required></div>
                <div class="form-group"><label>Agregar Estudios (Abreviatura + Enter):</label><input type="text" id="inputBuscaEstudioEdit" class="form-control" placeholder="Ej. GLU..." autocomplete="off" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                <div class="form-group"><label>Estudios Asignados:</label><div id="contenedorEstudiosEdit" style="min-height: 60px; border: 2px dashed var(--borde-color); border-radius: 6px; padding: 10px; background: #fafbfc; display: flex; flex-wrap: wrap; gap: 5px;"></div></div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalEditarCita()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const catalogoEstudios = <?php echo json_encode($estudios_catalogo); ?>;
        let estudiosSeleccionadosIDs = [];
        let estudiosEditIDs = [];

        // Calendario Interactivo
        let fechaActualCalendario = new Date("<?php echo $fecha_seleccionada; ?>T00:00:00");
        if (isNaN(fechaActualCalendario.getTime())) fechaActualCalendario = new Date();
        const mesesNombres = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

        function renderizarCalendario() {
            let anio = fechaActualCalendario.getFullYear(), mes = fechaActualCalendario.getMonth();
            document.getElementById('labelMesAnio').innerText = `${mesesNombres[mes]} ${anio}`;

            let primerDiaIndex = new Date(anio, mes, 1).getDay();
            primerDiaIndex = (primerDiaIndex === 0) ? 6 : primerDiaIndex - 1;

            let ultimoDiaMes = new Date(anio, mes + 1, 0).getDate();
            let ultimoDiaMesAnterior = new Date(anio, mes, 0).getDate();

            let cuerpo = document.getElementById('cuerpoCalendario');
            cuerpo.innerHTML = '';

            let fechaSeleccionadaStr = "<?php echo $fecha_seleccionada; ?>";
            let diaCounter = 1, diaSiguienteCounter = 1;

            for (let i = 0; i < 6; i++) {
                let fila = document.createElement('tr');
                let semanaValida = false;
                for (let j = 0; j < 7; j++) {
                    let celda = document.createElement('td');
                    let fechaStrCell = '';
                    if (i === 0 && j < primerDiaIndex) {
                        let dPrev = ultimoDiaMesAnterior - (primerDiaIndex - 1 - j);
                        let mPrev = mes === 0 ? 12 : mes, aPrev = mes === 0 ? anio - 1 : anio;
                        fechaStrCell = `${aPrev}-${String(mPrev).padStart(2,'0')}-${String(dPrev).padStart(2,'0')}`;
                        celda.innerText = dPrev; celda.className = 'other-month';
                    } else if (diaCounter <= ultimoDiaMes) {
                        let dCurr = diaCounter, mCurr = mes + 1;
                        fechaStrCell = `${anio}-${String(mCurr).padStart(2,'0')}-${String(dCurr).padStart(2,'0')}`;
                        celda.innerText = dCurr; semanaValida = true;
                        if (fechaStrCell === fechaSeleccionadaStr) celda.className = 'active-day';
                        celda.onclick = () => { window.location.href = `laboratorio.php?fecha_seleccionada=${fechaStrCell}`; };
                        diaCounter++;
                    } else {
                        let dNext = diaSiguienteCounter, mNext = mes === 11 ? 1 : mes + 2, aNext = mes === 11 ? anio + 1 : anio;
                        fechaStrCell = `${aNext}-${String(mNext).padStart(2,'0')}-${String(dNext).padStart(2,'0')}`;
                        celda.innerText = dNext; celda.className = 'other-month';
                        diaSiguienteCounter++;
                    }
                    fila.appendChild(celda);
                }
                if (semanaValida || i < 4) cuerpo.appendChild(fila);
            }
        }
        function mesAnterior() { fechaActualCalendario.setMonth(fechaActualCalendario.getMonth() - 1); renderizarCalendario(); }
        function mesSiguiente() { fechaActualCalendario.setMonth(fechaActualCalendario.getMonth() + 1); renderizarCalendario(); }
        renderizarCalendario();

        const curpEntidades = { "AS": "AGUASCALIENTES", "BC": "BAJA CALIFORNIA", "BS": "BAJA CALIFORNIA SUR", "CC": "CAMPECHE", "CS": "CHIAPAS", "CH": "CHIHUAHUA", "DF": "CIUDAD DE MÉXICO", "CL": "COAHUILA", "CM": "COLIMA", "DG": "DURANGO", "MC": "ESTADO DE MÉXICO", "GT": "GUANAJUATO", "GR": "GUERRERO", "HG": "HIDALGO", "JC": "JALISCO", "MN": "MICHOACÁN", "MS": "MORELOS", "NT": "NAYARIT", "NL": "NUEVO LEÓN", "OC": "OAXACA", "PL": "PUEBLA", "QT": "QUERÉTARO", "QR": "QUINTANA ROO", "SP": "SAN LUIS POTOSÍ", "SL": "SINALOA", "SR": "SONORA", "TB": "TABASCO", "TS": "TAMAULIPAS", "TL": "TLAXCALA", "VZ": "VERACRUZ", "YN": "YUCATÁN", "ZS": "ZACATECAS" };

        function validarYAutocompletarCurp(curp) {
            curp = curp.trim().toUpperCase();
            if (curp.length === 18) {
                document.querySelectorAll('#tbodyPacientesAjax tr').forEach(tr => {
                    let cCurp = tr.cells[3] ? tr.cells[3].innerText.trim() : '', rad = tr.querySelector('input[type="radio"]');
                    if (cCurp === curp && rad) { rad.checked = true; tr.style.backgroundColor = '#e4f8dd'; cerrarModalPaciente(); return; }
                });
                let yy = curp.substr(4, 2), mm = curp.substr(6, 2), dd = curp.substr(8, 2);
                let anioCompleto = ((parseInt(yy, 10) > (new Date().getFullYear() % 100)) ? "19" : "20") + yy;
                let fnacStr = `${anioCompleto}-${mm}-${dd}`;
                if (!isNaN(Date.parse(fnacStr))) { document.getElementById('inputFnac').value = fnacStr; calcularEdadDesdeFecha(fnacStr); }
                let sexoChar = curp.substr(10, 1);
                if (sexoChar === 'H' || sexoChar === 'M') document.getElementById('selectSexo').value = sexoChar;
                let ent = curp.substr(11, 2);
                if (curpEntidades[ent]) document.getElementById('selectEstado').value = curpEntidades[ent];
            }
        }
        function calcularEdadDesdeFecha(fechaStr) {
            if (!fechaStr) return;
            let fnac = new Date(fechaStr), hoy = new Date(), edad = hoy.getFullYear() - fnac.getFullYear(), m = hoy.getMonth() - fnac.getMonth();
            if (m < 0 || (m === 0 && hoy.getDate() < fnac.getDate())) edad--;
            document.getElementById('inputEdad').value = Math.max(0, edad);
        }

        function cambiarTipoFiltro() {
            let tipo = document.getElementById('selectTipoFiltro').value;
            document.getElementById('filtroDiaContainer').style.display = (tipo === 'dia') ? 'block' : 'none';
            document.getElementById('filtroRangoContainer').style.display = (tipo === 'rango') ? 'flex' : 'none';
            if (tipo !== 'dia' && tipo !== 'rango') document.getElementById('formFiltrosCitas').submit();
        }

        // Búsqueda en tiempo real (AJAX) y control de icono para borrar
        let searchTimeout = null;
        function buscarPacientesEnVivo(term) {
            const btnLimpiar = document.getElementById('btnLimpiarBusqueda');
            if (term.length > 0) {
                btnLimpiar.style.display = 'block';
            } else {
                btnLimpiar.style.display = 'none';
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                fetch(`laboratorio.php?accion=buscar_pacientes_ajax&term=` + encodeURIComponent(term))
                    .then(response => response.json())
                    .then(data => {
                        const tbody = document.getElementById('tbodyPacientesAjax');
                        tbody.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach(pac => {
                                let tr = document.createElement('tr');
                                tr.id = `row_pac_${pac.id_paciente}`;
                                tr.innerHTML = `
                                    <td style="text-align: center;">
                                        <input type="radio" name="id_paciente" value="${pac.id_paciente}" style="cursor: pointer; width: 16px; height: 16px;" required>
                                    </td>
                                    <td><strong>${pac.expediente || 'S/N'}</strong></td>
                                    <td>${pac.nombre} ${pac.apaterno} ${pac.amaterno}</td>
                                    <td>${pac.curp || 'SIN CURP (RN)'}</td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #637381; padding: 15px;">No se encontraron pacientes.</td></tr>';
                        }
                    })
                    .catch(error => console.error('Error en búsqueda:', error));
            }, 300);
        }

        function limpiarBusquedaPaciente() {
            const input = document.getElementById('inputFiltroPac');
            input.value = '';
            document.getElementById('btnLimpiarBusqueda').style.display = 'none';
            buscarPacientesEnVivo('');
            input.focus();
        }

        function toggleRnMode() {
            const chk = document.getElementById('chkEsRn'), inputCurp = document.getElementById('inputCurpPaciente');
            if (chk.checked) { inputCurp.value = ''; inputCurp.removeAttribute('required'); } else { inputCurp.setAttribute('required', 'true'); }
        }

        document.getElementById('inputBuscaEstudio').addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); agregarEstudioPorInput(); } });
        function agregarEstudioPorInput() {
            let val = document.getElementById('inputBuscaEstudio').value.trim().toUpperCase();
            if (!val) return;
            let est = catalogoEstudios.find(e => e.nombre_corto.toUpperCase() === val || e.estudio.toUpperCase().includes(val));
            if (est) {
                if (estudiosSeleccionadosIDs.includes(est.id_estudio)) return;
                estudiosSeleccionadosIDs.push(est.id_estudio);
                renderizarChipsEstudios();
                document.getElementById('inputBuscaEstudio').value = '';
            } else { alert('Estudio no encontrado.'); }
        }
        function removerEstudio(id) { estudiosSeleccionadosIDs = estudiosSeleccionadosIDs.filter(i => i !== id); renderizarChipsEstudios(); }
        function renderizarChipsEstudios() {
            const cont = document.getElementById('contenedorEstudiosSeleccionados');
            cont.innerHTML = '';
            if (estudiosSeleccionadosIDs.length === 0) { cont.innerHTML = '<span style="color: #919eab; font-size: 13px; margin: auto;">Ningún estudio agregado todavía.</span>'; return; }
            estudiosSeleccionadosIDs.forEach(id => {
                let est = catalogoEstudios.find(e => e.id_estudio == id);
                if (est) {
                    let chip = document.createElement('div');
                    chip.className = 'estudio-chip';
                    chip.innerHTML = `<span>[${est.nombre_corto}] ${est.estudio}</span><input type="hidden" name="estudios[]" value="${est.id_estudio}"><button type="button" onclick="removerEstudio(${est.id_estudio})">&times;</button>`;
                    cont.appendChild(chip);
                }
            });
        }

        document.getElementById('inputBuscaEstudioEdit').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                let q = this.value.trim().toUpperCase();
                let est = catalogoEstudios.find(el => el.nombre_corto.toUpperCase() === q || el.estudio.toUpperCase().includes(q));
                if (est) {
                    if (estudiosEditIDs.includes(est.id_estudio)) return;
                    estudiosEditIDs.push(est.id_estudio);
                    renderizarChipsEdit();
                    this.value = '';
                }
            }
        });
        function removerEstudioEdit(id) { estudiosEditIDs = estudiosEditIDs.filter(i => i !== id); renderizarChipsEdit(); }
        function renderizarChipsEdit() {
            const cont = document.getElementById('contenedorEstudiosEdit');
            cont.innerHTML = '';
            estudiosEditIDs.forEach(id => {
                let est = catalogoEstudios.find(e => e.id_estudio == id);
                if (est) {
                    let chip = document.createElement('div');
                    chip.className = 'estudio-chip';
                    chip.innerHTML = `<span>[${est.nombre_corto}] ${est.estudio}</span><input type="hidden" name="estudios[]" value="${est.id_estudio}"><button type="button" onclick="removerEstudioEdit(${est.id_estudio})">&times;</button>`;
                    cont.appendChild(chip);
                }
            });
        }

        function abrirModalEditarCita(idCita, nombrePac, fechaCita, arrEst) {
            document.getElementById('edit_id_cita').value = idCita;
            document.getElementById('edit_nombre_paciente').value = nombrePac;
            document.getElementById('edit_fecha_cita').value = fechaCita;
            estudiosEditIDs = arrEst.map(Number);
            renderizarChipsEdit();
            document.getElementById('modalEditarCita').style.display = 'flex';
        }
        function cerrarModalEditarCita() { document.getElementById('modalEditarCita').style.display = 'none'; }

        function abrirModalConfig() { document.getElementById('modalConfig').style.display = 'flex'; }
        function cerrarModalConfig() { document.getElementById('modalConfig').style.display = 'none'; }
        function abrirModalCatalogo() { document.getElementById('modalCatalogo').style.display = 'flex'; }
        function cerrarModalCatalogo() { document.getElementById('modalCatalogo').style.display = 'none'; }
        function abrirModalEstudioForm() { document.getElementById('modalEstudioForm').style.display = 'flex'; }
        function cerrarModalEstudioForm() { document.getElementById('modalEstudioForm').style.display = 'none'; }
        function abrirModalPaciente() { document.getElementById('modalPacienteRapido').style.display = 'flex'; }
        function cerrarModalPaciente() { document.getElementById('modalPacienteRapido').style.display = 'none'; }

        <?php if (!empty($paciente_seleccionado_id)): ?>
            window.addEventListener('DOMContentLoaded', () => {
                let radioSel = document.querySelector('input[name="id_paciente"][value="<?php echo $paciente_seleccionado_id; ?>"]');
                if (radioSel) {
                    radioSel.checked = true;
                    let rowTr = document.getElementById('row_pac_<?php echo $paciente_seleccionado_id; ?>');
                    if (rowTr) { rowTr.style.backgroundColor = '#e4f8dd'; rowTr.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>