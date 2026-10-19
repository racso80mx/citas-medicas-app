<?php
// ===================================================================
// 1. CONFIGURACIÓN Y MANEJO DE ERRORES SEGURO
// ===================================================================
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}
require_once 'conexion.php';

function aMayusculas($str) {
    $str = trim((string)$str);
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($str, 'UTF-8');
    }
    return strtoupper($str);
}

function normalizarHora($horaStr) {
    $h = trim((string)$horaStr);
    if (empty($h)) return '';
    if (strlen($h) >= 5) {
        $h = substr($h, 0, 5);
    }
    $parts = explode(':', $h);
    if (count($parts) >= 2) {
        return sprintf('%02d:%02d', intval($parts[0]), intval($parts[1]));
    }
    return $h;
}

function normalizarFechaSQL($fechaStr) {
    $f = trim((string)$fechaStr);
    if (empty($f)) return '';
    if (strlen($f) >= 10) {
        return substr($f, 0, 10);
    }
    return $f;
}

// ===================================================================
// 2. CAPTURA DE FILTROS (POST O GET) PARA PRESERVARSE
// ===================================================================
$post_tipo_clinica   = $_POST['tipo_clinica'] ?? $_GET['tipo_clinica'] ?? '';
$post_nombre_clinica = $_POST['nombre_clinica'] ?? $_GET['nombre_clinica'] ?? '';
$post_colonia        = $_POST['colonia'] ?? $_GET['colonia'] ?? '';
$post_tipo_paciente  = $_POST['tipo_paciente'] ?? $_GET['tipo_paciente'] ?? '';
$post_fecha_cita     = $_POST['fecha_cita'] ?? $_GET['fecha_cita'] ?? date('Y-m-d');

// ===================================================================
// 3. AUTO-CREACIÓN Y MIGRACIÓN SEGURA DE TABLAS (POSTGRESQL SAFE)
// ===================================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS festivos (
        id_festivo SERIAL PRIMARY KEY,
        fecha DATE NOT NULL UNIQUE,
        descripcion VARCHAR(255)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clinica_excepciones (
        id_excepcion SERIAL PRIMARY KEY,
        id_clinica INT,
        fecha DATE NOT NULL,
        motivo VARCHAR(255),
        hora_inicio VARCHAR(100),
        hora_fin VARCHAR(100)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tipo_paciente (
        id_tipo_paciente SERIAL PRIMARY KEY,
        nombre_tipo VARCHAR(100) NOT NULL UNIQUE,
        descripcion VARCHAR(255),
        activo BOOLEAN DEFAULT TRUE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS colonias (
        id_colonia SERIAL PRIMARY KEY,
        nombre_colonia VARCHAR(255) NOT NULL,
        nombre_clinica VARCHAR(255) NOT NULL,
        activo BOOLEAN DEFAULT TRUE
    )");

    $pdo->exec("ALTER TABLE clinica_excepciones ADD COLUMN IF NOT EXISTS hora_inicio VARCHAR(100)");
    $pdo->exec("ALTER TABLE clinica_excepciones ADD COLUMN IF NOT EXISTS hora_fin VARCHAR(100)");
    $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS tipo_paciente VARCHAR(100) DEFAULT 'General'");
    $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS municipio VARCHAR(100)");
    $pdo->exec("ALTER TABLE clinicas ADD COLUMN IF NOT EXISTS dia_inactivo VARCHAR(20)");

    $countCol = $pdo->query("SELECT COUNT(*) FROM colonias")->fetchColumn();
    if ($countCol == 0) {
        $pdo->exec("INSERT INTO colonias (nombre_colonia, nombre_clinica) VALUES 
            ('Col. Centro', 'Nucleo Basico 1'),
            ('Col. Espejo', 'Nucleo Basico 1'),
            ('Col. Centro', 'Gumercindo -Ginecología y Obstetricia Vesp'),
            ('Col. Obrera', 'Gumercindo -Ginecología y Obstetricia Vesp'),
            ('Col. Aviación', 'Nucleo Basico 1')");
    }
} catch (Exception $e) {}

// ===================================================================
// 4. BLOQUES AJAX
// ===================================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'citas_dia') {
    header('Content-Type: application/json');
    $fecha = trim($_GET['fecha'] ?? date('Y-m-d'));
    $tipo  = trim($_GET['tipo_clinica'] ?? '');

    try {
        $sql = "
            SELECT id_cita, hora_cita::text as hora_cita, nombre_paciente, no_expediente, 
                   nombre_clinica, tipo_clinica, tipo_paciente, estatus 
            FROM citas 
            WHERE fecha_cita = :f 
              AND (estatus IS NULL OR TRIM(UPPER(estatus)) != 'CANCELADA')
        ";
        $params = ['f' => $fecha];

        if (!empty($tipo)) {
            $sql .= " AND TRIM(LOWER(tipo_clinica)) = TRIM(LOWER(:t))";
            $params['t'] = $tipo;
        }

        $sql .= " ORDER BY hora_cita ASC";

        $stmtCitasDia = $pdo->prepare($sql);
        $stmtCitasDia->execute($params);
        $citasDia = $stmtCitasDia->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'citas' => $citasDia]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'citas' => [], 'mensaje' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_bloqueo_slot') {
    header('Content-Type: application/json');
    $fecha   = trim($_GET['fecha'] ?? '');
    $clinica = trim($_GET['clinica'] ?? '');
    $hora    = normalizarHora($_GET['hora'] ?? '');

    if (empty($fecha) || empty($clinica) || empty($hora)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Datos incompletos.']); exit;
    }

    try {
        $stmtCl = $pdo->prepare("SELECT id_clinica FROM clinicas WHERE TRIM(LOWER(nombre_clinica)) = TRIM(LOWER(:c)) OR TRIM(LOWER(nombre_clinica)) LIKE TRIM(LOWER(:c_like)) LIMIT 1");
        $stmtCl->execute(['c' => $clinica, 'c_like' => "%$clinica%"]);
        $id_clinica = $stmtCl->fetchColumn();

        if (!$id_clinica) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Clínica no encontrada.']); exit;
        }

        $stmtChk = $pdo->prepare("SELECT id_excepcion FROM clinica_excepciones WHERE id_clinica = :id_c AND fecha = :f AND TRIM(hora_inicio::text) LIKE :h");
        $stmtChk->execute(['id_c' => $id_clinica, 'f' => $fecha, 'h' => "$hora%"]);
        $id_exc = $stmtChk->fetchColumn();

        if ($id_exc) {
            $stmtDel = $pdo->prepare("DELETE FROM clinica_excepciones WHERE id_excepcion = :id");
            $stmtDel->execute(['id' => $id_exc]);
            echo json_encode(['status' => 'ok', 'accion' => 'desbloqueado', 'mensaje' => "Horario $hora desbloqueado."]);
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO clinica_excepciones (id_clinica, fecha, motivo, hora_inicio, hora_fin) VALUES (:id_c, :f, 'Bloqueo Manual', :h, :h)");
            $stmtIns->execute(['id_c' => $id_clinica, 'f' => $fecha, 'h' => $hora]);
            echo json_encode(['status' => 'ok', 'accion' => 'bloqueado', 'mensaje' => "Horario $hora bloqueado manualmente."]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'mensaje' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'meta_clinica') {
    header('Content-Type: application/json');
    $clinica = trim($_GET['clinica'] ?? '');
    try {
        $stmtCl = $pdo->prepare("SELECT * FROM clinicas WHERE TRIM(LOWER(nombre_clinica)) = TRIM(LOWER(:c)) OR TRIM(LOWER(nombre_clinica)) LIKE TRIM(LOWER(:c_like)) LIMIT 1");
        $stmtCl->execute(['c' => $clinica, 'c_like' => "%$clinica%"]);
        $row_cli = $stmtCl->fetch(PDO::FETCH_ASSOC);

        $rc = $row_cli ? array_change_key_case($row_cli, CASE_LOWER) : [];
        $id_clinica_val = $rc['id_clinica'] ?? null;
        $tipo_cli = strtoupper($rc['tipo_clinica'] ?? $rc['tipo'] ?? $rc['servicio'] ?? '');
        $atiende_fines = $rc['atiende_fin_de_semana'] ?? $rc['atiende_fines'] ?? $rc['fin_de_semana'] ?? $rc['atiende_finde'] ?? $rc['sabados'] ?? null;
        $dia_inactivo = trim($rc['dia_inactivo'] ?? '');

        $no_atiende_fin = false;
        if ($atiende_fines !== null) {
            $val_upper = strtoupper(trim((string)$atiende_fines));
            if (in_array($val_upper, ['FALSO', 'FALSE', '0', 'NO', 'OFF']) || $atiende_fines === false) {
                $no_atiende_fin = true;
            }
        } else {
            if (strpos($tipo_cli, 'CONSULTA EXTERNA') !== false || strpos(strtoupper($clinica), 'NUCLEO') !== false || strpos(strtoupper($clinica), 'BASICO') !== false) {
                $no_atiende_fin = true;
            }
        }

        $festivos_raw = $pdo->query("SELECT fecha FROM festivos")->fetchAll(PDO::FETCH_COLUMN);
        $festivos = [];
        foreach($festivos_raw as $f) {
            $f_clean = normalizarFechaSQL($f);
            if(!empty($f_clean)) $festivos[] = $f_clean;
        }
        
        $excepciones = [];
        if ($id_clinica_val) {
            $stmtE = $pdo->prepare("SELECT fecha, hora_inicio::text as hora_inicio, hora_fin::text as hora_fin FROM clinica_excepciones WHERE id_clinica = :id_c");
            $stmtE->execute(['id_c' => intval($id_clinica_val)]);
            $excepciones_raw = $stmtE->fetchAll(PDO::FETCH_ASSOC);

            foreach($excepciones_raw as $exc) {
                $exc_f = normalizarFechaSQL($exc['fecha'] ?? '');
                $h_ini = trim(strtolower((string)($exc['hora_inicio'] ?? '')));
                $h_fin = trim(strtolower((string)($exc['hora_fin'] ?? '')));
                
                if (!empty($exc_f) && (empty($h_ini) || strpos($h_ini, 'completo') !== false || strpos($h_ini, 'todo') !== false)) {
                    $excepciones[] = $exc_f;
                }
            }
        }

        echo json_encode([
            'status' => 'ok',
            'no_atiende_fin' => $no_atiende_fin,
            'dia_inactivo' => $dia_inactivo,
            'festivos' => array_values(array_unique($festivos)),
            'excepciones' => array_values(array_unique($excepciones))
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'mensaje' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'slots') {
    header('Content-Type: application/json');
    $fecha = trim($_GET['fecha'] ?? '');
    $clinica = trim($_GET['clinica'] ?? '');

    if (empty($fecha) || empty($clinica)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Faltan datos de fecha o clínica.']); exit;
    }

    try {
        $stmtCl = $pdo->prepare("SELECT * FROM clinicas WHERE TRIM(LOWER(nombre_clinica)) = TRIM(LOWER(:c)) OR TRIM(LOWER(nombre_clinica)) LIKE TRIM(LOWER(:c_like)) LIMIT 1");
        $stmtCl->execute(['c' => $clinica, 'c_like' => "%$clinica%"]);
        $row_cli = $stmtCl->fetch(PDO::FETCH_ASSOC);

        if (!$row_cli) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No se encontró la configuración de la clínica.']); exit;
        }

        $rc = array_change_key_case($row_cli, CASE_LOWER);
        $id_clinica_val = $rc['id_clinica'] ?? null;
        $tipo_cli      = strtoupper($rc['tipo_clinica'] ?? $rc['tipo'] ?? $rc['servicio'] ?? '');
        $hora_inicio   = normalizarHora($rc['hora_inicio'] ?? $rc['horainicio'] ?? '08:00');
        $hora_fin      = normalizarHora($rc['hora_fin'] ?? $rc['horafin'] ?? '14:00');
        $duracion      = intval($rc['duracion_consulta'] ?? $rc['duracionconsulta'] ?? $rc['duracion'] ?? 25);
        $hora_comida   = normalizarHora($rc['hora_comida'] ?? $rc['horacomida'] ?? '');
        $atiende_fines = $rc['atiende_fin_de_semana'] ?? $rc['atiende_fines'] ?? $rc['fin_de_semana'] ?? $rc['atiende_finde'] ?? $rc['sabados'] ?? null;
        $dia_inactivo  = trim($rc['dia_inactivo'] ?? '');

        if($duracion <= 0) $duracion = 25;

        $dia_semana = date('N', strtotime($fecha));
        $es_fin_de_semana = ($dia_semana == 6 || $dia_semana == 7);

        $dias_map = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $nombre_dia_actual = $dias_map[$dia_semana] ?? '';

        if (!empty($dia_inactivo) && strcasecmp($nombre_dia_actual, $dia_inactivo) === 0) {
            echo json_encode(['status' => 'cerrado', 'mensaje' => "Esta clínica no labora los días $dia_inactivo según su configuración."]); exit;
        }

        $no_atiende_fin = false;
        if ($atiende_fines !== null) {
            $val_upper = strtoupper(trim((string)$atiende_fines));
            if (in_array($val_upper, ['FALSO', 'FALSE', '0', 'NO', 'OFF']) || $atiende_fines === false) {
                $no_atiende_fin = true;
            }
        } else {
            if (strpos($tipo_cli, 'CONSULTA EXTERNA') !== false || strpos(strtoupper($clinica), 'NUCLEO') !== false || strpos(strtoupper($clinica), 'BASICO') !== false) {
                $no_atiende_fin = true;
            }
        }

        if ($es_fin_de_semana && $no_atiende_fin) {
            echo json_encode(['status' => 'cerrado', 'mensaje' => 'Esta clínica no labora los fines de semana según su configuración.']); exit;
        }

        try {
            $stmtF = $pdo->prepare("SELECT descripcion FROM festivos WHERE fecha = :f");
            $stmtF->execute(['f' => $fecha]);
            if ($festivo = $stmtF->fetchColumn()) {
                echo json_encode(['status' => 'cerrado', 'mensaje' => "Día Festivo General: " . $festivo]); exit;
            }
        } catch (Exception $ex) {}

        $rangos_permiso = [];
        $bloqueos_exactos = [];

        if ($id_clinica_val) {
            try {
                $stmtE = $pdo->prepare("SELECT motivo, hora_inicio::text as hora_inicio, hora_fin::text as hora_fin FROM clinica_excepciones WHERE fecha = :f AND id_clinica = :id_c");
                $stmtE->execute(['f' => $fecha, 'id_c' => intval($id_clinica_val)]);
                $excepciones_dia = $stmtE->fetchAll(PDO::FETCH_ASSOC);

                foreach ($excepciones_dia as $exc) {
                    $motivo_exc = !empty($exc['motivo']) ? $exc['motivo'] : 'Permiso';
                    $h_ini_raw = trim((string)($exc['hora_inicio'] ?? ''));
                    $h_fin_raw = trim((string)($exc['hora_fin'] ?? ''));

                    if (empty($h_ini_raw) || strpos(strtolower($h_ini_raw), 'completo') !== false || strpos(strtolower($h_ini_raw), 'todo') !== false) {
                        echo json_encode(['status' => 'cerrado', 'mensaje' => "Clínica No Disponible (Excepción/Permiso): " . $motivo_exc]); 
                        exit;
                    } 

                    $h_ini_clean = normalizarHora($h_ini_raw);
                    $h_fin_clean = normalizarHora($h_fin_raw);

                    if (!empty($h_ini_clean) && (empty($h_fin_clean) || $h_ini_clean === $h_fin_clean)) {
                        $bloqueos_exactos[$h_ini_clean] = $motivo_exc;
                    } elseif (!empty($h_ini_clean) && !empty($h_fin_clean)) {
                        $rangos_permiso[] = [
                            'inicio' => strtotime($fecha . ' ' . $h_ini_clean),
                            'fin'    => strtotime($fecha . ' ' . $h_fin_clean),
                            'motivo' => $motivo_exc
                        ];
                    }
                }
            } catch (Exception $ex) {}
        }

        $stmtC = $pdo->prepare("
            SELECT hora_cita::text as hora_cita, nombre_paciente 
            FROM citas 
            WHERE fecha_cita = :f 
              AND (TRIM(LOWER(nombre_clinica)) = TRIM(LOWER(:c)) OR TRIM(LOWER(nombre_clinica)) LIKE TRIM(LOWER(:c_like)))
              AND (estatus IS NULL OR TRIM(UPPER(estatus)) != 'CANCELADA')
        ");
        $stmtC->execute(['f' => $fecha, 'c' => trim($clinica), 'c_like' => "%" . trim($clinica) . "%"]);
        $citas_registradas = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $ocupadas = [];
        $citas_map = [];
        
        foreach($citas_registradas as $cr) {
            $h_clean = normalizarHora($cr['hora_cita']);
            if (empty($h_clean)) continue;

            $ocupadas[] = $h_clean;
            $pac_nombre = trim((string)($cr['nombre_paciente'] ?? ''));
            if(empty($pac_nombre) || strtoupper($pac_nombre) === 'NULL') {
                $pac_nombre = 'PACIENTE';
            } else {
                $partes_nombre = explode(' ', $pac_nombre);
                $pac_nombre = strtoupper($partes_nombre[0]);
            }
            $citas_map[$h_clean] = $pac_nombre;
        }

        $todas_las_horas = [];
        $current_time = strtotime($fecha . ' ' . $hora_inicio);
        $end_time = strtotime($fecha . ' ' . $hora_fin);
        $comida_time = !empty($hora_comida) ? strtotime($fecha . ' ' . $hora_comida) : null;

        while ($current_time < $end_time) {
            $slot_str = date('H:i', $current_time);
            $es_comida = ($comida_time && $current_time === $comida_time);
            
            if (!$es_comida) {
                $todas_las_horas[] = $slot_str;
            }
            $current_time = strtotime("+$duracion minutes", $current_time);
        }

        foreach ($ocupadas as $hora_ocupada) {
            if (!in_array($hora_ocupada, $todas_las_horas)) {
                $todas_las_horas[] = $hora_ocupada;
            }
        }

        usort($todas_las_horas, function($a, $b) {
            return strtotime($a) - strtotime($b);
        });

        $slots_data = [];
        foreach ($todas_las_horas as $h) {
            $is_ocupado = in_array($h, $ocupadas, true);
            $t_slot = strtotime($fecha . ' ' . $h);
            $is_permiso = false;
            $motivo_permiso = '';

            foreach ($rangos_permiso as $rp) {
                if ($t_slot >= $rp['inicio'] && $t_slot < $rp['fin']) {
                    $is_permiso = true;
                    $motivo_permiso = $rp['motivo'];
                    break;
                }
            }

            if (!$is_permiso && isset($bloqueos_exactos[$h])) {
                $is_permiso = true;
                $motivo_permiso = $bloqueos_exactos[$h];
            }

            $label = '';
            if ($is_ocupado) {
                $label = $citas_map[$h] ?? 'OCUPADO';
            } elseif ($is_permiso) {
                $label = strtoupper($motivo_permiso);
            }

            $slots_data[] = [
                'hora' => $h,
                'disponible' => (!$is_ocupado && !$is_permiso),
                'permiso' => $is_permiso,
                'paciente' => $label
            ];
        }

        echo json_encode(['status' => 'abierto', 'slots' => $slots_data, 'duracion' => $duracion]); exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al calcular horarios: ' . $e->getMessage()]); exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'pacientes') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    try {
        if (!empty($q)) {
            $stmt_pac = $pdo->prepare("
                SELECT * FROM pacientes 
                WHERE nombre ILIKE :q 
                   OR apaterno ILIKE :q 
                   OR amaterno ILIKE :q 
                   OR (nombre || ' ' || apaterno) ILIKE :q 
                   OR (nombre || ' ' || apaterno || ' ' || amaterno) ILIKE :q 
                   OR expediente::text ILIKE :q 
                   OR curp ILIKE :q 
                   OR telefono ILIKE :q 
                ORDER BY id_paciente DESC LIMIT 20
            ");
            $stmt_pac->execute(['q' => "%$q%"]);
            $pacientes = $stmt_pac->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $pacientes = $pdo->query("SELECT * FROM pacientes ORDER BY id_paciente DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $pacientes = []; }
    echo json_encode($pacientes); exit;
}

// ===================================================================
// 5. PROCESAMIENTO DE FORMULARIOS (POST)
// ===================================================================
$mensaje = '';
$error = '';
$id_cita_creada = null;
$filtro_reciente = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'actualizar_paciente_faltante') {
        $id_pac    = intval($_POST['id_paciente'] ?? 0);
        $curp_act  = strtoupper(trim($_POST['curp'] ?? ''));
        $mun_act   = aMayusculas($_POST['municipio'] ?? '');
        $dom_act   = aMayusculas($_POST['domicilio'] ?? '');
        $col_act   = aMayusculas($_POST['colonia_paciente'] ?? '');

        if ($id_pac > 0 && !empty($curp_act) && !empty($mun_act) && !empty($dom_act) && !empty($col_act)) {
            try {
                $stmtUpd = $pdo->prepare("UPDATE pacientes SET curp = :curp, municipio = :mun, domicilio = :dom, colonia = :col WHERE id_paciente = :id");
                $stmtUpd->execute([
                    'curp' => $curp_act,
                    'mun'  => $mun_act,
                    'dom'  => $dom_act,
                    'col'  => $col_act,
                    'id'   => $id_pac
                ]);
                $_SESSION['id_paciente_seleccionado'] = $id_pac;
                $mensaje = "Datos del paciente actualizados correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar los datos del paciente: " . $e->getMessage();
            }
        } else {
            $error = "Debe completar todos los campos obligatorios para actualizar al paciente.";
        }
    }

    if ($accion === 'registrar_paciente') {
        $expediente      = trim($_POST['expediente'] ?? '');
        $nombre          = aMayusculas($_POST['nombre'] ?? '');
        $apaterno        = aMayusculas($_POST['apaterno'] ?? '');
        $amaterno        = aMayusculas($_POST['amaterno'] ?? '');
        $curp            = strtoupper(trim($_POST['curp'] ?? ''));
        $edad            = intval($_POST['edad'] ?? 0);
        $edad_actual     = intval($_POST['edad_actual'] ?? $edad);
        $sexo            = aMayusculas($_POST['sexo'] ?? '');
        $estado          = aMayusculas($_POST['estado'] ?? '');
        $municipio       = aMayusculas($_POST['municipio'] ?? '');
        $domicilio       = aMayusculas($_POST['domicilio'] ?? '');
        $colonia_pac     = aMayusculas($_POST['colonia_paciente'] ?? '');
        $telefono        = trim($_POST['telefono'] ?? '');
        $fnacimiento     = trim($_POST['fnacimiento'] ?? '');
        $year_nacimiento = trim($_POST['year_nacimiento'] ?? '');
        $nombre_padre    = aMayusculas($_POST['nombre_padre'] ?? '');
        $nombre_madre    = aMayusculas($_POST['nombre_madre'] ?? '');
        $edad_padre      = trim($_POST['edad_padre'] ?? '');
        $edad_madre      = trim($_POST['edad_madre'] ?? '');
        $fecha_apertura  = trim($_POST['fecha_apertura'] ?? date('Y-m-d'));
        $estatus         = aMayusculas($_POST['estatus'] ?? 'ACTIVO');
        $tipo_paciente   = aMayusculas($_POST['tipo_paciente'] ?? 'General');
        
        $nombre_completo  = trim("$nombre $apaterno $amaterno");
        $nombre_apellidos = trim("$apaterno $amaterno $nombre");

        if (!empty($curp) && !empty($telefono) && !empty($nombre) && !empty($expediente)) {
            try {
                $chkCurp = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE curp = :curp LIMIT 1");
                $chkCurp->execute(['curp' => $curp]);
                $paciente_por_curp = $chkCurp->fetch(PDO::FETCH_ASSOC);

                if ($paciente_por_curp) {
                    $error = "El paciente ya se encuentra registrado con esa CURP.";
                    $filtro_reciente = $curp;
                    $_SESSION['id_paciente_seleccionado'] = $paciente_por_curp['id_paciente'];
                } else {
                    $chkExp = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE expediente = :exp LIMIT 1");
                    $chkExp->execute(['exp' => $expediente]);
                    
                    if ($chkExp->fetch(PDO::FETCH_ASSOC)) {
                        $expediente = 'SE-' . mt_rand(100000, 999999);
                    }

                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO pacientes (
                                expediente, nombre, apaterno, amaterno, fnacimiento, year_nacimiento, 
                                edad, edad_actual, sexo, estado, municipio, domicilio, colonia, nombre_padre, 
                                nombre_madre, edad_padre, edad_madre, fecha_apertura, estatus, 
                                telefono, nombre_completo, curp, nombre_apellidos, tipo_paciente
                            ) VALUES (
                                :exp, :nom, :apat, :amat, :fnac, :ynac, 
                                :edad, :edadact, :sexo, :est, :mun, :dom, :col, :npad, 
                                :nmad, :epad, :emad, :fape, :estatus, 
                                :tel, :ncomp, :curp, :napel, :tp
                            )
                        ");
                        $stmt->execute([
                            'exp' => $expediente, 'nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno,
                            'fnac' => !empty($fnacimiento) ? $fnacimiento : null, 'ynac' => $year_nacimiento, 'edad' => $edad, 'edadact' => $edad_actual,
                            'sexo' => $sexo, 'est' => $estado, 'mun' => $municipio, 'dom' => $domicilio, 'col' => $colonia_pac,
                            'npad' => $nombre_padre, 'nmad' => $nombre_madre, 'epad' => $edad_padre, 'emad' => $edad_madre,
                            'fape' => !empty($fecha_apertura) ? $fecha_apertura : date('Y-m-d'), 'estatus' => $estatus,
                            'tel' => $telefono, 'ncomp' => $nombre_completo, 'curp' => $curp, 'napel' => $nombre_apellidos, 'tp' => $tipo_paciente
                        ]);
                    } catch (Exception $eCol) {
                        $stmt = $pdo->prepare("
                            INSERT INTO pacientes (
                                expediente, nombre, apaterno, amaterno, fnacimiento, year_nacimiento, 
                                edad, edad_actual, sexo, estado, domicilio, colonia, nombre_padre, 
                                nombre_madre, edad_padre, edad_madre, fecha_apertura, estatus, 
                                telefono, nombre_completo, curp, nombre_apellidos
                            ) VALUES (
                                :exp, :nom, :apat, :amat, :fnac, :ynac, 
                                :edad, :edadact, :sexo, :est, :dom, :col, :npad, 
                                :nmad, :epad, :emad, :fape, :estatus, 
                                :tel, :ncomp, :curp, :napel
                            )
                        ");
                        $stmt->execute([
                            'exp' => $expediente, 'nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno,
                            'fnac' => !empty($fnacimiento) ? $fnacimiento : null, 'ynac' => $year_nacimiento, 'edad' => $edad, 'edadact' => $edad_actual,
                            'sexo' => $sexo, 'est' => $estado, 'dom' => $domicilio, 'col' => $colonia_pac,
                            'npad' => $nombre_padre, 'nmad' => $nombre_madre, 'epad' => $edad_padre, 'emad' => $edad_madre,
                            'fape' => !empty($fecha_apertura) ? $fecha_apertura : date('Y-m-d'), 'estatus' => $estatus,
                            'tel' => $telefono, 'ncomp' => $nombre_completo, 'curp' => $curp, 'napel' => $nombre_apellidos
                        ]);
                    }

                    $id_nuevo_pac = $pdo->lastInsertId();
                    $_SESSION['id_paciente_seleccionado'] = $id_nuevo_pac;
                    $mensaje = "Paciente registrado exitosamente y seleccionado automáticamente.";
                    $filtro_reciente = $nombre; 
                }
            } catch (Exception $e) {
                $error = "Error al registrar el paciente: " . $e->getMessage();
            }
        } else {
            $error = "Los campos obligatorios (Expediente, Nombre, CURP y Teléfono) deben ser completados.";
        }
    }

    if ($accion === 'agendar_cita') {
        $id_paciente    = trim($_POST['paciente_id'] ?? '');
        $tipo_clinica   = trim($_POST['tipo_clinica'] ?? '');
        $nombre_clinica = trim($_POST['nombre_clinica'] ?? '');
        $colonia        = trim($_POST['colonia'] ?? '');
        $tipo_paciente  = trim($_POST['tipo_paciente'] ?? '');
        $fecha_cita     = trim($_POST['fecha_cita'] ?? '');
        $hora_cita      = normalizarHora($_POST['hora_cita'] ?? '');

        if (!empty($id_paciente) && !empty($fecha_cita) && !empty($hora_cita) && !empty($nombre_clinica)) {
            try {
                $stmtClCheck = $pdo->prepare("SELECT * FROM clinicas WHERE TRIM(LOWER(nombre_clinica)) = TRIM(LOWER(:c)) OR TRIM(LOWER(nombre_clinica)) LIKE TRIM(LOWER(:c_like)) LIMIT 1");
                $stmtClCheck->execute(['c' => $nombre_clinica, 'c_like' => "%$nombre_clinica%"]);
                $cli_chk = $stmtClCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($cli_chk) {
                    $rc_c = array_change_key_case($cli_chk, CASE_LOWER);
                    $id_cli_chk = $rc_c['id_clinica'] ?? null;
                    $dia_inactivo_chk = trim($rc_c['dia_inactivo'] ?? '');
                    
                    $dia_sem_chk = date('N', strtotime($fecha_cita));
                    $map_dias_chk = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
                    $nom_dia_chk = $map_dias_chk[$dia_sem_chk] ?? '';
                    
                    if (!empty($dia_inactivo_chk) && strcasecmp($nom_dia_chk, $dia_inactivo_chk) === 0) {
                        throw new Exception("No se puede agendar: La clínica no labora los días $dia_inactivo_chk.");
                    }
                    
                    if ($id_cli_chk) {
                        $stmtExcChk = $pdo->prepare("SELECT motivo, hora_inicio::text as hora_inicio, hora_fin::text as hora_fin FROM clinica_excepciones WHERE fecha = :f AND id_clinica = :id_c");
                        $stmtExcChk->execute(['f' => $fecha_cita, 'id_c' => intval($id_cli_chk)]);
                        $excs = $stmtExcChk->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach($excs as $e_item) {
                            $h_ini = normalizarHora($e_item['hora_inicio'] ?? '');
                            $h_fin = normalizarHora($e_item['hora_fin'] ?? '');
                            
                            if (empty($h_ini) || strpos(strtolower($h_ini), 'completo') !== false) {
                                throw new Exception("No se puede agendar: El día cuenta con una excepción/permiso (" . ($e_item['motivo'] ?? 'Permiso') . ").");
                            } elseif ($h_ini === $hora_cita && (empty($h_fin) || $h_ini === $h_fin)) {
                                throw new Exception("No se puede agendar: El horario $hora_cita está bloqueado (" . ($e_item['motivo'] ?? 'Bloqueado') . ").");
                            } elseif (!empty($h_ini) && !empty($h_fin)) {
                                $t_cita_chk = strtotime($fecha_cita . ' ' . $hora_cita);
                                $t_ini_chk = strtotime($fecha_cita . ' ' . $h_ini);
                                $t_fin_chk = strtotime($fecha_cita . ' ' . $h_fin);

                                if ($t_cita_chk >= $t_ini_chk && $t_cita_chk < $t_fin_chk) {
                                    throw new Exception("No se puede agendar: El horario $hora_cita está dentro del permiso (" . ($e_item['motivo'] ?? 'Permiso') . ").");
                                }
                            }
                        }
                    }
                }

                $chk = $pdo->prepare("SELECT id_cita FROM citas WHERE fecha_cita = :f AND TRIM(hora_cita::text) LIKE :h AND (TRIM(LOWER(nombre_clinica)) = TRIM(LOWER(:c)) OR TRIM(LOWER(nombre_clinica)) LIKE TRIM(LOWER(:c_like))) AND (estatus IS NULL OR TRIM(UPPER(estatus)) != 'CANCELADA')");
                $chk->execute(['f' => $fecha_cita, 'h' => "$hora_cita%", 'c' => $nombre_clinica, 'c_like' => "%$nombre_clinica%"]);
                if ($chk->fetch()) {
                    $error = "El horario $hora_cita ya fue ocupado. Por favor seleccione otro.";
                } else {
                    $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
                    $stmtP->execute(['id' => intval($id_paciente)]);
                    $pac = $stmtP->fetch(PDO::FETCH_ASSOC);

                    if ($pac) {
                        $stmtC = $pdo->prepare("
                            INSERT INTO citas (
                                id_paciente, fecha_cita, hora_cita, tipo_clinica, nombre_clinica, 
                                colonia, tipo_paciente, nombre_paciente, no_expediente, 
                                curp, telefono, estatus, fecha_creacion
                            ) VALUES (
                                :id_paciente, :fecha_cita, :hora_cita, :tipo_clinica, :nombre_clinica, 
                                :colonia, :tipo_paciente, :nombre_paciente, :no_expediente, 
                                :curp, :telefono, 'PROGRAMADA', :fecha_creacion
                            )
                        ");
                        $stmtC->execute([
                            'id_paciente'     => (string)$id_paciente, 
                            'fecha_cita'      => $fecha_cita,
                            'hora_cita'       => $hora_cita,
                            'tipo_clinica'    => $tipo_clinica,
                            'nombre_clinica'  => $nombre_clinica,
                            'colonia'         => $colonia,
                            'tipo_paciente'   => $tipo_paciente,
                            'nombre_paciente' => trim(($pac['nombre'] ?? '') . ' ' . ($pac['apaterno'] ?? '') . ' ' . ($pac['amaterno'] ?? '')),
                            'no_expediente'   => $pac['expediente'] ?? 'S/N',
                            'curp'            => $pac['curp'] ?? '',
                            'telefono'        => $pac['telefono'] ?? '',
                            'fecha_creacion'  => date('Y-m-d H:i:s')
                        ]);
                        
                        $id_cita_creada = $pdo->lastInsertId();
                        $mensaje = "Cita agendada correctamente para el $fecha_cita a las $hora_cita.";
                        unset($_SESSION['id_paciente_seleccionado']);
                    } else {
                        $error = "No se encontró información del paciente seleccionado.";
                    }
                }
            } catch (Exception $e) {
                $error = "Error al agendar la cita: " . $e->getMessage();
            }
        } else {
            $error = "Debe seleccionar un paciente, fecha, horario y clínica para guardar la cita.";
        }
    }
}

// ===================================================================
// 6. CATÁLOGOS Y PACIENTES
// ===================================================================
$clinicas_normalized = [];
$tipos_paciente = [];
$pacientes_lista = [];
$colonias_catalogo = [];

try {
    $stmtCli = $pdo->query("SELECT * FROM clinicas ORDER BY nombre_clinica ASC");
    foreach($stmtCli->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $r = array_change_key_case($row, CASE_LOWER);
        $tipo = $r['tipo_clinica'] ?? $r['tipo'] ?? $r['servicio'] ?? 'Consulta Externa';
        $nombre = $r['nombre_clinica'] ?? $r['nombre'] ?? $r['clinica'] ?? 'Núcleo Básico';

        if(!empty($nombre)) {
            $clinicas_normalized[] = [
                'tipo_clinica' => trim($tipo),
                'nombre_clinica' => trim($nombre)
            ];
        }
    }

    try {
        $stmtTp = $pdo->query("SELECT nombre_tipo FROM tipo_paciente ORDER BY nombre_tipo ASC");
        $tipos_paciente = $stmtTp->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $eTp) {
        $tipos_paciente = [];
    }

    $colonias_catalogo = $pdo->query("SELECT * FROM colonias ORDER BY nombre_colonia ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $id_sesion_pac = $_SESSION['id_paciente_seleccionado'] ?? null;
    if ($id_sesion_pac) {
        $stmt_ses = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id LIMIT 1");
        $stmt_ses->execute(['id' => $id_sesion_pac]);
        $pac_ses = $stmt_ses->fetchAll(PDO::FETCH_ASSOC);

        $stmt_resto = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente != :id ORDER BY id_paciente DESC LIMIT 14");
        $stmt_resto->execute(['id' => $id_sesion_pac]);
        $pac_resto = $stmt_resto->fetchAll(PDO::FETCH_ASSOC);

        $pacientes_lista = array_merge($pac_ses, $pac_resto);
    } elseif (!empty($filtro_reciente)) {
        $stmt_ini = $pdo->prepare("SELECT * FROM pacientes WHERE nombre ILIKE :q OR apaterno ILIKE :q OR curp ILIKE :q ORDER BY id_paciente DESC LIMIT 15");
        $stmt_ini->execute(['q' => "%$filtro_reciente%"]);
        $pacientes_lista = $stmt_ini->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $pacientes_lista = $pdo->query("SELECT * FROM pacientes ORDER BY id_paciente DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

if(empty($clinicas_normalized)) {
    $clinicas_normalized = [
        ['tipo_clinica' => 'CONSULTA EXTERNA', 'nombre_clinica' => 'Nucleo Basico 1']
    ];
}

if(empty($tipos_paciente)) {
    $tipos_paciente = ['General', 'Crónico', 'Embarazadas', 'Pediátricos', 'Adulto Mayor'];
}

$tipos_clinica_unicos = [];
foreach($clinicas_normalized as $c) {
    if(!in_array($c['tipo_clinica'], $tipos_clinica_unicos)) {
        $tipos_clinica_unicos[] = $c['tipo_clinica'];
    }
}
sort($tipos_clinica_unicos, SORT_STRING | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Asignación de Citas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-verde-hover: #004d34; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde-color: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: var(--texto-oscuro); }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: 700; font-size: 16px; }
        .container { max-width: 1450px; margin: 25px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 24px; margin: 0; color: var(--imss-verde); }
        .header-buttons { display: flex; gap: 12px; align-items: center; }
        
        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .btn-pdf { background-color: #9f2241; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); cursor: pointer; border: none; }
        .btn-pdf:hover { background-color: #7d1b33; }

        .layout-grid { display: grid; grid-template-columns: 340px 1fr 340px; gap: 20px; margin-bottom: 20px; }
        @media (max-width: 1200px) {
            .layout-grid { grid-template-columns: 1fr; }
        }
        .card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); margin-bottom: 20px; }
        .card-title { font-size: 18px; font-weight: 700; margin-top: 0; margin-bottom: 15px; color: var(--texto-oscuro); display: flex; align-items: center; gap: 10px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 9px 12px; font-size: 13px; border: 2px solid var(--borde-color); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; color: var(--texto-oscuro); }
        .form-control:focus { border-color: var(--imss-verde); }

        .flatpickr-calendar.inline {
            width: 100% !important;
            box-shadow: none !important;
            border: 2px solid var(--borde-color) !important;
            border-radius: 6px !important;
            background: #fff !important;
            margin: 0 auto;
        }
        .flatpickr-months { background: var(--imss-verde) !important; border-top-left-radius: 4px; border-top-right-radius: 4px; padding: 4px; color: white !important; }
        .flatpickr-month { color: white !important; fill: white !important; }
        .flatpickr-current-month .flatpickr-monthDropdown-months, .flatpickr-current-month input.cur-year { color: white !important; font-weight: bold; }
        span.flatpickr-weekday { color: var(--texto-oscuro) !important; font-weight: bold; }
        .flatpickr-day.selected { background: var(--imss-verde) !important; border-color: var(--imss-verde) !important; }
        .flatpickr-day.flatpickr-disabled, .flatpickr-day.flatpickr-disabled:hover { background: #fdf3f5 !important; color: #d32f2f !important; text-decoration: line-through; opacity: 0.6; cursor: not-allowed !important; }
        .flatpickr-day:hover { background: #dbe7e1 !important; }

        .slots-message { padding: 20px; text-align: center; font-weight: bold; font-size: 14px; border-radius: 6px; }
        .slots-message.cerrado { background: #fdf3f5; color: var(--imss-dorado); border: 1px solid #f5c2c7; }
        .slots-message.cargando { background: #eef2f5; color: #637381; }

        .slots-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(95px, 1fr)); gap: 8px; margin-top: 15px; }
        .slot-wrapper { display: flex; border: 2px solid #cfd8dc; border-radius: 6px; overflow: hidden; background: #eef2f5; transition: all 0.2s; }
        .slot-wrapper:hover:not(.is-disabled) { border-color: var(--imss-verde); }
        .slot-wrapper.selected { border-color: var(--imss-verde-hover); background: var(--imss-verde); color: white; }
        .slot-wrapper.is-disabled { background: #f0f0f0; border-color: #e0e0e0; opacity: 0.7; }
        .slot-wrapper.is-blocked { background: #fdf3f5; border-color: #f5c2c7; opacity: 1; }

        .slot-btn-text { flex: 1; padding: 8px 4px; text-align: center; font-weight: 700; font-size: 13px; cursor: pointer; user-select: none; }
        .slot-wrapper.is-disabled .slot-btn-text { cursor: not-allowed; text-decoration: line-through; color: #a0a0a0; }
        .slot-wrapper.is-blocked .slot-btn-text { cursor: not-allowed; text-decoration: line-through; color: var(--imss-dorado); }

        .slot-lock-btn { border: none; background: rgba(0,0,0,0.05); padding: 0 6px; cursor: pointer; color: #637381; display: flex; align-items: center; justify-content: center; font-size: 11px; transition: background 0.2s; }
        .slot-lock-btn:hover { background: rgba(0,0,0,0.15); color: #000; }
        .slot-wrapper.is-blocked .slot-lock-btn { color: var(--imss-dorado); background: #f8d7da; }

        .search-bar { display: flex; gap: 10px; margin-bottom: 15px; }
        .table-responsive { overflow-x: auto; max-height: 300px; overflow-y: auto; border: 1px solid var(--borde-color); border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 10px; font-weight: 700; border-bottom: 2px solid var(--borde-color); position: sticky; top: 0; z-index: 10; cursor: pointer; user-select: none; }
        table th:hover { background-color: #e2e8f0; }
        table th i { margin-left: 5px; color: #637381; font-size: 11px; }
        table td { padding: 10px; border-bottom: 1px solid var(--borde-color); }
        table tr:hover { background-color: #f8f9fa; }

        .citas-list-container { max-height: 480px; overflow-y: auto; border: 1px solid var(--borde-color); border-radius: 6px; background: #fff; }
        .cita-item-row { padding: 10px 12px; border-bottom: 1px solid #f1f3f5; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
        .cita-item-row:hover { background: #f8f9fa; }
        .cita-badge-hora { background: var(--imss-verde); color: #fff; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }

        .btn-confirm { background-color: var(--imss-verde); color: white; border: none; padding: 14px 20px; font-size: 16px; font-weight: 700; border-radius: 8px; cursor: pointer; width: 100%; margin-top: 15px; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .btn-confirm:hover { background-color: var(--imss-verde-hover); }
        
        .btn-new-patient { background-color: var(--imss-dorado); color: white; border: none; padding: 11px 18px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-new-patient:hover { background-color: #7d1b33; }

        .btn-new-cita { background-color: #0284c7; color: white; border: none; padding: 11px 18px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-new-cita:hover { background-color: #0369a1; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 850px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde-color); padding-bottom: 12px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: var(--imss-verde); font-size: 20px; }
        .close-modal { background: none; border: none; font-size: 22px; cursor: pointer; color: #637381; }

        @media print {
            body * { visibility: hidden; }
            #printableArea, #printableArea * { visibility: visible; }
            #printableArea { position: absolute; left: 0; top: 0; width: 100%; }
        }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-hospital"></i> <strong>IMSS-BIENESTAR</strong> | Asignación de Citas</div>
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Menú Principal</a>
    </div>

    <div class="container">
        <div class="page-header">
            <h1><i class="fa-solid fa-calendar-plus"></i> Asignar Cita Médica</h1>
            <div class="header-buttons">
                <button type="button" class="btn-new-cita" onclick="limpiarFormularioNuevaCita()">
                    <i class="fa-solid fa-plus-circle"></i> Agregar Nueva Cita
                </button>
                <button type="button" class="btn-new-patient" onclick="abrirModalPaciente()">
                    <i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente
                </button>
            </div>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success">
                <div><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
                <?php if ($id_cita_creada): ?>
                    <button type="button" class="btn-pdf" onclick="window.open('procesar_cita.php?id=<?php echo $id_cita_creada; ?>&id_cita=<?php echo $id_cita_creada; ?>', '_blank');">
                        <i class="fa-solid fa-print"></i> Imprimir Comprobante de Cita
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="agendar.php" method="POST" id="formAgendar" onsubmit="return validarFormulario()">
            <input type="hidden" name="accion" value="agendar_cita">
            <input type="hidden" name="hora_cita" id="inputHoraCita" value=""> 

            <div class="layout-grid">
                
                <!-- COLUMNA 1: FILTROS DEL SERVICIO Y CALENDARIO INLINE -->
                <div class="card" style="margin-bottom:0;">
                    <h2 class="card-title"><i class="fa-solid fa-sliders"></i> 1. Filtros del Servicio</h2>
                    
                    <div class="form-group">
                        <label>Tipo de Clínica:</label>
                        <select name="tipo_clinica" id="selTipoClinica" class="form-control" onchange="filtrarClinicas(); cargarCitasDiaFiltradas();" required>
                            <option value="">Seleccione el tipo...</option>
                            <?php foreach($tipos_clinica_unicos as $tc): ?>
                                <option value="<?php echo htmlspecialchars($tc); ?>" <?php echo (strcasecmp($post_tipo_clinica, $tc) === 0 || (empty($post_tipo_clinica) && strtoupper($tc) === 'CONSULTA EXTERNA')) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tc); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Núcleo Básico / Clínica:</label>
                        <select name="nombre_clinica" id="selClinica" class="form-control" onchange="actualizarCalendarioYSlots();" required>
                            <option value="">Seleccione primero el tipo...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Ubicación / Colonia de la Clínica:</label>
                        <select name="colonia" id="selColonia" class="form-control" required>
                            <option value="">Esperando clínica...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Programa de Atención (Tipo Paciente):</label>
                        <select name="tipo_paciente" id="selTipoPaciente" class="form-control" required>
                            <?php foreach($tipos_paciente as $tp): ?>
                                <option value="<?php echo htmlspecialchars($tp); ?>" <?php echo (strcasecmp($post_tipo_paciente, $tp) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fa-solid fa-calendar-days"></i> Fecha de Consulta (Calendario Activo):</label>
                        <input type="hidden" name="fecha_cita" id="inputFecha" value="<?php echo !empty($post_fecha_cita) ? htmlspecialchars($post_fecha_cita) : date('Y-m-d'); ?>">
                        <div id="calendarioInlineContainer"></div>
                    </div>
                </div>

                <!-- COLUMNA 2: HORARIOS Y PACIENTES -->
                <div>
                    <!-- HORARIOS DINÁMICOS -->
                    <div class="card" style="margin-bottom: 20px;">
                        <h2 class="card-title"><i class="fa-solid fa-clock"></i> 2. Horarios Disponibles</h2>
                        <div id="contenedorSlots">
                            <div class="slots-message cargando"><i class="fa-solid fa-spinner fa-spin"></i> Seleccione una clínica para ver horarios.</div>
                        </div>
                    </div>

                    <!-- BÚSQUEDA DE PACIENTES -->
                    <div class="card" style="margin-bottom: 0;">
                        <h2 class="card-title"><i class="fa-solid fa-user-search"></i> 3. Buscar y Seleccionar Paciente</h2>
                        
                        <div class="search-bar">
                            <input type="text" id="inputBusqueda" class="form-control" value="<?php echo htmlspecialchars($filtro_reciente); ?>" placeholder="BUSCAR POR NOMBRE, EXPEDIENTE O CURP..." style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();" onkeypress="if(event.key === 'Enter'){ buscarPacienteAjax(); return false; }">
                            <button type="button" class="btn-confirm" style="margin:0; width: auto; padding: 10px 20px;" onclick="buscarPacienteAjax()">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                            <button type="button" class="btn-confirm" style="margin:0; width: auto; padding: 10px 15px; background: #637381;" onclick="document.getElementById('inputBusqueda').value=''; buscarPacienteAjax();" title="Limpiar">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table id="tablaPacientes">
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Expediente</th>
                                        <th>Paciente</th>
                                        <th>CURP</th>
                                        <th>Programa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($pacientes_lista) > 0): ?>
                                        <?php foreach($pacientes_lista as $p): 
                                            $esSeleccionado = (($id_sesion_pac ?? null) == $p['id_paciente']);
                                        ?>
                                            <tr onclick="seleccionarFilaRadio(this)" <?php echo $esSeleccionado ? 'style="background-color: #e4f8dd;"' : ''; ?>>
                                                <td>
                                                    <input type="radio" name="paciente_id" value="<?php echo $p['id_paciente']; ?>" 
                                                        <?php echo $esSeleccionado ? 'checked' : ''; ?> 
                                                        onchange="verificarCamposPaciente(this)" required style="cursor:pointer;">
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($p['expediente'] ?? 'S/N'); ?></strong></td>
                                                <td><?php echo htmlspecialchars(trim(($p['nombre'] ?? '') . ' ' . ($p['apaterno'] ?? '') . ' ' . ($p['amaterno'] ?? ''))); ?></td>
                                                <td><?php echo htmlspecialchars($p['curp'] ?? ''); ?></td>
                                                <td><span style="background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-size:12px;"><?php echo htmlspecialchars($p['tipo_paciente'] ?? 'General'); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; color: #637381; padding: 20px;">No se encontraron pacientes. Registre uno nuevo arriba.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" class="btn-confirm">
                            <i class="fa-solid fa-check-circle"></i> Confirmar y Agendar Cita
                        </button>
                    </div>
                </div>

                <!-- COLUMNA 3: RELACIÓN DE CITAS DISPONIBLES / OCUPADAS -->
                <div class="card" style="margin-bottom:0;">
                    <h2 class="card-title" style="font-size: 16px;"><i class="fa-solid fa-list-check"></i> 4. Relación de Citas</h2>
                    <div style="font-size: 12px; color: #637381; margin-bottom: 12px;">
                        Listado de turnos y pacientes asignados para la fecha y clínica seleccionada:
                    </div>
                    <div id="contenedorRelacionCitas" class="citas-list-container">
                        <div class="slots-message cargando" style="font-size: 12px; padding: 15px;">Seleccione clínica y fecha.</div>
                    </div>
                </div>

            </div>
        </form>

        <!-- SECCIÓN INFERIOR: GRID DE CITAS AGENDADAS FILTRADAS POR TIPO DE CLÍNICA -->
        <div class="card" id="printableArea">
            <h2 class="card-title"><i class="fa-solid fa-calendar-check"></i> Citas Agendadas del Día (<span id="spanFechaGrid"><?php echo htmlspecialchars($post_fecha_cita); ?></span>)</h2>
            <div style="font-size: 13px; color: #637381; margin-bottom: 15px;">
                Listado filtrado en base al <strong>Tipo de Clínica</strong> seleccionado.
            </div>
            <div class="table-responsive" style="max-height: 380px;">
                <table id="tablaCitasDia">
                    <thead>
                        <tr>
                            <th onclick="ordenarTabla(0)">Hora <i class="fa-solid fa-sort"></i></th>
                            <th onclick="ordenarTabla(1)">No. Expediente <i class="fa-solid fa-sort"></i></th>
                            <th onclick="ordenarTabla(2)">Paciente <i class="fa-solid fa-sort"></i></th>
                            <th onclick="ordenarTabla(3)">Tipo de Clínica <i class="fa-solid fa-sort"></i></th>
                            <th onclick="ordenarTabla(4)">Clínica / Núcleo <i class="fa-solid fa-sort"></i></th>
                            <th onclick="ordenarTabla(5)">Programa <i class="fa-solid fa-sort"></i></th>
                            <th onclick="ordenarTabla(6)">Estatus <i class="fa-solid fa-sort"></i></th>
                            <th style="text-align: center;">Acción Impresión</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyCitasDia">
                        <tr>
                            <td colspan="8" style="text-align: center; color: #637381; padding: 20px;">Seleccione el Tipo de Clínica para ver el listado...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- MODAL REGISTRO DE PACIENTE -->
    <div class="modal-overlay" id="modalPaciente">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente</h3>
                <button type="button" class="close-modal" onclick="cerrarModalPaciente()">&times;</button>
            </div>
            <form action="agendar.php" method="POST" id="formRegistrarPaciente">
                <input type="hidden" name="accion" value="registrar_paciente">
                <!-- CAMPOS OCULTOS PARA PRESERVAR FILTROS -->
                <input type="hidden" name="tipo_clinica" id="hidden_tipo_clinica">
                <input type="hidden" name="nombre_clinica" id="hidden_nombre_clinica">
                <input type="hidden" name="colonia" id="hidden_colonia">
                <input type="hidden" name="tipo_paciente" id="hidden_tipo_paciente">
                <input type="hidden" name="fecha_cita" id="hidden_fecha_cita">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>No. Expediente <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="expediente" id="inputExpediente" class="form-control" required style="font-weight: bold; background: #fffde7; text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>CURP <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="curp" id="inputCurp" class="form-control" maxlength="18" required oninput="procesarCurp(this); this.value = this.value.toUpperCase();" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Teléfono (10 dígitos) <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="tel" name="telefono" class="form-control" required maxlength="10" pattern="[0-9]{10}" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);" placeholder="Ej. 9371000000">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Nombre <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="nombre" class="form-control" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Apellido Paterno <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="apaterno" class="form-control" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Apellido Materno</label>
                        <input type="text" name="amaterno" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Edad (Auto)</label>
                        <input type="number" name="edad" id="inputEdad" class="form-control" readonly style="background:#f1f3f5;">
                    </div>
                    <div class="form-group">
                        <label>Edad Actual</label>
                        <input type="number" name="edad_actual" id="inputEdadActual" class="form-control" style="background:#f1f3f5;">
                    </div>
                    <div class="form-group">
                        <label>Sexo (Auto)</label>
                        <input type="text" name="sexo" id="inputSexo" class="form-control" readonly style="background:#f1f3f5; text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Estado (Auto)</label>
                        <input type="text" name="estado" id="inputEstado" class="form-control" readonly style="background:#f1f3f5; text-transform: uppercase;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Fecha de Nacimiento</label>
                        <input type="date" name="fnacimiento" id="inputFnacimiento" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Año de Nacimiento</label>
                        <input type="text" name="year_nacimiento" id="inputYearNacimiento" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px;">
                    <div class="form-group">
                        <label>Municipio</label>
                        <input type="text" name="municipio" class="form-control" style="text-transform: uppercase;" placeholder="EJ. CENTRO" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Domicilio</label>
                        <input type="text" name="domicilio" class="form-control" style="text-transform: uppercase;" placeholder="Calle, Número..." oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Colonia / Ubicación</label>
                        <input type="text" name="colonia_paciente" class="form-control" style="text-transform: uppercase;" placeholder="EJ. CENTRO" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr 2fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Nombre del Padre</label>
                        <input type="text" name="nombre_padre" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Edad Padre</label>
                        <input type="text" name="edad_padre" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Nombre de la Madre</label>
                        <input type="text" name="nombre_madre" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Edad Madre</label>
                        <input type="text" name="edad_madre" class="form-control">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Fecha Apertura</label>
                        <input type="date" name="fecha_apertura" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Estatus</label>
                        <select name="estatus" class="form-control">
                            <option value="ACTIVO">ACTIVO</option>
                            <option value="INACTIVO">INACTIVO</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Programa (Tipo Paciente)</label>
                        <select name="tipo_paciente" class="form-control">
                            <?php foreach($tipos_paciente as $tp): ?>
                                <option value="<?php echo htmlspecialchars($tp); ?>"><?php echo htmlspecialchars($tp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-confirm" style="background:#637381; width:auto; display:inline-block; margin-right:10px;" onclick="cerrarModalPaciente()">Cancelar</button>
                    <button type="submit" class="btn-confirm" style="width:auto; display:inline-block; margin-top:0;" onclick="inyectarEstadoFormulario()">Guardar Paciente</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR / COMPLETAR DATOS DEL PACIENTE -->
    <div class="modal-overlay" id="modalEditarPaciente">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-pen"></i> Completar o Actualizar Datos del Paciente</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditarPaciente()">&times;</button>
            </div>
            <form action="agendar.php" method="POST" id="formActualizarPaciente">
                <input type="hidden" name="accion" value="actualizar_paciente_faltante">
                <input type="hidden" name="id_paciente" id="edit_id_paciente">
                <input type="hidden" name="tipo_clinica" id="edit_hidden_tipo_clinica">
                <input type="hidden" name="nombre_clinica" id="edit_hidden_nombre_clinica">
                <input type="hidden" name="colonia" id="edit_hidden_colonia">
                <input type="hidden" name="tipo_paciente" id="edit_hidden_tipo_paciente">
                <input type="hidden" name="fecha_cita" id="edit_hidden_fecha_cita">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>No. Expediente</label>
                        <input type="text" name="expediente" id="edit_expediente" class="form-control" style="background:#f1f3f5;" readonly>
                    </div>
                    <div class="form-group">
                        <label>CURP <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="curp" id="edit_curp" class="form-control" maxlength="18" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Nombre</label>
                        <input type="text" name="nombre" id="edit_nombre" class="form-control" style="background:#f1f3f5;" readonly>
                    </div>
                    <div class="form-group">
                        <label>Apellido Paterno</label>
                        <input type="text" name="apaterno" id="edit_apaterno" class="form-control" style="background:#f1f3f5;" readonly>
                    </div>
                    <div class="form-group">
                        <label>Apellido Materno</label>
                        <input type="text" name="amaterno" id="edit_amaterno" class="form-control" style="background:#f1f3f5;" readonly>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px;">
                    <div class="form-group">
                        <label>Municipio <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="municipio" id="edit_municipio" class="form-control" required style="text-transform: uppercase;" placeholder="EJ. HUIMANGUILLO" oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Domicilio <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" name="domicilio" id="edit_domicilio" class="form-control" required style="text-transform: uppercase;" placeholder="CALLE Y NÚMERO" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div class="form-group">
                    <label>Colonia / Ubicación <span style="color:var(--imss-dorado);">*</span></label>
                    <input type="text" name="colonia_paciente" id="edit_colonia" class="form-control" required style="text-transform: uppercase;" placeholder="EJ. CENTRO" oninput="this.value = this.value.toUpperCase();">
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-confirm" style="background:#637381; width:auto; display:inline-block; margin-right:10px;" onclick="cerrarModalEditarPaciente()">Cancelar</button>
                    <button type="submit" class="btn-confirm" style="width:auto; display:inline-block; margin-top:0;" onclick="inyectarEstadoActualizacion()">Actualizar y Continuar</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>

    <script>
        const dataClinicas = <?php echo json_encode($clinicas_normalized); ?>;
        const dataColonias = <?php echo json_encode($colonias_catalogo); ?>;
        let fpCalendario = null;
        
        const phpPostClinica = "<?php echo htmlspecialchars($post_nombre_clinica); ?>";
        const phpPostColonia = "<?php echo htmlspecialchars($post_colonia); ?>";
        const phpPostTipoPaciente = "<?php echo htmlspecialchars($post_tipo_paciente); ?>";

        let cachePacientes = {};

        function almacenarPacientesCache(lista) {
            cachePacientes = {};
            if (Array.isArray(lista)) {
                lista.forEach(p => {
                    cachePacientes[p.id_paciente] = p;
                });
            }
        }

        function inyectarEstadoFormulario() {
            document.getElementById('hidden_tipo_clinica').value = document.getElementById('selTipoClinica').value;
            document.getElementById('hidden_nombre_clinica').value = document.getElementById('selClinica').value;
            document.getElementById('hidden_colonia').value = document.getElementById('selColonia').value;
            document.getElementById('hidden_tipo_paciente').value = document.getElementById('selTipoPaciente').value;
            document.getElementById('hidden_fecha_cita').value = document.getElementById('inputFecha').value;
        }

        function inyectarEstadoActualizacion() {
            document.getElementById('edit_hidden_tipo_clinica').value = document.getElementById('selTipoClinica').value;
            document.getElementById('edit_hidden_nombre_clinica').value = document.getElementById('selClinica').value;
            document.getElementById('edit_hidden_colonia').value = document.getElementById('selColonia').value;
            document.getElementById('edit_hidden_tipo_paciente').value = document.getElementById('selTipoPaciente').value;
            document.getElementById('edit_hidden_fecha_cita').value = document.getElementById('inputFecha').value;
        }

        function verificarCamposPaciente(radio) {
            const idPaciente = radio.value;
            const paciente = cachePacientes[idPaciente] || (window.pacientesUltimaBusqueda ? window.pacientesUltimaBusqueda.find(p => p.id_paciente == idPaciente) : null);

            if (paciente) {
                let domicilio = (paciente.domicilio || '').trim();
                let municipio = (paciente.municipio || '').trim();
                let colonia = (paciente.colonia || '').trim();

                if (domicilio === '' || municipio === '' || colonia === '') {
                    document.getElementById('edit_id_paciente').value = paciente.id_paciente;
                    document.getElementById('edit_expediente').value = paciente.expediente || 'S/N';
                    document.getElementById('edit_curp').value = paciente.curp || '';
                    document.getElementById('edit_nombre').value = paciente.nombre || '';
                    document.getElementById('edit_apaterno').value = paciente.apaterno || '';
                    document.getElementById('edit_amaterno').value = paciente.amaterno || '';
                    document.getElementById('edit_municipio').value = municipio;
                    document.getElementById('edit_domicilio').value = domicilio;
                    document.getElementById('edit_colonia').value = colonia;

                    document.getElementById('modalEditarPaciente').style.display = 'flex';
                }
            }
        }

        function cerrarModalEditarPaciente() {
            document.getElementById('modalEditarPaciente').style.display = 'none';
        }

        function normStr(str) {
            return (str || '').toString().trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        }

        window.onload = function() {
            if (phpPostTipoPaciente) {
                const selTp = document.getElementById('selTipoPaciente');
                for (let i = 0; i < selTp.options.length; i++) {
                    if (selTp.options[i].value === phpPostTipoPaciente) {
                        selTp.selectedIndex = i;
                        break;
                    }
                }
            }

            filtrarClinicas(phpPostClinica, phpPostColonia);
            const pacientesIniciales = <?php echo json_encode($pacientes_lista); ?>;
            almacenarPacientesCache(pacientesIniciales);
            window.pacientesUltimaBusqueda = pacientesIniciales;

            cargarCitasDiaFiltradas();

            const horaGuardada = sessionStorage.getItem('hora_cita_seleccionada');
            if (horaGuardada) {
                document.getElementById('inputHoraCita').value = horaGuardada;
            }

            <?php if(!empty($filtro_reciente)): ?>
            buscarPacienteAjax();
            <?php endif; ?>
        };

        function filtrarClinicas(preselectClinica = '', preselectColonia = '') {
            const selTipoElem = document.getElementById('selTipoClinica');
            let selTipo = selTipoElem.value;
            const selClinica = document.getElementById('selClinica');
            const selColonia = document.getElementById('selColonia');
            
            if (!selTipo && selTipoElem.options.length > 1) {
                selTipoElem.selectedIndex = 1;
                selTipo = selTipoElem.value;
            }

            selClinica.innerHTML = '<option value="">Seleccione la clínica...</option>';
            selColonia.innerHTML = '<option value="">Seleccione primero la clínica...</option>';
            
            if(!selTipo) return;

            const filtradas = dataClinicas
                .filter(c => normStr(c.tipo_clinica) === normStr(selTipo))
                .sort((a, b) => a.nombre_clinica.localeCompare(b.nombre_clinica, 'es', {sensitivity: 'base'}));

            let encontrada = false;
            filtradas.forEach(c => {
                let opt = document.createElement('option');
                opt.value = c.nombre_clinica; 
                opt.text = c.nombre_clinica;
                if(preselectClinica && normStr(c.nombre_clinica) === normStr(preselectClinica)) {
                    opt.selected = true;
                    encontrada = true;
                }
                selClinica.add(opt);
            });
            
            if(!encontrada && selClinica.options.length > 1 && !preselectClinica) {
                selClinica.selectedIndex = 1;
            } else if (preselectClinica) {
                selClinica.value = preselectClinica;
            }
            
            filtrarColonias(preselectColonia);
            actualizarCalendarioYSlots();
        }

        function filtrarColonias(preselectColonia = '') {
            const valClinica = document.getElementById('selClinica').value;
            const selColonia = document.getElementById('selColonia');
            
            selColonia.innerHTML = '<option value="">Seleccione la colonia...</option>';
            
            if(!valClinica) return;

            const coloniasFiltradas = dataColonias
                .filter(c => normStr(c.nombre_clinica) === normStr(valClinica))
                .sort((a, b) => a.nombre_colonia.localeCompare(b.nombre_colonia, 'es', {sensitivity: 'base'}));
            
            if(coloniasFiltradas.length > 0) {
                coloniasFiltradas.forEach(col => {
                    let opt = document.createElement('option');
                    opt.value = col.nombre_colonia; 
                    opt.text = col.nombre_colonia;
                    if(preselectColonia && normStr(col.nombre_colonia) === normStr(preselectColonia)) {
                        opt.selected = true;
                    }
                    selColonia.add(opt);
                });
                if(preselectColonia) {
                    selColonia.value = preselectColonia;
                } else if(selColonia.selectedIndex <= 0) {
                    selColonia.selectedIndex = 1;
                }
            } else {
                let opt = document.createElement('option');
                opt.value = 'S/N'; 
                opt.text = 'Sin colonias asociadas';
                selColonia.add(opt);
            }
        }

        function actualizarCalendarioYSlots() {
            filtrarColonias();
            const clinica = document.getElementById('selClinica').value;
            if (!clinica) {
                inicializarCalendarioInline({no_atiende_fin: false, dia_inactivo: '', festivos: [], excepciones: []});
                return;
            }

            fetch(`agendar.php?ajax=meta_clinica&clinica=${encodeURIComponent(clinica)}`)
                .then(res => res.json())
                .then(meta => {
                    meta.excepciones = meta.excepciones || [];
                    meta.festivos = meta.festivos || [];
                    inicializarCalendarioInline(meta);
                    cargarSlots();
                    cargarCitasDiaFiltradas();
                })
                .catch(err => {
                    inicializarCalendarioInline({no_atiende_fin: false, dia_inactivo: '', festivos: [], excepciones: []});
                    cargarSlots();
                    cargarCitasDiaFiltradas();
                });
        }

        function inicializarCalendarioInline(meta) {
            const container = document.getElementById('calendarioInlineContainer');
            const fechaInput = document.getElementById('inputFecha');

            if (fpCalendario) {
                fpCalendario.destroy();
            }

            let listaDeshabilitados = [];

            if (meta.excepciones && meta.excepciones.length > 0) {
                listaDeshabilitados.push(...meta.excepciones);
            }
            if (meta.festivos && meta.festivos.length > 0) {
                listaDeshabilitados.push(...meta.festivos);
            }

            listaDeshabilitados.push(function(date) {
                const day = date.getDay();
                
                if (meta.no_atiende_fin && (day === 0 || day === 6)) {
                    return true; 
                }

                if (meta.dia_inactivo) {
                    const diasMapSemana = {0: 'Domingo', 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado'};
                    let nombreDia = diasMapSemana[day];
                    if (nombreDia && normStr(nombreDia) === normStr(meta.dia_inactivo)) {
                        return true;
                    }
                }

                return false;
            });

            fpCalendario = flatpickr(container, {
                locale: 'es',
                inline: true,
                defaultDate: fechaInput.value || 'today',
                dateFormat: 'Y-m-d',
                disable: listaDeshabilitados,
                onChange: function(selectedDates, dateStr, instance) {
                    fechaInput.value = dateStr;
                    document.getElementById('spanFechaGrid').innerText = dateStr;
                    cargarSlots();
                    cargarCitasDiaFiltradas();
                }
            });
        }

        function cargarCitasDiaFiltradas() {
            const fecha = document.getElementById('inputFecha').value;
            const tipoClinica = document.getElementById('selTipoClinica').value;
            const tbody = document.getElementById('tbodyCitasDia');

            if (!tipoClinica) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #637381; padding: 20px;">Seleccione un Tipo de Clínica para ver el listado.</td></tr>';
                return;
            }

            tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;"><i class="fa-solid fa-spinner fa-spin"></i> Filtrando citas...</td></tr>';

            fetch(`agendar.php?ajax=citas_dia&fecha=${fecha}&tipo_clinica=${encodeURIComponent(tipoClinica)}`)
                .then(res => res.json())
                .then(data => {
                    let html = '';
                    if (data.status === 'ok' && data.citas && data.citas.length > 0) {
                        data.citas.forEach(c => {
                            let horaFmt = (c.hora_cita || '').substring(0, 5);
                            let exp = c.no_expediente || 'S/N';
                            let paciente = c.nombre_paciente || 'PACIENTE';
                            let tCli = c.tipo_clinica || 'N/D';
                            let nCli = c.nombre_clinica || 'N/D';
                            let tipoP = c.tipo_paciente || 'General';
                            let estatus = c.estatus || 'PROGRAMADA';

                            html += `<tr>
                                <td><span class="cita-badge-hora">${horaFmt}</span></td>
                                <td><strong>${exp}</strong></td>
                                <td>${paciente}</td>
                                <td>${tCli}</td>
                                <td>${nCli}</td>
                                <td><span style="background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-size:12px;">${tipoP}</span></td>
                                <td><span style="background: #e4f8dd; color: var(--imss-verde); padding: 2px 8px; border-radius: 4px; font-weight: bold; font-size: 11px;">${estatus}</span></td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn-pdf" onclick="window.open('procesar_cita.php?id=${c.id_cita}&id_cita=${c.id_cita}', '_blank');" style="padding: 6px 12px; font-size: 12px;">
                                        <i class="fa-solid fa-print"></i> Imprimir
                                    </button>
                                </td>
                            </tr>`;
                        });
                        tbody.innerHTML = html;
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #637381; padding: 20px;">No hay citas agendadas para este tipo de clínica en la fecha seleccionada.</td></tr>';
                    }
                })
                .catch(err => {
                    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--imss-dorado); padding: 20px;">Error al cargar el listado de citas.</td></tr>';
                });
        }

        let ordenAsc = true;
        function ordenarTabla(columnaIndex) {
            const tabla = document.getElementById("tablaCitasDia");
            const tbody = tabla.querySelector("tbody");
            const filas = Array.from(tbody.querySelectorAll("tr"));

            if (filas.length <= 1 && filas[0].querySelector("td").getAttribute("colspan")) return;

            filas.sort((a, b) => {
                let celdaA = a.querySelectorAll("td")[columnaIndex].innerText.trim();
                let celdaB = b.querySelectorAll("td")[columnaIndex].innerText.trim();

                let valA = isNaN(celdaA) ? celdaA.toLowerCase() : parseFloat(celdaA);
                let valB = isNaN(celdaB) ? celdaB.toLowerCase() : parseFloat(celdaB);

                if (valA < valB) return ordenAsc ? -1 : 1;
                if (valA > valB) return ordenAsc ? 1 : -1;
                return 0;
            });

            ordenAsc = !ordenAsc;
            tbody.innerHTML = "";
            filas.forEach(f => tbody.appendChild(f));
        }

        function cargarSlots() {
            const fecha = document.getElementById('inputFecha').value;
            const clinica = document.getElementById('selClinica').value;
            const contenedor = document.getElementById('contenedorSlots');
            const contenedorRelacion = document.getElementById('contenedorRelacionCitas');

            if(!fecha || !clinica) {
                contenedor.innerHTML = '<div class="slots-message cargando">Seleccione clínica y fecha.</div>';
                contenedorRelacion.innerHTML = '<div class="slots-message cargando" style="font-size: 12px; padding: 15px;">Seleccione clínica y fecha.</div>';
                return;
            }

            contenedor.innerHTML = '<div class="slots-message cargando"><i class="fa-solid fa-spinner fa-spin"></i> Calculando turnos...</div>';
            contenedorRelacion.innerHTML = '<div class="slots-message cargando" style="font-size: 12px; padding: 15px;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando relación...</div>';

            fetch(`agendar.php?ajax=slots&fecha=${fecha}&clinica=${encodeURIComponent(clinica)}`)
                .then(response => response.json())
                .then(data => {
                    if(data.status === 'cerrado') {
                        contenedor.innerHTML = `<div class="slots-message cerrado"><i class="fa-solid fa-calendar-xmark"></i> ${data.mensaje}</div>`;
                        contenedorRelacion.innerHTML = `<div class="slots-message cerrado" style="font-size: 12px; padding: 15px;">Clínica cerrada por permiso o excepción.</div>`;
                    } else if (data.status === 'abierto') {
                        let html = `<div style="font-size:12px; color:#637381; margin-bottom:8px; font-weight:bold;"><i class="fa-solid fa-stopwatch"></i> Duración: ${data.duracion} min</div>`;
                        html += '<div class="slots-grid">';
                        let disponiblesCount = 0;
                        let relacionHtml = '';

                        const totalSlots = data.slots.length;
                        const horaActiva = document.getElementById('inputHoraCita').value;

                        data.slots.forEach((slot, index) => {
                            let isDisp = (slot.disponible === true || slot.disponible === "true" || slot.disponible === 1);
                            let isPermiso = slot.permiso === true;
                            let esUltimosCinco = (index >= totalSlots - 5);
                            let esSeleccionado = (slot.hora === horaActiva);
                            
                            if (isDisp) {
                                html += `<div class="slot-wrapper ${esSeleccionado ? 'selected' : ''}">
                                    <div class="slot-btn-text" onclick="seleccionarHora(this, '${slot.hora}')">${slot.hora}</div>`;
                                
                                if (esUltimosCinco) {
                                    html += `<button type="button" class="slot-lock-btn" onclick="toggleBloqueoSlot('${slot.hora}')" title="Bloquear turno ${slot.hora}">
                                        <i class="fa-solid fa-lock-open"></i>
                                    </button>`;
                                }

                                html += `</div>`;
                                disponiblesCount++;
                                relacionHtml += `<div class="cita-item-row">
                                    <div><span class="cita-badge-hora">${slot.hora}</span> <span style="color: #2e7d32; font-weight: 600; margin-left: 5px;">Disponible</span></div>
                                    <span style="color: #637381; font-style: italic; font-size: 12px;">Libre</span>
                                </div>`;
                            } else if (isPermiso) {
                                html += `<div class="slot-wrapper is-blocked">
                                    <div class="slot-btn-text" title="Permiso / Excepción: ${slot.paciente}">${slot.hora}</div>`;
                                
                                if (esUltimosCinco) {
                                    html += `<button type="button" class="slot-lock-btn" onclick="toggleBloqueoSlot('${slot.hora}')" title="Desbloquear turno ${slot.hora}">
                                        <i class="fa-solid fa-lock"></i>
                                    </button>`;
                                }

                                html += `</div>`;
                                relacionHtml += `<div class="cita-item-row" style="background: #fdf3f5;">
                                    <div><span class="cita-badge-hora" style="background: var(--imss-dorado);">${slot.hora}</span> <strong style="color: var(--texto-oscuro); margin-left: 5px;">${slot.paciente || 'PERMISO'}</strong></div>
                                    <span style="background: #f8d7da; color: #721c24; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold;">Permiso</span>
                                </div>`;
                            } else {
                                html += `<div class="slot-wrapper is-disabled">
                                    <div class="slot-btn-text" title="Ocupado por ${slot.paciente || 'Paciente'}">${slot.hora}</div>
                                </div>`;
                                relacionHtml += `<div class="cita-item-row" style="background: #fdf3f5;">
                                    <div><span class="cita-badge-hora" style="background: #637381;">${slot.hora}</span> <strong style="color: var(--texto-oscuro); margin-left: 5px;">${slot.paciente || 'PACIENTE'}</strong></div>
                                    <span style="background: #f8d7da; color: #721c24; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold;">Ocupado</span>
                                </div>`;
                            }
                        });
                        html += '</div>';
                        
                        if(disponiblesCount === 0) {
                            contenedor.innerHTML = `${html}<div class="slots-message cerrado" style="margin-top:10px;"><i class="fa-solid fa-calendar-xmark"></i> Sin horarios disponibles.</div>`;
                        } else {
                            contenedor.innerHTML = html;
                        }

                        contenedorRelacion.innerHTML = relacionHtml;

                    } else {
                        contenedor.innerHTML = `<div class="slots-message cerrado">${data.mensaje || 'Error al calcular horarios.'}</div>`;
                        contenedorRelacion.innerHTML = `<div class="slots-message cerrado" style="font-size: 12px; padding: 15px;">Error al cargar.</div>`;
                    }
                })
                .catch(err => {
                    contenedor.innerHTML = `<div class="slots-message cerrado">Error de conexión.</div>`;
                    contenedorRelacion.innerHTML = `<div class="slots-message cerrado" style="font-size: 12px; padding: 15px;">Error de conexión.</div>`;
                });
        }

        function toggleBloqueoSlot(hora) {
            const fecha = document.getElementById('inputFecha').value;
            const clinica = document.getElementById('selClinica').value;
            if(!fecha || !clinica) return;

            fetch(`agendar.php?ajax=toggle_bloqueo_slot&fecha=${fecha}&clinica=${encodeURIComponent(clinica)}&hora=${hora}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'ok') {
                        cargarSlots();
                        cargarCitasDiaFiltradas();
                    } else {
                        alert(data.mensaje || 'No se pudo cambiar el estado del turno.');
                    }
                })
                .catch(err => alert('Error de conexión al guardar el bloqueo.'));
        }

        function seleccionarHora(el, hora) {
            const wrapper = el.closest('.slot-wrapper');
            if(wrapper.classList.contains('is-disabled') || wrapper.classList.contains('is-blocked')) return;
            
            document.querySelectorAll('.slot-wrapper').forEach(w => w.classList.remove('selected'));
            wrapper.classList.add('selected');
            document.getElementById('inputHoraCita').value = hora;
            sessionStorage.setItem('hora_cita_seleccionada', hora);
        }

        function buscarPacienteAjax() {
            const q = document.getElementById('inputBusqueda').value.trim();
            const tbody = document.querySelector('#tablaPacientes tbody');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;"><i class="fa-solid fa-spinner fa-spin"></i> Buscando...</td></tr>';

            fetch(`agendar.php?ajax=pacientes&q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => {
                    window.pacientesUltimaBusqueda = data;
                    almacenarPacientesCache(data);
                    let html = '';
                    if(data.length > 0) {
                        data.forEach((p) => {
                            let exp = p.expediente || 'S/N';
                            let nombreComp = `${p.nombre || ''} ${p.apaterno || ''} ${p.amaterno || ''}`.trim();
                            let curp = p.curp || '';
                            let tp = p.tipo_paciente || 'General';
                            
                            html += `<tr onclick="seleccionarFilaRadio(this)">
                                <td><input type="radio" name="paciente_id" value="${p.id_paciente}" onchange="verificarCamposPaciente(this)" required style="cursor:pointer;"></td>
                                <td><strong>${exp}</strong></td>
                                <td>${nombreComp}</td>
                                <td>${curp}</td>
                                <td><span style="background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-size:12px;">${tp}</span></td>
                            </tr>`;
                        });
                        tbody.innerHTML = html;
                    } else {
                        html = '<tr><td colspan="5" style="text-align: center; color: #637381; padding: 20px;">No se encontraron pacientes. Abriendo registro automático...</td></tr>';
                        tbody.innerHTML = html;

                        if (q.length === 18) {
                            setTimeout(() => {
                                abrirModalPaciente();
                                const inputCurpModal = document.getElementById('inputCurp');
                                inputCurpModal.value = q;
                                procesarCurp(inputCurpModal);
                            }, 300);
                        }
                    }
                })
                .catch(err => {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--imss-dorado);">Error al buscar en el servidor.</td></tr>';
                });
        }

        function seleccionarFilaRadio(tr) {
            const radio = tr.querySelector('input[type="radio"]');
            if(radio) {
                radio.checked = true;
                verificarCamposPaciente(radio);
            }
        }

        function limpiarFormularioNuevaCita() {
            document.getElementById('inputHoraCita').value = '';
            sessionStorage.removeItem('hora_cita_seleccionada');
            document.querySelectorAll('.slot-wrapper').forEach(w => w.classList.remove('selected'));
            document.getElementById('inputBusqueda').value = '';
            buscarPacienteAjax();
            window.scrollTo({top: 0, behavior: 'smooth'});
        }

        function validarFormulario() {
            let hora = document.getElementById('inputHoraCita').value;
            let clinica = document.getElementById('selClinica').value;
            let pacienteSeleccionado = document.querySelector('input[name="paciente_id"]:checked');

            if(clinica === '') {
                alert("DEBE SELECCIONAR UNA CLÍNICA."); return false;
            }
            if(hora === '') {
                alert("DEBE SELECCIONAR UN HORARIO DISPONIBLE EN EL PANEL DE SLOTS."); return false;
            }
            if(!pacienteSeleccionado) {
                alert("DEBE SELECCIONAR UN PACIENTE DE LA TABLA."); return false;
            }
            sessionStorage.removeItem('hora_cita_seleccionada');
            return true;
        }

        function abrirModalPaciente() { 
            inyectarEstadoFormulario();
            const inputExp = document.getElementById('inputExpediente');
            if(!inputExp.value) {
                inputExp.value = 'SE-' + Math.floor(100000 + Math.random() * 900000);
            }
            document.getElementById('modalPaciente').style.display = 'flex'; 
        }

        function cerrarModalPaciente() { document.getElementById('modalPaciente').style.display = 'none'; }

        function procesarCurp(input) {
            let curp = input.value.trim().toUpperCase();
            if (curp.length >= 11) {
                let yy = parseInt(curp.substring(4, 6), 10);
                let mm = parseInt(curp.substring(6, 8), 10) - 1;
                let dd = parseInt(curp.substring(8, 10), 10);
                
                let currentYear = new Date().getFullYear() % 100;
                let century = yy > currentYear ? 1900 : 2000;
                let birthDate = new Date(century + yy, mm, dd);
                let fechaNacStr = birthDate.toISOString().split('T')[0];
                
                document.getElementById('inputFnacimiento').value = fechaNacStr;
                document.getElementById('inputYearNacimiento').value = (century + yy);

                let today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                let m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) { age--; }
                if (!isNaN(age) && age >= 0) {
                    document.getElementById('inputEdad').value = age;
                    document.getElementById('inputEdadActual').value = age;
                }

                let sexoChar = curp.charAt(10);
                if (sexoChar === 'H') document.getElementById('inputSexo').value = 'MASCULINO';
                else if (sexoChar === 'M') document.getElementById('inputSexo').value = 'FEMENINO';

                let estadoCode = curp.substring(11, 13);
                const estadosMap = {'AS':'Aguascalientes', 'BC':'Baja California', 'BS':'Baja California Sur', 'CC':'Campeche', 'CL':'Coahuila', 'CM':'Colima', 'CS':'Chiapas', 'CH':'Chihuahua', 'DF':'Ciudad de México', 'DG':'Durango', 'GT':'Guanajuato', 'GR':'Guerrero', 'HG':'Hidalgo', 'JC':'Jalisco', 'MC':'México', 'MN':'Michoacán', 'MS':'Morelos', 'NT':'Nayarit', 'NL':'Nuevo León', 'OC':'Oaxaca', 'PL':'Puebla', 'QT':'Querétaro', 'QR':'Quintana Roo', 'SP':'San Luis Potosí', 'SL':'Sinaloa', 'SR':'Sonora', 'TC':'Tabasco', 'TS':'Tamaulipas', 'TL':'Tlaxcala', 'VZ':'Veracruz', 'YN':'Yucatán', 'ZS':'Zacatecas', 'NE':'Extranjero'};
                if (estadosMap[estadoCode]) document.getElementById('inputEstado').value = estadosMap[estadoCode];
            }
        }
    </script>
</body>
</html>