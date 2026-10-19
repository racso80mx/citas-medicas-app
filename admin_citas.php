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

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

// ===================================================================
// 1.1 ENDPOINT AJAX INTERNO: HORARIOS OCUPADOS Y BLOQUEO DE SIS / FESTIVOS
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

        // 1. Validar Día Inactivo de la clínica
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

        // 4. Validar Excepciones manuales de clínica
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
// 2. CAPTURA DE PARÁMETROS DE FILTRO, LÍMITE Y ACCIONES (GET)
// ===================================================================
$sel_tipos     = isset($_GET['tipo_clinica']) && is_array($_GET['tipo_clinica']) ? $_GET['tipo_clinica'] : [];
$sel_nombres   = isset($_GET['nombre_clinica']) && is_array($_GET['nombre_clinica']) ? $_GET['nombre_clinica'] : [];
$filtro_tiempo = $_GET['filtro_tiempo'] ?? 'todos';
$f_inicio      = $_GET['fecha_inicio'] ?? '';
$f_fin         = $_GET['fecha_fin'] ?? '';
$limit         = $_GET['limit'] ?? '50';
$accion        = $_GET['accion'] ?? '';

$hoy = date('Y-m-d');
if ($filtro_tiempo === 'hoy') {
    $f_inicio = $hoy;
    $f_fin = $hoy;
} elseif ($filtro_tiempo === 'manana') {
    $f_inicio = date('Y-m-d', strtotime('+1 day'));
    $f_fin = date('Y-m-d', strtotime('+1 day'));
} elseif ($filtro_tiempo === 'semana') {
    $f_inicio = date('Y-m-d', strtotime('monday this week'));
    $f_fin = date('Y-m-d', strtotime('sunday this week'));
} elseif ($filtro_tiempo === 'mes') {
    $f_inicio = date('Y-m-01');
    $f_fin = date('Y-m-t');
} elseif ($filtro_tiempo === 'unica' && empty($f_inicio)) {
    $f_inicio = $hoy;
    $f_fin = $hoy;
}

// ===================================================================
// 3. CONSULTAS PARA LLENAR LOS DROPDOWN DESDE LA TABLA MAESTRA 'clinicas'
// ===================================================================
try {
    $lst_tipos = $pdo->query("
        SELECT DISTINCT tipo_clinica 
        FROM clinicas 
        WHERE tipo_clinica IS NOT NULL AND tipo_clinica != '' 
        ORDER BY tipo_clinica ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $sql_nombres = "SELECT DISTINCT nombre_clinica FROM clinicas WHERE nombre_clinica IS NOT NULL AND nombre_clinica != ''";
    $params_nombres = [];

    if (!empty($sel_tipos)) {
        $in_tipos_dropdown = [];
        foreach ($sel_tipos as $i => $val) {
            $key = "dp_tipo_" . $i;
            $in_tipos_dropdown[] = ":" . $key;
            $params_nombres[$key] = $val;
        }
        $sql_nombres .= " AND tipo_clinica IN (" . implode(',', $in_tipos_dropdown) . ")";
    }
    $sql_nombres .= " ORDER BY nombre_clinica ASC";

    $stmt_nombres = $pdo->prepare($sql_nombres);
    $stmt_nombres->execute($params_nombres);
    $lst_nombres = $stmt_nombres->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $lst_tipos = [];
    $lst_nombres = [];
}

// ===================================================================
// 4. CONSTRUCCIÓN DE LA CONSULTA PRINCIPAL (WHERE DINÁMICO)
// ===================================================================
$where = "WHERE 1=1";
$params = [];

if ($filtro_tiempo === 'rango' && !empty($f_inicio) && !empty($f_fin)) {
    $where .= " AND c.fecha_cita BETWEEN :f_inicio AND :f_fin";
    $params['f_inicio'] = $f_inicio;
    $params['f_fin']    = $f_fin;
} elseif ($filtro_tiempo === 'unica' && !empty($f_inicio)) {
    $where .= " AND c.fecha_cita = :f_inicio";
    $params['f_inicio'] = $f_inicio;
} elseif (in_array($filtro_tiempo, ['hoy', 'manana', 'semana', 'mes']) && !empty($f_inicio) && !empty($f_fin)) {
    $where .= " AND c.fecha_cita BETWEEN :f_inicio AND :f_fin";
    $params['f_inicio'] = $f_inicio;
    $params['f_fin']    = $f_fin;
}

if (!empty($sel_tipos)) {
    $in_tipos = [];
    foreach ($sel_tipos as $i => $val) {
        $key = "t_clinica_" . $i;
        $in_tipos[] = ":" . $key;
        $params[$key] = $val;
    }
    $where .= " AND c.tipo_clinica IN (" . implode(',', $in_tipos) . ")";
}

if (!empty($sel_nombres)) {
    $in_nombres = [];
    foreach ($sel_nombres as $i => $val) {
        $key = "n_clinica_" . $i;
        $in_nombres[] = ":" . $key;
        $params[$key] = $val;
    }
    $where .= " AND c.nombre_clinica IN (" . implode(',', $in_nombres) . ")";
}

// ===================================================================
// 5. PROCESAR EXPORTACIONES (EXCEL O PDF DE PACIENTES POR CLÍNICA)
// ===================================================================
if ($accion === 'exportar_excel') {
    if (ob_get_length()) ob_clean();
    try {
        $stmt_exp = $pdo->prepare("SELECT c.* FROM citas c $where ORDER BY c.id_cita DESC");
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
            ORDER BY c.fecha_cita ASC, c.hora_cita ASC
        ";
        $stmt_pdf = $pdo->prepare($sql_pdf);
        $stmt_pdf->execute($params);
        $lista_pacientes_pdf = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);

        $nombre_medico_clinica = "Dr. Asignado al Servicio";
        if (!empty($sel_nombres) && count($sel_nombres) == 1) {
            try {
                $stmt_med = $pdo->prepare("SELECT medico FROM clinicas WHERE nombre_clinica = :nom LIMIT 1");
                $stmt_med->execute(['nom' => $sel_nombres[0]]);
                $res_med = $stmt_med->fetch(PDO::FETCH_ASSOC);
                if ($res_med && isset($res_med['medico']) && !empty($res_med['medico'])) {
                    $nombre_medico_clinica = $res_med['medico'];
                }
            } catch (Exception $ex) {
                $nombre_medico_clinica = "Dr. Asignado al Servicio";
            }
        }
    } catch (Exception $e) {
        $lista_pacientes_pdf = [];
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Listado de Pacientes por Clínica - Hospital General de Huimanguillo</title>
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
            .btn:hover { opacity: 0.9; }
            @media print {
                body { background: none; padding: 0; }
                .sheet { box-shadow: none; padding: 10mm; width: 100%; margin: 0; min-height: auto; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="sheet">
            <div>
                <div class="header-container">
                    <img src="logo_hospital.png" alt="Logo Hospital General de Huimanguillo">
                    <div class="header-title">
                        HOSPITAL GENERAL DE HUIMANGUILLO<br><span style="font-size: 13px; color: #637381; font-weight: normal;">DR. ADELFO S. AGUIRRE</span>
                    </div>
                </div>
                <div class="banner-verde">LISTADO DE PACIENTES POR CLÍNICA Y CITA PROGRAMADA</div>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha Cita</th>
                            <th>Hora</th>
                            <th>Expediente</th>
                            <th>Paciente</th>
                            <th>CURP</th>
                            <th>Teléfono</th>
                            <th>Tipo Paciente</th>
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
                                    <td><?php echo htmlspecialchars($row['tipo_paciente'] ?? 'General'); ?></td>
                                    <td><?php echo htmlspecialchars($row['colonia'] ?? 'S/N'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px; color: #637381;">No se encontraron registros de citas con los filtros seleccionados.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <div class="firmas-container">
                    <div class="firma-box">
                        <?php echo htmlspecialchars($nombre_medico_clinica); ?>
                        <span>Nombre del Médico de la Clínica</span>
                    </div>
                    <div class="firma-box">
                        Firma Autorizada
                        <span>Firma de quien entrega</span>
                    </div>
                </div>
                <div class="no-print">
                    <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir / Guardar PDF Carta</button>
                    <a href="admin_citas.php" class="btn" style="background-color: #637381; margin-left: 10px;"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ===================================================================
// 6. CONSULTA PRINCIPAL CON LÍMITE Y LISTAS PARA MODALES (INCLUYENDO ID Y EXPEDIENTE)
// ===================================================================
try {
    $sql_main = "SELECT c.*, p.id_paciente as paciente_rel_id, p.expediente as paciente_rel_exp FROM citas c LEFT JOIN pacientes p ON c.no_expediente = p.expediente $where ORDER BY c.id_cita DESC";
    if ($limit !== 'all') {
        $lim_val = (int)$limit;
        if ($lim_val <= 0) $lim_val = 50;
        $sql_main .= " LIMIT " . $lim_val;
    }

    $stmt = $pdo->prepare($sql_main);
    $stmt->execute($params);
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt_pacientes_modal = $pdo->query("SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp, telefono FROM pacientes ORDER BY nombre ASC");
    $lista_pacientes_modal = $stmt_pacientes_modal->fetchAll(PDO::FETCH_ASSOC);

    $stmt_clinicas_modal = $pdo->query("SELECT id_clinica, nombre_clinica, tipo_clinica, hora_inicio, hora_fin, duracion_consulta, hora_comida, dia_inactivo, fin_semana FROM clinicas ORDER BY nombre_clinica ASC");
    $lista_clinicas_modal = $stmt_clinicas_modal->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $citas = [];
    $lista_pacientes_modal = [];
    $lista_clinicas_modal = [];
}

$query_params = $_GET;
$query_params['accion'] = 'exportar_excel';
$url_excel = 'admin_citas.php?' . http_build_query($query_params);

$query_params['accion'] = 'exportar_pacientes_clinica_pdf';
$url_pdf = 'admin_citas.php?' . http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Citas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --excel-verde: #217346; --btn-azul: #1976d2; --btn-naranja: #f57c00; --btn-rojo: #d32f2f;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-dorado); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .container { max-width: 98%; margin: 25px auto; padding: 0 10px; }
        
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }
        .panel-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #637381; }
        .btn:hover { opacity: 0.9; }

        .filters-form { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
        .filters-form input[type="date"], .filters-form select { padding: 7px 10px; border: 1px solid var(--borde); border-radius: 4px; outline: none; font-size: 13px; background: #fff; }

        .import-panel-toggle { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: bold; color: var(--imss-verde); cursor: pointer; }
        .import-box { display: none; background: #f8f9fa; padding: 10px 15px; border-radius: 6px; border: 1px dashed var(--imss-verde); width: 100%; align-items: center; gap: 15px; flex-wrap: wrap; box-sizing: border-box; margin-top: 5px; }
        .import-box.show { display: flex; }

        .dropdown-check-list { display: inline-block; position: relative; }
        .dropdown-check-list .anchor { 
            position: relative; cursor: pointer; display: inline-block; padding: 7px 30px 7px 10px; 
            border: 1px solid var(--borde); border-radius: 4px; background: #fff; font-size: 13px; min-width: 170px; user-select: none; font-weight: 500;
        }
        .dropdown-check-list .anchor:after { 
            position: absolute; right: 10px; top: 38%; content: ""; border: solid #637381; border-width: 0 2px 2px 0; padding: 3px; transform: rotate(45deg); 
        }
        .dropdown-check-list .items { 
            display: none; padding: 8px; position: absolute; background: #fff; border: 1px solid var(--borde); 
            border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 260px; overflow-y: auto; z-index: 1000; min-width: 220px; margin-top: 4px; 
        }
        .dropdown-check-list.visible .items { display: block; }
        .dropdown-check-list .items label { display: flex; align-items: center; gap: 8px; font-size: 12px; padding: 5px 8px; cursor: pointer; border-radius: 4px; }
        .dropdown-check-list .items label:hover { background-color: #f1f3f5; }
        .dropdown-header-actions { display: flex; justify-content: space-between; border-bottom: 1px solid var(--borde); padding-bottom: 6px; margin-bottom: 6px; }
        .dropdown-header-actions button { background: none; border: none; color: var(--imss-verde); font-size: 11px; font-weight: bold; cursor: pointer; padding: 0; }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); color: #637381; white-space: nowrap; }
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
        <div><i class="fa-solid fa-calendar-check"></i> <strong>Catálogo y Control de Citas</strong></div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver a Catálogos</a>
    </div>

    <div class="container">
        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <label class="import-panel-toggle">
                        <input type="checkbox" id="toggleImport" onchange="toggleImportBox()" style="cursor: pointer;"> 
                        <i class="fa-solid fa-file-excel"></i> Mostrar importación
                    </label>
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevaCita()" style="background-color: var(--imss-verde); font-weight: bold;">
                        <i class="fa-solid fa-calendar-plus"></i> Asignar Nueva Cita
                    </button>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo htmlspecialchars($url_pdf); ?>" target="_blank" class="btn btn-primary" title="Generar PDF con listado de pacientes de la clínica seleccionada">
                        <i class="fa-solid fa-file-pdf"></i> Descargar Pacientes de Clínica (PDF)
                    </a>
                    <a href="<?php echo htmlspecialchars($url_excel); ?>" class="btn btn-excel" title="Exportar citas filtradas a Excel">
                        <i class="fa-solid fa-file-download"></i> Exportar Citas a Excel
                    </a>
                </div>
            </div>

            <div id="importBox" class="import-box">
                <a href="descargar_plantilla_citas.php" class="btn btn-excel"><i class="fa-solid fa-file-arrow-down"></i> Descargar Plantilla Citas</a>
                <form action="importar_citas.php" method="POST" enctype="multipart/form-data" style="display: inline-flex; gap: 8px; align-items: center; margin: 0;">
                    <input type="file" name="archivo_excel" accept=".xlsx, .xls, .csv" required style="font-size: 12px;">
                    <button type="submit" class="btn btn-excel"><i class="fa-solid fa-upload"></i> Subir a BD</button>
                </form>
            </div>

            <form id="filterForm" method="GET" class="panel-row filters-form" style="border-top: 1px solid var(--borde); padding-top: 12px;">
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 12px; color: #637381;">Tipo Clínica:</label>
                        <div id="listTipo" class="dropdown-check-list">
                            <span class="anchor">
                                <?php echo !empty($sel_tipos) ? count($sel_tipos) . ' Tipo(s) Sel.' : 'Seleccionar Tipos'; ?>
                            </span>
                            <div class="items">
                                <div class="dropdown-header-actions">
                                    <button type="button" onclick="marcarTodos('tipo_clinica[]', true)">Marcar todos</button>
                                    <button type="button" onclick="marcarTodos('tipo_clinica[]', false)">Desmarcar</button>
                                </div>
                                <?php foreach ($lst_tipos as $t): ?>
                                    <label>
                                        <input type="checkbox" name="tipo_clinica[]" value="<?php echo htmlspecialchars($t); ?>" 
                                            <?php echo in_array($t, $sel_tipos) ? 'checked' : ''; ?> 
                                            onchange="submitFiltros()">
                                        <?php echo htmlspecialchars($t); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 12px; color: #637381;">Nombre Clínica:</label>
                        <div id="listNombre" class="dropdown-check-list">
                            <span class="anchor">
                                <?php echo !empty($sel_nombres) ? count($sel_nombres) . ' Clínica(s) Sel.' : 'Seleccionar Clínicas'; ?>
                            </span>
                            <div class="items">
                                <div class="dropdown-header-actions">
                                    <button type="button" onclick="marcarTodos('nombre_clinica[]', true)">Marcar todos</button>
                                    <button type="button" onclick="marcarTodos('nombre_clinica[]', false)">Desmarcar</button>
                                </div>
                                <?php if (!empty($lst_nombres)): ?>
                                    <?php foreach ($lst_nombres as $n): ?>
                                        <label>
                                            <input type="checkbox" name="nombre_clinica[]" value="<?php echo htmlspecialchars($n); ?>" 
                                                <?php echo in_array($n, $sel_nombres) ? 'checked' : ''; ?> 
                                                onchange="submitFiltros()">
                                            <?php echo htmlspecialchars($n); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="font-size:11px; color:#919eab; padding:6px; display:block;">Sin clínicas disponibles</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 12px; color: #637381;">Filtrar por Fecha:</label>
                        <select name="filtro_tiempo" id="filtro_tiempo" onchange="cambiarFiltroTiempo()" style="font-weight: 500;">
                            <option value="todos" <?php echo ($filtro_tiempo === 'todos') ? 'selected' : ''; ?>>Todas las fechas</option>
                            <option value="hoy" <?php echo ($filtro_tiempo === 'hoy') ? 'selected' : ''; ?>>Hoy</option>
                            <option value="manana" <?php echo ($filtro_tiempo === 'manana') ? 'selected' : ''; ?>>Mañana</option>
                            <option value="semana" <?php echo ($filtro_tiempo === 'semana') ? 'selected' : ''; ?>>Esta Semana</option>
                            <option value="mes" <?php echo ($filtro_tiempo === 'mes') ? 'selected' : ''; ?>>Este Mes</option>
                            <option value="rango" <?php echo ($filtro_tiempo === 'rango') ? 'selected' : ''; ?>>Rango de Fechas</option>
                            <option value="unica" <?php echo ($filtro_tiempo === 'unica') ? 'selected' : ''; ?>>Fecha Única</option>
                        </select>
                    </div>

                    <div id="box_fecha_inicio" style="display: flex; flex-direction: column; gap: 4px; <?php echo in_array($filtro_tiempo, ['rango', 'unica']) ? '' : 'display:none;'; ?>">
                        <label id="lbl_fecha_inicio" style="font-weight: bold; font-size: 12px; color: #637381;"><?php echo ($filtro_tiempo === 'unica') ? 'Fecha:' : 'Desde:'; ?></label>
                        <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($f_inicio); ?>" onchange="submitFiltros()">
                    </div>

                    <div id="box_fecha_fin" style="display: flex; flex-direction: column; gap: 4px; <?php echo ($filtro_tiempo === 'rango') ? '' : 'display:none;'; ?>">
                        <label style="font-weight: bold; font-size: 12px; color: #637381;">Hasta:</label>
                        <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($f_fin); ?>" onchange="submitFiltros()">
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 12px; color: #637381;">Mostrar:</label>
                        <select name="limit" onchange="submitFiltros()">
                            <option value="25" <?php echo ($limit == 25) ? 'selected' : ''; ?>>25 registros</option>
                            <option value="50" <?php echo ($limit == 50) ? 'selected' : ''; ?>>50 registros</option>
                            <option value="100" <?php echo ($limit == 100) ? 'selected' : ''; ?>>100 registros</option>
                            <option value="500" <?php echo ($limit == 500) ? 'selected' : ''; ?>>500 registros</option>
                            <option value="all" <?php echo ($limit === 'all') ? 'selected' : ''; ?>>Todos</option>
                        </select>
                    </div>
                </div>

                <div>
                    <a href="admin_citas.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-right"></i> Limpiar Filtros</a>
                </div>
            </form>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Expediente</th>
                        <th>Paciente</th>
                        <th>CURP</th>
                        <th>Fecha Cita</th>
                        <th>Hora</th>
                        <th>Tipo Clínica</th>
                        <th>Clínica</th>
                        <th>Teléfono</th>
                        <th>Estatus</th>
                        <th class="acciones-col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($citas) > 0): ?>
                        <?php foreach ($citas as $c): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['id_cita'] ?? ''); ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($c['no_expediente'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['nombre_paciente'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['curp'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['fecha_cita'] ?? ''); ?></td>
                                <td><span style="background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-weight: bold;"><?php echo htmlspecialchars($c['hora_cita'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($c['tipo_clinica'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['nombre_clinica'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['telefono'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['estatus'] ?? ''); ?></td>
                                <td class="acciones-col">
                                    <a href="procesar_cita.php?id_cita=<?php echo $c['id_cita']; ?>" target="_blank" class="btn-icon btn-blue" title="Ver Comprobante PDF">
                                        <i class="fa-solid fa-file-pdf"></i>
                                    </a>
                                    <button class="btn-icon btn-orange" onclick="abrirModalEditar(<?php echo $c['id_cita']; ?>, '<?php echo htmlspecialchars($c['fecha_cita'] ?? ''); ?>', '<?php echo htmlspecialchars($c['hora_cita'] ?? ''); ?>', '<?php echo htmlspecialchars($c['nombre_clinica'] ?? ''); ?>')" title="Modificar Fecha y Hora">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button class="btn-icon btn-primary" onclick="abrirModalNuevaCitaPrellenada('<?php echo htmlspecialchars($c['paciente_rel_id'] ?? ''); ?>', '<?php echo htmlspecialchars($c['no_expediente'] ?? ''); ?>', '<?php echo htmlspecialchars($c['nombre_clinica'] ?? ''); ?>')" title="Asignar Nueva Cita">
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
                            <td colspan="11" style="text-align: center; color: #637381; padding: 30px;">No se encontraron registros de citas con los filtros aplicados.</td>
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
            <form action="actualizar_cita.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="edit_id_cita" name="id_cita">
                    <input type="hidden" id="edit_nombre_clinica" name="nombre_clinica">
                    <div class="form-group">
                        <label>Clínica Asignada:</label>
                        <input type="text" id="edit_clinica_display" readonly style="background: #f1f3f5; font-weight: bold;">
                    </div>
                    <div class="form-group">
                        <label>Fecha de la Cita:</label>
                        <input type="date" id="edit_fecha_cita" name="fecha_cita" required onchange="actualizarHorariosEditar()">
                    </div>
                    <div class="form-group">
                        <label>Hora de la Cita:</label>
                        <select id="edit_hora_cita" name="hora_cita" required>
                            <option value="">Seleccione fecha primero...</option>
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
                        <select name="paciente_id" id="new_paciente_id" required>
                            <option value="">Seleccione paciente...</option>
                            <?php foreach ($lista_pacientes_modal as $pac): ?>
                                <option value="<?php echo $pac['id_paciente']; ?>" data-expediente="<?php echo htmlspecialchars($pac['expediente'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno'] . ' (Exp: ' . $pac['expediente'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Seleccionar Clínica / Servicio:</label>
                        <select name="clinica_id" id="new_clinica_id" required onchange="actualizarHorariosNuevo()">
                            <option value="">Seleccione clínica...</option>
                            <?php foreach ($lista_clinicas_modal as $cli): ?>
                                <option value="<?php echo $cli['id_clinica']; ?>" 
                                    data-nombre="<?php echo htmlspecialchars($cli['nombre_clinica']); ?>"
                                    data-inicio="<?php echo $cli['hora_inicio'] ?? '08:00'; ?>"
                                    data-fin="<?php echo $cli['hora_fin'] ?? '14:00'; ?>"
                                    data-duracion="<?php echo $cli['duracion_consulta'] ?? 25; ?>"
                                    data-comida="<?php echo $cli['hora_comida'] ?? ''; ?>"
                                    data-diainactivo="<?php echo $cli['dia_inactivo'] ?? ''; ?>"
                                    data-finsemana="<?php echo $cli['fin_semana'] ?? 'FALSE'; ?>">
                                    <?php echo htmlspecialchars($cli['tipo_clinica'] . ' — ' . $cli['nombre_clinica']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="nombre_clinica" id="new_nombre_clinica">
                    </div>

                    <div class="form-group">
                        <label>Fecha de la Cita:</label>
                        <input type="date" name="fecha_cita" id="new_fecha_cita" required value="<?php echo date('Y-m-d'); ?>" onchange="actualizarHorariosNuevo()">
                    </div>

                    <div class="form-group">
                        <label>Hora de la Cita:</label>
                        <select name="hora_cita" id="new_hora_cita" required>
                            <option value="">Seleccione clínica y fecha primero...</option>
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
        const clinicasMap = {};
        <?php foreach ($lista_clinicas_modal as $cli): ?>
        clinicasMap["<?php echo htmlspecialchars($cli['nombre_clinica']); ?>"] = {
            id: "<?php echo $cli['id_clinica']; ?>",
            hora_inicio: "<?php echo $cli['hora_inicio'] ?? '08:00'; ?>",
            hora_fin: "<?php echo $cli['hora_fin'] ?? '14:00'; ?>",
            duracion: parseInt("<?php echo $cli['duracion_consulta'] ?? 25; ?>") || 25,
            hora_comida: "<?php echo $cli['hora_comida'] ?? ''; ?>",
            dia_inactivo: "<?php echo $cli['dia_inactivo'] ?? ''; ?>",
            fin_semana: "<?php echo $cli['fin_semana'] ?? 'FALSE'; ?>"
        };
        <?php endforeach; ?>

        function cambiarFiltroTiempo() {
            const val = document.getElementById('filtro_tiempo').value;
            const boxInicio = document.getElementById('box_fecha_inicio');
            const boxFin = document.getElementById('box_fecha_fin');
            const lblInicio = document.getElementById('lbl_fecha_inicio');

            if (val === 'todos' || val === 'hoy' || val === 'manana' || val === 'semana' || val === 'mes') {
                boxInicio.style.display = 'none';
                boxFin.style.display = 'none';
            } else if (val === 'unica') {
                boxInicio.style.display = 'flex';
                lblInicio.innerText = 'Fecha:';
                boxFin.style.display = 'none';
            } else if (val === 'rango') {
                boxInicio.style.display = 'flex';
                lblInicio.innerText = 'Desde:';
                boxFin.style.display = 'flex';
            }
            submitFiltros();
        }

        function toggleImportBox() {
            const box = document.getElementById('importBox');
            const checkbox = document.getElementById('toggleImport');
            if (checkbox.checked) {
                box.classList.add('show');
            } else {
                box.classList.remove('show');
            }
        }

        document.querySelectorAll('.dropdown-check-list .anchor').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.stopPropagation();
                const parent = this.parentElement;
                document.querySelectorAll('.dropdown-check-list').forEach(el => {
                    if (el !== parent) el.classList.remove('visible');
                });
                parent.classList.toggle('visible');
            });
        });

        document.addEventListener('click', function () {
            document.querySelectorAll('.dropdown-check-list').forEach(el => el.classList.remove('visible'));
        });

        document.querySelectorAll('.dropdown-check-list .items').forEach(items => {
            items.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        });

        function submitFiltros() {
            document.getElementById('filterForm').submit();
        }

        function marcarTodos(nombreCampo, estado) {
            const checkboxes = document.querySelectorAll(`input[name="${nombreCampo}"]`);
            checkboxes.forEach(cb => cb.checked = estado);
            submitFiltros();
        }

        async function generarSlotsConOcupados(clinicaId, config, fechaStr, selectElementId, idCitaExcluir = '', horaActualSeleccionada = '') {
            const selectEl = document.getElementById(selectElementId);
            selectEl.innerHTML = '<option value="">Cargando horarios...</option>';

            if (!fechaStr || !clinicaId) {
                selectEl.innerHTML = '<option value="">Seleccione clínica y fecha primero...</option>';
                return;
            }

            let datosServidor = { ocupadas: [], cerrado: false, motivo: '' };
            try {
                let url = `admin_citas.php?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaStr}`;
                if (idCitaExcluir) {
                    url += `&id_cita_excluir=${idCitaExcluir}`;
                }
                const response = await fetch(url);
                datosServidor = await response.json();
            } catch (err) {
                console.error("Error al consultar disponibilidad:", err);
            }

            if (datosServidor.cerrado) {
                selectEl.innerHTML = `<option value="">${datosServidor.motivo}</option>`;
                return;
            }

            let horasOcupadas = datosServidor.ocupadas || [];

            let [hInicio, mInicio] = (config.hora_inicio || '08:00').split(':').map(Number);
            let [hFin, mFin] = (config.hora_fin || '14:00').split(':').map(Number);
            let [hComida, mComida] = config.hora_comida ? config.hora_comida.split(':').map(Number) : [-1, -1];

            let minutosActuales = hInicio * 60 + mInicio;
            let minutosMaximos = hFin * 60 + mFin;
            let minutosComida = (hComida >= 0) ? (hComida * 60 + mComida) : -1;

            selectEl.innerHTML = '<option value="">Seleccione hora...</option>';
            let generados = 0;

            while (minutosActuales < minutosMaximos) {
                let h = Math.floor(minutosActuales / 60).toString().padStart(2, '0');
                let m = (minutosActuales % 60).toString().padStart(2, '0');
                let horaTexto = `${h}:${m}`;

                if (minutosComida < 0 || minutosActuales !== minutosComida) {
                    let opt = document.createElement('option');
                    opt.value = horaTexto;

                    if (horasOcupadas.includes(horaTexto)) {
                        opt.text = `${horaTexto} hrs — [OCUPADO]`;
                        opt.disabled = true;
                        opt.style.color = '#a0a0a0';
                    } else {
                        opt.text = `${horaTexto} hrs`;
                    }

                    if (horaActualSeleccionada === horaTexto) {
                        opt.selected = true;
                    }
                    selectEl.appendChild(opt);
                    generados++;
                }
                minutosActuales += (config.duracion > 0 ? config.duracion : 25);
            }

            if (generados === 0) {
                selectEl.innerHTML = '<option value="">No hay horarios configurados</option>';
            }
        }

        function abrirModalEditar(idCita, fecha, hora, nombreClinica) {
            document.getElementById('edit_id_cita').value = idCita;
            document.getElementById('edit_fecha_cita').value = fecha;
            document.getElementById('edit_nombre_clinica').value = nombreClinica;
            document.getElementById('edit_clinica_display').value = nombreClinica;
            
            window.editIdCitaActual = idCita;
            window.editHoraSeleccionada = hora;
            
            actualizarHorariosEditar();
            document.getElementById('modalEditarCita').style.display = 'flex';
        }

        function actualizarHorariosEditar() {
            const nombreClinica = document.getElementById('edit_nombre_clinica').value;
            const fecha = document.getElementById('edit_fecha_cita').value;
            const config = clinicasMap[nombreClinica];

            if (config) {
                generarSlotsConOcupados(
                    config.id, 
                    config, 
                    fecha, 
                    'edit_hora_cita', 
                    window.editIdCitaActual, 
                    window.editHoraSeleccionada
                );
            }
        }

        function cerrarModalEditar() {
            document.getElementById('modalEditarCita').style.display = 'none';
        }

        function abrirModalNuevaCita() {
            document.getElementById('modalNuevaCita').style.display = 'flex';
        }

        function abrirModalNuevaCitaPrellenada(idPaciente, noExpediente, nombreClinica) {
            abrirModalNuevaCita();
            
            const selPaciente = document.getElementById('new_paciente_id');
            if (selPaciente) {
                let encontrado = false;
                // Intentar match por ID directo
                if (idPaciente) {
                    for (let opt of selPaciente.options) {
                        if (opt.value == idPaciente) {
                            selPaciente.value = opt.value;
                            encontrado = true;
                            break;
                        }
                    }
                }
                // Si no se encontró por ID, intentar match por Expediente (data-expediente)
                if (!encontrado && noExpediente) {
                    for (let opt of selPaciente.options) {
                        if (opt.dataset.expediente === noExpediente) {
                            selPaciente.value = opt.value;
                            encontrado = true;
                            break;
                        }
                    }
                }
            }

            if (nombreClinica) {
                const selClinica = document.getElementById('new_clinica_id');
                if (selClinica) {
                    for (let option of selClinica.options) {
                        if (option.dataset.nombre === nombreClinica) {
                            selClinica.value = option.value;
                            break;
                        }
                    }
                    actualizarHorariosNuevo();
                }
            }
        }

        function actualizarHorariosNuevo() {
            const selClinica = document.getElementById('new_clinica_id');
            const selectedOption = selClinica.options[selClinica.selectedIndex];
            const fecha = document.getElementById('new_fecha_cita').value;

            if (!selectedOption || !selectedOption.value) {
                document.getElementById('new_hora_cita').innerHTML = '<option value="">Seleccione clínica primero...</option>';
                document.getElementById('new_nombre_clinica').value = '';
                return;
            }

            const nombreCli = selectedOption.dataset.nombre;
            document.getElementById('new_nombre_clinica').value = nombreCli;

            const config = clinicasMap[nombreCli];
            if (config) {
                generarSlotsConOcupados(
                    selectedOption.value, 
                    config, 
                    fecha, 
                    'new_hora_cita'
                );
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