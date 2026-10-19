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

$es_admin = ($_SESSION['rol_id'] == 1);
$permisos_usuario = array_map('strtolower', $_SESSION['permisos'] ?? []);
$es_trabajo_social = !$es_admin && (in_array('trabajo_social', $permisos_usuario) || in_array('trabajo social', $permisos_usuario));

$error_msg = '';
$exito_msg = '';

$accion_post = $_POST['accion'] ?? '';

// ===================================================================
// 1.5 ENDPOINTS AJAX PARA SINCRONIZACIÓN SIHBI (PASO 1 Y PASO 2)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($accion_post === 'preview_sihbi')) {
    header('Content-Type: application/json');
    @set_time_limit(180);
    $script_path = __DIR__ . '/sincronizar.py';
    
    if (!file_exists($script_path)) {
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró el archivo sincronizar.py en el servidor.']);
        exit();
    }

    $output = [];
    $result_code = 0;
    exec("python3 " . escapeshellarg($script_path) . " 2>&1", $output, $result_code);
    
    $json_output = implode("\n", $output);
    $data_pacientes = json_decode($json_output, true);

    if (is_array($data_pacientes)) {
        echo json_encode(['success' => true, 'pacientes' => $data_pacientes]);
    } else {
        echo json_encode(['success' => false, 'mensaje' => 'Error al extraer datos de SIHBI: ' . $json_output]);
    }
    exit();
}

// NUEVO: Extraer detalles profundos de los pacientes seleccionados para mostrarlos antes de guardar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($accion_post === 'extraer_detalles_seleccionados')) {
    header('Content-Type: application/json');
    @set_time_limit(300);
    $pacientes_seleccionados = $_POST['pacientes'] ?? [];
    $script_path = __DIR__ . '/sincronizar.py';
    
    $detalles_extraidos = [];

    try {
        foreach ($pacientes_seleccionados as $p) {
            $url_expediente = trim($p['url_expediente'] ?? '');
            $expediente_base = strtoupper(trim($p['expediente'] ?? ''));

            $datos_detalle = [
                "expediente" => $expediente_base,
                "nombre" => $p['nombre'] ?? '',
                "apaterno" => "", "amaterno" => "",
                "sexo" => $p['sexo'] ?? '',
                "fnacimiento" => "", "tipo_sanguineo" => "", "rfc" => "",
                "telefono" => "", "correo" => "", "curp" => "",
                "estado" => "", "municipio" => "", "localidad" => "",
                "tipo_asentamiento" => "", "calle" => "",
                "num_interior" => "", "num_exterior" => "", "domicilio" => ""
            ];

            if (!empty($url_expediente) && file_exists($script_path)) {
                $output_det = [];
                exec("python3 " . escapeshellarg($script_path) . " --url " . escapeshellarg($url_expediente) . " 2>&1", $output_det);
                $json_det = json_decode(implode("\n", $output_det), true);
                if (is_array($json_det) && count($json_det) > 0) {
                    $d = $json_det[0];
                    $datos_detalle['nombre'] = strtoupper(trim($d['nombre'] ?? $p['nombre'] ?? ''));
                    $datos_detalle['apaterno'] = strtoupper(trim($d['apaterno'] ?? ''));
                    $datos_detalle['amaterno'] = strtoupper(trim($d['amaterno'] ?? ''));
                    $datos_detalle['sexo'] = strtoupper(trim($d['sexo'] ?? $p['sexo'] ?? ''));
                    $datos_detalle['fnacimiento'] = trim($d['fnacimiento'] ?? '');
                    $datos_detalle['tipo_sanguineo'] = strtoupper(trim($d['tipo_sanguineo'] ?? ''));
                    $datos_detalle['rfc'] = strtoupper(trim($d['rfc'] ?? ''));
                    $datos_detalle['telefono'] = trim($d['telefono'] ?? '');
                    $datos_detalle['correo'] = trim($d['correo'] ?? '');
                    $datos_detalle['curp'] = strtoupper(trim($d['curp'] ?? ''));
                    $datos_detalle['estado'] = strtoupper(trim($d['estado'] ?? ''));
                    $datos_detalle['municipio'] = strtoupper(trim($d['municipio'] ?? ''));
                    $datos_detalle['localidad'] = strtoupper(trim($d['localidad'] ?? ''));
                    $datos_detalle['tipo_asentamiento'] = strtoupper(trim($d['tipo_asentamiento'] ?? ''));
                    $datos_detalle['calle'] = strtoupper(trim($d['calle'] ?? ''));
                    $datos_detalle['num_interior'] = strtoupper(trim($d['num_interior'] ?? ''));
                    $datos_detalle['num_exterior'] = strtoupper(trim($d['num_exterior'] ?? ''));
                    $datos_detalle['domicilio'] = strtoupper(trim($d['domicilio'] ?? ''));
                }
            }
            $detalles_extraidos[] = $datos_detalle;
        }

        echo json_encode(['success' => true, 'detalles' => $detalles_extraidos]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'mensaje' => 'Error al extraer detalles: ' . $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($accion_post === 'guardar_masivo_sihbi')) {
    header('Content-Type: application/json');
    $pacientes_a_guardar = $_POST['pacientes'] ?? [];
    
    try {
        $actualizados = 0;
        $insertados = 0;

        foreach ($pacientes_a_guardar as $p) {
            $expediente  = strtoupper(trim($p['expediente'] ?? ''));
            if (empty($expediente)) continue;

            $nombre      = strtoupper(trim($p['nombre'] ?? ''));
            $apaterno    = strtoupper(trim($p['apaterno'] ?? ''));
            $amaterno    = strtoupper(trim($p['amaterno'] ?? ''));
            $sexo        = strtoupper(trim($p['sexo'] ?? ''));
            $curp        = strtoupper(trim($p['curp'] ?? ''));
            $telefono    = trim($p['telefono'] ?? '');
            $domicilio   = strtoupper(trim($p['domicilio'] ?? ''));
            $fnac        = trim($p['fnacimiento'] ?? '');
            $estado      = strtoupper(trim($p['estado'] ?? ''));
            $nombre_comp = trim("$nombre $apaterno $amaterno");
            if (empty($nombre_comp)) $nombre_comp = $nombre;

            $stmt_chk = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE expediente = :exp");
            $stmt_chk->execute(['exp' => $expediente]);
            $existe = $stmt_chk->fetch(PDO::FETCH_ASSOC);

            if ($existe) {
                $stmt_upd = $pdo->prepare("
                    UPDATE pacientes 
                    SET nombre = COALESCE(NULLIF(:nom, ''), nombre),
                        apaterno = COALESCE(NULLIF(:apat, ''), apaterno),
                        amaterno = COALESCE(NULLIF(:amat, ''), amaterno),
                        nombre_completo = COALESCE(NULLIF(:ncomp, ''), nombre_completo),
                        sexo = COALESCE(NULLIF(:sexo, ''), sexo),
                        curp = COALESCE(NULLIF(:curp, ''), curp),
                        telefono = COALESCE(NULLIF(:tel, ''), telefono),
                        domicilio = COALESCE(NULLIF(:dom, ''), domicilio),
                        fnacimiento = COALESCE(NULLIF(:fnac, ''), fnacimiento),
                        estado = COALESCE(NULLIF(:edo, ''), estado)
                    WHERE expediente = :exp
                ");
                $stmt_upd->execute([
                    'nom'   => $nombre, 'apat' => $apaterno, 'amat' => $amaterno,
                    'ncomp' => $nombre_comp, 'sexo' => $sexo, 'curp' => $curp,
                    'tel'   => $telefono, 'dom' => $domicilio, 
                    'fnac'  => !empty($fnac) ? $fnac : null,
                    'edo'   => $estado, 'exp' => $expediente
                ]);
                $actualizados++;
            } else {
                $stmt_ins = $pdo->prepare("
                    INSERT INTO pacientes (
                        expediente, nombre, apaterno, amaterno, nombre_completo, nombre_apellidos, 
                        sexo, curp, telefono, domicilio, fnacimiento, estado, estatus, fecha_registro
                    ) VALUES (
                        :exp, :nom, :apat, :amat, :ncomp, :napat, 
                        :sexo, :curp, :tel, :dom, :fnac, :edo, 'Vigente', CURRENT_TIMESTAMP
                    )
                ");
                $stmt_ins->execute([
                    'exp'   => $expediente, 'nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno,
                    'ncomp' => $nombre_comp, 'napat' => $nombre_comp, 'sexo' => $sexo,
                    'curp'  => $curp, 'tel' => $telefono, 'dom' => $domicilio,
                    'fnac'  => !empty($fnac) ? $fnac : null, 'edo' => $estado
                ]);
                $insertados++;
            }
        }

        echo json_encode([
            'success' => true, 
            'mensaje' => "Sincronización aplicada con éxito. Insertados: $insertados, Actualizados: $actualizados."
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'mensaje' => 'Error en base de datos: ' . $e->getMessage()]);
    }
    exit();
}

// ===================================================================
// 2. AJAX: OBTENER CAMAS DISPONIBLES
// ===================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'obtener_camas_disponibles') {
    header('Content-Type: application/json');
    $area_nombre = trim($_GET['area'] ?? '');

    try {
        $todas_camas_area = [];
        $stmtC = $pdo->prepare("
            SELECT DISTINCT c.numero_cama FROM camas c
            LEFT JOIN areas_hospital ah ON c.id_area = ah.id_area
            LEFT JOIN areas_atencion aa ON c.id_area = aa.id_area
            WHERE TRIM(UPPER(ah.nombre)) = TRIM(UPPER(:area)) 
               OR TRIM(UPPER(aa.nombre_area)) = TRIM(UPPER(:area))
            ORDER BY c.numero_cama ASC
        ");
        $stmtC->execute(['area' => $area_nombre]);
        $todas_camas_area = $stmtC->fetchAll(PDO::FETCH_COLUMN);

        $stmtOcupadas = $pdo->prepare("
            SELECT DISTINCT cama FROM censo 
            WHERE TRIM(UPPER(area_actual)) = TRIM(UPPER(:area))
              AND (estatus_censo IS NULL OR TRIM(UPPER(estatus_censo)) != 'DADO DE ALTA')
        ");
        $stmtOcupadas->execute(['area' => $area_nombre]);
        $camas_ocupadas = $stmtOcupadas->fetchAll(PDO::FETCH_COLUMN);
        $camas_ocupadas_upper = array_map(function($c) { return trim(strtoupper($c)); }, $camas_ocupadas);

        $camas_disponibles = [];
        foreach ($todas_camas_area as $cama) {
            $cama_limpia = trim(strtoupper($cama));
            if (!in_array($cama_limpia, $camas_ocupadas_upper)) {
                $camas_disponibles[] = $cama;
            }
        }

        echo json_encode(['success' => true, 'camas' => $camas_disponibles]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'camas' => [], 'error' => $e->getMessage()]);
    }
    exit();
}

// ===================================================================
// 3. PROCESAMIENTO DE FORMULARIOS (PACIENTES E INGRESOS)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($accion_post === 'nuevo_paciente' || $accion_post === 'editar_paciente')) {
    if (isset($_POST['validar_curp_ajax']) && $_POST['validar_curp_ajax'] === '1') {
        header('Content-Type: application/json');
        $curp_val = strtoupper(trim($_POST['curp'] ?? ''));
        $excluir_id = intval($_POST['excluir_id'] ?? 0);
        try {
            if ($excluir_id > 0) {
                $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp AND id_paciente <> :id");
                $stmt_val->execute(['curp' => $curp_val, 'id' => $excluir_id]);
            } else {
                $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp");
                $stmt_val->execute(['curp' => $curp_val]);
            }
            $existe = $stmt_val->fetchColumn() > 0;
            echo json_encode(['existe' => $existe]);
        } catch (Exception $e) {
            echo json_encode(['existe' => false]);
        }
        exit();
    }

    $id_paciente_post = intval($_POST['id_paciente'] ?? 0);
    $expediente       = strtoupper(trim($_POST['expediente'] ?? ''));
    $nombre           = strtoupper(trim($_POST['nombre'] ?? ''));
    $apaterno         = strtoupper(trim($_POST['apaterno'] ?? ''));
    $amaterno         = strtoupper(trim($_POST['amaterno'] ?? ''));
    $curp             = strtoupper(trim($_POST['curp'] ?? ''));
    $fnacimiento      = trim($_POST['fnacimiento'] ?? '');
    $edad             = intval($_POST['edad'] ?? 0);
    $edad_actual      = intval($_POST['edad_actual'] ?? $edad);
    $sexo             = strtoupper(trim($_POST['sexo'] ?? ''));
    $estado           = strtoupper(trim($_POST['estado'] ?? ''));
    $domicilio        = strtoupper(trim($_POST['domicilio'] ?? ''));
    $colonia          = strtoupper(trim($_POST['colonia'] ?? ''));
    $nombre_padre     = strtoupper(trim($_POST['nombre_padre'] ?? ''));
    $nombre_madre     = strtoupper(trim($_POST['nombre_madre'] ?? ''));
    $edad_padre       = trim($_POST['edad_padre'] ?? '');
    $edad_madre       = trim($_POST['edad_madre'] ?? '');
    $fecha_apertura   = trim($_POST['fecha_apertura'] ?? '');
    $telefono         = trim($_POST['telefono'] ?? '');
    $derechohabiencia = strtoupper(trim($_POST['derechohabiencia'] ?? ''));
    $estatus          = trim($_POST['estatus'] ?? 'Vigente');
    $year_nacimiento  = !empty($fnacimiento) ? substr($fnacimiento, 0, 4) : '';

    if (empty($telefono) || !preg_match('/^\d{10}$/', $telefono)) {
        $error_msg = "El teléfono es obligatorio y debe contener exactamente 10 dígitos numéricos.";
    } elseif (empty($curp)) {
        $error_msg = "El CURP es obligatorio.";
    } else {
        try {
            if ($id_paciente_post > 0) {
                $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp AND id_paciente <> :id");
                $stmt_val->execute(['curp' => $curp, 'id' => $id_paciente_post]);
            } else {
                $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp");
                $stmt_val->execute(['curp' => $curp]);
            }

            if ($stmt_val->fetchColumn() > 0) {
                $error_msg = "El CURP ingresado ya se encuentra registrado en el sistema con otro paciente.";
            } else {
                $nombre_completo = trim("$nombre $apaterno $amaterno");

                if ($id_paciente_post > 0) {
                    $stmt_upd = $pdo->prepare("
                        UPDATE pacientes SET 
                            expediente = :exp, nombre = :nom, apaterno = :apat, amaterno = :amat, 
                            curp = :curp, fnacimiento = :fnac, year_nacimiento = :ynac, 
                            edad = :edad, edad_actual = :eact, sexo = :sexo, estado = :edo, 
                            domicilio = :dom, colonia = :col, nombre_padre = :npad, nombre_madre = :nmad, 
                            edad_padre = :epad, edad_madre = :emad, fecha_apertura = :fape, 
                            estatus = :est, telefono = :tel, nombre_completo = :ncomp, 
                            nombre_apellidos = :napat, derechohabiencia = :derech
                        WHERE id_paciente = :id_pac
                    ");
                    $stmt_upd->execute([
                        'exp'    => $expediente, 'nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno,
                        'curp'   => $curp, 'fnac' => !empty($fnacimiento) ? $fnacimiento : null, 'ynac' => $year_nacimiento, 'edad' => $edad,
                        'eact'   => $edad_actual, 'sexo' => $sexo, 'edo' => $estado, 'dom' => $domicilio,
                        'col'    => $colonia, 'npad' => $nombre_padre, 'nmad' => $nombre_madre,
                        'epad'   => $edad_padre, 'emad' => $edad_madre, 'fape' => !empty($fecha_apertura) ? $fecha_apertura : null,
                        'est'    => $estatus, 'tel' => $telefono,
                        'ncomp'  => $nombre_completo, 'napat' => $nombre_completo, 'derech' => $derechohabiencia,
                        'id_pac' => $id_paciente_post
                    ]);
                    header("Location: trabajo_social.php?exito=2");
                    exit();
                } else {
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO pacientes (
                            expediente, nombre, apaterno, amaterno, curp, fnacimiento, year_nacimiento, 
                            edad, edad_actual, sexo, estado, domicilio, colonia, nombre_padre, nombre_madre, 
                            edad_padre, edad_madre, fecha_apertura, estatus, telefono, 
                            nombre_completo, nombre_apellidos, derechohabiencia, fecha_registro
                        ) VALUES (
                            :exp, :nom, :apat, :amat, :curp, :fnac, :ynac, 
                            :edad, :eact, :sexo, :edo, :dom, :col, :npad, :nmad, 
                            :epad, :emad, :fape, :est, :tel, 
                            :ncomp, :napat, :derech, CURRENT_TIMESTAMP
                        )
                    ");
                    $stmt_ins->execute([
                        'exp'    => $expediente, 'nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno,
                        'curp'   => $curp, 'fnac' => !empty($fnacimiento) ? $fnacimiento : null, 'ynac' => $year_nacimiento, 'edad' => $edad,
                        'eact'   => $edad_actual, 'sexo' => $sexo, 'edo' => $estado, 'dom' => $domicilio,
                        'col'    => $colonia, 'npad' => $nombre_padre, 'nmad' => $nombre_madre,
                        'epad'   => $edad_padre, 'emad' => $edad_madre, 'fape' => !empty($fecha_apertura) ? $fecha_apertura : null,
                        'est'    => $estatus, 'tel' => $telefono,
                        'ncomp'  => $nombre_completo, 'napat' => $nombre_completo, 'derech' => $derechohabiencia
                    ]);
                    header("Location: trabajo_social.php?exito=1");
                    exit();
                }
            }
        } catch (Exception $e) {
            $error_msg = "Error al guardar el paciente: " . $e->getMessage();
        }
    }
}

if ($accion_post === 'guardar_ingreso') {
    $id_paciente     = intval($_POST['id_paciente'] ?? 0);
    $area_actual     = trim($_POST['area_actual'] ?? '');
    $cama            = mb_strtoupper(trim($_POST['cama'] ?? ''), 'UTF-8');
    $medico_tratante = mb_strtoupper(trim($_POST['medico_tratante'] ?? ''), 'UTF-8');
    $dieta           = mb_strtoupper(trim($_POST['dieta'] ?? 'GENERAL'), 'UTF-8');
    $diagnostico     = mb_strtoupper(trim($_POST['diagnostico_ingreso'] ?? ''), 'UTF-8');
    $pendientes      = mb_strtoupper(trim($_POST['pendientes'] ?? ''), 'UTF-8');
    $fecha_ingreso   = trim($_POST['fecha_ingreso'] ?? date('Y-m-d'));
    $hora_ingreso    = trim($_POST['hora_ingreso'] ?? date('H:i'));

    if ($id_paciente > 0 && !empty($area_actual)) {
        try {
            $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS estatus_censo VARCHAR(50) DEFAULT 'ACTIVO'");
            $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS cama VARCHAR(50)");
            $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS curp VARCHAR(50)");
            $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS edad INT");
            $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS sexo VARCHAR(50)");
            $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS fecha_nacimiento DATE");
            $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS pendientes TEXT");

            $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
            $stmtP->execute(['id' => $id_paciente]);
            $pac = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($pac) {
                $nombre_completo = trim(($pac['nombre'] ?? '') . ' ' . ($pac['apaterno'] ?? '') . ' ' . ($pac['amaterno'] ?? ''));
                $curp = $pac['curp'] ?? '';
                $fnac = $pac['fnacimiento'] ?? null;
                $edad = intval($pac['edad_actual'] ?? $pac['edad'] ?? 0);
                $sexo = $pac['sexo'] ?? '';

                $dias_estancia = 0;
                if (!empty($fecha_ingreso)) {
                    $diff = strtotime(date('Y-m-d')) - strtotime($fecha_ingreso);
                    $dias_estancia = max(0, floor($diff / (60 * 60 * 24)));
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO censo (
                        id_paciente, nombre_paciente, curp, fecha_nacimiento, edad, sexo, 
                        area_actual, cama, fecha_ingreso, hora_ingreso, dias_estancia, 
                        diagnostico_ingreso, dieta, pendientes, medico_tratante_actual, estatus_censo
                    ) VALUES (
                        :id_p, :nom, :curp, :fnac, :edad, :sexo, 
                        :area, :cama, :f_ing, :h_ing, :dias, 
                        :diag, :dieta, :pend, :medico, 'ACTIVO'
                    )
                ");
                $stmtIns->execute([
                    'id_p'   => $id_paciente,
                    'nom'    => mb_strtoupper($nombre_completo, 'UTF-8'),
                    'curp'   => mb_strtoupper($curp, 'UTF-8'),
                    'fnac'   => !empty($fnac) ? $fnac : null,
                    'edad'   => $edad,
                    'sexo'   => mb_strtoupper($sexo, 'UTF-8'),
                    'area'   => $area_actual,
                    'cama'   => $cama,
                    'f_ing'  => $fecha_ingreso,
                    'h_ing'  => $hora_ingreso,
                    'dias'   => $dias_estancia,
                    'diag'   => $diagnostico,
                    'dieta'  => $dieta,
                    'pend'   => $pendientes,
                    'medico' => $medico_tratante
                ]);

                header("Location: trabajo_social.php?exito=3");
                exit();
            } else {
                $error_msg = "No se encontró el paciente seleccionado.";
            }
        } catch (Exception $e) {
            $error_msg = "Error al registrar el ingreso: " . $e->getMessage();
        }
    } else {
        $error_msg = "Debe seleccionar un paciente y un área de destino.";
    }
}

// ===================================================================
// 4. PARÁMETROS, PAGINACIÓN Y CATÁLOGOS
// ===================================================================
$search = strtoupper(trim($_GET['search'] ?? ''));
$estatus_filtro = $_GET['estatus'] ?? '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
if (!in_array($limit, [25, 50, 100, 500])) {
    $limit = 25;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$sort_by = $_GET['sort_by'] ?? 'id_paciente';
$order = $_GET['order'] ?? 'DESC';

$valid_columns = ['id_paciente', 'expediente', 'nombre', 'apaterno', 'amaterno', 'curp', 'sexo', 'edad', 'telefono', 'estatus', 'fecha_registro'];
if (!in_array($sort_by, $valid_columns)) {
    $sort_by = 'id_paciente';
}

$order = ($order === 'ASC') ? 'ASC' : 'DESC';
$next_order = ($order === 'ASC') ? 'DESC' : 'ASC';

$where_clauses = [];
$params = [];

if (!empty($search)) {
    $search_term = '%' . trim($search) . '%';
    $where_clauses[] = "(
        curp ILIKE :search_curp OR 
        expediente ILIKE :search_exp OR 
        nombre ILIKE :search_nom OR 
        apaterno ILIKE :search_apat OR 
        amaterno ILIKE :search_amat OR
        CONCAT_WS(' ', COALESCE(nombre,''), COALESCE(apaterno,''), COALESCE(amaterno,'')) ILIKE :search_full
    )";
    $params['search_curp']  = $search_term;
    $params['search_exp']   = $search_term;
    $params['search_nom']   = $search_term;
    $params['search_apat']  = $search_term;
    $params['search_amat']  = $search_term;
    $params['search_full']  = $search_term;
}

if ($estatus_filtro === 'Sin_Expediente') {
    $where_clauses[] = "(expediente IS NULL OR TRIM(expediente) = '' OR UPPER(TRIM(expediente)) = 'NULL' OR UPPER(TRIM(expediente)) LIKE 'SE%' OR UPPER(TRIM(expediente)) LIKE 'EX%')";
} elseif (!empty($estatus_filtro)) {
    $where_clauses[] = "TRIM(estatus) ILIKE :estatus";
    $params['estatus'] = "%$estatus_filtro%";
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

try {
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

    $stmt_derechohabiencias = $pdo->query("SELECT nombre_derechohabiencia FROM derechohabiencia ORDER BY nombre_derechohabiencia ASC");
    $lista_derechohabiencias = $stmt_derechohabiencias->fetchAll(PDO::FETCH_COLUMN);

    $stmt_areas = $pdo->query("SELECT DISTINCT nombre FROM areas_hospital WHERE estatus IS NULL OR UPPER(TRIM(estatus)) = 'ACTIVO' ORDER BY nombre ASC");
    $areas_hospital = $stmt_areas->fetchAll(PDO::FETCH_COLUMN);
    if (empty($areas_hospital)) {
        $areas_hospital = ['URGENCIAS / OBSERVACION', 'SALA DE CHOQUE', 'SALA DE OBSERVACIÓN', 'UCI (CUIDADOS INTENSIVOS)', 'PISO DE HOSPITALIZACIÓN', 'QUIRÓFANO'];
    }

    $stmt_m = $pdo->query("SELECT * FROM medicos");
    $cat_medicos = [];
    while ($m = $stmt_m->fetch(PDO::FETCH_ASSOC)) {
        $nombre = trim($m['nombre'] ?? $m['nombres'] ?? '');
        $apaterno = trim($m['apaterno'] ?? '');
        $amaterno = trim($m['amaterno'] ?? '');
        $cedula = trim($m['cedula'] ?? $m['cedula_profesional'] ?? $m['num_cedula'] ?? $m['no_cedula'] ?? '');
        $nombre_completo = trim("$nombre $apaterno $amaterno");
        if (!empty($nombre_completo)) {
            $cat_medicos[] = mb_strtoupper("$nombre_completo" . (!empty($cedula) ? " - CÉDULA: $cedula" : " - CÉDULA: N/D"), 'UTF-8');
        }
    }
    $cat_medicos = array_unique($cat_medicos);
    sort($cat_medicos);

} catch (Exception $e) {
    $pacientes = [];
    $total_pages = 1;
    $lista_derechohabiencias = [];
    $areas_hospital = ['URGENCIAS / OBSERVACION', 'SALA DE CHOQUE', 'PISO DE HOSPITALIZACIÓN'];
    $cat_medicos = [];
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
    <title>IMSS-BIENESTAR | Trabajo Social</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --btn-azul: #1976d2; --btn-ingreso: #0d9488; --btn-vista: #475569;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .container { max-width: 98%; width: 98%; margin: 25px auto; padding: 0 10px; box-sizing: border-box; }
        
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }
        input[type="text"], input[type="date"], input[type="number"], input[type="time"], select, textarea { padding: 5px 8px; border: 1px solid var(--borde); border-radius: 4px; font-size: 12px; outline: none; box-sizing: border-box; width: 100%; text-transform: uppercase; }
        input[type="text"], input[type="date"], input[type="number"], input[type="time"], select { height: 30px; }
        textarea { resize: vertical; }
        input:focus, select:focus, textarea:focus { border-color: var(--imss-verde); }
        input:disabled, select:disabled { background-color: #e9ecef !important; color: #6c757d !important; cursor: not-allowed; opacity: 1; }
        
        .btn { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px; display: inline-flex; align-items: center; gap: 5px; color: white; text-decoration: none; transition: opacity 0.2s;}
        .btn:hover { opacity: 0.9; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #637381; }
        .btn-sihbi { background-color: #0284c7; }
        .btn-icon { width: 32px; height: 32px; border: none; border-radius: 6px; cursor: pointer; color: white; display: inline-flex; justify-content: center; align-items: center; margin: 0 2px; text-decoration: none; }
        .btn-blue { background-color: var(--btn-azul); }
        .btn-ingreso-color { background-color: var(--btn-ingreso); }
        .btn-vista-color { background-color: var(--btn-vista); }
        
        .btn-estatus { padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; border: 1px solid var(--borde); background: #fff; color: #637381; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
        .btn-estatus.active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); white-space: nowrap; }
        th a { color: #637381; text-decoration: none; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        th a:hover { color: var(--imss-verde); }
        th i { font-size: 11px; opacity: 0.5; }
        th a.active i { opacity: 1; color: var(--imss-verde); }
        td { padding: 10px; border-bottom: 1px solid var(--borde); white-space: nowrap; vertical-align: middle;}
        tr:hover { background-color: #f8f9fa; }
        .acciones-col { text-align: center; white-space: nowrap; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 5px; margin-top: 20px; padding: 10px; }
        .pagination a, .pagination span { padding: 6px 12px; border-radius: 4px; font-size: 13px; text-decoration: none; border: 1px solid var(--borde); background: white; color: #1c252e; }
        .pagination a:hover { background: #f1f3f5; }
        .pagination .active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); }
        
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 950px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.25); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column; }
        .modal-header { background: var(--imss-verde); color: white; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
        .modal-header h3 { margin: 0; font-size: 15px; display: flex; align-items: center; gap: 8px; }
        .close-btn { background: none; border: none; color: white; font-size: 18px; cursor: pointer; }
        .modal-body { padding: 15px 20px; overflow-y: auto; flex: 1 1 auto; }
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .form-group { margin-bottom: 8px; }
        .form-group label { display: block; margin-bottom: 2px; font-weight: bold; font-size: 11px; color: #637381; }
        .modal-footer { padding: 12px 20px; background: #f1f3f5; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--borde); flex-shrink: 0; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-handshake-angle"></i> <strong>Módulo de Trabajo Social</strong></div>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver al Dashboard</a>
    </div>

    <div class="container">
        <?php if (!empty($error_msg)): ?>
            <div style="background: #ffebee; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['exito'])): ?>
            <div style="background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> Sincronización completada con éxito.
            </div>
        <?php endif; ?>

        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <div style="font-weight: bold; color: var(--imss-verde); font-size: 14px;">
                    <i class="fa-solid fa-users"></i> Listado General de Pacientes
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="ingresos_vista.php" class="btn btn-vista-color">
                        <i class="fa-solid fa-eye"></i> INGRESOS VISTA
                    </a>
                    <button type="button" class="btn btn-sihbi" onclick="consultarSihbiPreview()">
                        <i class="fa-solid fa-sync"></i> Sincronizar desde SIHBI
                    </button>
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevoPaciente()">
                        <i class="fa-solid fa-user-plus"></i> NUEVO PACIENTE +
                    </button>
                </div>
            </div>

            <form id="searchForm" method="GET" action="trabajo_social.php" style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 12px; margin-top: 5px;">
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; flex: 1;">
                    <div style="position: relative; width: 300px; display: flex; gap: 5px;">
                        <div style="position: relative; flex: 1;">
                            <i class="fa-solid fa-search" style="position: absolute; left: 9px; top: 10px; color: #637381; font-size: 12px; pointer-events: none;"></i>
                            <input type="text" id="searchInput" name="search" placeholder="NOMBRE, CURP, EXPEDIENTE..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding-left: 28px; padding-right: <?= !empty($search) ? '28px' : '8px' ?>; text-transform: uppercase;" autocomplete="off">
                            <?php if (!empty($search)): ?>
                                <a href="trabajo_social.php" title="Limpiar búsqueda" style="position: absolute; right: 8px; top: 8px; color: #637381; text-decoration: none; font-size: 12px; background: #e2e8f0; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-xmark" style="font-size: 10px;"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 30px; padding: 0 10px;" title="Buscar">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </div>

                    <input type="hidden" name="estatus" id="inputEstatus" value="<?= htmlspecialchars($estatus_filtro) ?>">
                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="btn-estatus <?= $estatus_filtro === 'Vigente' ? 'active' : '' ?>" onclick="filtrarEstatus('Vigente')">
                            <i class="fa-solid fa-check-circle"></i> VIGENTES
                        </button>
                        <button type="button" class="btn-estatus <?= $estatus_filtro === 'Sin_Expediente' ? 'active' : '' ?>" onclick="filtrarEstatus('Sin_Expediente')">
                            <i class="fa-solid fa-file-circle-xmark"></i> SIN EXPEDIENTE
                        </button>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #637381;">
                    <span>Mostrar:</span>
                    <select name="limit" onchange="document.getElementById('searchForm').submit()" style="padding: 5px; width: auto; height: 30px;">
                        <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    <span>registros</span>
                </div>
            </form>
        </div>

        <!-- VISTA GENERAL DE PACIENTES -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'id_paciente', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='id_paciente' ? 'active' : '' ?>">
                                ID <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='id_paciente' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='id_paciente' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'expediente', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='expediente' ? 'active' : '' ?>">
                                Expediente <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='expediente' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='expediente' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'nombre', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='nombre' ? 'active' : '' ?>">
                                Nombre <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='nombre' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='nombre' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'apaterno', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='apaterno' ? 'active' : '' ?>">
                                Apellido Paterno <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='apaterno' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='apaterno' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'amaterno', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='amaterno' ? 'active' : '' ?>">
                                Apellido Materno <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='amaterno' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='amaterno' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                            </a>
                        </th>
                        <th>
                            <a href="<?= buildUrl(['sort_by' => 'curp', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='curp' ? 'active' : '' ?>">
                                CURP <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='curp' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='curp' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                            </a>
                        </th>
                        <th>Sexo</th>
                        <th>Teléfono</th>
                        <th>Domicilio</th>
                        <th class="acciones-col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pacientes) > 0): ?>
                        <?php foreach ($pacientes as $p): 
                            $id_paciente = htmlspecialchars($p['id_paciente'] ?? $p['id'] ?? '');
                            $nombre_completo_row = trim(($p['nombre'] ?? '') . ' ' . ($p['apaterno'] ?? '') . ' ' . ($p['amaterno'] ?? ''));
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
                                <td><?php echo htmlspecialchars(str_replace('"', '', $p['domicilio'] ?? '')); ?></td>
                                <td class="acciones-col">
                                    <button type="button" class="btn-icon btn-ingreso-color" title="Ingresar Paciente al Censo" onclick='abrirModalIngresoPacienteDirecto(<?= $id_paciente ?>, "<?= htmlspecialchars($nombre_completo_row, ENT_QUOTES) ?>", "<?= htmlspecialchars($p['curp'] ?? '', ENT_QUOTES) ?>")'>
                                        <i class="fa-solid fa-bed-pulse"></i>
                                    </button>
                                    <button type="button" class="btn-icon btn-blue" title="Editar Paciente" onclick='abrirModalEditar(<?php echo json_encode($p); ?>)'>
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <a href="estudio_socioeconomico.php?id_paciente=<?= $id_paciente ?>" class="btn-icon btn-blue" title="Agregar Estudio Socioeconómico">
                                        <i class="fa-solid fa-file-lines"></i>
                                    </a>
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
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                        <span>...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= buildUrl(['page' => $page + 1]) ?>">Siguiente <i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL 1: VISTA PREVIA DE SEGUIMIENTO -->
    <div id="modalSihbiGrid" class="modal-overlay">
        <div class="modal-content" style="width: 1100px;">
            <div class="modal-header" style="background-color: #0284c7;">
                <h3><i class="fa-solid fa-sync"></i> Pacientes Extraídos desde SIHBI (Vista Previa)</h3>
                <button class="close-btn" onclick="cerrarModalSihbiGrid()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div id="sihbiLoading" style="text-align: center; padding: 40px; font-weight: bold; color: #0284c7;">
                    <i class="fa-solid fa-spinner fa-spin fa-2x"></i><br><br>Conectando con SIHBI y extrayendo pacientes... Por favor espere.
                </div>
                <div id="sihbiContentContainer" style="display: none;">
                    <p style="font-size: 12px; color: #637381; margin-bottom: 10px;">
                        Seleccione los pacientes que desea consultar o sincronizar:
                    </p>
                    <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--borde); border-radius: 6px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="background: #f1f3f5; position: sticky; top: 0;">
                                    <th style="padding: 8px; text-align: center; width: 40px;"><input type="checkbox" id="selectAllSihbi" checked onclick="toggleSelectAllSihbi(this)"></th>
                                    <th style="padding: 8px;">Expediente</th>
                                    <th style="padding: 8px;">Nombre Completo</th>
                                    <th style="padding: 8px;">Sexo</th>
                                    <th style="padding: 8px;">Fecha/Hora Llegada</th>
                                    <th style="padding: 8px;">Médico Responsable</th>
                                    <th style="padding: 8px;">Motivo Admisión</th>
                                </tr>
                            </thead>
                            <tbody id="tablaSihbiBody">
                                <!-- Dinámico -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalSihbiGrid()">Cancelar</button>
                <button type="button" class="btn btn-sihbi" id="btnExtraerDetalles" onclick="solicitarDetallesSeleccionados()" style="display: none;">
                    <i class="fa-solid fa-magnifying-glass"></i> Extraer y Ver Datos Detallados de Seleccionados
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL 2: REVISIÓN DE DATOS EXTRAÍDOS ANTES DE GUARDAR -->
    <div id="modalSihbiDetalles" class="modal-overlay">
        <div class="modal-content" style="width: 1250px;">
            <div class="modal-header" style="background-color: #0d9488;">
                <h3><i class="fa-solid fa-file-invoice"></i> Revisión de Datos Detallados Extraídos de SIHBI</h3>
                <button class="close-btn" onclick="cerrarModalDetalles()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div id="detallesLoading" style="text-align: center; padding: 40px; font-weight: bold; color: #0d9488; display: none;">
                    <i class="fa-solid fa-spinner fa-spin fa-2x"></i><br><br>Navegando a los expedientes y extrayendo los 16 campos desglosados... Esto puede tomar unos segundos.
                </div>
                <div id="detallesContentContainer">
                    <p style="font-size: 12px; color: #637381; margin-bottom: 10px;">
                        Verifique la información extraída de cada paciente. Al confirmar, se registrarán o actualizarán en su base de datos local:
                    </p>
                    <div style="max-height: 420px; overflow-y: auto; border: 1px solid var(--borde); border-radius: 6px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead>
                                <tr style="background: #f1f3f5; position: sticky; top: 0; z-index: 5;">
                                    <th style="padding: 6px;">Expediente</th>
                                    <th style="padding: 6px;">Nombre</th>
                                    <th style="padding: 6px;">1er Apellido</th>
                                    <th style="padding: 6px;">2do Apellido</th>
                                    <th style="padding: 6px;">CURP</th>
                                    <th style="padding: 6px;">Sexo</th>
                                    <th style="padding: 6px;">F. Nacimiento</th>
                                    <th style="padding: 6px;">Teléfono</th>
                                    <th style="padding: 6px;">Estado</th>
                                    <th style="padding: 6px;">Municipio</th>
                                    <th style="padding: 6px;">Domicilio Completo</th>
                                </tr>
                            </thead>
                            <tbody id="tablaDetallesBody">
                                <!-- Dinámico -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalDetalles()">Regresar</button>
                <button type="button" class="btn btn-primary" id="btnGuardarDefinitivo" onclick="guardarDatosDefinitivos()">
                    <i class="fa-solid fa-database"></i> Confirmar y Guardar en Base de Datos
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL PARA NUEVO O EDITAR PACIENTE -->
    <div id="modalNuevoPaciente" class="modal-overlay">
        <!-- ... formulario modal nuevo paciente ... -->
    </div>

    <!-- Scripts -->
    <script>
        let datosSihbiCache = [];
        let detallesCache = [];

        function consultarSihbiPreview() {
            document.getElementById('modalSihbiGrid').style.display = 'flex';
            document.getElementById('sihbiLoading').style.display = 'block';
            document.getElementById('sihbiContentContainer').style.display = 'none';
            document.getElementById('btnExtraerDetalles').style.display = 'none';

            let formData = new FormData();
            formData.append('accion', 'preview_sihbi');

            fetch('trabajo_social.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                document.getElementById('sihbiLoading').style.display = 'none';
                if (data.success) {
                    datosSihbiCache = data.pacientes || [];
                    let tbody = document.getElementById('tablaSihbiBody');
                    tbody.innerHTML = '';

                    if (datosSihbiCache.length > 0) {
                        datosSihbiCache.forEach((p, idx) => {
                            let tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td style="padding: 8px; text-align: center;"><input type="checkbox" class="chk-sihbi" data-index="${idx}" checked></td>
                                <td style="padding: 8px; font-weight: bold;">${p.expediente || ''}</td>
                                <td style="padding: 8px;">${p.nombre || ''}</td>
                                <td style="padding: 8px;">${p.sexo || ''}</td>
                                <td style="padding: 8px;">${p.fecha_llegada || ''}</td>
                                <td style="padding: 8px;">${p.medico || ''}</td>
                                <td style="padding: 8px;">${p.motivo || ''}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                        document.getElementById('sihbiContentContainer').style.display = 'block';
                        document.getElementById('btnExtraerDetalles').style.display = 'inline-flex';
                    } else {
                        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 20px; color:#637381;">No se encontraron pacientes activos.</td></tr>`;
                        document.getElementById('sihbiContentContainer').style.display = 'block';
                    }
                } else {
                    alert('Error: ' + data.mensaje);
                    cerrarModalSihbiGrid();
                }
            })
            .catch(err => {
                console.error(err);
                alert('Ocurrió un error al comunicarse con el servidor.');
                cerrarModalSihbiGrid();
            });
        }

        function cerrarModalSihbiGrid() {
            document.getElementById('modalSihbiGrid').style.display = 'none';
        }

        function cerrarModalDetalles() {
            document.getElementById('modalSihbiDetalles').style.display = 'none';
        }

        function toggleSelectAllSihbi(master) {
            document.querySelectorAll('.chk-sihbi').forEach(chk => chk.checked = master.checked);
        }

        // Paso 2: Solicitar extracción profunda de los seleccionados para mostrarlos en tabla de revisión
        function solicitarDetallesSeleccionados() {
            let seleccionados = [];
            document.querySelectorAll('.chk-sihbi:checked').forEach(chk => {
                let idx = chk.getAttribute('data-index');
                if (datosSihbiCache[idx]) {
                    seleccionados.push(datosSihbiCache[idx]);
                }
            });

            if (seleccionados.length === 0) {
                alert('Debe seleccionar al menos un paciente.');
                return;
            }

            cerrarModalSihbiGrid();
            document.getElementById('modalSihbiDetalles').style.display = 'flex';
            document.getElementById('detallesLoading').style.display = 'block';
            document.getElementById('detallesContentContainer').style.display = 'none';
            document.getElementById('btnGuardarDefinitivo').disabled = true;

            let formData = new FormData();
            formData.append('accion', 'extraer_detalles_seleccionados');
            seleccionados.forEach((p, i) => {
                formData.append(`pacientes[${i}][expediente]`, p.expediente);
                formData.append(`pacientes[${i}][nombre]`, p.nombre);
                formData.append(`pacientes[${i}][sexo]`, p.sexo);
                formData.append(`pacientes[${i}][url_expediente]`, p.url_expediente || '');
            });

            fetch('trabajo_social.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                document.getElementById('detallesLoading').style.display = 'none';
                if (data.success) {
                    detallesCache = data.detalles || [];
                    let tbody = document.getElementById('tablaDetallesBody');
                    tbody.innerHTML = '';

                    detallesCache.forEach((d, i) => {
                        let tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="padding: 6px; font-weight: bold;">${d.expediente || ''}</td>
                            <td style="padding: 6px;"><input type="text" value="${d.nombre || ''}" onchange="detallesCache[${i}].nombre = this.value" style="width: 100px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.apaterno || ''}" onchange="detallesCache[${i}].apaterno = this.value" style="width: 90px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.amaterno || ''}" onchange="detallesCache[${i}].amaterno = this.value" style="width: 90px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.curp || ''}" onchange="detallesCache[${i}].curp = this.value" style="width: 130px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.sexo || ''}" onchange="detallesCache[${i}].sexo = this.value" style="width: 70px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.fnacimiento || ''}" onchange="detallesCache[${i}].fnacimiento = this.value" style="width: 90px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.telefono || ''}" onchange="detallesCache[${i}].telefono = this.value" style="width: 90px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.estado || ''}" onchange="detallesCache[${i}].estado = this.value" style="width: 90px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.municipio || ''}" onchange="detallesCache[${i}].municipio = this.value" style="width: 90px;"></td>
                            <td style="padding: 6px;"><input type="text" value="${d.domicilio || ''}" onchange="detallesCache[${i}].domicilio = this.value" style="width: 180px;"></td>
                        `;
                        tbody.appendChild(tr);
                    });

                    document.getElementById('detallesContentContainer').style.display = 'block';
                    document.getElementById('btnGuardarDefinitivo').disabled = false;
                } else {
                    alert('Error: ' + data.mensaje);
                    cerrarModalDetalles();
                }
            })
            .catch(err => {
                console.error(err);
                alert('Ocurrió un error al extraer los detalles.');
                cerrarModalDetalles();
            });
        }

        // Paso 3: Guardar definitivamente los datos revisados en la base de datos
        function guardarDatosDefinitivos() {
            if (detallesCache.length === 0) return;

            let formData = new FormData();
            formData.append('accion', 'guardar_masivo_sihbi');
            detallesCache.forEach((d, i) => {
                formData.append(`pacientes[${i}][expediente]`, d.expediente);
                formData.append(`pacientes[${i}][nombre]`, d.nombre);
                formData.append(`pacientes[${i}][apaterno]`, d.apaterno);
                formData.append(`pacientes[${i}][amaterno]`, d.amaterno);
                formData.append(`pacientes[${i}][sexo]`, d.sexo);
                formData.append(`pacientes[${i}][curp]`, d.curp);
                formData.append(`pacientes[${i}][telefono]`, d.telefono);
                formData.append(`pacientes[${i}][domicilio]`, d.domicilio);
                formData.append(`pacientes[${i}][fnacimiento]`, d.fnacimiento);
                formData.append(`pacientes[${i}][estado]`, d.estado);
            });

            let btnGuardar = document.getElementById('btnGuardarDefinitivo');
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando en Base de Datos...';

            fetch('trabajo_social.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = '<i class="fa-solid fa-database"></i> Confirmar y Guardar en Base de Datos';
                if (data.success) {
                    alert(data.mensaje);
                    window.location.reload();
                } else {
                    alert('Error: ' + data.mensaje);
                }
            })
            .catch(err => {
                console.error(err);
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = '<i class="fa-solid fa-database"></i> Confirmar y Guardar en Base de Datos';
                alert('Error de red al guardar.');
            });
        }

        // --- Funciones auxiliares existentes ---
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); document.getElementById('searchForm').submit(); }
            });
        }

        function filtrarEstatus(estatus) {
            const inputEstatus = document.getElementById('inputEstatus');
            inputEstatus.value = (inputEstatus.value === estatus) ? '' : estatus;
            document.getElementById('searchForm').submit();
        }

        function abrirModalNuevoPaciente() {
            document.getElementById('modalTitulo').innerHTML = '<i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente';
            document.getElementById('inputAccionForm').value = 'nuevo_paciente';
            document.getElementById('inputEditIdPaciente').value = '';
            document.getElementById('formNuevoPaciente').reset();
            document.getElementById('modalNuevoPaciente').style.display = 'flex';
        }

        function cerrarModalNuevoPaciente() {
            document.getElementById('modalNuevoPaciente').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('modalNuevoPaciente')) cerrarModalNuevoPaciente();
            if (event.target == document.getElementById('modalSihbiGrid')) cerrarModalSihbiGrid();
            if (event.target == document.getElementById('modalSihbiDetalles')) cerrarModalDetalles();
        }
    </script>
</body>
</html>