<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN - FORMATO RPH (1)
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_paciente = isset($_GET['id_paciente']) ? intval($_GET['id_paciente']) : 0;
if ($id_paciente <= 0) {
    header("Location: trabajo_social.php");
    exit();
}

$id_estudio = isset($_GET['id_estudio']) ? intval($_GET['id_estudio']) : 0;
$error_msg = '';
$exito_msg = '';

// Obtener datos del paciente
try {
    $stmt_paciente = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
    $stmt_paciente->execute(['id' => $id_paciente]);
    $paciente = $stmt_paciente->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        header("Location: trabajo_social.php");
        exit();
    }
} catch (Exception $e) {
    die("Error al consultar el paciente: " . $e->getMessage());
}

// Obtener listado de estudios previos de este paciente para el combo desplegable
try {
    $stmt_lista = $pdo->prepare("SELECT id_estudio, fecha_estudio FROM estudio_socioeconomico WHERE id_paciente = :id_paciente ORDER BY fecha_estudio DESC, id_estudio DESC");
    $stmt_lista->execute(['id_paciente' => $id_paciente]);
    $estudios_previos = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $estudios_previos = [];
}

// Obtener listado de médicos usando las columnas exactas de tu base de datos
$json_medicos = [];
try {
    $stmt_medicos = $pdo->query("
        SELECT 
            TRIM(COALESCE(nombre, '') || ' ' || COALESCE(apaterno, '') || ' ' || COALESCE(amaterno, '')) AS nombre_completo, 
            COALESCE(cedula_profesional, '') AS cedula 
        FROM medicos 
        ORDER BY nombre ASC
    ");
    while ($row = $stmt_medicos->fetch(PDO::FETCH_ASSOC)) {
        $nombre = trim(preg_replace('/\s+/', ' ', $row['nombre_completo']));
        $cedula = trim($row['cedula']);
        if (!empty($nombre)) {
            $json_medicos[] = [
                'nombre' => mb_strtoupper($nombre, 'UTF-8'), 
                'cedula' => mb_strtoupper($cedula, 'UTF-8')
            ];
        }
    }
} catch (Exception $e) {
    $json_medicos = [];
}

// Si no hay id_estudio en la URL pero existen estudios previos, tomamos el más reciente por defecto
if ($id_estudio <= 0 && !empty($estudios_previos)) {
    $id_estudio = $estudios_previos[0]['id_estudio'];
}

// Si se solicita un estudio específico, cargarlo
$estudio = null;
if ($id_estudio > 0) {
    try {
        $stmt_est = $pdo->prepare("SELECT * FROM estudio_socioeconomico WHERE id_estudio = :id_estudio AND id_paciente = :id_paciente");
        $stmt_est->execute(['id_estudio' => $id_estudio, 'id_paciente' => $id_paciente]);
        $estudio = $stmt_est->fetch(PDO::FETCH_ASSOC);
        if (!$estudio) {
            $id_estudio = 0;
        }
    } catch (Exception $e) {
        $id_estudio = 0;
    }
}

// Procesar el formulario de guardado del RPH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_rph') {
    try {
        $txt = function($campo) {
            return mb_strtoupper(trim($_POST[$campo] ?? ''), 'UTF-8');
        };

        $fecha_null = function($campo) {
            $val = trim($_POST[$campo] ?? '');
            return ($val === '') ? null : $val;
        };

        $id_estudio_post = isset($_POST['id_estudio']) ? intval($_POST['id_estudio']) : 0;
        if ($id_estudio_post <= 0) {
            $id_estudio_post = isset($_GET['id_estudio']) ? intval($_GET['id_estudio']) : 0;
        }

        if ($id_estudio_post > 0) {
            // ACTUALIZAR REGISTRO RPH EXISTENTE
            $stmt_upd = $pdo->prepare("
                UPDATE estudio_socioeconomico SET 
                    rph_fecha_ingreso = :rph_fecha_ingreso,
                    rph_hora_ingreso = :rph_hora_ingreso,
                    rph_tipo_ingreso = :rph_tipo_ingreso,
                    rph_referido_de = :rph_referido_de,
                    rph_contrarreferido_de = :rph_contrarreferido_de,
                    rph_medico_ingresa = :rph_medico_ingresa,
                    rph_diagnostico_ingreso = :rph_diagnostico_ingreso,
                    rph_caso_medico_legal = :rph_caso_medico_legal,
                    rph_fecha_egreso = :rph_fecha_egreso,
                    rph_hora_egreso = :rph_hora_egreso,
                    rph_alta_por = :rph_alta_por,
                    rph_referido_a = :rph_referido_a,
                    rph_medico_autoriza_egreso = :rph_medico_autoriza_egreso,
                    rph_diagnostico_egreso = :rph_diagnostico_egreso,
                    rph_nombre_familiar_recibe = :rph_nombre_familiar_recibe,
                    rph_parentesco_recibe = :rph_parentesco_recibe,
                    rph_donacion_sangre = :rph_donacion_sangre,
                    rph_ts_ingreso_nombre = :rph_ts_ingreso_nombre,
                    rph_ts_ingreso_cedula = :rph_ts_ingreso_cedula,
                    rph_ts_egreso_nombre = :rph_ts_egreso_nombre,
                    rph_ts_egreso_cedula = :rph_ts_egreso_cedula,
                    nombre_completo = :nombre_completo,
                    edad = :edad,
                    genero = :genero,
                    telefono = :telefono,
                    domicilio = :domicilio,
                    nombre_responsable = :nombre_responsable,
                    parentesco_responsable = :parentesco_responsable,
                    curp = :curp
                WHERE id_estudio = :id_estudio AND id_paciente = :id_paciente
            ");

            $stmt_upd->execute([
                'id_estudio' => $id_estudio_post,
                'id_paciente' => $id_paciente,
                'rph_fecha_ingreso' => $fecha_null('rph_fecha_ingreso'),
                'rph_hora_ingreso' => $fecha_null('rph_hora_ingreso'),
                'rph_tipo_ingreso' => $txt('rph_tipo_ingreso'),
                'rph_referido_de' => $txt('rph_referido_de'),
                'rph_contrarreferido_de' => $txt('rph_contrarreferido_de'),
                'rph_medico_ingresa' => $txt('rph_medico_ingresa'),
                'rph_diagnostico_ingreso' => $txt('rph_diagnostico_ingreso'),
                'rph_caso_medico_legal' => $txt('rph_caso_medico_legal'),
                'rph_fecha_egreso' => $fecha_null('rph_fecha_egreso'),
                'rph_hora_egreso' => $fecha_null('rph_hora_egreso'),
                'rph_alta_por' => $txt('rph_alta_por'),
                'rph_referido_a' => $txt('rph_referido_a'),
                'rph_medico_autoriza_egreso' => $txt('rph_medico_autoriza_egreso'),
                'rph_diagnostico_egreso' => $txt('rph_diagnostico_egreso'),
                'rph_nombre_familiar_recibe' => $txt('rph_nombre_familiar_recibe'),
                'rph_parentesco_recibe' => $txt('rph_parentesco_recibe'),
                'rph_donacion_sangre' => $txt('rph_donacion_sangre'),
                'rph_ts_ingreso_nombre' => $txt('rph_ts_ingreso_nombre'),
                'rph_ts_ingreso_cedula' => $txt('rph_ts_ingreso_cedula'),
                'rph_ts_egreso_nombre' => $txt('rph_ts_egreso_nombre'),
                'rph_ts_egreso_cedula' => $txt('rph_ts_egreso_cedula'),
                'nombre_completo' => $txt('nombre_completo'),
                'edad' => intval($_POST['edad'] ?? 0),
                'genero' => $txt('genero'),
                'telefono' => $_POST['telefono'] ?? '',
                'domicilio' => $txt('domicilio'),
                'nombre_responsable' => $txt('nombre_responsable'),
                'parentesco_responsable' => $txt('parentesco_responsable'),
                'curp' => $txt('curp')
            ]);

            $id_estudio = $id_estudio_post;
            $exito_msg = "Registro RPH actualizado correctamente.";
        } else {
            // INSERTAR NUEVO REGISTRO SI NO EXISTE
            $stmt_ins = $pdo->prepare("
                INSERT INTO estudio_socioeconomico (
                    id_paciente, fecha_estudio, rph_fecha_ingreso, rph_hora_ingreso, rph_tipo_ingreso, rph_referido_de,
                    rph_contrarreferido_de, rph_medico_ingresa, rph_diagnostico_ingreso, rph_caso_medico_legal,
                    rph_fecha_egreso, rph_hora_egreso, rph_alta_por, rph_referido_a, rph_medico_autoriza_egreso,
                    rph_diagnostico_egreso, rph_nombre_familiar_recibe, rph_parentesco_recibe, rph_donacion_sangre,
                    rph_ts_ingreso_nombre, rph_ts_ingreso_cedula, rph_ts_egreso_nombre, rph_ts_egreso_cedula,
                    nombre_completo, edad, genero, telefono, domicilio, nombre_responsable, parentesco_responsable, curp
                ) VALUES (
                    :id_paciente, CURRENT_DATE, :f_ing, :h_ing, :t_ing, :ref_de, :cont_de, :med_ing, :diag_ing, :med_leg,
                    :f_egr, :h_egr, :alta_por, :ref_a, :med_aut, :diag_egr, :fam_rec, :par_rec, :don_sangre,
                    :ts_ing_nom, :ts_ing_ced, :ts_egr_nom, :ts_egr_ced, :nombre_completo, :edad, :genero, :telefono,
                    :domicilio, :nombre_resp, :par_resp, :curp
                )
            ");

            $stmt_ins->execute([
                'id_paciente' => $id_paciente,
                'f_ing' => $fecha_null('rph_fecha_ingreso'),
                'h_ing' => $fecha_null('rph_hora_ingreso'),
                't_ing' => $txt('rph_tipo_ingreso'),
                'ref_de' => $txt('rph_referido_de'),
                'cont_de' => $txt('rph_contrarreferido_de'),
                'med_ing' => $txt('rph_medico_ingresa'),
                'diag_ing' => $txt('rph_diagnostico_ingreso'),
                'med_leg' => $txt('rph_caso_medico_legal'),
                'f_egr' => $fecha_null('rph_fecha_egreso'),
                'h_egr' => $fecha_null('rph_hora_egreso'),
                'alta_por' => $txt('rph_alta_por'),
                'ref_a' => $txt('rph_referido_a'),
                'med_aut' => $txt('rph_medico_autoriza_egreso'),
                'diag_egr' => $txt('rph_diagnostico_egreso'),
                'fam_rec' => $txt('rph_nombre_familiar_recibe'),
                'par_rec' => $txt('rph_parentesco_recibe'),
                'don_sangre' => $txt('rph_donacion_sangre'),
                'ts_ing_nom' => $txt('rph_ts_ingreso_nombre'),
                'ts_ing_ced' => $txt('rph_ts_ingreso_cedula'),
                'ts_egr_nom' => $txt('rph_ts_egreso_nombre'),
                'ts_egr_ced' => $txt('rph_ts_egreso_cedula'),
                'nombre_completo' => $txt('nombre_completo'),
                'edad' => intval($_POST['edad'] ?? 0),
                'genero' => $txt('genero'),
                'telefono' => $_POST['telefono'] ?? '',
                'domicilio' => $txt('domicilio'),
                'nombre_resp' => $txt('nombre_responsable'),
                'par_resp' => $txt('parentesco_responsable'),
                'curp' => $txt('curp')
            ]);

            $id_estudio = $pdo->lastInsertId();
            $exito_msg = "Registro RPH guardado correctamente.";
            
            $stmt_lista = $pdo->prepare("SELECT id_estudio, fecha_estudio FROM estudio_socioeconomico WHERE id_paciente = :id_paciente ORDER BY fecha_estudio DESC, id_estudio DESC");
            $stmt_lista->execute(['id_paciente' => $id_paciente]);
            $estudios_previos = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);
        }

        // Recargar datos actualizados del estudio
        $stmt_est = $pdo->prepare("SELECT * FROM estudio_socioeconomico WHERE id_estudio = :id_estudio");
        $stmt_est->execute(['id_estudio' => $id_estudio]);
        $estudio = $stmt_est->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error_msg = "Error al guardar el RPH: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>REGISTRO DE PACIENTES HOSPITALIZADOS (RPH)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-gris-fondo: #f4f6f8; --borde: #94a3b8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .formatos-nav { background: #e2e8f0; padding: 10px 25px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #cbd5e1; flex-wrap: wrap; gap: 10px; font-size: 12px; }
        .formato-tab { padding: 5px 12px; background: white; border-radius: 4px; text-decoration: none; color: #334155; font-weight: bold; border: 1px solid #94a3b8; }
        .formato-tab.active { background: var(--imss-verde); color: white; border-color: var(--imss-verde); }

        .header-logos { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--imss-verde); padding-bottom: 10px; margin-bottom: 15px; }
        .header-logos img { height: 50px; object-fit: contain; }
        .header-title { text-align: center; flex: 1; margin: 0 15px; }
        .header-title h3 { margin: 2px 0; font-size: 12px; font-weight: bold; color: #1c252e; }
        .header-title h4 { margin: 2px 0; font-size: 11px; font-weight: bold; color: var(--imss-verde); }

        .container { max-width: 950px; margin: 25px auto; padding: 40px; background: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .section-title { background: #e2e8f0; font-weight: bold; font-size: 12px; padding: 6px; margin: 15px 0 10px 0; border: 1px solid #cbd5e1; text-align: center; text-transform: uppercase; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .form-group { margin-bottom: 8px; font-size: 12px; }
        .form-group label { font-weight: bold; display: inline-block; margin-right: 5px; }
        input[type="text"], input[type="date"], input[type="time"], select, textarea { width: 100%; padding: 4px 6px; border: 1px solid var(--borde); border-radius: 3px; font-size: 12px; text-transform: uppercase; box-sizing: border-box; }
        .btn-container { text-align: right; margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; color: white; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-print { background-color: #0284c7; }

        @media print {
            .navbar, .formatos-nav, .btn-container, .form-control-view { display: none !important; }
            body { background: white; font-size: 11px; color: #000; }
            .container { box-shadow: none; margin: 0; padding: 0; max-width: 100%; width: 100%; }
            input, select, textarea { border: none !important; border-bottom: 1px solid #000 !important; background: transparent !important; padding: 0 !important; border-radius: 0 !important; }
        }
    </style>
</head>
<body>
    <div class="navbar form-control-view">
        <div><strong>SECRETARÍA DE SALUD | TABASCO</strong></div>
        <a href="trabajo_social.php"><i class="fa-solid fa-arrow-left"></i> Volver al Listado</a>
    </div>

    <!-- BARRA UNIFICADA: 4 FORMATOS Y COMBO DE REGISTROS -->
    <div class="formatos-nav form-control-view">
        <div style="display: flex; gap: 5px; align-items: center; flex-wrap: wrap;">
            <strong>Formatos:</strong>
            <a href="rph.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab active">1. RPH</a>
            <a href="estudio_socioeconomico.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">2. Socioeconómico</a>
            <a href="nota_trabajo_social.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">3. Nota Trabajo Social</a>
            <a href="estructura_familiar.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">4. Estructura Familiar</a>
        </div>

        <div style="display: flex; gap: 8px; align-items: center;">
            <strong>Registros:</strong>
            <select id="selectorEstudios" onchange="cambiarEstudio(this)" style="padding: 4px 8px; border-radius: 4px; border: 1px solid #94a3b8; font-size: 12px; background: white; width: auto;">
                <option value="">-- SELECCIONE UN REGISTRO --</option>
                <?php 
                $total_est = count($estudios_previos);
                foreach ($estudios_previos as $index => $ep): 
                    $num_est = $total_est - $index;
                    $selected = ($id_estudio == $ep['id_estudio']) ? 'selected' : '';
                ?>
                    <option value="rph.php?id_paciente=<?= $id_paciente ?>&id_estudio=<?= $ep['id_estudio'] ?>" <?= $selected ?>>
                        Registro #<?= $num_est ?> (<?= date('d/m/Y', strtotime($ep['fecha_estudio'] ?? 'now')) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="rph.php?id_paciente=<?= $id_paciente ?>" class="btn-nuevo-estudio" style="background: #0284c7; color: white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: bold; display: inline-flex; align-items: center; gap: 4px;">
                <i class="fa-solid fa-plus"></i> Nuevo
            </a>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($error_msg)): ?>
            <div style="background: #ffebee; color: #c62828; padding: 10px; margin-bottom: 15px; font-weight: bold;"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($exito_msg)): ?>
            <div style="background: #e4f8dd; color: var(--imss-verde); padding: 10px; margin-bottom: 15px; font-weight: bold;"><?= htmlspecialchars($exito_msg) ?></div>
        <?php endif; ?>

        <!-- ENCABEZADO CON LOGOS -->
        <div class="header-logos">
            <div style="display: flex; gap: 8px; align-items: center;">
                <img src="logo_gobierno.png" alt="Gobierno de México" onerror="this.style.display='none'">
                <img src="logo_imss.png" alt="IMSS-Bienestar" onerror="this.style.display='none'">
            </div>
            <div class="header-title">
                <h3>HOSPITAL GENERAL DE HUIMANGUILLO - DR. ADELFO S. AGUIRRE</h3>
                <h3>IMSS-BIENESTAR - DEPARTAMENTO DE TRABAJO SOCIAL</h3>
                <h4>REGISTRO DE PACIENTES HOSPITALIZADOS "RPH"</h4>
            </div>
            <div>
                <img src="logo_hospital.png" alt="Hospital" onerror="this.style.display='none'">
            </div>
        </div>

        <form action="rph.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" method="POST">
            <input type="hidden" name="accion" value="guardar_rph">
            <input type="hidden" name="id_estudio" value="<?= $id_estudio ?>">

            <div class="section-title">DATOS DE INGRESO</div>
            <div class="grid-3">
                <div class="form-group" style="grid-column: span 2;">
                    <label>NOMBRE DEL PACIENTE:</label>
                    <input type="text" name="nombre_completo" value="<?= htmlspecialchars($estudio['nombre_completo'] ?? trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apaterno'] ?? '') . ' ' . ($paciente['amaterno'] ?? ''))) ?>" required>
                </div>
                <div class="form-group">
                    <label>GÉNERO:</label>
                    <select name="genero">
                        <?php $gen = $estudio['genero'] ?? ($paciente['sexo'] ?? ''); ?>
                        <option value="">SELECCIONE...</option>
                        <option value="MASCULINO" <?= $gen === 'MASCULINO' ? 'selected' : '' ?>>MASCULINO</option>
                        <option value="FEMENINO" <?= $gen === 'FEMENINO' ? 'selected' : '' ?>>FEMENINO</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>EDAD:</label>
                    <input type="number" name="edad" value="<?= htmlspecialchars($estudio['edad'] ?? ($paciente['edad'] ?? '')) ?>">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>CURP:</label>
                    <input type="text" name="curp" value="<?= htmlspecialchars($estudio['curp'] ?? ($paciente['curp'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>TELÉFONO:</label>
                    <input type="text" name="telefono" value="<?= htmlspecialchars($estudio['telefono'] ?? ($paciente['telefono'] ?? '')) ?>" maxlength="10">
                </div>
                <div class="form-group" style="grid-column: span 3;">
                    <label>DOMICILIO:</label>
                    <input type="text" name="domicilio" value="<?= htmlspecialchars($estudio['domicilio'] ?? ($paciente['domicilio'] ?? '')) ?>">
                </div>
            </div>

            <div class="grid-3" style="margin-top: 5px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label>NOMBRE DEL FAMILIAR RESPONSABLE / TUTOR:</label>
                    <input type="text" name="nombre_responsable" value="<?= htmlspecialchars($estudio['nombre_responsable'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>PARENTESCO:</label>
                    <input type="text" name="parentesco_responsable" value="<?= htmlspecialchars($estudio['parentesco_responsable'] ?? '') ?>">
                </div>
            </div>

            <div class="grid-4" style="margin-top: 5px;">
                <div class="form-group">
                    <label>CASO MÉDICO LEGAL:</label>
                    <select name="rph_caso_medico_legal">
                        <?php $cml = $estudio['rph_caso_medico_legal'] ?? 'NO'; ?>
                        <option value="NO" <?= $cml === 'NO' ? 'selected' : '' ?>>NO</option>
                        <option value="SI" <?= $cml === 'SI' ? 'selected' : '' ?>>SI</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>FECHA INGRESO:</label>
                    <input type="date" name="rph_fecha_ingreso" value="<?= htmlspecialchars($estudio['rph_fecha_ingreso'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label>HORA INGRESO:</label>
                    <input type="time" name="rph_hora_ingreso" value="<?= htmlspecialchars($estudio['rph_hora_ingreso'] ?? date('H:i')) ?>">
                </div>
                <div class="form-group">
                    <label>TIPO DE INGRESO:</label>
                    <select name="rph_tipo_ingreso">
                        <?php $ti = $estudio['rph_tipo_ingreso'] ?? ''; ?>
                        <option value="PROGRAMADO" <?= $ti === 'PROGRAMADO' ? 'selected' : '' ?>>PROGRAMADO</option>
                        <option value="ESPONTÁNEO" <?= $ti === 'ESPONTÁNEO' ? 'selected' : '' ?>>ESPONTÁNEO</option>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>REFERIDO DE:</label>
                    <input type="text" name="rph_referido_de" value="<?= htmlspecialchars($estudio['rph_referido_de'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>CONTRARREFERIDO DE:</label>
                    <input type="text" name="rph_contrarreferido_de" value="<?= htmlspecialchars($estudio['rph_contrarreferido_de'] ?? '') ?>">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>MÉDICO QUE INGRESA:</label>
                    <input type="text" name="rph_medico_ingresa" id="input_medico_ingresa" list="datalist_medicos" value="<?= htmlspecialchars($estudio['rph_medico_ingresa'] ?? '') ?>" placeholder="ESCRIBA O SELECCIONE UN MÉDICO..." oninput="autocompletarCedula('ingresa')" onchange="autocompletarCedula('ingresa')">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>DIAGNÓSTICO DE INGRESO:</label>
                    <textarea name="rph_diagnostico_ingreso" rows="2"><?= htmlspecialchars($estudio['rph_diagnostico_ingreso'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>TRABAJADOR(A) SOCIAL (Ingreso):</label>
                    <input type="text" name="rph_ts_ingreso_nombre" placeholder="Nombre completo" value="<?= htmlspecialchars($estudio['rph_ts_ingreso_nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>CÉDULA PROFESIONAL:</label>
                    <input type="text" name="rph_ts_ingreso_cedula" id="input_cedula_ingresa" value="<?= htmlspecialchars($estudio['rph_ts_ingreso_cedula'] ?? '') ?>" placeholder="Cédula automática o manual">
                </div>
            </div>

            <div class="section-title">EGRESO</div>
            <div class="grid-3">
                <div class="form-group">
                    <label>FECHA DE EGRESO:</label>
                    <input type="date" name="rph_fecha_egreso" value="<?= htmlspecialchars($estudio['rph_fecha_egreso'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>HORA:</label>
                    <input type="time" name="rph_hora_egreso" value="<?= htmlspecialchars($estudio['rph_hora_egreso'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>ALTA POR:</label>
                    <select name="rph_alta_por">
                        <?php $ap = $estudio['rph_alta_por'] ?? ''; ?>
                        <option value="MEJORÍA" <?= $ap === 'MEJORÍA' ? 'selected' : '' ?>>MEJORÍA</option>
                        <option value="VOLUNTARIA" <?= $ap === 'VOLUNTARIA' ? 'selected' : '' ?>>VOLUNTARIA</option>
                        <option value="MAX. BENEFICIO" <?= $ap === 'MAX. BENEFICIO' ? 'selected' : '' ?>>MAX. BENEFICIO</option>
                        <option value="DEFUNCIÓN" <?= $ap === 'DEFUNCIÓN' ? 'selected' : '' ?>>DEFUNCIÓN</option>
                        <option value="FUGA" <?= $ap === 'FUGA' ? 'selected' : '' ?>>FUGA</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>REFERIDO A:</label>
                    <input type="text" name="rph_referido_a" value="<?= htmlspecialchars($estudio['rph_referido_a'] ?? '') ?>">
                </div>
                <div class="form-group" style="grid-column: span 3;">
                    <label>MÉDICO QUE AUTORIZA EL EGRESO:</label>
                    <input type="text" name="rph_medico_autoriza_egreso" id="input_medico_egreso" list="datalist_medicos" value="<?= htmlspecialchars($estudio['rph_medico_autoriza_egreso'] ?? '') ?>" placeholder="ESCRIBA O SELECCIONE UN MÉDICO..." oninput="autocompletarCedula('egreso')" onchange="autocompletarCedula('egreso')">
                </div>
                <div class="form-group" style="grid-column: span 3;">
                    <label>DIAGNÓSTICO DE EGRESO:</label>
                    <textarea name="rph_diagnostico_egreso" rows="2"><?= htmlspecialchars($estudio['rph_diagnostico_egreso'] ?? '') ?></textarea>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>FAMILIAR QUE RECIBE AL PACIENTE:</label>
                    <input type="text" name="rph_nombre_familiar_recibe" value="<?= htmlspecialchars($estudio['rph_nombre_familiar_recibe'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>PARENTESCO:</label>
                    <input type="text" name="rph_parentesco_recibe" value="<?= htmlspecialchars($estudio['rph_parentesco_recibe'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>DONACIÓN DE SANGRE:</label>
                    <select name="rph_donacion_sangre">
                        <?php $ds = $estudio['rph_donacion_sangre'] ?? 'NO'; ?>
                        <option value="NO" <?= $ds === 'NO' ? 'selected' : '' ?>>NO</option>
                        <option value="SI" <?= $ds === 'SI' ? 'selected' : '' ?>>SI</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>TRABAJADOR(A) SOCIAL (Egreso):</label>
                    <input type="text" name="rph_ts_egreso_nombre" placeholder="Nombre completo" value="<?= htmlspecialchars($estudio['rph_ts_egreso_nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>CÉDULA PROFESIONAL:</label>
                    <input type="text" name="rph_ts_egreso_cedula" id="input_cedula_egreso" value="<?= htmlspecialchars($estudio['rph_ts_egreso_cedula'] ?? '') ?>" placeholder="Cédula automática o manual">
                </div>
            </div>

            <!-- Listado con nombre completo y cédula para el buscador -->
            <datalist id="datalist_medicos">
                <?php foreach ($json_medicos as $m): ?>
                    <option value="<?= htmlspecialchars($m['nombre']) ?> - CÉDULA: <?= htmlspecialchars($m['cedula']) ?>">
                <?php endforeach; ?>
            </datalist>

            <div class="btn-container">
                <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir RPH</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar RPH</button>
            </div>
        </form>
    </div>

    <script>
    const medicosData = <?= json_encode($json_medicos) ?>;

    function autocompletarCedula(tipo) {
        let inputNombre = document.getElementById(tipo === 'ingresa' ? 'input_medico_ingresa' : 'input_medico_egreso');
        let inputCedula = document.getElementById(tipo === 'ingresa' ? 'input_cedula_ingresa' : 'input_cedula_egreso');
        
        let valorEscrito = inputNombre.value.toUpperCase().trim();

        // 1. Si el usuario seleccionó la opción del listado que incluye " - CÉDULA: "
        if (valorEscrito.includes(" - CÉDULA: ")) {
            let partes = valorEscrito.split(" - CÉDULA: ");
            inputNombre.value = partes[0].trim();
            if (partes[1]) {
                inputCedula.value = partes[1].trim();
            }
            return;
        }

        // 2. Si escribe o coincide exactamente con el nombre completo
        let encontrado = medicosData.find(m => m.nombre === valorEscrito);
        if (encontrado) {
            inputCedula.value = encontrado.cedula;
        }
    }

    function cambiarEstudio(select) {
        if (select.value) {
            window.location.href = select.value;
        }
    }
    </script>
</body>
</html>