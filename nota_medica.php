<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_cita = $_GET['id_cita'] ?? '';
if (empty($id_cita)) {
    die("ID de cita no especificado.");
}

// 1. Obtener datos de la cita, paciente y clínica asignada
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               p.edad AS edad_paciente, 
               p.sexo AS sexo_paciente, 
               p.domicilio, p.colonia, p.municipio, p.telefono, p.curp 
        FROM citas c 
        LEFT JOIN pacientes p ON c.no_expediente = p.expediente 
        WHERE c.id_cita = :id_cita LIMIT 1
    ");
    $stmt->execute(['id_cita' => $id_cita]);
    $cita = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cita) {
        die("No se encontró la cita especificada.");
    }

    $edad_mostrar = !empty($cita['edad_paciente']) ? $cita['edad_paciente'] : ($cita['edad'] ?? '0');
    $sexo_mostrar = trim(strtoupper(!empty($cita['sexo_paciente']) ? $cita['sexo_paciente'] : ($cita['sexo'] ?? 'N/D')));

    $id_paciente = $cita['no_expediente'];

    // Obtener la clínica asignada del usuario o cita
    $clinica_asignada = '';
    $stmt_user = $pdo->prepare("SELECT clave_clinica FROM usuarios WHERE id_usuario = :id LIMIT 1");
    $stmt_user->execute(['id' => $_SESSION['usuario_id']]);
    $usr = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if ($usr && !empty(trim($usr['clave_clinica'] ?? ''))) {
        $clinica_asignada = trim($usr['clave_clinica']);
    }

    $nombre_busqueda_clinica = !empty($clinica_asignada) ? $clinica_asignada : ($cita['nombre_clinica'] ?? '');

    $nombre_medico_def = "DR. ASIGNADO AL SERVICIO";
    $cedula_profesional_def = "S/C";

    if (!empty($nombre_busqueda_clinica)) {
        $cli_info = false;
        if (is_numeric($nombre_busqueda_clinica)) {
            $sql_cli = $pdo->prepare("SELECT * FROM clinicas WHERE id_clinica = :id_val LIMIT 1");
            $sql_cli->execute(['id_val' => (int)$nombre_busqueda_clinica]);
            $cli_info = $sql_cli->fetch(PDO::FETCH_ASSOC);
        }

        if (!$cli_info) {
            $sql_cli = $pdo->prepare("SELECT * FROM clinicas WHERE nombre_clinica = :nom LIMIT 1");
            $sql_cli->execute(['nom' => $nombre_busqueda_clinica]);
            $cli_info = $sql_cli->fetch(PDO::FETCH_ASSOC);
        }

        if ($cli_info) {
            $medico_asignado_clinica = trim($cli_info['nombre_medico'] ?? $cli_info['medico'] ?? '');
            $cedula_clinica = trim($cli_info['cedula'] ?? '');

            if (!empty($cedula_clinica)) $cedula_profesional_def = $cedula_clinica;

            if (!empty($medico_asignado_clinica)) {
                $med_info = false;
                if (is_numeric($medico_asignado_clinica)) {
                    $sql_med = "SELECT * FROM medicos WHERE id_medico = :id_med LIMIT 1";
                    $stmt_med = $pdo->prepare($sql_med);
                    $stmt_med->execute(['id_med' => (int)$medico_asignado_clinica]);
                    $med_info = $stmt_med->fetch(PDO::FETCH_ASSOC);
                }

                if (!$med_info) {
                    $sql_med = "SELECT * FROM medicos WHERE nombre ILIKE :val OR CONCAT(nombre, ' ', apaterno, ' ', amaterno) ILIKE :val LIMIT 1";
                    $stmt_med = $pdo->prepare($sql_med);
                    $stmt_med->execute(['val' => '%' . $medico_asignado_clinica . '%']);
                    $med_info = $stmt_med->fetch(PDO::FETCH_ASSOC);
                }

                if ($med_info) {
                    $nombre = trim($med_info['nombre'] ?? $med_info['nombres'] ?? '');
                    $apaterno = trim($med_info['apaterno'] ?? $med_info['apellido_paterno'] ?? '');
                    $amaterno = trim($med_info['amaterno'] ?? $med_info['apellido_materno'] ?? '');
                    $ced_med = trim($med_info['cedula'] ?? $med_info['cedula_profesional'] ?? $med_info['no_cedula'] ?? '');

                    $nombre_medico_def = trim("$nombre $apaterno $amaterno");
                    if (!empty($ced_med)) $cedula_profesional_def = $ced_med;
                } else {
                    $partes = explode("-", $medico_asignado_clinica);
                    $nombre_medico_def = trim($partes[0]);
                    if (isset($partes[1])) {
                        if (preg_match('/(\d+)/', $partes[1], $matches)) {
                            $cedula_profesional_def = $matches[1];
                        } else {
                            $cedula_profesional_def = trim($partes[1]);
                        }
                    }
                }
            }
        }
    }

    // 2. Buscar la ÚLTIMA nota médica registrada de este paciente
    $stmt_ultima = $pdo->prepare("
        SELECT * FROM nota_medica 
        WHERE id_paciente = :id_paciente 
        ORDER BY fecha_hora_registro DESC LIMIT 1
    ");
    $stmt_ultima->execute(['id_paciente' => $id_paciente]);
    $ultima_nota = $stmt_ultima->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error al consultar la base de datos: " . $e->getMessage());
}

// Catálogo completo y oficial de estudios de laboratorio e imagenología
$cat_estudios_lab = [
    ['estudio' => 'BIOMETRÍA HEMÁTICA (BH)', 'nombre_corto' => 'BH', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],
    ['estudio' => 'FROTIS DE SANGRE PERIFÉRICA (FROTIS SANGRE)', 'nombre_corto' => 'FROTIS', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],
    ['estudio' => 'GRUPO SANGUÍNEO Y RH (GRUPO Y RH)', 'nombre_corto' => 'GRUPO', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],
    ['estudio' => 'PERFIL DE HIERRO (TRANSFERRINA, FERRITINA, HIERRO) (PERFIL HIERRO)', 'nombre_corto' => 'HIERRO', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],
    ['estudio' => 'RETICULOCITOS (RETICULOCITOS)', 'nombre_corto' => 'RETIC', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],
    ['estudio' => 'TIEMPO DE PROTROMBINA (TP) (TP)', 'nombre_corto' => 'TP', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],
    ['estudio' => 'TIEMPO DE TROMBOPLASTINA (TTPA) (TTPA)', 'nombre_corto' => 'TTPA', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],
    ['estudio' => 'VELOCIDAD DE SEDIMENTACIÓN GLOBULAR (VSG) (VSG)', 'nombre_corto' => 'VSG', 'tipo_estudio' => 'HEMATOLOGÍA / COAGULACIÓN'],

    ['estudio' => 'COPROLÓGICO (COPROLÓGICO)', 'nombre_corto' => 'COPRO', 'tipo_estudio' => 'UROANÁLISIS / PARASITOLOGÍA'],
    ['estudio' => 'COPROPARASITÓSCOPICO (CPS)', 'nombre_corto' => 'CPS', 'tipo_estudio' => 'UROANÁLISIS / PARASITOLOGÍA'],
    ['estudio' => 'EXAMEN GENERAL DE ORINA (EGO)', 'nombre_corto' => 'EGO', 'tipo_estudio' => 'UROANÁLISIS / PARASITOLOGÍA'],
    ['estudio' => 'SANGRE OCULTA EN HECES (SOH)', 'nombre_corto' => 'SOH', 'tipo_estudio' => 'UROANÁLISIS / PARASITOLOGÍA'],

    ['estudio' => 'ALBÚMINA (ALBÚMINA)', 'nombre_corto' => 'ALB', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'AMILASA (AMILASA)', 'nombre_corto' => 'AMIL', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'BILIRRUBINAS (BT, BD, BI) (BILIRRUBINAS)', 'nombre_corto' => 'BILI', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'DESHIDROGENASA LÁCTICA (DHL) (DHL)', 'nombre_corto' => 'DHL', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'ELECTROLITOS SÉRICOS DE 3 ELEMENTOS (NA, K, CL) (ES3)', 'nombre_corto' => 'ES3', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'ELECTROLITOS SÉRICOS DE 6 ELEMENTOS (NA, K, CL, MG, CA, P) (ES8)', 'nombre_corto' => 'ES8', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'FOSFATASA ALCALINA (ALP) (FAL)', 'nombre_corto' => 'FAL', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'GAMMA GLUTAMIL TRANSPEPTIDASA (GGT) (GGT)', 'nombre_corto' => 'GGT', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'GLUCOSA (GLUCOSA)', 'nombre_corto' => 'GLU', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'HEMOGLOBINA GLICADA (HBA1C) (HBA1C)', 'nombre_corto' => 'HBA1C', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'LIPASA (LIPASA)', 'nombre_corto' => 'LIPASA', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'PERFIL CARDÍACO (TGO, DHL, CPK, CPK-MB) (PERFIL CARDÍACO)', 'nombre_corto' => 'P_CARD', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'PERFIL DE LÍPIDOS (PERFIL LÍPIDOS)', 'nombre_corto' => 'LIPIDOS', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'PERFIL PANCREÁTICO (AMILASA, LIPASA, DHL) (PERFIL PANCREÁTICO)', 'nombre_corto' => 'P_PANC', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'PFH (BILIRRUBINAS, TGO, TGP, PROTEÍNAS TOTALES, ALBÚMINA, GGT, ALP, DHL) (PFH)', 'nombre_corto' => 'PFH', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'PROTEÍNAS TOTALES (PROTEÍNAS TOTALES)', 'nombre_corto' => 'PROT', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'QS3 (GLUCOSA, UREA, BUN, CREATININA) (QS3)', 'nombre_corto' => 'QS3', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'QS4 (GLUCOSA, UREA, BUN, CREATININA, ÁCIDO ÚRICO) (QS4)', 'nombre_corto' => 'QS4', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'QS8 (GLUCOSA, UREA, BUN, CREATININA, ÁCIDO ÚRICO, COLESTEROL, TRIGLICÉRIDOS) (QS8)', 'nombre_corto' => 'QS8', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'TGO (AST) (TGO)', 'nombre_corto' => 'TGO', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],
    ['estudio' => 'TGP (ALT) (TGP)', 'nombre_corto' => 'TGP', 'tipo_estudio' => 'QUÍMICA SANGUÍNEA'],

    ['estudio' => 'AC. CONTRA VIH TIPO 1 Y 2 (VIH) (VIH)', 'nombre_corto' => 'VIH', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'AC. TREPONEMA PALLIDUM (VDRL) (VDRL)', 'nombre_corto' => 'VDRL', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'ANTIESTREPTOLISINA (ASO) (ASO)', 'nombre_corto' => 'ASO', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'COOMBS DIRECTO (COOMBS DIRECTO)', 'nombre_corto' => 'C_DIR', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'COOMBS INDIRECTO (COOMBS INDIRECTO)', 'nombre_corto' => 'C_IND', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'FACTOR REUMATOIDE (FR) (FR)', 'nombre_corto' => 'FR', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'PERFIL REUMÁTICO (PCR, ASO, FR, VSG, ANA) (PERFIL REUMÁTICO)', 'nombre_corto' => 'P_REUM', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'PROTEÍNA C REACTIVA (PCR) (PCR)', 'nombre_corto' => 'PCR', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'PRUEBA RÁPIDA DE EMBARAZO (SANGRE) (PREG. SANGRE)', 'nombre_corto' => 'HCG_S', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'PRUEBAS CRUZADAS (CE) (CRUZADAS CE)', 'nombre_corto' => 'P_CRUZ', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'REACCIONES FEBRILES (REACCIONES FEBRILES)', 'nombre_corto' => 'R_FEB', 'tipo_estudio' => 'SEROLOGÍA'],
    ['estudio' => 'ROSA DE BENGALA (ROSA BENGALA)', 'nombre_corto' => 'ROSA', 'tipo_estudio' => 'SEROLOGÍA'],

    ['estudio' => 'ANTICUERPOS ANTINUCLEARES (ANA) (ANA)', 'nombre_corto' => 'ANA', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'ANTÍGENO PROSTÁTICO ESPECÍFICO TOTAL (PSA) (PSA)', 'nombre_corto' => 'PSA', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'ESTRADIOL (E2) (ESTRADIOL)', 'nombre_corto' => 'E2', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'HORMONA ESTIMULANTE DEL FOLÍCULO (FSH) (FSH)', 'nombre_corto' => 'FSH', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'HORMONA LUTEINIZANTE (LH) (LH)', 'nombre_corto' => 'LH', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'PERFIL TIROIDEO (TSH, T4T, T4L, T3T, T3L) (PERFIL TIROIDEO)', 'nombre_corto' => 'P_TIRO', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'PROGESTERONA (PROGESTERONA)', 'nombre_corto' => 'PROG', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'PROLACTINA (PROLACTINA)', 'nombre_corto' => 'PROL', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'TESTOSTERONA TOTAL (TESTOSTERONA TOTAL)', 'nombre_corto' => 'TESTO', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'TIROTROPINA, HORMONA ESTIMULANTE DE LA TIROIDES (TSH) (TSH)', 'nombre_corto' => 'TSH', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'TIROXINA (T4L) LIBRE (T4 LIBRE)', 'nombre_corto' => 'T4L', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],
    ['estudio' => 'TRIYODOTIRONINA (T3L) LIBRE (T3 LIBRE)', 'nombre_corto' => 'T3L', 'tipo_estudio' => 'INMUNOLOGÍA ESPECIAL'],

    ['estudio' => 'BAAR CONTROL (BAAR CONTROL)', 'nombre_corto' => 'BAAR_C', 'tipo_estudio' => 'MICROBIOLOGÍA'],
    ['estudio' => 'BAAR SERIADO (BAAR SERIADO)', 'nombre_corto' => 'BAAR_S', 'tipo_estudio' => 'MICROBIOLOGÍA'],

    ['estudio' => 'RAYOS X (ESPECIFICAR) (RAYOS X)', 'nombre_corto' => 'RX', 'tipo_estudio' => 'IMAGENOLOGÍA'],
    ['estudio' => 'ULTRASONIDO (ESPECIFICAR) (ULTRASONIDO)', 'nombre_corto' => 'USG', 'tipo_estudio' => 'IMAGENOLOGÍA']
];

// 3. Procesar guardado de la Nota Médica
$mensaje_exito = '';
$error_msj = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_nota'])) {
    try {
        $tension_arterial         = trim($_POST['tension_arterial'] ?? '');
        $frecuencia_cardiaca      = (int)($_POST['frecuencia_cardiaca'] ?? 0);
        $frecuencia_respiratoria  = (int)($_POST['frecuencia_respiratoria'] ?? 0);
        $temperatura              = (float)($_POST['temperatura'] ?? 0);
        $saturacion_oxigeno       = !empty($_POST['saturacion_oxigeno']) ? (int)$_POST['saturacion_oxigeno'] : null;
        $peso                     = (float)($_POST['peso'] ?? 0);
        $talla                    = (float)($_POST['talla'] ?? 0);
        $imc                      = (float)($_POST['imc'] ?? 0);

        $motivo_consulta          = trim($_POST['motivo_consulta'] ?? '');
        $padecimiento_actual      = trim($_POST['padecimiento_actual'] ?? '');
        $exploracion_fisica       = trim($_POST['exploracion_fisica'] ?? '');
        $estudios_previos         = trim($_POST['estudios_previos'] ?? '');

        // Datos de embarazo
        $fum                      = trim($_POST['fum'] ?? '');
        $semanas_gestacion        = trim($_POST['semanas_gestacion'] ?? '');
        $gestas                   = trim($_POST['gestas'] ?? '');
        $paras                    = trim($_POST['paras'] ?? '');
        $abortos                  = trim($_POST['abortos'] ?? '');
        $cesareas                 = trim($_POST['cesareas'] ?? '');

        $cie10_principal          = trim($_POST['cie10_principal'] ?? '');
        $diagnostico_principal_desc = trim($_POST['diagnostico_principal_desc'] ?? '');
        $cie10_secundarios        = trim($_POST['cie10_secundarios'] ?? '');
        $plan_tratamiento         = trim($_POST['plan_tratamiento'] ?? '');
        $pronostico               = trim($_POST['pronostico'] ?? '');
        $referencia_medica        = trim($_POST['referencia_medica'] ?? '');

        $nombre_medico            = trim($_POST['nombre_medico'] ?? '');
        $cedula_profesional       = trim($_POST['cedula_profesional'] ?? '');
        $firma_digital            = trim($_POST['firma_digital'] ?? 'FIRMA ELECTRÓNICA INSTITUCIONAL');

        $stmt_ins = $pdo->prepare("
            INSERT INTO nota_medica (
                id_paciente, id_unidad_medica, tension_arterial, frecuencia_cardiaca, 
                frecuencia_respiratoria, temperatura, saturacion_oxigeno, peso, talla, imc, 
                motivo_consulta, padecimiento_actual, exploracion_fisica, estudios_previos, 
                fum, semanas_gestacion, gestas, paras, abortos, cesareas,
                cie10_principal, diagnostico_principal_desc, cie10_secundarios, plan_tratamiento, 
                pronostico, referencia_medica, nombre_medico, cedula_profesional, firma_digital
            ) VALUES (
                :id_paciente, :id_unidad_medica, :ta, :fc, :fr, :temp, :sat, :peso, :talla, :imc, 
                :motivo, :padecimiento, :exploracion, :estudios, 
                :fum, :sem_g, :gestas, :paras, :abortos, :cesareas,
                :cie10_p, :diag_p, :cie10_s, :plan, :pronostico, :referencia, :medico, :cedula, :firma
            )
        ");
        $stmt_ins->execute([
            'id_paciente'         => $id_paciente,
            'id_unidad_medica'    => $cita['nombre_clinica'] ?? 'HOSPITAL GENERAL',
            'ta'                  => $tension_arterial,
            'fc'                  => $frecuencia_cardiaca,
            'fr'                  => $frecuencia_respiratoria,
            'temp'                => $temperatura,
            'sat'                 => $saturacion_oxigeno,
            'peso'                => $peso,
            'talla'               => $talla,
            'imc'                 => $imc,
            'motivo'              => $motivo_consulta,
            'padecimiento'        => $padecimiento_actual,
            'exploracion'         => $exploracion_fisica,
            'estudios'            => $estudios_previos,
            'fum'                 => $fum,
            'sem_g'               => $semanas_gestacion,
            'gestas'              => $gestas,
            'paras'               => $paras,
            'abortos'             => $abortos,
            'cesareas'            => $cesareas,
            'cie10_p'             => $cie10_principal,
            'diag_p'              => $diagnostico_principal_desc,
            'cie10_s'             => $cie10_secundarios,
            'plan'                => $plan_tratamiento,
            'pronostico'          => $pronostico,
            'referencia'          => $referencia_medica,
            'medico'              => $nombre_medico,
            'cedula'              => $cedula_profesional,
            'firma'               => $firma_digital
        ]);

        $mensaje_exito = "Nota médica guardada correctamente.";
        $stmt_ultima->execute(['id_paciente' => $id_paciente]);
        $ultima_nota = $stmt_ultima->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error_msj = "Error al guardar la nota médica: " . $e->getMessage();
    }
}

// Valores por defecto
$val_ta = $_POST['tension_arterial'] ?? ($ultima_nota['tension_arterial'] ?? '');
$val_fc = $_POST['frecuencia_cardiaca'] ?? ($ultima_nota['frecuencia_cardiaca'] ?? '');
$val_fr = $_POST['frecuencia_respiratoria'] ?? ($ultima_nota['frecuencia_respiratoria'] ?? '');
$val_temp = $_POST['temperatura'] ?? ($ultima_nota['temperatura'] ?? '');
$val_sat = $_POST['saturacion_oxigeno'] ?? ($ultima_nota['saturacion_oxigeno'] ?? '');
$val_peso = $_POST['peso'] ?? ($ultima_nota['peso'] ?? '');
$val_talla = $_POST['talla'] ?? ($ultima_nota['talla'] ?? '');
$val_imc = $_POST['imc'] ?? ($ultima_nota['imc'] ?? '');
$val_motivo = $_POST['motivo_consulta'] ?? ($ultima_nota['motivo_consulta'] ?? '');
$val_padecimiento = $_POST['padecimiento_actual'] ?? ($ultima_nota['padecimiento_actual'] ?? '');
$val_exploracion = $_POST['exploracion_fisica'] ?? ($ultima_nota['exploracion_fisica'] ?? '');
$val_estudios = $_POST['estudios_previos'] ?? ($ultima_nota['estudios_previos'] ?? '');
$val_fum = $_POST['fum'] ?? ($ultima_nota['fum'] ?? '');
$val_sem_g = $_POST['semanas_gestacion'] ?? ($ultima_nota['semanas_gestacion'] ?? '');
$val_gestas = $_POST['gestas'] ?? ($ultima_nota['gestas'] ?? '');
$val_paras = $_POST['paras'] ?? ($ultima_nota['paras'] ?? '');
$val_abortos = $_POST['abortos'] ?? ($ultima_nota['abortos'] ?? '');
$val_cesareas = $_POST['cesareas'] ?? ($ultima_nota['cesareas'] ?? '');
$val_cie10_p = $_POST['cie10_principal'] ?? ($ultima_nota['cie10_principal'] ?? '');
$val_diag_p = $_POST['diagnostico_principal_desc'] ?? ($ultima_nota['diagnostico_principal_desc'] ?? '');
$val_cie10_s = $_POST['cie10_secundarios'] ?? ($ultima_nota['cie10_secundarios'] ?? '');
$val_plan = $_POST['plan_tratamiento'] ?? ($ultima_nota['plan_tratamiento'] ?? '');
$val_pronostico = $_POST['pronostico'] ?? ($ultima_nota['pronostico'] ?? 'FAVORABLE');
$val_referencia = $_POST['referencia_medica'] ?? ($ultima_nota['referencia_medica'] ?? '');
$val_medico = $_POST['nombre_medico'] ?? ($ultima_nota['nombre_medico'] ?? $nombre_medico_def);
$val_cedula = $_POST['cedula_profesional'] ?? ($ultima_nota['cedula_profesional'] ?? $cedula_profesional_def);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nota Médica - Paciente</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background-color: #f4f6f8; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; color: #1c252e; font-size: 13px; text-transform: uppercase; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header-top { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 12px; margin-bottom: 20px; }
        .header-top h2 { color: #006847; margin: 0; font-size: 20px; }
        .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
        .btn:hover { background-color: #005338; }
        .btn-secundario { background-color: #0284c7; }
        .btn-secundario:hover { background-color: #0369a1; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        .grid-6 { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; color: #334155; font-size: 11px; }
        .form-control { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: Arial; text-transform: uppercase; box-sizing: border-box; }
        textarea.form-control { resize: vertical; min-height: 70px; }
        fieldset { border: 1px solid #cbd5e1; border-radius: 8px; padding: 15px; margin-bottom: 20px; background: #fafafa; }
        legend { font-weight: bold; color: #006847; padding: 0 10px; font-size: 13px; }
        .info-paciente { background: #f1f5f9; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 12px; }
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single { height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; display: flex; align-items: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-top">
            <h2><i class="fa-solid fa-notes-medical"></i> Nota Médica e Historial Clínico</h2>
            <div>
                <a href="generar_receta.php?id_cita=<?php echo $id_cita; ?>" target="_blank" class="btn btn-secundario" style="background-color: #673ab7; margin-right: 10px;">
                    <i class="fa-solid fa-file-prescription"></i> Generar / Editar Receta
                </a>
                <a href="citas_clinica.php" class="btn" style="background-color: #64748b;"><i class="fa-solid fa-arrow-left"></i> Regresar a Citas</a>
            </div>
        </div>

        <?php if (!empty($mensaje_exito)): ?>
            <div style="background: #dcfce7; color: #166534; border: 1px solid #15803d; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje_exito); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msj)): ?>
            <div style="background: #fee2e2; color: #991b1b; border: 1px solid #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error_msj); ?>
            </div>
        <?php endif; ?>

        <div class="info-paciente">
            <div><strong>Paciente:</strong> <br><?php echo htmlspecialchars($cita['nombre_paciente']); ?></div>
            <div><strong>Expediente:</strong> <br><?php echo htmlspecialchars($cita['no_expediente']); ?></div>
            <div><strong>Edad / Sexo:</strong> <br><?php echo htmlspecialchars($edad_mostrar); ?> AÑOS / <?php echo htmlspecialchars($sexo_mostrar); ?></div>
            <div><strong>Clínica / Cita:</strong> <br><?php echo htmlspecialchars($cita['nombre_clinica']); ?> (<?php echo htmlspecialchars($cita['fecha_cita']); ?>)</div>
        </div>

        <?php if ($ultima_nota): ?>
            <div style="background: #e0f2fe; color: #0369a1; border: 1px solid #0284c7; padding: 10px 15px; border-radius: 6px; margin-bottom: 20px; font-size: 11px; font-weight: bold;">
                <i class="fa-solid fa-info-circle"></i> Se han precargado los datos de la última nota médica registrada.
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- Signos Vitales -->
            <fieldset>
                <legend><i class="fa-solid fa-heart-pulse"></i> Signos Vitales y Antropometría</legend>
                <div class="grid-4">
                    <div class="form-group">
                        <label>Tensión Arterial (mmHg):</label>
                        <input type="text" name="tension_arterial" class="form-control" value="<?php echo htmlspecialchars($val_ta); ?>" placeholder="120/80" required>
                    </div>
                    <div class="form-group">
                        <label>Frecuencia Cardíaca (lpm):</label>
                        <input type="number" name="frecuencia_cardiaca" class="form-control" value="<?php echo htmlspecialchars($val_fc); ?>" placeholder="80" required>
                    </div>
                    <div class="form-group">
                        <label>Frecuencia Respiratoria (rpm):</label>
                        <input type="number" name="frecuencia_respiratoria" class="form-control" value="<?php echo htmlspecialchars($val_fr); ?>" placeholder="18" required>
                    </div>
                    <div class="form-group">
                        <label>Temperatura (°C):</label>
                        <input type="number" step="0.1" name="temperatura" class="form-control" value="<?php echo htmlspecialchars($val_temp); ?>" placeholder="36.5" required>
                    </div>
                    <div class="form-group">
                        <label>Saturación de Oxígeno (%):</label>
                        <input type="number" name="saturacion_oxigeno" class="form-control" value="<?php echo htmlspecialchars($val_sat); ?>" placeholder="98">
                    </div>
                    <div class="form-group">
                        <label>Peso (kg):</label>
                        <input type="number" step="0.01" name="peso" id="peso" class="form-control" value="<?php echo htmlspecialchars($val_peso); ?>" oninput="calcularIMC()" placeholder="70" required>
                    </div>
                    <div class="form-group">
                        <label>Talla (m):</label>
                        <input type="number" step="0.01" name="talla" id="talla" class="form-control" value="<?php echo htmlspecialchars($val_talla); ?>" oninput="calcularIMC()" placeholder="1.70" required>
                    </div>
                    <div class="form-group">
                        <label>IMC:</label>
                        <input type="number" step="0.01" name="imc" id="imc" class="form-control" value="<?php echo htmlspecialchars($val_imc); ?>" placeholder="24.22" readonly style="background:#f1f5f9;">
                    </div>
                </div>
            </fieldset>

            <!-- Control de Embarazo (Visible Únicamente si el sexo es MUJER) -->
            <?php if ($sexo_mostrar === 'MUJER'): ?>
            <fieldset style="background: #fdf2f8; border-color: #f472b6;">
                <legend style="color: #db2777;"><i class="fa-solid fa-person-pregnant"></i> Control Gineco-Obstétrico / Embarazo</legend>
                <div class="grid-6">
                    <div class="form-group">
                        <label>F.U.M. (Fecha):</label>
                        <input type="date" id="fum" name="fum" class="form-control" value="<?php echo htmlspecialchars($val_fum); ?>" onchange="calcularSemanasGestacion()">
                    </div>
                    <div class="form-group">
                        <label>Semanas Gestación:</label>
                        <input type="text" id="semanas_gestacion" name="semanas_gestacion" class="form-control" value="<?php echo htmlspecialchars($val_sem_g); ?>" placeholder="AUTO / EDITABLE">
                    </div>
                    <div class="form-group">
                        <label>Gestas (G):</label>
                        <input type="number" name="gestas" class="form-control" value="<?php echo htmlspecialchars($val_gestas); ?>" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Paras (P):</label>
                        <input type="number" name="paras" class="form-control" value="<?php echo htmlspecialchars($val_paras); ?>" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Abortos (A):</label>
                        <input type="number" name="abortos" class="form-control" value="<?php echo htmlspecialchars($val_abortos); ?>" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Cesáreas (C):</label>
                        <input type="number" name="cesareas" class="form-control" value="<?php echo htmlspecialchars($val_cesareas); ?>" placeholder="0">
                    </div>
                </div>
            </fieldset>
            <?php endif; ?>

            <!-- Padecimiento y Exploración -->
            <fieldset>
                <legend><i class="fa-solid fa-stethoscope"></i> Padecimiento y Exploración Física</legend>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Motivo de Consulta:</label>
                        <textarea name="motivo_consulta" class="form-control" required><?php echo htmlspecialchars($val_motivo); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Padecimiento Actual:</label>
                        <textarea name="padecimiento_actual" class="form-control" required><?php echo htmlspecialchars($val_padecimiento); ?></textarea>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Exploración Física:</label>
                        <textarea name="exploracion_fisica" class="form-control" required><?php echo htmlspecialchars($val_exploracion); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label><i class="fa-solid fa-flask"></i> Buscador de Estudios de Laboratorio / Imagen:</label>
                        <select id="selectEstudioIndividual" class="form-control">
                            <option value="">-- ESCRIBA O SELECCIONE UN ESTUDIO --</option>
                            <?php foreach ($cat_estudios_lab as $est): ?>
                                <option value="<?php echo htmlspecialchars($est['estudio']); ?>">
                                    <?php echo htmlspecialchars($est['estudio']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label style="margin-top: 8px;">Texto de Estudios / Laboratorios (Editable):</label>
                        <textarea name="estudios_previos" id="estudios_previos" class="form-control" placeholder="LOS ESTUDIOS SE AGREGARÁN AQUÍ..."><?php echo htmlspecialchars($val_estudios); ?></textarea>
                        
                        <div style="margin-top: 8px; text-align: right;">
                            <button type="button" onclick="imprimirOrdenLaboratorio()" class="btn" style="background-color: #0284c7; padding: 6px 14px; font-size: 11px;">
                                <i class="fa-solid fa-print"></i> Imprimir Orden de Laboratorio Oficial
                            </button>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- Diagnóstico y Tratamiento -->
            <fieldset>
                <legend><i class="fa-solid fa-file-medical"></i> Diagnóstico y Tratamiento</legend>
                <div class="grid-2">
                    <div class="form-group">
                        <label>CIE-10 Principal (Código):</label>
                        <input type="text" id="cie10_principal" name="cie10_principal" class="form-control" value="<?php echo htmlspecialchars($val_cie10_p); ?>" placeholder="J02.9" required>
                    </div>
                    <div class="form-group">
                        <label>Descripción Diagnóstico Principal:</label>
                        <input type="text" id="diagnostico_principal_desc" name="diagnostico_principal_desc" class="form-control" value="<?php echo htmlspecialchars($val_diag_p); ?>" placeholder="Faringitis aguda" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>CIE-10 Secundarios / Comorbilidades:</label>
                    <input type="text" name="cie10_secundarios" class="form-control" value="<?php echo htmlspecialchars($val_cie10_s); ?>">
                </div>
                <div class="form-group">
                    <label>Plan de Tratamiento / Indicaciones Médicas:</label>
                    <textarea name="plan_tratamiento" class="form-control" required><?php echo htmlspecialchars($val_plan); ?></textarea>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Pronóstico:</label>
                        <input type="text" name="pronostico" class="form-control" value="<?php echo htmlspecialchars($val_pronostico); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Referencia Médica (Si aplica):</label>
                        <input type="text" name="referencia_medica" class="form-control" value="<?php echo htmlspecialchars($val_referencia); ?>">
                    </div>
                </div>
            </fieldset>

            <!-- Validación y Cierre -->
            <fieldset>
                <legend><i class="fa-solid fa-id-card"></i> Validación del Médico</legend>
                <div class="grid-3" style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Nombre Completo del Médico:</label>
                        <input type="text" id="nombre_medico" name="nombre_medico" class="form-control" value="<?php echo htmlspecialchars($val_medico); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Cédula Profesional:</label>
                        <input type="text" id="cedula_profesional" name="cedula_profesional" class="form-control" value="<?php echo htmlspecialchars($val_cedula); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Firma / Sello Digital:</label>
                        <input type="text" name="firma_digital" class="form-control" value="VALIDADO - FIRMA ELECTRÓNICA" readonly style="background:#f1f5f9;">
                    </div>
                </div>
            </fieldset>

            <div style="text-align: right; margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="imprimirNotaMedica()" class="btn" style="background-color: #0284c7;">
                    <i class="fa-solid fa-print"></i> Imprimir Nota Médica
                </button>
                <button type="submit" name="guardar_nota" class="btn" style="background-color: #006847;">
                    <i class="fa-solid fa-save"></i> Guardar Nota Médica
                </button>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        const catalogoCompletoLab = <?php echo json_encode($cat_estudios_lab); ?>;

        $(document).ready(function() {
            $('#selectEstudioIndividual').select2({
                placeholder: '-- ESCRIBA O SELECCIONE UN ESTUDIO --',
                allowClear: true
            }).on('select2:select', function (e) {
                let estudioSeleccionado = e.params.data.id;
                agregarEstudioAlTexto(estudioSeleccionado);
                $(this).val(null).trigger('change');
            });
        });

        function calcularSemanasGestacion() {
            let fumInput = document.getElementById('fum');
            let semanasInput = document.getElementById('semanas_gestacion');
            
            if (!fumInput || !fumInput.value) {
                return;
            }

            let fumDate = new Date(fumInput.value);
            let hoy = new Date();

            fumDate.setHours(0, 0, 0, 0);
            hoy.setHours(0, 0, 0, 0);

            let diferenciaMilisegundos = hoy - fumDate;
            let diferenciaDias = Math.floor(diferenciaMilisegundos / (1000 * 60 * 60 * 24));

            if (diferenciaDias < 0) {
                semanasInput.value = 'FECHA FUTURA';
                return;
            }

            let semanas = Math.floor(diferenciaDias / 7);
            let dias = diferenciaDias % 7;

            semanasInput.value = semanas + ' SEMANAS' + (dias > 0 ? ' (' + dias + ' D)' : '');
        }

        function agregarEstudioAlTexto(estudio) {
            if (!estudio) return;
            let textarea = document.getElementById('estudios_previos');
            let actual = textarea.value.trim();
            if (actual === '') {
                textarea.value = estudio;
            } else {
                let arr = actual.split(',').map(e => e.trim());
                if (!arr.includes(estudio)) {
                    textarea.value = actual + ', ' + estudio;
                }
            }
        }

        function calcularIMC() {
            let peso = parseFloat(document.getElementById('peso').value) || 0;
            let talla = parseFloat(document.getElementById('talla').value) || 0;
            if (peso > 0 && talla > 0) {
                let imc = peso / (talla * talla);
                document.getElementById('imc').value = imc.toFixed(2);
            } else {
                document.getElementById('imc').value = '';
            }
        }

        function imprimirNotaMedica() {
            let p = {
                nombre: "<?php echo htmlspecialchars($cita['nombre_paciente']); ?>",
                expediente: "<?php echo htmlspecialchars($cita['no_expediente']); ?>",
                curp: "<?php echo htmlspecialchars($cita['curp'] ?? 'S/C'); ?>",
                edad: "<?php echo htmlspecialchars($edad_mostrar); ?>",
                sexo: "<?php echo htmlspecialchars($sexo_mostrar); ?>",
                clinica: "<?php echo htmlspecialchars($cita['nombre_clinica']); ?>",
                fechaCita: "<?php echo htmlspecialchars($cita['fecha_cita']); ?>",
                ta: document.querySelector('[name="tension_arterial"]').value,
                fc: document.querySelector('[name="frecuencia_cardiaca"]').value,
                fr: document.querySelector('[name="frecuencia_respiratoria"]').value,
                temp: document.querySelector('[name="temperatura"]').value,
                sat: document.querySelector('[name="saturacion_oxigeno"]').value || 'N/D',
                peso: document.querySelector('[name="peso"]').value,
                talla: document.querySelector('[name="talla"]').value,
                imc: document.querySelector('[name="imc"]').value,
                
                fum: document.getElementById('fum') ? document.getElementById('fum').value : '',
                semanas: document.getElementById('semanas_gestacion') ? document.getElementById('semanas_gestacion').value : '',
                gestas: document.querySelector('[name="gestas"]') ? document.querySelector('[name="gestas"]').value : '',
                paras: document.querySelector('[name="paras"]') ? document.querySelector('[name="paras"]').value : '',
                abortos: document.querySelector('[name="abortos"]') ? document.querySelector('[name="abortos"]').value : '',
                cesareas: document.querySelector('[name="cesareas"]') ? document.querySelector('[name="cesareas"]').value : '',

                motivo: document.querySelector('[name="motivo_consulta"]').value,
                padecimiento: document.querySelector('[name="padecimiento_actual"]').value,
                exploracion: document.querySelector('[name="exploracion_fisica"]').value,
                estudios: document.querySelector('[name="estudios_previos"]').value,
                cie10P: document.getElementById('cie10_principal').value,
                diagP: document.getElementById('diagnostico_principal_desc').value,
                cie10S: document.querySelector('[name="cie10_secundarios"]').value,
                plan: document.querySelector('[name="plan_tratamiento"]').value,
                pronostico: document.querySelector('[name="pronostico"]').value,
                referencia: document.querySelector('[name="referencia_medica"]').value,
                medico: document.getElementById('nombre_medico').value,
                cedula: document.getElementById('cedula_profesional').value
            };

            let fechaActual = new Date().toISOString().split('T')[0].split('-').reverse().join('-');

            let ventana = window.open('', '_blank', 'width=900,height=1100');
            ventana.document.write(`
                <html>
                <head>
                    <title>Nota Médica - Hospital General de Huimanguillo</title>
                    <style>
                        @page { size: letter portrait; margin: 10mm; }
                        body { font-family: Arial, sans-serif; font-size: 10px; color: #111; text-transform: uppercase; line-height: 1.2; }
                        .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #006847; padding-bottom: 5px; margin-bottom: 10px; }
                        .header-center { text-align: center; }
                        .header-center h2 { margin: 0; color: #006847; font-size: 13px; }
                        .header-center h3 { margin: 2px 0 0 0; color: #9f2241; font-size: 11px; }
                        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9.5px; }
                        .info-grid td { padding: 4px 6px; border: 1px solid #cbd5e1; background: #f8fafc; }
                        .section-title { background: #006847; color: white; font-weight: bold; padding: 4px 8px; margin-top: 10px; margin-bottom: 6px; font-size: 10px; }
                        .content-box { border: 1px solid #cbd5e1; padding: 6px; margin-bottom: 8px; font-size: 9.5px; background: #fff; min-height: 25px; }
                        .firma-container { margin-top: 40px; text-align: center; }
                        .firma-line { display: inline-block; width: 300px; border-top: 1px solid #111; padding-top: 4px; font-size: 10px; }
                    </style>
                </head>
                <body>
                    <table class="header-table">
                        <tr>
                            <td width="20%" style="font-weight: bold; color: #006847; font-size: 10px;">HUIMANGUILLO<br><span style="font-size:7px; color:#555;">HOSPITAL GENERAL</span></td>
                            <td width="60%" class="header-center">
                               <h2>GOBIERNO DE MÉXICO &nbsp;|&nbsp; IMSS-BIENESTAR</h2>
                               <h3>HOSPITAL GENERAL HUIMANGUILLO — NOTA MÉDICA E HISTORIAL CLÍNICO</h3>
                            </td>
                            <td width="20%" style="text-align: right; font-size: 9px;">
                                <strong>FECHA:</strong> ${fechaActual}
                            </td>
                        </tr>
                    </table>

                    <table class="info-grid">
                        <tr>
                            <td><strong>PACIENTE:</strong> ${p.nombre}</td>
                            <td><strong>EXPEDIENTE:</strong> ${p.expediente}</td>
                            <td><strong>EDAD / SEXO:</strong> ${p.edad} AÑOS / ${p.sexo}</td>
                        </tr>
                        <tr>
                            <td><strong>CURP:</strong> ${p.curp}</td>
                            <td colspan="2"><strong>CLÍNICA / CITA:</strong> ${p.clinica} (${p.fechaCita})</td>
                        </tr>
                    </table>

                    <div class="section-title">SIGNOS VITALES Y ANTROPOMETRÍA</div>
                    <table class="info-grid" style="text-align: center;">
                        <tr>
                            <td><strong>T.A.:</strong> ${p.ta} MMHG</td>
                            <td><strong>F.C.:</strong> ${p.fc} LPM</td>
                            <td><strong>F.R.:</strong> ${p.fr} RPM</td>
                            <td><strong>TEMP:</strong> ${p.temp} °C</td>
                            <td><strong>SAT:</strong> ${p.sat} %</td>
                            <td><strong>PESO:</strong> ${p.peso} KG</td>
                            <td><strong>TALLA:</strong> ${p.talla} M</td>
                            <td><strong>I.M.C.:</strong> ${p.imc}</td>
                        </tr>
                    </table>

                    ${p.sexo === 'MUJER' && (p.fum || p.gestas) ? `
                    <div class="section-title">CONTROL GINECO-OBSTÉTRICO / EMBARAZO</div>
                    <table class="info-grid" style="text-align: center;">
                        <tr>
                            <td><strong>F.U.M.:</strong> ${p.fum || 'N/D'}</td>
                            <td><strong>SEM. GESTACIÓN:</strong> ${p.semanas || 'N/D'}</td>
                            <td><strong>GESTAS (G):</strong> ${p.gestas || '0'}</td>
                            <td><strong>PARAS (P):</strong> ${p.paras || '0'}</td>
                            <td><strong>ABORTOS (A):</strong> ${p.abortos || '0'}</td>
                            <td><strong>CESÁREAS (C):</strong> ${p.cesareas || '0'}</td>
                        </tr>
                    </table>
                    ` : ''}

                    <div class="section-title">PADECIMIENTO Y EXPLORACIÓN FÍSICA</div>
                    <strong>MOTIVO DE CONSULTA:</strong>
                    <div class="content-box">${p.motivo}</div>
                    
                    <strong>PADECIMIENTO ACTUAL:</strong>
                    <div class="content-box">${p.padecimiento}</div>

                    <strong>EXPLORACIÓN FÍSICA:</strong>
                    <div class="content-box">${p.exploracion}</div>

                    ${p.estudios ? `
                    <strong>ESTUDIOS DE LABORATORIO / GABINETE SOLICITADOS:</strong>
                    <div class="content-box">${p.estudios}</div>
                    ` : ''}

                    <div class="section-title">DIAGNÓSTICO Y TRATAMIENTO</div>
                    <strong>DIAGNÓSTICO PRINCIPAL (CIE-10: ${p.cie10P}):</strong>
                    <div class="content-box">${p.diagP}</div>

                    ${p.cie10S ? `
                    <strong>DIAGNÓSTICOS SECUNDARIOS / COMORBILIDADES:</strong>
                    <div class="content-box">${p.cie10S}</div>
                    ` : ''}

                    <strong>PLAN DE TRATAMIENTO / INDICACIONES MÉDICAS:</strong>
                    <div class="content-box">${p.plan}</div>

                    <table class="info-grid">
                        <tr>
                            <td><strong>PRONÓSTICO:</strong> ${p.pronostico}</td>
                            <td><strong>REFERENCIA MÉDICA:</strong> ${p.referencia || 'NINGUNA'}</td>
                        </tr>
                    </table>

                    <div class="firma-container">
                        <div class="firma-line">
                            <strong>${p.medico}</strong><br>
                            CÉD. PROF.: ${p.cedula}<br>
                            NOMBRE Y FIRMA DEL MÉDICO
                        </div>
                    </div>

                    <script>window.onload = function() { window.print(); };<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }

        function imprimirOrdenLaboratorio() {
            let p = {
                nombre: "<?php echo htmlspecialchars($cita['nombre_paciente']); ?>",
                curp: "<?php echo htmlspecialchars($cita['curp'] ?? 'S/C'); ?>",
                edad: "<?php echo htmlspecialchars($edad_mostrar); ?>",
                sexo: "<?php echo htmlspecialchars($sexo_mostrar); ?>",
                servicio: "CONSULTA EXTERNA",
                procedencia: "TABASCO",
                diagnostico: document.getElementById('cie10_principal').value ? (document.getElementById('cie10_principal').value + ' - ' + document.getElementById('diagnostico_principal_desc').value) : 'S/D',
                medico: document.getElementById('nombre_medico').value + ' - CÉD: ' + document.getElementById('cedula_profesional').value,
                estudios: document.getElementById('estudios_previos').value.toUpperCase()
            };

            let estudiosArr = p.estudios.split(',').map(e => e.trim());
            let categorias = {};
            catalogoCompletoLab.forEach(item => {
                if (!categorias[item.tipo_estudio]) categorias[item.tipo_estudio] = [];
                categorias[item.tipo_estudio].push(item.estudio);
            });

            let fechaActual = new Date().toISOString().split('T')[0].split('-').reverse().join('-');

            let ventana = window.open('', '_blank', 'width=850,height=1100');
            ventana.document.write(`
                <html>
                <head>
                    <title>Solicitud de Estudios de Laboratorio - Hospital General de Huimanguillo</title>
                    <style>
                        @page { size: letter portrait; margin: 6mm; }
                        html, body { width: 100%; height: 100%; margin: 0; padding: 0; box-sizing: border-box; }
                        body { font-family: Arial, sans-serif; font-size: 9.5px; color: #111; text-transform: uppercase; line-height: 1.25; display: flex; flex-direction: column; justify-content: space-between; }
                        .page-container { width: 100%; height: 100%; display: flex; flex-direction: column; justify-content: space-between; }
                        .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #006847; padding-bottom: 4px; margin-bottom: 5px; }
                        .header-table td { vertical-align: middle; border: none; }
                        .header-center { text-align: center; }
                        .header-center h2 { margin: 0; color: #006847; font-size: 11.5px; }
                        .header-center h3 { margin: 2px 0 0 0; color: #9f2241; font-size: 9.5px; }
                        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 5px; font-size: 9px; }
                        .info-grid td { padding: 3px 4px; border: 1px solid #cbd5e1; background: #f8fafc; }
                        .columns-container { width: 100%; display: flex; gap: 10px; flex-grow: 1; margin-bottom: 5px; }
                        .column { flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
                        .cat-block { display: flex; flex-direction: column; flex-grow: 1; margin-bottom: 4px; }
                        .section-cat { background: #006847; color: white; font-weight: bold; padding: 3px 6px; font-size: 8.5px; border: 1px solid #004d35; }
                        .checkbox-table { width: 100%; border-collapse: collapse; font-size: 8.5px; flex-grow: 1; }
                        .checkbox-table td { padding: 2px 3px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
                        .box-cb { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; text-align: center; line-height: 10px; font-size: 8px; font-weight: bold; margin-right: 4px; background: #fff; }
                        .footer-area { margin-top: auto; }
                        .observaciones-box { font-size: 9px; border: 1px solid #cbd5e1; padding: 4px; margin-bottom: 5px; background: #fff; }
                        .firma-box { text-align: center; font-size: 9px; margin-bottom: 3px; }
                        .firma-line { display: inline-block; width: 260px; border-top: 1px solid #111; padding-top: 2px; }
                    </style>
                </head>
                <body>
                    <div class="page-container">
                        <div>
                            <table class="header-table">
                                <tr>
                                    <td width="20%" style="font-weight: bold; color: #006847; font-size: 9.5px;">HUIMANGUILLO<br><span style="font-size:6.5px; color:#555;">HOSPITAL GENERAL</span></td>
                                    <td width="60%" class="header-center">
                                       <h2>GOBIERNO DE MÉXICO &nbsp;|&nbsp; IMSS-BIENESTAR</h2>
                                       <h3>HOSPITAL GENERAL HUIMANGUILLO — LABORATORIO DE ANÁLISIS CLÍNICOS</h3>
                                    </td>
                                    <td width="20%" style="text-align: right; font-size: 8.5px;">
                                        <strong>FECHA:</strong> ${fechaActual}
                                    </td>
                                </tr>
                            </table>

                            <div style="text-align: center; font-weight: bold; font-size: 10.5px; margin-bottom: 2px; color: #006847;">SOLICITUD DE ESTUDIOS DE LABORATORIO</div>
                            <div style="text-align: center; font-size: 8px; font-weight: bold; margin-bottom: 4px;">HORARIO DE TOMA DE MUESTRA: LUNES A VIERNES 7:00 - 8:00 AM.</div>

                            <table class="info-grid">
                                <tr>
                                    <td colspan="2"><strong>NOMBRE DEL PACIENTE:</strong> ${p.nombre}</td>
                                    <td><strong>FECHA:</strong> ${fechaActual}</td>
                                </tr>
                                <tr>
                                    <td><strong>CURP:</strong> ${p.curp}</td>
                                    <td><strong>EDAD:</strong> ${p.edad} AÑOS</td>
                                    <td><strong>SEXO:</strong> ${p.sexo}</td>
                                </tr>
                                <tr>
                                    <td><strong>PROCEDENCIA:</strong> ${p.procedencia}</td>
                                    <td colspan="2"><strong>TELÉFONO:</strong> S/N</td>
                                </tr>
                                <tr>
                                    <td colspan="2"><strong>DIAGNÓSTICO:</strong> ${p.diagnostico}</td>
                                    <td><strong>PRÓXIMA CITA:</strong> N/D</td>
                                </tr>
                                <tr>
                                    <td colspan="3">
                                        CONSULTA EXTERNA: [ X ] &nbsp;&nbsp; 
                                        <strong>SERVICIO:</strong> ${p.servicio}
                                    </td>
                                </tr>
                            </table>

                            <div class="columns-container">
                                <div class="column">
                                    ${renderCatLab(['HEMATOLOGÍA / COAGULACIÓN', 'UROANÁLISIS / PARASITOLOGÍA', 'QUÍMICA SANGUÍNEA'], categorias, estudiosArr)}
                                </div>
                                <div class="column">
                                    ${renderCatLab(['SEROLOGÍA', 'INMUNOLOGÍA ESPECIAL', 'MICROBIOLOGÍA', 'IMAGENOLOGÍA'], categorias, estudiosArr)}
                                </div>
                            </div>
                        </div>

                        <div class="footer-area">
                            <div class="observaciones-box">
                                <strong>ESTUDIOS / OBSERVACIONES:</strong> ${p.estudios}
                            </div>

                            <div class="firma-box">
                                <div class="firma-line">
                                    <strong>${p.medico}</strong><br>
                                    NOMBRE Y FIRMA DEL MÉDICO
                                </div>
                            </div>
                        </div>
                    </div>
                    <script>window.onload = function() { window.print(); };<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }

        function renderCatLab(listaCats, categorias, seleccionados) {
            let html = '';
            listaCats.forEach(catName => {
                if (categorias[catName]) {
                    html += `<div class="cat-block">`;
                    html += `<div class="section-cat">${catName}</div>`;
                    html += `<table class="checkbox-table">`;
                    categorias[catName].forEach(est => {
                        let marcado = seleccionados.some(s => est.toUpperCase().includes(s) || s.includes(est.toUpperCase())) ? 'X' : '&nbsp;';
                        html += `<tr><td width="14px"><span class="box-cb">${marcado}</span></td><td>${est}</td></tr>`;
                    });
                    html += `</table>`;
                    html += `</div>`;
                }
            });
            return html;
        }
    </script>
</body>
</html>