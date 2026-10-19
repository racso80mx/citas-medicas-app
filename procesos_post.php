<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexion.php';

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $accion = $_POST['accion'] ?? '';
    $area_actual_post = trim($_POST['area_actual'] ?? '');

    if ($accion === 'autoregistro_medico') {
        $nombre       = mb_strtoupper(trim($_POST['auto_nombre'] ?? ''), 'UTF-8');
        $apaterno     = mb_strtoupper(trim($_POST['auto_apaterno'] ?? ''), 'UTF-8');
        $amaterno     = mb_strtoupper(trim($_POST['auto_amaterno'] ?? ''), 'UTF-8');
        $cedula       = mb_strtoupper(trim($_POST['auto_cedula'] ?? ''), 'UTF-8');
        $especialidad = mb_strtoupper(trim($_POST['auto_especialidad'] ?? 'MEDICINA GENERAL'), 'UTF-8');
        $telefono     = trim($_POST['auto_telefono'] ?? '');
        $correo       = trim($_POST['auto_correo'] ?? '');
        $sin_cedula   = isset($_POST['auto_sin_cedula']) && $_POST['auto_sin_cedula'] === '1';

        if ($sin_cedula || empty($cedula)) { $cedula = "MEDICO PASANTE"; }

        if (!empty($nombre) && !empty($apaterno)) {
            try {
                $stmtInsMed = $pdo->prepare("
                    INSERT INTO medicos (nombre, apaterno, amaterno, cedula, especialidad, telefono, correo)
                    VALUES (:nom, :apat, :amat, :ced, :esp, :tel, :corr)
                ");
                $stmtInsMed->execute(['nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno, 'ced' => $cedula, 'esp' => $especialidad, 'tel' => $telefono, 'corr' => $correo]);
                $mensaje = "¡Médico registrado exitosamente!";
            } catch (Exception $e) {
                try {
                    $stmtInsMed2 = $pdo->prepare("
                        INSERT INTO medicos (nombre, apaterno, amaterno, cedula_profesional, especialidad, telefono, correo)
                        VALUES (:nom, :apat, :amat, :ced, :esp, :tel, :corr)
                    ");
                    $stmtInsMed2->execute(['nom' => $nombre, 'apat' => $apaterno, 'amat' => $amaterno, 'ced' => $cedula, 'esp' => $especialidad, 'tel' => $telefono, 'corr' => $correo]);
                    $mensaje = "¡Médico registrado exitosamente!";
                } catch (Exception $ex) {
                    $error = "Error al registrar el médico: " . $ex->getMessage();
                }
            }
        } else {
            $error = "Nombre y apellido paterno obligatorios.";
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
                        'id_p' => $id_paciente, 'nom' => mb_strtoupper($nombre_completo, 'UTF-8'),
                        'curp' => mb_strtoupper($curp, 'UTF-8'), 'fnac' => !empty($fnac) ? $fnac : null,
                        'edad' => $edad, 'sexo' => mb_strtoupper($sexo, 'UTF-8'),
                        'area' => $area_actual, 'cama' => $cama, 'f_ing' => $fecha_ingreso,
                        'h_ing' => $hora_ingreso, 'dias' => $dias_estancia, 'diag' => $diagnostico,
                        'dieta' => $dieta, 'anot' => $anotaciones, 'pend' => $pendientes, 'medico' => $medico_tratante
                    ]);
                    $mensaje = "Paciente ingresado correctamente.";
                }
            } catch (Exception $e) {
                $error = "Error al registrar el ingreso: " . $e->getMessage();
            }
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
                    'area' => $area_actual, 'cama' => $cama, 'medico' => $medico_tratante,
                    'dieta' => $dieta, 'diag' => $diagnostico, 'anot' => $anotaciones,
                    'pend' => $pendientes, 'id' => $id_censo
                ]);
                $mensaje = "Datos actualizados correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar.";
            }
        }
    }

    if ($accion === 'dar_alta') {
        $id_censo    = intval($_POST['id_censo'] ?? 0);
        $medico_alta = mb_strtoupper(trim($_POST['medico_alta'] ?? ''), 'UTF-8');
        $motivo_alta = mb_strtoupper(trim($_POST['motivo_alta'] ?? ''), 'UTF-8');

        if ($id_censo > 0 && !empty($medico_alta) && !empty($motivo_alta)) {
            try {
                $stmtAlt = $pdo->prepare("
                    UPDATE censo 
                    SET estatus_censo = 'DADO DE ALTA', fecha_egreso = :f_egreso, hora_egreso = :h_egreso, 
                        medico_alta = :med, motivo_alta = :mot 
                    WHERE id_censo = :id
                ");
                $stmtAlt->execute([
                    'f_egreso' => date('Y-m-d'), 'h_egreso' => date('H:i'),
                    'med' => $medico_alta, 'mot' => $motivo_alta, 'id' => $id_censo
                ]);
                $mensaje = "Alta médica procesada con éxito.";
            } catch (Exception $e) {
                $error = "Error al dar de alta: " . $e->getMessage();
            }
        }
    }

    if ($accion === 'reingresar_paciente') {
        $id_censo       = intval($_POST['id_censo'] ?? 0);
        $area_reingreso = trim($_POST['area_reingreso'] ?? '');
        $medico_reingreso= mb_strtoupper(trim($_POST['medico_reingreso'] ?? ''), 'UTF-8');
        $motivo_reingreso= mb_strtoupper(trim($_POST['motivo_reingreso'] ?? ''), 'UTF-8');

        if ($id_censo > 0 && !empty($area_reingreso) && !empty($medico_reingreso)) {
            try {
                $stmtRe = $pdo->prepare("
                    UPDATE censo 
                    SET estatus_censo = 'ACTIVO', area_actual = :area, medico_tratante_actual = :med, 
                        anotaciones = CASE WHEN anotaciones IS NULL OR anotaciones = '' THEN :mot ELSE anotaciones || ' | REINGRESO: ' || :mot END 
                    WHERE id_censo = :id
                ");
                $stmtRe->execute(['area' => $area_reingreso, 'med' => $medico_reingreso, 'mot' => $motivo_reingreso, 'id' => $id_censo]);
                $mensaje = "Paciente reingresado con éxito.";
            } catch (Exception $e) {
                $error = "Error al reingresar: " . $e->getMessage();
            }
        }
    }

    if (!empty($area_actual_post)) {
        header("Location: ingresos.php?area=" . urlencode($area_actual_post));
        exit();
    }
}