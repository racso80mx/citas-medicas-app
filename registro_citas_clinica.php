<?php
// ===================================================================
// 1. CONFIGURACIÓN DE ERRORES, SESIÓN Y SEGURIDAD ESTRICTA
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (ob_get_length()) {
    ob_clean();
}
ob_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

// ===================================================================
// 1.1 ENDPOINT AJAX INTERNO: HORARIOS OCUPADOS, FESTIVOS, SIS Y METADATOS
// ===================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'obtener_ocupados') {
    header('Content-Type: application/json');
    $clinica_id = $_GET['clinica_id'] ?? '';
    $fecha_cita = $_GET['fecha_cita'] ?? '';
    $id_cita_excluir = $_GET['id_cita_excluir'] ?? '';

    if (empty($clinica_id) || empty($fecha_cita)) {
        echo json_encode(['ocupadas' => [], 'cerrado' => false, 'motivo' => '']);
        exit();
    }

    try {
        $stmt_cli = $pdo->prepare("SELECT * FROM clinicas WHERE id_clinica = :id LIMIT 1");
        $stmt_cli->execute(['id' => $clinica_id]);
        $row_cli = $stmt_cli->fetch(PDO::FETCH_ASSOC);

        if (!$row_cli) {
            echo json_encode(['ocupadas' => [], 'cerrado' => false, 'motivo' => 'Clínica no encontrada']);
            exit();
        }

        $nombre_clinica = $row_cli['nombre_clinica'] ?? '';
        $dia_inactivo = trim($row_cli['dia_inactivo'] ?? '');
        $fin_semana = strtoupper(trim($row_cli['fin_semana'] ?? 'FALSE'));

        // 1. Validar Día Inactivo
        $dia_semana_num = date('N', strtotime($fecha_cita)); // 1 (Lunes) a 7 (Domingo)
        $dias_map = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $nombre_dia_actual = $dias_map[$dia_semana_num] ?? '';

        if (!empty($dia_inactivo) && strcasecmp($nombre_dia_actual, $dia_inactivo) === 0) {
            echo json_encode(['ocupadas' => [], 'cerrado' => true, 'motivo' => "Clínica cerrada ($dia_inactivo inactivo)"]);
            exit();
        }

        // 2. Validar Fin de Semana
        $es_fin_de_semana = ($dia_semana_num == 6 || $dia_semana_num == 7);
        $atiende_sabados_domingos = (in_array($fin_semana, ['TRUE', '1', 'YES', 'SI', 'SÍ', 'ON']));

        // Comprobar si hay un Festivo General que habilite los fines de semana
        $festivo_habilita_fin = false;
        try {
            $stmtF_gen = $pdo->prepare("SELECT tipo_festivo FROM festivos WHERE fecha = :f LIMIT 1");
            $stmtF_gen->execute(['f' => $fecha_cita]);
            $festivo_tipo = $stmtF_gen->fetchColumn();
            if ($festivo_tipo === 'general') {
                $festivo_habilita_fin = true;
            }
        } catch (Exception $ex) {}

        if ($es_fin_de_semana && !$atiende_sabados_domingos && !$festivo_habilita_fin) {
            echo json_encode(['ocupadas' => [], 'cerrado' => true, 'motivo' => 'Clínica cerrada (Fin de semana)']);
            exit();
        }

        // 3. Validar Catálogo de Días Festivos / Cierres SIS / Suspensiones
        try {
            $stmtF = $pdo->prepare("SELECT descripcion, tipo_festivo, clinicas_afectadas FROM festivos WHERE fecha = :f LIMIT 1");
            $stmtF->execute(['f' => $fecha_cita]);
            $festivo_row = $stmtF->fetch(PDO::FETCH_ASSOC);

            if ($festivo_row) {
                $t_festivo = $festivo_row['tipo_festivo'] ?? 'general';
                $c_afectadas = $festivo_row['clinicas_afectadas'] ?? '';
                $motivo_festivo = $festivo_row['descripcion'] ?? 'Día Inhábil';

                if ($t_festivo === 'general') {
                    if (!$es_fin_de_semana) {
                        echo json_encode(['ocupadas' => [], 'cerrado' => true, 'motivo' => "Suspensión General: $motivo_festivo"]);
                        exit();
                    }
                } elseif ($t_festivo === 'general_laboral') {
                    echo json_encode(['ocupadas' => [], 'cerrado' => true, 'motivo' => "Suspensión General: $motivo_festivo"]);
                    exit();
                } elseif ($t_festivo === 'administrativo_nucleos') {
                    if (!empty($c_afectadas)) {
                        $lista_afectadas = array_map('trim', explode(',', $c_afectadas));
                        if (in_array($nombre_clinica, $lista_afectadas)) {
                            echo json_encode(['ocupadas' => [], 'cerrado' => true, 'motivo' => "Cierre por Entrega de SIS: $motivo_festivo"]);
                            exit();
                        }
                    }
                }
            }
        } catch (Exception $ex) {}

        // 4. Validar Excepciones de Clínica
        try {
            $stmtE = $pdo->prepare("SELECT motivo FROM clinica_excepciones WHERE fecha = :f AND (nombre_clinica = :c OR clinica = :c)");
            $stmtE->execute(['f' => $fecha_cita, 'c' => $nombre_clinica]);
            if ($excepcion = $stmtE->fetchColumn()) {
                echo json_encode(['ocupadas' => [], 'cerrado' => true, 'motivo' => "Clínica No Disponible: $excepcion"]);
                exit();
            }
        } catch (Exception $ex) {}

        // 5. Consultar Horas Ocupadas
        $sql = "SELECT hora_cita FROM citas WHERE nombre_clinica = :nombre_clinica AND fecha_cita = :fecha_cita AND (estatus != 'CANCELADA' OR estatus IS NULL)";
        $params = ['nombre_clinica' => $nombre_clinica, 'fecha_cita' => $fecha_cita];

        if (!empty($id_cita_excluir)) {
            $sql .= " AND id_cita <> :id_cita_excluir";
            $params['id_cita_excluir'] = $id_cita_excluir;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ocupadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $horas_ocupadas = array_map(function($h) {
            return substr($h, 0, 5);
        }, $ocupadas);

        echo json_encode(['ocupadas' => $horas_ocupadas, 'cerrado' => false, 'motivo' => '']);
    } catch (Exception $e) {
        echo json_encode(['ocupadas' => [], 'cerrado' => false, 'motivo' => 'Error en el servidor']);
    }
    exit();
}

// ===================================================================
// 2. CAPTURA DE PARÁMETROS DE FILTRO, ORDENAMIENTO Y LÍMITE (GET)
// ===================================================================
$f_inicio     = $_GET['fecha_inicio'] ?? '';
$f_fin        = $_GET['fecha_fin'] ?? '';
$filtro_fecha = $_GET['filtro_fecha'] ?? '';
$limit        = $_GET['limit'] ?? '50';
$filtro_tipo  = $_GET['tipo_clinica'] ?? '';
$filtro_clin  = $_GET['nombre_clinica'] ?? '';
$sort         = $_GET['sort'] ?? 'id_cita';
$order        = strtoupper($_GET['order'] ?? 'DESC');
$accion       = $_GET['accion'] ?? '';

if (!empty($filtro_fecha)) {
    $hoy = date('Y-m-d');
    switch ($filtro_fecha) {
        case 'hoy':
            $f_inicio = $hoy;
            $f_fin    = $hoy;
            break;
        case 'manana':
            $f_inicio = date('Y-m-d', strtotime('+1 day'));
            $f_fin    = date('Y-m-d', strtotime('+1 day'));
            break;
        case 'semana':
            $f_inicio = date('Y-m-d', strtotime('monday this week'));
            $f_fin    = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'mes':
            $f_inicio = date('Y-m-01');
            $f_fin    = date('Y-m-t');
            break;
        case 'fecha_especifica':
            $f_fin = $f_inicio; 
            break;
        case 'rango':
            break;
    }
}

$columnas_permitidas = ['id_cita', 'no_expediente', 'nombre_paciente', 'curp', 'fecha_cita', 'hora_cita', 'tipo_clinica', 'nombre_clinica', 'telefono', 'estatus'];
if (!in_array($sort, $columnas_permitidas)) {
    $sort = 'id_cita';
}
if ($order !== 'ASC' && $order !== 'DESC') {
    $order = 'DESC';
}

// ===================================================================
// 3. CONSTRUCCIÓN DE LA CONSULTA PRINCIPAL DE CITAS
// ===================================================================
$where = "WHERE 1=1";
$params = [];

if (!empty($f_inicio) && !empty($f_fin)) {
    $where .= " AND c.fecha_cita BETWEEN :f_inicio AND :f_fin";
    $params['f_inicio'] = $f_inicio;
    $params['f_fin']    = $f_fin;
} elseif (!empty($f_inicio)) {
    $where .= " AND c.fecha_cita = :f_inicio";
    $params['f_inicio'] = $f_inicio;
}

if (!empty($filtro_tipo)) {
    $where .= " AND c.tipo_clinica = :filtro_tipo";
    $params['filtro_tipo'] = $filtro_tipo;
}

if (!empty($filtro_clin)) {
    $where .= " AND c.nombre_clinica = :filtro_clin";
    $params['filtro_clin'] = $filtro_clin;
}

// ===================================================================
// 4. PROCESAR EXPORTACIONES (EXCEL O PDF)
// ===================================================================
if ($accion === 'exportar_excel') {
    if (ob_get_length()) ob_clean();
    try {
        $stmt_exp = $pdo->prepare("SELECT c.* FROM citas c $where ORDER BY c.$sort $order");
        $stmt_exp->execute($params);
        $data_excel = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=reporte_citas_" . date('Ymd_His') . ".xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        echo "<table border='1'>";
        echo "<tr style='background-color:#006847; color:#ffffff; font-weight:bold;'>
                <th>ID</th><th>Expediente</th><th>Paciente</th><th>CURP</th><th>Fecha Cita</th><th>Hora</th><th>Tipo Clínica</th><th>Clínica</th><th>Teléfono</th><th>Tipo Paciente</th><th>Estatus</th>
              </tr>";
        foreach ($data_excel as $r) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($r['id_cita'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['no_expediente'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['nombre_paciente'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['curp'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['fecha_cita'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['hora_cita'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['tipo_clinica'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['nombre_clinica'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['telefono'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['tipo_paciente'] ?? 'General') . "</td>";
            echo "<td>" . htmlspecialchars($r['estatus'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "Error al exportar Excel: " . $e->getMessage();
    }
    exit();
}

if ($accion === 'exportar_pacientes_clinica_pdf') {
    if (ob_get_length()) ob_clean();
    try {
        $sql_pdf = "
            SELECT c.*, p.colonia 
            FROM citas c 
            LEFT JOIN pacientes p ON c.no_expediente = p.expediente 
            $where 
            ORDER BY c.$sort $order
        ";
        $stmt_pdf = $pdo->prepare($sql_pdf);
        $stmt_pdf->execute($params);
        $lista_pacientes_pdf = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);

        $titulo_reporte = !empty($filtro_clin) ? $filtro_clin : (!empty($filtro_tipo) ? $filtro_tipo : "Reporte General de Citas");
    } catch (Exception $e) {
        $lista_pacientes_pdf = [];
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Listado de Pacientes - Hospital General de Huimanguillo</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { background-color: #555; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; }
            .sheet { width: 210mm; min-height: 297mm; background: white; margin: 0 auto; padding: 15mm; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; justify-content: space-between; }
            .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 12px; margin-bottom: 15px; }
            .header-container img { max-height: 65px; }
            .header-title { text-align: right; color: #006847; font-size: 20px; font-weight: bold; line-height: 1.2; }
            .banner-verde { background-color: #006847; color: white; text-align: center; font-size: 16px; font-weight: bold; padding: 6px; margin-bottom: 15px; letter-spacing: 1px; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 20px; }
            th { background-color: #f1f3f5; color: #333; padding: 8px 6px; border: 1px solid #dfe3e8; text-align: left; white-space: nowrap; }
            td { padding: 7px 6px; border: 1px solid #dfe3e8; vertical-align: middle; }
            tr:nth-child(even) { background-color: #fafbfc; }
            .firmas-container { display: flex; justify-content: space-between; margin-top: 40px; padding: 0 30px; page-break-inside: avoid; }
            .firma-box { width: 40%; text-align: center; border-top: 1px solid #333; padding-top: 8px; font-size: 13px; font-weight: bold; color: #333; }
            .firma-box span { display: block; font-weight: normal; font-size: 11px; color: #637381; margin-top: 3px; }
            .no-print { text-align: center; margin-top: 20px; }
            .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 15px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
            @media print { body { background: none; padding: 0; } .sheet { box-shadow: none; padding: 10mm; width: 100%; margin: 0; min-height: auto; } .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="sheet">
            <div>
                <div class="header-container">
                    <img src="logo_hospital.png" alt="Logo Hospital General de Huimanguillo" onerror="this.style.display='none'">
                    <div class="header-title">
                        HOSPITAL GENERAL DE HUIMANGUILLO<br><span style="font-size: 13px; color: #637381; font-weight: normal;">DR. ADELFO S. AGUIRRE</span>
                    </div>
                </div>
                <div class="banner-verde">LISTADO DE PACIENTES - <?php echo htmlspecialchars($titulo_reporte); ?></div>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha Cita</th>
                            <th>Hora</th>
                            <th>Expediente</th>
                            <th>Paciente</th>
                            <th>CURP</th>
                            <th>Teléfono</th>
                            <th>Clínica</th>
                            <th>Colonia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($lista_pacientes_pdf)): ?>
                            <?php foreach ($lista_pacientes_pdf as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['fecha_cita'] ?? ''); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($row['hora_cita'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['no_expediente'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['nombre_paciente'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['curp'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['telefono'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['nombre_clinica'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['colonia'] ?? 'S/N'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; padding: 20px; color: #637381;">No se encontraron registros de citas con los filtros seleccionados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <div class="firmas-container">
                    <div class="firma-box">Responsable del Servicio<span>Nombre del Médico / Responsable</span></div>
                    <div class="firma-box">Firma Autorizada<span>Firma de quien entrega</span></div>
                </div>
                <div class="no-print">
                    <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir / Guardar PDF</button>
                    <a href="registro_citas_clinica.php" class="btn" style="background-color: #637381; margin-left: 10px;"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ===================================================================
// 5. CONSULTA PRINCIPAL Y CARGA DE CATÁLOGOS DESDE LA BASE DE DATOS
// ===================================================================
try {
    $sql_main = "SELECT c.* FROM citas c $where ORDER BY c.$sort $order";
    if ($limit !== 'all') {
        $lim_val = (int)$limit;
        if ($lim_val <= 0) $lim_val = 50;
        $sql_main .= " LIMIT " . $lim_val;
    }

    $stmt = $pdo->prepare($sql_main);
    $stmt->execute($params);
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clinicas_lista = $pdo->query("SELECT * FROM clinicas ORDER BY nombre_clinica ASC")->fetchAll(PDO::FETCH_ASSOC);

    $clinicas_por_nombre = [];
    foreach ($clinicas_lista as $cli) {
        $clinicas_por_nombre[$cli['nombre_clinica']] = $cli;
    }

    $tipos_consulta = [];
    foreach ($clinicas_lista as $cli_item) {
        $t_val = trim($cli_item['tipo_clinica'] ?? '');
        if (!empty($t_val) && !in_array($t_val, $tipos_consulta)) {
            $tipos_consulta[] = $t_val;
        }
    }
    sort($tipos_consulta);

    $stmt_pacientes_modal = $pdo->query("SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp, telefono FROM pacientes ORDER BY nombre ASC");
    $lista_pacientes_modal = $stmt_pacientes_modal->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $citas = [];
    $tipos_consulta = [];
    $clinicas_lista = [];
    $clinicas_por_nombre = [];
    $lista_pacientes_modal = [];
}

function urlOrden($columna) {
    $params = $_GET;
    $params['sort'] = $columna;
    $params['order'] = (isset($_GET['sort']) && $_GET['sort'] === $columna && isset($_GET['order']) && $_GET['order'] === 'ASC') ? 'DESC' : 'ASC';
    return 'registro_citas_clinica.php?' . http_build_query($params);
}

$query_params = $_GET;
$query_params['accion'] = 'exportar_excel';
$url_excel = 'registro_citas_clinica.php?' . http_build_query($query_params);

$query_params['accion'] = 'exportar_pacientes_clinica_pdf';
$url_pdf = 'registro_citas_clinica.php?' . http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Registro de Citas de Clínica</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --excel-verde: #217346; --btn-azul: #1976d2; --btn-naranja: #f57c00; --btn-rojo: #d32f2f;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .container { max-width: 98%; margin: 25px auto; padding: 0 10px; }
        
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }
        .panel-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #637381; }
        .btn:hover { opacity: 0.9; }

        .filters-form { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
        .filters-form input[type="date"], .filters-form select { padding: 7px 10px; border: 1px solid var(--borde); border-radius: 4px; outline: none; font-size: 13px; background: #fff; }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); color: #637381; white-space: nowrap; }
        th a { color: #637381; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        th a:hover { color: var(--imss-verde); }
        td { padding: 10px; border-bottom: 1px solid var(--borde); white-space: nowrap; vertical-align: middle; }
        tr:hover { background-color: #f8f9fa; }
        .acciones-col { text-align: center; white-space: nowrap; }

        .btn-icon { width: 32px; height: 32px; border: none; border-radius: 6px; cursor: pointer; color: white; display: inline-flex; justify-content: center; align-items: center; margin: 0 2px; text-decoration: none; }
        .btn-blue { background-color: var(--btn-azul); }
        .btn-orange { background-color: var(--btn-naranja); }
        .btn-red { background-color: var(--btn-rojo); }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 480px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); overflow: hidden; }
        .modal-header { background: var(--imss-verde); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 16px; }
        .close-btn { background: none; border: none; color: white; font-size: 18px; cursor: pointer; }
        .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 12px; color: #637381; }
        .form-group input, .form-group select { width: 100%; padding: 8px 10px; box-sizing: border-box; border: 1px solid var(--borde); border-radius: 4px; font-size: 13px; }
        .modal-footer { padding: 15px 20px; background: #f1f3f5; text-align: right; border-top: 1px solid var(--borde); }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-calendar-check"></i> <strong>Gestión de Citas de Clínica</strong></div>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver al Dashboard</a>
    </div>

    <div class="container">
        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalAsignarCitaDirecta()" style="font-weight: bold;">
                        <i class="fa-solid fa-calendar-plus"></i> Asignar Nueva Cita
                    </button>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo htmlspecialchars($url_pdf); ?>" target="_blank" class="btn btn-primary" title="Generar PDF con listado filtrado">
                        <i class="fa-solid fa-file-pdf"></i> Descargar Listado (PDF)
                    </a>
                    <a href="<?php echo htmlspecialchars($url_excel); ?>" class="btn btn-excel" title="Exportar citas filtradas a Excel">
                        <i class="fa-solid fa-file-download"></i> Exportar Citas a Excel
                    </a>
                </div>
            </div>

            <form id="filterForm" method="GET" class="panel-row filters-form" style="border-top: 1px solid var(--borde); padding-top: 12px;">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <input type="hidden" name="order" value="<?php echo htmlspecialchars($order); ?>">

                <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 11px; color: #637381;">Tipo Consulta:</label>
                        <select name="tipo_clinica" id="filtroTipo" onchange="filtrarClinicasFiltro(); submitFiltros();">
                            <option value="">-- Todos los Tipos --</option>
                            <?php foreach ($tipos_consulta as $tc): ?>
                                <option value="<?php echo htmlspecialchars($tc); ?>" <?php echo ($filtro_tipo === $tc) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tc); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 11px; color: #637381;">Clínica:</label>
                        <select name="nombre_clinica" id="filtroClinica" onchange="submitFiltros()">
                            <option value="">-- Todas las Clínicas --</option>
                            <?php foreach ($clinicas_lista as $cl): ?>
                                <option value="<?php echo htmlspecialchars($cl['nombre_clinica']); ?>" data-tipo="<?php echo htmlspecialchars($cl['tipo_clinica'] ?? ''); ?>" <?php echo ($filtro_clin === $cl['nombre_clinica']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cl['nombre_clinica']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 11px; color: #637381;">Filtrar por Fecha:</label>
                        <select name="filtro_fecha" id="filtroFechaSelect" onchange="manejarCambioFiltroFecha()">
                            <option value="">-- Seleccionar Periodo --</option>
                            <option value="hoy" <?php echo ($filtro_fecha === 'hoy') ? 'selected' : ''; ?>>Hoy</option>
                            <option value="manana" <?php echo ($filtro_fecha === 'manana') ? 'selected' : ''; ?>>Mañana</option>
                            <option value="semana" <?php echo ($filtro_fecha === 'semana') ? 'selected' : ''; ?>>Esta Semana</option>
                            <option value="mes" <?php echo ($filtro_fecha === 'mes') ? 'selected' : ''; ?>>Este Mes</option>
                            <option value="fecha_especifica" <?php echo ($filtro_fecha === 'fecha_especifica') ? 'selected' : ''; ?>>Fecha Específica</option>
                            <option value="rango" <?php echo ($filtro_fecha === 'rango') ? 'selected' : ''; ?>>Rango de Fechas</option>
                        </select>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px; display: none;" id="divFechaInicio">
                        <label style="font-weight: bold; font-size: 11px; color: #637381;" id="lblFechaInicio">Fecha:</label>
                        <input type="date" name="fecha_inicio" id="fechaInicio" value="<?php echo htmlspecialchars($f_inicio); ?>" onchange="submitFiltros()">
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px; display: none;" id="divFechaFin">
                        <label style="font-weight: bold; font-size: 11px; color: #637381;">Hasta:</label>
                        <input type="date" name="fecha_fin" id="fechaFin" value="<?php echo htmlspecialchars($f_fin); ?>" onchange="submitFiltros()">
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 11px; color: #637381;">Mostrar:</label>
                        <select name="limit" onchange="submitFiltros()">
                            <option value="25" <?php echo ($limit == 25) ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo ($limit == 50) ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo ($limit == 100) ? 'selected' : ''; ?>>100</option>
                            <option value="500" <?php echo ($limit == 500) ? 'selected' : ''; ?>>500</option>
                            <option value="all" <?php echo ($limit === 'all') ? 'selected' : ''; ?>>Todos</option>
                        </select>
                    </div>
                </div>

                <div>
                    <a href="registro_citas_clinica.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-right"></i> Limpiar Filtros</a>
                </div>
            </form>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><a href="<?php echo urlOrden('id_cita'); ?>">ID <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('no_expediente'); ?>">Expediente <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('nombre_paciente'); ?>">Paciente <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('curp'); ?>">CURP <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('fecha_cita'); ?>">Fecha Cita <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('hora_cita'); ?>">Hora <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('tipo_clinica'); ?>">Tipo Consulta <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('nombre_clinica'); ?>">Clínica <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('telefono'); ?>">Teléfono <i class="fa-solid fa-sort"></i></a></th>
                        <th><a href="<?php echo urlOrden('estatus'); ?>">Estatus <i class="fa-solid fa-sort"></i></a></th>
                        <th class="acciones-col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($citas) > 0): ?>
                        <?php foreach ($citas as $c): 
                            $nombre_cli_actual = $c['nombre_clinica'] ?? '';
                            $clinica_info = $clinicas_por_nombre[$nombre_cli_actual] ?? [];
                            $c_id_clinica = $clinica_info['id_clinica'] ?? '';
                            $c_hora_inicio = $clinica_info['hora_inicio'] ?? '07:00';
                            $c_hora_fin = $clinica_info['hora_fin'] ?? '14:00';
                            $c_duracion = intval($clinica_info['duracion_consulta'] ?? $clinica_info['duracion'] ?? 30);
                            $c_comida = $clinica_info['hora_comida'] ?? $clinica_info['comida'] ?? '';
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['id_cita'] ?? ''); ?></td>
                                <td><strong><?php echo htmlspecialchars($c['no_expediente'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['nombre_paciente'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['curp'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['fecha_cita'] ?? ''); ?></td>
                                <td><span style="background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-weight: bold;"><?php echo htmlspecialchars($c['hora_cita'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($c['tipo_clinica'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($nombre_cli_actual); ?></td>
                                <td><?php echo htmlspecialchars($c['telefono'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['estatus'] ?? ''); ?></td>
                                <td class="acciones-col">
                                    <a href="procesar_cita.php?id_cita=<?php echo $c['id_cita']; ?>" target="_blank" class="btn-icon btn-blue" title="Ver Comprobante PDF">
                                        <i class="fa-solid fa-file-pdf"></i>
                                    </a>
                                    <button class="btn-icon btn-orange" onclick="abrirModalEditar(<?php echo $c['id_cita']; ?>, '<?php echo htmlspecialchars($c['fecha_cita'] ?? ''); ?>', '<?php echo htmlspecialchars($c['hora_cita'] ?? ''); ?>', '<?php echo $c_id_clinica; ?>', '<?php echo $c_duracion; ?>', '<?php echo $c_hora_inicio; ?>', '<?php echo $c_hora_fin; ?>', '<?php echo $c_comida; ?>')" title="Modificar Fecha y Hora">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button class="btn-icon btn-primary" onclick="abrirModalNuevaCitaPrellenada('<?php echo htmlspecialchars($c['id_paciente'] ?? ''); ?>', '<?php echo htmlspecialchars($c['tipo_clinica'] ?? ''); ?>', '<?php echo htmlspecialchars($nombre_cli_actual); ?>')" title="Asignar Nueva Cita">
                                        <i class="fa-solid fa-calendar-plus"></i>
                                    </button>
                                    <button class="btn-icon btn-red" onclick="eliminarCita(<?php echo $c['id_cita']; ?>)" title="Eliminar Cita">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" style="text-align: center; color: #637381; padding: 30px;">No se encontraron registros de citas con los filtros especificados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Editar Cita -->
    <div id="modalEditarCita" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-calendar-pen"></i> Modificar Cita Médica</h3>
                <button class="close-btn" onclick="cerrarModalEditar()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="actualizar_cita_medico.php" method="POST">
                <input type="hidden" name="tipo_clinica" value="<?php echo htmlspecialchars($filtro_tipo); ?>">
                <input type="hidden" name="nombre_clinica" value="<?php echo htmlspecialchars($filtro_clin); ?>">
                <input type="hidden" name="fecha_inicio" value="<?php echo htmlspecialchars($f_inicio); ?>">
                <input type="hidden" name="fecha_fin" value="<?php echo htmlspecialchars($f_fin); ?>">
                <input type="hidden" name="filtro_fecha" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                <input type="hidden" name="limit" value="<?php echo htmlspecialchars($limit); ?>">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <input type="hidden" name="order" value="<?php echo htmlspecialchars($order); ?>">

                <div class="modal-body">
                    <input type="hidden" id="edit_id_cita" name="id_cita">
                    <input type="hidden" id="edit_clinica_id" name="clinica_id">
                    <input type="hidden" id="edit_duracion" value="30">
                    <input type="hidden" id="edit_hora_inicio" value="07:00">
                    <input type="hidden" id="edit_hora_fin" value="14:00">
                    <input type="hidden" id="edit_hora_comida" value="">

                    <div class="form-group">
                        <label>Fecha de la Cita:</label>
                        <input type="date" id="edit_fecha_cita" name="fecha_cita" required onchange="generarHorariosEdicionDinamicos()">
                    </div>
                    <div class="form-group">
                        <label>Hora de la Cita:</label>
                        <select id="edit_hora_cita" name="hora_cita" required>
                            <option value="">Seleccione hora...</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Asignar Nueva Cita -->
    <div id="modalNuevaCita" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-calendar-plus"></i> Asignar Nueva Cita</h3>
                <button class="close-btn" onclick="cerrarModalNuevaCita()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="procesar_cita.php" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Seleccionar Paciente:</label>
                        <select name="paciente_id" required>
                            <option value="">Seleccione paciente...</option>
                            <?php foreach ($lista_pacientes_modal as $pac): ?>
                                <option value="<?php echo $pac['id_paciente']; ?>">
                                    <?php echo htmlspecialchars($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno'] . ' (Exp: ' . $pac['expediente'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Tipo de Consulta:</label>
                        <select name="tipo_clinica" id="modalTipoConsulta" onchange="filtrarClinicasModal()" required>
                            <option value="">Seleccione tipo de consulta...</option>
                            <?php foreach ($tipos_consulta as $tc): ?>
                                <option value="<?php echo htmlspecialchars($tc); ?>">
                                    <?php echo htmlspecialchars($tc); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Clínica u Hospital:</label>
                        <select name="clinica_id" id="modalClinica" onchange="generarHorariosDinamicos()" required>
                            <option value="">Seleccione primero el tipo de consulta...</option>
                            <?php foreach ($clinicas_lista as $cli): ?>
                                <option value="<?php echo $cli['id_clinica']; ?>" 
                                        data-nombre="<?php echo htmlspecialchars($cli['nombre_clinica'] ?? ''); ?>"
                                        data-tipo="<?php echo htmlspecialchars($cli['tipo_clinica'] ?? ''); ?>"
                                        data-inicio="<?php echo htmlspecialchars($cli['hora_inicio'] ?? '07:00'); ?>"
                                        data-fin="<?php echo htmlspecialchars($cli['hora_fin'] ?? '14:00'); ?>"
                                        data-duracion="<?php echo intval($cli['duracion_consulta'] ?? $cli['duracion'] ?? 30); ?>"
                                        data-comida="<?php echo htmlspecialchars($cli['hora_comida'] ?? $cli['comida'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($cli['nombre_clinica']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Fecha de la Cita:</label>
                        <input type="date" name="fecha_cita" id="modalFechaCita" required value="<?php echo date('Y-m-d'); ?>" onchange="generarHorariosDinamicos()">
                    </div>

                    <div class="form-group">
                        <label>Hora de la Cita:</label>
                        <select name="hora_cita" id="modalHoraCita" required>
                            <option value="">Seleccione clínica primero...</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalNuevaCita()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Generar Cita y Comprobante</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function manejarCambioFiltroFecha() {
            const selectVal = document.getElementById('filtroFechaSelect').value;
            const divInicio = document.getElementById('divFechaInicio');
            const divFin = document.getElementById('divFechaFin');
            const lblInicio = document.getElementById('lblFechaInicio');

            if (selectVal === 'fecha_especifica') {
                divInicio.style.display = 'flex';
                divFin.style.display = 'none';
                lblInicio.textContent = 'Fecha Específica:';
                document.getElementById('fechaFin').value = '';
            } else if (selectVal === 'rango') {
                divInicio.style.display = 'flex';
                divFin.style.display = 'flex';
                lblInicio.textContent = 'Desde:';
            } else {
                divInicio.style.display = 'none';
                divFin.style.display = 'none';
                document.getElementById('fechaInicio').value = '';
                document.getElementById('fechaFin').value = '';
                submitFiltros();
            }
        }

        function filtrarClinicasFiltro() {
            const tipoSelect = document.getElementById('filtroTipo');
            const clinicaSelect = document.getElementById('filtroClinica');
            const tipoSeleccionado = tipoSelect.value;

            for (let option of clinicaSelect.options) {
                if (option.value === "") continue;
                let tipoClinica = option.getAttribute('data-tipo');
                if (!tipoSeleccionado || tipoClinica === tipoSeleccionado) {
                    option.style.display = "";
                } else {
                    option.style.display = "none";
                }
            }

            let optionSeleccionada = clinicaSelect.options[clinicaSelect.selectedIndex];
            if (optionSeleccionada && optionSeleccionada.style.display === "none") {
                clinicaSelect.value = "";
            }
        }

        function filtrarClinicasModal(preservarClinica = '') {
            const tipoSelect = document.getElementById('modalTipoConsulta');
            const clinicaSelect = document.getElementById('modalClinica');
            const tipoSeleccionado = tipoSelect.value;

            if (!preservarClinica) {
                clinicaSelect.value = "";
                document.getElementById('modalHoraCita').innerHTML = '<option value="">Seleccione clínica primero...</option>';
            }

            for (let option of clinicaSelect.options) {
                if (option.value === "") continue;
                let tipoClinica = option.getAttribute('data-tipo');
                if (!tipoSeleccionado || tipoClinica === tipoSeleccionado) {
                    option.style.display = "";
                } else {
                    option.style.display = "none";
                }
            }

            if (preservarClinica) {
                clinicaSelect.value = preservarClinica;
                generarHorariosDinamicos();
            }
        }

        // Generar intervalos dinámicos con validación del endpoint AJAX (Nueva Cita)
        async function generarHorariosDinamicos(idCitaExcluir = '') {
            const clinicaSelect = document.getElementById('modalClinica');
            const fechaInput = document.getElementById('modalFechaCita');
            const horaSelect = document.getElementById('modalHoraCita');
            
            horaSelect.innerHTML = '<option value="">Cargando horarios disponibles...</option>';

            const selectedOption = clinicaSelect.options[clinicaSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                horaSelect.innerHTML = '<option value="">Seleccione clínica primero...</option>';
                return;
            }

            const clinicaId = selectedOption.value;
            const fechaCita = fechaInput ? fechaInput.value : '';

            if (!fechaCita) {
                horaSelect.innerHTML = '<option value="">Seleccione fecha primero...</option>';
                return;
            }

            let resultadoAjax = { ocupadas: [], cerrado: false, motivo: '' };
            try {
                let response = await fetch(`registro_citas_clinica.php?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaCita}&id_cita_excluir=${idCitaExcluir}`);
                resultadoAjax = await response.json();
            } catch (e) {
                console.error("Error al consultar disponibilidad:", e);
            }

            if (resultadoAjax.cerrado) {
                horaSelect.innerHTML = `<option value="">${resultadoAjax.motivo}</option>`;
                return;
            }

            let horaInicioStr = selectedOption.getAttribute('data-inicio') || '07:00';
            let horaFinStr = selectedOption.getAttribute('data-fin') || '14:00';
            let duracionMinutos = parseInt(selectedOption.getAttribute('data-duracion')) || 30;
            let horaComida = selectedOption.getAttribute('data-comida') || '';

            poblarSelectHorarios(horaSelect, horaInicioStr, horaFinStr, duracionMinutos, horaComida, resultadoAjax.ocupadas);
        }

        // Generar intervalos dinámicos con validación (Edición Cita)
        async function generarHorariosEdicionDinamicos() {
            const idCita = document.getElementById('edit_id_cita').value;
            const clinicaId = document.getElementById('edit_clinica_id').value;
            const fechaCita = document.getElementById('edit_fecha_cita').value;
            const horaSelect = document.getElementById('edit_hora_cita');
            
            horaSelect.innerHTML = '<option value="">Cargando horarios disponibles...</option>';

            if (!clinicaId || !fechaCita) return;

            let resultadoAjax = { ocupadas: [], cerrado: false, motivo: '' };
            try {
                let response = await fetch(`registro_citas_clinica.php?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaCita}&id_cita_excluir=${idCita}`);
                resultadoAjax = await response.json();
            } catch (e) {
                console.error("Error al consultar disponibilidad en edición:", e);
            }

            if (resultadoAjax.cerrado) {
                horaSelect.innerHTML = `<option value="">${resultadoAjax.motivo}</option>`;
                return;
            }

            let horaInicioStr = document.getElementById('edit_hora_inicio').value || '07:00';
            let horaFinStr = document.getElementById('edit_hora_fin').value || '14:00';
            let duracionMinutos = parseInt(document.getElementById('edit_duracion').value) || 30;
            let horaComida = document.getElementById('edit_hora_comida').value || '';

            poblarSelectHorarios(horaSelect, horaInicioStr, horaFinStr, duracionMinutos, horaComida, resultadoAjax.ocupadas);
        }

        function poblarSelectHorarios(selectElement, horaInicioStr, horaFinStr, duracionMinutos, horaComida, horasOcupadas) {
            function timeToMinutes(timeStr) {
                const parts = timeStr.split(':');
                return parseInt(parts[0]) * 60 + parseInt(parts[1] || 0);
            }

            function minutesToTime(totalMinutes) {
                const hours = Math.floor(totalMinutes / 60);
                const minutes = totalMinutes % 60;
                return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
            }

            let currentMinutes = timeToMinutes(horaInicioStr);
            let endMinutes = timeToMinutes(horaFinStr);
            let comidaMinutes = horaComida ? timeToMinutes(horaComida) : -1;

            let valorActualSeleccionado = selectElement.value;
            selectElement.innerHTML = '<option value="">Seleccione hora...</option>';
            let horariosDisponiblesCount = 0;

            while (currentMinutes <= endMinutes) {
                let timeFormatted = minutesToTime(currentMinutes);

                let enComida = false;
                if (comidaMinutes !== -1 && Math.abs(currentMinutes - comidaMinutes) < duracionMinutos) {
                    enComida = true;
                }

                let estaOcupado = horasOcupadas.includes(timeFormatted);

                if (!enComida) {
                    let opt = document.createElement('option');
                    opt.value = timeFormatted;
                    
                    if (estaOcupado) {
                        opt.textContent = timeFormatted + ' hrs (Ocupado)';
                        opt.disabled = true;
                        opt.style.color = '#999';
                        opt.style.backgroundColor = '#f1f3f5';
                    } else {
                        opt.textContent = timeFormatted + ' hrs (Disponible)';
                        horariosDisponiblesCount++;
                    }
                    selectElement.appendChild(opt);
                }

                currentMinutes += duracionMinutos;
            }

            if (valorActualSeleccionado) {
                selectElement.value = valorActualSeleccionado;
            }

            if (horariosDisponiblesCount === 0 && selectElement.options.length <= 1) {
                selectElement.innerHTML = '<option value="">No hay horarios disponibles para esta fecha</option>';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            filtrarClinicasFiltro();
            const filtroFechaVal = document.getElementById('filtroFechaSelect').value;
            if (filtroFechaVal === 'fecha_especifica') {
                document.getElementById('divFechaInicio').style.display = 'flex';
                document.getElementById('lblFechaInicio').textContent = 'Fecha Específica:';
            } else if (filtroFechaVal === 'rango') {
                document.getElementById('divFechaInicio').style.display = 'flex';
                document.getElementById('divFechaFin').style.display = 'flex';
                document.getElementById('lblFechaInicio').textContent = 'Desde:';
            }
        });

        function submitFiltros() {
            document.getElementById('filterForm').submit();
        }

        async function abrirModalEditar(idCita, fecha, hora, clinicaId, duracion, horaInicio, horaFin, horaComida) {
            document.getElementById('edit_id_cita').value = idCita;
            document.getElementById('edit_fecha_cita').value = fecha;
            document.getElementById('edit_clinica_id').value = clinicaId;
            document.getElementById('edit_duracion').value = duracion || 30;
            document.getElementById('edit_hora_inicio').value = horaInicio || '07:00';
            document.getElementById('edit_hora_fin').value = horaFin || '14:00';
            document.getElementById('edit_hora_comida').value = horaComida || '';

            document.getElementById('edit_hora_cita').innerHTML = '<option value="">Cargando horarios...</option>';
            document.getElementById('edit_hora_cita').value = hora;

            document.getElementById('modalEditarCita').style.display = 'flex';

            await generarHorariosEdicionDinamicos();
            document.getElementById('edit_hora_cita').value = hora; 
        }

        function cerrarModalEditar() {
            document.getElementById('modalEditarCita').style.display = 'none';
        }

        function abrirModalNuevaCita() {
            document.getElementById('modalTipoConsulta').value = '';
            document.getElementById('modalClinica').value = '';
            document.getElementById('modalHoraCita').innerHTML = '<option value="">Seleccione clínica primero...</option>';
            document.getElementById('modalNuevaCita').style.display = 'flex';
        }

        function abrirModalAsignarCitaDirecta() {
            abrirModalNuevaCita();
            const tipoFiltro = document.getElementById('filtroTipo').value;
            const clinicaFiltro = document.getElementById('filtroClinica').value;

            if (tipoFiltro) {
                document.getElementById('modalTipoConsulta').value = tipoFiltro;
                filtrarClinicasModal();
                if (clinicaFiltro) {
                    const clinicaSelect = document.getElementById('modalClinica');
                    for (let opt of clinicaSelect.options) {
                        if (opt.getAttribute('data-nombre') === clinicaFiltro) {
                            clinicaSelect.value = opt.value;
                            generarHorariosDinamicos();
                            break;
                        }
                    }
                }
            }
        }

        function abrirModalNuevaCitaPrellenada(idPaciente, tipoClinicaVal, nombreClinicaVal) {
            abrirModalNuevaCita();
            if (idPaciente) {
                const selPaciente = document.querySelector('#modalNuevaCita select[name="paciente_id"]');
                if (selPaciente) selPaciente.value = idPaciente;
            }
            if (tipoClinicaVal) {
                document.getElementById('modalTipoConsulta').value = tipoClinicaVal;
                filtrarClinicasModal();
                if (nombreClinicaVal) {
                    const clinicaSelect = document.getElementById('modalClinica');
                    for (let opt of clinicaSelect.options) {
                        if (opt.getAttribute('data-nombre') === nombreClinicaVal) {
                            clinicaSelect.value = opt.value;
                            generarHorariosDinamicos();
                            break;
                        }
                    }
                }
            }
        }

        function cerrarModalNuevaCita() {
            document.getElementById('modalNuevaCita').style.display = 'none';
        }

        function eliminarCita(idCita) {
            if (confirm('¿Está seguro de eliminar esta cita del registro?')) {
                window.location.href = 'eliminar_cita.php?id=' + idCita;
            }
        }

        window.onclick = function(event) {
            var modalEdit = document.getElementById('modalEditarCita');
            var modalNew = document.getElementById('modalNuevaCita');
            if (event.target == modalEdit) cerrarModalEditar();
            if (event.target == modalNew) cerrarModalNuevaCita();
        }
    </script>
</body>
</html>