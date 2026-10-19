<?php
// ===================================================================
// NOTA CLÍNICA PSICOLÓGICA (NOTA_PSICOLOGIA.PHP)
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_cita = intval($_GET['id_cita'] ?? 0);
$expediente = trim($_GET['expediente'] ?? '');

// Procesar guardado de la nota estructurada completa
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fecha_hora                = $_POST['fecha_hora'] ?? date('Y-m-d H:i:s');
        $motivo_consulta           = mb_strtoupper(trim($_POST['motivo_consulta'] ?? ''));
        $observaciones             = mb_strtoupper(trim($_POST['observaciones'] ?? ''));
        $estrategias               = mb_strtoupper(trim($_POST['estrategias'] ?? ''));
        $seguimiento_tx            = mb_strtoupper(trim($_POST['seguimiento_tx'] ?? ''));
        $impresion_dx              = mb_strtoupper(trim($_POST['impresion_dx'] ?? ''));
        $impresion_diagnostica_dsm = mb_strtoupper(trim($_POST['impresion_diagnostica_dsm'] ?? ''));
        $plan_diagnostico          = mb_strtoupper(trim($_POST['plan_diagnostico'] ?? ''));
        $pronostico                = mb_strtoupper(trim($_POST['pronostico'] ?? ''));
        $proxima_cita              = mb_strtoupper(trim($_POST['proxima_cita'] ?? ''));
        $usuario_id                = intval($_SESSION['usuario_id']);

        // Asegurar que la tabla notas_psicologia soporte todos los campos institucionales
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notas_psicologia (
                id_nota SERIAL PRIMARY KEY,
                id_cita INT,
                no_expediente VARCHAR(50),
                fecha_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                motivo_consulta TEXT,
                observaciones TEXT,
                estrategias TEXT,
                seguimiento_tx TEXT,
                impresion_dx VARCHAR(255),
                impresion_diagnostica_dsm TEXT,
                plan_diagnostico TEXT,
                pronostico TEXT,
                proxima_cita VARCHAR(100),
                usuario_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $stmt = $pdo->prepare("
            INSERT INTO notas_psicologia (
                id_cita, no_expediente, fecha_hora, motivo_consulta, observaciones, 
                estrategias, seguimiento_tx, impresion_dx, impresion_diagnostica_dsm, 
                plan_diagnostico, pronostico, proxima_cita, usuario_id
            )
            VALUES (
                :id_cita, :exp, :f_hora, :motivo, :obs, 
                :est, :seg, :imp, :dsm, 
                :plan, :pron, :prox, :usr
            )
        ");
        $stmt->execute([
            'id_cita'   => $id_cita,
            'exp'       => $expediente,
            'f_hora'    => $fecha_hora,
            'motivo'    => $motivo_consulta,
            'obs'       => $observaciones,
            'est'       => $estrategias,
            'seg'       => $seguimiento_tx,
            'imp'       => $impresion_dx,
            'dsm'       => $impresion_diagnostica_dsm,
            'plan'      => $plan_diagnostico,
            'pron'      => $pronostico,
            'prox'      => $proxima_cita,
            'usr'       => $usuario_id
        ]);

        header("Location: psicologia.php?exito=nota");
        exit();
    } catch (Exception $e) {
        $error_msg = urlencode($e->getMessage());
        header("Location: nota_psicologia.php?id_cita=$id_cita&expediente=$expediente&error=" . $error_msg);
        exit();
    }
}

// Obtener datos de la cita y del paciente de forma robusta
$cita = [];
$paciente = [];
try {
    if ($id_cita > 0) {
        $stmt_c = $pdo->prepare("SELECT * FROM citas_psicologia WHERE id_cita = :id LIMIT 1");
        $stmt_c->execute(['id' => $id_cita]);
        $cita = $stmt_c->fetch(PDO::FETCH_ASSOC);
        
        if ($cita) {
            if (empty($expediente) && !empty($cita['no_expediente'])) {
                $expediente = trim($cita['no_expediente']);
            }
        }
    }

    if (!empty($expediente) || !empty($cita['curp'])) {
        $stmt_p = $pdo->prepare("
            SELECT * FROM pacientes 
            WHERE expediente = :exp OR curp = :curp 
            LIMIT 1
        ");
        $stmt_p->execute([
            'exp'  => $expediente,
            'curp' => $cita['curp'] ?? ''
        ]);
        $paciente = $stmt_p->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$nombre_paciente = $cita['nombre_paciente'] ?? trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apaterno'] ?? '') . ' ' . ($paciente['amaterno'] ?? ''));
$edad_paciente   = $paciente['edad'] ?? ($paciente['anios'] ?? 'S/N');
$sexo_paciente   = $paciente['sexo'] ?? ($paciente['genero'] ?? 'S/N');
$curp_paciente   = $cita['curp'] ?? ($paciente['curp'] ?? 'S/N');
$exp_val         = $expediente ?: ($cita['no_expediente'] ?? ($paciente['expediente'] ?? 'S/N'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAISME | NOTA CLÍNICA PSICOLÓGICA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --borde: #000; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f6f8; color: #1c252e; text-transform: uppercase; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 980px; margin: 25px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 5px solid var(--imss-verde); }
        
        .header-sheet { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--imss-verde); padding-bottom: 10px; margin-bottom: 20px; }
        .header-sheet img { max-height: 50px; object-fit: contain; }
        .header-title { text-align: center; color: var(--imss-verde); font-size: 13px; font-weight: bold; line-height: 1.3; }

        .tabla-form { width: 100%; border-collapse: collapse; border: 2px solid var(--borde); margin-bottom: 20px; font-size: 12px; }
        .tabla-form th, .tabla-form td { border: 1px solid var(--borde); padding: 8px; text-align: left; }
        .tabla-form th { background: #f1f5f9; color: #000; font-weight: bold; }
        
        textarea { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; text-transform: uppercase; resize: vertical; min-height: 80px; box-sizing: border-box; background: #f8fafc; }
        input[type="text"] { width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; text-transform: uppercase; background: #f8fafc; box-sizing: border-box; }

        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #64748b; }
        .acciones-bar { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; border-top: 1px solid #cbd5e1; padding-top: 15px; }

        @media print {
            body { background: white; padding: 0; }
            .navbar, .acciones-bar, .no-print { display: none !important; }
            .container { box-shadow: none; margin: 0; max-width: 100%; padding: 0; border: none; }
        }
    </style>
</head>
<body>

    <div class="navbar no-print">
        <div><i class="fa-solid fa-notes-medical"></i> <strong>SAISME: NOTA CLÍNICA PSICOLÓGICA</strong></div>
        <a href="psicologia.php"><i class="fa-solid fa-arrow-left"></i> VOLVER A PSICOLOGÍA</a>
    </div>

    <div class="container">
        <div class="header-sheet">
            <img src="logo_hospital.png" alt="Logo Hospital">
            <div class="header-title">
                SERVICIOS DE SALUD IMSS-BIENESTAR<br>
                HOSPITAL GENERAL DE HUIMANGUILLO<br>
                <span style="font-size: 10px; font-weight: normal; color: #333;">“DR. ADELFO S. AGUIRRE” (CLUES: TCIMB001870)</span>
            </div>
            <img src="logo_imss.png" alt="Logo IMSS">
        </div>

        <form action="nota_psicologia.php?id_cita=<?php echo $id_cita; ?>&expediente=<?php echo urlencode($expediente); ?>" method="POST">
            
            <table class="tabla-form">
                <tr>
                    <th style="width: 10%;">NOMBRE:</th>
                    <td style="width: 55%;"><strong><?php echo htmlspecialchars($nombre_paciente); ?></strong></td>
                    <th style="width: 15%;">S.P. / NO. EXPE:</th>
                    <td style="width: 20%;"><strong><?php echo htmlspecialchars($exp_val); ?></strong></td>
                </tr>
                <tr>
                    <th>EDAD:</th>
                    <td><?php echo htmlspecialchars($edad_paciente); ?></td>
                    <th>SEXO:</th>
                    <td><?php echo htmlspecialchars($sexo_paciente); ?></td>
                </tr>
                <tr>
                    <th>CURP:</th>
                    <td colspan="3"><?php echo htmlspecialchars($curp_paciente); ?></td>
                </tr>
                <tr>
                    <th>IMP. DX (CIE-10):</th>
                    <td colspan="3">
                        <input type="text" name="impresion_dx" placeholder="EJ. F91.9 / TRASTORNO DE CONDUCTA..." required>
                    </td>
                </tr>
                <tr>
                    <th colspan="4" style="text-align: center; background: #e2e8f0;">NOTAS CLÍNICA PSICOLÓGICA</th>
                </tr>
                <tr>
                    <th style="text-align: center; vertical-align: top;">FECHA Y HORA / PRÓXIMA CITA</th>
                    <th colspan="3" style="text-align: center;">REGISTRO CLÍNICO (S, O, A, P, DSM-IV-TR Y PRONÓSTICO)</th>
                </tr>
                <tr>
                    <td style="vertical-align: top; font-size: 11px;">
                        <div style="margin-bottom: 10px;">
                            <strong>FECHA Y HORA:</strong><br>
                            <input type="datetime-local" name="fecha_hora" value="<?php echo date('Y-m-d\TH:i'); ?>" style="font-size: 11px; padding: 4px; width:100%; margin-top:3px;">
                        </div>
                        <div>
                            <strong>PRÓX. CITA:</strong><br>
                            <input type="text" name="proxima_cita" placeholder="EJ. ABIERTA / 15 DÍAS" style="font-size: 11px; padding: 4px; width:100%; margin-top:3px;">
                        </div>
                    </td>
                    <td colspan="3" style="padding: 12px; vertical-align: top;">
                        <div style="margin-bottom: 12px;">
                            <label style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">MOTIVO DE CONSULTA (S):</label>
                            <textarea name="motivo_consulta" placeholder="DESCRIBA EL MOTIVO DE CONSULTA SEGÚN EL PACIENTE / FAMILIAR..." required></textarea>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">OBSERVACIONES (O):</label>
                            <textarea name="observaciones" placeholder="OBSERVACIONES CLÍNICAS DURANTE LA SESIÓN..."></textarea>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">ESTRATEGIAS DE INTERVENCIÓN (A):</label>
                            <textarea name="estrategias" placeholder="ESTRATEGIAS, PSICOEDUCACIÓN Y ORIENTACIÓN BRINDADA..."></textarea>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">SEGUIMIENTO Y TX (P):</label>
                            <textarea name="seguimiento_tx" placeholder="PLAN DE TRATAMIENTO, RECOMENDACIONES Y SEGUIMIENTO..."></textarea>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">IMPRESIÓN DIAGNÓSTICA SEGÚN DSM-IV-TR (HIPÓTESIS CLÍNICAS / DIFERENCIALES):</label>
                            <textarea name="impresion_diagnostica_dsm" placeholder="EJ. TDAH, TRASTORNO DE CONDUCTA, DIAGNÓSTICOS DIFERENCIALES..."></textarea>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">PLAN DIAGNÓSTICO:</label>
                            <textarea name="plan_diagnostico" placeholder="MANTENER DIAGNÓSTICOS ANTERIORES COMO HIPÓTESIS POR DESCARTAR..."></textarea>
                        </div>
                        <div style="margin-bottom: 5px;">
                            <label style="font-weight: bold; font-size: 11px; color: var(--imss-verde);">PRONÓSTICO:</label>
                            <textarea name="pronostico" placeholder="EJ. FAVORABLE, LA (EL) PACIENTE COLABORA CON LAS ACTIVIDADES EN SESIÓN..." style="min-height: 60px;"></textarea>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="acciones-bar">
                <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> IMPRIMIR NOTA</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> GUARDAR NOTA CLÍNICA</button>
            </div>
        </form>
    </div>
</body>
</html>