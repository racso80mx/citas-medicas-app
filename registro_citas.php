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
// 1.1 ENDPOINT AJAX INTERNO: HORARIOS OCUPADOS, FESTIVOS, SIS Y BLOQUEOS
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
// 2. CAPTURA DE PARÁMETROS DE FILTRO, LÍMITE Y ACCIONES (GET)
// ===================================================================
$sel_tipos    = isset($_GET['tipo_clinica']) && is_array($_GET['tipo_clinica']) ? $_GET['tipo_clinica'] : [];
$sel_nombres  = isset($_GET['nombre_clinica']) && is_array($_GET['nombre_clinica']) ? $_GET['nombre_clinica'] : [];
$filtro_fecha = $_GET['filtro_fecha'] ?? '';
$f_inicio     = $_GET['fecha_inicio'] ?? '';
$f_fin        = $_GET['fecha_fin'] ?? '';
$limit        = $_GET['limit'] ?? '50';
$accion       = $_GET['accion'] ?? '';

// Lógica de filtrado rápido de fechas
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

// ===================================================================
// 3. CONSULTAS PARA LLENAR LOS DROPDOWN Y MODALES
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

if (!empty($f_inicio) && !empty($f_fin)) {
    $where .= " AND c.fecha_cita BETWEEN :f_inicio AND :f_fin";
    $params['f_inicio'] = $f_inicio;
    $params['f_fin']    = $f_fin;
} elseif (!empty($f_inicio)) {
    $where .= " AND c.fecha_cita = :f_inicio";
    $params['f_inicio'] = $f_inicio;
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
// 5. CONSULTA PRINCIPAL CON LÍMITE Y EXTRACCIÓN DE DATOS PARA JS
// ===================================================================
try {
    $sql_main = "SELECT c.* FROM citas c $where ORDER BY c.id_cita DESC";
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

    $stmt_clinicas_modal = $pdo->query("SELECT * FROM clinicas ORDER BY tipo_clinica ASC, nombre_clinica ASC");
    $lista_clinicas_modal = $stmt_clinicas_modal->fetchAll(PDO::FETCH_ASSOC);

    $clinicas_por_nombre = [];
    foreach ($lista_clinicas_modal as $cli) {
        $clinicas_por_nombre[$cli['nombre_clinica']] = $cli;
    }

} catch (Exception $e) {
    $citas = [];
    $lista_pacientes_modal = [];
    $lista_clinicas_modal = [];
    $clinicas_por_nombre = [];
}

$query_params = $_GET;
$query_params['accion'] = 'exportar_excel';
$url_excel = '?' . http_build_query($query_params);
$query_params['accion'] = 'exportar_pacientes_clinica_pdf';
$url_pdf = '?' . http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Registro de Citas</title>
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
    <script>
        const clinicasData = <?php echo json_encode($lista_clinicas_modal); ?>;
    </script>

    <div class="navbar">
        <div><i class="fa-solid fa-calendar-check"></i> <strong>Registro de Citas</strong></div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver a Catálogos</a>
    </div>

    <div class="container">
        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevaCita()" style="background-color: var(--imss-verde); font-weight: bold;">
                        <i class="fa-solid fa-calendar-plus"></i> Asignar Nueva Cita
                    </button>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo htmlspecialchars($url_pdf); ?>" target="_blank" class="btn btn-primary" title="Generar PDF con listado de pacientes de la clínica seleccionada">
                        <i class="fa-solid fa-file-pdf"></i> Descargar Pacientes (PDF)
                    </a>
                    <a href="<?php echo htmlspecialchars($url_excel); ?>" class="btn btn-excel" title="Exportar citas filtradas a Excel">
                        <i class="fa-solid fa-file-download"></i> Exportar a Excel
                    </a>
                </div>
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

                    <!-- FILTRO DESPLEGABLE DE FECHAS (Hoy, Mañana, etc.) -->
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 12px; color: #637381;">Filtrar por Fecha:</label>
                        <select name="filtro_fecha" id="filtro_fecha" onchange="cambiarFiltroFecha()" style="font-weight: 500;">
                            <option value="" <?php echo ($filtro_fecha === '') ? 'selected' : ''; ?>>Todas las fechas</option>
                            <option value="hoy" <?php echo ($filtro_fecha === 'hoy') ? 'selected' : ''; ?>>Hoy</option>
                            <option value="manana" <?php echo ($filtro_fecha === 'manana') ? 'selected' : ''; ?>>Mañana</option>
                            <option value="semana" <?php echo ($filtro_fecha === 'semana') ? 'selected' : ''; ?>>Esta Semana</option>
                            <option value="mes" <?php echo ($filtro_fecha === 'mes') ? 'selected' : ''; ?>>Este Mes</option>
                            <option value="fecha_especifica" <?php echo ($filtro_fecha === 'fecha_especifica') ? 'selected' : ''; ?>>Fecha Específica</option>
                            <option value="rango" <?php echo ($filtro_fecha === 'rango') ? 'selected' : ''; ?>>Rango de Fechas</option>
                        </select>
                    </div>

                    <!-- CAMPOS DE FECHA DINÁMICOS -->
                    <div id="box_fecha_inicio" style="display: flex; flex-direction: column; gap: 4px; <?php echo in_array($filtro_fecha, ['fecha_especifica', 'rango']) ? '' : 'display:none;'; ?>">
                        <label id="lbl_fecha_inicio" style="font-weight: bold; font-size: 12px; color: #637381;"><?php echo ($filtro_fecha === 'fecha_especifica') ? 'Fecha:' : 'Desde:'; ?></label>
                        <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($f_inicio); ?>" onchange="submitFiltros()">
                    </div>

                    <div id="box_fecha_fin" style="display: flex; flex-direction: column; gap: 4px; <?php echo ($filtro_fecha === 'rango') ? '' : 'display:none;'; ?>">
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
                    <a href="?" class="btn btn-secondary"><i class="fa-solid fa-rotate-right"></i> Limpiar Filtros</a>
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
                        <th>Tipo Consulta</th>
                        <th>Clínica</th>
                        <th>Teléfono</th>
                        <th>Estatus</th>
                        <th class="acciones-col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($citas) > 0): ?>
                        <?php foreach ($citas as $c): 
                            $nombre_cli_actual = $c['nombre_clinica'] ?? '';
                            $tipo_cli_actual = $c['tipo_clinica'] ?? '';
                            if (empty($tipo_cli_actual) && isset($clinicas_por_nombre[$nombre_cli_actual])) {
                                $tipo_cli_actual = $clinicas_por_nombre[$nombre_cli_actual]['tipo_clinica'] ?? '';
                            }
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['id_cita'] ?? ''); ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($c['no_expediente'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['nombre_paciente'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['curp'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['fecha_cita'] ?? ''); ?></td>
                                <td><span style="background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-weight: bold;"><?php echo htmlspecialchars($c['hora_cita'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($tipo_cli_actual); ?></td>
                                <td><?php echo htmlspecialchars($nombre_cli_actual); ?></td>
                                <td><?php echo htmlspecialchars($c['telefono'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['estatus'] ?? ''); ?></td>
                                <td class="acciones-col">
                                    <a href="procesar_cita.php?id_cita=<?php echo $c['id_cita']; ?>" target="_blank" class="btn-icon btn-blue" title="Ver Comprobante PDF">
                                        <i class="fa-solid fa-file-pdf"></i>
                                    </a>
                                    <button class="btn-icon btn-orange" onclick="abrirModalEditar(<?php echo $c['id_cita']; ?>, '<?php echo htmlspecialchars($c['fecha_cita'] ?? ''); ?>', '<?php echo htmlspecialchars($c['hora_cita'] ?? ''); ?>', '<?php echo htmlspecialchars($nombre_cli_actual); ?>')" title="Modificar Fecha y Hora">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button class="btn-icon btn-primary" onclick="abrirModalNuevaCitaPrellenada('<?php echo htmlspecialchars($c['id_paciente'] ?? ''); ?>', '<?php echo htmlspecialchars($nombre_cli_actual); ?>', '<?php echo htmlspecialchars($tipo_cli_actual); ?>')" title="Asignar Nueva Cita">
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

    <!-- ========================================================= -->
    <!-- MODAL EDITAR CITA                                         -->
    <!-- ========================================================= -->
    <div id="modalEditarCita" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-calendar-pen"></i> Modificar Cita Médica</h3>
                <button class="close-btn" onclick="cerrarModalEditar()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="actualizar_cita.php" method="POST">
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
                        <label>Hora de la Cita (Respetando duración de la clínica):</label>
                        <select id="edit_hora_cita" name="hora_cita" required>
                            <option value="">Cargando horarios...</option>
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

    <!-- ========================================================= -->
    <!-- MODAL ASIGNAR NUEVA CITA                                  -->
    <!-- ========================================================= -->
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
                        <select id="modalTipoConsulta" onchange="filtrarClinicasModal()" required>
                            <option value="">Seleccione tipo de consulta...</option>
                            <?php 
                            $tipos_unicos = [];
                            foreach ($lista_clinicas_modal as $cli_item) {
                                $t_val = trim($cli_item['tipo_clinica'] ?? '');
                                if (!empty($t_val) && !in_array($t_val, $tipos_unicos)) {
                                    $tipos_unicos[] = $t_val;
                                }
                            }
                            sort($tipos_unicos);
                            foreach ($tipos_unicos as $tc): 
                            ?>
                                <option value="<?php echo htmlspecialchars($tc); ?>">
                                    <?php echo htmlspecialchars($tc); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Clínica Asignada:</label>
                        <select name="clinica_id" id="modalClinica" onchange="generarHorariosDinamicos()" required>
                            <option value="">Seleccione primero el tipo de consulta...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Fecha de la Cita:</label>
                        <input type="date" name="fecha_cita" id="modalFechaCita" required value="<?php echo date('Y-m-d'); ?>" onchange="generarHorariosDinamicos()">
                    </div>

                    <div class="form-group">
                        <label>Hora de la Cita (Horarios según configuración y disponibilidad):</label>
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

    <!-- SCRIPTS -->
    <script>
        function cambiarFiltroFecha() {
            const val = document.getElementById('filtro_fecha').value;
            const boxInicio = document.getElementById('box_fecha_inicio');
            const boxFin = document.getElementById('box_fecha_fin');
            const lblInicio = document.getElementById('lbl_fecha_inicio');

            if (val === '' || val === 'hoy' || val === 'manana' || val === 'semana' || val === 'mes') {
                boxInicio.style.display = 'none';
                boxFin.style.display = 'none';
            } else if (val === 'fecha_especifica') {
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

        function timeToMinutes(timeStr) {
            const parts = timeStr.split(':');
            return parseInt(parts[0]) * 60 + parseInt(parts[1] || 0);
        }

        function minutesToTime(totalMinutes) {
            const hours = Math.floor(totalMinutes / 60);
            const minutes = totalMinutes % 60;
            return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
        }

        function poblarSelectHorarios(selectElement, horaInicioStr, horaFinStr, duracionMinutos, horaComida, horasOcupadas, horaActualPreseleccionada = '') {
            let currentMinutes = timeToMinutes(horaInicioStr);
            let endMinutes = timeToMinutes(horaFinStr);
            let comidaMinutes = horaComida ? timeToMinutes(horaComida) : -1;

            let valorActualSeleccionado = selectElement.value || horaActualPreseleccionada;
            selectElement.innerHTML = '<option value="">Seleccione hora...</option>';
            let horariosDisponiblesCount = 0;

            while (currentMinutes <= endMinutes) {
                let timeFormatted = minutesToTime(currentMinutes);
                let enComida = false;
                
                if (comidaMinutes !== -1 && Math.abs(currentMinutes - comidaMinutes) < duracionMinutos) {
                    enComida = true;
                }

                let estaOcupado = horasOcupadas.includes(timeFormatted);
                let esLaActual = (valorActualSeleccionado && timeFormatted === valorActualSeleccionado);

                if (!enComida) {
                    let opt = document.createElement('option');
                    opt.value = timeFormatted;
                    
                    if (estaOcupado && !esLaActual) {
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
                selectElement.innerHTML = '<option value="">No hay horarios disponibles</option>';
            }
        }

        // --- FILTRAR CLÍNICAS EN CASCADA (Tipo Consulta -> Clínica) ---
        function filtrarClinicasModal(preservarClinicaId = '') {
            const tipoSelect = document.getElementById('modalTipoConsulta');
            const clinicaSelect = document.getElementById('modalClinica');
            const tipoSeleccionado = tipoSelect.value;

            clinicaSelect.innerHTML = '<option value="">Seleccione clínica...</option>';
            document.getElementById('modalHoraCita').innerHTML = '<option value="">Seleccione clínica primero...</option>';

            if (!tipoSeleccionado) return;

            const clinicasFiltradas = clinicasData.filter(c => (c.tipo_clinica || '').trim() === tipoSeleccionado.trim());

            clinicasFiltradas.forEach(cli => {
                let opt = document.createElement('option');
                opt.value = cli.id_clinica;
                opt.textContent = cli.nombre_clinica;
                opt.setAttribute('data-nombre', cli.nombre_clinica);
                clinicaSelect.appendChild(opt);
            });

            if (preservarClinicaId) {
                clinicaSelect.value = preservarClinicaId;
                generarHorariosDinamicos();
            }
        }

        // --- NUEVA CITA (Con validación de días inactivos, festivos y cierres SIS) ---
        async function generarHorariosDinamicos() {
            const clinicaSelect = document.getElementById('modalClinica');
            const fechaInput = document.getElementById('modalFechaCita');
            const horaSelect = document.getElementById('modalHoraCita');
            
            horaSelect.innerHTML = '<option value="">Cargando horarios...</option>';

            const clinicaId = clinicaSelect.value;
            const fechaCita = fechaInput ? fechaInput.value : '';

            if (!clinicaId || !fechaCita) {
                horaSelect.innerHTML = '<option value="">Seleccione clínica y fecha...</option>';
                return;
            }

            const clinicaInfo = clinicasData.find(c => c.id_clinica == clinicaId);
            if (!clinicaInfo) return;

            let resultadoAjax = { ocupadas: [], cerrado: false, motivo: '' };
            try {
                let response = await fetch(window.location.pathname + `?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaCita}`);
                resultadoAjax = await response.json();
            } catch (e) {
                console.error(e);
            }

            if (resultadoAjax.cerrado) {
                horaSelect.innerHTML = `<option value="">${resultadoAjax.motivo}</option>`;
                return;
            }

            let duracionMinutos = parseInt(clinicaInfo.duracion_consulta || clinicaInfo.duracion || 30);
            let horaInicioStr = clinicaInfo.hora_inicio || '07:00';
            let horaFinStr = clinicaInfo.hora_fin || '14:00';
            let horaComida = clinicaInfo.hora_comida || clinicaInfo.comida || '';

            poblarSelectHorarios(horaSelect, horaInicioStr, horaFinStr, duracionMinutos, horaComida, resultadoAjax.ocupadas);
        }

        function abrirModalNuevaCita() {
            document.getElementById('modalTipoConsulta').value = '';
            document.getElementById('modalClinica').innerHTML = '<option value="">Seleccione primero el tipo de consulta...</option>';
            document.getElementById('modalHoraCita').innerHTML = '<option value="">Seleccione clínica primero...</option>';
            document.getElementById('modalNuevaCita').style.display = 'flex';
        }

        function abrirModalNuevaCitaPrellenada(idPaciente, nombreClinica, tipoClinica) {
            abrirModalNuevaCita();
            if (idPaciente) {
                document.querySelector('#modalNuevaCita select[name="paciente_id"]').value = idPaciente;
            }
            if (tipoClinica) {
                document.getElementById('modalTipoConsulta').value = tipoClinica;
                filtrarClinicasModal();
                if (nombreClinica) {
                    const clinicaSelect = document.getElementById('modalClinica');
                    for (let opt of clinicaSelect.options) {
                        if (opt.getAttribute('data-nombre') === nombreClinica) {
                            clinicaSelect.value = opt.value;
                            generarHorariosDinamicos();
                            break;
                        }
                    }
                }
            }
        }

        // --- EDITAR CITA (Con validación de días inactivos, festivos, cierres SIS y exclusión de cita actual) ---
        async function generarHorariosEdicionDinamicos() {
            const idCita = document.getElementById('edit_id_cita').value;
            const clinicaId = document.getElementById('edit_clinica_id').value;
            const fechaCita = document.getElementById('edit_fecha_cita').value;
            const horaSelect = document.getElementById('edit_hora_cita');
            
            let horaActual = horaSelect.getAttribute('data-hora-actual') || '';

            horaSelect.innerHTML = '<option value="">Cargando horarios...</option>';

            if (!clinicaId || !fechaCita) return;

            let resultadoAjax = { ocupadas: [], cerrado: false, motivo: '' };
            try {
                let response = await fetch(window.location.pathname + `?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaCita}&id_cita_excluir=${idCita}`);
                resultadoAjax = await response.json();
            } catch (e) {
                console.error(e);
            }

            if (resultadoAjax.cerrado) {
                horaSelect.innerHTML = `<option value="">${resultadoAjax.motivo}</option>`;
                return;
            }

            let horaInicioStr = document.getElementById('edit_hora_inicio').value;
            let horaFinStr = document.getElementById('edit_hora_fin').value;
            let duracionMinutos = parseInt(document.getElementById('edit_duracion').value);
            let horaComida = document.getElementById('edit_hora_comida').value;

            poblarSelectHorarios(horaSelect, horaInicioStr, horaFinStr, duracionMinutos, horaComida, resultadoAjax.ocupadas, horaActual);
        }

        async function abrirModalEditar(idCita, fecha, hora, nombreClinica) {
            document.getElementById('edit_id_cita').value = idCita;
            document.getElementById('edit_fecha_cita').value = fecha;

            let clinicaInfo = clinicasData.find(c => c.nombre_clinica === nombreClinica);
            
            if(clinicaInfo) {
                document.getElementById('edit_clinica_id').value = clinicaInfo.id_clinica;
                document.getElementById('edit_duracion').value = clinicaInfo.duracion_consulta || clinicaInfo.duracion || 30;
                document.getElementById('edit_hora_inicio').value = clinicaInfo.hora_inicio || '07:00';
                document.getElementById('edit_hora_fin').value = clinicaInfo.hora_fin || '14:00';
                document.getElementById('edit_hora_comida').value = clinicaInfo.hora_comida || clinicaInfo.comida || '';
            }

            const horaSelect = document.getElementById('edit_hora_cita');
            let horaLimpia = hora.substring(0, 5);
            horaSelect.setAttribute('data-hora-actual', horaLimpia);

            document.getElementById('modalEditarCita').style.display = 'flex';
            await generarHorariosEdicionDinamicos();
        }

        function cerrarModalEditar() { document.getElementById('modalEditarCita').style.display = 'none'; }
        function cerrarModalNuevaCita() { document.getElementById('modalNuevaCita').style.display = 'none'; }
        
        function eliminarCita(idCita) {
            if (confirm('¿Está seguro de eliminar esta cita del registro?')) {
                window.location.href = 'eliminar_cita.php?id=' + idCita;
            }
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('modalEditarCita')) cerrarModalEditar();
            if (event.target == document.getElementById('modalNuevaCita')) cerrarModalNuevaCita();
        }
    </script>
</body>
</html>