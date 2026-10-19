<?php
// ===================================================================
// ARCHIVO: receta_express.php
// Módulo integrado de Receta Médica Express para Consulta Express
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

// Obtener ID del paciente y médico si vienen por GET
$id_paciente = intval($_GET['id_paciente'] ?? 0);
$medico_get = trim($_GET['medico'] ?? '');
$paciente_info = null;

if ($id_paciente > 0) {
    try {
        $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id LIMIT 1");
        $stmtP->execute(['id' => $id_paciente]);
        $paciente_info = $stmtP->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$p = $_POST;
$folio = rand(100000, 999999);
$fecha_elaboracion = date('d/m/Y');

// Definir valores del paciente prioritariamente desde la base de datos (según id_paciente)
$nombre_paciente = mb_strtoupper($paciente_info ? trim(($paciente_info['nombre'] ?? '') . ' ' . ($paciente_info['apaterno'] ?? '') . ' ' . ($paciente_info['amaterno'] ?? '')) : ($p['nombre_paciente'] ?? ''), 'UTF-8');
$edad_paciente = mb_strtoupper($paciente_info ? ($paciente_info['edad_actual'] ?? $paciente_info['edad'] ?? '0') : ($p['edad_paciente'] ?? '0'), 'UTF-8');

$sexo_bd = strtoupper($paciente_info['sexo'] ?? '');
if (str_contains($sexo_bd, 'FEM') || $sexo_bd === '2' || $sexo_bd === 'F') {
    $sexo_paciente = '2'; // MUJER
} else {
    $sexo_paciente = '1'; // HOMBRE
}

$domicilio_paciente = mb_strtoupper($paciente_info ? trim(($paciente_info['domicilio'] ?? '') . ', ' . ($paciente_info['colonia'] ?? '')) : ($p['domicilio_paciente'] ?? 'DOMICILIO CONOCIDO'), 'UTF-8');

// Médico recibido por GET o POST
$medico_seleccionado = mb_strtoupper($p['medico_seleccionado'] ?? $medico_get, 'UTF-8');

// Extraer cédula del string del médico seleccionado
$cedula_profesional = "S/C";
$nombre_medico = $medico_seleccionado;
if (strpos($medico_seleccionado, 'CÉDULA:') !== false) {
    $partes = explode("CÉDULA:", $medico_seleccionado);
    $nombre_medico = trim($partes[0], " -");
    if (isset($partes[1]) && trim($partes[1]) !== "") {
        $cedula_profesional = trim($partes[1]);
    }
}

// Guardar en base de datos al emitir/imprimir
$mensaje_exito = "";
$error_bd = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_receta'])) {
    try {
        $stmtIns = $pdo->prepare("
            INSERT INTO recetas_express (
                folio, id_paciente, nombre_paciente, edad_paciente, sexo_paciente, domicilio_paciente, 
                medico_tratante, cedula_medico, medicamento_1, solicitada_1, surtida_1, clave_1, modo_uso_1,
                medicamento_2, solicitada_2, surtida_2, clave_2, modo_uso_2,
                medicamento_3, solicitada_3, surtida_3, clave_3, modo_uso_3
            ) VALUES (
                :folio, :id_pac, :nom_pac, :edad_pac, :sexo_pac, :dom_pac, 
                :med_trat, :ced_med, :m1, :s1, :su1, :c1, :u1,
                :m2, :s2, :su2, :c2, :u2,
                :m3, :s3, :su3, :c3, :u3
            )
        ");
        $stmtIns->execute([
            'folio'    => $p['folio_oculto'] ?? $folio,
            'id_pac'   => $id_paciente > 0 ? $id_paciente : null,
            'nom_pac'  => $nombre_paciente,
            'edad_pac' => $edad_paciente,
            'sexo_pac' => $sexo_paciente,
            'dom_pac'  => $domicilio_paciente,
            'med_trat' => $nombre_medico,
            'ced_med'  => $cedula_profesional,
            'm1'       => $p['med_1'] ?? '', 's1' => $p['sol_1'] ?? '', 'su1' => $p['sur_1'] ?? '', 'c1' => $p['cve_1'] ?? '', 'u1' => $p['uso_1'] ?? '',
            'm2'       => $p['med_2'] ?? '', 's2' => $p['sol_2'] ?? '', 'su2' => $p['sur_2'] ?? '', 'c2' => $p['cve_2'] ?? '', 'u2' => $p['uso_2'] ?? '',
            'm3'       => $p['med_3'] ?? '', 's3' => $p['sol_3'] ?? '', 'su3' => $p['sur_3'] ?? '', 'c3' => $p['cve_3'] ?? '', 'u3' => $p['uso_3'] ?? ''
        ]);
        $mensaje_exito = "¡Receta guardada y registrada con éxito en el sistema!";
    } catch (Exception $e) {
        $error_bd = "Error al guardar la receta: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>IMSS-BIENESTAR | Receta Médica Express</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #333; font-family: 'Arial', sans-serif; margin: 0; padding: 15px; font-size: 11px; color: #000; text-transform: uppercase; }
        .sheet { width: 100%; max-width: 1700px; background: white; margin: 0 auto; padding: 20px 25px; box-sizing: border-box; box-shadow: 0 0 25px rgba(0,0,0,0.5); border-radius: 6px; }
        
        .receta-tabla { width: 100%; border-collapse: collapse; border: 1.5px solid #000; margin-bottom: 15px; background: #fff; }
        .receta-tabla th, .receta-tabla td { border: 1px solid #000; padding: 6px 10px; vertical-align: middle; }
        
        .bg-gris { background-color: #d1d5db !important; }
        .lbl-title { font-size: 8.5px; font-weight: bold; color: #333; display: block; margin-bottom: 2px; }
        .val-data { font-size: 11.5px; font-weight: bold; min-height: 18px; }
        
        .input-editable { width: 100%; border: none; font-size: 11.5px; font-family: Arial, sans-serif; outline: none; background: transparent; padding: 3px 0; text-transform: uppercase; box-sizing: border-box; }
        .input-editable:focus { background-color: #fef08a; padding: 3px 6px; border-radius: 3px; }
        
        .no-print { text-align: center; margin-top: 20px; margin-bottom: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .btn { padding: 12px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 3px 6px rgba(0,0,0,0.2); }
        .btn:hover { background-color: #005338; }

        @media print {
            body { background: none; padding: 0; font-size: 8.5px; }
            .sheet { width: 100%; max-width: none; box-shadow: none; padding: 4mm 8mm; margin: 0; border-radius: 0; }
            .no-print { display: none !important; }
            .receta-tabla th, .receta-tabla td { padding: 3px 4px; }
            .lbl-title { font-size: 6px; }
            .val-data, .input-editable { font-size: 9px; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <?php if (!empty($mensaje_exito)): ?>
            <div class="no-print" style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; font-weight: bold; margin-bottom: 15px;">
                <i class="fa-solid fa-circle-check"></i> <?php echo $mensaje_exito; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_bd)): ?>
            <div class="no-print" style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; font-weight: bold; margin-bottom: 15px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error_bd; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="folio_oculto" value="<?php echo $folio; ?>">
            <input type="hidden" name="medico_seleccionado" value="<?php echo htmlspecialchars($medico_seleccionado); ?>">
            <input type="hidden" name="nombre_paciente" value="<?php echo htmlspecialchars($nombre_paciente); ?>">
            <input type="hidden" name="edad_paciente" value="<?php echo htmlspecialchars($edad_paciente); ?>">
            <input type="hidden" name="sexo_paciente" value="<?php echo htmlspecialchars($sexo_paciente); ?>">
            <input type="hidden" name="domicilio_paciente" value="<?php echo htmlspecialchars($domicilio_paciente); ?>">

            <!-- ================= BLOQUE 1: FARMACIA ================= -->
            <table class="receta-tabla">
                <tr>
                    <td colspan="4" style="font-weight: bold; font-size: 13px; padding: 6px;">IMSS BIENESTAR TABASCO 2026</td>
                    <td colspan="4" style="text-align: right; padding: 4px;">
                        <div style="display: inline-flex; align-items: center; gap: 10px;">
                            <span style="font-weight: bold; font-size: 11px; color: #555;">GOBIERNO DE MÉXICO</span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan="6" style="text-align: center; font-weight: bold; font-size: 13px; background-color: #f3f4f6; padding: 6px;">RECETA MÉDICA EXPRESS</td>
                    <td colspan="2" style="text-align: center; font-weight: bold; font-size: 12px; background-color: #e5e7eb;">
                        FOLIO: <span style="color: #9f2241;"><?php echo $folio; ?></span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2"><span class="lbl-title">UNIDAD MÉDICA</span><div class="val-data">HOSPITAL GENERAL DE HUIMANGUILLO</div></td>
                    <td colspan="2"><span class="lbl-title">CLUES</span><div class="val-data">TCIMB001870</div></td>
                    <td colspan="2"><span class="lbl-title">FECHA</span><div class="val-data"><?php echo $fecha_elaboracion; ?></div></td>
                    <td colspan="2"><span class="lbl-title">DOMICILIO ESTABLECIMIENTO</span><div class="val-data">SIMON SARLAT S/N</div></td>
                </tr>
                <tr>
                    <td colspan="6"><span class="lbl-title">NOMBRE COMPLETO DEL PACIENTE</span><div class="val-data"><?php echo htmlspecialchars($nombre_paciente ?: 'SIN PACIENTE ASIGNADO'); ?></div></td>
                    <td><span class="lbl-title">SEXO</span><div class="val-data"><?php echo htmlspecialchars($sexo_paciente); ?></div></td>
                    <td><span class="lbl-title">EDAD</span><div class="val-data"><?php echo htmlspecialchars($edad_paciente); ?></div></td>
                </tr>
                <tr>
                    <td colspan="8"><span class="lbl-title">DOMICILIO DEL PACIENTE</span><div class="val-data"><?php echo htmlspecialchars($domicilio_paciente); ?></div></td>
                </tr>
                <tr>
                    <td colspan="5" class="bg-gris" style="font-size: 9px; font-weight: bold; text-align: center;">Para llenado exclusivo de farmacia</td>
                    <td class="bg-gris" style="font-size: 8px; text-align: center; font-weight: bold;">SOLICITADA</td>
                    <td class="bg-gris" style="font-size: 8px; text-align: center; font-weight: bold;">SURTIDA</td>
                    <td class="bg-gris" style="font-size: 8px; text-align: center; font-weight: bold;">CLAVE</td>
                </tr>
                
                <!-- Medicamento 1 -->
                <tr>
                    <td colspan="5"><span class="lbl-title">MEDICAMENTO 1</span><input type="text" id="med_1" name="med_1" class="input-editable campo-espejo" data-target="med_1_copia" value="<?php echo htmlspecialchars(mb_strtoupper($p['med_1'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="sol_1" name="sol_1" class="input-editable campo-espejo" data-target="sol_1_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['sol_1'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="sur_1" name="sur_1" class="input-editable campo-espejo" data-target="sur_1_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['sur_1'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="cve_1" name="cve_1" class="input-editable campo-espejo" data-target="cve_1_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['cve_1'] ?? '', 'UTF-8')); ?>"></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_1" name="uso_1" class="input-editable campo-espejo" data-target="uso_1_copia" value="<?php echo htmlspecialchars(mb_strtoupper($p['uso_1'] ?? '', 'UTF-8')); ?>"></td>
                </tr>

                <!-- Medicamento 2 -->
                <tr>
                    <td colspan="5"><span class="lbl-title">MEDICAMENTO 2</span><input type="text" id="med_2" name="med_2" class="input-editable campo-espejo" data-target="med_2_copia" value="<?php echo htmlspecialchars(mb_strtoupper($p['med_2'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="sol_2" name="sol_2" class="input-editable campo-espejo" data-target="sol_2_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['sol_2'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="sur_2" name="sur_2" class="input-editable campo-espejo" data-target="sur_2_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['sur_2'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="cve_2" name="cve_2" class="input-editable campo-espejo" data-target="cve_2_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['cve_2'] ?? '', 'UTF-8')); ?>"></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_2" name="uso_2" class="input-editable campo-espejo" data-target="uso_2_copia" value="<?php echo htmlspecialchars(mb_strtoupper($p['uso_2'] ?? '', 'UTF-8')); ?>"></td>
                </tr>

                <!-- Medicamento 3 -->
                <tr>
                    <td colspan="5"><span class="lbl-title">MEDICAMENTO 3</span><input type="text" id="med_3" name="med_3" class="input-editable campo-espejo" data-target="med_3_copia" value="<?php echo htmlspecialchars(mb_strtoupper($p['med_3'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="sol_3" name="sol_3" class="input-editable campo-espejo" data-target="sol_3_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['sol_3'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="sur_3" name="sur_3" class="input-editable campo-espejo" data-target="sur_3_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['sur_3'] ?? '', 'UTF-8')); ?>"></td>
                    <td><input type="text" id="cve_3" name="cve_3" class="input-editable campo-espejo" data-target="cve_3_copia" style="text-align: center;" value="<?php echo htmlspecialchars(mb_strtoupper($p['cve_3'] ?? '', 'UTF-8')); ?>"></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_3" name="uso_3" class="input-editable campo-espejo" data-target="uso_3_copia" value="<?php echo htmlspecialchars(mb_strtoupper($p['uso_3'] ?? '', 'UTF-8')); ?>"></td>
                </tr>

                <tr>
                    <td colspan="2"><span class="lbl-title">MÉDICO</span><div class="val-data"><?php echo htmlspecialchars($nombre_medico); ?></div></td>
                    <td colspan="2"><span class="lbl-title">CÉDULA</span><div class="val-data"><?php echo htmlspecialchars($cedula_profesional); ?></div></td>
                    <td colspan="2"><span class="lbl-title">UNIVERSIDAD</span><div class="val-data">S/N</div></td>
                    <td colspan="2"><span class="lbl-title">REGISTRO S.S.</span><div class="val-data">S/N</div></td>
                </tr>
                <tr>
                    <td colspan="3" class="bg-gris" style="height: 40px; text-align: center;"><span class="lbl-title">FIRMA MÉDICO</span></td>
                    <td colspan="3" class="bg-gris" style="height: 40px; text-align: center;"><span class="lbl-title">FIRMA RECIBIDO</span></td>
                    <td colspan="2" class="bg-gris" style="height: 40px; text-align: center;"><span class="lbl-title">FECHA</span></td>
                </tr>
            </table>
            <div style="text-align: right; font-weight: bold; font-size: 10px; margin-bottom: 25px;">Farmacia</div>

            <!-- ================= BLOQUE 2: COPIA PACIENTE ================= -->
            <table class="receta-tabla">
                <tr>
                    <td colspan="4" style="font-weight: bold; font-size: 13px; padding: 6px;">IMSS BIENESTAR TABASCO 2026</td>
                    <td colspan="4" style="text-align: right; padding: 4px;">
                        <span style="font-weight: bold; font-size: 11px; color: #555;">GOBIERNO DE MÉXICO</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="6" style="text-align: center; font-weight: bold; font-size: 13px; background-color: #f3f4f6; padding: 6px;">RECETA MÉDICA EXPRESS</td>
                    <td colspan="2" style="text-align: center; font-weight: bold; font-size: 12px; background-color: #e5e7eb;">
                        FOLIO: <span style="color: #9f2241;"><?php echo $folio; ?></span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2"><span class="lbl-title">UNIDAD MÉDICA</span><div class="val-data">HOSPITAL GENERAL DE HUIMANGUILLO</div></td>
                    <td colspan="2"><span class="lbl-title">CLUES</span><div class="val-data">TCIMB001870</div></td>
                    <td colspan="2"><span class="lbl-title">FECHA</span><div class="val-data"><?php echo $fecha_elaboracion; ?></div></td>
                    <td colspan="2"><span class="lbl-title">DOMICILIO ESTABLECIMIENTO</span><div class="val-data">SIMON SARLAT S/N</div></td>
                </tr>
                <tr>
                    <td colspan="6"><span class="lbl-title">NOMBRE COMPLETO DEL PACIENTE</span><div class="val-data"><?php echo htmlspecialchars($nombre_paciente ?: 'SIN PACIENTE ASIGNADO'); ?></div></td>
                    <td><span class="lbl-title">SEXO</span><div class="val-data"><?php echo htmlspecialchars($sexo_paciente); ?></div></td>
                    <td><span class="lbl-title">EDAD</span><div class="val-data"><?php echo htmlspecialchars($edad_paciente); ?></div></td>
                </tr>
                <tr>
                    <td colspan="8"><span class="lbl-title">DOMICILIO DEL PACIENTE</span><div class="val-data"><?php echo htmlspecialchars($domicilio_paciente); ?></div></td>
                </tr>

                <tr>
                    <td colspan="8"><span class="lbl-title">MEDICAMENTO 1</span><input type="text" id="med_1_copia" class="input-editable" value="<?php echo htmlspecialchars(mb_strtoupper($p['med_1'] ?? '', 'UTF-8')); ?>" readonly></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_1_copia" class="input-editable" value="<?php echo htmlspecialchars(mb_strtoupper($p['uso_1'] ?? '', 'UTF-8')); ?>" readonly></td>
                </tr>

                <tr>
                    <td colspan="8"><span class="lbl-title">MEDICAMENTO 2</span><input type="text" id="med_2_copia" class="input-editable" value="<?php echo htmlspecialchars(mb_strtoupper($p['med_2'] ?? '', 'UTF-8')); ?>" readonly></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_2_copia" class="input-editable" value="<?php echo htmlspecialchars(mb_strtoupper($p['uso_2'] ?? '', 'UTF-8')); ?>" readonly></td>
                </tr>

                <tr>
                    <td colspan="8"><span class="lbl-title">MEDICAMENTO 3</span><input type="text" id="med_3_copia" class="input-editable" value="<?php echo htmlspecialchars(mb_strtoupper($p['med_3'] ?? '', 'UTF-8')); ?>" readonly></td>
                </tr>
                <tr>
                    <td colspan="8" style="background-color: #f9fafb;"><span class="lbl-title">MODO DE USO</span><input type="text" id="uso_3_copia" class="input-editable" value="<?php echo htmlspecialchars(mb_strtoupper($p['uso_3'] ?? '', 'UTF-8')); ?>" readonly></td>
                </tr>

                <tr>
                    <td colspan="2"><span class="lbl-title">MÉDICO</span><div class="val-data"><?php echo htmlspecialchars($nombre_medico); ?></div></td>
                    <td colspan="2"><span class="lbl-title">CÉDULA</span><div class="val-data"><?php echo htmlspecialchars($cedula_profesional); ?></div></td>
                    <td colspan="2"><span class="lbl-title">UNIVERSIDAD</span><div class="val-data">S/N</div></td>
                    <td colspan="2"><span class="lbl-title">REGISTRO S.S.</span><div class="val-data">S/N</div></td>
                </tr>
                <tr>
                    <td colspan="3" class="bg-gris" style="height: 40px; text-align: center;"><span class="lbl-title">FIRMA MÉDICO</span></td>
                    <td colspan="3" class="bg-gris" style="height: 40px; text-align: center;"><span class="lbl-title">FIRMA RECIBIDO</span></td>
                    <td colspan="2" class="bg-gris" style="height: 40px; text-align: center;"><span class="lbl-title">FECHA</span></td>
                </tr>
            </table>
            <div style="text-align: right; font-weight: bold; font-size: 10px;">Copia paciente</div>

            <!-- Botones de AcciónInferiores -->
            <div class="no-print">
                <button type="submit" name="guardar_receta" value="1" onclick="window.print()" class="btn" style="background-color: #006847;">
                    <i class="fa-solid fa-print"></i> Guardar e Imprimir Receta
                </button>
                <a href="consulta_express.php<?php echo $id_paciente > 0 ? '?id_paciente='.$id_paciente : ''; ?><?php echo !empty($medico_get) ? '&medico='.urlencode($medico_get) : ''; ?>" class="btn" style="background-color: #637381;">
                    <i class="fa-solid fa-arrow-left"></i> Regresar a Consulta Express
                </a>
            </div>
        </form>
    </div>

    <script>
        // Espejo automático para medicamentos en tiempo real
        document.addEventListener('DOMContentLoaded', function() {
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