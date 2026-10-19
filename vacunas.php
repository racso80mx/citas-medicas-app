<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$mensaje = '';
$error = '';
$paciente_seleccionado_id = null;

// ===================================================================
// 2. PROCESAMIENTO DE ACCIONES (POST Y EXPORTACIONES GET)
// ===================================================================
$hoy_str = date('Y-m-d');
$accion = $_REQUEST['accion'] ?? '';

$tipo_filtro = $_GET['tipo_filtro'] ?? 'dia';
$filtro_fecha = $_GET['filtro_fecha'] ?? $hoy_str;
$fecha_inicio = $_GET['fecha_inicio'] ?? $hoy_str;
$fecha_fin = $_GET['fecha_fin'] ?? $hoy_str;

if ($tipo_filtro === 'hoy') {
    $f_ini = $hoy_str;
    $f_fin = $hoy_str;
} elseif ($tipo_filtro === 'manana') {
    $f_ini = date('Y-m-d', strtotime('+1 day'));
    $f_fin = date('Y-m-d', strtotime('+1 day'));
} elseif ($tipo_filtro === 'mes') {
    $f_ini = date('Y-m-01');
    $f_fin = date('Y-m-t');
} elseif ($tipo_filtro === 'rango') {
    $f_ini = !empty($fecha_inicio) ? $fecha_inicio : $hoy_str;
    $f_fin = !empty($fecha_fin) ? $fecha_fin : $hoy_str;
} else {
    $f_ini = !empty($filtro_fecha) ? $filtro_fecha : $hoy_str;
    $f_fin = !empty($filtro_fecha) ? $filtro_fecha : $hoy_str;
}

// Función auxiliar para validar teléfonos (10 dígitos y no uniformes como 0000000000)[cite: 41]
function validarTelefonoValido($tel) {
    if (!preg_match('/^\d{10}$/', $tel)) return false;
    if (preg_match('/^(\d)\1{9}$/', $tel)) return false; 
    return true;
}

// Asegurar columnas necesarias en la base de datos[cite: 41]
try {
    $pdo->exec("ALTER TABLE vacunas_citas ADD COLUMN IF NOT EXISTS curp VARCHAR(30)");
    $pdo->exec("ALTER TABLE vacunas_citas ADD COLUMN IF NOT EXISTS hora_cita VARCHAR(10)");
    $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS estado VARCHAR(100)");
    $pdo->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS fnacimiento VARCHAR(50)");
    $pdo->exec("ALTER TABLE config_vacunas ADD COLUMN IF NOT EXISTS horarios_bloqueados TEXT DEFAULT ''");
} catch (Exception $e) {}

// Exportar Excel de Citas de Vacunas[cite: 41]
if ($accion === 'exportar_excel') {
    if (ob_get_length()) ob_clean();

    try {
        $stmt_exp = $pdo->prepare("SELECT c.*, p.telefono FROM vacunas_citas c LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente WHERE c.fecha_cita BETWEEN :fini AND :ffin ORDER BY c.fecha_cita DESC, c.id_cita_vacuna DESC");
        $stmt_exp->execute(['fini' => $f_ini, 'ffin' => $f_fin]);
        $citas_excel = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=reporte_citas_vacunas_" . $f_ini . "_al_" . $f_fin . ".xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        echo "<table border='1'>";
        echo "<tr style='background-color:#006847; color:#ffffff; font-weight:bold;'>
                <th>ID Cita</th><th>Expediente</th><th>Paciente</th><th>CURP</th><th>Teléfono</th><th>Fecha Cita</th><th>Hora</th><th>Vacunas Aplicadas / Asignadas</th><th>Estatus</th>
              </tr>";
        foreach ($citas_excel as $c) {
            $st_det = $pdo->prepare("SELECT v.nombre_vacuna, v.abrev FROM vacunas_cita_detalles d JOIN catalogo_vacunas v ON d.id_vacuna = v.id_vacuna WHERE d.id_cita_vacuna = :id");
            $st_det->execute(['id' => $c['id_cita_vacuna']]);
            $vac_detalles = $st_det->fetchAll(PDO::FETCH_ASSOC);
            $str_vacunas = "";
            foreach($vac_detalles as $vd) {
                $str_vacunas .= "[" . $vd['abrev'] . "] " . $vd['nombre_vacuna'] . "; ";
            }

            echo "<tr>";
            echo "<td>" . htmlspecialchars($c['id_cita_vacuna']) . "</td>";
            echo "<td>" . htmlspecialchars($c['no_expediente'] ?? 'S/N') . "</td>";
            echo "<td>" . htmlspecialchars($c['nombre_paciente']) . "</td>";
            echo "<td>" . htmlspecialchars($c['curp'] ?? 'SIN CURP (RN)') . "</td>";
            echo "<td>" . htmlspecialchars($c['telefono'] ?? 'S/T') . "</td>";
            echo "<td>" . htmlspecialchars($c['fecha_cita']) . "</td>";
            echo "<td>" . htmlspecialchars($c['hora_cita'] ?? 'N/D') . "</td>";
            echo "<td>" . htmlspecialchars($str_vacunas) . "</td>";
            echo "<td>" . htmlspecialchars($c['estatus']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "Error al exportar Excel: " . $e->getMessage();
    }
    exit();
}

// Exportar PDF de Reporte de Citas[cite: 41]
if ($accion === 'exportar_pdf') {
    if (ob_get_length()) ob_clean();

    try {
        $stmt_pdf = $pdo->prepare("SELECT c.*, p.telefono FROM vacunas_citas c LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente WHERE c.fecha_cita BETWEEN :fini AND :ffin ORDER BY c.fecha_cita ASC, c.id_cita_vacuna ASC");
        $stmt_pdf->execute(['fini' => $f_ini, 'ffin' => $f_fin]);
        $citas_pdf = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);

        foreach ($citas_pdf as &$cp) {
            $st_det = $pdo->prepare("SELECT v.nombre_vacuna, v.abrev, v.rango_edad FROM vacunas_cita_detalles d JOIN catalogo_vacunas v ON d.id_vacuna = v.id_vacuna WHERE d.id_cita_vacuna = :id");
            $st_det->execute(['id' => $cp['id_cita_vacuna']]);
            $cp['vacunas'] = $st_det->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($cp);
    } catch (Exception $e) {
        $citas_pdf = [];
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Listado de Citas de Vacunación</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { background-color: #555; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; }
            .sheet { width: 210mm; min-height: 297mm; background: white; margin: 0 auto; padding: 15mm; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; justify-content: space-between; }
            .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 12px; margin-bottom: 15px; }
            .header-container img { max-height: 50px; width: auto; object-fit: contain; }
            .header-title { text-align: right; color: #006847; font-size: 20px; font-weight: bold; line-height: 1.2; }
            .banner-verde { background-color: #006847; color: white; text-align: center; font-size: 15px; font-weight: bold; padding: 6px; margin-bottom: 15px; letter-spacing: 1px; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 20px; }
            th { background-color: #f1f3f5; color: #333; padding: 8px 6px; border: 1px solid #dfe3e8; text-align: left; }
            td { padding: 7px 6px; border: 1px solid #dfe3e8; vertical-align: top; }
            tr:nth-child(even) { background-color: #fafbfc; }
            .no-print { text-align: center; margin-top: 20px; }
            .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
            @media print { body { background: none; padding: 0; } .sheet { box-shadow: none; padding: 10mm; width: 100%; margin: 0; min-height: auto; } .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="sheet">
            <div>
                <div class="header-container">
                    <img src="logo_hospital.png" alt="Logo Hospital" onerror="this.style.display='none'">
                    <div class="header-title">
                        HOSPITAL GENERAL DE HUIMANGUILLO<br><span style="font-size: 13px; color: #637381; font-weight: normal;">MÓDULO DE VACUNACIÓN - CITAS PROGRAMADAS</span>
                    </div>
                </div>
                <div class="banner-verde">REPORTE DE CITAS Y VACUNAS (DEL <?php echo $f_ini; ?> AL <?php echo $f_fin; ?>)</div>
                <table>
                    <thead>
                        <tr>
                            <th>Expediente</th>
                            <th>Paciente</th>
                            <th>CURP / Teléfono</th>
                            <th>Fecha / Hora</th>
                            <th>Vacunas y Rango de Edad</th>
                            <th>Estatus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($citas_pdf)): ?>
                            <?php foreach ($citas_pdf as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['no_expediente'] ?? 'S/N'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['nombre_paciente']); ?></td>
                                    <td>CURP: <?php echo htmlspecialchars($row['curp'] ?? 'SIN CURP (RN)'); ?><br>Tel: <strong><?php echo htmlspecialchars($row['telefono'] ?? 'S/T'); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($row['fecha_cita']); ?></strong><br><span style="color:#0288d1;"><?php echo htmlspecialchars($row['hora_cita'] ?? 'N/D'); ?></span></td>
                                    <td>
                                        <?php foreach ($row['vacunas'] as $vac): ?>
                                            <div style="margin-bottom: 4px;">
                                                <strong>[<?php echo htmlspecialchars($vac['abrev']); ?>]</strong> <?php echo htmlspecialchars($vac['nombre_vacuna']); ?><br>
                                                <span style="font-size: 9px; color: #555;">Rango de Edad: <?php echo htmlspecialchars($vac['rango_edad']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['estatus']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; padding: 20px; color: #637381;">No se encontraron registros de citas en el rango seleccionado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="no-print">
                <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir / Guardar PDF Carta</button>
                <a href="vacunas.php" class="btn" style="background-color: #637381; margin-left: 10px;"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Comprobante Individual PDF[cite: 41]
if ($accion === 'comprobante_cita_pdf') {
    if (ob_get_length()) ob_clean();
    $id_cita = intval($_GET['id_cita'] ?? 0);

    try {
        $st_c = $pdo->prepare("SELECT c.*, p.telefono FROM vacunas_citas c LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente WHERE c.id_cita_vacuna = :id");
        $st_c->execute(['id' => $id_cita]);
        $cita_info = $st_c->fetch(PDO::FETCH_ASSOC);

        $st_d = $pdo->prepare("SELECT v.* FROM vacunas_cita_detalles d JOIN catalogo_vacunas v ON d.id_vacuna = v.id_vacuna WHERE d.id_cita_vacuna = :id");
        $st_d->execute(['id' => $id_cita]);
        $vacunas_cita = $st_d->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cita_info = null;
        $vacunas_cita = [];
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Comprobante de Cita de Vacunación</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { background-color: #555; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; }
            .ticket { width: 200mm; background: white; margin: 0 auto; padding: 15mm; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.3); }
            .header-ticket { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 10px; margin-bottom: 15px; }
            .header-ticket img { max-height: 50px; width: auto; object-fit: contain; }
            .header-title { text-align: right; color: #006847; font-size: 18px; font-weight: bold; }
            .info-box { background: #f8f9fa; border: 1px solid #dfe3e8; border-radius: 6px; padding: 12px; margin-bottom: 15px; font-size: 13px; }
            .info-box p { margin: 4px 0; }
            .aviso-alerta { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 10px; border-radius: 6px; font-size: 13px; font-weight: bold; text-align: center; margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 20px; }
            th { background-color: #006847; color: white; padding: 8px; text-align: left; }
            td { padding: 8px; border: 1px solid #dfe3e8; vertical-align: top; }
            .no-print { text-align: center; margin-top: 20px; }
            .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
            @media print { body { background: none; padding: 0; } .ticket { box-shadow: none; padding: 10mm; width: 100%; } .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="ticket">
            <div>
                <div class="header-ticket">
                    <img src="logo_hospital.png" alt="Logo Hospital" onerror="this.style.display='none'">
                    <div class="header-title">
                        HOSPITAL GENERAL DE HUIMANGUILLO<br><span style="font-size: 12px; color: #637381; font-weight: normal;">COMPROBANTE DE CITA DE VACUNACIÓN</span>
                    </div>
                </div>

                <?php if ($cita_info): ?>
                    <div class="info-box">
                        <p><strong>Folio de Cita:</strong> #<?php echo $cita_info['id_cita_vacuna']; ?></p>
                        <p><strong>Paciente:</strong> <?php echo htmlspecialchars($cita_info['nombre_paciente']); ?></p>
                        <p><strong>Número de Expediente:</strong> <?php echo htmlspecialchars($cita_info['no_expediente'] ?? 'S/N'); ?></p>
                        <p><strong>CURP:</strong> <?php echo htmlspecialchars($cita_info['curp'] ?? 'SIN CURP (RN)'); ?></p>
                        <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($cita_info['telefono'] ?? 'S/T'); ?></p>
                        <p><strong>Fecha de Cita:</strong> <?php echo htmlspecialchars($cita_info['fecha_cita']); ?></p>
                        <p><strong>Hora Asignada:</strong> <span style="color: #006847; font-weight: bold; font-size: 15px;"><?php echo htmlspecialchars($cita_info['hora_cita'] ?? 'N/D'); ?></span></p>
                    </div>

                    <div class="aviso-alerta">
                        <i class="fa-solid fa-triangle-exclamation"></i> IMPORTANTE: Presentarse 10 minutos antes de su horario programado (<strong><?php echo htmlspecialchars($cita_info['hora_cita'] ?? '08:00'); ?></strong>) con su Cartilla de Vacunación.
                    </div>

                    <h4 style="color: #006847; margin-bottom: 8px;">Vacunas Asignadas e Indicaciones:</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Vacuna / Biológico</th>
                                <th>Enfermedad que Previene</th>
                                <th>Rango de Edad / Esquema</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vacunas_cita as $vac): ?>
                                <tr>
                                    <td><strong>[<?php echo htmlspecialchars($vac['abrev']); ?>]</strong> <?php echo htmlspecialchars($vac['nombre_vacuna']); ?></td>
                                    <td><?php echo htmlspecialchars($vac['enfermedad_previene']); ?></td>
                                    <td><?php echo htmlspecialchars($vac['rango_edad']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #9f2241;">Cita no encontrada.</p>
                <?php endif; ?>
            </div>

            <div class="no-print">
                <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Imprimir Comprobante</button>
                <a href="vacunas.php" class="btn" style="background-color: #637381; margin-left: 10px;"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($accion === 'actualizar_config') {
        $cantidad_citas       = intval($_POST['cantidad_citas'] ?? 20);
        $hora_inicio          = trim($_POST['hora_inicio'] ?? '08:00:00');
        $tiempo_atencion      = intval($_POST['tiempo_atencion'] ?? 15);
        $horarios_bloqueados  = trim($_POST['horarios_bloqueados'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE config_vacunas SET cantidad_citas = :cant, hora_inicio = :hi, tiempo_atencion = :ta, horarios_bloqueados = :hb WHERE id_config = 1");
            $stmt->execute(['cant' => $cantidad_citas, 'hi' => $hora_inicio, 'ta' => $tiempo_atencion, 'hb' => $horarios_bloqueados]);
            $mensaje = "Configuración de vacunación actualizada correctamente.";
        } catch (Exception $e) {
            $error = "Error al actualizar la configuración: " . $e->getMessage();
        }
    }

    if ($accion === 'actualizar_telefono_curp_paciente') {
        $id_paciente    = intval($_POST['id_paciente_up'] ?? 0);
        $nuevo_telefono = trim($_POST['nuevo_telefono'] ?? '');
        $nueva_curp     = trim(strtoupper($_POST['nueva_curp'] ?? ''));
        if (strlen($nueva_curp) > 18) {
            $nueva_curp = substr($nueva_curp, 0, 18);
        }

        $c_fecha_cita   = trim($_POST['c_fecha_cita'] ?? '');
        $c_hora_cita    = trim($_POST['c_hora_cita'] ?? '');
        $c_vacunas      = $_POST['c_vacunas'] ?? [];

        if ($id_paciente > 0 && !empty($nuevo_telefono)) {
            if (!validarTelefonoValido($nuevo_telefono)) {
                $error = "El número telefónico ingresado no es válido.";
            } else {
                try {
                    $fnacimiento_act = null;
                    $year_nac_act = null;
                    $edad_act = null;
                    $sexo_act = null;

                    if (!empty($nueva_curp) && strlen($nueva_curp) >= 11) {
                        $digitoAnio = substr($nueva_curp, 4, 2);
                        $digitoMes = substr($nueva_curp, 6, 2);
                        $digitoDia = substr($nueva_curp, 8, 2);
                        $siglo = (intval($digitoAnio) > 30) ? "19" : "20";
                        $year_nac_act = $siglo . $digitoAnio;
                        $fnacimiento_act = "{$year_nac_act}-{$digitoMes}-{$digitoDia}";
                        
                        try {
                            $bDate = new DateTime($fnacimiento_act);
                            $today = new DateTime('today');
                            $edad_act = $bDate->diff($today)->y;
                        } catch (Exception $ex) {}

                        $letraSexo = substr($nueva_curp, 10, 1);
                        $sexo_act = ($letraSexo === 'H') ? 'HOMBRE' : (($letraSexo === 'M') ? 'MUJER' : null);
                    }

                    $sql_up = "UPDATE pacientes SET telefono = :tel";
                    $params_up = ['tel' => $nuevo_telefono, 'id' => $id_paciente];

                    if (!empty($nueva_curp)) {
                        $sql_up .= ", curp = :curp";
                        $params_up['curp'] = $nueva_curp;
                    }
                    if (!empty($fnacimiento_act)) {
                        $sql_up .= ", fnacimiento = :fnac, year_nacimiento = :ynac, edad = :edad, edad_actual = :eact";
                        $params_up['fnac'] = $fnacimiento_act;
                        $params_up['ynac'] = $year_nac_act;
                        $params_up['edad'] = $edad_act;
                        $params_up['eact'] = $edad_act;
                    }
                    if (!empty($sexo_act)) {
                        $sql_up .= ", sexo = :sexo";
                        $params_up['sexo'] = $sexo_act;
                    }

                    $sql_up .= " WHERE id_paciente = :id";
                    $stmt_p_up = $pdo->prepare($sql_up);
                    $stmt_p_up->execute($params_up);

                    if (!empty($c_fecha_cita) && !empty($c_hora_cita) && !empty($c_vacunas)) {
                        $cfg = $pdo->query("SELECT cantidad_citas FROM config_vacunas WHERE id_config = 1")->fetch(PDO::FETCH_ASSOC);
                        $max_cupo = $cfg ? intval($cfg['cantidad_citas']) : 20;

                        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM vacunas_citas WHERE fecha_cita = :f");
                        $stmt_count->execute(['f' => $c_fecha_cita]);
                        $ocupadas = intval($stmt_count->fetchColumn());

                        if ($ocupadas < $max_cupo) {
                            $stmt_pac = $pdo->prepare("SELECT expediente, nombre, apaterno, amaterno, curp FROM pacientes WHERE id_paciente = :id");
                            $stmt_pac->execute(['id' => $id_paciente]);
                            $pac = $stmt_pac->fetch(PDO::FETCH_ASSOC);

                            if ($pac) {
                                $nombre_completo = trim($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno']);
                                $expediente      = $pac['expediente'] ?? 'S/N';
                                $curp_pac        = $pac['curp'] ?? '';

                                $stmt_ins = $pdo->prepare("INSERT INTO vacunas_citas (id_paciente, no_expediente, nombre_paciente, curp, fecha_cita, hora_cita, estatus) VALUES (:id_pac, :exp, :nom, :curp, :f, :h, 'PROGRAMADA')");
                                $stmt_ins->execute(['id_pac' => $id_paciente, 'exp' => $expediente, 'nom' => $nombre_completo, 'curp' => $curp_pac, 'f' => $c_fecha_cita, 'h' => $c_hora_cita]);
                                $id_cita_vacuna = $pdo->lastInsertId();

                                $stmt_det = $pdo->prepare("INSERT INTO vacunas_cita_detalles (id_cita_vacuna, id_vacuna) VALUES (:cita, :vac)");
                                foreach ($c_vacunas as $id_vac) {
                                    $stmt_det->execute(['cita' => $id_cita_vacuna, 'vac' => intval($id_vac)]);
                                }

                                header("Location: vacunas.php?accion=comprobante_cita_pdf&id_cita=" . $id_cita_vacuna);
                                exit();
                            }
                        }
                    }

                    $paciente_seleccionado_id = $id_paciente;
                    $mensaje = "Datos actualizados correctamente.";
                } catch (Exception $e) {
                    $error = "Error al actualizar datos: " . $e->getMessage();
                }
            }
        } else {
            $error = "El teléfono es obligatorio (10 dígitos).";
        }
    }

    if ($accion === 'guardar_vacuna') {
        $id_vacuna           = intval($_POST['id_vacuna'] ?? 0);
        $area_tipo           = trim($_POST['area_tipo'] ?? '');
        $nombre_vacuna       = trim($_POST['nombre_vacuna'] ?? '');
        $abrev               = trim($_POST['abrev'] ?? '');
        $enfermedad_previene = trim($_POST['enfermedad_previene'] ?? '');
        $rango_edad          = trim($_POST['rango_edad'] ?? '');

        if (!empty($area_tipo) && !empty($nombre_vacuna) && !empty($abrev)) {
            try {
                if ($id_vacuna > 0) {
                    $stmt = $pdo->prepare("UPDATE catalogo_vacunas SET area_tipo = :at, nombre_vacuna = :nv, abrev = :ab, enfermedad_previene = :ep, rango_edad = :re WHERE id_vacuna = :id");
                    $stmt->execute(['at' => $area_tipo, 'nv' => $nombre_vacuna, 'ab' => $abrev, 'ep' => $enfermedad_previene, 're' => $rango_edad, 'id' => $id_vacuna]);
                    $mensaje = "Vacuna actualizada correctamente.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO catalogo_vacunas (area_tipo, nombre_vacuna, abrev, enfermedad_previene, rango_edad) VALUES (:at, :nv, :ab, :ep, :re)");
                    $stmt->execute(['at' => $area_tipo, 'nv' => $nombre_vacuna, 'ab' => $abrev, 'ep' => $enfermedad_previene, 're' => $rango_edad]);
                    $mensaje = "Vacuna agregada al catálogo.";
                }
            } catch (Exception $e) {
                $error = "Error al guardar la vacuna: " . $e->getMessage();
            }
        }
    }

    if ($accion === 'eliminar_vacuna') {
        $id_vacuna = intval($_POST['id_vacuna'] ?? 0);
        if ($id_vacuna > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM catalogo_vacunas WHERE id_vacuna = :id");
                $stmt->execute(['id' => $id_vacuna]);
                $mensaje = "Vacuna eliminada del catálogo.";
            } catch (Exception $e) {
                $error = "No se puede eliminar porque está vinculada a citas.";
            }
        }
    }

    // REGISTRAR PACIENTE COMPLETO CON SOPORTE RN Y VALIDACIÓN DE CURP (MÁX 18 CARACTERES)[cite: 41]
    if ($accion === 'registrar_paciente_rapido') {
        $nombre           = trim($_POST['p_nombre'] ?? '');
        $apaterno         = trim($_POST['p_apaterno'] ?? '');
        $amaterno         = trim($_POST['p_amaterno'] ?? '');
        $expediente       = trim($_POST['p_expediente'] ?? '');
        $curp             = trim(strtoupper($_POST['p_curp'] ?? ''));
        if (strlen($curp) > 18) {
            $curp = substr($curp, 0, 18);
        }
        $telefono         = trim($_POST['p_telefono'] ?? '');
        $estado           = trim($_POST['p_estado'] ?? '');
        $municipio        = trim($_POST['p_municipio'] ?? '');
        $domicilio        = trim($_POST['p_domicilio'] ?? '');
        $colonia          = trim($_POST['p_colonia'] ?? '');
        $sexo             = trim($_POST['p_sexo'] ?? '');
        $fnacimiento      = trim($_POST['p_fnacimiento'] ?? '');
        $nombre_padre     = trim($_POST['p_nombre_padre'] ?? '');
        $nombre_madre     = trim($_POST['p_nombre_madre'] ?? '');
        $edad_padre       = trim($_POST['p_edad_padre'] ?? '');
        $edad_madre       = trim($_POST['p_edad_madre'] ?? '');
        $derechohabiencia = trim($_POST['p_derechohabiencia'] ?? '');
        $tipo_paciente    = trim($_POST['p_tipo_paciente'] ?? 'GENERAL');
        $es_rn            = isset($_POST['es_rn']) ? true : false;

        // Si se ingresó una CURP válida de al menos 11 caracteres, recalcular la fecha de nacimiento automáticamente desde la CURP[cite: 41]
        if (!empty($curp) && strlen($curp) >= 11 && strpos($curp, 'RN-') === false) {
            $digAnio = substr($curp, 4, 2);
            $digMes  = substr($curp, 6, 2);
            $digDia  = substr($curp, 8, 2);
            $siglo   = (intval($digAnio) > 30) ? "19" : "20";
            $fnacimiento = "{$siglo}{$digAnio}-{$digMes}-{$digDia}";
        }

        if (!validarTelefonoValido($telefono)) {
            $error = "El número telefónico ingresado no es válido.";
        } else {
            if ($es_rn) {
                if (empty($expediente) || strpos($expediente, 'SE-') === false) {
                    $countRn = $pdo->query("SELECT COUNT(*) FROM pacientes WHERE expediente LIKE 'SE-%'")->fetchColumn();
                    $secuencial = str_pad($countRn + 1, 5, '0', STR_PAD_LEFT);
                    $expediente = "SE-" . date('Y') . "-" . $secuencial;
                }
                if (empty($curp)) {
                    $countRnCurp = $pdo->query("SELECT COUNT(*) FROM pacientes WHERE curp LIKE 'RN-%'")->fetchColumn();
                    $secuencialCurp = str_pad($countRnCurp + 1, 5, '0', STR_PAD_LEFT);
                    $curp = "RN-" . date('Y') . "-" . $secuencialCurp;
                }
            } elseif (empty($expediente)) {
                $expediente = strval(rand(100000, 999999));
            }

            $year_nacimiento = !empty($fnacimiento) ? substr($fnacimiento, 0, 4) : null;
            $edad = 0;
            if (!empty($fnacimiento)) {
                try {
                    $bDate = new DateTime($fnacimiento);
                    $today = new DateTime('today');
                    $edad = $bDate->diff($today)->y;
                } catch (Exception $e) {}
            }
            $edad_actual = $edad;
            $nombre_completo = trim("$nombre $apaterno $amaterno");

            if (!empty($nombre)) {
                try {
                    if (!empty($curp) && strpos($curp, 'RN-') === false) {
                        $stmt_chk = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE curp = :curp LIMIT 1");
                        $stmt_chk->execute(['curp' => $curp]);
                        if ($stmt_chk->fetch(PDO::FETCH_ASSOC)) {
                            $error = "El CURP ingresado ya existe en el sistema.";
                        }
                    }

                    if (empty($error)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO pacientes (
                                expediente, nombre, apaterno, amaterno, fnacimiento, year_nacimiento, 
                                edad, edad_actual, sexo, estado, municipio, domicilio, colonia, 
                                nombre_padre, nombre_madre, edad_padre, edad_madre, fecha_apertura, 
                                estatus, telefono, nombre_completo, curp, nombre_apellidos, derechohabiencia, tipo_paciente, fecha_registro
                            ) VALUES (
                                :exp, :nom, :ap, :am, :fnac, :ynac, 
                                :edad, :eact, :sexo, :est, :mun, :dom, :col, 
                                :npad, :nmad, :epad, :emad, CURRENT_DATE, 
                                'Vigente', :tel, :ncomp, :curp, :napat, :derech, :tipo, CURRENT_TIMESTAMP
                            ) RETURNING id_paciente
                        ");
                        $stmt->execute([
                            'exp'    => $expediente, 'nom' => $nombre, 'ap' => $apaterno, 'am' => $amaterno,
                            'fnac'   => !empty($fnacimiento) ? $fnacimiento : null, 'ynac' => $year_nacimiento,
                            'edad'   => $edad, 'eact' => $edad_actual, 'sexo' => $sexo, 'est' => $estado,
                            'mun'    => $municipio, 'dom' => $domicilio, 'col' => $colonia,
                            'npad'   => $nombre_padre, 'nmad' => $nombre_madre, 'epad' => $edad_padre, 'emad' => $edad_madre,
                            'tel'    => $telefono, 'ncomp' => $nombre_completo, 'curp' => $curp,
                            'napat'  => $nombre_completo, 'derech' => $derechohabiencia, 'tipo' => $tipo_paciente
                        ]);
                        $paciente_seleccionado_id = $stmt->fetchColumn();
                        $mensaje = "Paciente registrado y seleccionado correctamente.";
                    }
                } catch (Exception $e) {
                    $error = "Error al registrar el paciente: " . $e->getMessage();
                }
            }
        }
    }

    if ($accion === 'registrar_cita_vacuna') {
        $id_paciente  = intval($_POST['id_paciente'] ?? 0);
        $fecha_cita   = trim($_POST['fecha_cita'] ?? '');
        $hora_cita    = trim($_POST['hora_cita'] ?? '');
        $vacunas_sel  = $_POST['vacunas'] ?? [];

        if ($id_paciente > 0 && !empty($fecha_cita) && !empty($hora_cita) && !empty($vacunas_sel)) {
            try {
                $st_tel = $pdo->prepare("SELECT telefono FROM pacientes WHERE id_paciente = :id");
                $st_tel->execute(['id' => $id_paciente]);
                $tel_pac = trim($st_tel->fetchColumn());

                if (empty($tel_pac) || !validarTelefonoValido($tel_pac)) {
                    $error = "El paciente seleccionado no cuenta con un número de teléfono válido.";
                } else {
                    $cfg = $pdo->query("SELECT cantidad_citas FROM config_vacunas WHERE id_config = 1")->fetch(PDO::FETCH_ASSOC);
                    $max_cupo = $cfg ? intval($cfg['cantidad_citas']) : 20;

                    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM vacunas_citas WHERE fecha_cita = :f");
                    $stmt_count->execute(['f' => $fecha_cita]);
                    $ocupadas = intval($stmt_count->fetchColumn());

                    if ($ocupadas >= $max_cupo) {
                        $error = "El cupo para la fecha {$fecha_cita} está completo.";
                    } else {
                        $stmt_pac = $pdo->prepare("SELECT expediente, nombre, apaterno, amaterno, curp FROM pacientes WHERE id_paciente = :id");
                        $stmt_pac->execute(['id' => $id_paciente]);
                        $pac = $stmt_pac->fetch(PDO::FETCH_ASSOC);

                        if ($pac) {
                            $nombre_completo = trim($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno']);
                            $expediente      = $pac['expediente'] ?? 'S/N';
                            $curp_pac        = $pac['curp'] ?? '';

                            $stmt_ins = $pdo->prepare("INSERT INTO vacunas_citas (id_paciente, no_expediente, nombre_paciente, curp, fecha_cita, hora_cita, estatus) VALUES (:id_pac, :exp, :nom, :curp, :f, :h, 'PROGRAMADA')");
                            $stmt_ins->execute(['id_pac' => $id_paciente, 'exp' => $expediente, 'nom' => $nombre_completo, 'curp' => $curp_pac, 'f' => $fecha_cita, 'h' => $hora_cita]);
                            $id_cita_vacuna = $pdo->lastInsertId();

                            $stmt_det = $pdo->prepare("INSERT INTO vacunas_cita_detalles (id_cita_vacuna, id_vacuna) VALUES (:cita, :vac)");
                            foreach ($vacunas_sel as $id_vac) {
                                $stmt_det->execute(['cita' => $id_cita_vacuna, 'vac' => intval($id_vac)]);
                            }

                            header("Location: vacunas.php?accion=comprobante_cita_pdf&id_cita=" . $id_cita_vacuna);
                            exit();
                        }
                    }
                }
            } catch (Exception $e) {
                $error = "Error al registrar la cita: " . $e->getMessage();
            }
        } else {
            $error = "Debe seleccionar un paciente, fecha, horario y al menos una vacuna.";
        }
    }

    if ($accion === 'editar_cita_vacuna') {
        $id_cita_vacuna = intval($_POST['id_cita_vacuna'] ?? 0);
        $fecha_cita     = trim($_POST['fecha_cita'] ?? '');
        $hora_cita      = trim($_POST['hora_cita'] ?? '');
        $vacunas_sel    = $_POST['vacunas'] ?? [];

        if ($id_cita_vacuna > 0 && !empty($fecha_cita) && !empty($hora_cita) && !empty($vacunas_sel)) {
            try {
                $stmt_up = $pdo->prepare("UPDATE vacunas_citas SET fecha_cita = :f, hora_cita = :h WHERE id_cita_vacuna = :id");
                $stmt_up->execute(['f' => $fecha_cita, 'h' => $hora_cita, 'id' => $id_cita_vacuna]);

                $pdo->prepare("DELETE FROM vacunas_cita_detalles WHERE id_cita_vacuna = :id")->execute(['id' => $id_cita_vacuna]);
                $stmt_det = $pdo->prepare("INSERT INTO vacunas_cita_detalles (id_cita_vacuna, id_vacuna) VALUES (:cita, :vac)");
                foreach ($vacunas_sel as $id_vac) {
                    $stmt_det->execute(['cita' => $id_cita_vacuna, 'vac' => intval($id_vac)]);
                }
                $mensaje = "Cita de vacunación actualizada correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar la cita: " . $e->getMessage();
            }
        } else {
            $error = "Debe proporcionar una fecha, un horario y al menos una vacuna.";
        }
    }

    if ($accion === 'eliminar_cita_vacuna') {
        $id_cita_vacuna = intval($_POST['id_cita_vacuna'] ?? 0);
        if ($id_cita_vacuna > 0) {
            try {
                $pdo->prepare("DELETE FROM vacunas_citas WHERE id_cita_vacuna = :id")->execute(['id' => $id_cita_vacuna]);
                $mensaje = "Cita eliminada correctamente.";
            } catch (Exception $e) {
                $error = "Error al eliminar la cita.";
            }
        }
    }
}

// ===================================================================
// 3. DATOS Y FILTROS PARA LA VISTA
// ===================================================================
$config_vac = $pdo->query("SELECT * FROM config_vacunas WHERE id_config = 1")->fetch(PDO::FETCH_ASSOC);
$cantidad_citas       = $config_vac ? intval($config_vac['cantidad_citas']) : 20;
$hora_inicio          = $config_vac['hora_inicio'] ?? '08:00:00';
$tiempo_atencion      = intval($config_vac['tiempo_atencion'] ?? 15);
$horarios_bloqueados_str = $config_vac['horarios_bloqueados'] ?? '';
$horarios_bloqueados_arr = array_filter(array_map('trim', explode(',', $horarios_bloqueados_str)));

$filtro_busqueda_paciente = $_GET['q_paciente'] ?? '';

$stmt_ocupadas = $pdo->prepare("SELECT COUNT(*) FROM vacunas_citas WHERE fecha_cita = :f");
$stmt_ocupadas->execute(['f' => $hoy_str]);
$citas_ocupadas_hoy = intval($stmt_ocupadas->fetchColumn());
$espacios_libres = max(0, $cantidad_citas - $citas_ocupadas_hoy);

$mes_actual_inicio = date('Y-m-01');
$mes_actual_fin = date('Y-m-t');
$stmt_ocu_mes = $pdo->prepare("SELECT fecha_cita, COUNT(*) as total FROM vacunas_citas WHERE fecha_cita BETWEEN :ini AND :fin GROUP BY fecha_cita");
$stmt_ocu_mes->execute(['ini' => $mes_actual_inicio, 'fin' => $mes_actual_fin]);
$ocupacion_mes_raw = $stmt_ocu_mes->fetchAll(PDO::FETCH_ASSOC);
$ocupacion_por_dia = [];
foreach($ocupacion_mes_raw as $om) {
    $ocupacion_por_dia[$om['fecha_cita']] = intval($om['total']);
}

$stmt_ocu_horas = $pdo->prepare("SELECT fecha_cita, hora_cita FROM vacunas_citas WHERE fecha_cita BETWEEN :ini AND :fin");
$stmt_ocu_horas->execute(['ini' => $mes_actual_inicio, 'fin' => $mes_actual_fin]);
$citas_horas_raw = $stmt_ocu_horas->fetchAll(PDO::FETCH_ASSOC);
$citas_ocupadas_por_fecha = [];
foreach($citas_horas_raw as $ch) {
    $f = $ch['fecha_cita'];
    $h = substr($ch['hora_cita'], 0, 5);
    if (!isset($citas_ocupadas_por_fecha[$f])) {
        $citas_ocupadas_por_fecha[$f] = [];
    }
    $citas_ocupadas_por_fecha[$f][] = $h;
}

$stmt_citas = $pdo->prepare("SELECT c.*, p.telefono FROM vacunas_citas c LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente WHERE c.fecha_cita BETWEEN :fini AND :ffin ORDER BY c.fecha_cita DESC, c.id_cita_vacuna DESC");
$stmt_citas->execute(['fini' => $f_ini, 'ffin' => $f_fin]);
$citas_lista = $stmt_citas->fetchAll(PDO::FETCH_ASSOC);

foreach ($citas_lista as &$cita) {
    $stmt_vac_cita = $pdo->prepare("
        SELECT v.id_vacuna, v.nombre_vacuna, v.abrev, v.area_tipo, v.enfermedad_previene, v.rango_edad 
        FROM vacunas_cita_detalles d 
        JOIN catalogo_vacunas v ON d.id_vacuna = v.id_vacuna 
        WHERE d.id_cita_vacuna = :id
    ");
    $stmt_vac_cita->execute(['id' => $cita['id_cita_vacuna']]);
    $cita['vacunas'] = $stmt_vac_cita->fetchAll(PDO::FETCH_ASSOC);
}
unset($cita);

$catalogo_vacunas = $pdo->query("SELECT * FROM catalogo_vacunas ORDER BY area_tipo ASC, nombre_vacuna ASC")->fetchAll(PDO::FETCH_ASSOC);

$estados_mexico = [
    "AGUASCALIENTES", "BAJA CALIFORNIA", "BAJA CALIFORNIA SUR", "CAMPECHE", 
    "CHIAPAS", "CHIHUAHUA", "CIUDAD DE MÉXICO", "COAHUILA", "COLIMA", 
    "DURANGO", "ESTADO DE MÉXICO", "GUANAJUATO", "GUERRERO", "HIDALGO", 
    "JALISCO", "MICHOACÁN", "MORELOS", "NAYARIT", "NUEVO LEÓN", "OAXACA", 
    "PUEBLA", "QUERÉTARO", "QUINTANA ROO", "SAN LUIS POTOSÍ", "SINALOA", 
    "SONORA", "TABASCO", "TAMAULIPAS", "TLAXCALA", "VERACRUZ", "YUCATÁN", "ZACATECAS"
];

$sql_pac = "SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp, telefono FROM pacientes";
$params_pac = [];

if (!empty($fil_p = trim($filtro_busqueda_paciente))) {
    $palabras = preg_split('/\s+/', $fil_p);
    $whereClauses = [];
    
    foreach ($palabras as $index => $palabra) {
        $paramName = "q_" . $index;
        $whereClauses[] = "(nombre ILIKE :{$paramName} OR apaterno ILIKE :{$paramName} OR amaterno ILIKE :{$paramName} OR nombre_completo ILIKE :{$paramName} OR nombre_apellidos ILIKE :{$paramName} OR expediente ILIKE :{$paramName} OR curp ILIKE :{$paramName} OR telefono ILIKE :{$paramName})";
        $params_pac[$paramName] = '%' . $palabra . '%';
    }
    
    if (!empty($whereClauses)) {
        $sql_pac .= " WHERE " . implode(" AND ", $whereClauses);
    }
} elseif (!empty($paciente_seleccionado_id)) {
    $sql_pac = "SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp, telefono FROM pacientes WHERE id_paciente = :id_sel UNION SELECT id_paciente, nombre, apaterno, amaterno, expediente, curp, telefono FROM pacientes WHERE id_paciente <> :id_sel";
    $params_pac['id_sel'] = $paciente_seleccionado_id;
}
$sql_pac .= " ORDER BY id_paciente DESC LIMIT 50";

$stmt_p = $pdo->prepare($sql_pac);
$stmt_p->execute($params_pac);
$pacientes_grid = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

$expediente_aleatorio = "SE-" . date('Y') . "-" . strval(rand(10000, 99999));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Módulo de Vacunación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-verde-hover: #004d34; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde-color: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: var(--texto-oscuro); }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: 700; font-size: 16px; }
        .container { max-width: 1400px; margin: 25px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 24px; margin: 0; color: var(--imss-verde); display: flex; align-items: center; gap: 10px; }
        
        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); margin-bottom: 25px; }
        .card-title { font-size: 18px; font-weight: 700; margin-top: 0; margin-bottom: 15px; color: var(--texto-oscuro); display: flex; align-items: center; gap: 10px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 9px 12px; font-size: 14px; border: 2px solid var(--borde-color); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; }
        .form-control:focus { border-color: var(--imss-verde); }

        .btn-action { background-color: var(--imss-verde); color: white; border: none; padding: 9px 16px; font-size: 14px; font-weight: 700; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-action:hover { background-color: var(--imss-verde-hover); }
        
        .btn-delete { background-color: var(--imss-dorado); color: white; border: none; padding: 5px 10px; font-size: 12px; border-radius: 4px; cursor: pointer; }
        .btn-edit { background-color: #0288d1; color: white; border: none; padding: 5px 10px; font-size: 12px; border-radius: 4px; cursor: pointer; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 10px; font-weight: 700; border-bottom: 2px solid var(--borde-color); white-space: nowrap; }
        table td { padding: 10px; border-bottom: 1px solid var(--borde-color); vertical-align: middle; }
        table tr:hover { background-color: #f8f9fa; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 800px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde-color); padding-bottom: 10px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: var(--imss-verde); font-size: 18px; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #637381; }

        .badge-cupo { display: inline-block; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 14px; background: #e3f2fd; color: #0288d1; }
        .badge-lleno { background: #ffebee; color: #c62828; }
        
        .vacuna-chip { display: inline-flex; align-items: center; gap: 8px; background: #e1f5fe; color: #0288d1; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; margin: 4px; }
        .vacuna-chip button { background: none; border: none; color: #c62828; cursor: pointer; font-size: 14px; font-weight: bold; }

        .mini-calendario { background: #fff; border: 1px solid var(--borde-color); border-radius: 8px; padding: 12px; margin-top: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .mini-calendario-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-weight: bold; color: var(--imss-verde); }
        .mini-calendario-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; font-size: 11px; }
        .mini-calendario-grid div { padding: 4px 2px; border-radius: 4px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .mini-calendario-grid .day-header { font-weight: bold; color: #637381; background: #f1f3f5; }
        .mini-calendario-grid .day-cell { cursor: pointer; background: #f8f9fa; border: 1px solid #dfe3e8; min-height: 38px; }
        .mini-calendario-grid .day-cell:hover { opacity: 0.8; }
        .mini-calendario-grid .day-cell.selected { border: 2px solid var(--imss-verde) !important; font-weight: bold; }
        
        .semaforo-verde { background-color: #e4f8dd !important; color: #006847 !important; border-color: #c8e6c9 !important; }
        .semaforo-amarillo { background-color: #fff9c4 !important; color: #f57f17 !important; border-color: #fff59d !important; }
        .semaforo-rojo { background-color: #ffebee !important; color: #c62828 !important; border-color: #ffcdd2 !important; }
        .badge-count { font-size: 9px; font-weight: bold; margin-top: 2px; padding: 1px 4px; border-radius: 3px; background: rgba(0,0,0,0.06); }

        .horarios-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; max-height: 130px; overflow-y: auto; margin-top: 10px; padding: 6px; border: 1px solid var(--borde-color); border-radius: 6px; background: #fafbfc; }
        .horario-slot { background: #e4f8dd; color: var(--imss-verde); font-size: 11px; font-weight: bold; text-align: center; padding: 6px; border-radius: 4px; border: 1px solid #c8e6c9; cursor: pointer; transition: all 0.2s; }
        .horario-slot:hover { background: #c8e6c9; }
        .horario-slot.selected { background: var(--imss-verde) !important; color: white !important; border-color: var(--imss-verde-hover); box-shadow: 0 0 5px rgba(0,104,71,0.4); }
        .horario-slot.ocupado { background-color: #ffebee !important; color: #c62828 !important; border-color: #ffcdd2 !important; cursor: not-allowed; opacity: 0.8; }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-syringe"></i> <strong>IMSS-BIENESTAR</strong> | Módulo de Vacunación</div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <button type="button" class="btn-action" style="background-color: var(--imss-dorado); padding: 6px 12px; font-size: 12px;" onclick="abrirModalConfig()">
                <i class="fa-solid fa-gear"></i> Configurar Cupo Máximo Citas
            </button>
            <button type="button" class="btn-action" style="background-color: #0288d1; padding: 6px 12px; font-size: 12px;" onclick="abrirModalCatalogo()">
                <i class="fa-solid fa-book-medical"></i> Catálogo de Vacunas
            </button>
            <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1><i class="fa-solid fa-syringe"></i> Asignación y Registro de Citas de Vacunación</h1>
            <div class="badge-cupo <?php echo ($espacios_libres == 0) ? 'badge-lleno' : ''; ?>">
                Cupo Hoy (<?php echo $hoy_str; ?>): <?php echo $citas_ocupadas_hoy; ?> / <?php echo $cantidad_citas; ?> | Hora Inicio: <strong><?php echo htmlspecialchars($hora_inicio); ?></strong>
            </div>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- TARJETA PRINCIPAL: 2 COLUMNAS -->
        <div class="card">
            <h2 class="card-title"><i class="fa-solid fa-calendar-plus"></i> Nueva Cita de Vacunación</h2>
            
            <form action="vacunas.php" method="POST" id="formCitaVacuna">
                <input type="hidden" name="accion" value="registrar_cita_vacuna">
                <input type="hidden" name="hora_cita" id="inputHoraCitaSeleccionada" value="">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; align-items: start; margin-bottom: 20px;">
                    
                    <div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label><i class="fa-solid fa-calendar-days"></i> Fecha de la Cita (Seleccione o ingrese):</label>
                            <input type="date" name="fecha_cita" id="inputFechaCita" class="form-control" required value="<?php echo htmlspecialchars($hoy_str); ?>" onchange="actualizarCalendarioVisual(this.value)">
                        </div>
                        
                        <div class="mini-calendario">
                            <div class="mini-calendario-header">
                                <span id="calMesAnio">Octubre 2026</span>
                                <div>
                                    <button type="button" onclick="cambiarMesCal(-1)" style="border:none; background:none; cursor:pointer;"><i class="fa-solid fa-chevron-left"></i></button>
                                    <button type="button" onclick="cambiarMesCal(1)" style="border:none; background:none; cursor:pointer;"><i class="fa-solid fa-chevron-right"></i></button>
                                </div>
                            </div>
                            <div class="mini-calendario-grid" id="miniCalGrid"></div>
                            
                            <div style="display: flex; justify-content: space-around; font-size: 10px; margin-top: 8px; border-top: 1px solid #dfe3e8; padding-top: 6px; color: #637381;">
                                <span><i class="fa-solid fa-circle" style="color: #2e7d32;"></i> Disponible (&lt;70%)</span>
                                <span><i class="fa-solid fa-circle" style="color: #f57f17;"></i> Medio (70-99%)</span>
                                <span><i class="fa-solid fa-circle" style="color: #c62828;"></i> Lleno (100%)</span>
                            </div>
                        </div>

                        <div style="margin-top: 15px;">
                            <label style="font-weight: 700; font-size: 12px; color: #637381;"><i class="fa-solid fa-clock"></i> Seleccione el Horario de Atención:</label>
                            <div class="horarios-grid" id="horariosDisponiblesGrid"></div>
                            <div id="errorHoraMsg" style="color: var(--imss-dorado); font-size: 11px; font-weight: bold; margin-top: 4px; display: none;">* Debe seleccionar un horario disponible.</div>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label style="margin: 0; font-weight: 700; font-size: 13px;"><i class="fa-solid fa-user-magnifying-glass"></i> Buscar y Seleccionar Paciente:</label>
                                <button type="button" class="btn-action" style="background-color: var(--imss-dorado); padding: 5px 10px; font-size: 11px;" onclick="abrirModalPaciente()">
                                    <i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente
                                </button>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <div style="display: flex; gap: 8px;">
                                    <input type="text" id="inputFiltroPac" class="form-control" placeholder="Escriba nombre, CURP, teléfono..." value="<?php echo htmlspecialchars($filtro_busqueda_paciente); ?>" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                                    <button type="button" class="btn-action" onclick="filtrarPacientesGrid()"><i class="fa-solid fa-search"></i> Buscar</button>
                                </div>
                            </div>

                            <div style="max-height: 200px; overflow-y: auto; border: 2px solid var(--borde-color); border-radius: 6px;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width: 35px; text-align: center;">Sel</th>
                                            <th>Expediente</th>
                                            <th>Paciente</th>
                                            <th>CURP</th>
                                            <th>Teléfono</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(count($pacientes_grid) > 0): ?>
                                            <?php foreach($pacientes_grid as $pac): ?>
                                                <?php 
                                                    $tel_db = $pac['telefono'] ?? '';
                                                    $es_tel_valido = !empty($tel_db) && preg_match('/^\d{10}$/', $tel_db) && !preg_match('/^(\d)\1{9}$/', $tel_db);
                                                ?>
                                                <tr id="row_pac_<?php echo $pac['id_paciente']; ?>" <?php echo ($paciente_seleccionado_id == $pac['id_paciente']) ? 'style="background-color: #e4f8dd; font-weight: bold;"' : ''; ?>>
                                                    <td style="text-align: center;">
                                                        <input type="radio" name="id_paciente" value="<?php echo $pac['id_paciente']; ?>" data-telefono="<?php echo htmlspecialchars($tel_db); ?>" data-curp="<?php echo htmlspecialchars($pac['curp'] ?? ''); ?>" data-nombre="<?php echo htmlspecialchars($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno']); ?>" <?php echo ($paciente_seleccionado_id == $pac['id_paciente']) ? 'checked' : ''; ?> required style="cursor: pointer; width: 16px; height: 16px;">
                                                    </td>
                                                    <td><strong><?php echo htmlspecialchars($pac['expediente'] ?? 'S/N'); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($pac['nombre'] . ' ' . $pac['apaterno'] . ' ' . $pac['amaterno']); ?></td>
                                                    <td><?php echo htmlspecialchars($pac['curp'] ?? 'SIN CURP (RN)'); ?></td>
                                                    <td>
                                                        <?php if($es_tel_valido): ?>
                                                            <span style="color: var(--imss-verde); font-weight: bold;"><?php echo htmlspecialchars($tel_db); ?></span>
                                                        <?php else: ?>
                                                            <span style="color: var(--imss-dorado); font-weight: bold; font-size: 11px;"><i class="fa-solid fa-triangle-exclamation"></i> S/ Teléfono Válido</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" style="text-align: center; color: #637381; padding: 15px;">No se encontraron pacientes. Registre uno nuevo.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid var(--borde-color);">
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label><i class="fa-solid fa-book-medical"></i> Búsqueda y Adición de Vacunas:</label>
                                <div style="display: flex; gap: 8px; margin-top: 5px;">
                                    <input type="text" id="inputBuscaVacuna" class="form-control" list="listaVacunasCatalogo" placeholder="Escriba abreviatura (ej. BCG)..." autocomplete="off" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                                    <datalist id="listaVacunasCatalogo">
                                        <?php foreach($catalogo_vacunas as $vc): ?>
                                            <option value="<?php echo htmlspecialchars($vc['abrev']); ?>">[<?php echo htmlspecialchars($vc['abrev']); ?>] <?php echo htmlspecialchars($vc['nombre_vacuna']); ?> - <?php echo htmlspecialchars($vc['rango_edad']); ?></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                    <button type="button" class="btn-action" onclick="agregarVacunaPorInput()"><i class="fa-solid fa-plus"></i> Agregar</button>
                                </div>
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label><i class="fa-solid fa-syringe"></i> Vacunas Asignadas a la Cita:</label>
                                <div id="contenedorVacunasSeleccionadas" style="min-height: 45px; border: 2px dashed var(--borde-color); border-radius: 6px; padding: 6px 10px; background: #fff; display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;">
                                    <span id="sinVacunasMsg" style="color: #919eab; font-size: 13px; margin: auto;">Ninguna vacuna agregada todavía.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn-action" style="font-size: 15px; padding: 12px 24px;" onclick="return validarFormularioCita()"><i class="fa-solid fa-calendar-check"></i> Confirmar y Agendar Cita de Vacunación</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h2 class="card-title" style="margin: 0;"><i class="fa-solid fa-calendar-days"></i> Citas y Vacunas Programadas</h2>
                
                <form method="GET" id="formFiltrosCitas" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 0;">
                    <select name="tipo_filtro" id="selectTipoFiltro" class="form-control" style="width: auto; padding: 6px 10px;" onchange="cambiarTipoFiltro()">
                        <option value="hoy" <?php echo ($tipo_filtro === 'hoy') ? 'selected' : ''; ?>>Hoy</option>
                        <option value="manana" <?php echo ($tipo_filtro === 'manana') ? 'selected' : ''; ?>>Mañana</option>
                        <option value="dia" <?php echo ($tipo_filtro === 'dia') ? 'selected' : ''; ?>>Día Específico</option>
                        <option value="mes" <?php echo ($tipo_filtro === 'mes') ? 'selected' : ''; ?>>Este Mes</option>
                        <option value="rango" <?php echo ($tipo_filtro === 'rango') ? 'selected' : ''; ?>>Rango de Fechas</option>
                    </select>

                    <div id="filtroDiaContainer" style="display: <?php echo ($tipo_filtro === 'dia') ? 'block' : 'none'; ?>;">
                        <input type="date" name="filtro_fecha" class="form-control" value="<?php echo htmlspecialchars($filtro_fecha); ?>" style="padding: 6px 10px;" onchange="this.form.submit()">
                    </div>

                    <div id="filtroRangoContainer" style="display: <?php echo ($tipo_filtro === 'rango') ? 'flex' : 'none'; ?>; gap: 6px; align-items: center;">
                        <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($fecha_inicio); ?>" style="padding: 6px 10px;" title="Fecha Inicio">
                        <span style="font-size: 12px; font-weight: bold;">a</span>
                        <input type="date" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($fecha_fin); ?>" style="padding: 6px 10px;" title="Fecha Fin">
                        <button type="submit" class="btn-action" style="padding: 6px 12px; font-size: 12px;"><i class="fa-solid fa-filter"></i></button>
                    </div>

                    <div style="display: flex; gap: 8px; align-items: center; margin-left: 10px;">
                        <a href="vacunas.php?accion=exportar_pdf&tipo_filtro=<?php echo $tipo_filtro; ?>&filtro_fecha=<?php echo $filtro_fecha; ?>&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" target="_blank" class="btn-action" style="background-color: var(--imss-dorado); padding: 8px 12px; font-size: 13px;">
                            <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                        </a>
                        <a href="vacunas.php?accion=exportar_excel&tipo_filtro=<?php echo $tipo_filtro; ?>&filtro_fecha=<?php echo $filtro_fecha; ?>&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" class="btn-action" style="background-color: #217346; padding: 8px 12px; font-size: 13px;">
                            <i class="fa-solid fa-file-excel"></i> Exportar Excel
                        </a>
                    </div>
                </form>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Expediente</th>
                            <th>Paciente</th>
                            <th>CURP / Teléfono</th>
                            <th>Fecha / Hora</th>
                            <th>Vacunas Solicitadas</th>
                            <th>Estatus</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($citas_lista) > 0): ?>
                            <?php foreach($citas_lista as $cita): ?>
                                <tr>
                                    <td><?php echo $cita['id_cita_vacuna']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($cita['no_expediente'] ?? 'S/N'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cita['nombre_paciente']); ?></td>
                                    <td>
                                        CURP: <strong><?php echo htmlspecialchars($cita['curp'] ?? 'SIN CURP (RN)'); ?></strong><br>
                                        Tel: <span style="color: var(--imss-verde); font-weight: bold;"><?php echo htmlspecialchars($cita['telefono'] ?? 'S/T'); ?></span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($cita['fecha_cita']); ?></strong><br><span style="color: #0288d1; font-weight: bold;"><?php echo htmlspecialchars($cita['hora_cita'] ?? 'N/D'); ?></span></td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <?php foreach($cita['vacunas'] as $v): ?>
                                                <div style="background: #e1f5fe; color: #0288d1; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                    <strong>[<?php echo htmlspecialchars($v['abrev']); ?>]</strong> <?php echo htmlspecialchars($v['nombre_vacuna']); ?> 
                                                    <span style="color: #333; display: block; font-size: 11px; margin-top: 2px;">
                                                        <i class="fa-solid fa-shield-virus"></i> Previene: <?php echo htmlspecialchars($v['enfermedad_previene']); ?> | 
                                                        <i class="fa-solid fa-child"></i> Edad: <?php echo htmlspecialchars($v['rango_edad']); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><span style="background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-size: 11px; font-weight: bold;"><?php echo htmlspecialchars($cita['estatus']); ?></span></td>
                                    <td style="text-align: center; display: flex; gap: 5px; justify-content: center;">
                                        <a href="vacunas.php?accion=comprobante_cita_pdf&id_cita=<?php echo $cita['id_cita_vacuna']; ?>" target="_blank" class="btn-action" style="padding: 5px 8px; font-size: 11px; background-color: #0288d1;" title="Imprimir Comprobante"><i class="fa-solid fa-print"></i></a>
                                        <button type="button" class="btn-edit" onclick="abrirModalEditarCita(<?php echo $cita['id_cita_vacuna']; ?>, '<?php echo htmlspecialchars($cita['nombre_paciente'], ENT_QUOTES); ?>', '<?php echo $cita['fecha_cita']; ?>', '<?php echo htmlspecialchars($cita['hora_cita'] ?? ''); ?>', <?php echo htmlspecialchars(json_encode(array_column($cita['vacunas'], 'id_vacuna'))); ?>)"><i class="fa-solid fa-pen"></i></button>
                                        <form action="vacunas.php" method="POST" onsubmit="return confirm('¿Está seguro de eliminar esta cita?');" style="margin:0;">
                                            <input type="hidden" name="accion" value="eliminar_cita_vacuna">
                                            <input type="hidden" name="id_cita_vacuna" value="<?php echo $cita['id_cita_vacuna']; ?>">
                                            <input type="hidden" name="tipo_filtro" value="<?php echo $tipo_filtro; ?>">
                                            <input type="hidden" name="filtro_fecha" value="<?php echo $filtro_fecha; ?>">
                                            <input type="hidden" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                                            <input type="hidden" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                                            <button type="submit" class="btn-delete"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; color: #637381; padding: 25px;">No hay citas programadas para el filtro seleccionado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL OBLIGATORIO: ACTUALIZAR TELÉFONO Y CURP -->
    <div class="modal-overlay" id="modalActualizarTelefonoCurp">
        <div class="modal-content" style="max-width: 450px; border-top: 5px solid var(--imss-dorado);">
            <div class="modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation" style="color: var(--imss-dorado);"></i> Teléfono Requerido o Inválido</h3>
            </div>
            <p style="font-size: 13px; color: #555;">El paciente seleccionado <strong><span id="lblNombrePacienteFaltaTel"></span></strong> no cuenta con un número de teléfono válido. Capture un número real y su CURP (máximo 18 caracteres); la cita se agendará automáticamente.</p>
            
            <form action="vacunas.php" method="POST" onsubmit="return validarModalTelefonoForm()">
                <input type="hidden" name="accion" value="actualizar_telefono_curp_paciente">
                <input type="hidden" name="id_paciente_up" id="inputPacienteIdUp">
                <input type="hidden" name="c_fecha_cita" id="modal_c_fecha_cita">
                <input type="hidden" name="c_hora_cita" id="modal_c_hora_cita">
                <div id="modalContainerVacunasHidden"></div>
                
                <div class="form-group">
                    <label>Número de Teléfono (10 dígitos reales):</label>
                    <input type="tel" name="nuevo_telefono" id="inputNuevoTel" class="form-control" required pattern="[0-9]{10}" maxlength="10" placeholder="Ej. 9931234567">
                </div>

                <div class="form-group">
                    <label>Actualizar CURP (Máx. 18 caracteres):</label>
                    <input type="text" name="nueva_curp" id="inputNuevaCurp" class="form-control" maxlength="18" placeholder="CURP de 18 caracteres" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalFaltaTel()">Cancelar</button>
                    <button type="submit" class="btn-action" style="background-color: var(--imss-dorado);"><i class="fa-solid fa-save"></i> Guardar y Agendar Cita</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL CONFIGURACIÓN Y HORARIO -->
    <div class="modal-overlay" id="modalConfig">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-gear"></i> Configuración de Cupo y Horario</h3>
                <button type="button" class="close-modal" onclick="cerrarModalConfig()">&times;</button>
            </div>
            <form action="vacunas.php" method="POST">
                <input type="hidden" name="accion" value="actualizar_config">
                <div class="form-group">
                    <label>Máximo de citas permitidas por día (Cupo Diario):</label>
                    <input type="number" name="cantidad_citas" class="form-control" value="<?php echo $cantidad_citas; ?>" required min="1" max="200">
                </div>
                <div class="form-group">
                    <label>Hora de Inicio:</label>
                    <input type="text" name="hora_inicio" class="form-control" value="<?php echo htmlspecialchars($hora_inicio); ?>" required placeholder="Ej. 08:00:00">
                </div>
                <div class="form-group">
                    <label>Tiempo de Atención por Cita (minutos):</label>
                    <input type="number" name="tiempo_atencion" class="form-control" value="<?php echo $tiempo_atencion; ?>" required min="1" max="120">
                </div>
                <div class="form-group">
                    <label>Horarios Bloqueados Automáticos (separados por coma):</label>
                    <input type="text" name="horarios_bloqueados" class="form-control" value="<?php echo htmlspecialchars($horarios_bloqueados_str); ?>" placeholder="Ej. 13:00, 13:15, 14:00">
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalConfig()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar Configuración</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL CATÁLOGO DE VACUNAS -->
    <div class="modal-overlay" id="modalCatalogo">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-book-medical"></i> Catálogo de Vacunas</h3>
                <button type="button" class="close-modal" onclick="cerrarModalCatalogo()">&times;</button>
            </div>
            <div style="margin-bottom: 15px; text-align: right;">
                <button type="button" class="btn-action" style="padding: 6px 12px; font-size: 12px;" onclick="abrirModalVacunaForm()">
                    <i class="fa-solid fa-plus"></i> Nueva Vacuna
                </button>
            </div>
            <div style="max-height: 350px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Área / Tipo</th>
                            <th>Vacuna</th>
                            <th>Abrev.</th>
                            <th>Enfermedad que Previene</th>
                            <th>Rango de Edad / Esquema</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($catalogo_vacunas as $vac): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($vac['area_tipo']); ?></strong></td>
                                <td><?php echo htmlspecialchars($vac['nombre_vacuna']); ?></td>
                                <td><span style="background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-size:11px;"><?php echo htmlspecialchars($vac['abrev']); ?></span></td>
                                <td><?php echo htmlspecialchars($vac['enfermedad_previene']); ?></td>
                                <td><span style="font-size: 11px;"><?php echo htmlspecialchars($vac['rango_edad']); ?></span></td>
                                <td style="text-align: center; display: flex; gap: 4px; justify-content: center;">
                                    <button type="button" class="btn-edit" onclick="editarVacuna(<?php echo $vac['id_vacuna']; ?>, '<?php echo htmlspecialchars($vac['area_tipo'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($vac['nombre_vacuna'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($vac['abrev'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($vac['enfermedad_previene'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($vac['rango_edad'], ENT_QUOTES); ?>')"><i class="fa-solid fa-pen"></i></button>
                                    <form action="vacunas.php" method="POST" onsubmit="return confirm('¿Eliminar vacuna?');" style="margin:0;">
                                        <input type="hidden" name="accion" value="eliminar_vacuna">
                                        <input type="hidden" name="id_vacuna" value="<?php echo $vac['id_vacuna']; ?>">
                                        <button type="submit" class="btn-delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL FORMULARIO VACUNA -->
    <div class="modal-overlay" id="modalVacunaForm">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">
                <h3 id="tituloModalVacuna"><i class="fa-solid fa-book-medical"></i> Registrar / Editar Vacuna</h3>
                <button type="button" class="close-modal" onclick="cerrarModalVacunaForm()">&times;</button>
            </div>
            <form action="vacunas.php" method="POST">
                <input type="hidden" name="accion" value="guardar_vacuna">
                <input type="hidden" name="id_vacuna" id="vacuna_id">
                <div class="form-group"><label>Área / Tipo:</label><input type="text" name="area_tipo" id="vacuna_area" class="form-control" required style="text-transform: uppercase;"></div>
                <div class="form-group"><label>Nombre de la Vacuna:</label><input type="text" name="nombre_vacuna" id="vacuna_nombre" class="form-control" required></div>
                <div class="form-group"><label>Abreviación (Nombre Corto):</label><input type="text" name="abrev" id="vacuna_abrev" class="form-control" required style="text-transform: uppercase;"></div>
                <div class="form-group"><label>Enfermedad que Previene:</label><input type="text" name="enfermedad_previene" id="vacuna_enfermedad" class="form-control" required></div>
                <div class="form-group"><label>Rango de Edad / Esquema:</label><input type="text" name="rango_edad" id="vacuna_rango" class="form-control" required></div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalVacunaForm()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL REGISTRO DE PACIENTE COMPLETO (CON AUTO-CÁLCULO DE FECHA DESDE CURP) -->
    <div class="modal-overlay" id="modalPacienteRapido">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente (Completo)</h3>
                <button type="button" class="close-modal" onclick="cerrarModalPaciente()">&times;</button>
            </div>
            <form action="vacunas.php" method="POST" onsubmit="return validarRegistroPacienteForm(this)">
                <input type="hidden" name="accion" value="registrar_paciente_rapido">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group"><label>Nombre(s):</label><input type="text" name="p_nombre" class="form-control" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                    <div class="form-group"><label>Apellido Paterno:</label><input type="text" name="p_apaterno" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group"><label>Apellido Materno:</label><input type="text" name="p_amaterno" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <label style="margin:0;">Número de Expediente:</label>
                            <label style="font-weight: normal; font-size: 11px; cursor: pointer; color: var(--imss-verde);">
                                <input type="checkbox" name="es_rn" id="chkEsRn" value="1" onchange="toggleRnMode()" style="cursor: pointer;"> Es RN (Prefijo SE-)
                            </label>
                        </div>
                        <input type="text" name="p_expediente" id="inputExpedientePaciente" class="form-control" value="<?php echo $expediente_aleatorio; ?>">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Estado de Nacimiento:</label>
                        <select name="p_estado" class="form-control" required>
                            <option value="">Seleccione estado...</option>
                            <?php foreach($estados_mexico as $est): ?>
                                <option value="<?php echo $est; ?>" <?php echo ($est === 'TABASCO') ? 'selected' : ''; ?>><?php echo $est; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Municipio:</label><input type="text" name="p_municipio" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group"><label>Domicilio (Calle / Núm):</label><input type="text" name="p_domicilio" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                    <div class="form-group"><label>Colonia / Localidad:</label><input type="text" name="p_colonia" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Sexo:</label>
                        <select name="p_sexo" class="form-control" id="selectSexoPaciente">
                            <option value="">Seleccione...</option>
                            <option value="HOMBRE">HOMBRE</option>
                            <option value="MUJER">MUJER</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Fecha de Nacimiento:</label><input type="date" name="p_fnacimiento" id="inputFnacimientoPaciente" class="form-control" required value="<?php echo $hoy_str; ?>"></div>
                    <div class="form-group">
                        <label>CURP (Máx. 18 car.):</label>
                        <input type="text" name="p_curp" id="inputCurpPaciente" class="form-control" maxlength="18" placeholder="CURP de 18 caracteres" style="text-transform: uppercase;" oninput="procesarCurpAuto(this.value)">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group"><label>Nombre del Padre:</label><input type="text" name="p_nombre_padre" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                    <div class="form-group"><label>Nombre de la Madre:</label><input type="text" name="p_nombre_madre" class="form-control" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();"></div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group"><label>Edad del Padre:</label><input type="text" name="p_edad_padre" class="form-control"></div>
                    <div class="form-group"><label>Edad de la Madre:</label><input type="text" name="p_edad_madre" class="form-control"></div>
                    <div class="form-group"><label>Derechohabiencia:</label><input type="text" name="p_derechohabiencia" class="form-control" value="IMSS-BIENESTAR"></div>
                </div>

                <div class="form-group">
                    <label>Teléfono (10 dígitos obligatorios y no uniformes):</label>
                    <input type="tel" name="p_telefono" id="inputRegTel" class="form-control" required pattern="[0-9]{10}" maxlength="10" placeholder="Ej. 9931234567">
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalPaciente()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar Paciente</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR CITA -->
    <div class="modal-overlay" id="modalEditarCita">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Cita de Vacunación</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditarCita()">&times;</button>
            </div>
            <form action="vacunas.php" method="POST">
                <input type="hidden" name="accion" value="editar_cita_vacuna">
                <input type="hidden" name="id_cita_vacuna" id="edit_id_cita">
                <input type="hidden" name="tipo_filtro" value="<?php echo $tipo_filtro; ?>">
                <input type="hidden" name="filtro_fecha" value="<?php echo $filtro_fecha; ?>">
                <input type="hidden" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                <input type="hidden" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                
                <div class="form-group">
                    <label>Paciente:</label>
                    <input type="text" id="edit_nombre_paciente" class="form-control" readonly style="background: #f1f3f5; font-weight: bold;">
                </div>

                <div class="form-group">
                    <label>Fecha de la Cita:</label>
                    <input type="date" name="fecha_cita" id="edit_fecha_cita" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Hora de la Cita:</label>
                    <input type="text" name="hora_cita" id="edit_hora_cita" class="form-control" required placeholder="Ej. 08:00">
                </div>

                <div class="form-group">
                    <label>Agregar Vacunas (Escriba abreviatura y presione ENTER):</label>
                    <input type="text" id="inputBuscaVacunaEdit" class="form-control" placeholder="Ej. BCG, Hexavalente..." autocomplete="off" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                </div>

                <div class="form-group">
                    <label>Vacunas Asignadas:</label>
                    <div id="contenedorVacunasEdit" style="min-height: 60px; border: 2px dashed var(--borde-color); border-radius: 6px; padding: 10px; background: #fafbfc; display: flex; flex-wrap: wrap; gap: 5px;"></div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-action" style="background: #637381; margin-right: 10px;" onclick="cerrarModalEditarCita()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const catalogoVacunas = <?php echo json_encode($catalogo_vacunas); ?>;
        const ocupacionMesData = <?php echo json_encode($ocupacion_por_dia); ?>;
        const citasOcupadasPorFecha = <?php echo json_encode($citas_ocupadas_por_fecha); ?>;
        const horariosBloqueadosFijos = <?php echo json_encode($horarios_bloqueados_arr); ?>;
        
        const horaInicioConfig = "<?php echo $hora_inicio; ?>";
        const tiempoAtencionConfig = <?php echo $tiempo_atencion; ?>;
        const maxCitasConfig = <?php echo $cantidad_citas; ?>;

        let vacunasSeleccionadasIDs = [];
        let vacunasEditIDs = [];
        let calAnioActual = new Date().getFullYear();
        let calMesActual = new Date().getMonth();

        // Función JavaScript para extraer automáticamente la fecha de nacimiento y género desde la CURP
        function procesarCurpAuto(curpVal) {
            curpVal = curpVal.toUpperCase().trim();
            if (curpVal.length > 18) {
                curpVal = curpVal.substring(0, 18);
                document.getElementById('inputCurpPaciente').value = curpVal;
            }

            if (curpVal.length >= 10) {
                let yy = curpVal.substring(4, 6);
                let mm = curpVal.substring(6, 8);
                let dd = curpVal.substring(8, 10);
                
                let sigli = (parseInt(yy, 10) > 30) ? "19" : "20";
                let fechaNac = `${sigli}${yy}-${mm}-${dd}`;
                
                // Validar si es una fecha válida
                let dateTest = new Date(fechaNac);
                if (!isNaN(dateTest.getTime())) {
                    document.getElementById('inputFnacimientoPaciente').value = fechaNac;
                }
            }

            if (curpVal.length >= 11) {
                let generoChar = curpVal.substring(10, 11);
                let selectSexo = document.getElementById('selectSexoPaciente');
                if (generoChar === 'H') {
                    selectSexo.value = 'HOMBRE';
                } else if (generoChar === 'M') {
                    selectSexo.value = 'MUJER';
                }
            }
        }

        function esTelefonoUniformeOGenerico(tel) {
            if (!/^\d{10}$/.test(tel)) return true;
            if (/^(\d)\1{9}$/.test(tel)) return true;
            return false;
        }

        function generarHorariosDistribuidos() {
            const grid = document.getElementById('horariosDisponiblesGrid');
            grid.innerHTML = '';

            let fechaSel = document.getElementById('inputFechaCita').value;
            let horasOcupadasFecha = citasOcupadasPorFecha[fechaSel] || [];

            let [h, m, s] = horaInicioConfig.split(':').map(Number);
            let fechaBaseMinutes = h * 60 + m;

            let horaSeleccionadaActual = document.getElementById('inputHoraCitaSeleccionada').value;

            for (let i = 0; i < maxCitasConfig; i++) {
                let totalMin = fechaBaseMinutes + (i * tiempoAtencionConfig);
                let currentH = Math.floor(totalMin / 60);
                let currentM = totalMin % 60;
                let horaStr = String(currentH).padStart(2, '0') + ':' + String(currentM).padStart(2, '0');

                let slot = document.createElement('div');
                
                let estaOcupado = horasOcupadasFecha.includes(horaStr);
                let estaBloqueadoFijo = horariosBloqueadosFijos.includes(horaStr);

                if (estaOcupado || estaBloqueadoFijo) {
                    slot.className = 'horario-slot ocupado';
                    slot.innerHTML = `<i class="fa-solid fa-ban"></i> ${horaStr} <br><span style="font-size:9px;">${estaOcupado ? '(Ocupado)' : '(Bloqueado)'}</span>`;
                } else {
                    slot.className = 'horario-slot';
                    if (horaStr === horaSeleccionadaActual) {
                        slot.classList.add('selected');
                    }
                    slot.innerHTML = `<i class="fa-regular fa-clock"></i> ${horaStr}`;
                    slot.onclick = function() {
                        document.querySelectorAll('.horario-slot').forEach(s => s.classList.remove('selected'));
                        slot.classList.add('selected');
                        document.getElementById('inputHoraCitaSeleccionada').value = horaStr;
                        document.getElementById('errorHoraMsg').style.display = 'none';
                    };
                }
                grid.appendChild(slot);
            }
        }

        function generarMiniCalendario(anio, mes, fechaSeleccionadaStr) {
            const mesesNombres = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
            document.getElementById('calMesAnio').innerText = `${mesesNombres[mes]} ${anio}`;

            const grid = document.getElementById('miniCalGrid');
            grid.innerHTML = '';

            const diasSemana = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];
            diasSemana.forEach(d => {
                let divH = document.createElement('div');
                divH.className = 'day-header';
                divH.innerText = d;
                grid.appendChild(divH);
            });

            let primerDiaIndex = new Date(anio, mes, 1).getDay();
            primerDiaIndex = (primerDiaIndex === 0) ? 6 : primerDiaIndex - 1;
            let totalDias = new Date(anio, mes + 1, 0).getDate();

            for (let i = 0; i < primerDiaIndex; i++) {
                let emptyDiv = document.createElement('div');
                grid.appendChild(emptyDiv);
            }

            for (let dia = 1; dia <= totalDias; dia++) {
                let mFormatted = String(mes + 1).padStart(2, '0');
                let dFormatted = String(dia).padStart(2, '0');
                let fechaStr = `${anio}-${mFormatted}-${dFormatted}`;

                let ocupados = ocupacionMesData[fechaStr] || 0;
                let porcentaje = (ocupados / maxCitasConfig) * 100;

                let claseSemaforo = 'semaforo-verde';
                if (porcentaje >= 100) {
                    claseSemaforo = 'semaforo-rojo';
                } else if (porcentaje >= 70) {
                    claseSemaforo = 'semaforo-amarillo';
                }

                let cell = document.createElement('div');
                cell.className = `day-cell ${claseSemaforo}`;
                if (fechaStr === fechaSeleccionadaStr) {
                    cell.classList.add('selected');
                }

                cell.innerHTML = `<span>${dia}</span><span class="badge-count">${ocupados}/${maxCitasConfig}</span>`;
                cell.onclick = function() {
                    document.getElementById('inputFechaCita').value = fechaStr;
                    generarMiniCalendario(anio, mes, fechaStr);
                    document.getElementById('inputHoraCitaSeleccionada').value = '';
                    generarHorariosDistribuidos();
                };
                grid.appendChild(cell);
            }
        }

        function cambiarMesCal(dir) {
            calMesActual += dir;
            if (calMesActual > 11) { calMesActual = 0; calAnioActual++; }
            else if (calMesActual < 0) { calMesActual = 11; calAnioActual--; }
            let fechaSel = document.getElementById('inputFechaCita').value;
            generarMiniCalendario(calAnioActual, calMesActual, fechaSel);
        }

        function actualizarCalendarioVisual(valFecha) {
            if (valFecha) {
                let partes = valFecha.split('-');
                calAnioActual = parseInt(partes[0]);
                calMesActual = parseInt(partes[1]) - 1;
                generarMiniCalendario(calAnioActual, calMesActual, valFecha);
                document.getElementById('inputHoraCitaSeleccionada').value = '';
                generarHorariosDistribuidos();
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            let valIni = document.getElementById('inputFechaCita').value;
            actualizarCalendarioVisual(valIni);
            generarHorariosDistribuidos();
        });

        function validarFormularioCita() {
            let radioPaciente = document.querySelector('input[name="id_paciente"]:checked');
            if (!radioPaciente) {
                alert('Por favor, seleccione un paciente de la lista.');
                return false;
            }

            let telPaciente = radioPaciente.getAttribute('data-telefono');
            let curpPaciente = radioPaciente.getAttribute('data-curp');
            let nombrePaciente = radioPaciente.getAttribute('data-nombre');
            let pacId = radioPaciente.value;

            let horaSel = document.getElementById('inputHoraCitaSeleccionada').value;
            let fechaSel = document.getElementById('inputFechaCita').value;

            if (!horaSel) {
                document.getElementById('errorHoraMsg').style.display = 'block';
                alert('Por favor, seleccione un horario disponible de la lista.');
                return false;
            }

            if (vacunasSeleccionadasIDs.length === 0) {
                alert('Por favor, seleccione al menos una vacuna.');
                return false;
            }

            if (!telPaciente || esTelefonoUniformeOGenerico(telPaciente)) {
                document.getElementById('inputPacienteIdUp').value = pacId;
                document.getElementById('lblNombrePacienteFaltaTel').innerText = nombrePaciente;
                document.getElementById('inputNuevaCurp').value = curpPaciente;
                
                document.getElementById('modal_c_fecha_cita').value = fechaSel;
                document.getElementById('modal_c_hora_cita').value = horaSel;
                
                const vacContainer = document.getElementById('modalContainerVacunasHidden');
                vacContainer.innerHTML = '';
                vacunasSeleccionadasIDs.forEach(vid => {
                    let inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'c_vacunas[]';
                    inp.value = vid;
                    vacContainer.appendChild(inp);
                });

                document.getElementById('modalActualizarTelefonoCurp').style.display = 'flex';
                return false;
            }

            return true;
        }

        function validarModalTelefonoForm() {
            let tel = document.getElementById('inputNuevoTel').value.trim();
            if (esTelefonoUniformeOGenerico(tel)) {
                alert('El número telefónico no es válido.');
                return false;
            }
            return true;
        }

        function validarRegistroPacienteForm(form) {
            let tel = form.p_telefono.value.trim();
            if (esTelefonoUniformeOGenerico(tel)) {
                alert('El número telefónico no es válido.');
                return false;
            }
            return true;
        }

        function cerrarModalFaltaTel() {
            document.getElementById('modalActualizarTelefonoCurp').style.display = 'none';
        }

        function cambiarTipoFiltro() {
            let tipo = document.getElementById('selectTipoFiltro').value;
            let divDia = document.getElementById('filtroDiaContainer');
            let divRango = document.getElementById('filtroRangoContainer');

            divDia.style.display = 'none';
            divRango.style.display = 'none';

            if (tipo === 'dia') {
                divDia.style.display = 'block';
            } else if (tipo === 'rango') {
                divRango.style.display = 'flex';
            } else {
                document.getElementById('formFiltrosCitas').submit();
            }
        }

        document.getElementById('inputFiltroPac').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                filtrarPacientesGrid();
            }
        });

        function filtrarPacientesGrid() {
            let q = document.getElementById('inputFiltroPac').value;
            let fecha = document.getElementById('inputFechaCita').value;
            window.location.href = `vacunas.php?filtro_fecha=${fecha}&q_paciente=` + encodeURIComponent(q);
        }

        function toggleRnMode() {
            const chk = document.getElementById('chkEsRn');
            const inputExp = document.getElementById('inputExpedientePaciente');
            const inputCurp = document.getElementById('inputCurpPaciente');
            if (chk.checked) {
                inputExp.value = "SE-" + new Date().getFullYear() + "-" + Math.floor(10000 + Math.random() * 90000);
                inputCurp.value = "";
                inputCurp.placeholder = "Se generará CURP temporal RN automáticamente";
            } else {
                inputCurp.placeholder = "CURP de 18 caracteres";
            }
        }

        document.getElementById('inputBuscaVacuna').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                agregarVacunaPorInput();
            }
        });

        function agregarVacunaPorInput() {
            let val = document.getElementById('inputBuscaVacuna').value.trim().toUpperCase();
            if (!val) return;
            let vacunaEncontrada = catalogoVacunas.find(v => v.abrev.toUpperCase() === val || v.nombre_vacuna.toUpperCase().includes(val) || val.includes(v.abrev.toUpperCase()));
            if (vacunaEncontrada) {
                agregarVacunaAceptada(vacunaEncontrada);
                document.getElementById('inputBuscaVacuna').value = '';
            } else {
                alert('No se encontró ninguna vacuna con esa abreviatura en el catálogo.');
            }
        }

        function agregarVacunaAceptada(vac) {
            if (vacunasSeleccionadasIDs.includes(vac.id_vacuna)) {
                alert('Esta vacuna ya fue agregada.');
                return;
            }
            vacunasSeleccionadasIDs.push(vac.id_vacuna);
            renderizarChipsVacunas();
        }

        function removerVacuna(id) {
            vacunasSeleccionadasIDs = vacunasSeleccionadasIDs.filter(item => item !== id);
            renderizarChipsVacunas();
        }

        function renderizarChipsVacunas() {
            const contenedor = document.getElementById('contenedorVacunasSeleccionadas');
            contenedor.innerHTML = '';
            if (vacunasSeleccionadasIDs.length === 0) {
                contenedor.innerHTML = '<span id="sinVacunasMsg" style="color: #919eab; font-size: 13px; margin: auto;">Ninguna vacuna agregada todavía.</span>';
                return;
            }
            vacunasSeleccionadasIDs.forEach(id => {
                let vac = catalogoVacunas.find(v => v.id_vacuna == id);
                if (vac) {
                    let chip = document.createElement('div');
                    chip.className = 'vacuna-chip';
                    chip.innerHTML = `
                        <span>[${vac.abrev}] ${vac.nombre_vacuna}</span>
                        <input type="hidden" name="vacunas[]" value="${vac.id_vacuna}">
                        <button type="button" onclick="removerVacuna(${vac.id_vacuna})">&times;</button>
                    `;
                    contenedor.appendChild(chip);
                }
            });
        }

        document.getElementById('inputBuscaVacunaEdit').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                let query = this.value.trim().toUpperCase();
                if (!query) return;
                let vacunaEncontrada = catalogoVacunas.find(v => v.abrev.toUpperCase() === query || v.nombre_vacuna.toUpperCase().includes(query));
                if (vacunaEncontrada) {
                    if (vacunasEditIDs.includes(vacunaEncontrada.id_vacuna)) {
                        alert('Esta vacuna ya está agregada.');
                        return;
                    }
                    vacunasEditIDs.push(vacunaEncontrada.id_vacuna);
                    renderizarChipsEdit();
                    this.value = '';
                } else {
                    alert('Vacuna no encontrada.');
                }
            }
        });

        function removerVacunaEdit(id) {
            vacunasEditIDs = vacunasEditIDs.filter(item => item !== id);
            renderizarChipsEdit();
        }

        function renderizarChipsEdit() {
            const contenedor = document.getElementById('contenedorVacunasEdit');
            contenedor.innerHTML = '';
            if (vacunasEditIDs.length === 0) {
                contenedor.innerHTML = '<span style="color: #919eab; font-size: 13px; margin: auto;">Ninguna vacuna asignada.</span>';
                return;
            }
            vacunasEditIDs.forEach(id => {
                let vac = catalogoVacunas.find(v => v.id_vacuna == id);
                if (vac) {
                    let chip = document.createElement('div');
                    chip.className = 'vacuna-chip';
                    chip.innerHTML = `
                        <span>[${vac.abrev}] ${vac.nombre_vacuna}</span>
                        <input type="hidden" name="vacunas[]" value="${vac.id_vacuna}">
                        <button type="button" onclick="removerVacunaEdit(${vac.id_vacuna})">&times;</button>
                    `;
                    contenedor.appendChild(chip);
                }
            });
        }

        function abrirModalEditarCita(idCita, nombrePac, fechaCita, horaCita, arrVacunas) {
            document.getElementById('edit_id_cita').value = idCita;
            document.getElementById('edit_nombre_paciente').value = nombrePac;
            document.getElementById('edit_fecha_cita').value = fechaCita;
            document.getElementById('edit_hora_cita').value = horaCita;
            vacunasEditIDs = arrVacunas.map(Number);
            renderizarChipsEdit();
            document.getElementById('modalEditarCita').style.display = 'flex';
        }
        function cerrarModalEditarCita() { document.getElementById('modalEditarCita').style.display = 'none'; }

        function abrirModalConfig() { document.getElementById('modalConfig').style.display = 'flex'; }
        function cerrarModalConfig() { document.getElementById('modalConfig').style.display = 'none'; }

        function abrirModalCatalogo() { document.getElementById('modalCatalogo').style.display = 'flex'; }
        function cerrarModalCatalogo() { document.getElementById('modalCatalogo').style.display = 'none'; }

        function abrirModalVacunaForm() {
            document.getElementById('vacuna_id').value = '';
            document.getElementById('vacuna_area').value = '';
            document.getElementById('vacuna_nombre').value = '';
            document.getElementById('vacuna_abrev').value = '';
            document.getElementById('vacuna_enfermedad').value = '';
            document.getElementById('vacuna_rango').value = '';
            document.getElementById('modalVacunaForm').style.display = 'flex';
        }
        function editarVacuna(id, area, nombre, abrev, enfermedad, rango) {
            document.getElementById('vacuna_id').value = id;
            document.getElementById('vacuna_area').value = area;
            document.getElementById('vacuna_nombre').value = nombre;
            document.getElementById('vacuna_abrev').value = abrev;
            document.getElementById('vacuna_enfermedad').value = enfermedad;
            document.getElementById('vacuna_rango').value = rango;
            document.getElementById('modalVacunaForm').style.display = 'flex';
        }
        function cerrarModalVacunaForm() { document.getElementById('modalVacunaForm').style.display = 'none'; }

        function abrirModalPaciente() { document.getElementById('modalPacienteRapido').style.display = 'flex'; }
        function cerrarModalPaciente() { document.getElementById('modalPacienteRapido').style.display = 'none'; }

        <?php if (!empty($paciente_seleccionado_id)): ?>
            window.addEventListener('DOMContentLoaded', () => {
                let radioSel = document.querySelector('input[name="id_paciente"][value="<?php echo $paciente_seleccionado_id; ?>"]');
                if (radioSel) {
                    radioSel.checked = true;
                    let rowTr = document.getElementById('row_pac_<?php echo $paciente_seleccionado_id; ?>');
                    if (rowTr) {
                        rowTr.style.backgroundColor = '#e4f8dd';
                        rowTr.style.fontWeight = 'bold';
                        rowTr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>