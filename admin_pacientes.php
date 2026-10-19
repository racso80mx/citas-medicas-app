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

// ===================================================================
// 2. RECEPCIÓN DE PARÁMETROS Y LÍMITE ESTRICTO
// ===================================================================
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$search = trim($_GET['search'] ?? '');
$estatus_filtro = $_GET['estatus'] ?? '';
$accion = $_GET['accion'] ?? '';

// Selector estricto de cantidad de registros por página (Respeta exactamente lo que el usuario elija)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if (!in_array($limit, [10, 25, 50, 100, 500])) {
    $limit = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Parámetros de ordenamiento por defecto
$sort_by = $_GET['sort_by'] ?? 'id_paciente';
$order = $_GET['order'] ?? 'DESC';

$valid_columns = ['id_paciente', 'expediente', 'nombre', 'apaterno', 'amaterno', 'curp', 'sexo', 'edad', 'telefono', 'estatus'];
if (!in_array($sort_by, $valid_columns)) {
    $sort_by = 'id_paciente';
}

$order = ($order === 'ASC') ? 'ASC' : 'DESC';
$next_order = ($order === 'ASC') ? 'DESC' : 'ASC';

// ===================================================================
// 3. CONSTRUCCIÓN DINÁMICA DE LA CONSULTA
// ===================================================================
$where_clauses = [];
$params = [];

if (!empty($desde) && !empty($hasta)) {
    $where_clauses[] = "fecha_registro BETWEEN :desde AND :hasta";
    $params['desde'] = $desde;
    $params['hasta'] = $hasta;
}

if (!empty($search)) {
    $search_term = '%' . trim($search) . '%';
    $where_clauses[] = "CONCAT_WS(' ', COALESCE(nombre,''), COALESCE(apaterno,''), COALESCE(amaterno,''), COALESCE(curp,''), COALESCE(expediente,'')) ILIKE :search";
    $params['search'] = $search_term;
}

if (!empty($estatus_filtro)) {
    $where_clauses[] = "TRIM(estatus) ILIKE :estatus";
    $params['estatus'] = "%$estatus_filtro%";
} else {
    $where_clauses[] = "(TRIM(estatus) ILIKE 'Vigente' OR estatus IS NULL OR TRIM(estatus) = '' OR TRIM(estatus) ILIKE 'NULL')";
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// ===================================================================
// 4. EJECUCIÓN DE CONSULTAS Y CARGA DE CLÍNICAS
// ===================================================================
try {
    // Si se solicita exportar a Excel
    if ($accion === 'exportar_excel') {
        $sql_export = "SELECT id_paciente, expediente, nombre, apaterno, amaterno, curp, sexo, edad, telefono, estatus, fecha_registro FROM pacientes $where_sql ORDER BY $sort_by $order";
        $stmt_export = $pdo->prepare($sql_export);
        $stmt_export->execute($params);
        $datos_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=catalogo_pacientes_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['ID', 'Expediente', 'Nombre', 'Apellido Paterno', 'Apellido Materno', 'CURP', 'Sexo', 'Edad', 'Teléfono', 'Estatus', 'Fecha Registro']);
        
        foreach ($datos_export as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    }

    $sql_count = "SELECT COUNT(*) FROM pacientes $where_sql";
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    if ($total_pages == 0) $total_pages = 1;

    $sql = "SELECT * FROM pacientes $where_sql ORDER BY $sort_by $order LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $val) {
        $stmt->bindValue(":$key", $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener TODAS las clínicas para filtrarlas instantáneamente por JavaScript sin errores AJAX
    $stmt_todas_clinicas = $pdo->query("SELECT id_clinica, nombre_clinica, tipo_clinica FROM clinicas ORDER BY nombre_clinica ASC");
    $todas_clinicas = $stmt_todas_clinicas->fetchAll(PDO::FETCH_ASSOC);

    // Obtener lista única de Tipos de Clínica
    $tipos_clinicas = array_unique(array_column($todas_clinicas, 'tipo_clinica'));
    sort($tipos_clinicas);

} catch (Exception $e) {
    echo "<div style='background: #ffebee; color: #c62828; padding: 15px; margin: 20px; border-radius: 5px; font-family: monospace;'><strong>Error SQL:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    $pacientes = [];
    $total_pages = 1;
    $todas_clinicas = [];
    $tipos_clinicas = [];
}

function buildUrl($params_to_change) {
    $current_params = $_GET;
    foreach ($params_to_change as $key => $value) {
        if ($value === null) {
            unset($current_params[$key]);
        } else {
            $current_params[$key] = $value;
        }
    }
    return '?' . http_build_query($current_params);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Pacientes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --excel-verde: #217346; 
            --btn-azul: #1976d2; --btn-naranja: #f57c00; --btn-rojo: #d32f2f;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        
        .navbar { background-color: var(--imss-dorado); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 1400px; margin: 25px auto; padding: 0 20px; }
        
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }
        
        input[type="text"], input[type="date"], select { padding: 7px 10px; border: 1px solid var(--borde); border-radius: 4px; font-size: 13px; outline: none; box-sizing: border-box; }
        input[type="text"]:focus, select:focus { border-color: var(--imss-verde); }
        
        .btn { padding: 7px 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; transition: opacity 0.2s;}
        .btn:hover { opacity: 0.9; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-secondary { background-color: #637381; }
        
        .btn-estatus { padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; border: 1px solid var(--borde); background: #fff; color: #637381; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
        .btn-estatus.active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); }
        
        .btn-icon { width: 32px; height: 32px; border: none; border-radius: 6px; cursor: pointer; color: white; display: inline-flex; justify-content: center; align-items: center; margin: 0 2px; text-decoration: none; }
        .btn-blue { background-color: var(--btn-azul); }
        .btn-orange { background-color: var(--btn-naranja); }
        .btn-red { background-color: var(--btn-rojo); }

        .import-panel-toggle { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: bold; color: var(--imss-verde); cursor: pointer; }
        .import-box { display: none; background: #f8f9fa; padding: 10px 15px; border-radius: 6px; border: 1px dashed var(--imss-verde); width: 100%; align-items: center; gap: 15px; flex-wrap: wrap; box-sizing: border-box; margin-top: 5px; }
        .import-box.show { display: flex; }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); white-space: nowrap; }
        th a { color: #637381; text-decoration: none; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        th a:hover { color: var(--imss-verde); }
        th i { font-size: 11px; opacity: 0.5; }
        th a.active i { opacity: 1; color: var(--imss-verde); }
        td { padding: 10px; border-bottom: 1px solid var(--borde); white-space: nowrap; vertical-align: middle;}
        tr:hover { background-color: #f8f9fa; }
        .acciones-col { text-align: center; }

        .pagination { display: flex; justify-content: center; align-items: center; gap: 5px; margin-top: 20px; padding: 10px; }
        .pagination a, .pagination span { padding: 6px 12px; border-radius: 4px; font-size: 13px; text-decoration: none; border: 1px solid var(--borde); background: white; color: #1c252e; }
        .pagination a:hover { background: #f1f3f5; }
        .pagination .active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 480px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); overflow: hidden; }
        .modal-header { background: var(--imss-verde); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 16px; }
        .close-btn { background: none; border: none; color: white; font-size: 18px; cursor: pointer; }
        .modal-body { padding: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 12px; color: #637381; }
        .form-group input, .form-group select { width: 100%; box-sizing: border-box; }
        .modal-footer { padding: 15px 20px; background: #f1f3f5; text-align: right; border-top: 1px solid var(--borde); }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-users"></i> <strong>Catálogo de Pacientes</strong></div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver a Catálogos</a>
    </div>

    <div class="container">
        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <label class="import-panel-toggle">
                    <input type="checkbox" id="toggleImport" onchange="toggleImportBox()" style="cursor: pointer;"> 
                    <i class="fa-solid fa-file-excel"></i> Mostrar herramientas de importación (Plantilla y Subir)
                </label>
                <a href="<?= buildUrl(['accion' => 'exportar_excel']) ?>" class="btn btn-excel">
                    <i class="fa-solid fa-file-arrow-down"></i> Descargar a Excel
                </a>
            </div>

            <div id="importBox" class="import-box">
                <a href="descargar_plantilla_pacientes.php" class="btn btn-excel"><i class="fa-solid fa-file-arrow-down"></i> Descargar Plantilla</a>
                <form action="importar_pacientes.php" method="POST" enctype="multipart/form-data" style="display: inline-flex; gap: 8px; align-items: center; margin: 0;">
                    <input type="file" name="archivo_excel" accept=".xlsx, .xls, .csv" required style="font-size: 12px;">
                    <button type="submit" class="btn btn-excel"><i class="fa-solid fa-upload"></i> Subir a BD</button>
                </form>
            </div>

            <form id="searchForm" method="GET" action="admin_pacientes.php" style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 12px; margin-top: 5px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; flex: 1;">
                    <div style="display: flex; gap: 5px;">
                        <div style="position: relative; width: 220px;">
                            <!-- MODIFICADO: Se agregó style="text-transform: uppercase;" y oninput para forzar mayúsculas -->
                            <input type="text" id="searchInput" name="search" placeholder="NOMBRE, APELLIDOS, CURP..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding-left: 28px; text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();" autocomplete="off">
                            <i class="fa-solid fa-search" style="position: absolute; left: 9px; top: 10px; color: #637381; font-size: 12px;"></i>
                        </div>
                        <button type="submit" class="btn btn-primary" title="Buscar"><i class="fa-solid fa-search"></i></button>
                    </div>

                    <input type="hidden" name="estatus" id="inputEstatus" value="<?= htmlspecialchars($estatus_filtro) ?>">
                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="btn-estatus <?= $estatus_filtro === 'Vigente' ? 'active' : '' ?>" onclick="filtrarEstatus('Vigente')">
                            <i class="fa-solid fa-check-circle"></i> Vigentes
                        </button>
                        <button type="button" class="btn-estatus <?= $estatus_filtro === 'Baja' ? 'active' : '' ?>" onclick="filtrarEstatus('Baja')">
                            <i class="fa-solid fa-ban"></i> De Baja
                        </button>
                    </div>

                    <?php if(!empty($estatus_filtro) || !empty($search)): ?>
                        <a href="admin_pacientes.php" class="btn btn-secondary" style="padding: 6px 10px;" title="Limpiar filtros"><i class="fa-solid fa-eraser"></i></a>
                    <?php endif; ?>
                </div>

                <div style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #637381;">
                    <span>Mostrar:</span>
                    <select name="limit" onchange="document.getElementById('searchForm').submit()" style="padding: 5px;">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    <span>registros</span>
                </div>
            </form>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'id_paciente', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='id_paciente' ? 'active' : '' ?>">
                                ID <i class="fa-solid fa-sort<?= $sort_by=='id_paciente' ? ($order=='ASC'?'-up':'-down') : '' ?>"></i>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'expediente', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='expediente' ? 'active' : '' ?>">
                                Expediente <i class="fa-solid fa-sort<?= $sort_by=='expediente' ? ($order=='ASC'?'-up':'-down') : '' ?>"></i>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'nombre', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='nombre' ? 'active' : '' ?>">
                                Nombre <i class="fa-solid fa-sort<?= $sort_by=='nombre' ? ($order=='ASC'?'-up':'-down') : '' ?>"></i>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'apaterno', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='apaterno' ? 'active' : '' ?>">
                                Apellido Paterno <i class="fa-solid fa-sort<?= $sort_by=='apaterno' ? ($order=='ASC'?'-up':'-down') : '' ?>"></i>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'amaterno', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='amaterno' ? 'active' : '' ?>">
                                Apellido Materno <i class="fa-solid fa-sort<?= $sort_by=='amaterno' ? ($order=='ASC'?'-up':'-down') : '' ?>"></i>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'curp', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='curp' ? 'active' : '' ?>">
                                CURP <i class="fa-solid fa-sort<?= $sort_by=='curp' ? ($order=='ASC'?'-up':'-down') : '' ?>"></i>
                            </a>
                        </th>
                        <th>Sexo</th>
                        <th>Teléfono</th>
                        <th>Estatus</th>
                        <th class="acciones-col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pacientes) > 0): ?>
                        <?php foreach ($pacientes as $p): 
                            $id_paciente = htmlspecialchars($p['id_paciente'] ?? $p['id'] ?? '');
                            $nombre_completo_modal = htmlspecialchars(trim(($p['nombre'] ?? '') . ' ' . ($p['apaterno'] ?? '') . ' ' . ($p['amaterno'] ?? '')));
                        ?>
                            <tr>
                                <td><?php echo $id_paciente; ?></td>
                                <td><strong><?php echo htmlspecialchars(str_replace('"', '', $p['expediente'] ?? '')); ?></strong></td>
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['nombre'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['apaterno'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['amaterno'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['curp'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['sexo'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['telefono'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['estatus'] ?? '')); ?></td>
                                <td class="acciones-col">
                                    <button class="btn-icon btn-blue" onclick="abrirModalCita('<?= $id_paciente ?>', '<?= addslashes($nombre_completo_modal) ?>')" title="Asignar Cita">
                                        <i class="fa-solid fa-calendar-days"></i>
                                    </button>
                                    <a href="editar_paciente.php?id=<?= $id_paciente ?>" class="btn-icon btn-orange" title="Editar">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <button class="btn-icon btn-red" onclick="eliminarPaciente('<?= $id_paciente ?>')" title="Eliminar">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" style="text-align: center; padding: 30px; color: #637381;">No se encontraron registros de pacientes.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= buildUrl(['page' => $page - 1]) ?>"><i class="fa-solid fa-chevron-left"></i> Anterior</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= buildUrl(['page' => $i]) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?elseif ($i == $page - 3 || $i == $page + 3): ?>
                        <span>...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= buildUrl(['page' => $page + 1]) ?>">Siguiente <i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal para Asignar Cita -->
    <div id="modalCita" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-calendar-check"></i> Asignar Cita Médica</h3>
                <button class="close-btn" onclick="cerrarModalCita()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="procesar_cita.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="cita_paciente_id" name="paciente_id">
                    
                    <div class="form-group">
                        <label>Paciente:</label>
                        <input type="text" id="cita_paciente_nombre" readonly style="background-color: #f1f3f5; border: none;">
                    </div>
                    
                    <div class="form-group">
                        <label>Tipo de Clínica:</label>
                        <select id="select_tipo_clinica" name="tipo_clinica" required onchange="filtrarClinicasPorTipo()">
                            <option value="">Seleccione tipo de clínica...</option>
                            <?php foreach ($tipos_clinicas as $tipo): ?>
                                <option value="<?= htmlspecialchars($tipo) ?>"><?= htmlspecialchars($tipo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Clínica:</label>
                        <select id="select_clinica" name="clinica_id" required onchange="cargarHorariosDisponibles()">
                            <option value="">Primero seleccione tipo de clínica...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Fecha de la Cita:</label>
                        <input type="date" id="input_fecha" name="fecha_cita" required onchange="cargarHorariosDisponibles()">
                    </div>

                    <div class="form-group">
                        <label>Horarios Disponibles:</label>
                        <select id="select_hora" name="hora_cita" required>
                            <option value="">Primero seleccione clínica y fecha...</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Observaciones (Opcional):</label>
                        <input type="text" name="observaciones" placeholder="Ej. Presentar análisis previos">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalCita()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Cita</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Arreglo global con todas las clínicas inyectadas de forma segura desde PHP
        const todasLasClinicas = <?= json_encode($todas_clinicas) ?>;

        function toggleImportBox() {
            const box = document.getElementById('importBox');
            const checkbox = document.getElementById('toggleImport');
            if (checkbox.checked) {
                box.classList.add('show');
            } else {
                box.classList.remove('show');
            }
        }

        function filtrarEstatus(estatus) {
            const inputEstatus = document.getElementById('inputEstatus');
            if (inputEstatus.value === estatus) {
                inputEstatus.value = '';
            } else {
                inputEstatus.value = estatus;
            }
            document.getElementById('searchForm').submit();
        }

        function abrirModalCita(id, nombre) {
            document.getElementById('cita_paciente_id').value = id;
            document.getElementById('cita_paciente_nombre').value = nombre;
            document.getElementById('modalCita').style.display = 'flex';
        }

        function cerrarModalCita() {
            document.getElementById('modalCita').style.display = 'none';
        }

        // Función JavaScript para filtrar el selector de clínicas instantáneamente sin dependencias externas
        function filtrarClinicasPorTipo() {
            const tipoSeleccionado = document.getElementById('select_tipo_clinica').value;
            const selectClinica = document.getElementById('select_clinica');

            selectClinica.innerHTML = '<option value="">Seleccione una clínica...</option>';

            if (!tipoSeleccionado) {
                selectClinica.innerHTML = '<option value="">Primero seleccione tipo de clínica...</option>';
                return;
            }

            const clinicasFiltradas = todasLasClinicas.filter(c => c.tipo_clinica === tipoSeleccionado);

            if (clinicasFiltradas.length > 0) {
                clinicasFiltradas.forEach(c => {
                    let opt = document.createElement('option');
                    opt.value = c.id_clinica;
                    opt.textContent = c.nombre_clinica;
                    selectClinica.appendChild(opt);
                });
            } else {
                selectClinica.innerHTML = '<option value="">No hay clínicas disponibles para este tipo</option>';
            }
        }

        function cargarHorariosDisponibles() {
            const clinicaId = document.getElementById('select_clinica').value;
            const fecha = document.getElementById('input_fecha').value;
            const selectHora = document.getElementById('select_hora');

            if (!clinicaId || !fecha) {
                selectHora.innerHTML = '<option value="">Complete clínica y fecha para ver horarios...</option>';
                return;
            }

            selectHora.innerHTML = '<option value="">Cargando horarios...</option>';
            
            setTimeout(() => {
                selectHora.innerHTML = '<option value="">Seleccione una hora...</option>';
                const horarios = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00'];
                horarios.forEach(h => {
                    let opt = document.createElement('option');
                    opt.value = h;
                    opt.textContent = h + ' hrs';
                    selectHora.appendChild(opt);
                });
            }, 200);
        }

        function eliminarPaciente(id) {
            if(confirm('¿Está seguro de eliminar este paciente?')) {
                window.location.href = 'eliminar_paciente.php?id=' + id;
            }
        }

        window.onclick = function(event) {
            var modal = document.getElementById('modalCita');
            if (event.target == modal) {
                cerrarModalCita();
            }
        }
    </script>
</body>
</html>