<?php
// ===================================================================
// 1. CONFIGURACIÓN Y SEGURIDAD
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.html");
    exit();
}
require_once 'conexion.php';

$es_admin = ($_SESSION['rol_id'] == 1);
$permisos_usuario_raw = $_SESSION['permisos'] ?? [];
$permisos_usuario = array_map('strtolower', $permisos_usuario_raw);

$tiene_acceso = $es_admin || in_array('consulta_express', $permisos_usuario);
if (!$tiene_acceso) {
    header("Location: dashboard.php");
    exit();
}

$mensaje = '';
$error = '';

// ===================================================================
// 2. CARGAR CATÁLOGOS DESDE LA BASE DE DATOS
// ===================================================================
$lista_derechohabiencia = [];
try {
    $stmtDh = $pdo->query("SELECT nombre_derechohabiencia FROM derechohabiencia ORDER BY nombre_derechohabiencia ASC");
    $lista_derechohabiencia = $stmtDh->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $lista_derechohabiencia = ['IMSS', 'ISSSTE', 'PEMEX', 'SEGURO POPULAR', 'INSABI', 'IMSS-BIENESTAR', 'NINGUNA'];
}

$lista_tipo_paciente = [];
try {
    $stmtTp = $pdo->query("SELECT nombre_tipo FROM tipo_paciente WHERE activo = TRUE OR activo IS NULL ORDER BY nombre_tipo ASC");
    $lista_tipo_paciente = $stmtTp->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $lista_tipo_paciente = ['Crónico', 'Embarazada', 'Pediatrico', 'Adulto Mayor', 'Agúdo', 'Recien Nacido', 'General'];
}

// Catálogo de Médicos
$cat_medicos = [];
try {
    $stmt_m = $pdo->query("SELECT * FROM medicos ORDER BY nombre ASC");
    while ($m = $stmt_m->fetch(PDO::FETCH_ASSOC)) {
        $nombre = trim($m['nombre'] ?? $m['nombres'] ?? '');
        $apaterno = trim($m['apaterno'] ?? '');
        $amaterno = trim($m['amaterno'] ?? '');
        $cedula = trim($m['cedula'] ?? $m['cedula_profesional'] ?? 'S/C');
        $nombre_completo = trim("$nombre $apaterno $amaterno");
        if (!empty($nombre_completo)) {
            $cat_medicos[] = [
                'id' => $m['id_medico'] ?? $m['id'] ?? $nombre_completo,
                'texto' => mb_strtoupper("$nombre_completo - CÉDULA: $cedula", 'UTF-8')
            ];
        }
    }
} catch (Exception $e) {}

// Recuperar el médico seleccionado por GET o sesión si aplica
$id_medico_get = trim($_GET['medico'] ?? '');

// ===================================================================
// 3. PROCESAMIENTO DE ACCIONES (REGISTRO Y ACTUALIZACIÓN COMPLETA)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $medico_post = trim($_POST['medico'] ?? '');

    if ($accion === 'registrar_paciente_express') {
        $nombre            = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
        $apaterno          = mb_strtoupper(trim($_POST['apaterno'] ?? ''), 'UTF-8');
        $amaterno          = mb_strtoupper(trim($_POST['amaterno'] ?? ''), 'UTF-8');
        $curp              = strtoupper(trim($_POST['curp'] ?? ''));
        if (empty($curp)) {
            $curp = 'S/C-' . mt_rand(100000, 999999);
        }
        $edad              = intval($_POST['edad'] ?? 0);
        $sexo              = mb_strtoupper(trim($_POST['sexo'] ?? 'MASCULINO'), 'UTF-8');
        $telefono          = trim($_POST['telefono'] ?? '0000000000');
        $domicilio         = mb_strtoupper(trim($_POST['domicilio'] ?? 'DOMICILIO CONOCIDO'), 'UTF-8');
        $municipio         = mb_strtoupper(trim($_POST['municipio'] ?? 'HUIMANGUILLO'), 'UTF-8');
        $colonia           = mb_strtoupper(trim($_POST['colonia'] ?? 'CENTRO'), 'UTF-8');
        $estado            = mb_strtoupper(trim($_POST['estado'] ?? 'TABASCO'), 'UTF-8');
        $derechohabiencia  = mb_strtoupper(trim($_POST['derechohabiencia'] ?? ''), 'UTF-8');
        $tipo_paciente     = mb_strtoupper(trim($_POST['tipo_paciente'] ?? 'GENERAL'), 'UTF-8');
        $expediente        = 'EX-' . mt_rand(100000, 999999);

        if (!empty($nombre) && !empty($apaterno)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO pacientes (
                        expediente, nombre, apaterno, amaterno, curp, edad, edad_actual, sexo, estado, domicilio, municipio, colonia, telefono, estatus, tipo_paciente, derechohabiencia, fecha_apertura, nombre_completo, nombre_apellidos
                    ) VALUES (
                        :exp, :nom, :apat, :amat, :curp, :edad, :edad, :sexo, :est, :dom, :mun, :col, :tel, 'ACTIVO', :tp, :derech, CURRENT_DATE, :ncomp, :napel
                    )
                ");
                $nombre_completo  = trim("$nombre $apaterno $amaterno");
                $nombre_apellidos = trim("$apaterno $amaterno $nombre");

                $stmt->execute([
                    'exp'    => $expediente,
                    'nom'    => $nombre,
                    'apat'   => $apaterno,
                    'amat'   => $amaterno,
                    'curp'   => $curp,
                    'edad'   => $edad,
                    'sexo'   => $sexo,
                    'est'    => $estado,
                    'dom'    => $domicilio,
                    'mun'    => $municipio,
                    'col'    => $colonia,
                    'tel'    => $telefono,
                    'tp'     => $tipo_paciente,
                    'derech' => $derechohabiencia,
                    'ncomp'  => $nombre_completo,
                    'napel'  => $nombre_apellidos
                ]);
                
                $nuevo_id = $pdo->lastInsertId();
                header("Location: consulta_express.php?id_paciente=" . $nuevo_id . "&medico=" . urlencode($medico_post));
                exit();
            } catch (Exception $e) {
                $error = "Error al registrar el paciente: " . $e->getMessage();
            }
        } else {
            $error = "El nombre y apellido paterno son obligatorios.";
        }
    }

    if ($accion === 'actualizar_paciente_completo') {
        $id_pac         = intval($_POST['id_paciente'] ?? 0);
        $expediente     = trim($_POST['expediente'] ?? '');
        $nombre         = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
        $apaterno       = mb_strtoupper(trim($_POST['apaterno'] ?? ''), 'UTF-8');
        $amaterno       = mb_strtoupper(trim($_POST['amaterno'] ?? ''), 'UTF-8');
        $curp           = strtoupper(trim($_POST['curp'] ?? ''));
        $fnacimiento    = trim($_POST['fnacimiento'] ?? null);
        $year_nacimiento= trim($_POST['year_nacimiento'] ?? '');
        $edad           = intval($_POST['edad'] ?? 0);
        $edad_actual    = intval($_POST['edad_actual'] ?? 0);
        $sexo           = mb_strtoupper(trim($_POST['sexo'] ?? ''), 'UTF-8');
        $estado         = mb_strtoupper(trim($_POST['estado'] ?? ''), 'UTF-8');
        $municipio      = mb_strtoupper(trim($_POST['municipio'] ?? ''), 'UTF-8');
        $colonia        = mb_strtoupper(trim($_POST['colonia'] ?? ''), 'UTF-8');
        $domicilio      = mb_strtoupper(trim($_POST['domicilio'] ?? ''), 'UTF-8');
        $telefono       = trim($_POST['telefono'] ?? '');
        $nombre_padre   = mb_strtoupper(trim($_POST['nombre_padre'] ?? ''), 'UTF-8');
        $nombre_madre   = mb_strtoupper(trim($_POST['nombre_madre'] ?? ''), 'UTF-8');
        $edad_padre     = trim($_POST['edad_padre'] ?? '');
        $edad_madre     = trim($_POST['edad_madre'] ?? '');
        $fecha_apertura = trim($_POST['fecha_apertura'] ?? null);
        $estatus        = mb_strtoupper(trim($_POST['estatus'] ?? 'ACTIVO'), 'UTF-8');
        $derechohabiencia = mb_strtoupper(trim($_POST['derechohabiencia'] ?? ''), 'UTF-8');
        $tipo_paciente  = mb_strtoupper(trim($_POST['tipo_paciente'] ?? 'General'), 'UTF-8');

        if ($id_pac > 0) {
            try {
                $nombre_completo  = trim("$nombre $apaterno $amaterno");
                $nombre_apellidos = trim("$apaterno $amaterno $nombre");

                $stmtUpd = $pdo->prepare("
                    UPDATE pacientes 
                    SET expediente = :exp, nombre = :nom, apaterno = :apat, amaterno = :amat, curp = :curp, 
                        fnacimiento = NULLIF(:fnac, ''), year_nacimiento = :ynac, edad = :edad, edad_actual = :edadact, 
                        sexo = :sexo, estado = :est, municipio = :mun, colonia = :col, domicilio = :dom, 
                        telefono = :tel, nombre_padre = :npad, nombre_madre = :nmad, edad_padre = :epad, 
                        edad_madre = :emad, fecha_apertura = NULLIF(:fape, ''), estatus = :estatus, 
                        derechohabiencia = :derech, tipo_paciente = :tp, nombre_completo = :ncomp, nombre_apellidos = :napel 
                    WHERE id_paciente = :id
                ");
                $stmtUpd->execute([
                    'exp'     => $expediente,
                    'nom'     => $nombre,
                    'apat'    => $apaterno,
                    'amat'    => $amaterno,
                    'curp'    => $curp,
                    'fnac'    => $fnacimiento,
                    'ynac'    => $year_nacimiento,
                    'edad'    => $edad,
                    'edadact' => $edad_actual,
                    'sexo'    => $sexo,
                    'est'     => $estado,
                    'mun'     => $municipio,
                    'col'     => $colonia,
                    'dom'     => $domicilio,
                    'tel'     => $telefono,
                    'npad'    => $nombre_padre,
                    'nmad'    => $nombre_madre,
                    'epad'    => $edad_padre,
                    'emad'    => $edad_madre,
                    'fape'    => $fecha_apertura,
                    'estatus' => $estatus,
                    'derech'  => $derechohabiencia,
                    'tp'      => $tipo_paciente,
                    'ncomp'   => $nombre_completo,
                    'napel'   => $nombre_apellidos,
                    'id'      => $id_pac
                ]);
                header("Location: consulta_express.php?id_paciente=" . $id_pac . "&medico=" . urlencode($medico_post));
                exit();
            } catch (Exception $e) {
                $error = "Error al actualizar los datos del paciente: " . $e->getMessage();
            }
        }
    }
}

// ===================================================================
// 4. AJAX PARA BÚSQUEDA AVANZADA (MULTIPALABRA)
// ===================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_pacientes') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    try {
        if (!empty($q)) {
            $palabras = explode(' ', $q);
            $conditions = [];
            $params = [];

            foreach ($palabras as $index => $palabra) {
                if (trim($palabra) !== '') {
                    $conditions[] = "(nombre ILIKE :p$index OR apaterno ILIKE :p$index OR amaterno ILIKE :p$index OR expediente ILIKE :p$index OR curp ILIKE :p$index OR telefono ILIKE :p$index OR (nombre || ' ' || apaterno || ' ' || amaterno) ILIKE :p$index)";
                    $params["p$index"] = "%$palabra%";
                }
            }

            if (count($conditions) > 0) {
                $sql = "SELECT * FROM pacientes WHERE " . implode(" AND ", $conditions) . " ORDER BY id_paciente DESC LIMIT 25";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $pacientes = $pdo->query("SELECT * FROM pacientes ORDER BY id_paciente DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            $pacientes = $pdo->query("SELECT * FROM pacientes ORDER BY id_paciente DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($pacientes);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

$id_paciente_seleccionado = intval($_GET['id_paciente'] ?? 0);
$paciente_actual = null;
$requiere_completar = false;

try {
    if ($id_paciente_seleccionado > 0) {
        $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id LIMIT 1");
        $stmtP->execute(['id' => $id_paciente_seleccionado]);
        $paciente_actual = $stmtP->fetch(PDO::FETCH_ASSOC);

        if ($paciente_actual) {
            $col = trim($paciente_actual['colonia'] ?? '');
            $dom = trim($paciente_actual['domicilio'] ?? '');
            $mun = trim($paciente_actual['municipio'] ?? '');
            if (empty($col) || empty($dom) || empty($mun) || $col === 'S/N' || $dom === 'S/N') {
                $requiere_completar = true;
            }
        }
    }
} catch (Exception $e) {
    $paciente_actual = null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Consulta Express</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root { --imss-verde: #006847; --imss-verde-hover: #004d34; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde-color: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: var(--texto-oscuro); }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: 700; font-size: 16px; }
        
        .container-fluid { width: 96%; max-width: 1800px; margin: 20px auto; padding: 0 10px; box-sizing: border-box; }
        
        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); margin-bottom: 25px; }
        .card-title { font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 20px; color: var(--texto-oscuro); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; }
        
        .search-bar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .form-control { width: 100%; padding: 10px 14px; font-size: 14px; border: 2px solid var(--borde-color); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; color: var(--texto-oscuro); text-transform: uppercase; }
        .form-control:focus { border-color: var(--imss-verde); }

        .btn { padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-primary:hover { background-color: var(--imss-verde-hover); }
        .btn-express { background-color: #0284c7; }
        .btn-express:hover { background-color: #0369a1; }
        .btn-lab { background-color: #7c3aed; }
        .btn-lab:hover { background-color: #6d28d9; }
        .btn-ref { background-color: var(--imss-dorado); }
        .btn-ref:hover { background-color: #7d1b33; }
        .btn-new { background-color: #059669; }
        .btn-new:hover { background-color: #047857; }

        .table-responsive { overflow-x: auto; max-height: 450px; overflow-y: auto; border: 1px solid var(--borde-color); border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 12px; font-weight: 700; border-bottom: 2px solid var(--borde-color); position: sticky; top: 0; z-index: 10; }
        table td { padding: 12px; border-bottom: 1px solid var(--borde-color); }
        table tr:hover { background-color: #f8f9fa; cursor: pointer; }

        .patient-info-box { background: #e4f8dd; border-left: 5px solid var(--imss-verde); padding: 15px 20px; border-radius: 6px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .action-card { background: #fff; border: 2px solid var(--borde-color); border-radius: 8px; padding: 25px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 12px; transition: all 0.2s; }
        .action-card:hover { border-color: var(--imss-verde); transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .action-card i { font-size: 45px; margin-bottom: 5px; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 900px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto; }
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single { height: 42px; border: 2px solid var(--borde-color); border-radius: 6px; display: flex; align-items: center; }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-stethoscope"></i> <strong>IMSS-BIENESTAR</strong> | Módulo de Consulta Express</div>
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Menú Principal</a>
    </div>

    <div class="container-fluid">
        
        <?php if (!empty($error)): ?>
            <div style="background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado);">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- ================= SELECCIÓN DEL MÉDICO TRATANTE (PRIMERO) ================= -->
        <div class="card" style="border-top-color: #0284c7;">
            <div class="card-title" style="margin-bottom: 10px;">
                <span><i class="fa-solid fa-user-doctor" style="color: #0284c7;"></i> 1. Selección del Médico Tratante</span>
            </div>
            <div style="max-width: 600px;">
                <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 5px; color: #0284c7;">SELECCIONE EL MÉDICO QUE EMITE EL FORMATO *</label>
                <select id="medicoGlobal" class="form-control" onchange="actualizarMedicoUrl()">
                    <option value="">-- SELECCIONE MÉDICO --</option>
                    <?php foreach ($cat_medicos as $med): ?>
                        <option value="<?php echo htmlspecialchars($med['texto']); ?>" <?php echo ($id_medico_get === $med['texto']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($med['texto']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($paciente_actual): ?>
            <!-- PACIENTE SELECCIONADO -->
            <div class="patient-info-box">
                <div>
                    <span style="font-size: 12px; color: var(--imss-verde); font-weight: bold; text-transform: uppercase;">Paciente Activo:</span>
                    <h3 style="margin: 5px 0 0 0; color: var(--texto-oscuro);">
                        <?php echo htmlspecialchars(trim(($paciente_actual['nombre'] ?? '') . ' ' . ($paciente_actual['apaterno'] ?? '') . ' ' . ($paciente_actual['amaterno'] ?? ''))); ?>
                    </h3>
                    <p style="margin: 5px 0 0 0; font-size: 13px; color: #555;">
                        <strong>Expediente:</strong> <?php echo htmlspecialchars($paciente_actual['expediente'] ?? 'S/N'); ?> | 
                        <strong>CURP:</strong> <?php echo htmlspecialchars($paciente_actual['curp'] ?? 'Sin CURP'); ?> | 
                        <strong>Edad:</strong> <?php echo htmlspecialchars($paciente_actual['edad'] ?? 'N/A'); ?> años |
                        <strong>Programa:</strong> <?php echo htmlspecialchars($paciente_actual['tipo_paciente'] ?? 'General'); ?>
                    </p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn" style="background: #0284c7;" onclick="abrirModalEditarPaciente()">
                        <i class="fa-solid fa-user-pen"></i> Editar / Completar Todos los Datos
                    </button>
                    <a href="#" onclick="cambiarPaciente()" class="btn" style="background: #637381;">
                        <i class="fa-solid fa-user-xmark"></i> Cambiar Paciente
                    </a>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-bolt"></i> 2. Seleccione el Formato Express a Emitir</h2>
                <div class="actions-grid">
                    
                    <div class="action-card">
                        <i class="fa-solid fa-prescription-bottle-medical" style="color: #0284c7;"></i>
                        <h3 style="margin: 0;">Receta Médica Express</h3>
                        <p style="font-size: 12px; color: #637381; margin: 0;">Emitir receta rápida de medicamentos con vigencia.</p>
                        <button type="button" onclick="irA('receta_express.php')" class="btn btn-express" style="width: 100%; justify-content: center;">
                            <i class="fa-solid fa-file-prescription"></i> Abrir Receta
                        </button>
                    </div>

                    <div class="action-card">
                        <i class="fa-solid fa-flask" style="color: #7c3aed;"></i>
                        <h3 style="margin: 0;">Orden de Laboratorios</h3>
                        <p style="font-size: 12px; color: #637381; margin: 0;">Solicitar estudios clínicos y perfiles de laboratorio.</p>
                        <button type="button" onclick="irA('laboratorio_express.php')" class="btn btn-lab" style="width: 100%; justify-content: center;">
                            <i class="fa-solid fa-microscope"></i> Solicitar Estudios
                        </button>
                    </div>

                    <div class="action-card">
                        <i class="fa-solid fa-file-medical" style="color: var(--imss-dorado);"></i>
                        <h3 style="margin: 0;">Referencia Médica</h3>
                        <p style="font-size: 12px; color: #637381; margin: 0;">Generar formato de referencia o contrarreferencia hospitalaria.</p>
                        <button type="button" onclick="irA('referencia_express.php')" class="btn btn-ref" style="width: 100%; justify-content: center;">
                            <i class="fa-solid fa-hospital-user"></i> Generar Referencia
                        </button>
                    </div>

                </div>
            </div>

        <?php else: ?>
            <!-- BÚSQUEDA Y REGISTRO RÁPIDO -->
            <div class="card">
                <div class="card-title">
                    <span><i class="fa-solid fa-user-search"></i> 2. Buscar o Registrar Paciente para Consulta Express</span>
                    <button type="button" class="btn btn-new" onclick="abrirModalNuevoPaciente()">
                        <i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente
                    </button>
                </div>
                
                <div class="search-bar">
                    <input type="text" id="inputBusqueda" class="form-control" placeholder="ESCRIBA NOMBRES O APELLIDOS (EJ. MARIO ORDOÑEZ) Y PRESIONE ENTER..." oninput="this.value = this.value.toUpperCase();" onkeypress="if(event.key === 'Enter'){ buscarPacientesExpress(); return false; }" style="flex: 1;">
                    <button type="button" class="btn btn-primary" onclick="buscarPacientesExpress()">
                        <i class="fa-solid fa-magnifying-glass"></i> Buscar
                    </button>
                </div>

                <div class="table-responsive">
                    <table id="tablaPacientesExpress">
                        <thead>
                            <tr>
                                <th>Acción</th>
                                <th>Expediente</th>
                                <th>Nombre Completo</th>
                                <th>CURP</th>
                                <th>Teléfono</th>
                                <th>Programa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $stmt_recientes = $pdo->query("SELECT * FROM pacientes ORDER BY id_paciente DESC LIMIT 20");
                                $pacientes_recientes = $stmt_recientes->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (count($pacientes_recientes) > 0) {
                                    foreach ($pacientes_recientes as $p) {
                                        $tipo_pac = $p['tipo_paciente'] ?? 'General';
                                        $curp_val = !empty($p['curp']) ? $p['curp'] : '<span style="color:#999; font-style:italic;">Sin CURP</span>';
                                        echo '<tr>';
                                        echo '<td><button type="button" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;" onclick="verificarYSeleccionar('.$p['id_paciente'].')"><i class="fa-solid fa-check"></i> Seleccionar</button></td>';
                                        echo '<td><strong>'.htmlspecialchars($p['expediente'] ?? 'S/N').'</strong></td>';
                                        echo '<td>'.htmlspecialchars(trim(($p['nombre'] ?? '').' '.($p['apaterno'] ?? '').' '.($p['amaterno'] ?? ''))).'</td>';
                                        echo '<td>'.$curp_val.'</td>';
                                        echo '<td>'.htmlspecialchars($p['telefono'] ?? 'S/N').'</td>';
                                        echo '<td><span style="background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-size:12px;">'.htmlspecialchars($tipo_pac).'</span></td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="6" style="text-align: center; color: #637381; padding: 20px;">No hay pacientes registrados en el sistema.</td></tr>';
                                }
                            } catch (Exception $e) {
                                echo '<tr><td colspan="6" style="text-align: center; color: var(--imss-dorado);">Error al cargar los pacientes.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL NUEVO PACIENTE -->
    <div class="modal-overlay" id="modalNuevoPaciente">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde-color); padding-bottom: 12px; margin-bottom: 20px;">
                <h3 style="margin: 0; color: var(--imss-verde);"><i class="fa-solid fa-user-plus"></i> Registro Rápido de Paciente</h3>
                <button type="button" onclick="cerrarModalNuevoPaciente()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #637381;">&times;</button>
            </div>
            
            <form action="consulta_express.php" method="POST" id="formNuevoPaciente">
                <input type="hidden" name="accion" value="registrar_paciente_express">
                <input type="hidden" name="medico" id="input_medico_nuevo">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Nombre(s) *</label>
                        <input type="text" name="nombre" class="form-control" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Apellido Paterno *</label>
                        <input type="text" name="apaterno" class="form-control" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Apellido Materno</label>
                        <input type="text" name="amaterno" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">CURP <small style="color:#637381; font-weight:normal;">(Opcional)</small></label>
                        <input type="text" name="curp" id="nuevo_curp" class="form-control" maxlength="18" placeholder="Opcional" style="text-transform: uppercase;" oninput="procesarCurp(this, 'nuevo_'); this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Edad *</label>
                        <input type="number" name="edad" id="nuevo_edad" class="form-control" value="30" required min="0" max="120">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Sexo *</label>
                        <select name="sexo" id="nuevo_sexo" class="form-control">
                            <option value="MASCULINO">MASCULINO</option>
                            <option value="FEMENINO">FEMENINO</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Teléfono *</label>
                        <input type="tel" name="telefono" class="form-control" value="9370000000" required maxlength="10">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Municipio *</label>
                        <input type="text" name="municipio" class="form-control" value="HUIMANGUILLO" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Colonia *</label>
                        <input type="text" name="colonia" class="form-control" value="CENTRO" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Domicilio (Calle y Número) *</label>
                    <input type="text" name="domicilio" class="form-control" value="DOMICILIO CONOCIDO" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Derechohabiencia</label>
                        <select name="derechohabiencia" class="form-control">
                            <option value="">-- SELECCIONE --</option>
                            <?php foreach ($lista_derechohabiencia as $dh): ?>
                                <option value="<?php echo htmlspecialchars($dh); ?>"><?php echo htmlspecialchars($dh); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px;">Tipo Paciente (Programa)</label>
                        <select name="tipo_paciente" class="form-control">
                            <option value="">-- SELECCIONE --</option>
                            <?php foreach ($lista_tipo_paciente as $tp): ?>
                                <option value="<?php echo htmlspecialchars($tp); ?>"><?php echo htmlspecialchars($tp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" style="background:#637381; margin-right: 10px;" onclick="cerrarModalNuevoPaciente()">Cancelar</button>
                    <button type="submit" class="btn btn-primary" onclick="return validarMedicoForm()"><i class="fa-solid fa-save"></i> Guardar y Seleccionar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR / COMPLETAR TODOS LOS CAMPOS DE LA TABLA PACIENTES -->
    <div class="modal-overlay" id="modalEditarPaciente">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde-color); padding-bottom: 12px; margin-bottom: 20px;">
                <h3 style="margin: 0; color: var(--imss-dorado);"><i class="fa-solid fa-user-pen"></i> Actualizar Todos los Datos del Paciente</h3>
                <button type="button" onclick="cerrarModalEditarPaciente()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #637381;">&times;</button>
            </div>
            
            <form action="consulta_express.php" method="POST">
                <input type="hidden" name="accion" value="actualizar_paciente_completo">
                <input type="hidden" name="id_paciente" id="edit_id_paciente" value="<?php echo $paciente_actual['id_paciente'] ?? ''; ?>">
                <input type="hidden" name="medico" id="input_medico_edit">
                <input type="hidden" name="fnacimiento" id="edit_fnacimiento" value="<?php echo htmlspecialchars($paciente_actual['fnacimiento'] ?? ''); ?>">
                <input type="hidden" name="year_nacimiento" id="edit_year_nacimiento" value="<?php echo htmlspecialchars($paciente_actual['year_nacimiento'] ?? ''); ?>">

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">No. Expediente</label>
                        <input type="text" name="expediente" id="edit_expediente" class="form-control" value="<?php echo htmlspecialchars($paciente_actual['expediente'] ?? ''); ?>" style="text-transform: uppercase;">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">CURP (Autocompleta)</label>
                        <input type="text" name="curp" id="edit_curp" class="form-control" maxlength="18" style="text-transform: uppercase;" oninput="procesarCurp(this, 'edit_'); this.value = this.value.toUpperCase();" value="<?php echo htmlspecialchars($paciente_actual['curp'] ?? ''); ?>">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Teléfono</label>
                        <input type="tel" name="telefono" id="edit_telefono" class="form-control" maxlength="10" value="<?php echo htmlspecialchars($paciente_actual['telefono'] ?? ''); ?>">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Nombre(s)</label>
                        <input type="text" name="nombre" id="edit_nombre" class="form-control" required style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['nombre'] ?? ''); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Apellido Paterno</label>
                        <input type="text" name="apaterno" id="edit_apaterno" class="form-control" required style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['apaterno'] ?? ''); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Apellido Materno</label>
                        <input type="text" name="amaterno" id="edit_amaterno" class="form-control" style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['amaterno'] ?? ''); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Edad</label>
                        <input type="number" name="edad" id="edit_edad" class="form-control" value="<?php echo intval($paciente_actual['edad'] ?? 0); ?>">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Edad Actual</label>
                        <input type="number" name="edad_actual" id="edit_edad_actual" class="form-control" value="<?php echo intval($paciente_actual['edad_actual'] ?? 0); ?>">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Sexo</label>
                        <select name="sexo" id="edit_sexo" class="form-control">
                            <?php $sexo_db = strtoupper($paciente_actual['sexo'] ?? ''); ?>
                            <option value="MASCULINO" <?php echo ($sexo_db === 'MASCULINO' || $sexo_db === 'M') ? 'selected' : ''; ?>>MASCULINO</option>
                            <option value="FEMENINO" <?php echo ($sexo_db === 'FEMENINO' || $sexo_db === 'F') ? 'selected' : ''; ?>>FEMENINO</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Estado</label>
                        <input type="text" name="estado" id="edit_estado" class="form-control" style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['estado'] ?? 'TABASCO'); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Municipio *</label>
                        <input type="text" name="municipio" id="edit_municipio" class="form-control" required style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['municipio'] ?? 'HUIMANGUILLO'); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Colonia *</label>
                        <input type="text" name="colonia" id="edit_colonia" class="form-control" required style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['colonia'] ?? ''); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Domicilio (Calle y Número) *</label>
                    <input type="text" name="domicilio" id="edit_domicilio" class="form-control" required style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['domicilio'] ?? ''); ?>" oninput="this.value = this.value.toUpperCase();">
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr 2fr 1fr; gap: 12px;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Nombre del Padre</label>
                        <input type="text" name="nombre_padre" class="form-control" style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['nombre_padre'] ?? ''); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Edad Padre</label>
                        <input type="text" name="edad_padre" class="form-control" value="<?php echo htmlspecialchars($paciente_actual['edad_padre'] ?? ''); ?>">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Nombre de la Madre</label>
                        <input type="text" name="nombre_madre" class="form-control" style="text-transform: uppercase;" value="<?php echo htmlspecialchars($paciente_actual['nombre_madre'] ?? ''); ?>" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Edad Madre</label>
                        <input type="text" name="edad_madre" class="form-control" value="<?php echo htmlspecialchars($paciente_actual['edad_madre'] ?? ''); ?>">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Fecha Apertura</label>
                        <input type="date" name="fecha_apertura" class="form-control" value="<?php echo htmlspecialchars($paciente_actual['fecha_apertura'] ?? date('Y-m-d')); ?>">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Estatus</label>
                        <select name="estatus" class="form-control">
                            <option value="ACTIVO" <?php echo ($paciente_actual['estatus'] ?? '') === 'ACTIVO' ? 'selected' : ''; ?>>ACTIVO</option>
                            <option value="INACTIVO" <?php echo ($paciente_actual['estatus'] ?? '') === 'INACTIVO' ? 'selected' : ''; ?>>INACTIVO</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Derechohabiencia</label>
                        <select name="derechohabiencia" id="edit_derechohabiencia" class="form-control">
                            <option value="">-- SELECCIONE --</option>
                            <?php foreach ($lista_derechohabiencia as $dh): 
                                $sel = (strtoupper($paciente_actual['derechohabiencia'] ?? '') === strtoupper($dh)) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($dh); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($dh); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-weight: bold; font-size: 12px; margin-bottom: 3px;">Tipo Paciente (Programa)</label>
                        <select name="tipo_paciente" id="edit_tipo_paciente" class="form-control">
                            <option value="">-- SELECCIONE --</option>
                            <?php foreach ($lista_tipo_paciente as $tp): 
                                $sel = (strtoupper($paciente_actual['tipo_paciente'] ?? '') === strtoupper($tp)) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($tp); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($tp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" style="background:#637381; margin-right: 10px;" onclick="cerrarModalEditarPaciente()">Omitir por ahora</button>
                    <button type="submit" class="btn btn-primary" onclick="return validarMedicoForm()"><i class="fa-solid fa-save"></i> Guardar Todos los Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        const requiereCompletar = <?php echo $requiere_completar ? 'true' : 'false'; ?>;

        $(document).ready(function() {
            $('#medicoGlobal').select2();
            if (requiereCompletar) {
                abrirModalEditarPaciente();
            }
        });

        function actualizarMedicoUrl() {
            const medico = $('#medicoGlobal').val();
            const urlParams = new URLSearchParams(window.location.search);
            if (medico) {
                urlParams.set('medico', medico);
            } else {
                urlParams.delete('medico');
            }
            window.history.replaceState({}, '', `${window.location.pathname}?${urlParams.toString()}`);
        }

        function validarMedico() {
            const medico = $('#medicoGlobal').val();
            if (!medico) {
                alert('Por favor, seleccione un médico tratante antes de continuar.');
                $('#medicoGlobal').select2('open');
                return false;
            }
            return true;
        }

        function validarMedicoForm() {
            if (!validarMedico()) return false;
            const medico = $('#medicoGlobal').val();
            document.getElementById('input_medico_nuevo').value = medico;
            const editMedicoInput = document.getElementById('input_medico_edit');
            if(editMedicoInput) editMedicoInput.value = medico;
            return true;
        }

        function verificarYSeleccionar(id) {
            if (!validarMedico()) return;
            const medico = encodeURIComponent($('#medicoGlobal').val());
            window.location.href = `consulta_express.php?id_paciente=${id}&medico=${medico}`;
        }

        function cambiarPaciente() {
            const medico = encodeURIComponent($('#medicoGlobal').val() || '');
            window.location.href = `consulta_express.php${medico ? '?medico=' + medico : ''}`;
        }

        function irA(pagina) {
    if (!validarMedico()) return;
    const medico = encodeURIComponent($('#medicoGlobal').val());
    window.location.href = `${pagina}?id_paciente=<?php echo $id_paciente_seleccionado; ?>&medico=${medico}`;
}

        function buscarPacientesExpress() {
            if (!validarMedico()) return;
            const q = document.getElementById('inputBusqueda').value.trim();
            const tbody = document.querySelector('#tablaPacientesExpress tbody');
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;"><i class="fa-solid fa-spinner fa-spin"></i> Buscando...</td></tr>';

            fetch(`consulta_express.php?ajax=buscar_pacientes&q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => {
                    let html = '';
                    if(data.length > 0) {
                        data.forEach(p => {
                            let exp = p.expediente || 'S/N';
                            let nombreComp = `${p.nombre || ''} ${p.apaterno || ''} ${p.amaterno || ''}`.trim();
                            let curp = (p.curp && p.curp.indexOf('S/C-') !== 0) ? p.curp : '<span style="color:#999; font-style:italic;">Sin CURP</span>';
                            let tel = p.telefono || 'S/N';
                            let tp = p.tipo_paciente || 'General';
                            
                            html += `<tr>
                                <td><button type="button" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;" onclick="verificarYSeleccionar(${p.id_paciente})"><i class="fa-solid fa-check"></i> Seleccionar</button></td>
                                <td><strong>${exp}</strong></td>
                                <td>${nombreComp}</td>
                                <td>${curp}</td>
                                <td>${tel}</td>
                                <td><span style="background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-size:12px;">${tp}</span></td>
                            </tr>`;
                        });
                        tbody.innerHTML = html;
                    } else {
                        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #637381; padding: 20px;">No se encontraron pacientes. Puede registrar uno nuevo arriba.</td></tr>';
                    }
                })
                .catch(err => {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--imss-dorado);">Error de conexión en la búsqueda.</td></tr>';
                });
        }

        function abrirModalNuevoPaciente() {
            if (!validarMedico()) return;
            document.getElementById('input_medico_nuevo').value = $('#medicoGlobal').val();
            document.getElementById('modalNuevoPaciente').style.display = 'flex';
        }

        function cerrarModalNuevoPaciente() {
            document.getElementById('modalNuevoPaciente').style.display = 'none';
        }

        function abrirModalEditarPaciente() {
            if (!validarMedico()) return;
            const editMedicoInput = document.getElementById('input_medico_edit');
            if(editMedicoInput) editMedicoInput.value = $('#medicoGlobal').val();
            document.getElementById('modalEditarPaciente').style.display = 'flex';
        }

        function cerrarModalEditarPaciente() {
            document.getElementById('modalEditarPaciente').style.display = 'none';
        }

        function procesarCurp(input, prefix) {
            let curp = input.value.trim().toUpperCase();
            if (curp.length >= 11) {
                let yy = parseInt(curp.substring(4, 6), 10);
                let mm = parseInt(curp.substring(6, 8), 10) - 1;
                let dd = parseInt(curp.substring(8, 10), 10);
                
                let currentYear = new Date().getFullYear() % 100;
                let century = yy > currentYear ? 1900 : 2000;
                let birthYear = century + yy;
                let birthDate = new Date(birthYear, mm, dd);
                let fechaNacStr = birthDate.toISOString().split('T')[0];
                
                let elFnac = document.getElementById(prefix + 'fnacimiento');
                if(elFnac) elFnac.value = fechaNacStr;

                let elYear = document.getElementById(prefix + 'year_nacimiento');
                if(elYear) elYear.value = birthYear;

                let today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                let m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) { age--; }
                if (!isNaN(age) && age >= 0) {
                    let elEdad = document.getElementById(prefix + 'edad');
                    if(elEdad) elEdad.value = age;
                    let elEdadAct = document.getElementById(prefix + 'edad_actual');
                    if(elEdadAct) elEdadAct.value = age;
                }

                let sexoChar = curp.charAt(10);
                let elSexo = document.getElementById(prefix + 'sexo');
                if (elSexo) {
                    if (sexoChar === 'H') elSexo.value = 'MASCULINO';
                    else if (sexoChar === 'M') elSexo.value = 'FEMENINO';
                }

                let estadoCode = curp.substring(11, 13);
                const estadosMap = {'AS':'Aguascalientes', 'BC':'Baja California', 'BS':'Baja California Sur', 'CC':'Campeche', 'CL':'Coahuila', 'CM':'Colima', 'CS':'Chiapas', 'CH':'Chihuahua', 'DF':'Ciudad de México', 'DG':'Durango', 'GT':'Guanajuato', 'GR':'Guerrero', 'HG':'Hidalgo', 'JC':'Jalisco', 'MC':'México', 'MN':'Michoacán', 'MS':'Morelos', 'NT':'Nayarit', 'NL':'Nuevo León', 'OC':'Oaxaca', 'PL':'Puebla', 'QT':'Querétaro', 'QR':'Quintana Roo', 'SP':'San Luis Potosí', 'SL':'Sinaloa', 'SR':'Sonora', 'TC':'Tabasco', 'TS':'Tamaulipas', 'TL':'Tlaxcala', 'VZ':'Veracruz', 'YN':'Yucatán', 'ZS':'Zacatecas', 'NE':'Extranjero'};
                if (estadosMap[estadoCode]) {
                    let elEstado = document.getElementById(prefix + 'estado');
                    if(elEstado) elEstado.value = estadosMap[estadoCode].toUpperCase();
                }
            }
        }
    </script>
</body>
</html>