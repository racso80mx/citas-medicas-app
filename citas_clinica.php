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

// Obtener la clínica asignada consultando directamente la base de datos[cite: 31]
$clinica_asignada = '';
try {
    $stmt_user = $pdo->prepare("SELECT clave_clinica FROM usuarios WHERE id_usuario = :id LIMIT 1");
    $stmt_user->execute(['id' => $_SESSION['usuario_id']]);
    $usr = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if ($usr && !empty(trim($usr['clave_clinica'] ?? ''))) {
        $clinica_asignada = trim($usr['clave_clinica']);
    }
} catch (Exception $e) {
    $clinica_asignada = '';
}

// ===================================================================
// 1.1 ENDPOINT AJAX INTERNO PARA CONSULTAR HORARIOS OCUPADOS
// ===================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'obtener_ocupados') {
    header('Content-Type: application/json');
    $clinica_id = $_GET['clinica_id'] ?? '';
    $fecha_cita = $_GET['fecha_cita'] ?? '';
    $id_cita_excluir = $_GET['id_cita_excluir'] ?? '';

    if (empty($clinica_id) || empty($fecha_cita)) {
        echo json_encode([]);
        exit();
    }

    try {
        $stmt_cli = $pdo->prepare("SELECT nombre_clinica FROM clinicas WHERE id_clinica = :id");
        $stmt_cli->execute(['id' => $clinica_id]);
        $nombre_clinica = $stmt_cli->fetchColumn();

        if (!$nombre_clinica) {
            echo json_encode([]);
            exit();
        }

        $sql = "SELECT hora_cita FROM citas WHERE nombre_clinica = :nombre_clinica AND fecha_cita = :fecha_cita";
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

        echo json_encode($horas_ocupadas);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// ===================================================================
// 2. CAPTURA DE PARÁMETROS DE FILTRO, LÍMITE, ORDEN Y ACCIONES (GET)
// ===================================================================
$f_inicio         = $_GET['fecha_inicio'] ?? '';
$f_fin            = $_GET['fecha_fin'] ?? '';
$fecha_especifica = $_GET['fecha_especifica'] ?? '';
$filtro_fecha     = $_GET['filtro_fecha'] ?? '';
$limit            = $_GET['limit'] ?? '50';
$accion           = $_GET['accion'] ?? '';

// Parámetros de ordenamiento[cite: 31]
$sort         = $_GET['sort'] ?? 'id_cita';
$order        = strtoupper($_GET['order'] ?? 'DESC');
$order        = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';

// Si es la primera carga (sin parámetros GET de fecha ni filtro), aplicar "hoy" por defecto[cite: 31]
if (!isset($_GET['filtro_fecha']) && !isset($_GET['fecha_inicio']) && !isset($_GET['fecha_fin']) && !isset($_GET['fecha_especifica'])) {
    $filtro_fecha = 'hoy';
}

// Lógica para botones rápidos de fecha (Hoy, Mañana, Semana, Mes)[cite: 31]
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
    }
}

// Si se selecciona fecha específica, tiene prioridad sobre el rango
if (!empty($fecha_especifica)) {
    $f_inicio = $fecha_especifica;
    $f_fin    = $fecha_especifica;
}

// ===================================================================
// 3. CONSTRUCCIÓN DE LA CONSULTA PRINCIPAL[cite: 31]
// ===================================================================
$where = "WHERE 1=1";
$params = [];

if (!empty($clinica_asignada)) {
    $where .= " AND c.nombre_clinica = :clinica_asignada";
    $params['clinica_asignada'] = $clinica_asignada;
}

if (!empty($f_inicio) && !empty($f_fin)) {
    $where .= " AND c.fecha_cita BETWEEN :f_inicio AND :f_fin";
    $params['f_inicio'] = $f_inicio;
    $params['f_fin']    = $f_fin;
}

// ===================================================================
// 3.1 PROCESAR ELIMINACIÓN DE CITA DE FORMA SEGURA
// ===================================================================
if ($accion === 'eliminar' && isset($_GET['id'])) {
    $id_cita_eliminar = (int)$_GET['id'];
    try {
        if (!empty($clinica_asignada)) {
            $stmt_del = $pdo->prepare("DELETE FROM citas WHERE id_cita = :id AND nombre_clinica = :clinica");
            $stmt_del->execute(['id' => $id_cita_eliminar, 'clinica' => $clinica_asignada]);
        } else {
            $stmt_del = $pdo->prepare("DELETE FROM citas WHERE id_cita = :id");
            $stmt_del->execute(['id' => $id_cita_eliminar]);
        }
    } catch (Exception $e) {}

    $redirect_params = $_GET;
    unset($redirect_params['accion'], $redirect_params['id']);
    header("Location: citas_clinica.php?" . http_build_query($redirect_params));
    exit();
}

// ===================================================================
// 4. PROCESAR EXPORTACIONES[cite: 31]
// ===================================================================
if ($accion === 'exportar_excel') {
    if (ob_get_length()) ob_clean();
    try {
        $stmt_exp = $pdo->prepare("SELECT c.* FROM citas c $where ORDER BY c.id_cita DESC");
        $stmt_exp->execute($params);
        $data_excel = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=reporte_citas_" . preg_replace('/[^a-zA-Z0-9]/', '_', $clinica_asignada) . "_" . date('Ymd_His') . ".xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        echo "<table border='1'>";
        echo "<tr style='background-color:#006847; color:#ffffff; font-weight:bold;'>
                <th>ID</th><th>Expediente</th><th>Paciente</th><th>CURP</th><th>Fecha Cita</th><th>Hora</th><th>Tipo Consulta</th><th>Clínica</th><th>Teléfono</th><th>Tipo Paciente</th><th>Estatus</th>
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
    } catch (Exception $e) {}
    exit();
}

if ($accion === 'exportar_pacientes_clinica_pdf') {
    if (ob_get_length()) ob_clean();
    try {
        $sql_pdf = "SELECT c.*, p.colonia FROM citas c LEFT JOIN pacientes p ON c.no_expediente = p.expediente $where ORDER BY c.fecha_cita ASC, c.hora_cita ASC";
        $stmt_pdf = $pdo->prepare($sql_pdf);
        $stmt_pdf->execute($params);
        $lista_pacientes_pdf = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);

        $nombre_medico_clinica = "Dr. Asignado al Servicio";
        if (!empty($clinica_asignada)) {
            $stmt_med = $pdo->prepare("SELECT medico FROM clinicas WHERE nombre_clinica = :nom LIMIT 1");
            $stmt_med->execute(['nom' => $clinica_asignada]);
            $res_med = $stmt_med->fetch(PDO::FETCH_ASSOC);
            if ($res_med && !empty($res_med['medico'])) {
                $nombre_medico_clinica = $res_med['medico'];
            }
        }
    } catch (Exception $e) { $lista_pacientes_pdf = []; }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Listado de Pacientes por Clínica</title>
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
                    <img src="logo_hospital.png" alt="Logo">
                    <div class="header-title">HOSPITAL GENERAL DE HUIMANGUILLO<br><span style="font-size: 13px; color: #637381; font-weight: normal;">DR. ADELFO S. AGUIRRE</span></div>
                </div>
                <div class="banner-verde">LISTADO DE PACIENTES - <?php echo htmlspecialchars($clinica_asignada); ?></div>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha Cita</th><th>Hora</th><th>Expediente</th><th>Paciente</th><th>CURP</th><th>Teléfono</th><th>Tipo Paciente</th><th>Colonia</th>
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
                            <tr><td colspan="8" style="text-align: center; padding: 20px; color: #637381;">No se encontraron registros de citas para esta clínica.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <div class="firmas-container">
                    <div class="firma-box"><?php echo htmlspecialchars($nombre_medico_clinica); ?><span>Nombre del Médico de la Clínica</span></div>
                    <div class="firma-box">Firma Autorizada<span>Firma de quien entrega</span></div>
                </div>
                <div class="no-print">
                    <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir PDF</button>
                    <a href="citas_clinica.php" class="btn" style="background-color: #637381; margin-left: 10px;">Regresar</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ===================================================================
// 5. CONSULTA PRINCIPAL Y DATOS PARA MODALES CON ORDENAMIENTO[cite: 31]
// ===================================================================
$citas = [];
$lista_pacientes_modal = [];
$lista_clinicas_modal = [];

try {
    $allowed_sorts = [
        'id_cita'         => 'c.id_cita',
        'no_expediente'   => 'c.no_expediente',
        'nombre_paciente' => 'c.nombre_paciente',
        'curp'            => 'c.curp',
        'fecha_cita'      => 'c.fecha_cita',
        'hora_cita'       => 'c.hora_cita',
        'tipo_clinica'    => 'c.tipo_clinica',
        'nombre_clinica'  => 'c.nombre_clinica',
        'telefono'        => 'c.telefono',
        'estatus'         => 'c.estatus'
    ];
    
    $sort_sql = $allowed_sorts[$sort] ?? 'c.id_cita';

    $sql_main = "SELECT c.*, 
                        p.nombre AS pac_nombre, 
                        p.apaterno AS pac_apaterno, 
                        p.amaterno AS pac_amaterno, 
                        p.domicilio AS pac_domicilio, 
                        p.municipio AS pac_municipio, 
                        p.colonia AS pac_colonia 
                 FROM citas c 
                 LEFT JOIN pacientes p ON c.no_expediente = p.expediente 
                 $where ORDER BY $sort_sql $order";

    if ($limit !== 'all') {
        $lim_val = (int)$limit > 0 ? (int)$limit : 50;
        $sql_main .= " LIMIT " . $lim_val;
    }
    $stmt = $pdo->prepare($sql_main);
    $stmt->execute($params);
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

try {
    $stmt_pacientes_modal = $pdo->query("SELECT id_paciente, nombre, apaterno, amaterno, expediente FROM pacientes ORDER BY nombre ASC");
    $lista_pacientes_modal = $stmt_pacientes_modal->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

try {
    $stmt_clinicas_modal = $pdo->query("SELECT * FROM clinicas ORDER BY tipo_clinica ASC, nombre_clinica ASC");
    $lista_clinicas_modal = $stmt_clinicas_modal->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

// URLs de exportación dinámicas
$query_params = $_GET;
$query_params['accion'] = 'exportar_excel';
$url_excel = 'citas_clinica.php?' . http_build_query($query_params);

$query_params['accion'] = 'exportar_pacientes_clinica_pdf';
$url_pdf = 'citas_clinica.php?' . http_build_query($query_params);

// Funciones auxiliares para el ordenamiento de columnas[cite: 31]
function obtenerUrlOrden($campo) {
    $params = $_GET;
    $nuevo_orden = 'ASC';
    if (isset($params['sort']) && $params['sort'] === $campo) {
        $nuevo_orden = (isset($params['order']) && $params['order'] === 'ASC') ? 'DESC' : 'ASC';
    }
    $params['sort'] = $campo;
    $params['order'] = $nuevo_orden;
    return 'citas_clinica.php?' . http_build_query($params);
}

function obtenerIconoOrden($campo) {
    $sort = $_GET['sort'] ?? 'id_cita';
    $order = $_GET['order'] ?? 'DESC';
    if ($sort === $campo) {
        return $order === 'ASC' ? ' <i class="fa-solid fa-arrow-up" style="color:var(--imss-verde); font-size:10px;"></i>' : ' <i class="fa-solid fa-arrow-down" style="color:var(--imss-verde); font-size:10px;"></i>';
    }
    return ' <i class="fa-solid fa-sort" style="color:#adb5bd; font-size:10px;"></i>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Citas de Clínica</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --excel-verde: #217346; --btn-azul: #1976d2; --btn-naranja: #f57c00; --btn-rojo: #d32f2f;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 98%; margin: 25px auto; padding: 0 15px; }
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }
        .panel-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #637381; }
        .filters-form { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
        .filters-form input[type="date"], .filters-form select { padding: 7px 10px; border: 1px solid var(--borde); border-radius: 4px; outline: none; font-size: 13px; background: #fff; }
        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); color: #637381; white-space: nowrap; }
        th a { color: #637381; text-decoration: none; display: flex; align-items: center; justify-content: space-between; gap: 5px; }
        th a:hover { color: var(--imss-verde); }
        td { padding: 10px; border-bottom: 1px solid var(--borde); white-space: nowrap; vertical-align: middle; }
        tr:hover { background-color: #f8f9fa; }
        .acciones-col { text-align: center; white-space: nowrap; }
        .btn-icon { width: 32px; height: 32px; border: none; border-radius: 6px; cursor: pointer; color: white; display: inline-flex; justify-content: center; align-items: center; margin: 0 2px; text-decoration: none; }
        .btn-blue { background-color: var(--btn-azul); }
        .btn-orange { background-color: var(--btn-naranja); }
        .btn-red { background-color: var(--btn-rojo); }
        .btn-purple { background-color: #673ab7; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 550px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); overflow: hidden; }
        .modal-header { background: var(--imss-verde); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 16px; }
        .close-btn { background: none; border: none; color: white; font-size: 18px; cursor: pointer; }
        .modal-body { padding: 20px; max-height: 75vh; overflow-y: auto; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 4px; font-weight: bold; font-size: 11px; color: #637381; }
        .form-group input[type="text"] { width: 100%; padding: 7px 10px; box-sizing: border-box; border: 1px solid var(--borde); border-radius: 4px; font-size: 12px; text-transform: uppercase; }
        .form-group input[type="date"], .form-group select { width: 100%; padding: 7px 10px; box-sizing: border-box; border: 1px solid var(--borde); border-radius: 4px; font-size: 12px; }
        .modal-footer { padding: 15px 20px; background: #f1f3f5; text-align: right; border-top: 1px solid var(--borde); }
    </style>
</head>
<body>
    <script>
        const clinicasData = <?php echo json_encode($lista_clinicas_modal); ?>;
        const clinicaAsignadaSesion = "<?php echo htmlspecialchars($clinica_asignada); ?>";
    </script>

    <div class="navbar">
        <div><i class="fa-solid fa-calendar-check"></i> <strong>Citas de la Clínica: <?php echo htmlspecialchars($clinica_asignada ?: 'Sin Asignar'); ?></strong></div>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver al Dashboard</a>
    </div>

    <div class="container">
        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevaCita()" style="font-weight: bold;">
                        <i class="fa-solid fa-calendar-plus"></i> Asignar Nueva Cita
                    </button>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo htmlspecialchars($url_pdf); ?>" target="_blank" class="btn btn-primary" title="Generar PDF con listado de pacientes de su clínica">
                        <i class="fa-solid fa-file-pdf"></i> Descargar Pacientes (PDF)
                    </a>
                    <a href="<?php echo htmlspecialchars($url_excel); ?>" class="btn btn-excel" title="Exportar citas de su clínica a Excel">
                        <i class="fa-solid fa-file-download"></i> Exportar a Excel
                    </a>
                </div>
            </div>

            <form id="filterForm" method="GET" class="panel-row filters-form" style="border-top: 1px solid var(--borde); padding-top: 12px;">
                <input type="hidden" name="filtro_fecha" id="filtroFechaInput" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <input type="hidden" name="order" value="<?php echo htmlspecialchars($order); ?>">
                
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <!-- NUEVO: FILTRO DE FECHA ESPECÍFICA -->
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label>Fecha Específica:</label>
                        <input type="date" name="fecha_especifica" id="fechaEspecifica" value="<?php echo htmlspecialchars($fecha_especifica); ?>" onchange="limpiarRangoYFiltroRapido(); submitFiltros();">
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label>Fecha Desde:</label>
                        <input type="date" name="fecha_inicio" id="fechaInicio" value="<?php echo htmlspecialchars($f_inicio); ?>" onchange="limpiarFechaEspecificaYRapido(); submitFiltros();">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label>Hasta:</label>
                        <input type="date" name="fecha_fin" id="fechaFin" value="<?php echo htmlspecialchars($f_fin); ?>" onchange="limpiarFechaEspecificaYRapido(); submitFiltros();">
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-weight: bold; font-size: 11px; color: #637381;">Búsqueda Rápida:</label>
                        <div style="display: flex; gap: 4px;">
                            <button type="button" class="btn <?php echo ($filtro_fecha === 'hoy') ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 7px 10px; font-size: 11px;" onclick="aplicarFiltroFecha('hoy')">Hoy</button>
                            <button type="button" class="btn <?php echo ($filtro_fecha === 'manana') ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 7px 10px; font-size: 11px;" onclick="aplicarFiltroFecha('manana')">Mañana</button>
                            <button type="button" class="btn <?php echo ($filtro_fecha === 'semana') ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 7px 10px; font-size: 11px;" onclick="aplicarFiltroFecha('semana')">Semana</button>
                            <button type="button" class="btn <?php echo ($filtro_fecha === 'mes') ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 7px 10px; font-size: 11px;" onclick="aplicarFiltroFecha('mes')">Mes</button>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label>Mostrar:</label>
                        <select name="limit" onchange="submitFiltros()">
                            <option value="25" <?php echo ($limit == 25) ? 'selected' : ''; ?>>25 registros</option>
                            <option value="50" <?php echo ($limit == 50) ? 'selected' : ''; ?>>50 registros</option>
                            <option value="100" <?php echo ($limit == 100) ? 'selected' : ''; ?>>100 registros</option>
                            <option value="all" <?php echo ($limit === 'all') ? 'selected' : ''; ?>>Todos</option>
                        </select>
                    </div>
                </div>
                <div>
                    <a href="citas_clinica.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-right"></i> Limpiar Filtros</a>
                </div>
            </form>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><a href="<?php echo obtenerUrlOrden('id_cita'); ?>">ID <?php echo obtenerIconoOrden('id_cita'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('no_expediente'); ?>">Expediente <?php echo obtenerIconoOrden('no_expediente'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('nombre_paciente'); ?>">Paciente <?php echo obtenerIconoOrden('nombre_paciente'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('curp'); ?>">CURP <?php echo obtenerIconoOrden('curp'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('fecha_cita'); ?>">Fecha Cita <?php echo obtenerIconoOrden('fecha_cita'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('hora_cita'); ?>">Hora <?php echo obtenerIconoOrden('hora_cita'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('tipo_clinica'); ?>">Tipo Consulta <?php echo obtenerIconoOrden('tipo_clinica'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('nombre_clinica'); ?>">Clínica <?php echo obtenerIconoOrden('nombre_clinica'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('telefono'); ?>">Teléfono <?php echo obtenerIconoOrden('telefono'); ?></a></th>
                        <th><a href="<?php echo obtenerUrlOrden('estatus'); ?>">Estatus <?php echo obtenerIconoOrden('estatus'); ?></a></th>
                        <th class="acciones-col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($citas) > 0): ?>
                        <?php foreach ($citas as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['id_cita'] ?? ''); ?></td>
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
                                    <a href="nota_medica.php?id_cita=<?php echo $c['id_cita']; ?>" class="btn-icon" style="background-color: var(--imss-verde);" title="Nota Médica y Receta">
                                        <i class="fa-solid fa-notes-medical"></i>
                                    </a>
                                    <a href="generar_receta.php?id_cita=<?php echo $c['id_cita']; ?>" target="_blank" class="btn-icon btn-purple" title="Generar Receta Médica">
                                        <i class="fa-solid fa-file-prescription"></i>
                                    </a>
                                    <a href="procesar_cita.php?id_cita=<?php echo $c['id_cita']; ?>" target="_blank" class="btn-icon btn-blue" title="Ver Comprobante PDF"><i class="fa-solid fa-file-pdf"></i></a>
                                    <button class="btn-icon btn-orange" onclick="abrirModalEditar(
                                        <?php echo $c['id_cita']; ?>, 
                                        '<?php echo htmlspecialchars($c['fecha_cita'] ?? ''); ?>', 
                                        '<?php echo htmlspecialchars($c['hora_cita'] ?? ''); ?>', 
                                        '<?php echo htmlspecialchars($c['nombre_clinica'] ?? ''); ?>',
                                        '<?php echo htmlspecialchars($c['pac_nombre'] ?? $c['nombre_paciente'] ?? ''); ?>',
                                        '<?php echo htmlspecialchars($c['pac_apaterno'] ?? ''); ?>',
                                        '<?php echo htmlspecialchars($c['pac_amaterno'] ?? ''); ?>',
                                        '<?php echo htmlspecialchars($c['pac_domicilio'] ?? ''); ?>',
                                        '<?php echo htmlspecialchars($c['pac_municipio'] ?? ''); ?>',
                                        '<?php echo htmlspecialchars($c['pac_colonia'] ?? ''); ?>',
                                        '<?php echo htmlspecialchars($c['no_expediente'] ?? ''); ?>'
                                    )" title="Modificar Cita"><i class="fa-solid fa-pen"></i></button>
                                    <button class="btn-icon btn-primary" onclick="abrirModalNuevaCitaPrellenada('<?php echo htmlspecialchars($c['id_paciente'] ?? ''); ?>', '<?php echo htmlspecialchars($c['nombre_clinica'] ?? ''); ?>', '<?php echo htmlspecialchars($c['tipo_clinica'] ?? ''); ?>')" title="Asignar Nueva Cita"><i class="fa-solid fa-calendar-plus"></i></button>
                                    <button class="btn-icon btn-red" onclick="eliminarCita(<?php echo $c['id_cita']; ?>)" title="Eliminar Cita"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="11" style="text-align: center; color: #637381; padding: 30px;">No se encontraron registros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL EDITAR CITA Y DATOS DEL PACIENTE -->
    <div id="modalEditarCita" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-calendar-pen"></i> Modificar Cita y Datos del Paciente</h3>
                <button class="close-btn" onclick="cerrarModalEditar()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="actualizar_citas_medico.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="edit_id_cita" name="id_cita">
                    <input type="hidden" id="edit_clinica_id" name="clinica_id">
                    <input type="hidden" id="edit_expediente" name="expediente">
                    <input type="hidden" id="edit_duracion" value="30">
                    <input type="hidden" id="edit_hora_inicio" value="07:00">
                    <input type="hidden" id="edit_hora_fin" value="14:00">
                    <input type="hidden" id="edit_hora_comida" value="">

                    <input type="hidden" name="filtro_fecha" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                    <input type="hidden" name="fecha_inicio" value="<?php echo htmlspecialchars($f_inicio); ?>">
                    <input type="hidden" name="fecha_fin" value="<?php echo htmlspecialchars($f_fin); ?>">
                    <input type="hidden" name="fecha_especifica" value="<?php echo htmlspecialchars($fecha_especifica); ?>">
                    <input type="hidden" name="limit" value="<?php echo htmlspecialchars($limit); ?>">
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <input type="hidden" name="order" value="<?php echo htmlspecialchars($order); ?>">

                    <fieldset style="border: 1px solid var(--borde); border-radius: 6px; padding: 10px; margin-bottom: 12px;">
                        <legend style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">Información del Paciente (Mayúsculas)</legend>
                        <div style="display: flex; gap: 10px;">
                            <div class="form-group" style="flex: 1;">
                                <label>Nombre(s):</label>
                                <input type="text" id="edit_nombre" name="nombre" required oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Apellido Paterno:</label>
                                <input type="text" id="edit_apaterno" name="apaterno" required oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Apellido Materno:</label>
                                <input type="text" id="edit_amaterno" name="amaterno" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <div class="form-group" style="flex: 2;">
                                <label>Domicilio:</label>
                                <input type="text" id="edit_domicilio" name="domicilio" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Municipio:</label>
                                <input type="text" id="edit_municipio" name="municipio" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Colonia:</label>
                                <input type="text" id="edit_colonia" name="colonia" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset style="border: 1px solid var(--borde); border-radius: 6px; padding: 10px;">
                        <legend style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">Programación de Cita</legend>
                        <div class="form-group">
                            <label>Fecha de la Cita:</label>
                            <input type="date" id="edit_fecha_cita" name="fecha_cita" required onchange="generarHorariosEdicionDinamicos()">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Hora de la Cita:</label>
                            <select id="edit_hora_cita" name="hora_cita" required>
                                <option value="">Cargando horarios...</option>
                            </select>
                        </div>
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL ASIGNAR NUEVA CITA -->
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
                                <option value="<?php echo $pac['id_paciente']; ?>"><?php echo htmlspecialchars($pac['nombre'] . ' ' . $pac['apaterno'] . ' (Exp: ' . $pac['expediente'] . ')'); ?></option>
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
                        <input type="date" name="fecha_cita" required value="<?php echo date('Y-m-d'); ?>" onchange="generarHorariosDinamicos()">
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
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Generar Cita</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function aplicarFiltroFecha(tipo) {
            document.getElementById('filtroFechaInput').value = tipo;
            document.getElementById('fechaInicio').value = '';
            document.getElementById('fechaFin').value = '';
            document.getElementById('fechaEspecifica').value = '';
            submitFiltros();
        }

        function limpiarRangoYFiltroRapido() {
            document.getElementById('fechaInicio').value = '';
            document.getElementById('fechaFin').value = '';
            document.getElementById('filtroFechaInput').value = '';
        }

        function limpiarFechaEspecificaYRapido() {
            document.getElementById('fechaEspecifica').value = '';
            document.getElementById('filtroFechaInput').value = '';
        }

        function submitFiltros() { document.getElementById('filterForm').submit(); }

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

            selectElement.innerHTML = '<option value="">Seleccione hora...</option>';
            let horariosDisponiblesCount = 0;

            while (currentMinutes <= endMinutes) {
                let timeFormatted = minutesToTime(currentMinutes);
                let enComida = false;
                
                if (comidaMinutes !== -1 && Math.abs(currentMinutes - comidaMinutes) < duracionMinutos) {
                    enComida = true;
                }

                let estaOcupado = horasOcupadas.includes(timeFormatted);
                let esLaActual = (horaActualPreseleccionada && timeFormatted === horaActualPreseleccionada);

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

            if (horaActualPreseleccionada) {
                selectElement.value = horaActualPreseleccionada;
            }

            if (horariosDisponiblesCount === 0 && selectElement.options.length <= 1) {
                selectElement.innerHTML = '<option value="">No hay horarios disponibles</option>';
            }
        }

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
                
                if (cli.nombre_clinica === clinicaAsignadaSesion) {
                    opt.selected = true;
                }
                
                clinicaSelect.appendChild(opt);
            });

            if (preservarClinicaId) {
                clinicaSelect.value = preservarClinicaId;
            }

            if (clinicaSelect.value) {
                generarHorariosDinamicos();
            }
        }

        async function generarHorariosDinamicos() {
            const clinicaSelect = document.getElementById('modalClinica');
            const fechaInput = document.querySelector('#modalNuevaCita input[name="fecha_cita"]');
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

            let horasOcupadas = [];
            try {
                let response = await fetch(window.location.pathname + `?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaCita}`);
                horasOcupadas = await response.json();
            } catch (e) {
                console.error(e);
            }

            let duracionMinutos = parseInt(clinicaInfo.duracion_cita || clinicaInfo.duracion_consulta || 30);
            let horaInicioStr = clinicaInfo.hora_inicio || '07:00';
            let horaFinStr = clinicaInfo.hora_fin || '14:00';
            let horaComida = clinicaInfo.hora_comida || '';

            poblarSelectHorarios(horaSelect, horaInicioStr, horaFinStr, duracionMinutos, horaComida, horasOcupadas);
        }

        function abrirModalNuevaCita() {
            document.getElementById('modalTipoConsulta').value = '';
            document.getElementById('modalClinica').innerHTML = '<option value="">Seleccione primero el tipo de consulta...</option>';
            document.getElementById('modalHoraCita').innerHTML = '<option value="">Seleccione clínica primero...</option>';
            
            if (clinicaAsignadaSesion) {
                let cliInfo = clinicasData.find(c => c.nombre_clinica === clinicaAsignadaSesion);
                if (cliInfo && cliInfo.tipo_clinica) {
                    document.getElementById('modalTipoConsulta').value = cliInfo.tipo_clinica;
                    filtrarClinicasModal(cliInfo.id_clinica);
                }
            }

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

        async function generarHorariosEdicionDinamicos() {
            const idCita = document.getElementById('edit_id_cita').value;
            const clinicaId = document.getElementById('edit_clinica_id').value;
            const fechaCita = document.getElementById('edit_fecha_cita').value;
            const horaSelect = document.getElementById('edit_hora_cita');
            
            let horaActual = horaSelect.getAttribute('data-hora-actual') || '';

            horaSelect.innerHTML = '<option value="">Cargando horarios...</option>';

            if (!clinicaId || !fechaCita) return;

            let horasOcupadas = [];
            try {
                let response = await fetch(window.location.pathname + `?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaCita}&id_cita_excluir=${idCita}`);
                horasOcupadas = await response.json();
            } catch (e) {
                console.error(e);
            }

            let horaInicioStr = document.getElementById('edit_hora_inicio').value;
            let horaFinStr = document.getElementById('edit_hora_fin').value;
            let duracionMinutos = parseInt(document.getElementById('edit_duracion').value);
            let horaComida = document.getElementById('edit_hora_comida').value;

            poblarSelectHorarios(horaSelect, horaInicioStr, horaFinStr, duracionMinutos, horaComida, horasOcupadas, horaActual);
        }

        async function abrirModalEditar(idCita, fecha, hora, nombreClinica, nombre, apaterno, amaterno, domicilio, municipio, colonia, expediente) {
            document.getElementById('edit_id_cita').value = idCita;
            document.getElementById('edit_fecha_cita').value = fecha;
            document.getElementById('edit_expediente').value = expediente;

            if (!apaterno && nombre) {
                let partes = nombre.trim().split(' ');
                if (partes.length >= 3) {
                    document.getElementById('edit_nombre').value = partes.slice(0, partes.length - 2).join(' ').toUpperCase();
                    document.getElementById('edit_apaterno').value = partes[partes.length - 2].toUpperCase();
                    document.getElementById('edit_amaterno').value = partes[partes.length - 1].toUpperCase();
                } else if (partes.length === 2) {
                    document.getElementById('edit_nombre').value = partes[0].toUpperCase();
                    document.getElementById('edit_apaterno').value = partes[1].toUpperCase();
                    document.getElementById('edit_amaterno').value = '';
                } else {
                    document.getElementById('edit_nombre').value = nombre.toUpperCase();
                    document.getElementById('edit_apaterno').value = '';
                    document.getElementById('edit_amaterno').value = '';
                }
            } else {
                document.getElementById('edit_nombre').value = (nombre || '').toUpperCase();
                document.getElementById('edit_apaterno').value = (apaterno || '').toUpperCase();
                document.getElementById('edit_amaterno').value = (amaterno || '').toUpperCase();
            }

            document.getElementById('edit_domicilio').value = (domicilio || '').toUpperCase();
            document.getElementById('edit_municipio').value = (municipio || '').toUpperCase();
            document.getElementById('edit_colonia').value = (colonia || '').toUpperCase();

            let clinicaInfo = clinicasData.find(c => c.nombre_clinica === nombreClinica);
            
            if(clinicaInfo) {
                document.getElementById('edit_clinica_id').value = clinicaInfo.id_clinica;
                document.getElementById('edit_duracion').value = parseInt(clinicaInfo.duracion_cita || clinicaInfo.duracion_consulta || 30);
                document.getElementById('edit_hora_inicio').value = clinicaInfo.hora_inicio || '07:00';
                document.getElementById('edit_hora_fin').value = clinicaInfo.hora_fin || '14:00';
                document.getElementById('edit_hora_comida').value = clinicaInfo.hora_comida || '';
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
            if (confirm('¿Está seguro de eliminar esta cita?')) {
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('accion', 'eliminar');
                urlParams.set('id', idCita);
                window.location.href = 'citas_clinica.php?' + urlParams.toString();
            }
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('modalEditarCita')) cerrarModalEditar();
            if (event.target == document.getElementById('modalNuevaCita')) cerrarModalNuevaCita();
        }
    </script>
</body>
</html>