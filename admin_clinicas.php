<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN
// ===================================================================
session_start();
$es_admin = ($_SESSION['rol_id'] == 1);
$permisos = $_SESSION['permisos'] ?? [];

if (!isset($_SESSION['usuario_id']) || (!$es_admin && !in_array('admin_clinicas', $permisos))) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

// ===================================================================
// 2. PROCESAR ACCIONES DE CLÍNICAS
// ===================================================================
if (isset($_GET['action']) && $_GET['action'] == 'eliminar' && !empty($_GET['id'])) {
    $stmt_del = $pdo->prepare("DELETE FROM clinicas WHERE id_clinica = :id");
    $stmt_del->execute(['id' => $_GET['id']]);
    header("Location: admin_clinicas.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_clinica'])) {
    $id_clinica        = $_POST['id_clinica'] ?? '';
    $nombre_clinica    = TRIM($_POST['nombre_clinica']);
    $tipo_clinica      = TRIM($_POST['tipo_clinica']);
    $turno             = TRIM($_POST['turno']);
    $hora_inicio       = TRIM($_POST['hora_inicio']);
    $hora_fin          = TRIM($_POST['hora_fin']);
    $nombre_medico     = TRIM($_POST['nombre_medico']);
    $duracion_consulta = TRIM($_POST['duracion_consulta']);
    $hora_comida       = TRIM($_POST['hora_comida']);
    $fin_semana        = TRIM($_POST['fin_semana']);
    $dia_inactivo      = TRIM($_POST['dia_inactivo'] ?? '');

    if (!empty($id_clinica)) {
        $sql = "UPDATE clinicas SET nombre_clinica = :nombre, tipo_clinica = :tipo, turno = :turno, hora_inicio = :h_inicio, hora_fin = :h_fin, nombre_medico = :medico, duracion_consulta = :duracion, hora_comida = :h_comida, fin_semana = :fin_sem, dia_inactivo = :dia_inactivo WHERE id_clinica = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['nombre' => $nombre_clinica, 'tipo' => $tipo_clinica, 'turno' => $turno, 'h_inicio' => $hora_inicio, 'h_fin' => $hora_fin, 'medico' => $nombre_medico, 'duracion' => $duracion_consulta, 'h_comida' => $hora_comida, 'fin_sem' => $fin_semana, 'dia_inactivo' => !empty($dia_inactivo) ? $dia_inactivo : null, 'id' => $id_clinica]);
    } else {
        $sql = "INSERT INTO clinicas (nombre_clinica, tipo_clinica, turno, hora_inicio, hora_fin, nombre_medico, duracion_consulta, hora_comida, fin_semana, dia_inactivo) VALUES (:nombre, :tipo, :turno, :h_inicio, :h_fin, :medico, :duracion, :h_comida, :fin_sem, :dia_inactivo)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['nombre' => $nombre_clinica, 'tipo' => $tipo_clinica, 'turno' => $turno, 'h_inicio' => $hora_inicio, 'h_fin' => $hora_fin, 'medico' => $nombre_medico, 'duracion' => $duracion_consulta, 'h_comida' => $hora_comida, 'fin_sem' => $fin_semana, 'dia_inactivo' => !empty($dia_inactivo) ? $dia_inactivo : null]);
    }
    header("Location: admin_clinicas.php");
    exit();
}

// ===================================================================
// 3. PROCESAR ACCIONES DE EXCEPCIONES (SOPORTE AJAX Y NORMAL)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_excepcion'])) {
    $id_clinica  = $_POST['exc_id_clinica'];
    $fecha       = $_POST['exc_fecha'];
    $motivo      = !empty(TRIM($_POST['exc_motivo'])) ? TRIM($_POST['exc_motivo']) : 'Permiso';
    $todo_el_dia = 1; 
    $hora_inicio = null;
    $hora_fin    = null;

    $sql = "INSERT INTO clinica_excepciones (id_clinica, fecha, motivo, todo_el_dia, hora_inicio, hora_fin) VALUES (:id, :fecha, :motivo, :todo, :h_inicio, :h_fin)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id_clinica, 'fecha' => $fecha, 'motivo' => $motivo, 'todo' => $todo_el_dia, 'h_inicio' => $hora_inicio, 'h_fin' => $hora_fin]);
    
    // Si la petición es AJAX, respondemos con éxito en JSON sin recargar
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        // Consultar lista actualizada de excepciones para esta clínica
        $stmt_exc = $pdo->prepare("SELECT id_clinica, id_excepcion, fecha, motivo, todo_el_dia, hora_inicio, hora_fin FROM clinica_excepciones WHERE id_clinica = :id ORDER BY fecha ASC");
        $stmt_exc->execute(['id' => $id_clinica]);
        echo json_encode(['success' => true, 'excepciones' => $stmt_exc->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    header("Location: admin_clinicas.php?abrir_excepciones=" . $id_clinica);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'eliminar_excepcion' && !empty($_GET['id_exc'])) {
    $id_clinica_redir = $_GET['id_clinica_retorno'] ?? '';
    $id_exc = $_GET['id_exc'];
    
    $stmt = $pdo->prepare("DELETE FROM clinica_excepciones WHERE id_excepcion = :id");
    $stmt->execute(['id' => $id_exc]);

    // Soporte para eliminación por AJAX desde el modal
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        $stmt_exc = $pdo->prepare("SELECT id_clinica, id_excepcion, fecha, motivo, todo_el_dia, hora_inicio, hora_fin FROM clinica_excepciones WHERE id_clinica = :id ORDER BY fecha ASC");
        $stmt_exc->execute(['id' => $id_clinica_redir]);
        echo json_encode(['success' => true, 'excepciones' => $stmt_exc->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }
    
    $redireccion = "admin_clinicas.php";
    if (!empty($id_clinica_redir)) {
        $redireccion .= "?abrir_excepciones=" . $id_clinica_redir;
    }
    header("Location: " . $redireccion);
    exit();
}

// ===================================================================
// 4. CARGAR CATÁLOGOS
// ===================================================================
$cat_tipos = [];
try {
    $cat_tipos = $pdo->query("SELECT * FROM tipo_clinica ORDER BY 1 ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $cat_tipos = $pdo->query("SELECT DISTINCT tipo_clinica FROM clinicas WHERE tipo_clinica IS NOT NULL ORDER BY 1 ASC")->fetchAll(PDO::FETCH_COLUMN);
}

$cat_turnos = [];
try {
    $cat_turnos = $pdo->query("SELECT * FROM turnos ORDER BY 1 ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $cat_turnos = $pdo->query("SELECT DISTINCT turno FROM clinicas WHERE turno IS NOT NULL ORDER BY 1 ASC")->fetchAll(PDO::FETCH_COLUMN);
}

$cat_dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

$cat_medicos = [];
try {
    $stmt_m = $pdo->query("SELECT * FROM medicos");
    while ($m = $stmt_m->fetch(PDO::FETCH_ASSOC)) {
        $nombre = trim($m['nombre'] ?? $m['nombres'] ?? $m['nombre_medico'] ?? '');
        $apaterno = trim($m['apaterno'] ?? $m['apellido_paterno'] ?? '');
        $amaterno = trim($m['amaterno'] ?? $m['apellido_materno'] ?? '');
        $cedula = trim($m['cedula'] ?? $m['cedula_profesional'] ?? '');

        $nombre_completo = trim("$nombre $apaterno $amaterno");
        if (!empty($nombre_completo)) {
            $texto_cedula = !empty($cedula) ? " - Cédula: $cedula" : "";
            $cat_medicos[] = mb_strtoupper("$nombre_completo$texto_cedula", 'UTF-8');
        }
    }
} catch (Exception $e) {
    try {
        $stmt_m = $pdo->query("SELECT * FROM medico");
        while ($m = $stmt_m->fetch(PDO::FETCH_ASSOC)) {
            $nombre = trim($m['nombre'] ?? $m['nombre_medico'] ?? '');
            $cedula = trim($m['cedula'] ?? '');
            if (!empty($nombre)) {
                $texto_cedula = !empty($cedula) ? " - Cédula: $cedula" : "";
                $cat_medicos[] = mb_strtoupper("$nombre$texto_cedula", 'UTF-8');
            }
        }
    } catch (Exception $ex) {
        $cat_medicos = [];
    }
}
$cat_medicos = array_unique($cat_medicos);
sort($cat_medicos);

// ===================================================================
// 5. ORDENAMIENTO Y CONSULTAS
// ===================================================================
$allowed_cols = ['id_clinica', 'nombre_clinica', 'tipo_clinica', 'turno', 'hora_inicio', 'hora_fin', 'nombre_medico', 'duracion_consulta', 'hora_comida', 'dia_inactivo'];
$sort = $_GET['sort'] ?? 'id_clinica';
$order = isset($_GET['order']) && strtolower($_GET['order']) == 'asc' ? 'ASC' : 'DESC';

if (!in_array($sort, $allowed_cols)) {
    $sort = 'id_clinica';
}

$next_order = ($order == 'ASC') ? 'desc' : 'asc';
$clinicas = $pdo->query("SELECT * FROM clinicas ORDER BY $sort $order")->fetchAll();

$raw_excepciones = $pdo->query("SELECT id_clinica, id_excepcion, fecha, motivo, todo_el_dia, hora_inicio, hora_fin FROM clinica_excepciones ORDER BY fecha ASC")->fetchAll(PDO::FETCH_ASSOC);
$excepciones_crud = [];
foreach ($raw_excepciones as $exc) {
    $excepciones_crud[$exc['id_clinica']][] = $exc;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Clínicas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --borde: #dfe3e8; --excel-verde: #217346; --rojo: #d32f2f; --azul: #1976d2; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-dorado); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .container { max-width: 98%; margin: 20px auto; padding: 0 10px; }
        
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-info { background-color: var(--azul); }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-danger { background-color: var(--rojo); }
        .btn-warning { background-color: #ff9800; color: #fff; }
        
        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); color: #637381; white-space: nowrap; }
        th a { color: #637381; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        td { padding: 10px; border-bottom: 1px solid var(--borde); white-space: nowrap; }
        tr:hover { background-color: #f8f9fa; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 25px; border-radius: 8px; width: 100%; max-width: 650px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: bold; color: #637381; }
        .form-group input, .form-group select { padding: 8px; border: 1px solid var(--borde); border-radius: 4px; outline: none; background: #fff; }
        .exc-table th { font-size: 11px; padding: 8px; }
        .exc-table td { font-size: 12px; padding: 8px; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-hospital"></i> <strong>Catálogo y Mantenimiento de Clínicas</strong></div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver a Catálogos</a>
    </div>

    <div class="container">
        <div class="control-panel">
            <button onclick="abrirModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nueva Clínica</button>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Nombre Clínica</th>
                        <th>Tipo Clínica</th>
                        <th>Turno</th>
                        <th>Hora Inicio</th>
                        <th>Hora Fin</th>
                        <th>Nombre del Médico y Cédula</th>
                        <th style="text-align: center;">Duración</th>
                        <th style="text-align: center;">Comida</th>
                        <th>Día Inactivo (Sin Citas)</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($clinicas) > 0): ?>
                        <?php foreach ($clinicas as $c): 
                            $id = $c['id_clinica'];
                            $excepciones_json = isset($excepciones_crud[$id]) ? json_encode($excepciones_crud[$id]) : '[]';
                            $dia_inactivo = $c['dia_inactivo'] ?? '';
                        ?>
                            <tr id="fila_clinica_<?php echo $id; ?>">
                                <td><strong><?php echo $id; ?></strong></td>
                                <td><strong id="nombre_clinica_<?php echo $id; ?>"><?php echo htmlspecialchars($c['nombre_clinica']); ?></strong></td>
                                <td><span style="background: #e1f5fe; color: #0288d1; padding: 3px 6px; border-radius: 4px; font-weight: bold;"><?php echo htmlspecialchars($c['tipo_clinica']); ?></span></td>
                                <td><?php echo htmlspecialchars($c['turno']); ?></td>
                                <td><?php echo htmlspecialchars($c['hora_inicio']); ?></td>
                                <td><?php echo htmlspecialchars($c['hora_fin']); ?></td>
                                <td><?php echo htmlspecialchars($c['nombre_medico']); ?></td>
                                <td style="text-align: center;"><?php echo htmlspecialchars($c['duracion_consulta']); ?> min</td>
                                <td style="text-align: center;"><?php echo htmlspecialchars($c['hora_comida']); ?></td>
                                <td>
                                    <?php if (!empty($dia_inactivo)): ?>
                                        <span style="background: #ffebee; color: var(--rojo); padding: 3px 8px; border-radius: 4px; font-weight: bold;"><?php echo htmlspecialchars($dia_inactivo); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">Ninguno</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; display: flex; gap: 5px; justify-content: center;">
                                    <button type="button" class="btn btn-info" id="btn_exc_<?php echo $id; ?>" onclick="abrirModalExcepciones(<?php echo $id; ?>, '<?php echo addslashes($c['nombre_clinica']); ?>')" data-excepciones='<?php echo htmlspecialchars($excepciones_json, ENT_QUOTES, 'UTF-8'); ?>' title="Bloqueos"><i class="fa-solid fa-calendar-xmark"></i></button>
                                    <button type="button" class="btn btn-warning" onclick='editarClinica(<?php echo json_encode($c); ?>)' title="Editar"><i class="fa-solid fa-pen"></i></button>
                                    <a href="admin_clinicas.php?action=eliminar&id=<?php echo $id; ?>" class="btn btn-danger" onclick="return confirm('¿Seguro que deseas eliminar esta clínica?');"><i class="fa-solid fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Clínica Base -->
    <div id="modalClinica" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-top:0; color: var(--imss-verde);">Nueva Clínica</h3>
            <form method="POST" action="admin_clinicas.php">
                <input type="hidden" name="guardar_clinica" value="1">
                <input type="hidden" name="id_clinica" id="id_clinica">

                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nombre de Clínica:</label>
                        <input type="text" name="nombre_clinica" id="nombre_clinica" required>
                    </div>

                    <div class="form-group">
                        <label>Tipo Clínica:</label>
                        <select name="tipo_clinica" id="tipo_clinica" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($cat_tipos as $t): ?>
                                <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Turno:</label>
                        <select name="turno" id="turno">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($cat_turnos as $tu): ?>
                                <option value="<?php echo htmlspecialchars($tu); ?>"><?php echo htmlspecialchars($tu); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nombre del Médico y Cédula:</label>
                        <select name="nombre_medico" id="nombre_medico">
                            <option value="">-- Seleccionar Médico --</option>
                            <?php foreach ($cat_medicos as $m): ?>
                                <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Duración Consulta (minutos):</label>
                        <input type="number" name="duracion_consulta" id="duracion_consulta" oninput="regenerarDropdowns()" required min="5">
                    </div>
                    
                    <div class="form-group">
                        <label>Día Inactivo (Sin Citas):</label>
                        <select name="dia_inactivo" id="dia_inactivo">
                            <option value="">-- Ninguno (Atiende todos los días) --</option>
                            <?php foreach ($cat_dias as $dia): ?>
                                <option value="<?php echo $dia; ?>"><?php echo $dia; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Atiende Fin de Semana:</label>
                        <select name="fin_semana" id="fin_semana">
                            <option value="FALSE">FALSO</option>
                            <option value="TRUE">VERDADERO</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Hora Inicio:</label>
                        <select name="hora_inicio" id="hora_inicio"></select>
                    </div>
                    <div class="form-group">
                        <label>Hora Fin:</label>
                        <select name="hora_fin" id="hora_fin"></select>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Hora Comida (Bloqueo):</label>
                        <select name="hora_comida" id="hora_comida"></select>
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn" style="background:#637381;" onclick="cerrarModal('modalClinica')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Excepciones -->
    <div id="modalExcepciones" class="modal">
        <div class="modal-content" style="max-width: 750px;">
            <h3 style="margin-top:0; color: var(--azul);">Bloqueos y Excepciones</h3>
            <p id="excClinicaNombre" style="font-weight: bold; margin-bottom: 15px;"></p>
            
            <!-- FORMULARIO ACTUALIZADO CON AJAX -->
            <form id="formAgregarExcepcion" onsubmit="enviarExcepcionAjax(event)" style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid var(--borde); margin-bottom: 20px;">
                <input type="hidden" name="agregar_excepcion" value="1">
                <input type="hidden" name="exc_id_clinica" id="exc_id_clinica">
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                    <div class="form-group"><label>Fecha:</label><input type="date" name="exc_fecha" id="exc_fecha" required></div>
                    <div class="form-group" style="flex: 1;"><label>Motivo:</label><input type="text" name="exc_motivo" id="exc_motivo" placeholder="Permiso"></div>
                </div>
                <div style="margin-top: 15px; text-align: right;"><button type="submit" class="btn btn-info"><i class="fa-solid fa-plus"></i> Agregar</button></div>
            </form>

            <table class="exc-table" style="width: 100%; border-collapse: collapse;">
                <thead><tr><th>Fecha</th><th>Motivo</th><th>Horario</th><th style="text-align: center;">Acción</th></tr></thead>
                <tbody id="listaExcepciones"></tbody>
            </table>
            <div style="margin-top: 20px; display: flex; justify-content: flex-end;"><button type="button" class="btn" style="background:#637381;" onclick="cerrarModal('modalExcepciones')">Cerrar</button></div>
        </div>
    </div>

    <script>
        function actualizarOpcionesTiempo(duracionMinutos, valInicio, valFin, valComida) {
            let duracion = parseInt(duracionMinutos);
            if (isNaN(duracion) || duracion <= 0) duracion = 30;
            const generarLista = (selectId, valorSeleccionado) => {
                const select = document.getElementById(selectId);
                select.innerHTML = '<option value="">-- Seleccionar --</option>';
                let minutosActuales = 7 * 60, minutosMaximos = 24 * 60;
                while (minutosActuales < minutosMaximos) {
                    let h = Math.floor(minutosActuales / 60).toString().padStart(2, '0');
                    let m = (minutosActuales % 60).toString().padStart(2, '0');
                    let horaTexto = `${h}:${m}`;
                    let option = document.createElement('option');
                    option.value = horaTexto; option.text = horaTexto;
                    if (valorSeleccionado === horaTexto || (valorSeleccionado && valorSeleccionado.includes(horaTexto))) option.selected = true;
                    select.appendChild(option);
                    minutosActuales += duracion;
                }
            };
            generarLista('hora_inicio', valInicio);
            generarLista('hora_fin', valFin);
            generarLista('hora_comida', valComida);
        }

        function regenerarDropdowns() {
            actualizarOpcionesTiempo(document.getElementById('duracion_consulta').value, document.getElementById('hora_inicio').value, document.getElementById('hora_fin').value, document.getElementById('hora_comida').value);
        }

        function abrirModal() {
            document.getElementById('modalTitle').innerText = 'Nueva Clínica';
            document.getElementById('id_clinica').value = '';
            document.getElementById('nombre_clinica').value = '';
            document.getElementById('tipo_clinica').value = '';
            document.getElementById('turno').value = '';
            document.getElementById('nombre_medico').value = '';
            document.getElementById('duracion_consulta').value = '30';
            document.getElementById('fin_semana').value = 'FALSE';
            document.getElementById('dia_inactivo').value = '';
            actualizarOpcionesTiempo(30, '', '', '');
            document.getElementById('modalClinica').style.display = 'flex';
        }

        function editarClinica(data) {
            document.getElementById('modalTitle').innerText = 'Editar Clínica';
            document.getElementById('id_clinica').value = data.id_clinica;
            document.getElementById('nombre_clinica').value = data.nombre_clinica || '';
            document.getElementById('tipo_clinica').value = data.tipo_clinica || '';
            document.getElementById('turno').value = data.turno || '';
            document.getElementById('dia_inactivo').value = data.dia_inactivo || '';
            
            let selectMedico = document.getElementById('nombre_medico');
            let medicoGuardado = (data.nombre_medico || '').toUpperCase().trim();
            selectMedico.value = "";
            for (let i = 0; i < selectMedico.options.length; i++) {
                let optVal = selectMedico.options[i].value.toUpperCase();
                if (optVal === medicoGuardado || (medicoGuardado !== "" && optVal.includes(medicoGuardado))) {
                    selectMedico.selectedIndex = i;
                    break;
                }
            }

            document.getElementById('fin_semana').value = data.fin_semana || 'FALSE';
            let duracion = data.duracion_consulta || 30;
            document.getElementById('duracion_consulta').value = duracion;
            actualizarOpcionesTiempo(duracion, data.hora_inicio, data.hora_fin, data.hora_comida);
            document.getElementById('modalClinica').style.display = 'flex';
        }

        function abrirModalExcepciones(id, nombre) {
            document.getElementById('excClinicaNombre').innerText = `Clínica: ${nombre}`;
            document.getElementById('exc_id_clinica').value = id;
            
            // Limpiar formulario de captura rápida
            document.getElementById('exc_fecha').value = '';
            document.getElementById('exc_motivo').value = '';

            const btn = document.getElementById(`btn_exc_${id}`);
            let excepciones = btn && btn.dataset.excepciones ? JSON.parse(btn.dataset.excepciones) : [];
            renderizarTablaExcepciones(excepciones, id);
            
            document.getElementById('modalExcepciones').style.display = 'flex';
        }

        function renderizarTablaExcepciones(excepciones, idClinica) {
            const tbody = document.getElementById('listaExcepciones');
            tbody.innerHTML = '';
            if (excepciones.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #637381; padding: 15px;">No hay bloqueos registrados.</td></tr>';
            } else {
                excepciones.forEach(e => {
                    let esTodoElDia = (e.todo_el_dia === true || e.todo_el_dia === 1 || e.todo_el_dia === '1' || e.todo_el_dia === 't');
                    let horario = esTodoElDia ? '<span style="color:var(--rojo); font-weight:bold;">Día Completo</span>' : `${e.hora_inicio.substring(0,5)} a ${e.hora_fin.substring(0,5)}`;
                    let tr = document.createElement('tr');
                    tr.innerHTML = `<td>${e.fecha}</td><td>${e.motivo}</td><td>${horario}</td><td style="text-align: center;"><button type="button" class="btn btn-danger" style="padding: 4px 8px; font-size: 11px;" onclick="eliminarExcepcionAjax(${e.id_excepcion}, ${idClinica})"><i class="fa-solid fa-trash"></i></button></td>`;
                    tbody.appendChild(tr);
                });
            }
            // Actualizar dataset del botón principal en la tabla de clínicas por si se vuelve a abrir
            const btn = document.getElementById(`btn_exc_${idClinica}`);
            if (btn) {
                btn.dataset.excepciones = JSON.stringify(excepciones);
            }
        }

        // Función para enviar excepciones por AJAX sin cerrar la ventana
        function enviarExcepcionAjax(event) {
            event.preventDefault();
            const form = document.getElementById('formAgregarExcepcion');
            const formData = new FormData(form);
            const idClinica = document.getElementById('exc_id_clinica').value;

            fetch('admin_clinicas.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderizarTablaExcepciones(data.excepciones, idClinica);
                    // Limpiar campos para el siguiente registro
                    document.getElementById('exc_fecha').value = '';
                    document.getElementById('exc_motivo').value = '';
                } else {
                    alert('Error al registrar el bloqueo.');
                }
            })
            .catch(error => console.error('Error:', error));
        }

        // Función para eliminar excepciones por AJAX sin cerrar la ventana
        function eliminarExcepcionAjax(idExcepcion, idClinica) {
            if (!confirm('¿Eliminar bloqueo?')) return;

            fetch(`admin_clinicas.php?action=eliminar_excepcion&id_exc=${idExcepcion}&id_clinica_retorno=${idClinica}`, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderizarTablaExcepciones(data.excepciones, idClinica);
                } else {
                    alert('Error al eliminar el bloqueo.');
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function cerrarModal(id) {
            document.getElementById(id).style.display = 'none';
        }
    </script>
</body>
</html>