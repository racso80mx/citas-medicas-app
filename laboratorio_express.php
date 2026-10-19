<?php
/**
 * MÓDULO DE LABORATORIO EXPRESS (INTEGRADO CON CONSULTA EXPRESS)
 * Hospital General - Huimanguillo, Tabasco
 */

// Forzar la visualización de cualquier error en pantalla para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();

    // 1. CONEXIÓN DIRECTA A LA BASE DE DATOS (AUTÓNOMA Y SEGURA)
    $host = 'localhost';
    $db   = 'hospital_db';$user = 'root';
    $pass = '';$charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn,$user, $pass,$options);

    // 2. CAPTURAR PARÁMETROS GET
    $id_paciente_param = trim($_GET['id_paciente'] ?? '');
    $medico_get        = trim($_GET['medico'] ?? '');

    $paciente = null;
    if (!empty($id_paciente_param)) {
        $stmt =$pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = ? OR expediente = ? LIMIT 1");
        $stmt->execute([$id_paciente_param,$id_paciente_param]);
        $paciente =$stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 3. ASIGNAR VARIABLES DE FORMA SEGURA
    $nombre_paciente = '';
    if ($paciente) {$n  = $paciente['nombre'] ?? ($paciente['nombres'] ?? '');
        $ap =$paciente['apaterno'] ?? ($paciente['apellido_paterno'] ?? '');$am = $paciente['amaterno'] ?? ($paciente['apellido_materno'] ?? '');
        $nombre_paciente = trim("$n $ap$am");
    }

    $curp_paciente   = $paciente['curp'] ?? '';$edad_paciente   = $paciente['edad'] ?? ($paciente['edad_paciente'] ?? '');
    $sexo_paciente   = strtoupper(trim($paciente['sexo'] ?? ($paciente['sexo_paciente'] ?? '')));$exp_paciente    = $paciente['expediente'] ?? ($paciente['no_expediente'] ?? ($paciente['id_paciente'] ?? 'S/N'));$medico_emisor   = !empty($medico_get) ?$medico_get : 'ANTONIO MORALES GARCIA - CÉD. PROF.: 2170531';

    // Catálogo completo de estudios con categorías exactas[cite: 20]
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

} catch (Exception $e) {
    echo "<div style='padding: 20px; background: #fee2e2; color: #991b1b; font-family: Arial; border: 1px solid #dc2626; margin: 20px; border-radius: 6px;'>";
    echo "<h3 style='margin-top:0;'><i class='fa-solid fa-triangle-exclamation'></i> Error detectado en el servidor:</h3>";
    echo "<p><strong>Mensaje:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Línea:</strong> " . $e->getLine() . "</p>";
    echo "</div>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Laboratorio Express</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde-color: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: var(--texto-oscuro); text-transform: uppercase; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: 700; font-size: 16px; }
        .container { max-width: 1000px; margin: 25px auto; padding: 0 20px; }
        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control, .form-select { width: 100%; padding: 9px 12px; font-size: 13px; border: 2px solid var(--borde-color); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; text-transform: uppercase; }
        .form-control:focus, .form-select:focus { border-color: var(--imss-verde); }
        .btn-nuevo { background-color: var(--imss-verde); color: white; border: none; padding: 10px 18px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-nuevo:hover { background-color: #004d35; }
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single, .select2-container .select2-selection--multiple { min-height: 38px; border: 2px solid var(--borde-color); border-radius: 6px; display: flex; align-items: center; }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-flask"></i> <strong>IMSS-BIENESTAR</strong> | Laboratorio Express</div>
        <a href="consulta_express.php?id_paciente=<?php echo urlencode($id_paciente_param); ?>&medico=<?php echo urlencode($medico_get); ?>"><i class="fa-solid fa-arrow-left"></i> Volver a Consulta Express</a>
    </div>

    <div class="container">
        <div class="card">
            <h3 style="margin-top:0; color: var(--imss-verde); font-size: 20px; border-bottom: 2px solid var(--borde-color); padding-bottom: 10px; margin-bottom: 20px;">
                <i class="fa-solid fa-file-medical"></i> Solicitud Rápida de Laboratorio e Imagenología
            </h3>

            <form id="formLabExpress" onsubmit="imprimirSolicitudLaboratorioExpress(event)">
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Nombre Completo del Paciente <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" id="ex_nombre" class="form-control" value="<?php echo htmlspecialchars($nombre_paciente); ?>" placeholder="NOMBRE COMPLETO" required oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>CURP <span style="color:var(--imss-dorado);">*</span></label>
                        <input type="text" id="ex_curp" class="form-control" value="<?php echo htmlspecialchars($curp_paciente); ?>" placeholder="CURP" maxlength="18" required oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group">
                        <label>Edad (Años)</label>
                        <input type="number" id="ex_edad" class="form-control" value="<?php echo htmlspecialchars($edad_paciente); ?>" placeholder="EDAD" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Sexo <span style="color:var(--imss-dorado);">*</span></label>
                        <select id="ex_sexo" class="form-select" required>
                            <option value="">-- SELECCIONAR --</option>
                            <option value="MASCULINO" <?php echo ($sexo_paciente === 'MASCULINO' \vert{}\vert{}$sexo_paciente === 'M') ? 'selected' : ''; ?>>MASCULINO</option>
                            <option value="FEMENINO" <?php echo ($sexo_paciente === 'FEMENINO' \vert{}\vert{}$sexo_paciente === 'F') ? 'selected' : ''; ?>>FEMENINO</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Servicio / Área <span style="color:var(--imss-dorado);">*</span></label>
                        <select id="ex_servicio" class="form-select" required>
                            <option value="URGENCIAS">URGENCIAS</option>
                            <option value="HOSPITALIZACIÓN">HOSPITALIZACIÓN</option>
                            <option value="CONSULTA EXTERNA" selected>CONSULTA EXTERNA</option>
                            <option value="CHOQUE">CHOQUE</option>
                            <option value="TOCO-CIRUGÍA">TOCO-CIRUGÍA</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Procedencia</label>
                        <input type="text" id="ex_procedencia" class="form-control" value="TABASCO" oninput="this.value = this.value.toUpperCase();">
                    </div>
                </div>

                <div class="form-group">
                    <label>Diagnóstico / Observaciones</label>
                    <input type="text" id="ex_diagnostico" class="form-control" value="<?php echo htmlspecialchars($exp_paciente); ?>" placeholder="DIAGNÓSTICO CLÍNICO" oninput="this.value = this.value.toUpperCase();">
                </div>

                <div class="form-group">
                    <label>Médico Tratante y Cédula Profesional <span style="color:var(--imss-dorado);">*</span></label>
                    <input type="text" id="ex_medico" class="form-control" value="<?php echo htmlspecialchars($medico_emisor); ?>" required oninput="this.value = this.value.toUpperCase();">
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-flask"></i> Selección de Estudios (Catálogo Oficial):[cite: 20]</label>
                    <select id="selectEstudiosLabMulti" class="form-control" multiple style="width: 100%;" data-placeholder="-- BUSCAR ESTUDIO O ABREVIATURA --">
                        <?php foreach ($cat_estudios_lab as$est):[cite: 20] ?>
                            <option value="<?php echo htmlspecialchars($est['estudio']); ?>">
                                <?php echo htmlspecialchars($est['estudio']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Estudios Seleccionados (Para Impresión):</label>
                    <textarea id="ex_estudios_texto" class="form-control" rows="3" placeholder="LOS ESTUDIOS SELECCIONADOS APARECERÁN AQUÍ..." oninput="this.value = this.value.toUpperCase();" required></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="submit" class="btn-nuevo" style="background-color: var(--imss-verde);">
                        <i class="fa-solid fa-print"></i> Generar e Imprimir Formato Oficial
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        const catalogoCompletoLab = <?php echo json_encode($cat_estudios_lab); ?>;

        $(document).ready(function() {$('#selectEstudiosLabMulti').select2({
                placeholder: '-- BUSCAR ESTUDIO O ABREVIATURA --',
                allowClear: true
            }).on('change', function() {
                let seleccionados = $(this).val() || [];
                document.getElementById('ex_estudios_texto').value = seleccionados.join(', ').toUpperCase();
            });
        });

        function imprimirSolicitudLaboratorioExpress(event) {
            event.preventDefault();

            let p = {
                nombre: document.getElementById('ex_nombre').value.toUpperCase(),
                curp: document.getElementById('ex_curp').value.toUpperCase(),
                edad: document.getElementById('ex_edad').value,
                sexo: document.getElementById('ex_sexo').value,
                servicio: document.getElementById('ex_servicio').value,
                procedencia: document.getElementById('ex_procedencia').value.toUpperCase(),
                diagnostico: document.getElementById('ex_diagnostico').value.toUpperCase() || 'S/D',
                medico: document.getElementById('ex_medico').value.toUpperCase(),
                estudios: document.getElementById('ex_estudios_texto').value.toUpperCase()
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
                        @page { size: letter portrait; margin: 3mm; }
                        html, body { width: 100%; height: 100%; margin: 0; padding: 0; }
                        body { font-family: Arial, sans-serif; font-size: 8.5px; color: #111; text-transform: uppercase; line-height: 1.15; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; }
                        .page-container { width: 100%; height: 100%; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; }
                        .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #006847; padding-bottom: 3px; margin-bottom: 4px; }
                        .header-table td { vertical-align: middle; border: none; }
                        .header-center { text-align: center; }
                        .header-center h2 { margin: 0; color: #006847; font-size: 10.5px; }
                        .header-center h3 { margin: 2px 0 0 0; color: #9f2241; font-size: 9px; }
                        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 8.5px; }
                        .info-grid td { padding: 2px 4px; border: 1px solid #cbd5e1; background: #f8fafc; }
                        .columns-container { width: 100%; display: table; table-layout: fixed; flex-grow: 1; }
                        .column { display: table-cell; width: 50%; vertical-align: top; padding: 0 3px; }
                        .section-cat { background: #006847; color: white; font-weight: bold; padding: 2px 5px; margin-top: 4px; font-size: 7.5px; border: 1px solid #004d35; }
                        .checkbox-table { width: 100%; border-collapse: collapse; font-size: 7.5px; margin-top: 1px; }
                        .checkbox-table td { padding: 0.8px 2px; vertical-align: middle; }
                        .box-cb { display: inline-block; width: 8.5px; height: 8.5px; border: 1px solid #000; text-align: center; line-height: 8.5px; font-size: 7.5px; font-weight: bold; margin-right: 3px; background: #fff; }
                        .footer-area { margin-top: 4px; }
                        .observaciones-box { font-size: 8px; border: 1px solid #cbd5e1; padding: 3px; margin-bottom: 4px; }
                        .firma-box { text-align: center; font-size: 8.5px; margin-bottom: 2px; }
                        .firma-line { display: inline-block; width: 260px; border-top: 1px solid #111; padding-top: 2px; }
                    </style>
                </head>
                <body>
                    <div class="page-container">
                        <div>
                            <table class="header-table">
                                <tr>
                                    <td width="20%" style="font-weight: bold; color: #006847; font-size: 9px;">HUIMANGUILLO<br><span style="font-size:6.5px; color:#555;">HOSPITAL GENERAL</span></td>
                                    <td width="60%" class="header-center">
                                       <h2>GOBIERNO DE MÉXICO &nbsp;|&nbsp; IMSS-BIENESTAR</h2>
                                       <h3>HOSPITAL GENERAL HUIMANGUILLO — LABORATORIO DE ANÁLISIS CLÍNICOS</h3>
                                    </td>
                                    <td width="20%" style="text-align: right; font-size: 7.5px;">
                                        <strong>FECHA:</strong> ${fechaActual}
                                    </td>
                                </tr>
                            </table>

                            <div style="text-align: center; font-weight: bold; font-size: 10px; margin-bottom: 2px; color: #006847;">SOLICITUD DE ESTUDIOS DE LABORATORIO</div>
                            <div style="text-align: center; font-size: 7.5px; font-weight: bold; margin-bottom: 3px;">HORARIO DE TOMA DE MUESTRA: LUNES A VIERNES 7:00 - 8:00 AM.</div>

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
                                        HOSPITALIZACIÓN: [ ${p.servicio === 'HOSPITALIZACIÓN' ? 'X' : ' '} ] &nbsp;&nbsp; 
                                        URGENCIAS: [ ${p.servicio === 'URGENCIAS' || p.servicio === 'CHOQUE' ? 'X' : ' '} ] &nbsp;&nbsp; 
                                        CONSULTA EXTERNA: [ ${p.servicio === 'CONSULTA EXTERNA' ? 'X' : ' '} ] &nbsp;&nbsp; 
                                        <strong>SERVICIO:</strong> ${p.servicio} &nbsp;&nbsp; 
                                        <strong>CAMA:</strong> S/C
                                    </td>
                                </tr>
                            </table>

                            <div class="columns-container">
                                <div class="column">
                                    ${renderCat(['HEMATOLOGÍA / COAGULACIÓN', 'UROANÁLISIS / PARASITOLOGÍA', 'QUÍMICA SANGUÍNEA'], categorias, estudiosArr)}
                                </div>
                                <div class="column">
                                    ${renderCat(['SEROLOGÍA', 'INMUNOLOGÍA ESPECIAL', 'MICROBIOLOGÍA', 'IMAGENOLOGÍA'], categorias, estudiosArr)}
                                </div>
                            </div>
                        </div>

                        <div class="footer-area">
                            <div class="observaciones-box">
                                <strong>OTRO ESTUDIO / OBSERVACIONES:</strong> ${p.estudios}
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

        function renderCat(listaCats, categorias, seleccionados) {
            let html = '';
            listaCats.forEach(catName => {
                if (!categorias[catName]) return;
                html += `<div class="section-cat">${catName}</div>`;
                html += `<table class="checkbox-table">`;
                categorias[catName].forEach(est => {
                    let marcado = seleccionados.some(s => est.toUpperCase().includes(s) || s.includes(est.toUpperCase())) ? 'X' : '&nbsp;';
                    html += `<tr><td width="13px"><span class="box-cb">${marcado}</span></td><td>${est}</td></tr>`;
                });
                html += `</table>`;
            });
            return html;
        }
    </script>
</body>
</html>