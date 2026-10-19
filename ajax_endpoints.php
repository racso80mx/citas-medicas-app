<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexion.php';

$accion_ajax = $_GET['ajax'] ?? $_POST['ajax'] ?? '';

if ($accion_ajax === 'buscar_pacientes') {
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

if ($accion_ajax === 'obtener_camas_disponibles') {
    header('Content-Type: application/json');
    $area_nombre = trim($_GET['area'] ?? '');
    $id_censo_actual = intval($_GET['id_censo_actual'] ?? 0);

    try {
        $stmtC = $pdo->prepare("
            SELECT DISTINCT c.numero_cama FROM camas c
            LEFT JOIN areas_hospital ah ON c.id_area = ah.id_area
            LEFT JOIN areas_atencion aa ON c.id_area = aa.id_area
            WHERE TRIM(UPPER(ah.nombre)) = TRIM(UPPER(:area)) 
               OR TRIM(UPPER(aa.nombre_area)) = TRIM(UPPER(:area))
            ORDER BY 
                CASE WHEN c.numero_cama ~ '\d+' THEN 0 ELSE 1 END ASC,
                NULLIF(regexp_replace(c.numero_cama, '[^0-9]', '', 'g'), '')::INTEGER ASC,
                c.numero_cama ASC
        ");
        $stmtC->execute(['area' => $area_nombre]);
        $todas_camas_area = $stmtC->fetchAll(PDO::FETCH_COLUMN);

        $stmtOcupadas = $pdo->prepare("
            SELECT DISTINCT cama FROM censo 
            WHERE TRIM(UPPER(area_actual)) = TRIM(UPPER(:area))
              AND (estatus_censo IS NULL OR TRIM(UPPER(estatus_censo)) != 'DADO DE ALTA')
        ");
        $stmtOcupadas->execute(['area' => $area_nombre]);
        $camas_ocupadas = array_map(function($c) { return trim(strtoupper($c)); }, $stmtOcupadas->fetchAll(PDO::FETCH_COLUMN));

        $cama_actual_asignada = '';
        if ($id_censo_actual > 0) {
            $stmtCActual = $pdo->prepare("SELECT cama FROM censo WHERE id_censo = :id");
            $stmtCActual->execute(['id' => $id_censo_actual]);
            $cama_actual_asignada = trim(strtoupper($stmtCActual->fetchColumn() ?: ''));
        }

        $camas_disponibles = [];
        foreach ($todas_camas_area as $cama) {
            $cama_limpia = trim(strtoupper($cama));
            if (!in_array($cama_limpia, $camas_ocupadas) || $cama_limpia === $cama_actual_asignada) {
                $camas_disponibles[] = $cama;
            }
        }

        echo json_encode(['success' => true, 'camas' => $camas_disponibles]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'camas' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

if ($accion_ajax === 'crear_paciente_rapido') {
    header('Content-Type: application/json');
    $nombre    = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
    $apaterno  = mb_strtoupper(trim($_POST['apaterno'] ?? ''), 'UTF-8');
    $amaterno  = mb_strtoupper(trim($_POST['amaterno'] ?? ''), 'UTF-8');
    $curp      = mb_strtoupper(trim($_POST['curp'] ?? ''), 'UTF-8');
    $fnac      = trim($_POST['fnacimiento'] ?? '');
    $sexo      = mb_strtoupper(trim($_POST['sexo'] ?? ''), 'UTF-8');
    $estado    = mb_strtoupper(trim($_POST['estado'] ?? 'TABASCO'), 'UTF-8');
    $sin_curp  = isset($_POST['sin_curp']) && $_POST['sin_curp'] === 'true';

    if (!empty($nombre) && !empty($apaterno)) {
        try {
            $expediente_generado = null;
            if ($sin_curp || empty($curp)) {
                $letras = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 3);
                $numeros = rand(100000, 999999);
                $expediente_generado = "SE-" . $letras . $numeros;
                if (empty($curp)) { $curp = $expediente_generado; }
            }

            $edad = 0;
            if (!empty($fnac)) {
                $diff = strtotime(date('Y-m-d')) - strtotime($fnac);
                $edad = max(0, floor($diff / (365.25 * 24 * 60 * 60)));
            }

            $stmtInsP = $pdo->prepare("
                INSERT INTO pacientes (nombre, apaterno, amaterno, curp, expediente, fnacimiento, edad_actual, edad, sexo, estado)
                VALUES (:nom, :apat, :amat, :curp, :exp, :fnac, :edad1, :edad2, :sexo, :estado)
                RETURNING id_paciente
            ");
            $stmtInsP->execute([
                'nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno,
                'curp' => $curp, 'exp' => $expediente_generado,
                'fnac' => !empty($fnac) ? $fnac : null,
                'edad1' => $edad, 'edad2' => $edad, 'sexo' => $sexo, 'estado' => $estado
            ]);
            $nuevoId = $stmtInsP->fetchColumn();

            echo json_encode(['success' => true, 'id_paciente' => $nuevoId, 'nombre_completo' => "$nombre $apaterno $amaterno", 'curp' => $curp]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Nombre y apellido paterno obligatorios.']);
    }
    exit;
}

if ($accion_ajax === 'obtener_bitacora') {
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

if ($accion_ajax === 'guardar_evolucion_ajax') {
    header('Content-Type: application/json');
    $id_censo        = intval($_POST['id_censo_ev'] ?? 0);
    $fecha_evento    = trim($_POST['fecha_evento'] ?? date('Y-m-d'));
    $hora_evento     = trim($_POST['hora_evento'] ?? date('H:i'));
    $area_evento     = trim($_POST['area_evento'] ?? '');
    $medico_evento   = mb_strtoupper(trim($_POST['medico_evento'] ?? ''), 'UTF-8');
    $tipo_evento     = mb_strtoupper(trim($_POST['tipo_evento'] ?? 'EVOLUCIÓN MÉDICA'), 'UTF-8');
    $descripcion     = mb_strtoupper(trim($_POST['descripcion_evento'] ?? ''), 'UTF-8');
    $tratamiento_farm= mb_strtoupper(trim($_POST['tratamiento_farmacologico'] ?? ''), 'UTF-8');
    $indicaciones_gen= mb_strtoupper(trim($_POST['indicaciones_generales'] ?? ''), 'UTF-8');
    $estudios_sol    = mb_strtoupper(trim($_POST['estudios_solicitados'] ?? ''), 'UTF-8');
    $proxima_cita    = !empty($_POST['proxima_cita']) ? trim($_POST['proxima_cita']) : null;
    $proxima_hora    = !empty($_POST['proxima_hora']) ? trim($_POST['proxima_hora']) : null;

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
                'id_c' => $id_censo, 'f_ev' => $fecha_evento, 'h_ev' => $hora_evento,
                'ar_ev' => $area_evento, 'med_ev' => $medico_evento, 'tip_ev' => $tipo_evento,
                'desc' => $descripcion, 'trat' => $tratamiento_farm, 'ind' => $indicaciones_gen,
                'est' => $estudios_sol, 'prox' => $proxima_cita, 'prox_h' => $proxima_hora
            ]);

            $stmtUpCenso = $pdo->prepare("UPDATE censo SET area_actual = :ar, medico_tratante_actual = :med WHERE id_censo = :id");
            $stmtUpCenso->execute(['ar' => $area_evento, 'med' => $medico_evento, 'id' => $id_censo]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'La descripción es obligatoria.']);
    }
    exit;
}

if ($accion_ajax === 'historial_altas') {
    header('Content-Type: application/json');
    $tipo        = $_GET['tipo'] ?? 'todos';
    $fecha_unica = $_GET['fecha_unica'] ?? date('Y-m-d');
    $fecha_inicio= $_GET['fecha_inicio'] ?? date('Y-m-d');
    $fecha_fin   = $_GET['fecha_fin'] ?? date('Y-m-d');
    $medico      = trim($_GET['medico'] ?? '');

    try {
        $sql = "SELECT * FROM censo WHERE TRIM(UPPER(estatus_censo)) = 'DADO DE ALTA'";
        $params = [];

        if ($tipo === 'hoy') { $sql .= " AND fecha_egreso = CURRENT_DATE"; }
        elseif ($tipo === 'manana') { $sql .= " AND fecha_egreso = CURRENT_DATE + INTERVAL '1 day'"; }
        elseif ($tipo === 'semana') { $sql .= " AND fecha_egreso >= DATE_TRUNC('week', CURRENT_DATE) AND fecha_egreso <= CURRENT_DATE + INTERVAL '7 days'"; }
        elseif ($tipo === 'mes') { $sql .= " AND DATE_TRUNC('month', fecha_egreso) = DATE_TRUNC('month', CURRENT_DATE)"; }
        elseif ($tipo === 'fecha_unica') { $sql .= " AND fecha_egreso = :f_unica"; $params['f_unica'] = $fecha_unica; }
        elseif ($tipo === 'rango') { $sql .= " AND fecha_egreso BETWEEN :f_ini AND :f_fin"; $params['f_ini'] = $fecha_inicio; $params['f_fin'] = $fecha_fin; }
        elseif ($tipo === 'medico' && !empty($medico)) { $sql .= " AND medico_alta ILIKE :med"; $params['med'] = "%$med%"; }

        $sql .= " ORDER BY fecha_egreso DESC, hora_egreso DESC LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}