<?php
// ===================================================================
// ARCHIVO: generar_receta_ingreso.php
// Generación de receta institucional adaptada para Censo Hospitalario
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_censo = $_GET['id_censo'] ?? '';
if (empty($id_censo)) {
    die("ID de censo no especificado.");
}

// 1. Obtener catálogo de médicos con su universidad y cédula para el Select2
$cat_medicos = [];
try {
    $stmt_m = $pdo->query("SELECT * FROM medicos ORDER BY nombre ASC");
    while ($m = $stmt_m->fetch(PDO::FETCH_ASSOC)) {
        $nombre = trim($m['nombre'] ?? $m['nombres'] ?? '');
        $apaterno = trim($m['apaterno'] ?? '');
        $amaterno = trim($m['amaterno'] ?? '');
        $cedula = trim($m['cedula'] ?? $m['cedula_profesional'] ?? 'S/C');
        $universidad = trim($m['universidad'] ?? 'S/N');
        $nombre_completo = trim("$nombre $apaterno $amaterno");
        if (!empty($nombre_completo)) {
            $cat_medicos[] = [
                'texto' => mb_strtoupper("$nombre_completo - CÉDULA: $cedula", 'UTF-8'),
                'universidad' => mb_strtoupper($universidad, 'UTF-8')
            ];
        }
    }
} catch (Exception $e) {}

// 2. Endpoint AJAX para Select2 de medicamentos
if (isset($_GET['ajax_medicamentos'])) {
    header('Content-Type: application/json');
    $term = strtoupper(trim($_GET['q'] ?? ''));
    try {
        $stmt_med = $pdo->prepare("
            SELECT id_medicamento, clave_cuadro_basico, descripcion, SUM(existencia) as total_stock 
            FROM medicamentos 
            WHERE (descripcion ILIKE :term OR clave_cuadro_basico ILIKE :term) 
            GROUP BY id_medicamento, clave_cuadro_basico, descripcion 
            ORDER BY descripcion ASC LIMIT 30
        ");
        $stmt_med->execute(['term' => "%$term%"]);
        $resultados = [];
        while ($row = $stmt_med->fetch(PDO::FETCH_ASSOC)) {
            $resultados[] = [
                'id' => $row['descripcion'], 
                'text' => '[' . $row['clave_cuadro_basico'] . '] ' . $row['descripcion'] . ' (STOCK: ' . number_format($row['total_stock']) . ')',
                'clave' => $row['clave_cuadro_basico'],
                'id_medicamento' => $row['id_medicamento']
            ];
        }
        echo json_encode(['results' => $resultados]);
    } catch (Exception $e) {
        echo json_encode(['results' => []]);
    }
    exit();
}

try {
    // 3. Consultar datos del censo hospitalario y del paciente
    $sql = "SELECT c.*, p.domicilio, p.colonia, p.edad AS p_edad, p.sexo AS p_sexo, p.telefono as tel_paciente 
            FROM censo c 
            LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente 
            WHERE c.id_censo = :id_censo LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id_censo' => $id_censo]);
    $censo_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$censo_info) {
        die("No se encontró el registro de hospitalización especificado.");
    }

    $nombre_medico_raw = $censo_info['medico_tratante_actual'] ?? "DR. ASIGNADO AL SERVICIO";
    $cedula_profesional = "S/C";
    $universidad = "S/N";

    if (!empty($nombre_medico_raw)) {
        if (strpos($nombre_medico_raw, 'CÉDULA:') !== false) {
            $partes = explode("CÉDULA:", $nombre_medico_raw);
            $nombre_medico_raw = trim($partes[0], " -");
            if (isset($partes[1]) && trim($partes[1]) !== "") {
                $cedula_profesional = trim($partes[1]);
            }
        } else {
            $sql_med = "SELECT * FROM medicos WHERE nombre ILIKE :val OR CONCAT(nombre, ' ', apaterno, ' ', amaterno) ILIKE :val LIMIT 1";
            $stmt_med = $pdo->prepare($sql_med);
            $stmt_med->execute(['val' => '%' . $nombre_medico_raw . '%']);
            $med_info = $stmt_med->fetch(PDO::FETCH_ASSOC);

            if ($med_info) {
                $nombre = trim($med_info['nombre'] ?? $med_info['nombres'] ?? '');
                $apaterno = trim($med_info['apaterno'] ?? '');
                $amaterno = trim($med_info['amaterno'] ?? '');
                $ced_med = trim($med_info['cedula'] ?? $med_info['cedula_profesional'] ?? '');
                $univ_med = trim($med_info['universidad'] ?? 'S/N');

                $nombre_medico_raw = trim("$nombre $apaterno $amaterno");
                if (!empty($ced_med)) {
                    $cedula_profesional = $ced_med;
                }
                if (!empty($univ_med)) {
                    $universidad = $univ_med;
                }
            }
        }
    }

} catch (Exception $e) {
    die("Error en la base de datos: " . $e->getMessage());
}

$p = $_POST;
$enviado = $_SERVER['REQUEST_METHOD'] === 'POST';
$fecha_elaboracion = !empty($censo_info['fecha_ingreso']) ? date('d/m/Y', strtotime($censo_info['fecha_ingreso'])) : date('d/m/Y');
$folio = str_pad($censo_info['id_censo'], 6, '0', STR_PAD_LEFT);

$nombre_paciente = mb_strtoupper($p['nombre_paciente'] ?? ($censo_info['nombre_paciente'] ?? ''), 'UTF-8');
$domicilio_paciente = mb_strtoupper($p['domicilio_paciente'] ?? trim(($censo_info['domicilio'] ?? '') . ' ' . ($censo_info['colonia'] ?? '')), 'UTF-8');
if(empty(trim($domicilio_paciente))) { $domicilio_paciente = "DOMICILIO CONOCIDO / TABASCO"; }

$edad_paciente = !empty($p['edad_paciente']) ? $p['edad_paciente'] : (!empty($censo_info['edad']) ? $censo_info['edad'] : ($censo_info['p_edad'] ?? '0'));
$sexo_raw = !empty($p['sexo_paciente']) ? $p['sexo_paciente'] : (!empty($censo_info['sexo']) ? $censo_info['sexo'] : ($censo_info['p_sexo'] ?? '1'));

if ($sexo_raw === 'MASCULINO' || $sexo_raw === 'HOMBRE' || $sexo_raw === 'M' || $sexo_raw === '1') {
    $sexo_paciente = '1';
} else {
    $sexo_paciente = '2';
}

$medico_seleccionado = mb_strtoupper($p['medico_seleccionado'] ?? "$nombre_medico_raw - CÉDULA: $cedula_profesional", 'UTF-8');
$nombre_medico = $nombre_medico_raw;
$registro_ss = "S/N";

if (strpos($medico_seleccionado, 'CÉDULA:') !== false) {
    $partes_m = explode("CÉDULA:", $medico_seleccionado);
    $nombre_medico = trim($partes_m[0], " -");
    if (isset($partes_m[1]) && trim($partes_m[1]) !== "") {
        $cedula_profesional = trim($partes_m[1]);
    }
}

foreach ($cat_medicos as $med) {
    if ($med['texto'] === $medico_seleccionado) {
        $universidad = $med['universidad'];
        break;
    }
}

// 4. Procesamiento y Guardado en Base de Datos (`recetas_express`)
$guardado_exito = false;
if ($enviado && !empty($nombre_paciente)) {
    try {
        $med_1 = mb_strtoupper(trim($p['med_1'] ?? ''), 'UTF-8');
        $sol_1 = trim($p['sol_1'] ?? '0');
        $cve_1 = mb_strtoupper(trim($p['cve_1'] ?? ''), 'UTF-8');
        $uso_1 = mb_strtoupper(trim($p['uso_1'] ?? ''), 'UTF-8');
        $id_med_1 = !empty($p['id_med_1']) ? (int)$p['id_med_1'] : null;

        $med_2 = mb_strtoupper(trim($p['med_2'] ?? ''), 'UTF-8');
        $sol_2 = trim($p['sol_2'] ?? '0');
        $cve_2 = mb_strtoupper(trim($p['cve_2'] ?? ''), 'UTF-8');
        $uso_2 = mb_strtoupper(trim($p['uso_2'] ?? ''), 'UTF-8');
        $id_med_2 = !empty($p['id_med_2']) ? (int)$p['id_med_2'] : null;

        $med_3 = mb_strtoupper(trim($p['med_3'] ?? ''), 'UTF-8');
        $sol_3 = trim($p['sol_3'] ?? '0');
        $cve_3 = mb_strtoupper(trim($p['cve_3'] ?? ''), 'UTF-8');
        $uso_3 = mb_strtoupper(trim($p['uso_3'] ?? ''), 'UTF-8');
        $id_med_3 = !empty($p['id_med_3']) ? (int)$p['id_med_3'] : null;

        $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM recetas_express WHERE folio = :folio");
        $stmt_chk->execute(['folio' => $folio]);
        
        if ($stmt_chk->fetchColumn() > 0) {
            $stmt_upd = $pdo->prepare("
                UPDATE recetas_express SET 
                    nombre_paciente = :nom, edad_paciente = :edad, sexo_paciente = :sexo, 
                    domicilio_paciente = :dom, medico_tratante = :med, cedula_medico = :ced,
                    medicamento_1 = :m1, solicitada_1 = :s1, clave_1 = :c1, modo_uso_1 = :u1, id_medicamento_1 = :im1,
                    medicamento_2 = :m2, solicitada_2 = :s2, clave_2 = :c2, modo_uso_2 = :u2, id_medicamento_2 = :im2,
                    medicamento_3 = :m3, solicitada_3 = :s3, clave_3 = :c3, modo_uso_3 = :u3, id_medicamento_3 = :im3
                WHERE folio = :folio
            ");
            $stmt_upd->execute([
                'nom' => $nombre_paciente, 'edad' => $edad_paciente, 'sexo' => $sexo_paciente,
                'dom' => $domicilio_paciente, 'med' => $nombre_medico, 'ced' => $cedula_profesional,
                'm1' => $med_1, 's1' => $sol_1, 'c1' => $cve_1, 'u1' => $uso_1, 'im1' => $id_med_1,
                'm2' => $med_2, 's2' => $sol_2, 'c2' => $cve_2, 'u2' => $uso_2, 'im2' => $id_med_2,
                'm3' => $med_3, 's3' => $sol_3, 'c3' => $cve_3, 'u3' => $uso_3, 'im3' => $id_med_3,
                'folio' => $folio
            ]);
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO recetas_express (
                    folio, nombre_paciente, edad_paciente, sexo_paciente, domicilio_paciente, 
                    medico_tratante, cedula_medico, medicamento_1, solicitada_1, clave_1, modo_uso_1, id_medicamento_1,
                    medicamento_2, solicitada_2, clave_2, modo_uso_2, id_medicamento_2, medicamento_3, solicitada_3, clave_3, modo_uso_3, id_medicamento_3
                ) VALUES (
                    :folio, :nom, :edad, :sexo, :dom, :med, :ced, :m1, :s1, :c1, :u1, :im1, :m2, :s2, :c2, :u2, :im2, :m3, :s3, :c3, :u3, :im3
                )
            ");
            $stmt_ins->execute([
                'folio' => $folio, 'nom' => $nombre_paciente, 'edad' => $edad_paciente, 'sexo' => $sexo_paciente,
                'dom' => $domicilio_paciente, 'med' => $nombre_medico, 'ced' => $cedula_profesional,
                'm1' => $med_1, 's1' => $sol_1, 'c1' => $cve_1, 'u1' => $uso_1, 'im1' => $id_med_1,
                'm2' => $med_2, 's2' => $sol_2, 'c2' => $cve_2, 'u2' => $uso_2, 'im2' => $id_med_2,
                'm3' => $med_3, 's3' => $sol_3, 'c3' => $cve_3, 'u3' => $uso_3, 'im3' => $id_med_3
            ]);
        }
        $guardado_exito = true;
    } catch (Exception $e) {
        $error_db = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Receta Médica - Censo Hospitalario IMSS BIENESTAR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background-color: #333; font-family: 'Arial', sans-serif; margin: 0; padding: 20px; font-size: 11px; color: #000; text-transform: uppercase; }
        .sheet { width: 96%; max-width: 1400px; background: white; margin: 0 auto; padding: 20px 30px; box-sizing: border-box; box-shadow: 0 0 25px rgba(0,0,0,0.5); border-radius: 6px; }
        
        .receta-tabla { width: 100%; border-collapse: collapse; border: 1.5px solid #000; margin-bottom: 15px; background: #fff; table-layout: fixed; }
        .receta-tabla th, .receta-tabla td { border: 1px solid #000; padding: 5px 8px; vertical-align: middle; word-wrap: break-word; overflow: hidden; }
        
        .bg-gris { background-color: #d1d5db !important; }
        .lbl-title { font-size: 8px; font-weight: bold; color: #333; display: block; margin-bottom: 2px; }
        .val-data { font-size: 11px; font-weight: bold; min-height: 18px; word-break: break-word; }
        
        .input-editable { width: 100%; border: none; font-size: 11px; font-family: Arial, sans-serif; outline: none; background: transparent; padding: 2px 0; text-transform: uppercase; box-sizing: border-box; }
        .input-editable:focus { background-color: #fef08a; padding: 2px 4px; border-radius: 3px; }
        
        .form-control { width: 100%; padding: 8px; border: 2px solid #dfe3e8; border-radius: 6px; font-size: 13px; font-family: Arial; text-transform: uppercase; box-sizing: border-box; outline: none; background: #fff; }
        .form-control:focus { border-color: #006847; }

        /* Estilo Select2 sombreado */
        .select2-sombreado .select2-selection--single {
            background-color: #fef9c3 !important;
            border: 2px solid #ca8a04 !important;
            border-radius: 6px !important;
            height: 40px !important;
            box-shadow: 0 2px 5px rgba(202, 138, 4, 0.2);
        }
        .select2-sombreado .select2-selection__rendered {
            line-height: 38px !important;
            font-weight: bold !important;
            color: #713f12 !important;
        }

        .config-box { background: #f8f9fa; border: 2px dashed #006847; padding: 15px; border-radius: 8px; margin-bottom: 25px; }
        .no-print { text-align: center; margin-top: 30px; margin-bottom: 30px; }
        .btn { padding: 12px 25px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 15px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 3px 6px rgba(0,0,0,0.3); }
        .btn:hover { background-color: #005338; }

        .select2-container { width: 100% !important; }
        .receta-tabla .select2-container .select2-selection--single { height: 30px; border: none; border-radius: 0px; display: flex; align-items: center; background: transparent; padding: 0; }
        .receta-tabla .select2-container--default .select2-selection--single .select2-selection__rendered { font-size: 11px; font-family: Arial; text-transform: uppercase; padding-left: 0; line-height: 30px; color: #000; }
        .receta-tabla .select2-container--default .select2-selection--single .select2-selection__arrow { height: 28px; }

        @media print {
            body { background: none; padding: 0; font-size: 8px; }
            .sheet { width: 100%; max-width: none; box-shadow: none; padding: 4mm 8mm; margin: 0; border-radius: 0; }
            .no-print, .config-box { display: none; }
            .receta-tabla th, .receta-tabla td { padding: 3px 4px; }
            .lbl-title { font-size: 5.5px; }
            .val-data, .input-editable { font-size: 8.5px; }
            .select2-container { display: none !important; }
            .print-fallback { display: block !important; }
        }
        .print-fallback { display: none; font-size: 11px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="sheet">
        <?php if ($guardado_exito): ?>
            <div class="no-print" style="background: #e4f8dd; color: #006847; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> Receta hospitalaria registrada y enviada a farmacia correctamente con el folio: <?= $folio ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="folio" value="<?= $folio ?>">
            
            <div class="config-box no-print">
                <h3 style="margin-top:0; color:#006847;"><i class="fa-solid fa-user-doctor"></i> Configuración del Médico Tratante (Censo Hospitalario)</h3>
                <div style="margin-bottom: 15px;">
                    <label class="lbl-title" style="font-size: 11px; margin-bottom: 5px;">Seleccionar / Actualizar Médico Tratante:</label>
                    <select name="medico_seleccionado" id="selectMedico" class="form-control" required>
                        <option value="">-- SELECCIONAR MÉDICO --</option>
                        <?php foreach ($cat_medicos as $med): ?>
                            <option value="<?= htmlspecialchars($med['texto']); ?>" <?= ($medico_seleccionado === $med['texto']) ? 'selected' : ''; ?>><?= htmlspecialchars($med['texto']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 3fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label class="lbl-title">Nombre Completo del Paciente</label>
                        <input type="text" name="nombre_paciente" id="inputNombrePaciente" class="form-control" value="<?= htmlspecialchars($nombre_paciente); ?>" required>
                    </div>
                    <div>
                        <label class="lbl-title">Edad (Años)</label>
                        <input type="number" name="edad_paciente" id="inputEdadPaciente" class="form-control" value="<?= htmlspecialchars($edad_paciente); ?>" required>
                    </div>
                    <div>
                        <label class="lbl-title">Sexo (1=Hombre, 2=Mujer)</label>
                        <select name="sexo_paciente" id="inputSexoPaciente" class="form-control">
                            <option value="1" <?= ($sexo_paciente === '1') ? 'selected' : ''; ?>>1 - HOMBRE</option>
                            <option value="2" <?= ($sexo_paciente === '2') ? 'selected' : ''; ?>>2 - MUJER</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label class="lbl-title">Domicilio del Paciente</label>
                    <input type="text" name="domicilio_paciente" id="inputDomicilioPaciente" class="form-control" value="<?= htmlspecialchars($domicilio_paciente); ?>">
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn" style="background-color: #0284c7;"><i class="fa-solid fa-save"></i> Guardar y Actualizar Receta</button>
                </div>
            </div>

            <!-- ================= BLOQUE 1: FARMACIA ================= -->
            <table class="receta-tabla">
                <colgroup>
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 10%;">
                    <col style="width: 10%;">
                    <col style="width: 10%;">
                </colgroup>
                <tr>
                    <td colspan="4" style="font-weight: bold; font-size: 12px; padding: 6px;">IMSS BIENESTAR TABASCO 2024</td>
                    <td colspan="4" style="text-align: right; padding: 4px;">
                        <div style="display: inline-flex; align-items: center; gap: 10px;">
                            <img src="logo_gobierno.png" alt="Gobierno de México" style="height: 28px; max-width: 110px; object-fit: contain;">
                            <img src="logo_imss.png" alt="IMSS Bienestar" style="height: 28px; max-width: 110px; object-fit: contain;">
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan="6" style="text-align: center; font-weight: bold; font-size: 12px; background-color: #f3f4f6; padding: 6px;">RECETA MÉDICA (HOSPITALIZACIÓN)</td>
                    <td colspan="2" style="text-align: center; font-weight: bold; font-size: 11px; background-color: #e5e7eb;">
                        FOLIO: <span style="color: #9f2241;"><?= $folio; ?></span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2"><span class="lbl-title">UNIDAD MÉDICA</span><div class="val-data">HOSPITAL GENERAL DE HUIMANGUILLO</div></td>
                    <td colspan="2"><span class="lbl-title">CLUES</span><div class="val-data">TCIMB001870</div></td>
                    <td colspan="2"><span class="lbl-title">FECHA</span><div class="val-data"><?= $fecha_elaboracion; ?></div></td>
                    <td colspan="2"><span class="lbl-title">DOMICILIO ESTABLECIMIENTO</span><div class="val-data">SIMON SARLAT S/N</div></td>
                </tr>
                <tr>
                    <td colspan="6"><span class="lbl-title">NOMBRE COMPLETO DEL PACIENTE</span><div class="val-data"><?= htmlspecialchars($nombre_paciente); ?></div></td>
                    <td><span class="lbl-title">SEXO</span><div class="val-data"><?= htmlspecialchars($sexo_paciente); ?></div></td>
                    <td><span class="lbl-title">EDAD</span><div class="val-data"><?= htmlspecialchars($edad_paciente); ?></div></td>
                </tr>
                <tr>
                    <td colspan="8"><span class="lbl-title">DOMICILIO DEL PACIENTE</span><div class="val-data"><?= htmlspecialchars($domicilio_paciente); ?></div></td>
                </tr>
                <tr>
                    <td colspan="5" class="bg-gris" style="font-size: 8.5px; font-weight: bold; text-align: center;">Para llenado exclusivo de farmacia</td>
                    <td class="bg-gris" style="font-size: 7.5px; text-align: center; font-weight: bold;">SOLICITADA</td>
                    <td class="bg-gris" style="font-size: 7.5px; text-align: center; font-weight: bold;">SURTIDA</td>
                    <td class="bg-gris" style="font-size: 7.5px; text-align: center; font-weight: bold;">CLAVE</td>
                </tr>
                
                <!-- Medicamento 1 -->
                <tr>
                    <td colspan="5">
                        <span class="lbl-title">MEDICAMENTO 1</span>
                        <select name="med_1" id="med_1" class="select-medicamento" data-target-cve="cve_1" data-target-copia="med_1_copia" data-target-id="id_med_1">
                            <?php if(!empty($p['med_1'] ?? '')): ?>
                                <option value="<?= htmlspecialchars($p['med_1']) ?>" selected><?= htmlspecialchars($p['med_1']) ?></option>
                            <?php endif; ?>
                        </select>
                        <input type="hidden" name="id_med_1" id="id_med_1" value="<?= htmlspecialchars($p['id_med_1'] ?? ''); ?>">
                        <div class="print-fallback"><?= htmlspecialchars($p['med_1'] ?? '') ?></div>
                    </td>
                    <td><input type="number" id="sol_1" name="sol_1" min="0" step="1" class="input-editable campo-espejo input-solo-numeros" data-target="sol_1_copia" style="text-align: center;" value="<?= htmlspecialchars($p['sol_1'] ?? ''); ?>"></td>
                    <td><input type="text" id="sur_1" name="sur_1" class="input-editable campo-espejo" data-target="sur_1_copia" style="text-align: center; background-color: #f3f4f6; color: #6b7280;" value="<?= htmlspecialchars($p['sur_1'] ?? ''); ?>" readonly tabindex="-1"></td>
                    <td><input type="text" id="cve_1" name="cve_1" class="input-editable campo-espejo" data-target="cve_1_copia" style="text-align: center;" value="<?= htmlspecialchars($p['cve_1'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_1" name="uso_1" class="input-editable campo-espejo" data-target="uso_1_copia" value="<?= htmlspecialchars($p['uso_1'] ?? ''); ?>"></td>
                </tr>

                <!-- Medicamento 2 -->
                <tr>
                    <td colspan="5">
                        <span class="lbl-title">MEDICAMENTO 2</span>
                        <select name="med_2" id="med_2" class="select-medicamento" data-target-cve="cve_2" data-target-copia="med_2_copia" data-target-id="id_med_2">
                            <?php if(!empty($p['med_2'] ?? '')): ?>
                                <option value="<?= htmlspecialchars($p['med_2']) ?>" selected><?= htmlspecialchars($p['med_2']) ?></option>
                            <?php endif; ?>
                        </select>
                        <input type="hidden" name="id_med_2" id="id_med_2" value="<?= htmlspecialchars($p['id_med_2'] ?? ''); ?>">
                        <div class="print-fallback"><?= htmlspecialchars($p['med_2'] ?? '') ?></div>
                    </td>
                    <td><input type="number" id="sol_2" name="sol_2" min="0" step="1" class="input-editable campo-espejo input-solo-numeros" data-target="sol_2_copia" style="text-align: center;" value="<?= htmlspecialchars($p['sol_2'] ?? ''); ?>"></td>
                    <td><input type="text" id="sur_2" name="sur_2" class="input-editable campo-espejo" data-target="sur_2_copia" style="text-align: center; background-color: #f3f4f6; color: #6b7280;" value="<?= htmlspecialchars($p['sur_2'] ?? ''); ?>" readonly tabindex="-1"></td>
                    <td><input type="text" id="cve_2" name="cve_2" class="input-editable campo-espejo" data-target="cve_2_copia" style="text-align: center;" value="<?= htmlspecialchars($p['cve_2'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_2" name="uso_2" class="input-editable campo-espejo" data-target="uso_2_copia" value="<?= htmlspecialchars($p['uso_2'] ?? ''); ?>"></td>
                </tr>

                <!-- Medicamento 3 -->
                <tr>
                    <td colspan="5">
                        <span class="lbl-title">MEDICAMENTO 3</span>
                        <select name="med_3" id="med_3" class="select-medicamento" data-target-cve="cve_3" data-target-copia="med_3_copia" data-target-id="id_med_3">
                            <?php if(!empty($p['med_3'] ?? '')): ?>
                                <option value="<?= htmlspecialchars($p['med_3']) ?>" selected><?= htmlspecialchars($p['med_3']) ?></option>
                            <?php endif; ?>
                        </select>
                        <input type="hidden" name="id_med_3" id="id_med_3" value="<?= htmlspecialchars($p['id_med_3'] ?? ''); ?>">
                        <div class="print-fallback"><?= htmlspecialchars($p['med_3'] ?? '') ?></div>
                    </td>
                    <td><input type="number" id="sol_3" name="sol_3" min="0" step="1" class="input-editable campo-espejo input-solo-numeros" data-target="sol_3_copia" style="text-align: center;" value="<?= htmlspecialchars($p['sol_3'] ?? ''); ?>"></td>
                    <td><input type="text" id="sur_3" name="sur_3" class="input-editable campo-espejo" data-target="sur_3_copia" style="text-align: center; background-color: #f3f4f6; color: #6b7280;" value="<?= htmlspecialchars($p['sur_3'] ?? ''); ?>" readonly tabindex="-1"></td>
                    <td><input type="text" id="cve_3" name="cve_3" class="input-editable campo-espejo" data-target="cve_3_copia" style="text-align: center;" value="<?= htmlspecialchars($p['cve_3'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_3" name="uso_3" class="input-editable campo-espejo" data-target="uso_3_copia" value="<?= htmlspecialchars($p['uso_3'] ?? ''); ?>"></td>
                </tr>

                <tr>
                    <td colspan="2"><span class="lbl-title">MÉDICO</span><div class="val-data"><?= htmlspecialchars($nombre_medico); ?></div></td>
                    <td colspan="2"><span class="lbl-title">CÉDULA</span><div class="val-data"><?= htmlspecialchars($cedula_profesional); ?></div></td>
                    <td colspan="2"><span class="lbl-title">UNIVERSIDAD</span><div class="val-data"><?= htmlspecialchars($universidad); ?></div></td>
                    <td colspan="2"><span class="lbl-title">REGISTRO S.S.</span><div class="val-data"><?= htmlspecialchars($registro_ss); ?></div></td>
                </tr>
                <tr>
                    <td colspan="3" class="bg-gris" style="height: 35px; text-align: center;"><span class="lbl-title">FIRMA MÉDICO</span></td>
                    <td colspan="3" class="bg-gris" style="height: 35px; text-align: center;"><span class="lbl-title">FIRMA RECIBIDO</span></td>
                    <td colspan="2" class="bg-gris" style="height: 35px; text-align: center;"><span class="lbl-title">FECHA</span></td>
                </tr>
            </table>
            <div style="text-align: right; font-weight: bold; font-size: 9px; margin-bottom: 20px;">Farmacia</div>

            <!-- ================= BLOQUE 2: COPIA PACIENTE ================= -->
            <table class="receta-tabla">
                <colgroup>
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 10%;">
                    <col style="width: 10%;">
                    <col style="width: 10%;">
                </colgroup>
                <tr>
                    <td colspan="4" style="font-weight: bold; font-size: 12px; padding: 6px;">IMSS BIENESTAR TABASCO 2024</td>
                    <td colspan="4" style="text-align: right; padding: 4px;">
                        <div style="display: inline-flex; align-items: center; gap: 10px;">
                            <img src="logo_gobierno.png" alt="Gobierno de México" style="height: 28px; max-width: 110px; object-fit: contain;">
                            <img src="logo_imss.png" alt="IMSS Bienestar" style="height: 28px; max-width: 110px; object-fit: contain;">
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan="6" style="text-align: center; font-weight: bold; font-size: 12px; background-color: #f3f4f6; padding: 6px;">RECETA MÉDICA (HOSPITALIZACIÓN)</td>
                    <td colspan="2" style="text-align: center; font-weight: bold; font-size: 11px; background-color: #e5e7eb;">
                        FOLIO: <span style="color: #9f2241;"><?= $folio; ?></span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2"><span class="lbl-title">UNIDAD MÉDICA</span><div class="val-data">HOSPITAL GENERAL DE HUIMANGUILLO</div></td>
                    <td colspan="2"><span class="lbl-title">CLUES</span><div class="val-data">TCIMB001870</div></td>
                    <td colspan="2"><span class="lbl-title">FECHA</span><div class="val-data"><?= $fecha_elaboracion; ?></div></td>
                    <td colspan="2"><span class="lbl-title">DOMICILIO ESTABLECIMIENTO</span><div class="val-data">SIMON SARLAT S/N</div></td>
                </tr>
                <tr>
                    <td colspan="6"><span class="lbl-title">NOMBRE COMPLETO DEL PACIENTE</span><div class="val-data"><?= htmlspecialchars($nombre_paciente); ?></div></td>
                    <td><span class="lbl-title">SEXO</span><div class="val-data"><?= htmlspecialchars($sexo_paciente); ?></div></td>
                    <td><span class="lbl-title">EDAD</span><div class="val-data"><?= htmlspecialchars($edad_paciente); ?></div></td>
                </tr>
                <tr>
                    <td colspan="8"><span class="lbl-title">DOMICILIO DEL PACIENTE</span><div class="val-data"><?= htmlspecialchars($domicilio_paciente); ?></div></td>
                </tr>

                <tr>
                    <td colspan="8"><span class="lbl-title">MEDICAMENTO 1</span><input type="text" id="med_1_copia" name="med_1_copia" class="input-editable" value="<?= htmlspecialchars($p['med_1'] ?? ''); ?>" readonly></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_1_copia" name="uso_1_copia" class="input-editable" value="<?= htmlspecialchars($p['uso_1'] ?? ''); ?>" readonly></td>
                </tr>

                <tr>
                    <td colspan="8"><span class="lbl-title">MEDICAMENTO 2</span><input type="text" id="med_2_copia" name="med_2_copia" class="input-editable" value="<?= htmlspecialchars($p['med_2'] ?? ''); ?>" readonly></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_2_copia" name="uso_2_copia" class="input-editable" value="<?= htmlspecialchars($p['uso_2'] ?? ''); ?>" readonly></td>
                </tr>

                <tr>
                    <td colspan="8"><span class="lbl-title">MEDICAMENTO 3</span><input type="text" id="med_3_copia" name="med_3_copia" class="input-editable" value="<?= htmlspecialchars($p['med_3'] ?? ''); ?>" readonly></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_3_copia" name="uso_3_copia" class="input-editable" value="<?= htmlspecialchars($p['uso_3'] ?? ''); ?>" readonly></td>
                </tr>

                <tr>
                    <td colspan="2"><span class="lbl-title">MÉDICO</span><div class="val-data"><?= htmlspecialchars($nombre_medico); ?></div></td>
                    <td colspan="2"><span class="lbl-title">CÉDULA</span><div class="val-data"><?= htmlspecialchars($cedula_profesional); ?></div></td>
                    <td colspan="2"><span class="lbl-title">UNIVERSIDAD</span><div class="val-data"><?= htmlspecialchars($universidad); ?></div></td>
                    <td colspan="2"><span class="lbl-title">REGISTRO S.S.</span><div class="val-data"><?= htmlspecialchars($registro_ss); ?></div></td>
                </tr>
                <tr>
                    <td colspan="3" class="bg-gris" style="height: 35px; text-align: center;"><span class="lbl-title">FIRMA MÉDICO</span></td>
                    <td colspan="3" class="bg-gris" style="height: 35px; text-align: center;"><span class="lbl-title">FIRMA RECIBIDO</span></td>
                    <td colspan="2" class="bg-gris" style="height: 35px; text-align: center;"><span class="lbl-title">FECHA</span></td>
                </tr>
            </table>
            <div style="text-align: right; font-weight: bold; font-size: 9px;">Copia paciente</div>

            <!-- Botones de Acción -->
            <div class="no-print">
                <button type="submit" class="btn" style="background-color: #0284c7;"><i class="fa-solid fa-save"></i> Guardar y Actualizar Receta</button>
                <button type="button" onclick="window.print()" class="btn" style="margin-left: 10px;"><i class="fa-solid fa-print"></i> Imprimir Receta Oficial</button>
                <a href="ingresos.php" class="btn" style="background-color: #637381; margin-left: 10px;"><i class="fa-solid fa-arrow-left"></i> Regresar al Censo</a>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Inicializar Select2 con estilo sombreado para el médico
            $('#selectMedico').select2();
            $('#selectMedico').next('.select2-container').addClass('select2-sombreado');
            $('#selectMedico').on('select2:open', function () {
                document.querySelector('.select2-search__field').focus();
            });

            // Configurar Select2 de medicamentos con buscador AJAX y tags libres
            $('.select-medicamento').each(function() {
                let $this =$(this);
                let targetCve = $this.attr('data-target-cve');
                let targetId = $this.attr('data-target-id');
                let targetCopia = $this.attr('data-target');

                $this.select2({
                    tags: true,
                    placeholder: 'BUSCAR O ESCRIBIR MEDICAMENTO...',
                    allowClear: true,
                    ajax: {
                        url: 'generar_receta_ingreso.php?id_censo=<?= $id_censo ?>&ajax_medicamentos=1',
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return { q: params.term };
                        },
                        processResults: function (data) {
                            return { results: data.results };
                        },
                        cache: true
                    }
                }).on('select2:open', function () {
                    document.querySelector('.select2-search__field').focus();
                }).on('select2:select', function (e) {
                    let data = e.params.data;
                    if (data.clave && targetCve) {
                        $('#' + targetCve).val(data.clave);
                    }
                    if (data.id_medicamento && targetId) {
                        $('#' + targetId).val(data.id_medicamento);
                    }
                    let descText = data.text;
                    if (targetCopia) {
                        $('#' + targetCopia).val(descText.toUpperCase());
                    }
                }).on('change', function() {
                    let val = $this.val() || '';
                    if (targetCopia) {
                        $('#' + targetCopia).val(val.toUpperCase());
                    }
                });
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const inputsNum = document.querySelectorAll('.input-solo-numeros');
            inputsNum.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            });

            const camposEspejo = document.querySelectorAll('.campo-espejo');
            camposEspejo.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                    const targetId = this.getAttribute('data-target');
                    if (targetId) {
                        const targetElement = document.getElementById(targetId);
                        if (targetElement) {
                            targetElement.value = this.value;
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>