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
// 1.1 ENDPOINT AJAX: OBTENER HORARIOS OCUPADOS (PSICOLOGÍA)
// ===================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'obtener_ocupados') {
    header('Content-Type: application/json');
    $clinica_id = $_GET['clinica_id'] ?? '';
    $fecha_cita = $_GET['fecha_cita'] ?? '';

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

        $stmt = $pdo->prepare("SELECT hora_cita FROM citas_psicologia WHERE nombre_clinica = :nombre_clinica AND fecha_cita = :fecha_cita");
        $stmt->execute(['nombre_clinica' => $nombre_clinica, 'fecha_cita' => $fecha_cita]);
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
// 1.2 ENDPOINT AJAX: BÚSQUEDA AVANZADA DE PACIENTES (MULTIPALABRA)
// ===================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'buscar_pacientes_ajax') {
    header('Content-Type: application/json');
    $busqueda = mb_strtoupper(trim($_GET['q'] ?? ''));
    try {
        if (!empty($busqueda)) {
            $palabras = explode(' ', $busqueda);
            $conditions = [];
            $params = [];

            foreach ($palabras as $i => $palabra) {
                if (!empty($palabra)) {
                    $conditions[] = "(nombre ILIKE :q$i OR apaterno ILIKE :q$i OR amaterno ILIKE :q$i OR expediente ILIKE :q$i OR curp ILIKE :q$i)";
                    $params["q$i"] = "%$palabra%";
                }
            }

            $whereClause = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

            $stmt = $pdo->prepare("
                SELECT id_paciente, expediente, nombre, apaterno, amaterno, curp, telefono 
                FROM pacientes 
                WHERE $whereClause 
                LIMIT 30
            ");
            $stmt->execute($params);
        } else {
            $stmt = $pdo->query("
                SELECT id_paciente, expediente, nombre, apaterno, amaterno, curp, telefono 
                FROM pacientes 
                ORDER BY id_paciente DESC 
                LIMIT 30
            ");
        }
        $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($pacientes);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// ===================================================================
// 1.3 ENDPOINT AJAX: GENERAR NUEVO EXPEDIENTE CON SIGLAS SE-
// ===================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'generar_expediente_se') {
    header('Content-Type: application/json');
    try {
        $rand_num = rand(10000, 99999);
        $exp = 'SE-' . $rand_num;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE expediente = :exp");
        $stmt->execute(['exp' => $exp]);
        if ($stmt->fetchColumn() > 0) {
            $exp = 'SE-' . rand(10000, 99999);
        }
        echo json_encode(['expediente' => $exp]);
    } catch (Exception $e) {
        echo json_encode(['expediente' => 'SE-' . rand(10000, 99999)]);
    }
    exit();
}

// ===================================================================
// 1.4 ENDPOINT AJAX: OBTENER HISTORIAL DE NOTAS CLÍNICAS
// ===================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'obtener_notas_ajax') {
    header('Content-Type: application/json');
    $id_cita = intval($_GET['id_cita'] ?? 0);
    $expediente = trim($_GET['expediente'] ?? '');
    try {
        if (empty($expediente) && $id_cita > 0) {
            $stmt_c = $pdo->prepare("SELECT no_expediente FROM citas_psicologia WHERE id_cita = :id LIMIT 1");
            $stmt_c->execute(['id' => $id_cita]);
            $expediente = trim($stmt_c->fetchColumn() ?? '');
        }
        $notas = [];
        if (!empty($expediente)) {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(to_char(fecha_hora, 'YYYY-MM-DD HH24:MI'), to_char(created_at, 'YYYY-MM-DD HH24:MI'), 'S/F') AS fecha_nota,
                    motivo_consulta, observaciones, estrategias, seguimiento_tx, 
                    impresion_dx, impresion_diagnostica_dsm, plan_diagnostico, pronostico, proxima_cita
                FROM notas_psicologia 
                WHERE no_expediente = :exp 
                ORDER BY id_nota DESC
            ");
            $stmt->execute(['exp' => $expediente]);
            $notas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($notas);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// ===================================================================
// 2. PROCESAMIENTO DE FORMULARIOS (PACIENTES, CITAS Y NOTAS)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion_post = $_POST['accion_post'] ?? '';

    // Guardar Nota de Psicología Completa (Estructurada SOAP, DSM-IV-TR y Pronóstico)
    if ($accion_post === 'guardar_nota_psicologia') {
        try {
            $id_cita                   = intval($_POST['id_cita'] ?? 0);
            $expediente                = trim($_POST['expediente'] ?? '');
            $fecha_hora                = $_POST['fecha_hora'] ?? date('Y-m-d H:i:s');
            $motivo_consulta           = mb_strtoupper(trim($_POST['motivo_consulta'] ?? ''));
            $observaciones             = mb_strtoupper(trim($_POST['observaciones'] ?? ''));
            $estrategias               = mb_strtoupper(trim($_POST['estrategias'] ?? ''));
            $seguimiento_tx            = mb_strtoupper(trim($_POST['seguimiento_tx'] ?? ''));
            $impresion_dx              = mb_strtoupper(trim($_POST['impresion_dx'] ?? ''));
            $impresion_diagnostica_dsm = mb_strtoupper(trim($_POST['impresion_diagnostica_dsm'] ?? ''));
            $plan_diagnostico          = mb_strtoupper(trim($_POST['plan_diagnostico'] ?? ''));
            $pronostico                = mb_strtoupper(trim($_POST['pronostico'] ?? ''));
            $proxima_cita              = mb_strtoupper(trim($_POST['proxima_cita'] ?? ''));
            $usuario_id                = intval($_SESSION['usuario_id']);

            // Asegurar tabla notas_psicologia con todos los campos requeridos
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notas_psicologia (
                    id_nota SERIAL PRIMARY KEY,
                    id_cita INT,
                    no_expediente VARCHAR(50),
                    fecha_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    motivo_consulta TEXT,
                    observaciones TEXT,
                    estrategias TEXT,
                    seguimiento_tx TEXT,
                    impresion_dx VARCHAR(255),
                    impresion_diagnostica_dsm TEXT,
                    plan_diagnostico TEXT,
                    pronostico TEXT,
                    proxima_cita VARCHAR(100),
                    usuario_id INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $stmt = $pdo->prepare("
                INSERT INTO notas_psicologia (
                    id_cita, no_expediente, fecha_hora, motivo_consulta, observaciones, 
                    estrategias, seguimiento_tx, impresion_dx, impresion_diagnostica_dsm, 
                    plan_diagnostico, pronostico, proxima_cita, usuario_id
                )
                VALUES (
                    :id_cita, :exp, :f_hora, :motivo, :obs, 
                    :est, :seg, :imp, :dsm, 
                    :plan, :pron, :prox, :usr
                )
            ");
            $stmt->execute([
                'id_cita'   => $id_cita,
                'exp'       => $expediente,
                'f_hora'    => $fecha_hora,
                'motivo'    => $motivo_consulta,
                'obs'       => $observaciones,
                'est'       => $estrategias,
                'seg'       => $seguimiento_tx,
                'imp'       => $impresion_dx,
                'dsm'       => $impresion_diagnostica_dsm,
                'plan'      => $plan_diagnostico,
                'pron'      => $pronostico,
                'prox'      => $proxima_cita,
                'usr'       => $usuario_id
            ]);

            header("Location: psicologia.php?exito=nota");
            exit();
        } catch (Exception $e) {
            $err = urlencode(mb_strtoupper($e->getMessage()));
            header("Location: psicologia.php?error=" . $err);
            exit();
        }
    }

    // Registrar un nuevo paciente (con formato SE- y cálculo por CURP)
    if ($accion_post === 'registrar_paciente') {
        try {
            $expediente      = trim($_POST['expediente'] ?? '');
            $curp            = mb_strtoupper(trim($_POST['curp'] ?? ''));
            $telefono        = trim($_POST['telefono'] ?? '');
            $nombre          = mb_strtoupper(trim($_POST['nombre'] ?? ''));
            $apaterno        = mb_strtoupper(trim($_POST['apaterno'] ?? ''));
            $amaterno        = mb_strtoupper(trim($_POST['amaterno'] ?? ''));
            $edad            = trim($_POST['edad'] ?? '');
            $sexo            = mb_strtoupper(trim($_POST['sexo'] ?? ''));
            $fecha_nacimiento= trim($_POST['fecha_nacimiento'] ?? null);
            $municipio       = mb_strtoupper(trim($_POST['municipio'] ?? ''));
            $domicilio       = mb_strtoupper(trim($_POST['domicilio'] ?? ''));
            $colonia         = mb_strtoupper(trim($_POST['colonia'] ?? ''));
            $nombre_padre    = mb_strtoupper(trim($_POST['nombre_padre'] ?? ''));
            $edad_padre      = trim($_POST['edad_padre'] ?? null);
            $nombre_madre    = mb_strtoupper(trim($_POST['nombre_madre'] ?? ''));
            $edad_madre      = trim($_POST['edad_madre'] ?? null);
            $fecha_apertura  = trim($_POST['fecha_apertura'] ?? date('Y-m-d'));
            $estatus         = mb_strtoupper(trim($_POST['estatus'] ?? 'ACTIVO'));
            $tipo_paciente   = mb_strtoupper(trim($_POST['tipo_paciente'] ?? 'GENERAL'));

            if (empty($nombre) || empty($apaterno)) {
                throw new Exception("EL NOMBRE Y APELLIDO PATERNO SON OBLIGATORIOS.");
            }

            if (empty($expediente)) {
                $expediente = 'SE-' . rand(10000, 99999);
            }

            $stmt = $pdo->prepare("
                INSERT INTO pacientes (
                    expediente, nombre, apaterno, amaterno, curp, telefono, domicilio, 
                    edad, sexo, fecha_nacimiento, municipio, colonia, 
                    nombre_padre, edad_padre, nombre_madre, edad_madre, fecha_apertura, estatus, tipo_paciente
                )
                VALUES (
                    :exp, :nom, :apat, :amat, :curp, :tel, :dom, 
                    :edad, :sexo, :fnac, :mun, :col, 
                    :n_padre, :e_padre, :n_madre, :e_madre, :f_apertura, :est, :t_pac
                )
            ");
            $stmt->execute([
                'exp'        => $expediente,
                'nom'        => $nombre,
                'apat'       => $apaterno,
                'amat'       => $amaterno,
                'curp'       => !empty($curp) ? $curp : null,
                'tel'        => $telefono,
                'dom'        => !empty($domicilio) ? $domicilio : null,
                'edad'       => !empty($edad) ? $edad : null,
                'sexo'       => !empty($sexo) ? $sexo : null,
                'fnac'       => !empty($fecha_nacimiento) ? $fecha_nacimiento : null,
                'mun'        => !empty($municipio) ? $municipio : null,
                'col'        => !empty($colonia) ? $colonia : null,
                'n_padre'    => !empty($nombre_padre) ? $nombre_padre : null,
                'e_padre'    => !empty($edad_padre) ? $edad_padre : null,
                'n_madre'    => !empty($nombre_madre) ? $nombre_madre : null,
                'e_madre'    => !empty($edad_madre) ? $edad_madre : null,
                'f_apertura' => !empty($fecha_apertura) ? $fecha_apertura : date('Y-m-d'),
                'est'        => !empty($estatus) ? $estatus : 'ACTIVO',
                't_pac'      => !empty($tipo_paciente) ? $tipo_paciente : 'GENERAL'
            ]);

            header("Location: psicologia.php?exito=paciente");
            exit();
        } catch (Exception $e) {
            $err = urlencode(mb_strtoupper($e->getMessage()));
            header("Location: psicologia.php?error=" . $err);
            exit();
        }
    }

    // Agendar Cita de Psicología
    if ($accion_post === 'agendar_cita_psicologia') {
        $paciente_id  = intval($_POST['paciente_id'] ?? 0);
        $clinica_id   = intval($_POST['clinica_id'] ?? 0);
        $fecha_cita   = $_POST['fecha_cita'] ?? date('Y-m-d');
        $hora_cita    = $_POST['hora_cita'] ?? '08:00';
        $tipo_clinica = 'SALUD MENTAL';

        try {
            $stmt_p = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id LIMIT 1");
            $stmt_p->execute(['id' => $paciente_id]);
            $pac = $stmt_p->fetch(PDO::FETCH_ASSOC);

            if (!$pac) {
                throw new Exception("DEBE SELECCIONAR UN PACIENTE VÁLIDO DE LA LISTA.");
            }

            $stmt_c = $pdo->prepare("SELECT nombre_clinica FROM clinicas WHERE id_clinica = :id");
            $stmt_c->execute(['id' => $clinica_id]);
            $nombre_clinica = $stmt_c->fetchColumn() ?: 'SALUD MENTAL / PSICOLOGÍA';

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS citas_psicologia (
                    id_cita SERIAL PRIMARY KEY,
                    no_expediente VARCHAR(50),
                    nombre_paciente VARCHAR(255),
                    curp VARCHAR(20),
                    fecha_cita DATE,
                    hora_cita VARCHAR(10),
                    tipo_clinica VARCHAR(100),
                    nombre_clinica VARCHAR(255),
                    telefono VARCHAR(50),
                    tipo_paciente VARCHAR(50) DEFAULT 'GENERAL',
                    estatus VARCHAR(50) DEFAULT 'PROGRAMADA',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS entrevistas_psicologia (
                    id_entrevista SERIAL PRIMARY KEY,
                    id_cita INT,
                    no_expediente VARCHAR(50),
                    motivo_consulta TEXT,
                    impresion_diagnostica TEXT,
                    estrategias_intervencion TEXT,
                    seguimiento TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $nombre_completo = trim($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . ($pac['amaterno'] ?? ''));

            $stmt_ins = $pdo->prepare("
                INSERT INTO citas_psicologia (no_expediente, nombre_paciente, curp, fecha_cita, hora_cita, tipo_clinica, nombre_clinica, telefono, estatus)
                VALUES (:exp, :nom, :curp, :f_cita, :h_cita, :t_cli, :n_cli, :tel, 'PROGRAMADA')
                RETURNING id_cita
            ");
            $stmt_ins->execute([
                'exp'   => $pac['expediente'] ?? 'S/N',
                'nom'   => mb_strtoupper($nombre_completo),
                'curp'  => mb_strtoupper($pac['curp'] ?? ''),
                'f_cita'=> $fecha_cita,
                'h_cita'=> $hora_cita,
                't_cli' => mb_strtoupper($tipo_clinica),
                'n_cli' => mb_strtoupper($nombre_clinica),
                'tel'   => $pac['telefono'] ?? ''
            ]);

            header("Location: psicologia.php?exito=cita");
            exit();
        } catch (Exception $e) {
            $err = urlencode(mb_strtoupper($e->getMessage()));
            header("Location: psicologia.php?error=" . $err);
            exit();
        }
    }
}

// ===================================================================
// 3. FILTROS Y CONSULTAS DE LA AGENDA
// ===================================================================
$f_inicio         = $_GET['fecha_inicio'] ?? '';
$f_fin            = $_GET['fecha_fin'] ?? '';
$fecha_especifica = $_GET['fecha_especifica'] ?? '';
$filtro_fecha     = $_GET['filtro_fecha'] ?? '';
$limit            = $_GET['limit'] ?? '50';
$accion           = $_GET['accion'] ?? '';

if (!isset($_GET['filtro_fecha']) && !isset($_GET['fecha_inicio']) && !isset($_GET['fecha_fin']) && !isset($_GET['fecha_especifica'])) {
    $filtro_fecha = 'hoy';
}

if (!empty($filtro_fecha)) {
    $hoy = date('Y-m-d');
    switch ($filtro_fecha) {
        case 'hoy': $f_inicio = $hoy; $f_fin = $hoy; break;
        case 'manana': $f_inicio = date('Y-m-d', strtotime('+1 day')); $f_fin = date('Y-m-d', strtotime('+1 day')); break;
        case 'semana': $f_inicio = date('Y-m-d', strtotime('monday this week')); $f_fin = date('Y-m-d', strtotime('sunday this week')); break;
        case 'mes': $f_inicio = date('Y-m-01'); $f_fin = date('Y-m-t'); break;
    }
}
if (!empty($fecha_especifica)) {
    $f_inicio = $fecha_especifica;
    $f_fin    = $fecha_especifica;
}

$where = "WHERE 1=1";
$params = [];
if (!empty($f_inicio) && !empty($f_fin)) {
    $where .= " AND c.fecha_cita BETWEEN :f_inicio AND :f_fin";
    $params['f_inicio'] = $f_inicio;
    $params['f_fin']    = $f_fin;
}

if ($accion === 'eliminar' && isset($_GET['id'])) {
    try {
        $id_del = (int)$_GET['id'];
        $pdo->prepare("DELETE FROM entrevistas_psicologia WHERE id_cita = :id")->execute(['id' => $id_del]);
        $pdo->prepare("DELETE FROM citas_psicologia WHERE id_cita = :id")->execute(['id' => $id_del]);
    } catch (Exception $e) {}
    header("Location: psicologia.php");
    exit();
}

$citas = [];
try {
    $sql_main = "
        SELECT c.*, 
               EXISTS(SELECT 1 FROM entrevistas_psicologia e WHERE e.id_cita = c.id_cita) as tiene_entrevista,
               (SELECT COUNT(*) FROM notas_psicologia n WHERE n.no_expediente = c.no_expediente) as total_notas,
               p.edad as pac_edad, p.sexo as pac_sexo
        FROM citas_psicologia c 
        LEFT JOIN pacientes p ON c.no_expediente = p.expediente OR c.curp = p.curp
        $where 
        ORDER BY id_cita DESC
    ";
    if ($limit !== 'all') {
        $sql_main .= " LIMIT " . ((int)$limit > 0 ? (int)$limit : 50);
    }
    $stmt = $pdo->prepare($sql_main);
    $stmt->execute($params);
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Obtener clínicas de Salud Mental
$lista_clinicas_psico = [];
try {
    $stmt_cli = $pdo->query("SELECT * FROM clinicas WHERE UPPER(tipo_clinica) LIKE '%SALUD MENTAL%' OR UPPER(tipo_clinica) LIKE '%PSICOL%' OR UPPER(nombre_clinica) LIKE '%SALUD MENTAL%' ORDER BY nombre_clinica ASC");
    $lista_clinicas_psico = $stmt_cli->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lista_clinicas_psico)) {
        $lista_clinicas_psico = $pdo->query("SELECT * FROM clinicas ORDER BY nombre_clinica ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | SALUD MENTAL - PSICOLOGÍA (SAISME)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --btn-azul: #1976d2; --btn-rojo: #d32f2f; --btn-morado: #673ab7;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; text-transform: uppercase; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 98%; margin: 25px auto; padding: 0 15px; }
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }
        .panel-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #637381; }
        .btn-red { background-color: var(--btn-rojo); }
        .btn-morado { background-color: var(--btn-morado); }
        .btn-paciente { background-color: var(--imss-dorado); }
        
        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); color: #637381; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        td { padding: 10px; border-bottom: 1px solid var(--borde); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
        tr:hover { background-color: #f8f9fa; }
        
        .asignar-wrapper { display: grid; grid-template-columns: 320px 1fr; gap: 20px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 4px solid var(--imss-verde); }
        .form-col { display: flex; flex-direction: column; gap: 15px; }
        .form-group label { display: block; margin-bottom: 4px; font-weight: bold; font-size: 11px; color: #637381; }
        .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 8px; border: 1px solid var(--borde); border-radius: 4px; font-size: 12px; box-sizing: border-box; text-transform: uppercase; background-color: #fff; }
        .form-group select:disabled { background-color: #e9ecef; color: #495057; cursor: not-allowed; }
        .tabla-pacientes-box { border: 1px solid var(--borde); border-radius: 6px; overflow: hidden; max-height: 250px; overflow-y: auto; background: #fff; }
        
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 900px; max-width: 95%; max-height: 90vh; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; }
        .modal-header { background: var(--imss-verde); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 15px 20px; background: #f1f3f5; text-align: right; border-top: 1px solid var(--borde); }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; display: inline-block; }
        .badge-verde { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .badge-gris { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .nota-historial-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; margin-bottom: 12px; font-size: 11px; }
        .nota-historial-card b { color: var(--imss-verde); }
    </style>
</head>
<body>
    <script>
        const clinicasPsicoData = <?php echo json_encode($lista_clinicas_psico); ?>;
    </script>

    <div class="navbar">
        <div><i class="fa-solid fa-brain"></i> <strong>SAISME: SERVICIO DE ATENCIÓN INTEGRAL A LA SALUD MENTAL</strong></div>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> VOLVER AL DASHBOARD</a>
    </div>

    <div class="container">
        <?php if (isset($_GET['exito'])): ?>
            <div style="background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-circle-check"></i> OPERACIÓN REALIZADA EXITOSAMENTE.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> ERROR: <?php echo htmlspecialchars(urldecode($_GET['error'])); ?>
            </div>
        <?php endif; ?>

        <!-- SECCIÓN PRINCIPAL: ASIGNAR CITA -->
        <form action="psicologia.php" method="POST" class="asignar-wrapper">
            <input type="hidden" name="accion_post" value="agendar_cita_psicologia">
            <input type="hidden" id="pacienteIdSeleccionado" name="paciente_id" required>

            <div class="form-col" style="border-right: 1px solid var(--borde); padding-right: 20px;">
                <h3 style="margin: 0 0 10px 0; font-size: 15px; color: var(--imss-verde);"><i class="fa-solid fa-calendar-days"></i> 1. DATOS DE LA CITA</h3>
                
                <div class="form-group">
                    <label>TIPO DE CLÍNICA:</label>
                    <select disabled>
                        <option selected>SALUD MENTAL</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>NÚCLEO BÁSICO / CLÍNICA:</label>
                    <select name="clinica_id" id="selectClinica" required onchange="cargarHorariosDisponibles()">
                        <option value="">SELECCIONE CONSULTORIO...</option>
                        <?php foreach ($lista_clinicas_psico as $cli): ?>
                            <option value="<?php echo $cli['id_clinica']; ?>"><?php echo htmlspecialchars($cli['nombre_clinica']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>FECHA DE LA CITA:</label>
                    <input type="date" name="fecha_cita" id="inputFechaCita" value="<?php echo date('Y-m-d'); ?>" required onchange="cargarHorariosDisponibles()">
                </div>

                <div class="form-group">
                    <label>HORA DISPONIBLE:</label>
                    <select name="hora_cita" id="selectHoraCita" required>
                        <option value="">SELECCIONE CONSULTORIO Y FECHA...</option>
                    </select>
                </div>

                <div style="margin-top: 15px;">
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 10px;">
                        <i class="fa-solid fa-check"></i> CONFIRMAR Y AGENDAR CITA
                    </button>
                </div>
            </div>

            <div class="form-col">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <h3 style="margin: 0; font-size: 15px; color: var(--imss-verde);"><i class="fa-solid fa-user-magnifying-glass"></i> 2. BUSCAR PACIENTE</h3>
                    <button type="button" class="btn btn-paciente" style="padding: 7px 14px; font-size: 12px;" onclick="abrirModalPaciente()">
                        <i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente
                    </button>
                </div>
                
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="inputBusquedaPaciente" placeholder="BUSCAR POR NOMBRE O EXPEDIENTE..." oninput="this.value = this.value.toUpperCase(); buscarPacientesAjax();" style="flex: 1;">
                    <button type="button" class="btn btn-secondary" onclick="buscarPacientesAjax()"><i class="fa-solid fa-search"></i></button>
                </div>

                <div class="tabla-pacientes-box">
                    <table>
                        <colgroup>
                            <col style="width: 60px;">
                            <col style="width: 130px;">
                            <col style="width: auto;">
                            <col style="width: 160px;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th style="text-align: center;">SELECT</th>
                                <th>EXPEDIENTE</th>
                                <th>PACIENTE</th>
                                <th>CURP</th>
                            </tr>
                        </thead>
                        <tbody id="tablaPacientesResultados">
                            <tr><td colspan="4" style="text-align: center; color: #637381; padding: 20px;">ESCRIBA PARA BUSCAR PACIENTES...</td></tr>
                        </tbody>
                    </table>
                </div>
                <small id="pacienteSeleccionadoTexto" style="color: var(--imss-verde); font-weight: bold;"></small>
            </div>
        </form>

        <!-- PANEL DE FILTROS DE CITAS -->
        <div class="control-panel">
            <form id="filterForm" method="GET" class="panel-row" style="align-items: flex-end;">
                <input type="hidden" name="filtro_fecha" id="filtroFechaInput" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-size: 11px; font-weight: bold; color: #637381;">FECHA ESPECÍFICA:</label>
                        <input type="date" name="fecha_especifica" id="fechaEspecifica" value="<?php echo htmlspecialchars($fecha_especifica); ?>" onchange="submitFiltros()" style="padding: 7px; border: 1px solid var(--borde); border-radius: 4px;">
                    </div>
                    <div style="display: flex; gap: 4px; align-items: center;">
                        <button type="button" class="btn <?php echo ($filtro_fecha === 'hoy') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 11px;" onclick="aplicarFiltroFecha('hoy')">HOY</button>
                        <button type="button" class="btn <?php echo ($filtro_fecha === 'manana') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 11px;" onclick="aplicarFiltroFecha('manana')">MAÑANA</button>
                        <button type="button" class="btn <?php echo ($filtro_fecha === 'semana') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 11px;" onclick="aplicarFiltroFecha('semana')">SEMANA</button>
                        <button type="button" class="btn <?php echo ($filtro_fecha === 'mes') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 11px;" onclick="aplicarFiltroFecha('mes')">MES</button>
                    </div>
                </div>
                <div>
                    <a href="psicologia.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-right"></i> LIMPIAR FILTROS</a>
                </div>
            </form>
        </div>

        <!-- TABLA DE CITAS AGENDADAS -->
        <div class="table-container">
            <table>
                <colgroup>
                    <col style="width: 55px;">
                    <col style="width: 110px;">
                    <col style="width: auto;">
                    <col style="width: 150px;">
                    <col style="width: 100px;">
                    <col style="width: 75px;">
                    <col style="width: 150px;">
                    <col style="width: 90px;">
                    <col style="width: 115px; text-align: center;">
                    <col style="width: 220px; text-align: center;">
                </colgroup>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>EXPEDIENTE</th>
                        <th>PACIENTE</th>
                        <th>CURP</th>
                        <th>FECHA</th>
                        <th>HORA</th>
                        <th>CONSULTORIO</th>
                        <th>TELÉFONO</th>
                        <th style="text-align: center;">ENTREVISTA</th>
                        <th style="text-align: center;">ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($citas) > 0): ?>
                        <?php foreach ($citas as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['id_cita']); ?></td>
                                <td><strong><?php echo htmlspecialchars($c['no_expediente']); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['nombre_paciente']); ?></td>
                                <td><?php echo htmlspecialchars($c['curp']); ?></td>
                                <td><?php echo htmlspecialchars($c['fecha_cita']); ?></td>
                                <td><span style="background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-weight: bold;"><?php echo htmlspecialchars($c['hora_cita']); ?></span></td>
                                <td><?php echo htmlspecialchars($c['nombre_clinica']); ?></td>
                                <td><?php echo htmlspecialchars($c['telefono']); ?></td>
                                <td style="text-align: center;">
                                    <?php if (!empty($c['tiene_entrevista'])): ?>
                                        <span class="badge badge-verde"><i class="fa-solid fa-check"></i> COMPLETADA</span>
                                    <?php else: ?>
                                        <span class="badge badge-gris">PENDIENTE</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; white-space: nowrap;">
                                    <a href="entrevistas_psicologia.php?id_cita=<?php echo $c['id_cita']; ?>&expediente=<?php echo urlencode($c['no_expediente']); ?>" class="btn btn-morado" style="padding: 5px 8px;" title="ENTREVISTA / HISTORIA CLÍNICA">
                                        <i class="fa-solid fa-comments"></i>
                                    </a>
                                    <!-- BOTÓN NOTAS QUE ABRE EL MODAL CON HISTORIAL Y FORMULARIO DE NOTA CLÍNICA -->
                                    <button type="button" class="btn" style="background-color: var(--imss-verde); padding: 5px 8px;" onclick="abrirModalNotaPsicologia(
                                        <?php echo $c['id_cita']; ?>, 
                                        '<?php echo htmlspecialchars($c['no_expediente']); ?>', 
                                        '<?php echo htmlspecialchars($c['nombre_paciente']); ?>', 
                                        '<?php echo htmlspecialchars($c['curp']); ?>', 
                                        '<?php echo htmlspecialchars($c['pac_edad'] ?? 'S/N'); ?>', 
                                        '<?php echo htmlspecialchars($c['pac_sexo'] ?? 'S/N'); ?>'
                                    )" title="NOTA CLÍNICA PSICOLÓGICA">
                                        <i class="fa-solid fa-notes-medical"></i> NOTAS (<?php echo $c['total_notas']; ?>)
                                    </button>
                                    <a href="psicologia.php?accion=eliminar&id=<?php echo $c['id_cita']; ?>" class="btn btn-red" style="padding: 5px 8px;" onclick="return confirm('¿DESEA ELIMINAR ESTA CITA?');" title="ELIMINAR">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" style="text-align: center; color: #637381; padding: 30px;">NO HAY CITAS DE PSICOLOGÍA REGISTRADAS PARA ESTE PERIODO.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL DE NOTAS CLÍNICAS (INCLUYE FORMULARIO COMPLETO Y HISTORIAL VISIBLE) -->
    <div id="modalNotaPsicologia" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="tituloModalNota" style="margin: 0; font-size: 16px;"><i class="fa-solid fa-notes-medical"></i> NOTA CLÍNICA PSICOLÓGICA</h3>
                <button type="button" onclick="cerrarModalNotaPsicologia()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 320px; gap: 20px;">
                    <!-- COLUMNA IZQUIERDA: FORMULARIO DE NUEVA NOTA -->
                    <div>
                        <form action="psicologia.php" method="POST" id="formGuardarNota">
                            <input type="hidden" name="accion_post" value="guardar_nota_psicologia">
                            <input type="hidden" name="id_cita" id="nota_id_cita">
                            <input type="hidden" name="expediente" id="nota_expediente">

                            <div style="background: #f1f5f9; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 11px;">
                                <div><strong>PACIENTE:</strong> <span id="lblPacienteNombre"></span></div>
                                <div style="display: flex; gap: 15px; margin-top: 4px;">
                                    <span><strong>EXP:</strong> <span id="lblPacienteExp"></span></span>
                                    <span><strong>EDAD:</strong> <span id="lblPacienteEdad"></span></span>
                                    <span><strong>SEXO:</strong> <span id="lblPacienteSexo"></span></span>
                                    <span><strong>CURP:</strong> <span id="lblPacienteCurp"></span></span>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                <div class="form-group">
                                    <label>FECHA Y HORA:</label>
                                    <input type="datetime-local" name="fecha_hora" id="nota_fecha_hora" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>PRÓXIMA CITA (CÁLCULO / OPCIÓN):</label>
                                    <div style="display: flex; gap: 4px;">
                                        <input type="text" name="proxima_cita" id="nota_proxima_cita" placeholder="EJ. 15 DÍAS / ABIERTA">
                                        <select id="selectProximaRapida" onchange="calcularProximaCitaRapida(this.value)" style="width: 90px; font-size: 11px;">
                                            <option value="">CALCULAR</option>
                                            <option value="7 DÍAS">7 DÍAS</option>
                                            <option value="15 DÍAS">15 DÍAS</option>
                                            <option value="30 DÍAS">30 DÍAS</option>
                                            <option value="ABIERTA">ABIERTA</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>IMP. DX (CIE-10):</label>
                                <input type="text" name="impresion_dx" placeholder="EJ. F91.9 / TRASTORNO DE CONDUCTA..." required>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>MOTIVO DE CONSULTA (S):</label>
                                <textarea name="motivo_consulta" rows="2" placeholder="DESCRIBA EL MOTIVO DE CONSULTA..." required></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>OBSERVACIONES (O):</label>
                                <textarea name="observaciones" rows="2" placeholder="OBSERVACIONES CLÍNICAS DURANTE LA SESIÓN..."></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>ESTRATEGIAS DE INTERVENCIÓN (A):</label>
                                <textarea name="estrategias" rows="2" placeholder="ESTRATEGIAS, PSICOEDUCACIÓN..."></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>SEGUIMIENTO Y TX (P):</label>
                                <textarea name="seguimiento_tx" rows="2" placeholder="PLAN DE TRATAMIENTO Y SEGUIMIENTO..."></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>IMPRESIÓN DIAGNÓSTICA SEGÚN DSM-IV-TR:</label>
                                <textarea name="impresion_diagnostica_dsm" rows="2" placeholder="EJ. TDAH, TRASTORNO DE CONDUCTA..."></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>PLAN DIAGNÓSTICO:</label>
                                <textarea name="plan_diagnostico" rows="2" placeholder="MANTENER DIAGNÓSTICOS COMO HIPÓTESIS..."></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom: 15px;">
                                <label>PRONÓSTICO:</label>
                                <textarea name="pronostico" rows="2" placeholder="FAVORABLE, PACIENTE COLABORA..."></textarea>
                            </div>

                            <div style="text-align: right;">
                                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> GUARDAR NOTA CLÍNICA</button>
                            </div>
                        </form>
                    </div>

                    <!-- COLUMNA DERECHA: HISTORIAL DE NOTAS ANTERIORES -->
                    <div>
                        <label style="font-weight: bold; font-size: 12px; color: var(--imss-verde); display: block; margin-bottom: 8px;">
                            <i class="fa-solid fa-clock-rotate-left"></i> HISTORIAL DE NOTAS
                        </label>
                        <div id="contenedorHistorialModal" style="max-height: 550px; overflow-y: auto; background: #f1f5f9; padding: 10px; border-radius: 6px; border: 1px solid var(--borde);">
                            <p style="color: #637381; text-align: center; font-size: 11px;">CARGANDO HISTORIAL...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalNotaPsicologia()">CERRAR</button>
            </div>
        </div>
    </div>

    <!-- MODAL AGREGAR NUEVO PACIENTE -->
    <div id="modalPaciente" class="modal-overlay">
        <div class="modal-content" style="width: 750px;">
            <div class="modal-header" style="background-color: var(--imss-dorado);">
                <h3 style="margin: 0; font-size: 16px;"><i class="fa-solid fa-user-plus"></i> REGISTRAR NUEVO PACIENTE</h3>
                <button type="button" onclick="cerrarModalPaciente()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">&times;</button>
            </div>
            <form action="psicologia.php" method="POST">
                <input type="hidden" name="accion_post" value="registrar_paciente">
                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div class="form-group">
                            <label>NO. EXPEDIENTE *:</label>
                            <input type="text" name="expediente" id="inputExpediente" required readonly style="background-color: #f1f5f9; font-weight: bold;">
                        </div>
                        <div class="form-group">
                            <label>CURP *:</label>
                            <input type="text" name="curp" id="inputCurp" maxlength="18" placeholder="18 DÍGITOS" required oninput="this.value = this.value.toUpperCase(); calcularDatosCurp(this.value);">
                        </div>
                        <div class="form-group">
                            <label>TELÉFONO (10 DÍGITOS) *:</label>
                            <input type="text" name="telefono" maxlength="10" placeholder="EJ. 9371000000" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div class="form-group">
                            <label>NOMBRE(S) *:</label>
                            <input type="text" name="nombre" required oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label>APELLIDO PATERNO *:</label>
                            <input type="text" name="apaterno" required oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label>APELLIDO MATERNO:</label>
                            <input type="text" name="amaterno" oninput="this.value = this.value.toUpperCase()">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div class="form-group">
                            <label>EDAD (AUTO):</label>
                            <input type="text" name="edad" id="inputEdad" readonly style="background-color: #f8fafc;">
                        </div>
                        <div class="form-group">
                            <label>EDAD ACTUAL:</label>
                            <input type="text" name="edad_actual" id="inputEdadActual" readonly style="background-color: #f8fafc;">
                        </div>
                        <div class="form-group">
                            <label>SEXO (AUTO):</label>
                            <input type="text" name="sexo" id="inputSexo" readonly style="background-color: #f8fafc;">
                        </div>
                        <div class="form-group">
                            <label>ESTADO (AUTO):</label>
                            <input type="text" name="estado_nacimiento" id="inputEstadoNacimiento" readonly style="background-color: #f8fafc;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div class="form-group">
                            <label>FECHA DE NACIMIENTO:</label>
                            <input type="date" name="fecha_nacimiento" id="inputFechaNacimiento" style="background-color: #f8fafc;">
                        </div>
                        <div class="form-group">
                            <label>AÑO DE NACIMIENTO:</label>
                            <input type="text" name="anio_nacimiento" id="inputAnioNacimiento" readonly style="background-color: #f8fafc;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div class="form-group">
                            <label>MUNICIPIO:</label>
                            <input type="text" name="municipio" placeholder="EJ. HUIMANGUILLO" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label>DOMICILIO:</label>
                            <input type="text" name="domicilio" placeholder="CALLE Y NÚMERO" oninput="this.value = this.value.toUpperCase()">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>COLONIA / UBICACIÓN:</label>
                        <input type="text" name="colonia" placeholder="EJ. CENTRO" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr 2fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div class="form-group">
                            <label>NOMBRE DEL PADRE:</label>
                            <input type="text" name="nombre_padre" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label>EDAD PADRE:</label>
                            <input type="text" name="edad_padre">
                        </div>
                        <div class="form-group">
                            <label>NOMBRE DE LA MADRE:</label>
                            <input type="text" name="nombre_madre" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label>EDAD MADRE:</label>
                            <input type="text" name="edad_madre">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 0;">
                        <div class="form-group">
                            <label>FECHA APERTURA:</label>
                            <input type="date" name="fecha_apertura" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>ESTATUS:</label>
                            <select name="estatus">
                                <option value="ACTIVO" selected>ACTIVO</option>
                                <option value="INACTIVO">INACTIVO</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>PROGRAMA (TIPO PACIENTE):</label>
                            <select name="tipo_paciente">
                                <option value="GENERAL" selected>GENERAL</option>
                                <option value="ADULTO MAYOR">ADULTO MAYOR</option>
                                <option value="MENOR DE EDAD">MENOR DE EDAD</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalPaciente()">CANCELAR</button>
                    <button type="submit" class="btn btn-paciente"><i class="fa-solid fa-save"></i> GUARDAR PACIENTE</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.onload = function() {
            let clinicaSelect = document.getElementById('selectClinica');
            if (clinicaSelect.options.length > 1) {
                clinicaSelect.selectedIndex = 1;
                cargarHorariosDisponibles();
            }
            buscarPacientesAjax();
        };

        // GESTIÓN DEL MODAL DE NOTAS CLÍNICAS
        async function abrirModalNotaPsicologia(idCita, expediente, nombrePaciente, curp, edad, sexo) {
            document.getElementById('nota_id_cita').value = idCita;
            document.getElementById('nota_expediente').value = expediente;
            document.getElementById('lblPacienteNombre').textContent = nombrePaciente;
            document.getElementById('lblPacienteExp').textContent = expediente;
            document.getElementById('lblPacienteEdad').textContent = edad;
            document.getElementById('lblPacienteSexo').textContent = sexo;
            document.getElementById('lblPacienteCurp').textContent = curp;
            
            document.getElementById('tituloModalNota').innerHTML = `<i class="fa-solid fa-notes-medical"></i> NOTA CLÍNICA: ${nombrePaciente}`;
            document.getElementById('modalNotaPsicologia').style.display = 'flex';

            // Cargar historial de notas anteriores mediante AJAX interno
            let contenedor = document.getElementById('contenedorHistorialModal');
            contenedor.innerHTML = '<p style="color: #637381; text-align: center; font-size: 11px;">CONSULTANDO HISTORIAL...</p>';

            try {
                let res = await fetch(`psicologia.php?accion=obtener_notas_ajax&id_cita=${idCita}&expediente=${encodeURIComponent(expediente)}`);
                let notas = await res.json();

                contenedor.innerHTML = '';
                if (!notas || notas.length === 0) {
                    contenedor.innerHTML = '<p style="color: #637381; text-align: center; font-size: 11px;">NO HAY NOTAS PREVIAS REGISTRADAS.</p>';
                    return;
                }

                notas.forEach(n => {
                    let div = document.createElement('div');
                    div.className = 'nota-historial-card';
                    div.innerHTML = `
                        <div style="font-weight: bold; color: var(--imss-verde); border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-bottom: 6px;">
                            <i class="fa-regular fa-calendar"></i> ${n.fecha_nota}
                        </div>
                        <div><b>Motivo:</b> ${n.motivo_consulta || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>Observaciones:</b> ${n.observaciones || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>Estrategias:</b> ${n.estrategias || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>Seguimiento:</b> ${n.seguimiento_tx || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>Imp. DX:</b> ${n.impresion_dx || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>DSM-IV-TR:</b> ${n.impresion_diagnostica_dsm || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>Plan:</b> ${n.plan_diagnostico || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>Pronóstico:</b> ${n.pronostico || 'N/D'}</div>
                        <div style="margin-top: 3px;"><b>Próxima Cita:</b> ${n.proxima_cita || 'N/D'}</div>
                    `;
                    contenedor.appendChild(div);
                });
            } catch (e) {
                contenedor.innerHTML = '<p style="color: red; text-align: center; font-size: 11px;">ERROR AL CARGAR HISTORIAL.</p>';
            }
        }

        function cerrarModalNotaPsicologia() {
            document.getElementById('modalNotaPsicologia').style.display = 'none';
        }

        function calcularProximaCitaRapida(val) {
            if (val) {
                document.getElementById('nota_proxima_cita').value = val;
            }
        }

        async function abrirModalPaciente() {
            try {
                let res = await fetch('psicologia.php?accion=generar_expediente_se');
                let data = await res.json();
                document.getElementById('inputExpediente').value = data.expediente;
            } catch(e) {
                document.getElementById('inputExpediente').value = 'SE-' + Math.floor(10000 + Math.random() * 90000);
            }
            document.getElementById('modalPaciente').style.display = 'flex';
        }

        function cerrarModalPaciente() {
            document.getElementById('modalPaciente').style.display = 'none';
        }

        function calcularDatosCurp(curp) {
            if (!curp || curp.length < 10) return;
            
            let anioDig = curp.substring(4, 6);
            let mesDig = curp.substring(6, 8);
            let diaDig = curp.substring(8, 10);
            
            let anioInt = parseInt(anioDig, 10);
            let anioActual = new Date().getFullYear() % 100;
            let siglo = (anioInt > anioActual) ? '19' : '20';
            let anioCompleto = siglo + anioDig;
            
            let fechaNac = `${anioCompleto}-${mesDig}-${diaDig}`;
            document.getElementById('inputFechaNacimiento').value = fechaNac;
            document.getElementById('inputAnioNacimiento').value = anioCompleto;

            let birthDate = new Date(fechaNac);
            let today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            let m = today.getMonth() - birthDate.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            if (!isNaN(age) && age >= 0) {
                document.getElementById('inputEdad').value = age + " AÑOS";
                document.getElementById('inputEdadActual').value = age;
            }

            if (curp.length >= 11) {
                let sexoChar = curp.charAt(10).toUpperCase();
                if (sexoChar === 'H') {
                    document.getElementById('inputSexo').value = 'HOMBRE';
                } else if (sexoChar === 'M') {
                    document.getElementById('inputSexo').value = 'MUJER';
                }
            }
        }

        function aplicarFiltroFecha(tipo) {
            document.getElementById('filtroFechaInput').value = tipo;
            document.getElementById('fechaEspecifica').value = '';
            submitFiltros();
        }

        function submitFiltros() { document.getElementById('filterForm').submit(); }

        async function buscarPacientesAjax() {
            let query = document.getElementById('inputBusquedaPaciente').value.trim().toUpperCase();
            let tbody = document.getElementById('tablaPacientesResultados');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #637381; padding: 15px;">BUSCANDO...</td></tr>';

            try {
                let res = await fetch(`psicologia.php?accion=buscar_pacientes_ajax&q=${encodeURIComponent(query)}`);
                let pacientes = await res.json();

                tbody.innerHTML = '';
                if (pacientes.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #637381; padding: 15px;">NO SE ENCONTRARON PACIENTES.</td></tr>';
                    return;
                }

                pacientes.forEach(p => {
                    let tr = document.createElement('tr');
                    tr.style.cursor = 'pointer';
                    let expDisplay = (p.expediente && p.expediente.trim() !== '') ? p.expediente : 'S/N (VACÍO)';
                    let nombreCompleto = `${p.nombre || ''} ${p.apaterno || ''} ${p.amaterno || ''}`.trim();
                    
                    tr.onclick = function() { seleccionarPaciente(p.id_paciente, nombreCompleto); };
                    
                    tr.innerHTML = `
                        <td style="text-align: center;"><input type="radio" name="paciente_radio" value="${p.id_paciente}" onclick="seleccionarPaciente(${p.id_paciente}, '${nombreCompleto}')"></td>
                        <td><strong>${expDisplay.toUpperCase()}</strong></td>
                        <td>${nombreCompleto.toUpperCase()}</td>
                        <td>${(p.curp || 'S/N').toUpperCase()}</td>
                    `;
                    tbody.appendChild(tr);
                });
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: red; padding: 15px;">ERROR AL CARGAR PACIENTES.</td></tr>';
            }
        }

        function seleccionarPaciente(id, nombreCompleto) {
            document.getElementById('pacienteIdSeleccionado').value = id;
            document.getElementById('pacienteSeleccionadoTexto').innerHTML = `<i class="fa-solid fa-check-circle"></i> PACIENTE SELECCIONADO: ${nombreCompleto.toUpperCase()}`;
            
            let radios = document.querySelectorAll('input[name="paciente_radio"]');
            radios.forEach(r => {
                if (r.value == id) r.checked = true;
            });
        }

        function timeToMinutes(t) {
            let p = t.split(':');
            return parseInt(p[0]) * 60 + parseInt(p[1] || 0);
        }
        function minutesToTime(m) {
            let h = Math.floor(m / 60);
            let min = m % 60;
            return String(h).padStart(2, '0') + ':' + String(min).padStart(2, '0');
        }

        async function cargarHorariosDisponibles() {
            let clinicaId = document.getElementById('selectClinica').value;
            let fechaCita = document.getElementById('inputFechaCita').value;
            let horaSelect = document.getElementById('selectHoraCita');

            if (!clinicaId || !fechaCita) {
                horaSelect.innerHTML = '<option value="">SELECCIONE CONSULTORIO Y FECHA...</option>';
                return;
            }

            horaSelect.innerHTML = '<option value="">CARGANDO HORARIOS...</option>';

            let clinicaInfo = clinicasPsicoData.find(c => c.id_clinica == clinicaId);
            if (!clinicaInfo) return;

            let horasOcupadas = [];
            try {
                let res = await fetch(`psicologia.php?accion=obtener_ocupados&clinica_id=${clinicaId}&fecha_cita=${fechaCita}`);
                horasOcupadas = await res.json();
            } catch(e) {}

            let duracion = parseInt(clinicaInfo.duracion_cita || clinicaInfo.duracion_consulta || 30);
            let hInicio = timeToMinutes(clinicaInfo.hora_inicio || '07:00');
            let hFin = timeToMinutes(clinicaInfo.hora_fin || '14:00');

            horaSelect.innerHTML = '<option value="">SELECCIONE HORA...</option>';
            let current = hInicio;
            let disponibles = 0;

            while (current <= hFin) {
                let tFormatted = minutesToTime(current);
                let ocupado = horasOcupadas.includes(tFormatted);

                let opt = document.createElement('option');
                opt.value = tFormatted;
                if (ocupado) {
                    opt.textContent = `${tFormatted} HRS (OCUPADO)`;
                    opt.disabled = true;
                    opt.style.color = '#999';
                } else {
                    opt.textContent = `${tFormatted} HRS (DISPONIBLE)`;
                    disponibles++;
                }
                horaSelect.appendChild(opt);
                current += duracion;
            }

            if (disponibles === 0 && horaSelect.options.length <= 1) {
                horaSelect.innerHTML = '<option value="">NO HAY HORARIOS DISPONIBLES</option>';
            }
        }
    </script>
</body>
</html>