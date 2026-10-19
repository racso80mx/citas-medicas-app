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

$id_cita = intval($_GET['id_cita'] ?? 0);
$expediente = trim($_GET['expediente'] ?? '');

if ($id_cita === 0 && empty($expediente)) {
    header("Location: psicologia.php");
    exit();
}

// ===================================================================
// 2. PROCESAMIENTO DE GUARDADO / ACTUALIZACIÓN DE LA ENTREVISTA
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fecha_historia                         = $_POST['fecha_historia'] ?? date('Y-m-d');
        $unidad                                 = mb_strtoupper(trim($_POST['unidad'] ?? ''));
        $jurisdiccion                           = mb_strtoupper(trim($_POST['jurisdiccion'] ?? ''));
        $estado_civil                           = mb_strtoupper(trim($_POST['estado_civil'] ?? ''));
        $religion                               = mb_strtoupper(trim($_POST['religion'] ?? ''));
        $escolaridad                            = mb_strtoupper(trim($_POST['escolaridad'] ?? ''));
        $estado_nacimiento                      = mb_strtoupper(trim($_POST['estado_nacimiento'] ?? ''));
        $fecha_nacimiento                       = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
        $edad_actual                            = !empty($_POST['edad_actual']) ? intval($_POST['edad_actual']) : null;
        $domicilio_actual                       = mb_strtoupper(trim($_POST['domicilio_actual'] ?? ''));
        $telefono                               = trim($_POST['telefono'] ?? '');
        $ocupacion                              = mb_strtoupper(trim($_POST['ocupacion'] ?? ''));
        $situacion_economica                    = mb_strtoupper(trim($_POST['situacion_economica'] ?? ''));
        $toma_fuma                              = mb_strtoupper(trim($_POST['toma_fuma'] ?? ''));
        $ha_recibido_psicoterapia               = mb_strtoupper(trim($_POST['ha_recibido_psicoterapia'] ?? ''));
        $impresion_dx                           = mb_strtoupper(trim($_POST['impresion_dx'] ?? ''));
        $curp                                   = mb_strtoupper(trim($_POST['curp'] ?? ''));
        $personas_vive_json                     = $_POST['personas_vive_json'] ?? '[]';
        $familiograma_json                      = $_POST['familiograma_json'] ?? '[]';
        $motivo_consulta                        = mb_strtoupper(trim($_POST['motivo_consulta'] ?? ''));
        $antecedentes_heredofamiliares          = mb_strtoupper(trim($_POST['antecedentes_heredofamiliares'] ?? ''));
        $antecedentes_personales_no_patologicos = mb_strtoupper(trim($_POST['antecedentes_personales_no_patologicos'] ?? ''));
        $antecedentes_personales_patologicos    = mb_strtoupper(trim($_POST['antecedentes_personales_patologicos'] ?? ''));
        $historia_padecimiento_actual           = mb_strtoupper(trim($_POST['historia_padecimiento_actual'] ?? ''));
        $familiograma_observaciones             = mb_strtoupper(trim($_POST['familiograma_observaciones'] ?? ''));
        $expl_descripcion_general               = mb_strtoupper(trim($_POST['expl_descripcion_general'] ?? ''));
        $expl_humor_afectividad                 = mb_strtoupper(trim($_POST['expl_humor_afectividad'] ?? ''));
        $expl_caracteristicas_lenguaje          = mb_strtoupper(trim($_POST['expl_caracteristicas_lenguaje'] ?? ''));
        $expl_percepcion                        = mb_strtoupper(trim($_POST['expl_percepcion'] ?? ''));
        $expl_contenido_pensamiento             = mb_strtoupper(trim($_POST['expl_contenido_pensamiento'] ?? ''));
        $expl_sensorium_cognicion               = mb_strtoupper(trim($_POST['expl_sensorium_cognicion'] ?? ''));
        $impulsividad                           = mb_strtoupper(trim($_POST['impulsividad'] ?? ''));
        $juicio_introspeccion                   = mb_strtoupper(trim($_POST['juicio_introspeccion'] ?? ''));
        $pruebas_complementarias                = mb_strtoupper(trim($_POST['pruebas_complementarias'] ?? ''));
        $tratamiento                            = mb_strtoupper(trim($_POST['tratamiento'] ?? ''));
        $pronostico                             = mb_strtoupper(trim($_POST['pronostico'] ?? ''));
        $proxima_cita                           = mb_strtoupper(trim($_POST['proxima_cita'] ?? ''));

        if (!empty($telefono) && !preg_match('/^\d{10}$/', $telefono)) {
            throw new Exception("EL NÚMERO DE TELÉFONO DEBE CONSTANTE EXACTAMENTE DE 10 DÍGITOS NÚMERICOS.");
        }

        if (!empty($expediente) && !empty($domicilio_actual)) {
            $stmt_upd_pac = $pdo->prepare("UPDATE pacientes SET domicilio = :domicilio WHERE expediente = :exp OR id_paciente::text = :exp");
            $stmt_upd_pac->execute(['domicilio' => $domicilio_actual, 'exp' => $expediente]);
        }

        $stmt_chk = $pdo->prepare("SELECT id_entrevista FROM entrevistas_psicologia WHERE id_cita = :id_cita LIMIT 1");
        $stmt_chk->execute(['id_cita' => $id_cita]);
        $existe = $stmt_chk->fetchColumn();

        if ($existe) {
            $stmt_upd = $pdo->prepare("
                UPDATE entrevistas_psicologia SET 
                    fecha_historia = :fecha_historia, unidad = :unidad, jurisdiccion = :jurisdiccion, 
                    estado_civil = :estado_civil, religion = :religion, escolaridad = :escolaridad, 
                    estado_nacimiento = :estado_nacimiento, fecha_nacimiento = :fecha_nacimiento, edad_actual = :edad_actual,
                    domicilio_actual = :domicilio_actual, telefono = :telefono, ocupacion = :ocupacion, 
                    situacion_economica = :situacion_economica, toma_fuma = :toma_fuma, ha_recibido_psicoterapia = :ha_recibido_psicoterapia, 
                    impresion_dx = :impresion_dx, curp = :curp, personas_vive_json = :personas_vive_json, familiograma_json = :familiograma_json, 
                    motivo_consulta = :motivo_consulta, antecedentes_heredofamiliares = :antecedentes_heredofamiliares, 
                    antecedentes_personales_no_patologicos = :antecedentes_personales_no_patologicos, 
                    antecedentes_personales_patologicos = :antecedentes_personales_patologicos, 
                    historia_padecimiento_actual = :historia_padecimiento_actual, familiograma_observaciones = :familiograma_observaciones, 
                    expl_descripcion_general = :expl_descripcion_general, expl_humor_afectividad = :expl_humor_afectividad, 
                    expl_caracteristicas_lenguaje = :expl_caracteristicas_lenguaje, expl_percepcion = :expl_percepcion, 
                    expl_contenido_pensamiento = :expl_contenido_pensamiento, expl_sensorium_cognicion = :expl_sensorium_cognicion, 
                    impulsividad = :impulsividad, juicio_introspeccion = :juicio_introspeccion, 
                    pruebas_complementarias = :pruebas_complementarias, tratamiento = :tratamiento, 
                    pronostico = :pronostico, proxima_cita = :proxima_cita
                WHERE id_cita = :id_cita
            ");
            $stmt_upd->execute([
                'fecha_historia' => $fecha_historia, 'unidad' => $unidad, 'jurisdiccion' => $jurisdiccion, 
                'estado_civil' => $estado_civil, 'religion' => $religion, 'escolaridad' => $escolaridad, 
                'estado_nacimiento' => $estado_nacimiento, 'fecha_nacimiento' => $fecha_nacimiento, 'edad_actual' => $edad_actual,
                'domicilio_actual' => $domicilio_actual, 'telefono' => $telefono, 'ocupacion' => $ocupacion, 
                'situacion_economica' => $situacion_economica, 'toma_fuma' => $toma_fuma, 'ha_recibido_psicoterapia' => $ha_recibido_psicoterapia, 
                'impresion_dx' => $impresion_dx, 'curp' => $curp, 'personas_vive_json' => $personas_vive_json, 'familiograma_json' => $familiograma_json, 
                'motivo_consulta' => $motivo_consulta, 'antecedentes_heredofamiliares' => $antecedentes_heredofamiliares, 
                'antecedentes_personales_no_patologicos' => $antecedentes_personales_no_patologicos, 
                'antecedentes_personales_patologicos' => $antecedentes_personales_patologicos, 
                'historia_padecimiento_actual' => $historia_padecimiento_actual, 'familiograma_observaciones' => $familiograma_observaciones, 
                'expl_descripcion_general' => $expl_descripcion_general, 'expl_humor_afectividad' => $expl_humor_afectividad, 
                'expl_caracteristicas_lenguaje' => $expl_caracteristicas_lenguaje, 'expl_percepcion' => $expl_percepcion, 
                'expl_contenido_pensamiento' => $expl_contenido_pensamiento, 'expl_sensorium_cognicion' => $expl_sensorium_cognicion, 
                'impulsividad' => $impulsividad, 'juicio_introspeccion' => $juicio_introspeccion, 
                'pruebas_complementarias' => $pruebas_complementarias, 'tratamiento' => $tratamiento, 
                'pronostico' => $pronostico, 'proxima_cita' => $proxima_cita, 'id_cita' => $id_cita
            ]);
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO entrevistas_psicologia (
                    id_cita, no_expediente, fecha_historia, unidad, jurisdiccion, estado_civil, religion, 
                    escolaridad, estado_nacimiento, fecha_nacimiento, edad_actual, domicilio_actual, telefono, ocupacion, situacion_economica, 
                    toma_fuma, ha_recibido_psicoterapia, impresion_dx, curp, personas_vive_json, familiograma_json, motivo_consulta, 
                    antecedentes_heredofamiliares, antecedentes_personales_no_patologicos, antecedentes_personales_patologicos, 
                    historia_padecimiento_actual, familiograma_observaciones, expl_descripcion_general, expl_humor_afectividad, 
                    expl_caracteristicas_lenguaje, expl_percepcion, expl_contenido_pensamiento, expl_sensorium_cognicion, 
                    impulsividad, juicio_introspeccion, pruebas_complementarias, tratamiento, pronostico, proxima_cita
                ) VALUES (
                    :id_cita, :exp, :fecha_historia, :unidad, :jurisdiccion, :estado_civil, :religion, 
                    :escolaridad, :estado_nacimiento, :fecha_nacimiento, :edad_actual, :domicilio_actual, :telefono, :ocupacion, :situacion_economica, 
                    :toma_fuma, :ha_recibido_psicoterapia, :impresion_dx, :curp, :personas_vive_json, :familiograma_json, :motivo_consulta, 
                    :antecedentes_heredofamiliares, :antecedentes_personales_no_patologicos, :antecedentes_personales_patologicos, 
                    :historia_padecimiento_actual, :familiograma_observaciones, :expl_descripcion_general, :expl_humor_afectividad, 
                    :expl_caracteristicas_lenguaje, :expl_percepcion, :expl_contenido_pensamiento, :expl_sensorium_cognicion, 
                    :impulsividad, :juicio_introspeccion, :pruebas_complementarias, :tratamiento, :pronostico, :proxima_cita
                )
            ");
            $stmt_ins->execute([
                'id_cita' => $id_cita, 'exp' => $expediente, 'fecha_historia' => $fecha_historia, 'unidad' => $unidad, 'jurisdiccion' => $jurisdiccion, 
                'estado_civil' => $estado_civil, 'religion' => $religion, 'escolaridad' => $escolaridad, 
                'estado_nacimiento' => $estado_nacimiento, 'fecha_nacimiento' => $fecha_nacimiento, 'edad_actual' => $edad_actual, 
                'domicilio_actual' => $domicilio_actual, 'telefono' => $telefono, 'ocupacion' => $ocupacion, 
                'situacion_economica' => $situacion_economica, 'toma_fuma' => $toma_fuma, 'ha_recibido_psicoterapia' => $ha_recibido_psicoterapia, 
                'impresion_dx' => $impresion_dx, 'curp' => $curp, 'personas_vive_json' => $personas_vive_json, 'familiograma_json' => $familiograma_json, 
                'motivo_consulta' => $motivo_consulta, 'antecedentes_heredofamiliares' => $antecedentes_heredofamiliares, 
                'antecedentes_personales_no_patologicos' => $antecedentes_personales_no_patologicos, 
                'antecedentes_personales_patologicos' => $antecedentes_personales_patologicos, 
                'historia_padecimiento_actual' => $historia_padecimiento_actual, 'familiograma_observaciones' => $familiograma_observaciones, 
                'expl_descripcion_general' => $expl_descripcion_general, 'expl_humor_afectividad' => $expl_humor_afectividad, 
                'expl_caracteristicas_lenguaje' => $expl_caracteristicas_lenguaje, 'expl_percepcion' => $expl_percepcion, 
                'expl_contenido_pensamiento' => $expl_contenido_pensamiento, 'expl_sensorium_cognicion' => $expl_sensorium_cognicion, 
                'impulsividad' => $impulsividad, 'juicio_introspeccion' => $juicio_introspeccion, 
                'pruebas_complementarias' => $pruebas_complementarias, 'tratamiento' => $tratamiento, 
                'pronostico' => $pronostico, 'proxima_cita' => $proxima_cita
            ]);
        }

        header("Location: entrevistas_psicologia.php?id_cita=$id_cita&expediente=$expediente&exito=1");
        exit();
    } catch (Exception $e) {
        $err = urlencode(mb_strtoupper($e->getMessage()));
        header("Location: entrevistas_psicologia.php?id_cita=$id_cita&expediente=$expediente&error=" . $err);
        exit();
    }
}

// ===================================================================
// 3. RECUPERAR DATOS DE LA CITA, PACIENTE Y ENTREVISTA EXISTENTE
// ===================================================================
$cita = [];
$paciente = [];
$entrevista = [];

try {
    if ($id_cita > 0) {
        $stmt_c = $pdo->prepare("SELECT * FROM citas_psicologia WHERE id_cita = :id LIMIT 1");
        $stmt_c->execute(['id' => $id_cita]);
        $cita = $stmt_c->fetch(PDO::FETCH_ASSOC);
        if ($cita && !empty($cita['no_expediente'])) {
            $expediente = $cita['no_expediente'];
        }
    }

    if (!empty($expediente)) {
        $stmt_p = $pdo->prepare("SELECT * FROM pacientes WHERE expediente = :exp OR id_paciente::text = :exp LIMIT 1");
        $stmt_p->execute(['exp' => $expediente]);
        $paciente = $stmt_p->fetch(PDO::FETCH_ASSOC);
    }

    $stmt_e = $pdo->prepare("SELECT * FROM entrevistas_psicologia WHERE id_cita = :id_cita LIMIT 1");
    $stmt_e->execute(['id_cita' => $id_cita]);
    $entrevista = $stmt_e->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$nombre_paciente = $cita['nombre_paciente'] ?? trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apaterno'] ?? '') . ' ' . ($paciente['amaterno'] ?? ''));
$curp_val = $entrevista['curp'] ?? $cita['curp'] ?? ($paciente['curp'] ?? '');
$tel_val = $entrevista['telefono'] ?? $cita['telefono'] ?? ($paciente['telefono'] ?? '');
$dom_val = $entrevista['domicilio_actual'] ?? ($paciente['domicilio'] ?? '');
$exp_val = $expediente ?: ($cita['no_expediente'] ?? 'S/N');
$personas_vive_data = $entrevista['personas_vive_json'] ?? '[]';
$familiograma_data = $entrevista['familiograma_json'] ?? '[]';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAISME | HISTORIA CLÍNICA Y NOTA PSICOLÓGICA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --fondo: #f4f6f8; --borde: #cbd5e1; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--fondo); color: #1c252e; text-transform: uppercase; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 980px; margin: 25px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 5px solid var(--imss-verde); }
        
        .header-sheet { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--imss-verde); padding-bottom: 10px; margin-bottom: 20px; }
        .header-sheet img { max-height: 55px; object-fit: contain; }
        .header-title { text-align: center; color: var(--imss-verde); font-size: 14px; font-weight: bold; line-height: 1.2; }
        
        fieldset { border: 1px solid var(--borde); border-radius: 6px; padding: 15px; margin-bottom: 20px; background: #fff; }
        legend { font-weight: bold; font-size: 13px; color: var(--imss-verde); padding: 0 8px; background: #fff; border: 1px solid var(--borde); border-radius: 4px; }
        
        .form-row { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
        .form-group { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 4px; }
        .form-group.full { flex: 100%; }
        label { font-size: 11px; font-weight: bold; color: #475569; }
        input[type="text"], input[type="date"], input[type="number"], select, textarea { width: 100%; padding: 8px; border: 1px solid var(--borde); border-radius: 4px; font-size: 12px; box-sizing: border-box; text-transform: uppercase; background: #f8fafc; }
        textarea { resize: vertical; min-height: 70px; }
        
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #64748b; }
        .btn-danger { background-color: #dc2626; }
        .acciones-bar { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; border-top: 1px solid var(--borde); padding-top: 15px; }

        .tabla-convive { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 10px; font-size: 12px; }
        .tabla-convive th, .tabla-convive td { border: 1px solid var(--borde); padding: 8px; text-align: left; }
        .tabla-convive th { background: #f1f5f9; color: var(--imss-verde); }

        /* Estilos Familiograma con Botones Grandes e Iconos */
        .familiograma-container { border: 2px dashed var(--borde); border-radius: 6px; padding: 12px; background: #fafafa; }
        .familiograma-toolbar { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; background: #e2e8f0; padding: 10px; border-radius: 6px; }
        .tool-btn { background: white; border: 1px solid var(--borde); padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 11px; display: inline-flex; align-items: center; gap: 6px; color: #1e293b; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .tool-btn:hover { background: #f1f5f9; border-color: var(--imss-verde); color: var(--imss-verde); }
        .familiograma-canvas { position: relative; width: 100%; height: 420px; background: white; border: 1px solid var(--borde); border-radius: 6px; overflow: hidden; }
        
        .fam-item { position: absolute; cursor: grab; padding: 2px; user-select: none; -webkit-user-select: none; touch-action: none; display: flex; align-items: center; justify-content: center; }
        .fam-item.dragging { cursor: grabbing; z-index: 5; opacity: 0.9; }
        .fam-square { width: 35px; height: 35px; border: 2px solid #2563eb; background: #eff6ff; box-sizing: border-box; }
        .fam-circle { width: 35px; height: 35px; border: 2px solid #db2777; border-radius: 50%; background: #fdf2f8; box-sizing: border-box; }
        .fam-square-dead { width: 35px; height: 35px; border: 2px solid #1e40af; background: #93c5fd; position: relative; box-sizing: border-box; }
        .fam-square-dead::before, .fam-square-dead::after { content: ''; position: absolute; top: 16px; left: 0; width: 35px; height: 2px; background: #1e3a8a; }
        .fam-square-dead::before { transform: rotate(45deg); }
        .fam-square-dead::after { transform: rotate(-45deg); }
        
        .fam-circle-dead { width: 35px; height: 35px; border: 2px solid #9d174d; border-radius: 50%; background: #fbcfe8; position: relative; box-sizing: border-box; }
        .fam-circle-dead::before, .fam-circle-dead::after { content: ''; position: absolute; top: 16px; left: 2px; width: 31px; height: 2px; background: #831843; }
        .fam-circle-dead::before { transform: rotate(45deg); }
        .fam-circle-dead::after { transform: rotate(-45deg); }

        .fam-triangle { width: 0; height: 0; border-left: 20px solid transparent; border-right: 20px solid transparent; border-bottom: 35px solid #7c3aed; position: relative; }
        
        .fam-line-h { height: 4px; background: #2563eb; width: 80px; }
        .fam-line-v { width: 4px; background: #2563eb; height: 50px; }
        .fam-line-sep { height: 8px; border-top: 2px solid #2563eb; border-bottom: 2px solid #2563eb; width: 40px; }
        .fam-line-conflict { height: 10px; border-top: 2px dashed #dc2626; border-bottom: 2px dashed #dc2626; width: 80px; }
        .fam-line-close { height: 8px; border-top: 2px solid #059669; border-bottom: 2px solid #059669; border-left: 2px solid #059669; width: 60px; }

        .fam-delete { position: absolute; top: -8px; right: -8px; background: #dc2626; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; z-index: 10; }
        .fam-item:hover .fam-delete { display: flex; }
        .fam-item.selected { outline: 2px dashed #f59e0b; }

        /* Símbolos SVG adicionales del familiograma */
        .fam-item.fam-svg { padding: 0; box-sizing: border-box; }
        .fam-item.fam-svg svg { display: block; width: 100%; height: 100%; overflow: visible; pointer-events: none; }
        .fam-shape-svg * { vector-effect: non-scaling-stroke; }
        .familiograma-toolbar.fam-extra { margin-top: -4px; background: #eef2f7; }
        .tool-btn.tool-shape { padding: 6px 8px; font-size: 10px; }
        .tool-btn.tool-shape svg { overflow: visible; flex-shrink: 0; }

        .fam-props { background: #f8fafc; padding: 8px 12px; border: 1px solid var(--borde); border-radius: 6px; margin-top: 8px; display: flex; gap: 12px; align-items: center; font-size: 11px; }

        @media print {
            body { background: white; padding: 0; }
            .navbar, .acciones-bar, .familiograma-toolbar, .fam-props, .no-print { display: none !important; }
            .container { box-shadow: none; margin: 0; max-width: 100%; padding: 10px; border: none; }
            .familiograma-canvas { border: 1px solid #000; height: 350px !important; }
        }
    </style>
</head>
<body>

    <div class="navbar no-print">
        <div><i class="fa-solid fa-comments"></i> <strong>SAISME: HISTORIA CLÍNICA Y ENTREVISTA PSICOLÓGICA</strong></div>
        <a href="psicologia.php"><i class="fa-solid fa-arrow-left"></i> VOLVER A CITAS</a>
    </div>

    <div class="container">
        <?php if (isset($_GET['exito'])): ?>
            <div class="no-print" style="background: #dcfce7; color: #166534; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-circle-check"></i> HISTORIA CLÍNICA Y ENTREVISTA GUARDADA CORRECTAMENTE.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="no-print" style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> ERROR: <?php echo htmlspecialchars(urldecode($_GET['error'])); ?>
            </div>
        <?php endif; ?>

        <div class="header-sheet">
            <img src="logo_hospital.png" alt="Logo Hospital">
            <div class="header-title">
                SERVICIOS DE SALUD IMSS-BIENESTAR<br>
                HOSPITAL GENERAL DE HUIMANGUILLO<br>
                <span style="font-size: 11px; font-weight: normal; color: #333;">“DR. ADELFO S. AGUIRRE” (CLUES: TCIMB001870)</span>
            </div>
            <img src="logo_imss.png" alt="Logo IMSS">
        </div>

        <form action="entrevistas_psicologia.php?id_cita=<?php echo $id_cita; ?>&expediente=<?php echo urlencode($expediente); ?>" method="POST" id="formHistoriaClinica">
            
            <input type="hidden" name="personas_vive_json" id="inputPersonasViveJson">
            <input type="hidden" name="familiograma_json" id="inputFamiliogramaJson">

            <!-- FICHA DE IDENTIFICACIÓN -->
            <fieldset>
                <legend>FICHA DE IDENTIFICACIÓN</legend>
                <div class="form-row">
                    <div class="form-group" style="flex: 2;">
                        <label>NOMBRE DEL PACIENTE:</label>
                        <input type="text" value="<?php echo htmlspecialchars($nombre_paciente); ?>" readonly style="background:#e2e8f0; font-weight:bold;">
                    </div>
                    <div class="form-group">
                        <label>S.P. / NO. DE EXPEDIENTE:</label>
                        <input type="text" value="<?php echo htmlspecialchars($exp_val); ?>" readonly style="background:#e2e8f0; font-weight:bold;">
                    </div>
                    <div class="form-group">
                        <label>CURP (18 CARACTERES):</label>
                        <input type="text" name="curp" id="inputCurp" value="<?php echo htmlspecialchars($curp_val); ?>" maxlength="18" oninput="this.value = this.value.toUpperCase(); procesarCurp(this.value);" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>FECHA DE HISTORIA:</label>
                        <input type="date" name="fecha_historia" value="<?php echo htmlspecialchars($entrevista['fecha_historia'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>UNIDAD:</label>
                        <input type="text" name="unidad" value="<?php echo htmlspecialchars($entrevista['unidad'] ?? 'HOSPITAL GRAL. HUIMANGUILLO'); ?>">
                    </div>
                    <div class="form-group">
                        <label>JURISDICCIÓN:</label>
                        <input type="text" name="jurisdiccion" value="<?php echo htmlspecialchars($entrevista['jurisdiccion'] ?? 'SANITARIA NO. 8'); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>ESTADO CIVIL:</label>
                        <select name="estado_civil">
                            <?php 
                            $ec_val = $entrevista['estado_civil'] ?? '';
                            $estados_civiles = ['SOLTERO(A)', 'CASADO(A)', 'DIVORCIADO(A)', 'VIUDO(A)', 'UNIÓN LIBRE', 'SEPARADO(A)'];
                            foreach($estados_civiles as $ec) {
                                $sel = ($ec_val === $ec) ? 'selected' : '';
                                echo "<option value=\"$ec\" $sel>$ec</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>RELIGIÓN:</label>
                        <select name="religion">
                            <?php 
                            $rel_val = $entrevista['religion'] ?? '';
                            $religiones = ['CATÓLICA', 'CRISTIANA / EVANGÉLICA', 'TESTIGO DE JEHOVÁ', 'MORMONA', 'JUDÍA', 'OTRA', 'SIN RELIGIÓN'];
                            foreach($religiones as $rel) {
                                $sel = ($rel_val === $rel) ? 'selected' : '';
                                echo "<option value=\"$rel\" $sel>$rel</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ESCOLARIDAD:</label>
                        <input type="text" name="escolaridad" value="<?php echo htmlspecialchars($entrevista['escolaridad'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>ESTADO DE NACIMIENTO:</label>
                        <select name="estado_nacimiento" id="selectEstadoNacimiento">
                            <?php 
                            $est_val = $entrevista['estado_nacimiento'] ?? '';
                            $estados_mexico = [
                                'AGUASCALIENTES', 'BAJA CALIFORNIA', 'BAJA CALIFORNIA SUR', 'CAMPECHE', 'CHIAPAS', 
                                'CHIHUAHUA', 'CIUDAD DE MÉXICO', 'COAHUILA', 'COLIMA', 'DURANGO', 'ESTADO DE MÉXICO', 
                                'GUANAJUATO', 'GUERRERO', 'HIDALGO', 'JALISCO', 'MICHOACÁN', 'MORELOS', 'NAYARIT', 
                                'NUEVO LEÓN', 'OAXACA', 'PUEBLA', 'QUERÉTARO', 'QUINTANA ROO', 'SAN LUIS POTOSÍ', 
                                'SINALOA', 'SONORA', 'TABASCO', 'TAMAULIPAS', 'TLAXCALA', 'VERACRUZ', 'YUCATÁN', 
                                'ZACATECAS', 'NACIDO EN EL EXTRANJERO'
                            ];
                            foreach($estados_mexico as $est) {
                                $sel = ($est_val === $est) ? 'selected' : '';
                                echo "<option value=\"$est\" $sel>$est</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>FECHA DE NACIMIENTO:</label>
                        <input type="date" name="fecha_nacimiento" id="inputFechaNacimiento" value="<?php echo htmlspecialchars($entrevista['fecha_nacimiento'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>EDAD ACTUAL (AÑOS):</label>
                        <input type="number" name="edad_actual" id="inputEdadActual" value="<?php echo htmlspecialchars($entrevista['edad_actual'] ?? ''); ?>" readonly style="background:#e2e8f0; font-weight:bold;">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 2;">
                        <label>DOMICILIO ACTUAL (CALLE, NO., COLONIA, ESTADO):</label>
                        <input type="text" name="domicilio_actual" value="<?php echo htmlspecialchars($dom_val); ?>" placeholder="SE ACTUALIZARÁ EN EL PERFIL DEL PACIENTE SI ESTÁ VACÍO">
                    </div>
                    <div class="form-group">
                        <label>TELÉFONO (10 DÍGITOS):</label>
                        <input type="text" name="telefono" value="<?php echo htmlspecialchars($tel_val); ?>" maxlength="10" placeholder="EJ. 9171145462" pattern="\d{10}" title="DEBE INGRESAR EXACTAMENTE 10 DÍGITOS NÚMERICOS" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,10);">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>OCUPACIÓN:</label>
                        <input type="text" name="ocupacion" value="<?php echo htmlspecialchars($entrevista['ocupacion'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>SITUACIÓN ECONÓMICA:</label>
                        <input type="text" name="situacion_economica" value="<?php echo htmlspecialchars($entrevista['situacion_economica'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>TOMA O FUMA:</label>
                        <select name="toma_fuma">
                            <?php 
                            $tf_val = $entrevista['toma_fuma'] ?? 'NO';
                            $opciones_tf = ['NO', 'SÍ TOMA', 'SÍ FUMA', 'SÍ TOMA Y FUMA'];
                            foreach($opciones_tf as $tf) {
                                $sel = ($tf_val === $tf) ? 'selected' : '';
                                echo "<option value=\"$tf\" $sel>$tf</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>HA RECIBIDO PSICOTERAPIA:</label>
                        <input type="text" name="ha_recibido_psicoterapia" value="<?php echo htmlspecialchars($entrevista['ha_recibido_psicoterapia'] ?? 'NO'); ?>">
                    </div>
                </div>

                <!-- PERSONAS CON QUIENES VIVE -->
                <div class="form-row" style="margin-top: 15px;">
                    <div class="form-group full">
                        <label>PERSONAS CON QUIENES VIVE:</label>
                        <div class="no-print" style="display: flex; gap: 8px; margin-bottom: 8px; background: #f8fafc; padding: 10px; border: 1px solid var(--borde); border-radius: 4px;">
                            <input type="text" id="addNombre" placeholder="NOMBRE COMPLETO" style="flex: 2;">
                            <input type="number" id="addEdad" placeholder="EDAD" style="width: 80px;">
                            <input type="text" id="addParentesco" placeholder="PARENTESCO (EJ. MADRE)" style="flex: 1;">
                            <button type="button" class="btn btn-primary" onclick="agregarPersonaVive()"><i class="fa-solid fa-plus"></i> AGREGAR</button>
                        </div>

                        <table class="tabla-convive">
                            <thead>
                                <tr>
                                    <th>NOMBRE</th>
                                    <th style="width: 80px;">EDAD</th>
                                    <th style="width: 150px;">PARENTESCO</th>
                                    <th class="no-print" style="width: 70px; text-align: center;">ACCIÓN</th>
                                </tr>
                            </thead>
                            <tbody id="tablaConviveBody"></tbody>
                        </table>
                    </div>
                </div>
            </fieldset>

            <!-- II. MOTIVO DE CONSULTA -->
            <fieldset>
                <legend>II. MOTIVO DE CONSULTA</legend>
                <div class="form-row">
                    <div class="form-group full">
                        <label>DESCRIPCIÓN DEL PROBLEMA SEGÚN EL PACIENTE / FAMILIAR:</label>
                        <textarea name="motivo_consulta" required><?php echo htmlspecialchars($entrevista['motivo_consulta'] ?? ''); ?></textarea>
                    </div>
                </div>
            </fieldset>

            <!-- III. ANTECEDENTES Y FAMILIOGRAMA -->
            <fieldset>
                <legend>III. ANTECEDENTES DEL PACIENTE Y FAMILIOGRAMA</legend>
                <div class="form-row">
                    <div class="form-group full">
                        <label>1. ANTECEDENTES HEREDOFAMILIARES (TRASTORNOS PSÍQUICOS, ETC.):</label>
                        <textarea name="antecedentes_heredofamiliares"><?php echo htmlspecialchars($entrevista['antecedentes_heredofamiliares'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>2. ANTECEDENTES PERSONALES NO PATOLÓGICOS (DESARROLLO, ESCOLAR, LABORAL, SEXUAL):</label>
                        <textarea name="antecedentes_personales_no_patologicos"><?php echo htmlspecialchars($entrevista['antecedentes_personales_no_patologicos'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>3. ANTECEDENTES PERSONALES PATOLÓGICOS (ESTADO SOMÁTICO, HÁBITOS, ETC.):</label>
                        <textarea name="antecedentes_personales_patologicos"><?php echo htmlspecialchars($entrevista['antecedentes_personales_patologicos'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>HISTORIA DEL PADECIMIENTO ACTUAL:</label>
                        <textarea name="historia_padecimiento_actual"><?php echo htmlspecialchars($entrevista['historia_padecimiento_actual'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- FAMILIOGRAMA INTERACTIVO CON BOTONES GRANDES E ICONOS -->
                <div class="form-row">
                    <div class="form-group full">
                        <label>2. FAMILIOGRAMA (ARRASTRE LOS SÍMBOLOS Y MODIFIQUE SU ANCHO/ALTO):</label>
                        <div class="familiograma-container">
                            <div class="familiograma-toolbar no-print">
                                <span style="font-size: 11px; font-weight: bold; color: var(--imss-verde);">AGREGAR SÍMBOLO:</span>
                                <button type="button" class="tool-btn" onclick="addFamElement('square')"><i class="fa-solid fa-square" style="color:#2563eb; font-size:14px;"></i> HOMBRE</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('circle')"><i class="fa-solid fa-circle" style="color:#db2777; font-size:14px;"></i> MUJER</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('square-dead')"><i class="fa-solid fa-square-xmark" style="color:#1e40af; font-size:14px;"></i> HOMB. +</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('circle-dead')"><i class="fa-solid fa-circle-xmark" style="color:#9d174d; font-size:14px;"></i> MUJ. +</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('triangle')"><i class="fa-solid fa-triangle-exclamation" style="color:#7c3aed; font-size:14px;"></i> ABORTO</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('line-h')"><i class="fa-solid fa-minus" style="color:#2563eb; font-size:14px;"></i> LÍNEA HORIZ.</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('line-v')"><i class="fa-solid fa-grip-lines-vertical" style="color:#2563eb; font-size:14px;"></i> LÍNEA VERT.</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('line-sep')"><i class="fa-solid fa-pause" style="color:#2563eb; font-size:14px;"></i> SEPARACIÓN</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('line-conflict')"><i class="fa-solid fa-bolt" style="color:#dc2626; font-size:14px;"></i> CONFLICTO</button>
                                <button type="button" class="tool-btn" onclick="addFamElement('line-close')"><i class="fa-solid fa-link" style="color:#059669; font-size:14px;"></i> ESTRECHA</button>
                                <button type="button" class="tool-btn btn-danger" onclick="clearFamCanvas()" style="color:#dc2626; margin-left:auto;"><i class="fa-solid fa-trash"></i> LIMPIAR</button>
                            </div>

                            <div id="famExtraToolbar" class="familiograma-toolbar fam-extra no-print">
                                <span style="font-size: 11px; font-weight: bold; color: var(--imss-verde);">MÁS SÍMBOLOS:</span>
                            </div>

                            <div id="familiogramaCanvas" class="familiograma-canvas"></div>

                            <div id="famPropsPanel" class="fam-props no-print" style="display:none;">
                                <span><strong>EDITAR SÍMBOLO SELECCIONADO:</strong></span>
                                <label>ANCHO (PX): <input type="number" id="propWidth" oninput="updateSelectedWidth(this.value)" style="width:70px;"></label>
                                <label>ALTO (PX): <input type="number" id="propHeight" oninput="updateSelectedHeight(this.value)" style="width:70px;"></label>
                                <span style="color:#64748b; font-size:10px; margin-left:auto;">(HAGA CLIC EN UN SÍMBOLO PARA REDIMENSIONARLO)</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 10px;">
                    <div class="form-group full">
                        <label>OBSERVACIONES DINÁMICAS DEL FAMILIOGRAMA:</label>
                        <textarea name="familiograma_observaciones"><?php echo htmlspecialchars($entrevista['familiograma_observaciones'] ?? ''); ?></textarea>
                    </div>
                </div>
            </fieldset>

            <!-- IV. EXPLORACIÓN MENTAL -->
            <fieldset>
                <legend>IV. EXPLORACIÓN MENTAL</legend>
                <div class="form-row">
                    <div class="form-group full">
                        <label>1. DESCRIPCIÓN GENERAL (ASPECTO, CONDUCTA, PSICOMOTORA, ACTITUD):</label>
                        <textarea name="expl_descripcion_general"><?php echo htmlspecialchars($entrevista['expl_descripcion_general'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>2. HUMOR Y AFECTIVIDAD (HUMOR, AFECTO):</label>
                        <textarea name="expl_humor_afectividad"><?php echo htmlspecialchars($entrevista['expl_humor_afectividad'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>3. CARACTERÍSTICAS DEL LENGUAJE:</label>
                        <textarea name="expl_caracteristicas_lenguaje"><?php echo htmlspecialchars($entrevista['expl_caracteristicas_lenguaje'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>4. PERCEPCIÓN:</label>
                        <textarea name="expl_percepcion"><?php echo htmlspecialchars($entrevista['expl_percepcion'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>5. CONTENIDO DEL PENSAMIENTO (PROCESO, CONTENIDO Y CONTROL):</label>
                        <textarea name="expl_contenido_pensamiento"><?php echo htmlspecialchars($entrevista['expl_contenido_pensamiento'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>6. SENSORIO Y COGNICIÓN (ORIENTACIÓN, MEMORIA, ATENCIÓN, LECTURA, ESCRITURA, ETC.):</label>
                        <textarea name="expl_sensorium_cognicion"><?php echo htmlspecialchars($entrevista['expl_sensorium_cognicion'] ?? ''); ?></textarea>
                    </div>
                </div>
            </fieldset>

            <!-- V, VI, VII, VIII. DIAGNÓSTICO Y PLAN TERAPÉUTICO -->
            <fieldset>
                <legend>V, VI, VII, VIII. DIAGNÓSTICO Y PLAN TERAPÉUTICO</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label>7. IMPULSIVIDAD:</label>
                        <input type="text" name="impulsividad" value="<?php echo htmlspecialchars($entrevista['impulsividad'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>8. JUICIO E INTROSPECCIÓN (INSIGHT):</label>
                        <input type="text" name="juicio_introspeccion" value="<?php echo htmlspecialchars($entrevista['juicio_introspeccion'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>V. PRUEBAS COMPLEMENTARIAS:</label>
                        <input type="text" name="pruebas_complementarias" value="<?php echo htmlspecialchars($entrevista['pruebas_complementarias'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group full">
                        <label>VI. TRATAMIENTO (SEGUIMIENTO):</label>
                        <input type="text" name="tratamiento" value="<?php echo htmlspecialchars($entrevista['tratamiento'] ?? 'PSICOTERAPIA HUMANISTA INDIVIDUAL'); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>VII. IMPRESIÓN DIAGNÓSTICA (CIE-10):</label>
                        <input type="text" name="impresion_dx" value="<?php echo htmlspecialchars($entrevista['impresion_dx'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>VIII. PRONÓSTICO:</label>
                        <input type="text" name="pronostico" value="<?php echo htmlspecialchars($entrevista['pronostico'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>PRÓXIMA CITA (FECHA Y HORA):</label>
                        <input type="text" name="proxima_cita" value="<?php echo htmlspecialchars($entrevista['proxima_cita'] ?? ''); ?>" placeholder="EJ. 25/10/2026 - 08:30 HRS">
                    </div>
                </div>
            </fieldset>

            <div class="acciones-bar">
                <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> IMPRIMIR HISTORIA CLÍNICA</button>
                <button type="button" class="btn btn-primary" onclick="prepararEnvio()"><i class="fa-solid fa-save"></i> GUARDAR ENTREVISTA</button>
            </div>
        </form>
    </div>

    <script>
        // PERSONAS CON QUIENES VIVE
        let personasViveList = <?php echo $personas_vive_data; ?>;

        function renderPersonasVive() {
            let tbody = document.getElementById('tablaConviveBody');
            tbody.innerHTML = '';
            if (personasViveList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #64748b;">NO SE HAN AGREGADO PERSONAS.</td></tr>';
                return;
            }
            personasViveList.forEach((p, index) => {
                let tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${p.nombre.toUpperCase()}</td>
                    <td>${p.edad}</td>
                    <td>${p.parentesco.toUpperCase()}</td>
                    <td class="no-print" style="text-align: center;"><button type="button" class="btn btn-danger" style="padding: 3px 8px; font-size: 11px;" onclick="eliminarPersonaVive(${index})"><i class="fa-solid fa-trash"></i></button></td>
                `;
                tbody.appendChild(tr);
            });
        }

        function agregarPersonaVive() {
            let nombre = document.getElementById('addNombre').value.trim();
            let edad = document.getElementById('addEdad').value.trim();
            let parentesco = document.getElementById('addParentesco').value.trim();
            if (!nombre || !edad || !parentesco) {
                alert("COMPLETE TODOS LOS CAMPOS DE LA PERSONA.");
                return;
            }
            personasViveList.push({ nombre, edad, parentesco });
            document.getElementById('addNombre').value = '';
            document.getElementById('addEdad').value = '';
            document.getElementById('addParentesco').value = '';
            renderPersonasVive();
        }

        function eliminarPersonaVive(index) {
            personasViveList.splice(index, 1);
            renderPersonasVive();
        }

        // FAMILIOGRAMA INTERACTIVO (ARRASTRE FLUIDO Y SEGURO)
        let familiogramaElements = <?php echo $familiograma_data; ?>;
        let selectedFamIndex = null;

        // CATÁLOGO DE SÍMBOLOS SVG ADICIONALES (se escalan con ANCHO/ALTO)
        const FAM_BLUE = '#1f6fb5', FAM_PINK = '#ff3fc0', FAM_PURPLE = '#7b5ea7';

        function famZigzag() {
            let pts = [];
            for (let i = 0; i <= 12; i++) pts.push((i * (100 / 12)).toFixed(2) + ',' + (i % 2 ? 80 : 20));
            return `<polyline points="${pts.join(' ')}" fill="none" stroke="#2e5c9e" stroke-width="2.5" stroke-linejoin="round"/>`;
        }

        function famWavy() {
            let d = 'M0 50';
            for (let i = 0; i < 10; i++) d += ` Q${i * 10 + 5} ${i % 2 ? 80 : 20} ${i * 10 + 10} 50`;
            return `<path d="M0 8 H100 M0 18 H100" stroke="${FAM_BLUE}" stroke-width="2" fill="none"/>` +
                   `<path d="${d}" stroke="#2e5c9e" stroke-width="2" fill="none"/>` +
                   `<path d="M0 82 H100 M0 92 H100" stroke="${FAM_BLUE}" stroke-width="2" fill="none"/>`;
        }

        const FAM_SHAPES = {
            'sv-sq-slash': { label: 'HOMBRE TACHADO', w: 50, h: 50, svg:
                `<line x1="25" y1="10" x2="25" y2="-12" stroke="${FAM_BLUE}" stroke-width="2.5"/>` +
                `<rect x="8" y="8" width="84" height="84" fill="#4472c4" stroke="#2f528f" stroke-width="2"/>` +
                `<line x1="-8" y1="108" x2="108" y2="-8" stroke="${FAM_BLUE}" stroke-width="2.5"/>` },
            'sv-circle-fill': { label: 'MUJER RELLENA', w: 36, h: 70, svg:
                `<line x1="50" y1="0" x2="50" y2="38" stroke="${FAM_BLUE}" stroke-width="2"/>` +
                `<ellipse cx="50" cy="69" rx="48" ry="30" fill="#5b9bd5" stroke="#41719c" stroke-width="2"/>` },
            'sv-sq-bold': { label: 'HOMBRE GRUESO', w: 45, h: 45, svg:
                `<rect x="4" y="4" width="92" height="92" fill="none" stroke="${FAM_BLUE}" stroke-width="4"/>` },
            'sv-circ-bold': { label: 'MUJER GRANDE', w: 55, h: 55, svg:
                `<ellipse cx="50" cy="50" rx="46" ry="46" fill="none" stroke="${FAM_PINK}" stroke-width="4"/>` },
            'sv-ellipse': { label: 'ÓVALO', w: 36, h: 50, svg:
                `<ellipse cx="50" cy="50" rx="46" ry="46" fill="none" stroke="${FAM_PINK}" stroke-width="3.5"/>` },
            'sv-ellipse-link': { label: 'ÓVALO + LÍNEA', w: 70, h: 62, svg:
                `<ellipse cx="30" cy="40" rx="27" ry="38" fill="none" stroke="${FAM_PINK}" stroke-width="3.5"/>` +
                `<path d="M30 78 V100 H100" fill="none" stroke="${FAM_BLUE}" stroke-width="2"/>` },
            'sv-circ-link': { label: 'MUJER + LÍNEA', w: 55, h: 60, svg:
                `<line x1="50" y1="0" x2="50" y2="30" stroke="${FAM_BLUE}" stroke-width="2"/>` +
                `<ellipse cx="50" cy="65" rx="46" ry="33" fill="none" stroke="${FAM_PINK}" stroke-width="4"/>` },
            'sv-sq-bold-link': { label: 'HOMBRE + LÍNEAS', w: 45, h: 85, svg:
                `<line x1="50" y1="0" x2="50" y2="22" stroke="${FAM_BLUE}" stroke-width="2"/>` +
                `<rect x="4" y="22" width="92" height="56" fill="none" stroke="${FAM_BLUE}" stroke-width="4"/>` +
                `<line x1="50" y1="78" x2="50" y2="100" stroke="${FAM_BLUE}" stroke-width="2"/>` },
            'sv-sq-link': { label: 'HIJO CONECTADO', w: 60, h: 65, svg:
                `<rect x="35" y="2" width="60" height="55" fill="none" stroke="${FAM_BLUE}" stroke-width="4"/>` +
                `<path d="M50 57 V98 H0" fill="none" stroke="${FAM_BLUE}" stroke-width="2"/>` },
            'sv-tri-double': { label: 'TRIÁNGULO DOBLE', w: 50, h: 46, svg:
                `<path d="M50 4 L96 94 H4 Z" fill="none" stroke="${FAM_PURPLE}" stroke-width="3.5" stroke-linejoin="round"/>` +
                `<path d="M50 30 L75 78 H25 Z" fill="none" stroke="${FAM_PURPLE}" stroke-width="3.5" stroke-linejoin="round"/>` },
            'sv-ring-dash': { label: 'ANILLO + LÍNEA', w: 40, h: 70, svg:
                `<line x1="50" y1="0" x2="50" y2="30" stroke="${FAM_PURPLE}" stroke-width="1.5" stroke-dasharray="4 3"/>` +
                `<ellipse cx="50" cy="68" rx="46" ry="30" fill="#f1ecf8" stroke="${FAM_PURPLE}" stroke-width="3.5"/>` +
                `<ellipse cx="50" cy="68" rx="27" ry="17" fill="#fff" stroke="${FAM_PURPLE}" stroke-width="3"/>` },
            'sv-sq-ring-dash': { label: 'CUADRO DOBLE + LÍNEA', w: 45, h: 70, svg:
                `<line x1="50" y1="0" x2="50" y2="30" stroke="${FAM_PURPLE}" stroke-width="1.5" stroke-dasharray="4 3"/>` +
                `<rect x="4" y="30" width="92" height="66" fill="#f4f0fa" stroke="${FAM_PURPLE}" stroke-width="2"/>` +
                `<rect x="22" y="44" width="56" height="38" fill="#fff" stroke="${FAM_PURPLE}" stroke-width="2"/>` },

            'sv-ring-link': { label: 'ANILLO MORADO', w: 50, h: 85, svg:
                `<line x1="50" y1="0" x2="50" y2="28" stroke="${FAM_BLUE}" stroke-width="2"/>` +
                `<ellipse cx="50" cy="66" rx="46" ry="32" fill="none" stroke="${FAM_PURPLE}" stroke-width="4"/>` +
                `<ellipse cx="50" cy="66" rx="24" ry="16" fill="none" stroke="${FAM_PURPLE}" stroke-width="4"/>` },
            'sv-sq-double': { label: 'CUADRO DOBLE AZUL', w: 40, h: 70, svg:
                `<line x1="50" y1="0" x2="50" y2="28" stroke="${FAM_BLUE}" stroke-width="2"/>` +
                `<rect x="4" y="28" width="92" height="68" fill="none" stroke="${FAM_BLUE}" stroke-width="4"/>` +
                `<rect x="22" y="44" width="56" height="38" fill="none" stroke="${FAM_BLUE}" stroke-width="4"/>` },
            'sv-x-box': { label: 'CUADRO CON X', w: 45, h: 45, svg:
                `<rect x="6" y="6" width="88" height="88" rx="10" fill="#fff" stroke="${FAM_BLUE}" stroke-width="3.5"/>` +
                `<path d="M24 24 L76 76 M76 24 L24 76" stroke="#4f81bd" stroke-width="9" stroke-linecap="round"/>` },
            'sv-y': { label: 'Y (DESCENDENCIA)', w: 50, h: 45, svg:
                `<path d="M50 55 L8 12 M50 55 L92 12 M50 55 V100" fill="none" stroke="${FAM_BLUE}" stroke-width="2.5"/>` },
            'sv-twins': { label: 'GEMELAS', w: 80, h: 55, svg:
                `<path d="M50 0 V30 M50 30 L18 52 M50 30 L82 52" fill="none" stroke="${FAM_BLUE}" stroke-width="2.5"/>` +
                `<ellipse cx="18" cy="75" rx="17" ry="22" fill="none" stroke="${FAM_PINK}" stroke-width="3.5"/>` +
                `<ellipse cx="82" cy="75" rx="17" ry="22" fill="none" stroke="${FAM_PINK}" stroke-width="3.5"/>` },
            'sv-tree': { label: 'UNIÓN + HIJO', w: 160, h: 70, svg:
                `<path d="M0 25 H100 M50 25 V100" fill="none" stroke="${FAM_BLUE}" stroke-width="2.5"/>` },
            'sv-divorce': { label: 'DIVORCIO //', w: 160, h: 50, svg:
                `<path d="M0 50 H100 M38 30 L50 70 M52 30 L64 70" fill="none" stroke="${FAM_BLUE}" stroke-width="2.5"/>` },
            'sv-zigzag': { label: 'CONFLICTO ZIGZAG', w: 120, h: 30, svg: famZigzag() },
            'sv-triple': { label: 'FUSIÓN (3 LÍNEAS)', w: 120, h: 20, svg:
                `<path d="M0 15 H100 M0 50 H100 M0 85 H100" fill="none" stroke="${FAM_BLUE}" stroke-width="2"/>` },
            'sv-wavy': { label: 'CONFLICTO ONDULADO', w: 130, h: 40, svg: famWavy() },
            'sv-dash-v': { label: 'LÍNEA PUNTEADA', w: 6, h: 50, svg:
                `<line x1="50" y1="0" x2="50" y2="100" stroke="${FAM_PURPLE}" stroke-width="1.5" stroke-dasharray="4 3"/>` }
        };

        function famShapeSvg(shape) {
            return `<svg class="fam-shape-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none">${shape.svg}</svg>`;
        }

        function initFamExtraToolbar() {
            let bar = document.getElementById('famExtraToolbar');
            if (!bar || bar.querySelector('.tool-shape')) return;
            Object.keys(FAM_SHAPES).forEach(key => {
                let s = FAM_SHAPES[key];
                let scale = 30 / Math.max(s.w, s.h);
                let bw = Math.max(6, Math.round(s.w * scale)), bh = Math.max(6, Math.round(s.h * scale));
                let btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tool-btn tool-shape';
                btn.title = s.label;
                btn.onclick = function() { addFamElement(key); };
                btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="${bw}" height="${bh}" viewBox="0 0 100 100" preserveAspectRatio="none" class="fam-shape-svg">${s.svg}</svg> ${s.label}`;
                bar.appendChild(btn);
            });
        }


        function renderFamiliograma() {
            let canvas = document.getElementById('familiogramaCanvas');
            canvas.innerHTML = '';
            familiogramaElements.forEach((el, index) => {
                let div = document.createElement('div');
                let shapeDef = FAM_SHAPES[el.type];
                div.className = `fam-item fam-${el.type} ${shapeDef ? 'fam-svg' : ''} ${selectedFamIndex === index ? 'selected' : ''}`;
                div.style.left = (el.x || 10) + 'px';
                div.style.top = (el.y || 10) + 'px';
                if (el.w) div.style.width = el.w + 'px';
                if (el.h) div.style.height = el.h + 'px';
                
                div.dataset.index = index;
                div.title = 'ARRASTRE PARA MOVER · HAGA CLIC PARA SELECCIONAR';
                div.innerHTML = `<span class="fam-delete no-print" title="ELIMINAR SÍMBOLO" data-delete="1">&times;</span>` + (shapeDef ? famShapeSvg(shapeDef) : '');

                // Botón eliminar (sin iniciar arrastre)
                let delBtn = div.querySelector('.fam-delete');
                delBtn.onpointerdown = function(e) { e.stopPropagation(); };
                delBtn.onclick = function(e) {
                    e.stopPropagation();
                    removeFamElement(index);
                };

                // ARRASTRE CON POINTER EVENTS: no se vuelve a dibujar el lienzo mientras se mueve
                div.onpointerdown = function(e) {
                    if (e.button !== undefined && e.button !== 0) return;
                    e.preventDefault();
                    e.stopPropagation();
                    selectFamElement(index);

                    let startX = e.clientX;
                    let startY = e.clientY;
                    let initialX = el.x || 0;
                    let initialY = el.y || 0;
                    let moved = false;

                    try { div.setPointerCapture(e.pointerId); } catch (err) {}
                    div.classList.add('dragging');

                    div.onpointermove = function(ev) {
                        let dx = ev.clientX - startX;
                        let dy = ev.clientY - startY;
                        if (!moved && Math.abs(dx) < 3 && Math.abs(dy) < 3) return;
                        moved = true;

                        let maxX = Math.max(0, canvas.clientWidth - div.offsetWidth);
                        let maxY = Math.max(0, canvas.clientHeight - div.offsetHeight);
                        let newX = Math.min(Math.max(0, initialX + dx), maxX);
                        let newY = Math.min(Math.max(0, initialY + dy), maxY);

                        el.x = Math.round(newX);
                        el.y = Math.round(newY);
                        div.style.left = el.x + 'px';
                        div.style.top = el.y + 'px';
                    };

                    let finish = function(ev) {
                        div.onpointermove = null;
                        div.onpointerup = null;
                        div.onpointercancel = null;
                        div.classList.remove('dragging');
                        try { div.releasePointerCapture(ev.pointerId); } catch (err) {}
                    };
                    div.onpointerup = finish;
                    div.onpointercancel = finish;
                };

                canvas.appendChild(div);
            });
            updatePropsPanel();
        }

        // Selecciona un símbolo SIN redibujar el lienzo (evita el parpadeo y la pérdida del arrastre)
        function selectFamElement(index) {
            selectedFamIndex = index;
            document.querySelectorAll('#familiogramaCanvas .fam-item').forEach(node => {
                node.classList.toggle('selected', parseInt(node.dataset.index) === index);
            });
            updatePropsPanel();
        }

        function getFamNode(index) {
            return document.querySelector(`#familiogramaCanvas .fam-item[data-index="${index}"]`);
        }

        function updatePropsPanel() {
            let panel = document.getElementById('famPropsPanel');
            if (selectedFamIndex !== null && familiogramaElements[selectedFamIndex]) {
                panel.style.display = 'flex';
                let el = familiogramaElements[selectedFamIndex];
                document.getElementById('propWidth').value = el.w || 35;
                document.getElementById('propHeight').value = el.h || 35;
            } else {
                panel.style.display = 'none';
            }
        }

        function updateSelectedWidth(val) {
            let n = parseInt(val);
            if (!n || n < 2) return;
            if (selectedFamIndex !== null && familiogramaElements[selectedFamIndex]) {
                familiogramaElements[selectedFamIndex].w = n;
                let node = getFamNode(selectedFamIndex);
                if (node) node.style.width = n + 'px';
            }
        }

        function updateSelectedHeight(val) {
            let n = parseInt(val);
            if (!n || n < 2) return;
            if (selectedFamIndex !== null && familiogramaElements[selectedFamIndex]) {
                familiogramaElements[selectedFamIndex].h = n;
                let node = getFamNode(selectedFamIndex);
                if (node) node.style.height = n + 'px';
            }
        }

        function addFamElement(type) {
            let defaultW = type.includes('line') ? 80 : 35;
            let defaultH = type.includes('line') ? 10 : 35;
            if (FAM_SHAPES[type]) {
                defaultW = FAM_SHAPES[type].w;
                defaultH = FAM_SHAPES[type].h;
            }
            familiogramaElements.push({ type: type, x: 30, y: 30, w: defaultW, h: defaultH });
            selectedFamIndex = familiogramaElements.length - 1;
            renderFamiliograma();
        }

        function removeFamElement(index) {
            familiogramaElements.splice(index, 1);
            if (selectedFamIndex === index) selectedFamIndex = null;
            renderFamiliograma();
        }

        function clearFamCanvas() {
            if (confirm("¿DESEA LIMPIAR TODO EL FAMILIOGRAMA?")) {
                familiogramaElements = [];
                selectedFamIndex = null;
                renderFamiliograma();
            }
        }

        // Clic en el área vacía del lienzo: deseleccionar
        document.getElementById('familiogramaCanvas').onpointerdown = function(e) {
            if (e.target === this) {
                selectedFamIndex = null;
                document.querySelectorAll('#familiogramaCanvas .fam-item.selected').forEach(n => n.classList.remove('selected'));
                updatePropsPanel();
            }
        };

        // CURP AUTOCÁLCULO
        const curpEstados = {
            'AS': 'AGUASCALIENTES', 'BC': 'BAJA CALIFORNIA', 'BS': 'BAJA CALIFORNIA SUR', 'CC': 'CAMPECHE', 
            'CS': 'CHIAPAS', 'CH': 'CHIHUAHUA', 'DF': 'CIUDAD DE MÉXICO', 'CL': 'COAHUILA', 'CM': 'COLIMA', 
            'DG': 'DURANGO', 'GT': 'GUANAJUATO', 'GR': 'GUERRERO', 'HG': 'HIDALGO', 'JC': 'JALISCO', 
            'MC': 'ESTADO DE MÉXICO', 'MN': 'MICHOACÁN', 'MS': 'MORELOS', 'NT': 'NAYARIT', 'NL': 'NUEVO LEÓN', 
            'OC': 'OAXACA', 'PL': 'PUEBLA', 'QO': 'QUERÉTARO', 'QR': 'QUINTANA ROO', 'SP': 'SAN LUIS POTOSÍ', 
            'SL': 'SINALOA', 'SR': 'SONORA', 'TC': 'TABASCO', 'TS': 'TAMAULIPAS', 'TL': 'TLAXCALA', 
            'VZ': 'VERACRUZ', 'YN': 'YUCATÁN', 'ZS': 'ZACATECAS', 'NE': 'NACIDO EN EL EXTRANJERO'
        };

        function procesarCurp(curp) {
            if (curp.length < 18) return;
            let yy = parseInt(curp.substr(4, 2), 10);
            let mm = curp.substr(6, 2);
            let dd = curp.substr(8, 2);
            let anioActualDosDigitos = new Date().getFullYear() % 100;
            let siglo = (yy > anioActualDosDigitos + 2) ? '19' : '20';
            let anioCompleto = siglo + yy;

            if (!isNaN(yy) && mm >= '01' && mm <= '12' && dd >= '01' && dd <= '31') {
                let fechaNac = `${anioCompleto}-${mm}-${dd}`;
                document.getElementById('inputFechaNacimiento').value = fechaNac;
                let fechaNacimientoObj = new Date(fechaNac);
                let hoy = new Date();
                let edad = hoy.getFullYear() - fechaNacimientoObj.getFullYear();
                let m = hoy.getMonth() - fechaNacimientoObj.getMonth();
                if (m < 0 || (m === 0 && hoy.getDate() < fechaNacimientoObj.getDate())) {
                    edad--;
                }
                document.getElementById('inputEdadActual').value = edad >= 0 ? edad : '';
            }

            let claveEntidad = curp.substr(11, 2).toUpperCase();
            if (curpEstados[claveEntidad]) {
                let estadoSelect = document.getElementById('selectEstadoNacimiento');
                let nombreEstado = curpEstados[claveEntidad];
                for (let i = 0; i < estadoSelect.options.length; i++) {
                    if (estadoSelect.options[i].value === nombreEstado) {
                        estadoSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        function prepararEnvio() {
            document.getElementById('inputPersonasViveJson').value = JSON.stringify(personasViveList);
            document.getElementById('inputFamiliogramaJson').value = JSON.stringify(familiogramaElements);
            document.getElementById('formHistoriaClinica').submit();
        }

        window.onload = function() {
            renderPersonasVive();
            initFamExtraToolbar();
            renderFamiliograma();
            let curpVal = document.getElementById('inputCurp').value;
            if (curpVal.length === 18) {
                procesarCurp(curpVal);
            }
        };
    </script>
</body>
</html>