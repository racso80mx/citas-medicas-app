<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}
require_once 'conexion.php';

$id_paciente = intval($_GET['id_paciente'] ?? 0);
$id_censo = intval($_GET['id_censo'] ?? 0);
$area_activa = trim($_GET['area'] ?? 'HOSPITALIZACIÓN');

// 1. Obtener lista de personal de enfermería para validación y firma
$personal_enfermeria = [];
try {
    $stmt_per = $pdo->query("SELECT id_personal, nombre, apellido, cedula_profesional FROM personal WHERE UPPER(TRIM(puesto)) LIKE '%ENFERM%' OR UPPER(TRIM(rol)) LIKE '%ENFERM%' ORDER BY nombre ASC");
    $personal_enfermeria = $stmt_per->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt_per = $pdo->query("SELECT id_personal, nombre, apellido, cedula_profesional FROM personal ORDER BY nombre ASC");
        $personal_enfermeria = $stmt_per->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {}
}

// 2. Búsqueda flexible del paciente activo
$paciente = [];
try {
    if ($id_censo > 0) {
        $stmt = $pdo->prepare("
            SELECT c.*, p.escolaridad, p.estado_civil, p.ocupacion, p.grupo_sanguineo, p.rh, p.alergias, p.alergias_especificar,
                   COALESCE(p.expediente, c.curp, 'S/N') as expediente_real, p.telefono as telefono_real
            FROM censo c
            LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente
            WHERE c.id_censo = :id_c LIMIT 1
        ");
        $stmt->execute(['id_c' => $id_censo]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$paciente && $id_paciente > 0) {
        $stmt = $pdo->prepare("
            SELECT c.*, p.escolaridad, p.estado_civil, p.ocupacion, p.grupo_sanguineo, p.rh, p.alergias, p.alergias_especificar,
                   COALESCE(p.expediente, c.curp, 'S/N') as expediente_real, p.telefono as telefono_real
            FROM censo c
            LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente
            WHERE c.id_paciente = :id_p AND (c.estatus_censo IS NULL OR UPPER(TRIM(c.estatus_censo)) != 'DADO DE ALTA')
            ORDER BY c.id_censo DESC LIMIT 1
        ");
        $stmt->execute(['id_p' => $id_paciente]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

if (!$paciente && $id_paciente > 0) {
    try {
        $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id_p LIMIT 1");
        $stmtP->execute(['id_p' => $id_paciente]);
        $p_base = $stmtP->fetch(PDO::FETCH_ASSOC);
        if ($p_base) {
            $paciente = [
                'id_censo' => 0, 'id_paciente' => $p_base['id_paciente'],
                'nombre_paciente' => trim(($p_base['nombre'] ?? '') . ' ' . ($p_base['apaterno'] ?? '') . ' ' . ($p_base['amaterno'] ?? '')),
                'curp' => $p_base['curp'] ?? '', 'fecha_nacimiento' => $p_base['fnacimiento'] ?? '',
                'edad' => $p_base['edad_actual'] ?? $p_base['edad'] ?? 0, 'sexo' => $p_base['sexo'] ?? '',
                'cama' => 'S/C', 'fecha_ingreso' => date('Y-m-d'), 'hora_ingreso' => date('H:i'),
                'dias_estancia' => 1, 'diagnostico_ingreso' => 'PENDIENTE DE DIAGNÓSTICO',
                'expediente_real' => $p_base['expediente'] ?? 'S/N', 'escolaridad' => $p_base['escolaridad'] ?? '',
                'estado_civil' => $p_base['estado_civil'] ?? '', 'ocupacion' => $p_base['ocupacion'] ?? '',
                'grupo_sanguineo' => $p_base['grupo_sanguineo'] ?? '', 'rh' => $p_base['rh'] ?? '',
                'alergias' => $p_base['alergias'] ?? 'NO', 'alergias_especificar' => $p_base['alergias_especificar'] ?? ''
            ];
        }
    } catch (Exception $e) {}
}

if (!$paciente) {
    echo "<div style='padding: 30px; font-family: Arial; color: #991b1b;'><h3>Error: No se encontró información activa para este paciente.</h3><a href='central_enfermeria.php'>Regresar</a></div>";
    exit();
}

// 3. Buscar si hay turno/registro previo activo
$registro_activo = null;
try {
    $stmt_act = $pdo->prepare("
        SELECT * FROM hoja_enfermeria_master 
        WHERE id_paciente = :id_p AND (estatus_hoja IS NULL OR estatus_hoja = 'ACTIVO')
        ORDER BY id_hoja DESC LIMIT 1
    ");
    $stmt_act->execute(['id_p' => $paciente['id_paciente']]);
    $registro_activo = $stmt_act->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$mensaje = '';
$error = '';

// 4. Procesar guardado maestro en hoja_enfermeria_master
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_master') {
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $dias_hosp = intval($_POST['dias_hospitalizacion'] ?? 1);
    $escolaridad = mb_strtoupper(trim($_POST['escolaridad'] ?? ''), 'UTF-8');
    $idioma = mb_strtoupper(trim($_POST['idioma'] ?? 'ESPAÑOL'), 'UTF-8');
    $estado_civil = mb_strtoupper(trim($_POST['estado_civil'] ?? ''), 'UTF-8');
    $ocupacion = mb_strtoupper(trim($_POST['ocupacion'] ?? ''), 'UTF-8');
    $grupo_sanguineo = mb_strtoupper(trim($_POST['grupo_sanguineo'] ?? ''), 'UTF-8');
    $rh = mb_strtoupper(trim($_POST['rh'] ?? ''), 'UTF-8');
    $alergias = mb_strtoupper(trim($_POST['alergias'] ?? 'NO'), 'UTF-8');
    $alergias_esp = mb_strtoupper(trim($_POST['alergias_especificar'] ?? ''), 'UTF-8');
    
    $id_enfermera = intval($_POST['id_enfermera'] ?? 0);
    $cedula_profesional = trim($_POST['cedula_profesional'] ?? '');
    $turno = trim($_POST['turno'] ?? 'MATUTINO');

    $nombre_enfermera = '';
    foreach ($personal_enfermeria as $p) {
        if ($p['id_personal'] == $id_enfermera) {
            $nombre_enfermera = trim(($p['nombre'] ?? '') . ' ' . ($p['apellido'] ?? ''));
            break;
        }
    }

    if (empty($id_enfermera)) {
        $error = "Debe seleccionar la enfermera(o) responsable para firmar y validar el registro.";
    } else {
        // Recopilación de JSONs de las 4 hojas
        $horas = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 1, 2, 3, 4, 5, 6, 7];
        $constantes = [];
        foreach ($horas as $h) {
            $constantes[$h] = [
                'fc' => $_POST['fc'][$h] ?? '', 'temp' => $_POST['temp'][$h] ?? '',
                'pa' => $_POST['pa'][$h] ?? '', 'pam' => $_POST['pam'][$h] ?? '',
                'fr' => $_POST['fr'][$h] ?? '', 'spo2' => $_POST['spo2'][$h] ?? '',
                'llenado' => $_POST['llenado'][$h] ?? '', 'pvc' => $_POST['pvc'][$h] ?? '',
                'glucemia' => $_POST['glucemia'][$h] ?? ''
            ];
        }

        $talla = $_POST['talla'] ?? '';
        $peso = $_POST['peso'] ?? '';
        $imc = $_POST['imc'] ?? '';
        $per_cef = $_POST['perimetro_cefalico'] ?? '';
        $per_abd = $_POST['perimetro_abdominal'] ?? '';
        $otras_med = mb_strtoupper($_POST['otras_mediciones'] ?? '', 'UTF-8');
        $glasgow = mb_strtoupper($_POST['escala_glasgow'] ?? '', 'UTF-8');
        $r_caidas = mb_strtoupper($_POST['riesgo_caidas'] ?? '', 'UTF-8');
        $r_lesion = mb_strtoupper($_POST['riesgo_lesion_presion'] ?? '', 'UTF-8');
        $e_dolor = mb_strtoupper($_POST['escala_dolor'] ?? '', 'UTF-8');
        $e_flebitis = mb_strtoupper($_POST['escala_flebitis'] ?? '', 'UTF-8');
        $e_infiltracion = mb_strtoupper($_POST['escala_infiltracion'] ?? '', 'UTF-8');
        $e_hads = mb_strtoupper($_POST['escala_hads'] ?? '', 'UTF-8');

        $sistemas = $_POST['sistema'] ?? [];
        $notas_obj = mb_strtoupper($_POST['notas_objetivos_subjetivos'] ?? '', 'UTF-8');
        $registros_dx = $_POST['dx_turno'] ?? [];
        
        $acciones_prev = [
            'caidas' => $_POST['caidas'] ?? [],
            'lesiones' => $_POST['lesiones'] ?? [],
            'heridas' => $_POST['heridas'] ?? [],
            'sonda' => [
                'activa' => $_POST['sonda_activa'] ?? 'NO',
                'calibre' => $_POST['sonda_calibre'] ?? '',
                'instalacion' => $_POST['sonda_fecha_instalacion'] ?? '',
                'cambio' => $_POST['sonda_fecha_cambio'] ?? '',
                'observaciones' => $_POST['sonda_observaciones'] ?? ''
            ]
        ];

        $accesos_vasculares = $_POST['accesos'] ?? [];
        $sonda_vesical = $_POST['sonda_v'] ?? [];
        $soporte_ventilatorio = $_POST['ventilacion'] ?? [];
        $terapia_intravenosa = $_POST['terapia_IV'] ?? [];
        $medicacion = $_POST['medicamentos'] ?? [];
        $monitorizacion_nutrimental = $_POST['nutricion'] ?? [];
        $ingresos_egresos = $_POST['ingresos_egresos'] ?? [];
        $examenes_evaluacion = $_POST['examenes_eval'] ?? [];
        $plan_alta = $_POST['plan_alta'] ?? [];

        try {
            $pdo->beginTransaction();

            // Cerrar automáticamente el turno anterior activo (Corte 08:00 A.M.)
            $stmtCierre = $pdo->prepare("
                UPDATE hoja_enfermeria_master 
                SET estatus_hoja = 'CERRADO', fecha_cierre = CURRENT_TIMESTAMP 
                WHERE id_paciente = :id_p AND (estatus_hoja IS NULL OR estatus_hoja = 'ACTIVO')
            ");
            $stmtCierre->execute(['id_p' => $paciente['id_paciente']]);

            // Insertar la nueva sábana unificada
            $stmtIns = $pdo->prepare("
                INSERT INTO hoja_enfermeria_master (
                    id_censo, id_paciente, fecha, dias_hospitalizacion, servicio, cama,
                    escolaridad, idioma, estado_civil, ocupacion, grupo_sanguineo, rh,
                    alergias, alergias_especificar, constantes_horarias, talla, peso, imc,
                    perimetro_cefalico, perimetro_abdominal, otras_mediciones, escala_glasgow,
                    riesgo_caidas, riesgo_lesion_presion, escala_dolor, escala_flebitis,
                    escala_infiltracion, escala_hads, valoracion_sistemas, notas_objetivos_subjetivos,
                    registros_diagnostico, acciones_preventivas, accesos_vasculares, sonda_vesical,
                    soporte_ventilatorio, terapia_intravenosa, medicacion, monitorizacion_nutrimental,
                    ingresos_egresos, examenes_evaluacion, plan_alta, turno, estatus_hoja,
                    id_enfermera, nombre_enfermera, cedula_profesional
                ) VALUES (
                    :id_c, :id_p, :f, :dh, :serv, :cama, :esc, :idiom, :ec, :oc, :gs, :rh,
                    :al, :al_esp, :ch::jsonb, :talla, :peso, :imc, :pc, :pa, :om, :eg,
                    :rc, :rl, :ed, :ef, :ei, :eh, :vs::jsonb, :notas, :rd::jsonb, :ap::jsonb,
                    :av::jsonb, :sv::jsonb, :sop::jsonb, :ti::jsonb, :med::jsonb, :mn::jsonb,
                    :ie::jsonb, :ee::jsonb, :pa_l::jsonb, :turno, 'ACTIVO', :id_enf, :nom_enf, :ced_enf
                )
            ");
            $stmtIns->execute([
                'id_c' => $paciente['id_censo'] ?? 0, 'id_p' => $paciente['id_paciente'],
                'f' => $fecha, 'dh' => $dias_hosp, 'serv' => $area_activa, 'cama' => $paciente['cama'] ?? '',
                'esc' => $escolaridad, 'idiom' => $idioma, 'ec' => $estado_civil, 'oc' => $ocupacion,
                'gs' => $grupo_sanguineo, 'rh' => $rh, 'al' => $alergias, 'al_esp' => $alergias_esp,
                'ch' => json_encode($constantes), 'talla' => $talla, 'peso' => $peso, 'imc' => $imc,
                'pc' => $per_cef, 'pa' => $per_abd, 'om' => $otras_med, 'eg' => $glasgow,
                'rc' => $r_caidas, 'rl' => $r_lesion, 'ed' => $e_dolor, 'ef' => $e_flebitis,
                'ei' => $e_infiltracion, 'eh' => $e_hads, 'vs' => json_encode($sistemas),
                'notas' => $notas_obj, 'rd' => json_encode($registros_dx), 'ap' => json_encode($acciones_prev),
                'av' => json_encode($accesos_vasculares), 'sv' => json_encode($sonda_vesical),
                'sop' => json_encode($soporte_ventilatorio), 'ti' => json_encode($terapia_intravenosa),
                'med' => json_encode($medicacion), 'mn' => json_encode($monitorizacion_nutrimental),
                'ie' => json_encode($ingresos_egresos), 'ee' => json_encode($examenes_evaluacion),
                'pa_l' => json_encode($plan_alta), 'turno' => $turno, 'id_enf' => $id_enfermera,
                'nom_enf' => $nombre_enfermera, 'ced_enf' => $cedula_profesional
            ]);

            $pdo->commit();
            $mensaje = "¡Turno anterior cerrado y nuevo registro de enfermería abierto correctamente!";
            
            // Refrescar estado activo
            $stmt_act->execute(['id_p' => $paciente['id_paciente']]);
            $registro_activo = $stmt_act->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al guardar el registro: " . $e->getMessage();
        }
    }
}

// Determinar turno sugerido basado en la hora del servidor
$hora_actual = intval(date('H'));
$turno_sugerido = 'MATUTINO';
if ($hora_actual >= 14 && $hora_actual < 21) {
    $turno_sugerido = 'VESPERTINO';
} elseif ($hora_actual >= 21 || $hora_actual < 8) {
    $turno_sugerido = 'NOCTURNO';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Expediente Clínico de Enfermería - Sábana Completa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --borde: #000; }
        body { margin: 0; font-family: Arial, sans-serif; background-color: #f4f6f8; color: #111; text-transform: uppercase; font-size: 11px; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; font-size: 13px; }
        
        .hoja-container { max-width: 1500px; margin: 20px auto; background: white; padding: 15px; border: 2px solid var(--borde); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        
        .tabs-nav { display: flex; gap: 5px; margin-bottom: 15px; border-bottom: 2px solid var(--imss-verde); padding-bottom: 5px; flex-wrap: wrap; }
        .tab-btn { background: #e2e8f0; border: none; padding: 10px 15px; font-weight: bold; cursor: pointer; font-size: 11px; border-radius: 4px 4px 0 0; color: #334155; }
        .tab-btn.active { background: var(--imss-verde); color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .header-table { width: 100%; border-collapse: collapse; border: 1px solid var(--borde); margin-bottom: 5px; }
        .header-table td { border: 1px solid var(--borde); padding: 4px 6px; vertical-align: middle; }
        .logo-box { text-align: center; font-weight: bold; font-size: 12px; color: var(--imss-verde); }
        .titulo-form { text-align: center; font-weight: bold; font-size: 13px; background: #e2e8f0; }

        .form-table { width: 100%; border-collapse: collapse; border: 1px solid var(--borde); margin-bottom: 8px; font-size: 10px; }
        .form-table th, .form-table td { border: 1px solid var(--borde); padding: 3px 4px; vertical-align: middle; }
        .form-table th { background: #f1f3f5; text-align: center; font-size: 10px; }
        .seccion-titulo { background: #d1d5db; font-weight: bold; text-align: center; padding: 4px; font-size: 11px; border: 1px solid var(--borde); }
        
        input[type="text"], input[type="date"], input[type="number"], select, textarea {
            width: 100%; border: none; background: transparent; font-size: 10px; font-family: Arial, sans-serif; text-transform: uppercase; outline: none; box-sizing: border-box;
        }
        input:focus, textarea:focus { background: #fef08a; }

        .btn-guardar { background-color: var(--imss-verde); color: white; border: none; padding: 12px 25px; font-weight: bold; font-size: 14px; cursor: pointer; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; }
        .btn-guardar:hover { background-color: #004d35; }
        .alert-success { background: #d1fae5; color: #065f46; padding: 10px; margin-bottom: 10px; font-weight: bold; border: 1px solid #34d399; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 10px; margin-bottom: 10px; font-weight: bold; border: 1px solid #f87171; }

        @media print {
            body { background: white; font-size: 9px; }
            .navbar, .btn-guardar, .no-print, .tabs-nav { display: none !important; }
            .tab-content { display: block !important; page-break-after: always; }
            .hoja-container { border: none; box-shadow: none; padding: 0; margin: 0; max-width: 100%; }
        }
    </style>
    <script>
        function cambiarPestaña(evt, tabId) {
            let contents = document.getElementsByClassName("tab-content");
            for (let c of contents) c.classList.remove("active");
            let buttons = document.getElementsByClassName("tab-btn");
            for (let b of buttons) b.classList.remove("active");
            document.getElementById(tabId).classList.add("active");
            evt.currentTarget.classList.add("active");
        }
        function actualizarCedula(select) {
            let option = select.options[select.selectedIndex];
            let cedula = option.getAttribute('data-cedula') || '';
            document.getElementById('input_cedula').value = cedula;
        }
    </script>
</head>
<body>

    <div class="navbar no-print">
        <div><i class="fa-solid fa-file-medical"></i> <strong>IMSS-BIENESTAR</strong> | Sábana de Enfermería - 4 Hojas (hoja_enfermeria_master)</div>
        <a href="central_enfermeria.php?area=<?php echo urlencode($area_activa); ?>"><i class="fa-solid fa-arrow-left"></i> Volver a Central de Enfermería</a>
    </div>

    <div class="hoja-container">
        <?php if (!empty($mensaje)): ?>
            <div class="alert-success no-print"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error no-print"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="enfermeria_hoja1.php?id_paciente=<?php echo $id_paciente; ?>&id_censo=<?php echo $id_censo; ?>&area=<?php echo urlencode($area_activa); ?>" method="POST">
            <input type="hidden" name="accion" value="guardar_master">

            <!-- BLOQUE DE VALIDACIÓN DE ENFERMERÍA Y CIERRE/APERTURA DE TURNO -->
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 12px; margin-bottom: 15px; border-radius: 6px;" class="no-print">
                <h4 style="margin: 0 0 10px 0; color: var(--imss-verde); font-size: 13px;"><i class="fa-solid fa-user-nurse"></i> VALIDACIÓN DE ENFERMERÍA Y CONTROL DE TURNO (CORTE 08:00 A.M.)</h4>
                
                <?php if ($registro_activo): ?>
                    <div style="background: #fef3c7; border: 1px solid #f59e0b; padding: 8px; margin-bottom: 10px; border-radius: 4px; font-size: 10px; color: #92400e;">
                        <i class="fa-solid fa-triangle-exclamation"></i> <strong>Turno activo previo:</strong> Turno <strong><?php echo $registro_activo['turno']; ?></strong> a cargo de <strong><?php echo htmlspecialchars($registro_activo['nombre_enfermera']); ?></strong>. Al guardar, este registro se cerrará y se abrirá el nuevo turno.
                    </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px;">
                    <div>
                        <label style="font-weight: bold; font-size: 10px;">ENFERMERA(O) RESPONSABLE:</label>
                        <select name="id_enfermera" id="select_enfermera" onchange="actualizarCedula(this)" required style="background: white; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; width: 100%;">
                            <option value="">-- SELECCIONE PERSONAL --</option>
                            <?php foreach ($personal_enfermeria as $per): 
                                $nombre_completo = trim(($per['nombre'] ?? '') . ' ' . ($per['apellido'] ?? ''));
                                $cedula = $per['cedula_profesional'] ?? 'S/N';
                            ?>
                                <option value="<?php echo $per['id_personal']; ?>" data-cedula="<?php echo htmlspecialchars($cedula); ?>">
                                    <?php echo htmlspecialchars($nombre_completo); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight: bold; font-size: 10px;">CÉDULA PROFESIONAL:</label>
                        <input type="text" name="cedula_profesional" id="input_cedula" readonly style="background: #e2e8f0; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; font-weight: bold;" placeholder="Se autocompletará...">
                    </div>
                    <div>
                        <label style="font-weight: bold; font-size: 10px;">TURNO DE REGISTRO:</label>
                        <select name="turno" style="background: white; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; font-weight: bold; width: 100%;">
                            <option value="MATUTINO" <?php echo ($turno_sugerido == 'MATUTINO') ? 'selected' : ''; ?>>MATUTINO (08:00 - 14:00)</option>
                            <option value="VESPERTINO" <?php echo ($turno_sugerido == 'VESPERTINO') ? 'selected' : ''; ?>>VESPERTINO (14:00 - 21:00)</option>
                            <option value="NOCTURNO" <?php echo ($turno_sugerido == 'NOCTURNO') ? 'selected' : ''; ?>>NOCTURNO (21:00 - 08:00)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Pestañas de las 4 Hojas -->
            <div class="tabs-nav no-print">
                <button type="button" class="tab-btn active" onclick="cambiarPestaña(event, 'hoja1')"><i class="fa-solid fa-heart-pulse"></i> Hoja 1: Signos y Valoración</button>
                <button type="button" class="tab-btn" onclick="cambiarPestaña(event, 'hoja2')"><i class="fa-solid fa-notes-medical"></i> Hoja 2: Diagnósticos y Prevención</button>
                <button type="button" class="tab-btn" onclick="cambiarPestaña(event, 'hoja3')"><i class="fa-solid fa-pills"></i> Hoja 3: Dispositivos y Medicación</button>
                <button type="button" class="tab-btn" onclick="cambiarPestaña(event, 'hoja4')"><i class="fa-solid fa-scale-balanced"></i> Hoja 4: Balances y Plan de Alta</button>
            </div>

            <!-- ENCABEZADO INSTITUCIONAL FIJO -->
            <table class="header-table">
                <tr>
                    <td class="logo-box" style="width: 25%;">
                        <strong>IMSS-BIENESTAR</strong><br><span style="font-size:8px;">SERVICIOS PÚBLICOS DE SALUD</span>
                    </td>
                    <td class="titulo-form" style="width: 50%;">
                        Formato de Notas y Registros Clínicos de Enfermería (Sábana de 4 Hojas)
                    </td>
                    <td style="width: 25%; font-size: 9px;">
                        IB/UAS/4/F/0007<br>HOSPITAL GENERAL HUIMANGUILLO
                    </td>
                </tr>
            </table>

            <!-- ================= HOJA 1 ================= -->
            <div id="hoja1" class="tab-content active">
                <table class="form-table">
                    <tr><td colspan="8" class="seccion-titulo">IDENTIFICACIÓN DEL PACIENTE</td></tr>
                    <tr>
                        <td style="width: 15%;"><strong>Fecha:</strong></td>
                        <td style="width: 20%;"><input type="date" name="fecha" value="<?php echo date('Y-m-d'); ?>" required></td>
                        <td style="width: 18%;"><strong>Días de hospitalización:</strong></td>
                        <td style="width: 12%;"><input type="number" name="dias_hospitalizacion" value="<?php echo $paciente['dias_estancia'] ?? 1; ?>"></td>
                        <td style="width: 10%;"><strong>Servicio:</strong></td>
                        <td style="width: 10%;"><input type="text" name="servicio" value="<?php echo htmlspecialchars($area_activa); ?>"></td>
                        <td style="width: 5%;"><strong>Cama:</strong></td>
                        <td style="width: 10%;"><input type="text" name="cama" value="<?php echo htmlspecialchars($paciente['cama'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <td><strong>Nombre del usuario:</strong></td>
                        <td colspan="3"><input type="text" value="<?php echo htmlspecialchars($paciente['nombre_paciente'] ?? ''); ?>" readonly style="font-weight: bold;"></td>
                        <td><strong>Fecha de nacimiento:</strong></td>
                        <td><input type="text" value="<?php echo htmlspecialchars($paciente['fecha_nacimiento'] ?? ''); ?>" readonly></td>
                        <td><strong>Edad:</strong></td>
                        <td><input type="text" value="<?php echo htmlspecialchars($paciente['edad'] ?? ''); ?>" readonly> AÑOS</td>
                    </tr>
                    <tr>
                        <td><strong>Sexo:</strong></td>
                        <td>H: [<?php echo (strcasecmp($paciente['sexo'] ?? '', 'M') === 0 || strcasecmp($paciente['sexo'] ?? '', 'MASCULINO') === 0) ? 'X' : ' '; ?>] M: [<?php echo (strcasecmp($paciente['sexo'] ?? '', 'F') === 0 || strcasecmp($paciente['sexo'] ?? '', 'FEMENINO') === 0) ? 'X' : ' '; ?>]</td>
                        <td><strong>Escolaridad:</strong></td><td><input type="text" name="escolaridad" value="<?php echo htmlspecialchars($paciente['escolaridad'] ?? ''); ?>"></td>
                        <td><strong>Idioma:</strong></td><td colspan="3"><input type="text" name="idioma" value="ESPAÑOL"></td>
                    </tr>
                    <tr>
                        <td><strong>Estado civil:</strong></td><td><input type="text" name="estado_civil" value="<?php echo htmlspecialchars($paciente['estado_civil'] ?? ''); ?>"></td>
                        <td><strong>Ocupación:</strong></td><td><input type="text" name="ocupacion" value="<?php echo htmlspecialchars($paciente['ocupacion'] ?? ''); ?>"></td>
                        <td><strong>Grupo Sanguíneo:</strong></td><td><input type="text" name="grupo_sanguineo" value="<?php echo htmlspecialchars($paciente['grupo_sanguineo'] ?? ''); ?>"></td>
                        <td><strong>Rh:</strong></td><td><input type="text" name="rh" value="<?php echo htmlspecialchars($paciente['rh'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <td><strong>Alergias:</strong></td>
                        <td colspan="7">No: [<input type="radio" name="alergias" value="NO" checked style="width:auto;">] Sí: [<input type="radio" name="alergias" value="SÍ" style="width:auto;">] <strong>Especificar:</strong> <input type="text" name="alergias_especificar" value="<?php echo htmlspecialchars($paciente['alergias_especificar'] ?? ''); ?>" style="width: 70%;"></td>
                    </tr>
                    <tr>
                        <td><strong>Expediente:</strong></td><td><input type="text" value="<?php echo htmlspecialchars($paciente['expediente_real'] ?? ''); ?>" readonly></td>
                        <td><strong>Fecha de ingreso:</strong></td><td><input type="text" value="<?php echo htmlspecialchars($paciente['fecha_ingreso'] ?? ''); ?>" readonly></td>
                        <td><strong>Diagnóstico médico:</strong></td><td colspan="3"><input type="text" value="<?php echo htmlspecialchars($paciente['diagnostico_ingreso'] ?? ''); ?>" readonly style="font-weight: bold; color: var(--imss-dorado);"></td>
                    </tr>
                </table>

                <table class="form-table">
                    <tr><td colspan="25" class="seccion-titulo">CONSTANTES VITALES Y HORARIOS (8:00 AM A 7:00 AM)</td></tr>
                    <tr style="background: #e2e8f0; font-size: 9px;"><th style="width: 170px;">Constante / Hora</th><th colspan="8">Matutino</th><th colspan="6">Vespertino</th><th colspan="10">Nocturno</th></tr>
                    <tr style="background: #f1f3f5; font-size: 9px;"><th>Horario</th>
                        <?php $horas_arr = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 1, 2, 3, 4, 5, 6, 7];
                        foreach ($horas_arr as $h) { echo "<th>{$h}</th>"; } ?>
                    </tr>
                    <tr><td><strong>Frecuencia cardiaca (x')</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="fc[<?php echo $h; ?>]" style="text-align:center;" maxlength="3"></td><?php endforeach; ?></tr>
                    <tr><td><strong>Temperatura (°C)</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="temp[<?php echo $h; ?>]" style="text-align:center;" maxlength="4"></td><?php endforeach; ?></tr>
                    <tr><td><strong>Presión arterial (mmHg)</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="pa[<?php echo $h; ?>]" style="text-align:center;" maxlength="7"></td><?php endforeach; ?></tr>
                    <tr><td><strong>Presión arterial media</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="pam[<?php echo $h; ?>]" style="text-align:center;" maxlength="5"></td><?php endforeach; ?></tr>
                    <tr><td><strong>Frecuencia respiratoria</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="fr[<?php echo $h; ?>]" style="text-align:center;" maxlength="3"></td><?php endforeach; ?></tr>
                    <tr><td><strong>Saturación O2 (SpO2%)</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="spo2[<?php echo $h; ?>]" style="text-align:center;" maxlength="3"></td><?php endforeach; ?></tr>
                    <tr><td><strong>Llenado capilar (seg)</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="llenado[<?php echo $h; ?>]" style="text-align:center;" maxlength="3"></td><?php endforeach; ?></tr>
                    <tr><td><strong>PVC (cm H2O)</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="pvc[<?php echo $h; ?>]" style="text-align:center;" maxlength="3"></td><?php endforeach; ?></tr>
                    <tr><td><strong>Glucemia capilar (mg/dl)</strong></td><?php foreach ($horas_arr as $h): ?><td><input type="text" name="glucemia[<?php echo $h; ?>]" style="text-align:center;" maxlength="4"></td><?php endforeach; ?></tr>
                </table>

                <table class="form-table">
                    <tr>
                        <td><strong>Talla:</strong> <input type="text" name="talla" style="width:60%;"></td>
                        <td><strong>Peso:</strong> <input type="text" name="peso" style="width:60%;"></td>
                        <td><strong>IMC:</strong> <input type="text" name="imc" style="width:60%;"></td>
                        <td><strong>Perim. Cefálico:</strong> <input type="text" name="perimetro_cefalico" style="width:50%;"></td>
                        <td><strong>Perim. Abdominal:</strong> <input type="text" name="perimetro_abdominal" style="width:50%;"></td>
                    </tr>
                    <tr>
                        <td><strong>Glasgow:</strong> <input type="text" name="escala_glasgow" style="width:60%;"></td>
                        <td><strong>Riesgo Caídas:</strong> <input type="text" name="riesgo_caidas" style="width:60%;"></td>
                        <td><strong>Riesgo Úlceras:</strong> <input type="text" name="riesgo_lesion_presion" style="width:50%;"></td>
                        <td colspan="2"><strong>Escala del Dolor:</strong> <input type="text" name="escala_dolor" style="width:70%;"></td>
                    </tr>
                </table>

                <table class="form-table">
                    <tr><td colspan="12" class="seccion-titulo">VALORACIÓN POR SISTEMAS Y APARATOS (TM, TV, TN)</td></tr>
                    <tr style="background:#f1f3f5; font-size:9px;"><th>Sistema / Aparato</th><th>TM</th><th>TV</th><th>TN</th><th>Sistema / Aparato</th><th>TM</th><th>TV</th><th>TN</th><th>Sistema / Aparato</th><th>TM</th><th>TV</th><th>TN</th></tr>
                    <?php 
                    $sistemas_lista = [
                        ['Sistema nervioso', 'Sistema endocrino', 'Sistema musculoesquelético'],
                        ['Sistema cardiovascular', 'Aparato digestivo', 'Sistema hematopoyético'],
                        ['Sistema respiratorio', 'Sistema urinario', 'Aspectos psicológicos'],
                        ['Aparato reproductor femenino', 'Aparato reproductor masculino', 'Sistema tegumentario']
                    ];
                    foreach ($sistemas_lista as $row) {
                        echo "<tr>";
                        foreach ($row as $sis) {
                            echo "<td><strong>{$sis}</strong></td><td><input type='checkbox' name='sistema[{$sis}][tm]' value='1' style='width:auto;'></td><td><input type='checkbox' name='sistema[{$sis}][tv]' value='1' style='width:auto;'></td><td><input type='checkbox' name='sistema[{$sis}][tn]' value='1' style='width:auto;'></td>";
                        }
                        echo "</tr>";
                    }
                    ?>
                </table>

                <table class="form-table">
                    <tr><td class="seccion-titulo">DATOS OBJETIVOS Y SUBJETIVOS (NOTAS DE ENFERMERÍA)</td></tr>
                    <tr><td><textarea name="notas_objetivos_subjetivos" rows="4" placeholder="REGISTRE AQUÍ LAS ANOTACIONES CLÍNICAS..."></textarea></td></tr>
                </table>
            </div>

            <!-- ================= HOJA 2 ================= -->
            <div id="hoja2" class="tab-content">
                <table class="form-table">
                    <thead>
                        <tr style="background: #e2e8f0;">
                            <th style="width: 8%;">Turno</th><th style="width: 5%;">Hora</th><th style="width: 25%;">Diagnósticos NANDA</th><th style="width: 15%;">Resultados NOC</th><th style="width: 12%;">Puntuación Diana</th><th style="width: 5%;">Hora</th><th style="width: 5%;">I/R</th><th style="width: 25%;">Intervenciones GPC (NIC)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $turnos = ['TURNO MATUTINO', 'TURNO VESPERTINO', 'TURNO NOCTURNO'];
                        $contador = 0;
                        foreach ($turnos as $turno_row): 
                            for ($i = 0; $i < 4; $i++): $contador++;
                        ?>
                            <tr>
                                <?php if ($i === 0): ?><td rowspan="4" style="text-align: center; font-weight: bold; background: #f8fafc; vertical-align: middle;"><?php echo $turno_row; ?></td><?php endif; ?>
                                <td><input type="text" name="dx_turno[<?php echo $contador; ?>][hora_dx]" maxlength="5" placeholder="--:--"></td>
                                <td><input type="text" name="dx_turno[<?php echo $contador; ?>][diagnostico]"></td>
                                <td><input type="text" name="dx_turno[<?php echo $contador; ?>][resultado]"></td>
                                <td><input type="text" name="dx_turno[<?php echo $contador; ?>][puntuacion]"></td>
                                <td><input type="text" name="dx_turno[<?php echo $contador; ?>][hora_ir]" maxlength="5" placeholder="--:--"></td>
                                <td><input type="text" name="dx_turno[<?php echo $contador; ?>][ir]" maxlength="3"></td>
                                <td><input type="text" name="dx_turno[<?php echo $contador; ?>][intervenciones]"></td>
                            </tr>
                        <?php endfor; endforeach; ?>
                    </tbody>
                </table>

                <div class="seccion-titulo" style="margin-top: 10px;">ACCIONES PREVENTIVAS</div>
                <table class="form-table">
                    <tr style="background: #f1f3f5;">
                        <th style="width: 25%;">Prevención de Caídas</th><th>TM</th><th>TV</th><th>TN</th>
                        <th style="width: 25%;">Lesiones por Presión</th><th>TM</th><th>TV</th><th>TN</th>
                        <th style="width: 26%;">Cuidado de Heridas</th><th>TM</th><th>TV</th><th>TN</th>
                    </tr>
                    <?php 
                    $caidas = ['Ayuda en deambulación', 'Barandillas laterales', 'Contención mecánica', 'Orientación riesgo caídas'];
                    $lesiones = ['Lubricación de piel', 'Movilización pasiva', 'Posición alineada', 'Cambios posturales 2h'];
                    $heridas = ['Profilaxis antimicrobiana', 'Glucemia perioperatoria', 'Inspección de herida', 'Curación técnica aséptica', 'Presencia de sangrado', 'Exudado de herida'];
                    $max_r = max(count($caidas), count($lesiones), count($heridas));
                    for ($r = 0; $r < $max_r; $r++):
                    ?>
                        <tr>
                            <td><?php echo $caidas[$r] ?? ''; ?></td>
                            <?php if (isset($caidas[$r])): ?><td><input type="checkbox" name="caidas[<?php echo $r; ?>][tm]" value="1" style="width:auto;"></td><td><input type="checkbox" name="caidas[<?php echo $r; ?>][tv]" value="1" style="width:auto;"></td><td><input type="checkbox" name="caidas[<?php echo $r; ?>][tn]" value="1" style="width:auto;"></td><?php else: ?><td colspan="3"></td><?php endif; ?>
                            
                            <td><?php echo $lesiones[$r] ?? ''; ?></td>
                            <?php if (isset($lesiones[$r])): ?><td><input type="checkbox" name="lesiones[<?php echo $r; ?>][tm]" value="1" style="width:auto;"></td><td><input type="checkbox" name="lesiones[<?php echo $r; ?>][tv]" value="1" style="width:auto;"></td><td><input type="checkbox" name="lesiones[<?php echo $r; ?>][tn]" value="1" style="width:auto;"></td><?php else: ?><td colspan="3"></td><?php endif; ?>

                            <td><?php echo $heridas[$r] ?? ''; ?></td>
                            <?php if (isset($heridas[$r])): ?><td><input type="checkbox" name="heridas[<?php echo $r; ?>][tm]" value="1" style="width:auto;"></td><td><input type="checkbox" name="heridas[<?php echo $r; ?>][tv]" value="1" style="width:auto;"></td><td><input type="checkbox" name="heridas[<?php echo $r; ?>][tn]" value="1" style="width:auto;"></td><?php else: ?><td colspan="3"></td><?php endif; ?>
                        </tr>
                    <?php endfor; ?>
                </table>
            </div>

            <!-- ================= HOJA 3 ================= -->
            <div id="hoja3" class="tab-content">
                <table class="form-table">
                    <tr><td colspan="6" class="seccion-titulo">CUIDADOS DE ACCESOS VASCULARES Y SONDA VESICAL</td></tr>
                    <tr style="background: #f1f3f5;">
                        <th style="width: 50%;">Cuidado de Accesos Vasculares</th>
                        <th style="width: 50%;">Cuidado de Sonda Vesical</th>
                    </tr>
                    <tr>
                        <td>
                            Día permanencia CVC: <input type="text" name="accesos[cvc_dias]" style="width:30%"><br>
                            Fecha instalación: <input type="date" name="accesos[cvc_fecha]" style="width:50%"><br>
                            Tipo catéter: <input type="text" name="accesos[tipo]" style="width:60%"><br>
                            Barrera máxima: NO [<input type="radio" name="accesos[barrera]" value="NO">] SÍ [<input type="radio" name="accesos[barrera]" value="SI">]
                        </td>
                        <td>
                            Día permanencia sonda: <input type="text" name="sonda_v[dias]" style="width:30%"><br>
                            Calibre (Fr): <input type="text" name="sonda_v[calibre]" style="width:30%"><br>
                            Fecha instalación: <input type="date" name="sonda_v[fecha_inst]" style="width:50%"><br>
                            Bolsa colectora por debajo de vejiga: NO [<input type="radio" name="sonda_v[bolsa]" value="NO">] SÍ [<input type="radio" name="sonda_v[bolsa]" value="SI">]
                        </td>
                    </tr>
                </table>

                <table class="form-table">
                    <tr><td colspan="4" class="seccion-titulo">MEDICACIÓN Y TERAPIA INTRAVENOSA</td></tr>
                    <tr style="background: #f1f3f5;">
                        <th>Medicamento y concentración</th><th>Dosis</th><th>Frecuencia</th><th>Vía / Horarios</th>
                    </tr>
                    <?php for($m=1; $m<=4; $m++): ?>
                    <tr>
                        <td><input type="text" name="medicamentos[<?php echo $m; ?>][nombre]" placeholder="Nombre del fármaco"></td>
                        <td><input type="text" name="medicamentos[<?php echo $m; ?>][dosis]" placeholder="Dosis"></td>
                        <td><input type="text" name="medicamentos[<?php echo $m; ?>][frecuencia]" placeholder="Frecuencia"></td>
                        <td><input type="text" name="medicamentos[<?php echo $m; ?>][via]" placeholder="Vía (IV, VO, SC)"></td>
                    </tr>
                    <?php endfor; ?>
                </table>
            </div>

            <!-- ================= HOJA 4 ================= -->
            <div id="hoja4" class="tab-content">
                <table class="form-table">
                    <tr><td colspan="2" class="seccion-titulo">INGRESOS Y EGRESOS (BALANCE DE LÍQUIDOS)</td></tr>
                    <tr style="background: #f1f3f5;">
                        <th>Ingresos (Soluciones, Medicamentos, Vía Oral)</th>
                        <th>Egresos (Uresis, Evacuaciones, Vómito, Drenajes)</th>
                    </tr>
                    <tr>
                        <td>
                            Vía oral (ml): <input type="text" name="ingresos_egresos[ing_vo]"><br>
                            Medicamentos (ml): <input type="text" name="ingresos_egresos[ing_med]"><br>
                            Solución base (ml): <input type="text" name="ingresos_egresos[ing_sol]"><br>
                            Hemocomponentes (ml): <input type="text" name="ingresos_egresos[ing_hemo]">
                        </td>
                        <td>
                            Uresis (ml): <input type="text" name="ingresos_egresos[eg_uresis]"><br>
                            Evacuaciones (ml): <input type="text" name="ingresos_egresos[eg_evac]"><br>
                            Sangrado (ml): <input type="text" name="ingresos_egresos[eg_sangrado]"><br>
                            Vómito / Drenajes (ml): <input type="text" name="ingresos_egresos[eg_vomito]">
                        </td>
                    </tr>
                </table>

                <table class="form-table">
                    <tr><td class="seccion-titulo">PLAN DE ALTA Y RECOMENDACIONES</td></tr>
                    <tr>
                        <td>
                            <textarea name="plan_alta[recomendaciones]" rows="4" placeholder="SIGNOS Y SÍNTOMAS DE ALERTA, ADMINISTRACIÓN DE MEDICAMENTOS, CONTROL DE CITAS..."></textarea>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- BOTÓN DE GUARDADO MAESTRO -->
            <div style="text-align: right; margin-top: 20px; padding-top: 15px; border-top: 2px solid var(--borde);" class="no-print">
                <button type="submit" class="btn-guardar"><i class="fa-solid fa-floppy-disk"></i> Guardar Sábana Completa de Enfermería (Cierre de Turno y Registro Único)</button>
            </div>
        </form>
    </div>
</body>
</html>