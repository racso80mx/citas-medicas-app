<?php
// ===================================================================
// 1. CONFIGURACIÓN Y SEGURIDAD
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}
require_once 'conexion.php';

$usuario_actual_id = $_SESSION['usuario_id'];
$es_superadmin = (isset($_SESSION['rol_id']) && $_SESSION['rol_id'] == 1);

// Obtener áreas autorizadas del usuario actual desde la base de datos si no es superadmin
$areas_autorizadas_usuario = [];
if (!$es_superadmin) {
    try {
        $stmt_usr = $pdo->prepare("SELECT areas_autorizadas, id_rol FROM usuarios WHERE id_usuario = :id");
        $stmt_usr->execute(['id' => $usuario_actual_id]);
        $u_data = $stmt_usr->fetch(PDO::FETCH_ASSOC);
        if ($u_data) {
            if ($u_data['id_rol'] == 1) {
                $es_superadmin = true;
            } else {
                $json_areas = json_decode($u_data['areas_autorizadas'] ?? '[]', true);
                if (is_array($json_areas)) {
                    $areas_autorizadas_usuario = $json_areas;
                }
            }
        }
    } catch (Exception $e) {}
}

try {
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS estatus_censo VARCHAR(50) DEFAULT 'ACTIVO'");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS cama VARCHAR(50)");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS fecha_egreso DATE");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS hora_egreso TIME");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS medico_alta VARCHAR(255)");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS motivo_alta TEXT");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS curp VARCHAR(50)");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS edad INT");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS sexo VARCHAR(50)");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS fecha_nacimiento DATE");
    $pdo->exec("ALTER TABLE censo ADD COLUMN IF NOT EXISTS pendientes TEXT");
    
    $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS telefono VARCHAR(50)");
    $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS domicilio TEXT");
    $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS colonia VARCHAR(150)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS evolucion_censo (
        id_evolucion SERIAL PRIMARY KEY,
        id_censo INT NOT NULL,
        fecha_evento DATE NOT NULL,
        hora_evento TIME NOT NULL,
        area_evento VARCHAR(100) NOT NULL,
        medico_tratante VARCHAR(255) NOT NULL,
        tipo_evento VARCHAR(100) DEFAULT 'EVOLUCIÓN MÉDICA',
        descripcion_evento TEXT NOT NULL,
        tratamiento_farmacologico TEXT,
        indicaciones_generales TEXT,
        estudios_solicitados TEXT,
        proxima_cita DATE,
        proxima_hora TIME,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("ALTER TABLE evolucion_censo ALTER COLUMN estudios_solicitados TYPE TEXT");
} catch (Exception $e) {}

$mensaje = '';
$error = '';

// ===================================================================
// 2. BLOQUE AJAX: BÚSQUEDA, CAMAS DISPONIBLES Y EVOLUCIÓN
// ===================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_pacientes') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    try {
        if (!empty($q)) {
            $stmt = $pdo->prepare("
                SELECT * FROM pacientes 
                WHERE (nombre || ' ' || COALESCE(apaterno, '') || ' ' || COALESCE(amaterno, '')) ILIKE :q 
                   OR expediente ILIKE :q 
                   OR curp ILIKE :q 
                   OR telefono ILIKE :q 
                ORDER BY id_paciente DESC LIMIT 15
            ");
            $stmt->execute(['q' => "%$q%"]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            echo json_encode($pdo->query("SELECT * FROM pacientes ORDER BY id_paciente DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'obtener_camas_disponibles') {
    header('Content-Type: application/json');
    $area_nombre = trim($_GET['area'] ?? '');
    $id_censo_actual = intval($_GET['id_censo_actual'] ?? 0);

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

        $cama_actual_asignada = '';
        if ($id_censo_actual > 0) {
            $stmtCActual = $pdo->prepare("SELECT cama FROM censo WHERE id_censo = :id");
            $stmtCActual->execute(['id' => $id_censo_actual]);
            $cama_actual_asignada = trim(strtoupper($stmtCActual->fetchColumn() ?: ''));
        }

        $camas_disponibles = [];
        foreach ($todas_camas_area as $cama) {
            $cama_limpia = trim(strtoupper($cama));
            if (!in_array($cama_limpia, $camas_ocupadas_upper) || $cama_limpia === $cama_actual_asignada) {
                $camas_disponibles[] = $cama;
            }
        }

        echo json_encode(['success' => true, 'camas' => $camas_disponibles]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'camas' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

if ((isset($_POST['ajax']) && $_POST['ajax'] === 'crear_paciente_rapido') || (isset($_POST['accion']) && $_POST['accion'] === 'crear_paciente_rapido')) {
    header('Content-Type: application/json');
    $nombre    = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
    $apaterno  = mb_strtoupper(trim($_POST['apaterno'] ?? ''), 'UTF-8');
    $amaterno  = mb_strtoupper(trim($_POST['amaterno'] ?? ''), 'UTF-8');
    $curp      = mb_strtoupper(trim($_POST['curp'] ?? ''), 'UTF-8');
    $expediente= mb_strtoupper(trim($_POST['expediente'] ?? ''), 'UTF-8');
    $telefono  = trim($_POST['telefono'] ?? '');
    $domicilio = mb_strtoupper(trim($_POST['domicilio'] ?? ''), 'UTF-8');
    $colonia   = mb_strtoupper(trim($_POST['colonia'] ?? ''), 'UTF-8');
    $fnac      = trim($_POST['fnacimiento'] ?? '');
    $sexo      = mb_strtoupper(trim($_POST['sexo'] ?? ''), 'UTF-8');
    $estado    = mb_strtoupper(trim($_POST['estado'] ?? 'TABASCO'), 'UTF-8');
    $sin_curp  = isset($_POST['sin_curp']) && $_POST['sin_curp'] === 'true';

    if (!empty($nombre) && !empty($apaterno)) {
        try {
            if (empty($expediente)) {
                $letras = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 3);
                $numeros = rand(100000, 999999);
                $expediente = "SE-" . $letras . $numeros;
            }

            if ($sin_curp || empty($curp)) {
                $curp = null;
            } else {
                $stmtCheck = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE curp = :curp");
                $stmtCheck->execute(['curp' => $curp]);
                if ($stmtCheck->fetch()) {
                    $curp = $curp . rand(10, 99);
                }
            }

            $edad = 0;
            if (!empty($fnac)) {
                $diff = strtotime(date('Y-m-d')) - strtotime($fnac);
                $edad = max(0, floor($diff / (365.25 * 24 * 60 * 60)));
            }

            $nombre_completo = trim("$nombre $apaterno $amaterno");

            $stmtInsP = $pdo->prepare("
                INSERT INTO pacientes (
                    nombre, apaterno, amaterno, nombre_completo, curp, expediente, 
                    telefono, domicilio, colonia, fnacimiento, edad_actual, edad, 
                    sexo, estado, estatus, tipo_paciente, fecha_registro
                )
                VALUES (
                    :nom, :apat, :amat, :nom_comp, :curp, :exp, 
                    :tel, :dom, :col, :fnac, :edad1, :edad2, 
                    :sexo, :estado, 'ACTIVO', 'GENERAL', CURRENT_TIMESTAMP
                )
                RETURNING id_paciente
            ");
            $stmtInsP->execute([
                'nom'      => $nombre,
                'apat'     => $apaterno,
                'amat'     => $amaterno,
                'nom_comp' => $nombre_completo,
                'curp'     => !empty($curp) ? $curp : null,
                'exp'      => $expediente,
                'tel'      => $telefono,
                'dom'      => $domicilio,
                'col'      => $colonia,
                'fnac'     => !empty($fnac) ? $fnac : null,
                'edad1'    => $edad,
                'edad2'    => $edad,
                'sexo'     => $sexo,
                'estado'   => $estado
            ]);
            $nuevoId = $stmtInsP->fetchColumn();

            echo json_encode(['success' => true, 'id_paciente' => $nuevoId, 'nombre_completo' => $nombre_completo, 'curp' => $curp]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Nombre y apellido paterno son obligatorios.']);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'obtener_bitacora') {
    header('Content-Type: application/json');
    $id_censo = intval($_GET['id_censo'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM evolucion_censo WHERE id_censo = :id ORDER BY fecha_evento DESC, hora_evento DESC");
        $stmt->execute(['id' => $id_censo]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if ((isset($_POST['ajax']) && $_POST['ajax'] === 'guardar_evolucion_ajax') || (isset($_POST['accion']) && $_POST['accion'] === 'guardar_evolucion_ajax')) {
    header('Content-Type: application/json');
    $id_censo            = intval($_POST['id_censo_ev'] ?? 0);
    $fecha_evento        = trim($_POST['fecha_evento'] ?? date('Y-m-d'));
    $hora_evento         = trim($_POST['hora_evento'] ?? date('H:i'));
    $area_evento         = trim($_POST['area_evento'] ?? '');
    $medico_evento       = mb_strtoupper(trim($_POST['medico_evento'] ?? ''), 'UTF-8');
    $tipo_evento         = mb_strtoupper(trim($_POST['tipo_evento'] ?? 'EVOLUCIÓN MÉDICA'), 'UTF-8');
    $descripcion         = mb_strtoupper(trim($_POST['descripcion_evento'] ?? ''), 'UTF-8');
    
    $tratamiento_farm    = mb_strtoupper(trim($_POST['tratamiento_farmacologico'] ?? ''), 'UTF-8');
    $indicaciones_gen    = mb_strtoupper(trim($_POST['indicaciones_generales'] ?? ''), 'UTF-8');
    $estudios_sol        = mb_strtoupper(trim($_POST['estudios_solicitados'] ?? ''), 'UTF-8');
    $proxima_cita        = !empty($_POST['proxima_cita']) ? trim($_POST['proxima_cita']) : null;
    $proxima_hora        = !empty($_POST['proxima_hora']) ? trim($_POST['proxima_hora']) : null;

    if ($id_censo > 0 && !empty($descripcion)) {
        try {
            $stmtEv = $pdo->prepare("
                INSERT INTO evolucion_censo (
                    id_censo, fecha_evento, hora_evento, area_evento, medico_tratante, 
                    tipo_evento, descripcion_evento, tratamiento_farmacologico, 
                    indicaciones_generales, estudios_solicitados, proxima_cita, proxima_hora
                ) VALUES (
                    :id_c, :f_ev, :h_ev, :ar_ev, :med_ev, :tip_ev, :desc, :trat, :ind, :est, :prox, :prox_h
                )
            ");
            $stmtEv->execute([
                'id_c'   => $id_censo,
                'f_ev'   => $fecha_evento,
                'h_ev'   => $hora_evento,
                'ar_ev'  => $area_evento,
                'med_ev' => $medico_evento,
                'tip_ev' => $tipo_evento,
                'desc'   => $descripcion,
                'trat'   => $tratamiento_farm,
                'ind'    => $indicaciones_gen,
                'est'    => $estudios_sol,
                'prox'   => $proxima_cita,
                'prox_h' => $proxima_hora
            ]);

            $stmtUpCenso = $pdo->prepare("UPDATE censo SET area_actual = :ar, medico_tratante_actual = :med WHERE id_censo = :id");
            $stmtUpCenso->execute(['ar' => $area_evento, 'med' => $medico_evento, 'id' => $id_censo]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'La descripción de la evolución es obligatoria.']);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'historial_altas') {
    header('Content-Type: application/json');
    $tipo        = $_GET['tipo'] ?? 'todos';
    $fecha_unica = $_GET['fecha_unica'] ?? date('Y-m-d');
    $fecha_inicio= $_GET['fecha_inicio'] ?? date('Y-m-d');
    $fecha_fin   = $_GET['fecha_fin'] ?? date('Y-m-d');
    $medico      = trim($_GET['medico'] ?? '');

    try {
        $sql = "SELECT * FROM censo WHERE TRIM(UPPER(estatus_censo)) = 'DADO DE ALTA'";
        $params = [];

        if ($tipo === 'hoy') {
            $sql .= " AND fecha_egreso = CURRENT_DATE";
        } elseif ($tipo === 'manana') {
            $sql .= " AND fecha_egreso = CURRENT_DATE + INTERVAL '1 day'";
        } elseif ($tipo === 'semana') {
            $sql .= " AND fecha_egreso >= DATE_TRUNC('week', CURRENT_DATE) AND fecha_egreso <= CURRENT_DATE + INTERVAL '7 days'";
        } elseif ($tipo === 'mes') {
            $sql .= " AND DATE_TRUNC('month', fecha_egreso) = DATE_TRUNC('month', CURRENT_DATE)";
        } elseif ($tipo === 'fecha_unica') {
            $sql .= " AND fecha_egreso = :f_unica";
            $params['f_unica'] = $fecha_unica;
        } elseif ($tipo === 'rango') {
            $sql .= " AND fecha_egreso BETWEEN :f_ini AND :f_fin";
            $params['f_ini'] = $fecha_inicio;
            $params['f_fin'] = $fecha_fin;
        } elseif ($tipo === 'medico' && !empty($medico)) {
            $sql .= " AND medico_alta ILIKE :med";
            $params['med'] = "%$medico%";
        }

        $sql .= " ORDER BY fecha_egreso DESC, hora_egreso DESC LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// ===================================================================
// 3. PROCESAMIENTO DE FORMULARIOS (POST)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $area_actual_post = trim($_POST['area_actual'] ?? '');

    if ($accion === 'autoregistro_medico') {
        $nombre             = mb_strtoupper(trim($_POST['auto_nombre'] ?? ''), 'UTF-8');
        $apaterno          = mb_strtoupper(trim($_POST['auto_apaterno'] ?? ''), 'UTF-8');
        $amaterno          = mb_strtoupper(trim($_POST['auto_amaterno'] ?? ''), 'UTF-8');
        $cedula            = mb_strtoupper(trim($_POST['auto_cedula'] ?? ''), 'UTF-8');
        $especialidad      = mb_strtoupper(trim($_POST['auto_especialidad'] ?? 'MEDICINA GENERAL'), 'UTF-8');
        $telefono          = trim($_POST['auto_telefono'] ?? '');
        $correo            = trim($_POST['auto_correo'] ?? '');
        $sin_cedula        = isset($_POST['auto_sin_cedula']) && $_POST['auto_sin_cedula'] === '1';

        if ($sin_cedula || empty($cedula)) {
            $cedula = "MEDICO PASANTE";
        }

        if (!empty($nombre) && !empty($apaterno)) {
            try {
                $stmtInsMed = $pdo->prepare("
                    INSERT INTO medicos (nombre, apaterno, amaterno, cedula, especialidad, telefono, correo)
                    VALUES (:nom, :apat, :amat, :ced, :esp, :tel, :corr)
                ");
                $stmtInsMed->execute([
                    'nom'  => $nombre,
                    'apat' => $apaterno,
                    'amat' => $amaterno,
                    'ced'  => $cedula,
                    'esp'  => $especialidad,
                    'tel'  => $telefono,
                    'corr' => $correo
                ]);
                $mensaje = "¡Médico registrado exitosamente con su cédula!";
            } catch (Exception $e) {
                try {
                    $stmtInsMed2 = $pdo->prepare("
                        INSERT INTO medicos (nombre, apaterno, amaterno, cedula_profesional, especialidad, telefono, correo)
                        VALUES (:nom, :apat, :amat, :ced, :esp, :tel, :corr)
                    ");
                    $stmtInsMed2->execute([
                        'nom'  => $nombre,
                        'apat' => $apaterno,
                        'amat' => $amaterno,
                        'ced'  => $cedula,
                        'esp'  => $especialidad,
                        'tel'  => $telefono,
                        'corr' => $correo
                    ]);
                    $mensaje = "¡Médico registrado exitosamente!";
                } catch (Exception $ex) {
                    $error = "Error al registrar el médico: " . $ex->getMessage();
                }
            }
        } else {
            $error = "Nombre y apellido paterno son obligatorios para el autoregistro.";
        }
    }

    if ($accion === 'guardar_ingreso') {
        $id_paciente    = intval($_POST['id_paciente'] ?? 0);
        $area_actual    = trim($_POST['area_actual'] ?? '');
        $cama           = mb_strtoupper(trim($_POST['cama'] ?? ''), 'UTF-8');
        $medico_tratante= mb_strtoupper(trim($_POST['medico_tratante'] ?? ''), 'UTF-8');
        $dieta          = mb_strtoupper(trim($_POST['dieta'] ?? ''), 'UTF-8');
        $diagnostico    = mb_strtoupper(trim($_POST['diagnostico_ingreso'] ?? ''), 'UTF-8');
        $anotaciones    = mb_strtoupper(trim($_POST['anotaciones'] ?? ''), 'UTF-8');
        $pendientes     = mb_strtoupper(trim($_POST['pendientes'] ?? ''), 'UTF-8');
        $fecha_ingreso  = trim($_POST['fecha_ingreso'] ?? date('Y-m-d'));
        $hora_ingreso   = trim($_POST['hora_ingreso'] ?? date('H:i'));

        if ($id_paciente > 0 && !empty($area_actual)) {
            try {
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
                            diagnostico_ingreso, dieta, anotaciones, pendientes, medico_tratante_actual, estatus_censo
                        ) VALUES (
                            :id_p, :nom, :curp, :fnac, :edad, :sexo, 
                            :area, :cama, :f_ing, :h_ing, :dias, 
                            :diag, :dieta, :anot, :pend, :medico, 'ACTIVO'
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
                        'anot'   => $anotaciones,
                        'pend'   => $pendientes,
                        'medico' => $medico_tratante
                    ]);

                    $mensaje = "Paciente ingresado correctamente al área de $area_actual.";
                } else {
                    $error = "No se encontró el paciente seleccionado.";
                }
            } catch (Exception $e) {
                $error = "Error al registrar el ingreso: " . $e->getMessage();
            }
        } else {
            $error = "Debe seleccionar un paciente y un área de destino.";
        }
    }

    if ($accion === 'editar_ingreso') {
        $id_censo       = intval($_POST['id_censo'] ?? 0);
        $area_actual    = trim($_POST['area_actual'] ?? '');
        $cama           = mb_strtoupper(trim($_POST['cama'] ?? ''), 'UTF-8');
        $medico_tratante= mb_strtoupper(trim($_POST['medico_tratante'] ?? ''), 'UTF-8');
        $dieta          = mb_strtoupper(trim($_POST['dieta'] ?? ''), 'UTF-8');
        $diagnostico    = mb_strtoupper(trim($_POST['diagnostico_ingreso'] ?? ''), 'UTF-8');
        $anotaciones    = mb_strtoupper(trim($_POST['anotaciones'] ?? ''), 'UTF-8');
        $pendientes     = mb_strtoupper(trim($_POST['pendientes'] ?? ''), 'UTF-8');

        if ($id_censo > 0 && !empty($area_actual)) {
            try {
                $stmtUp = $pdo->prepare("
                    UPDATE censo 
                    SET area_actual = :area, cama = :cama, medico_tratante_actual = :medico, 
                        dieta = :dieta, diagnostico_ingreso = :diag, anotaciones = :anot, pendientes = :pend 
                    WHERE id_censo = :id
                ");
                $stmtUp->execute([
                    'area'   => $area_actual,
                    'cama'   => $cama,
                    'medico' => $medico_tratante,
                    'dieta'  => $dieta,
                    'diag'   => $diagnostico,
                    'anot'   => $anotaciones,
                    'pend'   => $pendientes,
                    'id'     => $id_censo
                ]);
                $mensaje = "Datos del paciente actualizados correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar el registro.";
            }
        }
    }

    if ($accion === 'dar_alta') {
        $id_censo     = intval($_POST['id_censo'] ?? 0);
        $medico_alta  = mb_strtoupper(trim($_POST['medico_alta'] ?? ''), 'UTF-8');
        $motivo_alta  = mb_strtoupper(trim($_POST['motivo_alta'] ?? ''), 'UTF-8');

        if ($id_censo > 0 && !empty($medico_alta) && !empty($motivo_alta)) {
            try {
                $stmtAlt = $pdo->prepare("
                    UPDATE censo 
                    SET estatus_censo = 'DADO DE ALTA', fecha_egreso = :f_egreso, hora_egreso = :h_egreso, 
                        medico_alta = :med, motivo_alta = :mot 
                    WHERE id_censo = :id
                ");
                $stmtAlt->execute([
                    'f_egreso' => date('Y-m-d'),
                    'h_egreso' => date('H:i'),
                    'med'      => $medico_alta,
                    'mot'      => $motivo_alta,
                    'id'       => $id_censo
                ]);
                $mensaje = "Paciente dado de alta exitosamente.";
            } catch (Exception $e) {
                $error = "Error al procesar la alta médica: " . $e->getMessage();
            }
        } else {
            $error = "Debe especificar el médico que otorga el alta y el motivo.";
        }
    }

    if ($accion === 'reingresar_paciente') {
        $id_censo        = intval($_POST['id_censo'] ?? 0);
        $area_reingreso  = trim($_POST['area_reingreso'] ?? '');
        $medico_reingreso= mb_strtoupper(trim($_POST['medico_reingreso'] ?? ''), 'UTF-8');
        $motivo_reingreso= mb_strtoupper(trim($_POST['motivo_reingreso'] ?? ''), 'UTF-8');

        if ($id_censo > 0 && !empty($area_reingreso) && !empty($medico_reingreso) && !empty($motivo_reingreso)) {
            try {
                $stmtRe = $pdo->prepare("
                    UPDATE censo 
                    SET estatus_censo = 'ACTIVO', 
                        area_actual = :area, 
                        medico_tratante_actual = :med, 
                        anotaciones = CASE 
                            WHEN anotaciones IS NULL OR anotaciones = '' THEN :mot 
                            ELSE anotaciones || ' | REINGRESO: ' || :mot 
                        END 
                    WHERE id_censo = :id
                ");
                $stmtRe->execute([
                    'area' => $area_reingreso,
                    'med'  => $medico_reingreso,
                    'mot'  => $motivo_reingreso,
                    'id'   => $id_censo
                ]);
                $mensaje = "Paciente reingresado exitosamente al área de $area_reingreso.";
            } catch (Exception $e) {
                $error = "Error al reingresar al paciente: " . $e->getMessage();
            }
        } else {
            $error = "Todos los campos del reingreso son obligatorios.";
        }
    }

    if (!empty($area_actual_post) && $accion !== 'guardar_evolucion_ajax') {
        header("Location: ingresos.php?area=" . urlencode($area_actual_post));
        exit();
    }
}

// ===================================================================
// 4. CATÁLOGOS Y CONSULTAS (FILTRADOS POR PERMISOS DE USUARIO)
// ===================================================================
$areas_hospital_todas = [];
try {
    $stmt_areas = $pdo->query("SELECT DISTINCT nombre FROM areas_hospital WHERE estatus IS NULL OR UPPER(TRIM(estatus)) = 'ACTIVO' ORDER BY nombre ASC");
    $areas_hospital_todas = $stmt_areas->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

if (empty($areas_hospital_todas)) {
    $areas_hospital_todas = ['Triage', 'Sala de Choque', 'Sala de Observación', 'UCI (Cuidados Intensivos)', 'UCIN (Neonatales)', 'Piso de Hospitalización', 'Quirófano'];
}

$areas_hospital = [];
if ($es_superadmin || empty($areas_autorizadas_usuario)) {
    $areas_hospital = $areas_hospital_todas;
} else {
    foreach ($areas_hospital_todas as $ar) {
        foreach ($areas_autorizadas_usuario as $aut) {
            if (strcasecmp(trim($ar), trim($aut)) === 0) {
                $areas_hospital[] = $ar;
                break;
            }
        }
    }
    if (empty($areas_hospital)) {
        $areas_hospital = $areas_hospital_todas;
    }
}

$estados_mexico = [
    'AS' => 'AGUASCALIENTES', 'BC' => 'BAJA CALIFORNIA', 'BS' => 'BAJA CALIFORNIA SUR', 'CC' => 'CAMPECHE', 
    'CS' => 'CHIAPAS', 'CH' => 'CHIHUAHUA', 'DF' => 'CIUDAD DE MÉXICO', 'CL' => 'COAHUILA', 
    'CM' => 'COLIMA', 'DG' => 'DURANGO', 'GT' => 'GUANAJUATO', 'GR' => 'GUERRERO', 
    'HG' => 'HIDALGO', 'JC' => 'JALISCO', 'MC' => 'ESTADO DE MÉXICO', 'MN' => 'MICHOACÁN', 
    'MS' => 'MORELOS', 'NT' => 'NAYARIT', 'NL' => 'NUEVO LEÓN', 'OC' => 'OAXACA', 
    'PL' => 'PUEBLA', 'QT' => 'QUERÉTARO', 'QR' => 'QUINTANA ROO', 'SP' => 'SAN LUIS POTOSÍ', 
    'SL' => 'SINALOA', 'SR' => 'SONORA', 'TC' => 'TABASCO', 'TS' => 'TAMAULIPAS', 
    'TL' => 'TLAXCALA', 'VZ' => 'VERACRUZ', 'YN' => 'YUCATÁN', 'ZS' => 'ZACATECAS', 'NE' => 'EXTRANJERO'
];

$cat_medicos = [];
try {
    $stmt_m = $pdo->query("SELECT * FROM medicos");
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
} catch (Exception $e) {}
$cat_medicos = array_unique($cat_medicos);
sort($cat_medicos);

$cat_estudios_lab = [];
try {
    $stmt_est = $pdo->query("SELECT * FROM estudios_laboratorio WHERE activo = true ORDER BY tipo_estudio, estudio");
    $cat_estudios_lab = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$area_solicitada = trim($_GET['area'] ?? ($areas_hospital[0] ?? ''));

$area_seleccionada = $areas_hospital[0] ?? '';
foreach ($areas_hospital as $ar) {
    if (strcasecmp($ar, $area_solicitada) === 0) {
        $area_seleccionada = $ar;
        break;
    }
}

// Obtener catálogo completo de camas del área
$todas_camas_cat = [];
try {
    $stmtC = $pdo->prepare("
        SELECT DISTINCT c.numero_cama FROM camas c
        LEFT JOIN areas_hospital ah ON c.id_area = ah.id_area
        LEFT JOIN areas_atencion aa ON c.id_area = aa.id_area
        WHERE TRIM(UPPER(ah.nombre)) = TRIM(UPPER(:area)) 
           OR TRIM(UPPER(aa.nombre_area)) = TRIM(UPPER(:area))
        ORDER BY c.numero_cama ASC
    ");
    $stmtC->execute(['area' => $area_seleccionada]);
    $todas_camas_cat = $stmtC->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

usort($todas_camas_cat, function($a, $b) {
    return strnatcasecmp($a, $b);
});

$total_camas_registradas_area = count($todas_camas_cat);

// Obtener pacientes activos en el censo para esta área y asociarlos por cama
$pacientes_por_cama = [];
$pacientes_censo = [];
if (!empty($area_seleccionada)) {
    try {
        $stmtCenso = $pdo->prepare("
            SELECT c.*, p.curp AS p_curp, p.expediente AS p_expediente, p.edad AS p_edad, p.sexo AS p_sexo, p.fnacimiento AS p_fnac, p.telefono AS p_tel, p.estado AS p_est 
            FROM censo c
            LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente
            WHERE TRIM(LOWER(c.area_actual)) = TRIM(LOWER(:area)) 
              AND (c.estatus_censo IS NULL OR TRIM(UPPER(c.estatus_censo)) != 'DADO DE ALTA')
        ");
        $stmtCenso->execute(['area' => $area_seleccionada]);
        $pacientes_censo = $stmtCenso->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pacientes_censo as $pc) {
            if (!empty($pc['cama'])) {
                $pacientes_por_cama[trim(strtoupper($pc['cama']))] = $pc;
            }
        }
    } catch (Exception $e) {}
}

$total_camas_ocupadas = count($pacientes_censo);
$total_camas_disponibles_lista = [];

foreach ($todas_camas_cat as $cama_cat) {
    $cama_limpia = trim(strtoupper($cama_cat));
    if (!isset($pacientes_por_cama[$cama_limpia])) {
        $total_camas_disponibles_lista[] = $cama_cat;
    }
}
$total_camas_disponibles_count = count($total_camas_disponibles_lista);

// ===================================================================
// 5. CÁLCULO DE ESTADÍSTICAS Y CAMAS
// ===================================================================
$total_camas_ocupadas = count($pacientes_censo);
$total_camas_disponibles_lista = [];
$total_camas_registradas_area = 0;

$todas_camas_cat = [];
try {
    $stmtC = $pdo->prepare("
        SELECT DISTINCT c.numero_cama FROM camas c
        LEFT JOIN areas_hospital ah ON c.id_area = ah.id_area
        LEFT JOIN areas_atencion aa ON c.id_area = aa.id_area
        WHERE TRIM(UPPER(ah.nombre)) = TRIM(UPPER(:area)) 
           OR TRIM(UPPER(aa.nombre_area)) = TRIM(UPPER(:area))
        ORDER BY c.numero_cama ASC
    ");
    $stmtC->execute(['area' => $area_seleccionada]);
    $todas_camas_cat = $stmtC->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $todas_camas_cat = [];
}

$total_camas_registradas_area = count($todas_camas_cat);

$camas_ocupadas_array = [];
foreach ($pacientes_censo as $pc_occ) {
    if (!empty($pc_occ['cama'])) {
        $camas_ocupadas_array[] = trim(strtoupper($pc_occ['cama']));
    }
}

foreach ($todas_camas_cat as $cama_cat) {
    $cama_limpia = trim(strtoupper($cama_cat));
    if (!in_array($cama_limpia, $camas_ocupadas_array)) {
        $total_camas_disponibles_lista[] = $cama_cat;
    }
}

$total_camas_disponibles_count = count($total_camas_disponibles_lista);

// ===================================================================
// INICIO DEL AJUSTE: ORDENAMIENTO DE GRID CON FLECHAS Y ORDEN NATURAL
// ===================================================================
$sort_by = $_GET['sort_by'] ?? 'cama';
$order = $_GET['order'] ?? 'ASC';
$next_order = ($order === 'ASC') ? 'DESC' : 'ASC';

usort($todas_camas_cat, function($a, $b) use ($pacientes_por_cama, $sort_by, $order) {
    $camaA = trim(strtoupper($a));
    $camaB = trim(strtoupper($b));
    $pacA = $pacientes_por_cama[$camaA] ?? null;
    $pacB = $pacientes_por_cama[$camaB] ?? null;

    $cmp = 0;
    
    switch ($sort_by) {
        case 'cama':
            // Este es el que repara el problema de CAMA 1, CAMA 10, CAMA 2
            $cmp = strnatcasecmp($a, $b); 
            break;
        case 'expediente':
            $valA = $pacA['p_expediente'] ?? $pacA['curp'] ?? 'ZZZ'; // ZZZ manda vacíos al fondo
            $valB = $pacB['p_expediente'] ?? $pacB['curp'] ?? 'ZZZ';
            $cmp = strcasecmp($valA, $valB);
            break;
        case 'paciente':
            $valA = $pacA['nombre_paciente'] ?? 'ZZZ';
            $valB = $pacB['nombre_paciente'] ?? 'ZZZ';
            $cmp = strcasecmp($valA, $valB);
            break;
        case 'edad':
            $valA = (int)($pacA['edad'] ?? $pacA['p_edad'] ?? -1);
            $valB = (int)($pacB['edad'] ?? $pacB['p_edad'] ?? -1);
            $cmp = ($valA < $valB) ? -1 : (($valA > $valB) ? 1 : 0);
            break;
        case 'ingreso':
            $valA = ($pacA['fecha_ingreso'] ?? '9999-99-99') . ' ' . ($pacA['hora_ingreso'] ?? '');
            $valB = ($pacB['fecha_ingreso'] ?? '9999-99-99') . ' ' . ($pacB['hora_ingreso'] ?? '');
            $cmp = strcmp($valA, $valB);
            break;
        default:
            $cmp = strnatcasecmp($a, $b);
            break;
    }

    // Desempate por nombre de cama si los valores coinciden
    if ($cmp === 0) {
        $cmp = strnatcasecmp($a, $b);
    }

    return ($order === 'ASC') ? $cmp : -$cmp;
});
// ===================================================================
// FIN DEL AJUSTE: ORDENAMIENTO DE GRID CON FLECHAS
// ===================================================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Ingresos y Censo Hospitalario</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde-color: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: var(--texto-oscuro); text-transform: uppercase; }
        input[type="email"], select { text-transform: none !important; }
        
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: 700; font-size: 16px; }
        .container { max-width: 1450px; margin: 25px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        
        .btn-nuevo { background-color: var(--imss-dorado); color: white; border: none; padding: 10px 18px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-nuevo:hover { background-color: #7d1b33; }

        .btn-autoregistro { background-color: #0284c7; color: white; border: none; padding: 10px 18px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-autoregistro:hover { background-color: #0369a1; }

        .btn-altas-historial { background-color: #0284c7; color: white; border: none; padding: 10px 18px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-altas-historial:hover { background-color: #0369a1; }

        .btn-reporte-horizontal { background-color: #006847; color: white; border: none; padding: 10px 18px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-reporte-horizontal:hover { background-color: #004d35; }

        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .areas-tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 20px; }
        .area-tab { padding: 10px 16px; background: white; border: 1px solid var(--borde-color); border-radius: 6px; color: var(--texto-oscuro); font-weight: 600; font-size: 13px; text-decoration: none; white-space: nowrap; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .area-tab:hover { border-color: var(--imss-verde); color: var(--imss-verde); }
        .area-tab.active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); }

        .card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); }
        .table-responsive { overflow-x: auto; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; font-weight: 700; border-bottom: 2px solid var(--borde-color); color: #4b5563; white-space: nowrap; }
        table td { padding: 12px 10px; border-bottom: 1px solid var(--borde-color); vertical-align: middle; }
        table tr:hover { background-color: #f8f9fa; }

        /* ESTILOS PARA LAS CABECERAS Y FLECHAS */
        table th a { color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 6px; }
        table th a:hover { color: var(--imss-verde); }
        table th a i { font-size: 11px; }

        .badge-cama { background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .badge-cama-disponible { background: #e4f8dd; color: var(--imss-verde); padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .badge-estancia { background: #f3f4f6; color: #374151; padding: 3px 6px; border-radius: 4px; font-weight: bold; font-size: 11px; }
        
        .btn-accion { padding: 6px 10px; border-radius: 4px; border: none; font-size: 12px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; color: white; margin-right: 3px; }
        .btn-editar { background-color: #0284c7; }
        .btn-evolucion { background-color: #0d9488; }
        .btn-receta-oficial { background-color: #9f2241; }
        .btn-alta { background-color: #d32f2f; }
        .btn-reingreso { background-color: #16a34a; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-overlay-reingreso { z-index: 3000; }

        .modal-content { background: white; width: 100%; max-width: 1200px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 94vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde-color); padding-bottom: 12px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: var(--imss-verde); font-size: 20px; }
        .close-modal { background: none; border: none; font-size: 22px; cursor: pointer; color: #637381; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 9px 12px; font-size: 13px; border: 2px solid var(--borde-color); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; text-transform: uppercase; }
        .form-control:focus { border-color: var(--imss-verde); }

        .section-box { background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid var(--borde-color); margin-bottom: 15px; }
        .section-box h5 { margin: 0 0 10px 0; color: var(--imss-verde); font-size: 14px; display: flex; align-items: center; gap: 6px; border-bottom: 1px dashed var(--borde-color); padding-bottom: 6px; }

        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single, .select2-container .select2-selection--multiple { min-height: 38px; border: 2px solid var(--borde-color); border-radius: 6px; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { color: var(--texto-oscuro); font-size: 13px; font-weight: 600; }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-hospital-user"></i> <strong>IMSS-BIENESTAR</strong> | Módulo de Ingresos y Censo</div>
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Menú Principal</a>
    </div>

    <div class="container">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <h1 style="font-size: 22px; margin: 0; color: var(--imss-verde); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-bed-pulse"></i> Censo: <?php echo htmlspecialchars($area_seleccionada); ?>
                </h1>
                
                <div style="display: flex; gap: 10px; align-items: center;">
                    <div style="background: white; padding: 6px 14px; border-radius: 6px; border: 1px solid var(--borde-color); display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: bold; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <i class="fa-solid fa-bed" style="color: var(--imss-dorado); font-size: 15px;"></i>
                        <span>Ocupadas: <span style="color: var(--imss-dorado); font-size: 13px;"><?php echo $total_camas_ocupadas; ?></span></span>
                    </div>
                    <div style="background: white; padding: 6px 14px; border-radius: 6px; border: 1px solid var(--borde-color); display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: bold; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <i class="fa-solid fa-bed-pulse" style="color: var(--imss-verde); font-size: 15px;"></i>
                        <span>Disponibles: <span style="color: var(--imss-verde); font-size: 13px;"><?php echo $total_camas_disponibles_count; ?></span></span>
                    </div>
                    <div style="background: white; padding: 6px 14px; border-radius: 6px; border: 1px solid var(--borde-color); display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: bold; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <i class="fa-solid fa-hospital" style="color: #0284c7; font-size: 15px;"></i>
                        <span>Catálogo: <span style="color: #0284c7; font-size: 13px;"><?php echo $total_camas_registradas_area; ?></span></span>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn-autoregistro" onclick="abrirModalAutorregistroMedico()">
                    <i class="fa-solid fa-user-doctor"></i> Autorregistro de Médicos
                </button>
                <a href="generar_receta_libre.php" target="_blank" class="btn-autoregistro" style="background-color: #7c3aed;" title="Generar Receta Libre o Manual">
                    <i class="fa-solid fa-file-medical"></i> Nueva Receta Libre
                </a>
                <button type="button" class="btn-altas-historial" onclick="abrirModalAltas()">
                    <i class="fa-solid fa-clipboard-user"></i> Historial de Altas
                </button>
                <button type="button" class="btn-reporte-horizontal" onclick="imprimirReporteHorizontalCenso()">
                    <i class="fa-solid fa-file-pdf"></i> Reporte PDF
                </button>
                <button type="button" class="btn-nuevo" onclick="abrirModalIngreso()">
                    <i class="fa-solid fa-user-plus"></i> Nuevo Ingreso
                </button>
            </div>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="areas-tabs">
            <?php foreach ($areas_hospital as $ar): 
                $activa = (strcasecmp($ar, $area_seleccionada) === 0) ? 'active' : '';
            ?>
                <a href="ingresos.php?area=<?php echo urlencode($ar); ?>" class="area-tab <?php echo $activa; ?>">
                    <i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars($ar); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <h3 style="margin-top:0; font-size: 16px; color: var(--texto-oscuro);"><i class="fa-solid fa-users"></i> Censo y Ocupación de Camas en <?php echo htmlspecialchars($area_seleccionada); ?></h3>
            <div class="table-responsive">
                <table>
                    <!-- INICIO AJUSTE DE CABECERAS CON FLECHAS -->
                    <thead>
                        <tr>
                            <th>
                                <a href="?area=<?php echo urlencode($area_seleccionada); ?>&sort_by=cama&order=<?php echo $next_order; ?>">
                                    Cama 
                                    <span style="opacity: <?php echo $sort_by == 'cama' ? '1' : '0.3'; ?>; color: <?php echo $sort_by == 'cama' ? 'var(--imss-verde)' : 'inherit'; ?>;">
                                        <i class="fa-solid fa-arrow-<?php echo ($sort_by == 'cama' && $order == 'ASC') ? 'up-long' : 'down-long'; ?>"></i>
                                    </span>
                                </a>
                            </th>
                            <th>
                                <a href="?area=<?php echo urlencode($area_seleccionada); ?>&sort_by=expediente&order=<?php echo $next_order; ?>">
                                    Expediente
                                    <span style="opacity: <?php echo $sort_by == 'expediente' ? '1' : '0.3'; ?>; color: <?php echo $sort_by == 'expediente' ? 'var(--imss-verde)' : 'inherit'; ?>;">
                                        <i class="fa-solid fa-arrow-<?php echo ($sort_by == 'expediente' && $order == 'ASC') ? 'up-long' : 'down-long'; ?>"></i>
                                    </span>
                                </a>
                            </th>
                            <th>
                                <a href="?area=<?php echo urlencode($area_seleccionada); ?>&sort_by=paciente&order=<?php echo $next_order; ?>">
                                    Paciente / CURP
                                    <span style="opacity: <?php echo $sort_by == 'paciente' ? '1' : '0.3'; ?>; color: <?php echo $sort_by == 'paciente' ? 'var(--imss-verde)' : 'inherit'; ?>;">
                                        <i class="fa-solid fa-arrow-<?php echo ($sort_by == 'paciente' && $order == 'ASC') ? 'up-long' : 'down-long'; ?>"></i>
                                    </span>
                                </a>
                            </th>
                            <th>
                                <a href="?area=<?php echo urlencode($area_seleccionada); ?>&sort_by=edad&order=<?php echo $next_order; ?>">
                                    Edad / Sexo
                                    <span style="opacity: <?php echo $sort_by == 'edad' ? '1' : '0.3'; ?>; color: <?php echo $sort_by == 'edad' ? 'var(--imss-verde)' : 'inherit'; ?>;">
                                        <i class="fa-solid fa-arrow-<?php echo ($sort_by == 'edad' && $order == 'ASC') ? 'up-long' : 'down-long'; ?>"></i>
                                    </span>
                                </a>
                            </th>
                            <th>
                                <a href="?area=<?php echo urlencode($area_seleccionada); ?>&sort_by=ingreso&order=<?php echo $next_order; ?>">
                                    Ingreso
                                    <span style="opacity: <?php echo $sort_by == 'ingreso' ? '1' : '0.3'; ?>; color: <?php echo $sort_by == 'ingreso' ? 'var(--imss-verde)' : 'inherit'; ?>;">
                                        <i class="fa-solid fa-arrow-<?php echo ($sort_by == 'ingreso' && $order == 'ASC') ? 'up-long' : 'down-long'; ?>"></i>
                                    </span>
                                </a>
                            </th>
                            <th>Estancia</th>
                            <th>Médico Tratante</th>
                            <th>Dieta</th>
                            <th>Diagnóstico</th>
                            <th>Pendientes</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <!-- FIN AJUSTE DE CABECERAS CON FLECHAS -->
                    <tbody>
        <?php 
        if (count($todas_camas_cat) > 0):
            foreach ($todas_camas_cat as $cama_catalogo):
                $cama_limpia = trim(strtoupper($cama_catalogo));
                if (isset($pacientes_por_cama[$cama_limpia])):
                    $pc = $pacientes_por_cama[$cama_limpia];
                    $dias = $pc['dias_estancia'] ?? 0;
                    if (!empty($pc['fecha_ingreso'])) {
                        $diff = strtotime(date('Y-m-d')) - strtotime($pc['fecha_ingreso']);
                        $dias = max(0, floor($diff / (60 * 60 * 24)));
                    }
                    $curp_mostrar = !empty($pc['curp']) ? $pc['curp'] : (!empty($pc['p_curp']) ? $pc['p_curp'] : 'S/N');
                    $exp_mostrar = !empty($pc['p_expediente']) ? $pc['p_expediente'] : (!empty($pc['curp']) ? $pc['curp'] : 'S/N');
                    $edad_mostrar = !empty($pc['edad']) ? $pc['edad'] : (!empty($pc['p_edad']) ? $pc['p_edad'] : 0);
                    $sexo_mostrar = !empty($pc['sexo']) ? $pc['sexo'] : (!empty($pc['p_sexo']) ? $pc['p_sexo'] : 'N/D');
        ?>
                    <tr>
                        <td><span class="badge-cama"><?php echo htmlspecialchars($pc['cama']); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($exp_mostrar); ?></strong></td>
                        <td>
                            <strong><?php echo htmlspecialchars($pc['nombre_paciente']); ?></strong><br>
                            <small style="color: #637381;">CURP: <?php echo htmlspecialchars($curp_mostrar); ?></small>
                        </td>
                        <td><?php echo $edad_mostrar; ?> AÑOS <br><small><?php echo htmlspecialchars($sexo_mostrar); ?></small></td>
                        <td><?php echo $pc['fecha_ingreso']; ?><br><small><?php echo htmlspecialchars($pc['hora_ingreso']); ?></small></td>
                        <td><span class="badge-estancia"><?php echo $dias; ?> DÍAS</span></td>
                        <td><?php echo htmlspecialchars($pc['medico_tratante_actual'] ?: 'NO ASIGNADO'); ?></td>
                        <td><span style="background: #fdf2f8; color: #db2777; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold;"><?php echo htmlspecialchars($pc['dieta'] ?: 'GENERAL'); ?></span></td>
                        <td style="max-width: 180px; white-space: normal; font-size: 12px;"><?php echo htmlspecialchars($pc['diagnostico_ingreso'] ?: 'SIN DIAGNÓSTICO'); ?></td>
                        <td style="max-width: 180px; white-space: normal; font-size: 12px; color: #b45309; font-weight: 600;"><?php echo htmlspecialchars($pc['pendientes'] ?: 'NINGUNO'); ?></td>
                        <td style="text-align: center; white-space: nowrap;">
                            <button type="button" class="btn-accion btn-editar" title="Editar Ingreso" onclick='abrirModalEditar(<?php echo json_encode($pc); ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
                            <button type="button" class="btn-accion btn-evolucion" title="Bitácora de Evolución" onclick='abrirModalEvolucion(<?php echo json_encode($pc); ?>)'><i class="fa-solid fa-stethoscope"></i></button>
                            <a href="generar_receta_ingreso.php?id_censo=<?php echo $pc['id_censo']; ?>" target="_blank" class="btn-accion btn-receta-oficial" title="Generar Receta Médica Institucional"><i class="fa-solid fa-prescription"></i></a>
                            <button type="button" class="btn-accion btn-alta" title="Dar de Alta" onclick='abrirModalAlta(<?php echo json_encode($pc); ?>)'><i class="fa-solid fa-person-walking-arrow-right"></i></button>
                        </td>
                    </tr>
        <?php 
                else:
        ?>
                    <tr style="background-color: #f0fdf4;">
                        <td><span class="badge-cama-disponible"><i class="fa-solid fa-bed"></i> <?php echo htmlspecialchars($cama_catalogo); ?></span></td>
                        <td colspan="9" style="color: var(--imss-verde); font-weight: bold; font-style: italic;">
                            <i class="fa-solid fa-circle-check"></i> CAMA DISPONIBLE / LIBRE PARA ASIGNACIÓN
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-accion btn-nuevo" style="padding: 5px 10px; font-size: 11px;" onclick="abrirModalIngresoConCama('<?php echo htmlspecialchars($cama_catalogo); ?>')">
                                <i class="fa-solid fa-user-plus"></i> Asignar
                            </button>
                        </td>
                    </tr>
        <?php 
                endif;
            endforeach;
        else: 
        ?>
            <tr>
                <td colspan="11" style="text-align: center; color: #637381; padding: 30px;">
                    NO HAY CAMAS REGISTRADAS EN EL CATÁLOGO PARA EL ÁREA DE <strong><?php echo htmlspecialchars($area_seleccionada); ?></strong>.
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL DE AUTORREGISTRO DE MÉDICOS -->
    <div class="modal-overlay" id="modalAutorregistroMedico">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-doctor" style="color: #0284c7;"></i> Autorregistro de Nuevo Médico</h3>
                <button type="button" class="close-modal" onclick="cerrarModalAutorregistroMedico()">&times;</button>
            </div>
            
            <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
                <input type="hidden" name="accion" value="autoregistro_medico">
                <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">

                <div class="form-group" style="background: #f8f9fa; padding: 10px 15px; border: 1px solid var(--borde-color); border-radius: 6px;">
                    <label style="margin: 0; cursor: pointer; display: flex; align-items: center; gap: 8px; color: var(--imss-dorado); font-weight: bold;">
                        <input type="checkbox" name="auto_sin_cedula" id="autoSinCedulaCheck" value="1" onchange="toggleSinCedulaMedico(this)">
                        Sin Cédula Profesional (Médico Pasante / Servicio Social)
                    </label>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Cédula Profesional <span style="color:var(--imss-dorado);" id="labelAsteriskCedula">*</span></label>
                        <input type="text" name="auto_cedula" id="autoCedulaInput" class="form-control" placeholder="NÚMERO DE CÉDULA" required oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Especialidad Médica / Servicio</label>
                        <input type="text" name="auto_especialidad" class="form-control" placeholder="EJ. MEDICINA GENERAL, PEDIATRÍA..." value="MEDICINA GENERAL" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Nombre(s) <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="auto_nombre" class="form-control" placeholder="NOMBRE" required oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Apellido Paterno <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="auto_apaterno" class="form-control" placeholder="PATERNO" required oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Apellido Materno</label>
                        <input type="text" name="auto_amaterno" class="form-control" placeholder="MATERNO" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Teléfono de Contacto (10 Dígitos)</label>
                        <input type="text" name="auto_telefono" class="form-control" placeholder="MÓVIL / 10 DÍGITOS" maxlength="10" pattern="[0-9]{10}" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,10);">
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico Institucional</label>
                        <input type="email" name="auto_correo" class="form-control" placeholder="CORREO@CORREO.COM" style="text-transform: none !important;">
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalAutorregistroMedico()">Cancelar</button>
                    <button type="submit" class="btn-autoregistro">Guardar y Registrar Médico</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DAR DE ALTA SEGURO -->
    <div class="modal-overlay" id="modalAlta">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-person-walking-arrow-right" style="color: var(--imss-dorado);"></i> Confirmar Alta Médica</h3>
                <button type="button" class="close-modal" onclick="cerrarModalAlta()">&times;</button>
            </div>
            
            <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
                <input type="hidden" name="accion" value="dar_alta">
                <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
                <input type="hidden" name="id_censo" id="altaIdCenso" required>

                <div style="background: #fdf3f5; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid var(--imss-dorado); font-size: 13px;">
                    <strong>PACIENTE:</strong> <span id="altaNombrePaciente" style="color: var(--imss-dorado);"></span>
                </div>

                <div class="form-group">
                    <label>Médico que Autoriza el Alta <span style="color:var(--imss-dorado);">*</span></label>
                    <select name="medico_alta" id="selectMedicoAlta" class="form-control" required>
                        <option value="">-- SELECCIONAR MÉDICO --</option>
                        <?php foreach ($cat_medicos as $med): ?>
                            <option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Motivo de Alta / Diagnóstico de Egreso <span style="color:var(--imss-dorado);">*</span></label>
                    <textarea name="motivo_alta" class="form-control" rows="3" placeholder="EXPLIQUE EL MOTIVO DE LA ALTA MÉDICA..." required oninput="this.value = this.value.toUpperCase();"></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalAlta()">Cancelar</button>
                    <button type="submit" class="btn-nuevo" style="background-color: var(--imss-dorado);">Confirmar Alta Médica</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL REINGRESAR PACIENTE DESDE HISTORIAL DE ALTAS -->
    <div class="modal-overlay modal-overlay-reingreso" id="modalReingreso">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-check" style="color: #16a34a;"></i> Reingresar Paciente al Censo</h3>
                <button type="button" class="close-modal" onclick="cerrarModalReingreso()">&times;</button>
            </div>
            
            <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
                <input type="hidden" name="accion" value="reingresar_paciente">
                <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
                <input type="hidden" name="id_censo" id="reingresoIdCenso" required>

                <div style="background: #f0fdf4; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #16a34a; font-size: 13px; color: #166534;">
                    <strong>PACIENTE A REINGRESAR:</strong> <span id="reingresoNombrePaciente"></span>
                </div>

                <div class="form-group">
                    <label>Nueva Área de Destino <span style="color:var(--imss-dorado);">*</span></label>
                    <select name="area_reingreso" class="form-control" required onchange="actualizarCamasDisponiblesModal(this.value, 'reingresoCamaSelect')">
                        <?php foreach ($areas_hospital as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Cama / Cubículo Disponible</label>
                    <select name="cama" id="reingresoCamaSelect" class="form-control">
                        <option value="">-- SELECCIONE UNA CAMA DISPONIBLE --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Médico Tratante / Responsable <span style="color:var(--imss-dorado);">*</span></label>
                    <select name="medico_reingreso" id="selectMedicoReingreso" class="form-control" required>
                        <option value="">-- SELECCIONAR MÉDICO --</option>
                        <?php foreach ($cat_medicos as $med): ?>
                            <option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Motivo de Reingreso (Corrección o Revaloración) <span style="color:var(--imss-dorado);">*</span></label>
                    <textarea name="motivo_reingreso" class="form-control" rows="3" placeholder="EXPLIQUE EL MOTIVO DE LA CORRECCIÓN O REINGRESO..." required oninput="this.value = this.value.toUpperCase();"></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalReingreso()">Cancelar</button>
                    <button type="submit" class="btn-nuevo" style="background-color: #16a34a;">Confirmar Reingreso</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL HISTORIAL DE ALTAS Y FILTROS -->
    <div class="modal-overlay" id="modalAltasHistorial">
        <div class="modal-content" style="max-width: 1300px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-clipboard-user"></i> Historial de Pacientes Dados de Alta</h3>
                <button type="button" class="close-modal" onclick="cerrarModalAltas()">&times;</button>
            </div>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid var(--borde-color); margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: flex-end;">
                    <div class="form-group" style="margin:0;">
                        <label>Tipo de Filtro:</label>
                        <select id="filtroTipo" class="form-control" onchange="cambiarTipoFiltro()">
                            <option value="todos">TODOS LOS HISTORIALES</option>
                            <option value="hoy">HOY</option>
                            <option value="manana">MAÑANA</option>
                            <option value="semana">ESTA SEMANA</option>
                            <option value="mes">ESTE MES</option>
                            <option value="fecha_unica">FECHA ÚNICA</option>
                            <option value="rango">RANGO DE FECHAS</option>
                            <option value="medico">POR MÉDICO</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin:0; display:none;" id="boxFechaUnica">
                        <label>Fecha:</label>
                        <input type="date" id="filtroFechaUnica" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group" style="margin:0; display:none;" id="boxFechaInicio">
                        <label>Desde:</label>
                        <input type="date" id="filtroFechaInicio" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group" style="margin:0; display:none;" id="boxFechaFin">
                        <label>Hasta:</label>
                        <input type="date" id="filtroFechaFin" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group" style="margin:0; display:none;" id="boxMedico">
                        <label>Médico:</label>
                        <select id="filtroMedico" class="form-control">
                            <option value="">-- SELECCIONAR MÉDICO --</option>
                            <?php foreach ($cat_medicos as $med): ?>
                                <option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <button type="button" class="btn-nuevo" style="background: var(--imss-verde); width:100%; justify-content:center;" onclick="cargarHistorialAltas()">
                            <i class="fa-solid fa-filter"></i> Aplicar Filtros
                        </button>
                    </div>
                </div>
            </div>

            <div style="max-height: 450px; overflow-y: auto; border: 1px solid var(--borde-color); border-radius: 6px; background: #fff;" id="tablaAltasContainer">
                <div style="padding: 20px; text-align: center; color: #637381;">Seleccione los filtros y presione Aplicar Filtros.</div>
            </div>

            <div style="margin-top: 20px; text-align: right;">
                <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 20px;" onclick="cerrarModalAltas()">Cerrar Ventana</button>
            </div>
        </div>
    </div>

    <!-- MODAL NUEVO INGRESO -->
    <div class="modal-overlay" id="modalIngreso">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-plus"></i> Registrar Nuevo Ingreso Hospitalario</h3>
                <button type="button" class="close-modal" onclick="cerrarModalIngreso()">&times;</button>
            </div>
            
            <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
                <input type="hidden" name="accion" value="guardar_ingreso">
                <input type="hidden" name="id_paciente" id="inputIdPaciente" required>

                <div class="form-group">
                    <label>1. Seleccionar Paciente <span style="color:var(--imss-dorado);">*</span></label>
                    <input type="text" id="inputBuscarPaciente" class="form-control" placeholder="BUSCAR POR NOMBRE, CURP O EXPEDIENTE..." oninput="buscarPacientesAjax(); this.value = this.value.toUpperCase();">
                    <div id="resultadoBusquedaPacientes" style="max-height: 150px; overflow-y: auto; border: 1px solid var(--borde-color); border-radius: 6px; display: none; background: #fff; margin-top:5px;"></div>
                    <div id="pacienteSeleccionadoInfo" style="padding: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; margin-top: 5px; display: none; font-size: 13px; color: #166534;">
                        <strong>PACIENTE SELECCIONADO:</strong> <span id="spanNombrePaciente"></span>
                    </div>
                </div>

                <div id="seccionNuevoPacienteBtn" style="margin-bottom: 15px; display: none;">
                    <button type="button" class="btn-accion" style="background: #0284c7; width: 100%; justify-content: center; padding: 10px; font-size: 13px;" onclick="toggleFormularioNuevoPaciente(true)">
                        <i class="fa-solid fa-user-plus"></i> ¿El paciente no existe? Dar de alta como Nuevo Paciente
                    </button>
                </div>

                <!-- FORMULARIO DE NUEVO PACIENTE CON CAMPOS SOLICITADOS -->
                <div id="formularioNuevoPaciente" style="display: none; background: #f8f9fa; border: 1px solid var(--borde-color); border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                    <h4 style="margin-top: 0; color: var(--imss-verde); font-size: 14px; border-bottom: 1px dashed var(--borde-color); padding-bottom: 6px; margin-bottom: 12px;">
                        <i class="fa-solid fa-id-card"></i> Registrar Nuevo Paciente en el Catálogo
                    </h4>
                    
                    <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
                        <label style="font-size: 13px; font-weight: bold; color: var(--imss-dorado); cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" id="checkSinCurp" onchange="toggleSinCurp(this)"> Sin CURP (Generar Folio Automático)
                        </label>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Nombre(s) *</label>
                            <input type="text" id="npNombre" class="form-control" placeholder="NOMBRE" oninput="this.value = this.value.toUpperCase();">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Apellido Paterno *</label>
                            <input type="text" id="npApaterno" class="form-control" placeholder="PATERNO" oninput="this.value = this.value.toUpperCase();">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Apellido Materno</label>
                            <input type="text" id="npAmaterno" class="form-control" placeholder="MATERNO" oninput="this.value = this.value.toUpperCase();">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Expediente</label>
                            <input type="text" id="npExpediente" class="form-control" placeholder="NÚMERO DE EXPEDIENTE" oninput="this.value = this.value.toUpperCase();">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Teléfono</label>
                            <input type="text" id="npTelefono" class="form-control" placeholder="TELÉFONO" maxlength="15" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px; grid-column: span 2;">
                            <label id="labelCurp">CURP (Autocalcula datos)</label>
                            <input type="text" id="npCurp" class="form-control" placeholder="INGRESE CURP COMPLETA" maxlength="18" oninput="parsearCurp(this.value); this.value = this.value.toUpperCase();">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Domicilio</label>
                            <input type="text" id="npDomicilio" class="form-control" placeholder="CALLE Y NÚMERO" oninput="this.value = this.value.toUpperCase();">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Colonia / Localidad</label>
                            <input type="text" id="npColonia" class="form-control" placeholder="COLONIA O FRACCIONAMIENTO" oninput="this.value = this.value.toUpperCase();">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Fecha de Nacimiento</label>
                            <input type="date" id="npFnac" class="form-control" onchange="calcularEdadPacienteNuevo()">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Edad</label>
                            <input type="text" id="npEdadCalculada" class="form-control" readonly style="background:#e9ecef; font-weight:bold;" placeholder="AÑOS">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Sexo</label>
                            <select id="npSexo" class="form-control">
                                <option value="MASCULINO">MASCULINO</option>
                                <option value="FEMENINO">FEMENINO</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Procedencia</label>
                            <select id="npEstado" class="form-control">
                                <?php foreach ($estados_mexico as $siglas => $est): ?>
                                    <option value="<?php echo $est; ?>" <?php echo ($est === 'TABASCO') ? 'selected' : ''; ?>><?php echo $est; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                        <button type="button" class="btn-accion" style="background: #637381;" onclick="toggleFormularioNuevoPaciente(false)">Cancelar</button>
                        <button type="button" class="btn-nuevo" style="background: #16a34a; padding: 8px 15px;" onclick="guardarNuevoPacienteAjax()">Guardar y Seleccionar Paciente</button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>2. Área de Destino <span style="color:var(--imss-dorado);">*</span></label>
                        <select name="area_actual" id="selectAreaIngreso" class="form-control" required onchange="actualizarCamasDisponiblesModal(this.value, 'selectCamaIngreso')">
                            <?php foreach ($areas_hospital as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>" <?php echo (strcasecmp($ar, $area_seleccionada) === 0) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ar); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>3. Cama / Cubículo Disponible <span style="color:var(--imss-dorado);">*</span></label>
                        <select name="cama" id="selectCamaIngreso" class="form-control" required>
                            <option value="">-- SELECCIONE UNA CAMA DISPONIBLE --</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Fecha de Ingreso</label>
                        <input type="date" name="fecha_ingreso" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Hora de Ingreso</label>
                        <input type="time" name="hora_ingreso" class="form-control" value="<?php echo date('H:i'); ?>" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Médico Tratante</label>
                        <select name="medico_tratante" id="selectMedicoNuevo" class="form-control" required>
                            <option value="">-- SELECCIONAR MÉDICO --</option>
                            <?php foreach ($cat_medicos as $med): ?>
                                <option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Dieta Asignada</label>
                        <input type="text" name="dieta" class="form-control" value="GENERAL" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Diagnóstico Preliminar</label>
                        <textarea name="diagnostico_ingreso" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Pendientes / Indicaciones a Seguir</label>
                        <textarea name="pendientes" class="form-control" rows="2" placeholder="PENDIENTES CLÍNICOS..." oninput="this.value = this.value.toUpperCase();"></textarea>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalIngreso()">Cancelar</button>
                    <button type="submit" class="btn-nuevo">Guardar Ingreso</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR INGRESO -->
    <div class="modal-overlay" id="modalEditar">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Datos de Ingreso</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditar()">&times;</button>
            </div>
            
            <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
                <input type="hidden" name="accion" value="editar_ingreso">
                <input type="hidden" name="area_actual_post" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
                <input type="hidden" name="id_censo" id="editIdCenso">

                <div class="form-group">
                    <label>Paciente:</label>
                    <input type="text" id="editNombrePaciente" class="form-control" readonly style="background:#f1f3f5; font-weight:bold;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Área Actual <span style="color:var(--imss-dorado);">*</span></label>
                        <select name="area_actual" id="editAreaActual" class="form-control" required onchange="actualizarCamasDisponiblesModal(this.value, 'editCamaSelect', document.getElementById('editIdCenso').value)">
                            <?php foreach ($areas_hospital as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cama / Cubículo</label>
                        <select name="cama" id="editCamaSelect" class="form-control" required>
                            <option value="">-- SELECCIONE UNA CAMA --</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Médico Tratante</label>
                        <select name="medico_tratante" id="selectMedicoEditar" class="form-control" required>
                            <option value="">-- SELECCIONAR MÉDICO --</option>
                            <?php foreach ($cat_medicos as $med): ?>
                                <option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Dieta Asignada</label>
                        <input type="text" name="dieta" id="editDieta" class="form-control" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Diagnóstico Preliminar</label>
                        <textarea name="diagnostico_ingreso" id="editDiagnostico" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Pendientes / Indicaciones a Seguir</label>
                        <textarea name="pendientes" id="editPendientes" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn-nuevo">Actualizar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EVOLUCIÓN, TRASLADO Y RECETAS -->
    <div class="modal-overlay" id="modalEvolucion">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-stethoscope"></i> Bitácora de Evolución, Traslado e Indicaciones</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEvolucion()">&times;</button>
            </div>
            
            <div style="margin-bottom: 20px; background: #f8f9fa; padding: 12px; border-radius: 6px; border: 1px solid var(--borde-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <strong>PACIENTE:</strong> <span id="evNombrePaciente" style="color: var(--imss-verde);"></span><br>
                    <small style="color:#637381;">CURP: <span id="evCurpPaciente"></span> | EDAD: <span id="evEdadPaciente"></span> AÑOS | SEXO: <span id="evSexoPaciente"></span></small>
                </div>
                <div>
                    <span class="badge-cama" id="evCamaPaciente" style="font-size: 13px; padding: 6px 12px;"></span>
                </div>
            </div>

            <form id="formEvolucionAjax" onsubmit="guardarEvolucionAjax(event)" style="background: #f0fdf4; padding: 20px; border-radius: 8px; border: 1px solid #bbf7d0; margin-bottom: 25px;">
                <input type="hidden" name="accion" value="guardar_evolucion_ajax">
                <input type="hidden" name="ajax" value="guardar_evolucion_ajax">
                <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
                <input type="hidden" name="id_censo_ev" id="evIdCenso">
                
                <h4 style="margin-top:0; color: #166534; font-size: 15px; border-bottom: 2px solid #bbf7d0; padding-bottom: 8px; margin-bottom: 15px;">
                    <i class="fa-solid fa-plus-circle"></i> Nuevo Registro Clínico / Enviar a Área
                </h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Fecha:</label>
                        <input type="date" name="fecha_evento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Hora:</label>
                        <input type="time" name="hora_evento" class="form-control" value="<?php echo date('H:i'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo de Evento:</label>
                        <select name="tipo_evento" class="form-control" required>
                            <option value="EVOLUCIÓN MÉDICA">EVOLUCIÓN MÉDICA</option>
                            <option value="TRASLADO DE ÁREA">TRASLADO DE ÁREA</option>
                            <option value="NOTA DE ENFERMERÍA">NOTA DE ENFERMERÍA</option>
                            <option value="INTERCONSULTA">INTERCONSULTA</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label><span style="color: var(--imss-dorado); font-weight:bold;">Se debe de enviar a:</span> (Área)</label>
                        <select name="area_evento" class="form-control" required>
                            <?php foreach ($areas_hospital_todas as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>" <?php echo (strcasecmp($ar, $area_seleccionada) === 0) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ar); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Médico Tratante / Responsable:</label>
                        <select name="medico_evento" id="selectMedicoEvolucion" class="form-control" required>
                            <option value="">-- SELECCIONAR MÉDICO --</option>
                            <?php foreach ($cat_medicos as $med): ?>
                                <option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Descripción de la Evolución / Notas Clínicas:</label>
                    <textarea name="descripcion_evento" class="form-control" rows="2" required oninput="this.value = this.value.toUpperCase();"></textarea>
                </div>

                <div class="section-box">
                    <h5><i class="fa-solid fa-prescription-bottle-medical"></i> Plan, Tratamiento y Estudios</h5>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Tratamiento Farmacológico (Fármaco, Dosis, Frecuencia y Duración):</label>
                            <textarea name="tratamiento_farmacologico" class="form-control" rows="3" oninput="this.value = this.value.toUpperCase();"></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Indicaciones Generales:</label>
                            <textarea name="indicaciones_generales" class="form-control" rows="3" oninput="this.value = this.value.toUpperCase();"></textarea>
                        </div>
                    </div>

                    <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid var(--borde-color); margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label><i class="fa-solid fa-flask"></i> Selección Rápida (Catálogo de Laboratorios e Imagenología):</label>
                            <select id="selectEstudiosLabMulti" class="form-control" multiple style="width: 100%;" data-placeholder="-- BUSCAR ESTUDIO O ABREVIATURA --">
                                <?php foreach ($cat_estudios_lab as $est): ?>
                                    <option value="<?php echo htmlspecialchars($est['estudio']); ?>">
                                        <?php echo htmlspecialchars($est['estudio']); ?> (<?php echo htmlspecialchars($est['nombre_corto']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Estudios Solicitados (Para Impresión):</label>
                            <textarea name="estudios_solicitados" id="textareaEstudiosSolicitados" class="form-control" rows="3" placeholder="ESTUDIOS SELECCIONADOS..." oninput="this.value = this.value.toUpperCase();"></textarea>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Próxima Cita (Fecha):</label>
                            <input type="date" name="proxima_cita" class="form-control">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Próxima Hora:</label>
                            <input type="time" name="proxima_hora" class="form-control">
                        </div>
                    </div>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn-nuevo" style="background-color: #0d9488;">
                        <i class="fa-solid fa-save"></i> Guardar y Enviar a Área
                    </button>
                </div>
            </form>

            <h4 style="margin-bottom: 10px; font-size: 15px;"><i class="fa-solid fa-timeline"></i> Historial de Registros, Evolución y Tratamientos</h4>
            <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--borde-color); border-radius: 6px; background: #fff;" id="contenedorBitacoraEventos">
                <div style="padding: 15px; text-align: center; color: #637381;">Cargando historial...</div>
            </div>

            <div style="margin-top: 20px; text-align: right;">
                <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 20px;" onclick="cerrarModalEvolucion()">Cerrar Ventana</button>
            </div>
        </div>
    </div>

    <script>
    const mapaEstadosCurp = <?php echo json_encode($estados_mexico); ?>;
    const catalogoEstudiosLab = <?php echo json_encode($cat_estudios_lab); ?>;
    const catalogoCamasArea = <?php echo json_encode($todas_camas_cat); ?>;
    const pacientesPorCamaMapa = <?php echo json_encode($pacientes_por_cama); ?>;
    </script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        let pacienteActualGlobal = {};

        $(document).ready(function() {
            $('#selectMedicoNuevo').select2({ dropdownParent: $('#modalIngreso'), placeholder: '-- SELECCIONAR MÉDICO --', allowClear: true });
            $('#selectMedicoEditar').select2({ dropdownParent: $('#modalEditar'), placeholder: '-- SELECCIONAR MÉDICO --', allowClear: true });
            $('#selectMedicoEvolucion').select2({ dropdownParent: $('#modalEvolucion'), placeholder: '-- SELECCIONAR MÉDICO --', allowClear: true });
            $('#selectMedicoAlta').select2({ dropdownParent: $('#modalAlta'), placeholder: '-- SELECCIONAR MÉDICO --', allowClear: true });
            $('#selectMedicoReingreso').select2({ dropdownParent: $('#modalReingreso'), placeholder: '-- SELECCIONAR MÉDICO --', allowClear: true });
            $('#filtroMedico').select2({ dropdownParent: $('#modalAltasHistorial'), placeholder: '-- SELECCIONAR MÉDICO --', allowClear: true });
            
            $('#selectEstudiosLabMulti').select2({ 
                dropdownParent: $('#modalEvolucion'), 
                placeholder: '-- BUSCAR ESTUDIO O ABREVIATURA --', 
                allowClear: true 
            }).on('change', function() {
                let seleccionados = $(this).val() || [];
                document.getElementById('textareaEstudiosSolicitados').value = seleccionados.join(', ').toUpperCase();
            });
        });

        function actualizarCamasDisponiblesModal(areaNombre, selectId, idCensoActual = 0, camaSeleccionadaPrevia = '') {
            const selectCama = document.getElementById(selectId);
            if(!selectCama) return;

            selectCama.innerHTML = '<option value="">Cargando camas disponibles...</option>';

            fetch(`ingresos.php?ajax=obtener_camas_disponibles&area=${encodeURIComponent(areaNombre)}&id_censo_actual=${idCensoActual}`)
                .then(res => res.json())
                .then(data => {
                    let html = '<option value="">-- SELECCIONE UNA CAMA DISPONIBLE --</option>';
                    if(data.success && data.camas.length > 0) {
                        data.camas.forEach(cama => {
                            let selected = (cama === camaSeleccionadaPrevia) ? 'selected' : '';
                            html += `<option value="${cama}" ${selected}>CAMA: ${cama}</option>`;
                        });
                    } else {
                        html = '<option value="">NO HAY CAMAS DISPONIBLES EN ESTE ÁREA</option>';
                    }
                    selectCama.innerHTML = html;
                })
                .catch(() => {
                    selectCama.innerHTML = '<option value="">Error al cargar camas</option>';
                });
        }

        function abrirModalAutorregistroMedico() {
            document.getElementById('autoSinCedulaCheck').checked = false;
            const inputCedula = document.getElementById('autoCedulaInput');
            inputCedula.disabled = false;
            inputCedula.required = true;
            inputCedula.style.background = '#fff';
            document.getElementById('labelAsteriskCedula').style.display = 'inline';
            document.getElementById('modalAutorregistroMedico').style.display = 'flex';
        }
        function cerrarModalAutorregistroMedico() {
            document.getElementById('modalAutorregistroMedico').style.display = 'none';
        }

        function toggleSinCedulaMedico(checkbox) {
            const inputCedula = document.getElementById('autoCedulaInput');
            const asterisk = document.getElementById('labelAsteriskCedula');
            if (checkbox.checked) {
                inputCedula.value = '';
                inputCedula.disabled = true;
                inputCedula.required = false;
                inputCedula.style.background = '#e9ecef';
                asterisk.style.display = 'none';
            } else {
                inputCedula.disabled = false;
                inputCedula.required = true;
                inputCedula.style.background = '#fff';
                asterisk.style.display = 'inline';
            }
        }

        function abrirModalIngreso() { 
            document.getElementById('inputIdPaciente').value = '';
            document.getElementById('inputBuscarPaciente').value = '';
            document.getElementById('pacienteSeleccionadoInfo').style.display = 'none';
            document.getElementById('seccionNuevoPacienteBtn').style.display = 'none';
            document.getElementById('formularioNuevoPaciente').style.display = 'none';
            document.getElementById('checkSinCurp').checked = false;
            document.getElementById('npCurp').disabled = false;
            
            let areaActual = document.getElementById('selectAreaIngreso').value;
            actualizarCamasDisponiblesModal(areaActual, 'selectCamaIngreso');
            document.getElementById('modalIngreso').style.display = 'flex'; 
        }

        function abrirModalIngresoConCama(camaLibre) {
            abrirModalIngreso();
            setTimeout(() => {
                const selectCama = document.getElementById('selectCamaIngreso');
                let found = false;
                for (let i = 0; i < selectCama.options.length; i++) {
                    if (selectCama.options[i].value === camaLibre) {
                        selectCama.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    let opt = document.createElement('option');
                    opt.value = camaLibre;
                    opt.text = 'CAMA: ' + camaLibre;
                    opt.selected = true;
                    selectCama.add(opt);
                }
            }, 300);
        }

        function cerrarModalIngreso() { document.getElementById('modalIngreso').style.display = 'none'; }

        function toggleFormularioNuevoPaciente(mostrar) {
            document.getElementById('formularioNuevoPaciente').style.display = mostrar ? 'block' : 'none';
            document.getElementById('seccionNuevoPacienteBtn').style.display = mostrar ? 'none' : 'block';
            if(mostrar) {
                document.getElementById('npNombre').value = document.getElementById('inputBuscarPaciente').value.trim();
                document.getElementById('resultadoBusquedaPacientes').style.display = 'none';
            }
        }

        function toggleSinCurp(checkbox) {
            const curpInput = document.getElementById('npCurp');
            if (checkbox.checked) {
                curpInput.value = '';
                curpInput.disabled = true;
                curpInput.style.background = '#e9ecef';
                document.getElementById('labelCurp').innerText = 'CURP (Se asignará folio automático)';
            } else {
                curpInput.disabled = false;
                curpInput.style.background = '#fff';
                document.getElementById('labelCurp').innerText = 'CURP (Autocalcula datos)';
            }
        }

        function parsearCurp(curp) {
            curp = curp.trim().toUpperCase();
            if (curp.length >= 10) {
                let yy = parseInt(curp.substring(4, 6), 10);
                let anioActualDosDigitos = new Date().getFullYear() % 100;
                let siglo = (yy > anioActualDosDigitos) ? 1900 : 2000;
                let anio = siglo + yy;

                let mes = curp.substring(6, 8);
                let dia = curp.substring(8, 10);

                if (!isNaN(anio) && mes >= '01' && mes <= '12' && dia >= '01' && dia <= '31') {
                    let fechaNac = `${anio}-${mes}-${dia}`;
                    document.getElementById('npFnac').value = fechaNac;
                    calcularEdadPacienteNuevo();
                }
            }

            if (curp.length >= 11) {
                let sexoChar = curp.substring(10, 11);
                if (sexoChar === 'H') {
                    document.getElementById('npSexo').value = 'MASCULINO';
                } else if (sexoChar === 'M') {
                    document.getElementById('npSexo').value = 'FEMENINO';
                }
            }

            if (curp.length >= 13) {
                let entidadSiglas = curp.substring(11, 13);
                if (mapaEstadosCurp[entidadSiglas]) {
                    let nombreEstado = mapaEstadosCurp[entidadSiglas];
                    let selectEstado = document.getElementById('npEstado');
                    for (let i = 0; i < selectEstado.options.length; i++) {
                        if (selectEstado.options[i].value === nombreEstado) {
                            selectEstado.selectedIndex = i;
                            break;
                        }
                    }
                }
            }
        }

        function calcularEdadPacienteNuevo() {
            const fnacStr = document.getElementById('npFnac').value;
            if(!fnacStr) return;
            const fnac = new Date(fnacStr);
            const hoy = new Date();
            let edad = hoy.getFullYear() - fnac.getFullYear();
            const m = hoy.getMonth() - fnac.getMonth();
            if (m < 0 || (m === 0 && hoy.getDate() < fnac.getDate())) {
                edad--;
            }
            document.getElementById('npEdadCalculada').value = (edad >= 0 ? edad : 0) + ' AÑOS';
        }

        function guardarNuevoPacienteAjax() {
            const nombre = document.getElementById('npNombre').value.trim();
            const apaterno = document.getElementById('npApaterno').value.trim();
            const amaterno = document.getElementById('npAmaterno').value.trim();
            const expediente = document.getElementById('npExpediente').value.trim();
            const telefono = document.getElementById('npTelefono').value.trim();
            const domicilio = document.getElementById('npDomicilio').value.trim();
            const colonia = document.getElementById('npColonia').value.trim();
            const curp = document.getElementById('npCurp').value.trim();
            const fnacimiento = document.getElementById('npFnac').value;
            const sexo = document.getElementById('npSexo').value;
            const estado = document.getElementById('npEstado').value;
            const sinCurp = document.getElementById('checkSinCurp').checked;

            if(!nombre || !apaterno) {
                alert('Nombre y Apellido Paterno son obligatorios.');
                return;
            }

            if(!sinCurp && !fnacimiento) {
                alert('La Fecha de Nacimiento es obligatoria (o marque la casilla "Sin CURP").');
                return;
            }

            $.ajax({
                url: 'ingresos.php',
                type: 'POST',
                data: {
                    ajax: 'crear_paciente_rapido',
                    nombre: nombre,
                    apaterno: apaterno,
                    amaterno: amaterno,
                    expediente: expediente,
                    telefono: telefono,
                    domicilio: domicilio,
                    colonia: colonia,
                    curp: curp,
                    fnacimiento: fnacimiento,
                    sexo: sexo,
                    estado: estado,
                    sin_curp: sinCurp ? 'true' : 'false'
                },
                dataType: 'json',
                success: function(response) {
                    if(response.success) {
                        seleccionarPaciente(response.id_paciente, response.nombre_completo, response.curp || 'S/N');
                        toggleFormularioNuevoPaciente(false);
                        document.getElementById('npNombre').value = '';
                        document.getElementById('npApaterno').value = '';
                        document.getElementById('npAmaterno').value = '';
                        document.getElementById('npExpediente').value = '';
                        document.getElementById('npTelefono').value = '';
                        document.getElementById('npDomicilio').value = '';
                        document.getElementById('npColonia').value = '';
                        document.getElementById('npCurp').value = '';
                        document.getElementById('npFnac').value = '';
                        document.getElementById('npEdadCalculada').value = '';
                        document.getElementById('checkSinCurp').checked = false;
                        document.getElementById('npCurp').disabled = false;
                    } else {
                        alert('Error al guardar el paciente: ' + (response.error || 'Desconocido'));
                    }
                },
                error: function(xhr) {
                    alert('Error de conexión o código PHP: ' + xhr.responseText);
                }
            });
        }

        function abrirModalAlta(pc) {
            document.getElementById('altaIdCenso').value = pc.id_censo;
            document.getElementById('altaNombrePaciente').innerText = pc.nombre_paciente + ' (CURP: ' + (pc.curp || pc.p_curp || 'S/N') + ')';
            $('#selectMedicoAlta').val(null).trigger('change');
            document.getElementById('modalAlta').style.display = 'flex';
        }
        function cerrarModalAlta() { document.getElementById('modalAlta').style.display = 'none'; }

        function abrirModalReingreso(idCenso, nombrePaciente) {
            cerrarModalAltas();
            document.getElementById('reingresoIdCenso').value = idCenso;
            document.getElementById('reingresoNombrePaciente').innerText = nombrePaciente;
            
            let areaSelect = document.querySelector('#modalReingreso select[name="area_reingreso"]');
            if(areaSelect) {
                actualizarCamasDisponiblesModal(areaSelect.value, 'reingresoCamaSelect');
            }

            $('#selectMedicoReingreso').val(null).trigger('change');
            document.getElementById('modalReingreso').style.display = 'flex';
        }
        function cerrarModalReingreso() { document.getElementById('modalReingreso').style.display = 'none'; }

        function abrirModalAltas() { document.getElementById('modalAltasHistorial').style.display = 'flex'; }
        function cerrarModalAltas() { document.getElementById('modalAltasHistorial').style.display = 'none'; }

        function cambiarTipoFiltro() {
            const tipo = document.getElementById('filtroTipo').value;
            document.getElementById('boxFechaUnica').style.display = (tipo === 'fecha_unica') ? 'block' : 'none';
            document.getElementById('boxFechaInicio').style.display = (tipo === 'rango') ? 'block' : 'none';
            document.getElementById('boxFechaFin').style.display = (tipo === 'rango') ? 'block' : 'none';
            document.getElementById('boxMedico').style.display = (tipo === 'medico') ? 'block' : 'none';
        }

        function cargarHistorialAltas() {
            const tipo = document.getElementById('filtroTipo').value;
            const fechaUnica = document.getElementById('filtroFechaUnica').value;
            const fechaInicio = document.getElementById('filtroFechaInicio').value;
            const fechaFin = document.getElementById('filtroFechaFin').value;
            const medico = $('#filtroMedico').val();

            const container = document.getElementById('tablaAltasContainer');
            container.innerHTML = '<div style="padding: 20px; text-align: center; color: #637381;">Cargando registros...</div>';

            let url = `ingresos.php?ajax=historial_altas&tipo=${tipo}&fecha_unica=${fechaUnica}&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&medico=${encodeURIComponent(medico || '')}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        html = '<table><thead><tr><th>Egreso</th><th>Paciente / CURP</th><th>Área</th><th>Médico que Autoriza</th><th>Motivo de Alta</th><th style="text-align: center;">Acción</th></tr></thead><tbody>';
                        data.forEach(item => {
                            html += `<tr>` +
                                `<td>${item.fecha_egreso || ''} <br><small>${item.hora_egreso || ''}</small></td>` +
                                `<td><strong>${item.nombre_paciente}</strong><br><small style="color:#637381;">${item.curp || ''}</small></td>` +
                                `<td>${item.area_actual}</td>` +
                                `<td>${item.medico_alta || 'NO REGISTRADO'}</td>` +
                                `<td style="font-size:12px;">${item.motivo_alta || 'SIN MOTIVO ESPECIFICADO'}</td>` +
                                `<td style="text-align: center; white-space: nowrap;">` +
                                    `<button type="button" class="btn-accion btn-reingreso" title="Reingresar Paciente" onclick="abrirModalReingreso(${item.id_censo}, '${item.nombre_paciente.replace(/'/g, "\\'")}')">` +
                                        `<i class="fa-solid fa-user-check"></i> Reingresar` +
                                    `</button>` +
                                `</td>` +
                            `</tr>`;
                        });
                        html += '</tbody></table>';
                    } else {
                        html = '<div style="padding: 25px; text-align: center; color: #637381;">No se encontraron registros de pacientes dados de alta con los filtros seleccionados.</div>';
                    }
                    container.innerHTML = html;
                })
                .catch(err => {
                    container.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--imss-dorado);">Error al cargar el historial.</div>';
                });
        }

        function abrirModalEditar(pc) {
            document.getElementById('editIdCenso').value = pc.id_censo;
            document.getElementById('editNombrePaciente').value = pc.nombre_paciente;
            document.getElementById('editAreaActual').value = pc.area_actual;
            document.getElementById('editDieta').value = pc.dieta || 'GENERAL';
            document.getElementById('editDiagnostico').value = pc.diagnostico_ingreso || '';
            document.getElementById('editPendientes').value = pc.pendientes || '';
            
            actualizarCamasDisponiblesModal(pc.area_actual, 'editCamaSelect', pc.id_censo, pc.cama);

            $('#selectMedicoEditar').val(pc.medico_tratante_actual).trigger('change');
            document.getElementById('modalEditar').style.display = 'flex';
        }
        function cerrarModalEditar() { document.getElementById('modalEditar').style.display = 'none'; }

        function abrirModalEvolucion(pc) {
            pacienteActualGlobal = pc;
            document.getElementById('evIdCenso').value = pc.id_censo;
            document.getElementById('evNombrePaciente').innerText = pc.nombre_paciente;
            document.getElementById('evCurpPaciente').innerText = pc.curp || pc.p_curp || 'S/N';
            document.getElementById('evEdadPaciente').innerText = pc.edad || pc.p_edad || 0;
            document.getElementById('evSexoPaciente').innerText = pc.sexo || pc.p_sexo || 'N/D';
            document.getElementById('evCamaPaciente').innerText = 'CAMA: ' + (pc.cama || 'S/C');
            document.getElementById('modalEvolucion').style.display = 'flex';
            $('#selectMedicoEvolucion').val(null).trigger('change');
            $('#selectEstudiosLabMulti').val(null).trigger('change');
            document.getElementById('textareaEstudiosSolicitados').value = '';
            document.getElementById('formEvolucionAjax').reset();
            document.getElementById('evIdCenso').value = pc.id_censo;

            cargarBitacoraEventos(pc.id_censo);
        }

        function cargarBitacoraEventos(idCenso) {
            const contenedor = document.getElementById('contenedorBitacoraEventos');
            contenedor.innerHTML = '<div style="padding: 15px; text-align: center; color: #637381;">Cargando historial...</div>';

            fetch('ingresos.php?ajax=obtener_bitacora&id_censo=' + idCenso)
                .then(res => res.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        data.forEach(ev => {
                            let evStr = encodeURIComponent(JSON.stringify(ev));
                            html += '<div style="padding: 15px; border-bottom: 1px solid #e5e7eb; font-size: 13px;">' +
                                '<div style="display: flex; justify-content: space-between; margin-bottom: 6px; align-items:center; flex-wrap:wrap; gap:8px;">' +
                                    '<strong style="color: var(--imss-verde); font-size: 14px;"><i class="fa-solid fa-notes-medical"></i> ' + ev.tipo_evento + '</strong>' +
                                    '<div>' +
                                        '<span style="color: #637381; font-size: 12px; margin-right: 10px;"><i class="fa-solid fa-calendar-days"></i> ' + ev.fecha_evento + ' ' + ev.hora_evento + ' | Área: ' + ev.area_evento + '</span>' +
                                        '<button type="button" class="btn-accion" style="background:#0284c7;" onclick="imprimirNotaEvolucion(event, \'' + evStr + '\')">' +
                                            '<i class="fa-solid fa-print"></i> PDF Nota' +
                                        '</button>' +
                                        '<button type="button" class="btn-accion" style="background:#9f2241;" onclick="imprimirRecetaMedica(event, \'' + evStr + '\')">' +
                                            '<i class="fa-solid fa-prescription"></i> PDF Receta' +
                                        '</button>' +
                                        '<button type="button" class="btn-accion" style="background:#16a34a;" onclick="imprimirSolicitudLaboratorio(event, \'' + evStr + '\')">' +
                                            '<i class="fa-solid fa-flask"></i> PDF Lab' +
                                        '</button>' +
                                    '</div>' +
                                '</div>' +
                                '<div style="margin-bottom: 8px; color: var(--texto-oscuro);"><strong>Evolución / Motivo:</strong> ' + ev.descripcion_evento + '</div>';
                            
                            if (ev.tratamiento_farmacologico) {
                                html += '<div style="margin-bottom: 4px; color: #0369a1; background: #e0f2fe; padding: 6px 10px; border-radius: 4px;"><strong>Tratamiento:</strong> ' + ev.tratamiento_farmacologico + '</div>';
                            }
                            if (ev.indicaciones_generales) {
                                html += '<div style="margin-bottom: 4px; color: #374151; background: #f3f4f6; padding: 6px 10px; border-radius: 4px;"><strong>Indicaciones:</strong> ' + ev.indicaciones_generales + '</div>';
                            }
                            if (ev.estudios_solicitados) {
                                html += '<div style="margin-bottom: 4px; color: #854d0e; background: #fef9c3; padding: 6px 10px; border-radius: 4px;"><strong>Estudios:</strong> ' + ev.estudios_solicitados + '</div>';
                            }
                            if (ev.proxima_cita) {
                                let horaReval = ev.proxima_hora ? ' a las ' + ev.proxima_hora : '';
                                html += '<div style="margin-bottom: 4px; color: #166534; background: #f0fdf4; padding: 6px 10px; border-radius: 4px;"><strong>Próxima Revaloración:</strong> ' + ev.proxima_cita + horaReval + '</div>';
                            }

                            html += '<div style="margin-top: 8px;"><small style="color: #4b5563; font-style: italic;"><i class="fa-solid fa-user-doctor"></i> Médico: ' + ev.medico_tratante + '</small></div>' +
                            '</div>';
                        });
                    } else {
                        html = '<div style="padding: 20px; text-align: center; color: #637381;">No hay registros previos en la bitácora.</div>';
                    }
                    contenedor.innerHTML = html;
                })
                .catch(err => {
                    contenedor.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--imss-dorado);">Error al cargar el historial.</div>';
                });
        }

        function guardarEvolucionAjax(event) {
            event.preventDefault();
            const form = document.getElementById('formEvolucionAjax');
            const formData = new FormData(form);

            $.ajax({
                url: 'ingresos.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if(response && response.success) {
                        alert('¡Registro guardado y enviado al área correctamente! Ya puede imprimir sus documentos.');
                        cargarBitacoraEventos(pacienteActualGlobal.id_censo);
                        form.reset();
                        document.getElementById('evIdCenso').value = pacienteActualGlobal.id_censo;
                        $('#selectMedicoEvolucion').val(null).trigger('change');
                        $('#selectEstudiosLabMulti').val(null).trigger('change');
                    } else {
                        alert('Error al guardar: ' + (response && response.error ? response.error : 'Desconocido'));
                    }
                },
                error: function(xhr) {
                    alert('Error de conexión o servidor al procesar el registro: ' + (xhr.responseText || 'Desconocido'));
                }
            });
        }

        function cerrarModalEvolucion() { 
            document.getElementById('modalEvolucion').style.display = 'none';
            window.location.reload();
        }

        function imprimirReporteHorizontalCenso() {
            let areaNombre = "<?php echo addslashes($area_seleccionada); ?>";
            let fechaActual = "<?php echo date('d/m/Y H:i'); ?>";
            
            let camasCat = catalogoCamasArea.sort((a, b) => {
                return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
            });
            let pacientesMapa = pacientesPorCamaMapa;
            let totalOcupadas = Object.keys(pacientesMapa).length;
            let totalDisponibles = camasCat.length - totalOcupadas;

            let ventana = window.open('', '_blank', 'width=1100,height=800');
            ventana.document.write(`
                <html>
                <head>
                    <title>Reporte de Censo Hospitalario - ${areaNombre}</title>
                    <style>
                        @page { size: landscape; margin: 10mm; }
                        body { font-family: Arial, sans-serif; font-size: 11px; color: #111; margin: 0; text-transform: uppercase; }
                        .header-table { width: 100%; border-collapse: collapse; border-bottom: 3px solid #006847; padding-bottom: 8px; margin-bottom: 15px; }
                        .header-table td { vertical-align: middle; border: none; }
                        .logo-left img { height: 45px; object-fit: contain; }
                        .header-center { text-align: center; }
                        .header-center h2 { margin: 0; color: #006847; font-size: 16px; }
                        .header-center h3 { margin: 3px 0 0 0; color: #9f2241; font-size: 12px; }
                        .logo-right { text-align: right; }
                        .logo-right img { height: 40px; object-fit: contain; margin-left: 6px; }
                        .info-meta { display: flex; justify-content: space-between; font-weight: bold; font-size: 12px; margin-bottom: 10px; background: #f1f3f5; padding: 6px 10px; border: 1px solid #ccc; }
                        table.data-table { width: 100%; border-collapse: collapse; font-size: 11px; }
                        table.data-table th { background-color: #006847; color: white; text-align: left; padding: 8px 6px; font-weight: bold; border: 1px solid #004d35; }
                        table.data-table td { padding: 7px 6px; border: 1px solid #ccc; vertical-align: middle; }
                        table.data-table tr:nth-child(even) { background-color: #f9fafb; }
                        .firma-container { margin-top: 40px; display: flex; justify-content: space-around; text-align: center; }
                        .firma-line { width: 300px; border-top: 1px solid #111; padding-top: 4px; font-size: 11px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <table class="header-table">
                        <tr>
                            <td class="logo-left"><img src="logo_hospital.png" alt="Hospital"></td>
                            <td class="header-center">
                                <h2>GOBIERNO DE MÉXICO &nbsp;|&nbsp; IMSS-BIENESTAR</h2>
                                <h3>HOSPITAL GENERAL HUIMANGUILLO &mdash; REPORTE DE CENSO HOSPITALARIO</h3>
                            </td>
                            <td class="logo-right"><img src="logo_gobierno.png" alt="Gob"><img src="logo_imss.png" alt="IMSS"></td>
                        </tr>
                    </table>

                    <table class="info-meta">
                        <tr>
                            <td>ÁREA / SERVICIO: ${areaNombre}</td>
                            <td>FECHA Y HORA DE EMISIÓN: ${fechaActual}</td>
                            <td>OCUPADAS: ${totalOcupadas} | DISPONIBLES: ${totalDisponibles}</td>
                        </tr>
                    </table>

                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Cama</th>
                                <th style="width: 90px;">Expediente</th>
                                <th style="width: 180px;">Paciente / CURP</th>
                                <th style="width: 70px;">Edad / Sexo</th>
                                <th style="width: 80px;">Ingreso</th>
                                <th style="width: 50px;">Días</th>
                                <th style="width: 130px;">Médico Tratante</th>
                                <th style="width: 70px;">Dieta</th>
                                <th>Diagnóstico</th>
                                <th>Pendientes</th>
                            </tr>
                        </thead>
                        <tbody>
            `);

            if (camasCat.length > 0) {
                camasCat.forEach(camaCat => {
                    let camaLimpia = camaCat.trim().toUpperCase();
                    if (pacientesMapa[camaLimpia]) {
                        let pc = pacientesMapa[camaLimpia];
                        let dias = pc.dias_estancia || 0;
                        if (pc.fecha_ingreso) {
                            let diff = new Date() - new Date(pc.fecha_ingreso);
                            dias = Math.max(0, Math.floor(diff / (1000 * 60 * 60 * 24)));
                        }
                        let curp = pc.curp || pc.p_curp || 'S/N';
                        let expediente = pc.p_expediente || pc.curp || 'S/N';
                        let edad = pc.edad || pc.p_edad || 0;
                        let sexo = pc.sexo || pc.p_sexo || 'N/D';

                        ventana.document.write(`
                            <tr>
                                <td><strong>${pc.cama || camaCat}</strong></td>
                                <td><strong>${expediente}</strong></td>
                                <td><strong>${pc.nombre_paciente}</strong><br><small style="color:#555;">${curp}</small></td>
                                <td>${edad} AÑOS<br><small>${sexo}</small></td>
                                <td>${pc.fecha_ingreso}<br><small>${pc.hora_ingreso || ''}</small></td>
                                <td style="text-align: center;"><strong>${dias}</strong></td>
                                <td>${pc.medico_tratante_actual || 'S/A'}</td>
                                <td>${pc.dieta || 'GENERAL'}</td>
                                <td>${pc.diagnostico_ingreso || 'SIN DIAGNÓSTICO'}</td>
                                <td style="color: #b45309; font-weight: bold;">${pc.pendientes || 'NINGUNO'}</td>
                            </tr>
                        `);
                    } else {
                        ventana.document.write(`
                            <tr style="background-color: #f0fdf4;">
                                <td><strong>${camaCat}</strong></td>
                                <td colspan="9" style="color: #006847; font-weight: bold; font-style: italic;">CAMA DISPONIBLE / LIBRE PARA ASIGNACIÓN</td>
                            </tr>
                        `);
                    }
                });
            } else {
                ventana.document.write(`<tr><td colspan="10" style="text-align: center; padding: 20px; color: #666;">NO HAY CAMAS REGISTRADAS EN ESTE ÁREA.</td></tr>`);
            }

            ventana.document.write(`
                        </tbody>
                    </table>

                    <div class="firma-container">
                        <div>
                            <div class="firma-line">JEFE(A) DE ENFERMERÍA DE GUARDIA</div>
                        </div>
                        <div>
                            <div class="firma-line">MÉDICO(A) RESPONSABLE DEL ÁREA</div>
                        </div>
                    </div>

                    <script>window.onload = function() { window.print(); };<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }

        function imprimirNotaEvolucion(event, evEncoded) {
            event.preventDefault();
            let ev = JSON.parse(decodeURIComponent(evEncoded));
            let p = pacienteActualGlobal;
            
            let curpVal = p.curp || p.p_curp || 'S/N';
            let edadVal = p.edad || p.p_edad || 0;
            let sexoVal = p.sexo || p.p_sexo || 'N/D';

            let cedulaMatch = ev.medico_tratante.match(/CÉDULA:\s*(.*)/i);
            let cedula = (cedulaMatch && cedulaMatch[1] !== 'N/D') ? cedulaMatch[1] : 'PENDIENTE DE REGISTRO';
            let nombreMedicoLimpieza = ev.medico_tratante.replace(/- CÉDULA:.*/i, '').trim();

            let ventana = window.open('', '_blank', 'width=900,height=700');
            ventana.document.write(`
                <html>
                <head>
                    <title>Nota de Indicaciones - Hospital General de Huimanguillo</title>
                    <style>
                        body { font-family: Arial, sans-serif; font-size: 13px; color: #111; margin: 25px; }
                        .header-table { width: 100%; border-collapse: collapse; border-bottom: 3px solid #006847; padding-bottom: 10px; margin-bottom: 20px; }
                        .header-table td { vertical-align: middle; border: none; }
                        .logo-left { text-align: left; width: 20%; }
                        .logo-left img { height: 50px; object-fit: contain; }
                        .header-center { text-align: center; width: 60%; }
                        .header-center h2 { margin: 0; color: #006847; font-size: 20px; }
                        .header-center h3 { margin: 5px 0 0 0; color: #9f2241; font-size: 14px; }
                        .logo-right { text-align: right; width: 20%; }
                        .logo-right img { height: 45px; object-fit: contain; margin-left: 8px; }
                        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
                        .info-table td { border: 1px solid #ccc; padding: 6px 10px; }
                        .section-title { background: #f1f3f5; font-weight: bold; padding: 6px 10px; margin-top: 15px; border: 1px solid #ccc; font-size: 13px; }
                        .content-box { border: 1px solid #ccc; border-top: none; padding: 12px; min-height: 80px; margin-bottom: 15px; white-space: pre-wrap; }
                        .firma-box { margin-top: 60px; text-align: center; }
                        .firma-line { display: inline-block; width: 320px; border-top: 1px solid #111; padding-top: 5px; }
                    </style>
                </head>
                <body>
                    <table class="header-table">
                        <tr>
                            <td class="logo-left"><img src="logo_hospital.png" alt="Logo Hospital"></td>
                            <td class="header-center">
                                <h2>HOSPITAL GENERAL DE HUIMANGUILLO</h2>
                                <h3>SISTEMA DE ATENCIÓN MÉDICA - INDICACIONES</h3>
                            </td>
                            <td class="logo-right"><img src="logo_gobierno.png" alt="Gobierno"><img src="logo_imss.png" alt="IMSS"></td>
                        </tr>
                    </table>

                    <table class="info-table">
                        <tr>
                            <td><strong>PACIENTE:</strong> ${p.nombre_paciente}</td>
                            <td><strong>CURP:</strong> ${curpVal}</td>
                            <td><strong>EDAD:</strong> ${edadVal} AÑOS</td>
                        </tr>
                        <tr>
                            <td><strong>SEXO:</strong> ${sexoVal}</td>
                            <td><strong>CAMA:</strong> ${p.cama || 'S/C'}</td>
                            <td><strong>ÁREA / DESTINO:</strong> ${ev.area_evento}</td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>DIAGNÓSTICO INICIAL:</strong> ${p.diagnostico_ingreso || 'S/D'}</td>
                            <td><strong>FECHA / HORA:</strong> ${ev.fecha_evento} ${ev.hora_evento}</td>
                        </tr>
                    </table>

                    <div class="section-title">TIPO DE EVENTO: ${ev.tipo_evento}</div>
                    <div class="content-box"><strong>DESCRIPCIÓN CLÍNICA / EVOLUCIÓN:</strong><br>${ev.descripcion_evento}</div>

                    ${ev.tratamiento_farmacologico ? `<div class="section-title">TRATAMIENTO FARMACOLÓGICO</div><div class="content-box">${ev.tratamiento_farmacologico}</div>` : ''}
                    ${ev.indicaciones_generales ? `<div class="section-title">INDICACIONES GENERALES</div><div class="content-box">${ev.indicaciones_generales}</div>` : ''}
                    ${ev.estudios_solicitados ? `<div class="section-title">ESTUDIOS SOLICITADOS</div><div class="content-box">${ev.estudios_solicitados}</div>` : ''}

                    <div class="firma-box">
                        <div class="firma-line">
                            <strong>${nombreMedicoLimpieza}</strong><br>
                            CÉDULA PROFESIONAL: ${cedula}<br>
                            MÉDICO TRATANTE / RESPONSABLE
                        </div>
                    </div>

                    <script>window.onload = function() { window.print(); };<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }

        function imprimirRecetaMedica(event, evEncoded) {
            event.preventDefault();
            let ev = JSON.parse(decodeURIComponent(evEncoded));
            let p = pacienteActualGlobal;
            
            let edadVal = p.edad || p.p_edad || 0;
            let sexoVal = p.sexo || p.p_sexo || 'N/D';

            let cedulaMatch = ev.medico_tratante.match(/CÉDULA:\s*(.*)/i);
            let cedula = (cedulaMatch && cedulaMatch[1] !== 'N/D') ? cedulaMatch[1] : 'PENDIENTE DE REGISTRO';
            let nombreMedicoLimpieza = ev.medico_tratante.replace(/- CÉDULA:.*/i, '').trim();

            let ventana = window.open('', '_blank', 'width=900,height=700');
            ventana.document.write(`
                <html>
                <head>
                    <title>Receta Médica - Hospital General de Huimanguillo</title>
                    <style>
                        body { font-family: Arial, sans-serif; font-size: 13px; color: #111; margin: 25px; }
                        .header-table { width: 100%; border-collapse: collapse; border-bottom: 3px solid #9f2241; padding-bottom: 10px; margin-bottom: 20px; }
                        .header-table td { vertical-align: middle; border: none; }
                        .logo-left { text-align: left; width: 20%; }
                        .logo-left img { height: 50px; object-fit: contain; }
                        .header-center { text-align: center; width: 60%; }
                        .header-center h2 { margin: 0; color: #9f2241; font-size: 20px; }
                        .header-center h3 { margin: 5px 0 0 0; color: #006847; font-size: 14px; }
                        .logo-right { text-align: right; width: 20%; }
                        .logo-right img { height: 45px; object-fit: contain; margin-left: 8px; }
                        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
                        .info-table td { border: 1px solid #ccc; padding: 6px 10px; }
                        .section-title { background: #fdf3f5; color: #9f2241; font-weight: bold; padding: 6px 10px; margin-top: 15px; border: 1px solid #ccc; font-size: 13px; }
                        .content-box { border: 1px solid #ccc; border-top: none; padding: 15px; min-height: 120px; margin-bottom: 15px; white-space: pre-wrap; font-size: 14px; font-weight: bold; }
                        .firma-box { margin-top: 70px; text-align: center; }
                        .firma-line { display: inline-block; width: 320px; border-top: 1px solid #111; padding-top: 5px; }
                    </style>
                </head>
                <body>
                    <table class="header-table">
                        <tr>
                            <td class="logo-left"><img src="logo_hospital.png" alt="Hospital"></td>
                            <td class="header-center">
                                <h2>HOSPITAL GENERAL DE HUIMANGUILLO</h2>
                                <h3>RECETA MÉDICA INSTITUCIONAL</h3>
                            </td>
                            <td class="logo-right"><img src="logo_gobierno.png" alt="Gob"><img src="logo_imss.png" alt="IMSS"></td>
                        </tr>
                    </table>

                    <table class="info-table">
                        <tr>
                            <td><strong>PACIENTE:</strong> ${p.nombre_paciente}</td>
                            <td><strong>EDAD:</strong> ${edadVal} AÑOS</td>
                            <td><strong>SEXO:</strong> ${sexoVal}</td>
                        </tr>
                        <tr>
                            <td><strong>ÁREA / DESTINO:</strong> ${ev.area_evento}</td>
                            <td><strong>CAMA:</strong> ${p.cama || 'S/C'}</td>
                            <td><strong>FECHA:</strong> ${ev.fecha_evento}</td>
                        </tr>
                    </table>

                    <div class="section-title">TRATAMIENTO FARMACOLÓGICO (RÉXIT)</div>
                    <div class="content-box">${ev.tratamiento_farmacologico || 'SIN MEDICAMENTOS RECETADOS EN ESTE REGISTRO.'}</div>

                    <div class="section-title">INDICACIONES GENERALES Y RECOMENDACIONES</div>
                    <div class="content-box" style="font-weight: normal; font-size: 13px;">${ev.indicaciones_generales || 'NINGUNA.'}</div>

                    ${ev.estudios_solicitados ? `<div class="section-title">ESTUDIOS SOLICITADOS</div><div class="content-box" style="font-weight: normal; font-size: 13px;">${ev.estudios_solicitados}</div>` : ''}

                    ${ev.proxima_cita ? `<p style="font-size:13px;"><strong>PRÓXIMA CITA / REVALORACIÓN:</strong> ${ev.proxima_cita}${ev.proxima_hora ? 'A LAS ' + ev.proxima_hora : ''}</p>` : ''}

                    <div class="firma-box">
                        <div class="firma-line">
                            <strong>${nombreMedicoLimpieza}</strong><br>
                            CÉDULA PROFESIONAL: ${cedula}<br>
                            FIRMA Y SELLO DEL MÉDICO
                        </div>
                    </div>

                    <script>window.onload = function() { window.print(); };<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }

        function imprimirSolicitudLaboratorio(event, evEncoded) {
            event.preventDefault();
            let ev = JSON.parse(decodeURIComponent(evEncoded));
            let p = pacienteActualGlobal;
            
            let curpVal = p.curp || p.p_curp || 'S/N';
            let edadVal = p.edad || p.p_edad || 0;
            let sexoVal = p.sexo || p.p_sexo || 'N/D';
            let telVal = p.p_tel || 'S/N';
            let estSolicitados = (ev.estudios_solicitados || '').toUpperCase();

            let cedulaMatch = ev.medico_tratante.match(/CÉDULA:\s*(.*)/i);
            let cedula = (cedulaMatch && cedulaMatch[1] !== 'N/D') ? cedulaMatch[1] : 'PENDIENTE DE REGISTRO';
            let nombreMedicoLimpieza = ev.medico_tratante.replace(/- CÉDULA:.*/i, '').trim();

            let catalogo = catalogoEstudiosLab;

            let ventana = window.open('', '_blank', 'width=900,height=900');
            ventana.document.write(`
                <html>
                <head>
                    <title>Solicitud de Estudios de Laboratorio - Hospital General de Huimanguillo</title>
                    <style>
                        @page { size: letter; margin: 4mm; }
                        body { font-family: Arial, sans-serif; font-size: 10px; color: #111; margin: 0; text-transform: uppercase; line-height: 1.2; }
                        .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #006847; padding-bottom: 4px; margin-bottom: 8px; }
                        .header-table td { vertical-align: middle; border: none; }
                        .logo-left img { height: 35px; object-fit: contain; }
                        .header-center { text-align: center; }
                        .header-center h2 { margin: 0; color: #006847; font-size: 13px; }
                        .header-center h3 { margin: 2px 0 0 0; color: #9f2241; font-size: 10.5px; }
                        .logo-right { text-align: right; }
                        .logo-right img { height: 30px; object-fit: contain; margin-left: 5px; }
                        .sub-header { font-size: 9.5px; font-weight: bold; margin-bottom: 6px; text-align: center; }
                        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 10.5px; }
                        .info-grid td { padding: 3px 5px; border: 1px solid #cbd5e1; background: #f8fafc; }
                        .checkbox-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
                        .checkbox-table td { padding: 2px 4px; vertical-align: top; }
                        .box-cb { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; text-align: center; line-height: 10px; font-size: 8.5px; font-weight: bold; margin-right: 4px; }
                        .section-cat { background: #006847; color: white; font-weight: bold; padding: 3px 6px; margin-top: 6px; font-size: 10px; border: 1px solid #004d35; }
                        .firma-box { margin-top: 18px; text-align: center; font-size: 10.5px; }
                        .firma-line { display: inline-block; width: 280px; border-top: 1px solid #111; padding-top: 3px; }
                    </style>
                </head>
                <body>
                    <table class="header-table">
                        <tr>
                            <td class="logo-left"><img src="logo_hospital.png" alt="Hospital"></td>
                            <td class="header-center">
                                <h2>GOBIERNO DE MÉXICO &nbsp;|&nbsp; IMSS-BIENESTAR</h2>
                                <h3>HOSPITAL GENERAL HUIMANGUILLO &mdash; LABORATORIO DE ANÁLISIS CLÍNICOS</h3>
                            </td>
                            <td class="logo-right"><img src="logo_gobierno.png" alt="Gob"><img src="logo_imss.png" alt="IMSS"></td>
                        </tr>
                    </table>

                    <div style="text-align: center; font-weight: bold; font-size: 11.5px; margin-bottom: 2px;">SOLICITUD DE ESTUDIOS DE LABORATORIO</div>
                    <div class="sub-header">Horario de toma de muestra: Lunes a viernes 7:00 - 8:00 am.</div>

                    <table class="info-grid">
                        <tr>
                            <td colspan="2"><strong>Nombre del paciente:</strong> ${p.nombre_paciente}</td>
                            <td><strong>Fecha:</strong> ${ev.fecha_evento}</td>
                        </tr>
                        <tr>
                            <td><strong>CURP:</strong> ${curpVal}</td>
                            <td><strong>Edad:</strong> ${edadVal} AÑOS</td>
                            <td><strong>Sexo:</strong> ${sexoVal}</td>
                        </tr>
                        <tr>
                            <td><strong>Procedencia:</strong> ${p.p_est || 'TABASCO'}</td>
                            <td colspan="2"><strong>Teléfono:</strong> ${telVal}</td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Diagnóstico:</strong> ${p.diagnostico_ingreso || 'S/D'}</td>
                            <td><strong>Próxima cita:</strong> ${ev.proxima_cita || 'N/D'}</td>
                        </tr>
                        <tr>
                            <td colspan="3">
                                Hospitalización: [${ev.area_evento ? 'X' : ' '}] &nbsp;&nbsp; 
                                Urgencias: [ ] &nbsp;&nbsp; 
                                Consulta externa: [ ] &nbsp;&nbsp; 
                                <strong>Servicio:</strong> ${ev.area_evento} &nbsp;&nbsp; 
                                <strong>Cama:</strong> ${p.cama || 'S/C'}
                            </td>
                        </tr>
                    </table>

                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <div class="section-cat">Hematología / Coagulación</div>
                            ${renderizarEstudiosCategoria(catalogo, 'HEMATOLOGÍA / COAGULACIÓN', estSolicitados)}

                            <div class="section-cat">Uroanálisis / Parasitología</div>
                            ${renderizarEstudiosCategoria(catalogo, 'UROANÁLISIS / PARASITOLOGÍA', estSolicitados)}

                            <div class="section-cat">Química Sanguínea</div>
                            ${renderizarEstudiosCategoria(catalogo, 'QUÍMICA SANGUÍNEA', estSolicitados)}
                        </div>

                        <div style="flex: 1;">
                            <div class="section-cat">Serología</div>
                            ${renderizarEstudiosCategoria(catalogo, 'SEROLOGÍA', estSolicitados)}

                            <div class="section-cat">Inmunología Especial</div>
                            ${renderizarEstudiosCategoria(catalogo, 'INMUNOLOGÍA ESPECIAL', estSolicitados)}

                            <div class="section-cat">Microbiología / Otros</div>
                            ${renderizarEstudiosCategoria(catalogo, 'MICROBIOLOGÍA', estSolicitados)}

                            <div class="section-cat">Imagenología</div>
                            ${renderizarEstudiosCategoria(catalogo, 'IMAGENOLOGÍA', estSolicitados)}
                        </div>
                    </div>

                    <div style="margin-top: 8px; font-size: 10px;">
                        <strong>Otro estudio / Observaciones:</strong> __________________________________________________________________
                    </div>

                    <div class="firma-box">
                        <div class="firma-line">
                            <strong>${nombreMedicoLimpieza}</strong><br>
                            Céd. Prof.: ${cedula}<br>
                            NOMBRE Y FIRMA DEL MÉDICO
                        </div>
                    </div>

                    <script>window.onload = function() { window.print(); };<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }

        function renderizarEstudiosCategoria(catalogo, tipo, solicitadosStr) {
            let filtrados = catalogo.filter(e => e.tipo_estudio.toUpperCase() === tipo.toUpperCase());
            let html = '<table class="checkbox-table">';
            filtrados.forEach(est => {
                let nombreCompleto = est.estudio.toUpperCase();
                let abreviatura = est.nombre_corto.toUpperCase();
                let marcado = (solicitadosStr.includes(nombreCompleto) || solicitadosStr.includes(abreviatura)) ? 'X' : '&nbsp;';
                html += `<tr><td style="width: 16px;"><span class="box-cb">${marcado}</span></td><td>${est.estudio} (${est.nombre_corto})</td></tr>`;
            });
            html += '</table>';
            return html;
        }

        function buscarPacientesAjax() {
            const q = document.getElementById('inputBuscarPaciente').value.trim();
            const contenedor = document.getElementById('resultadoBusquedaPacientes');
            if (q.length < 2) { 
                contenedor.style.display = 'none'; 
                document.getElementById('seccionNuevoPacienteBtn').style.display = 'none';
                return; 
            }

            fetch('ingresos.php?ajax=buscar_pacientes&q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        data.forEach(p => {
                            let nombreCompleto = (p.nombre || '') + ' ' + (p.apaterno || '') + ' ' + (p.amaterno || '');
                            nombreCompleto = nombreCompleto.trim().toUpperCase();
                            html += '<div style="padding: 8px 12px; border-bottom: 1px solid #f1f3f5; cursor: pointer; font-size: 13px;" onmouseover="this.style.background=\'#f8f9fa\'" onmouseout="this.style.background=\'#fff\'" onclick="seleccionarPaciente(' + p.id_paciente + ', \'' + nombreCompleto.replace(/'/g, "\\'") + '\', \'' + (p.curp || '').toUpperCase() + '\')">' +
                                '<strong>' + nombreCompleto + '</strong> <span style="color: #637381; font-size: 11px;">(CURP: ' + (p.curp || 'S/N').toUpperCase() + ' - EXP: ' + (p.expediente || 'S/N').toUpperCase() + ')</span>' +
                            '</div>';
                        });
                        contenedor.style.display = 'block';
                        document.getElementById('seccionNuevoPacienteBtn').style.display = 'block';
                    } else {
                        html = '<div style="padding: 10px; color: #637381; font-size: 12px; text-align: center;">NO SE ENCONTRARON PACIENTES.</div>';
                        contenedor.style.display = 'block';
                        document.getElementById('seccionNuevoPacienteBtn').style.display = 'block';
                    }
                    contenedor.innerHTML = html;
                });
        }

        function seleccionarPaciente(id, nombre, curp) {
            document.getElementById('inputIdPaciente').value = id;
            document.getElementById('spanNombrePaciente').innerText = nombre + ' (CURP: ' + curp + ')';
            document.getElementById('pacienteSeleccionadoInfo').style.display = 'block';
            document.getElementById('resultadoBusquedaPacientes').style.display = 'none';
            document.getElementById('seccionNuevoPacienteBtn').style.display = 'none';
            document.getElementById('formularioNuevoPaciente').style.display = 'none';
            document.getElementById('inputBuscarPaciente').value = '';
        }
    </script>
</body>
</html>