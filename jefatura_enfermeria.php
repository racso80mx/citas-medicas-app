<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("conexion.php");

$mensaje = "";
$mensaje_error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. ASIGNAR PERSONAL A CELDA
    if ($accion === 'asignar') {
        $personal_id = $_POST['personal_id'];
        $servicios_area_id = $_POST['servicios_area_id'];
        $fecha = $_POST['fecha'];
        $turno = $_POST['turno'];
        $tipo_asignacion = $_POST['tipo_asignacion'];

        $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM programacion_guardias WHERE personal_id = ? AND servicios_area_id = ? AND fecha = ? AND turno = ?");
        $stmt_val->execute([$personal_id, $servicios_area_id, $fecha, $turno]);
        
        if ($stmt_val->fetchColumn() > 0) {
            $mensaje_error = "¡Atención! La enfermera seleccionada ya se encuentra asignada en este mismo servicio/área para esta fecha y turno.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO programacion_guardias (personal_id, servicios_area_id, fecha, turno, tipo_asignacion) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$personal_id, $servicios_area_id, $fecha, $turno, $tipo_asignacion]);
            $mensaje = "¡Personal asignado correctamente!";
        }
    } 
    // 2. REPLICAR PERSONAL DEL LUNES A TODA LA SEMANA
    elseif ($accion === 'replicar_dia_semana') {
        $servicios_area_id = $_POST['servicios_area_id'];
        $fecha_lunes = $_POST['fecha_lunes'];
        $turno = $_POST['turno'];

        $stmt_l = $pdo->prepare("SELECT personal_id, tipo_asignacion FROM programacion_guardias WHERE servicios_area_id = ? AND fecha = ? AND turno = ?");
        $stmt_l->execute([$servicios_area_id, $fecha_lunes, $turno]);
        $personal_lunes = $stmt_l->fetchAll();

        if (!empty($personal_lunes)) {
            for ($i = 1; $i < 6; $i++) {
                $fecha_destino = date('Y-m-d', strtotime("$fecha_lunes +$i days"));

                foreach ($personal_lunes as $p) {
                    $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM programacion_guardias WHERE personal_id = ? AND servicios_area_id = ? AND fecha = ? AND turno = ?");
                    $stmt_chk->execute([$p['personal_id'], $servicios_area_id, $fecha_destino, $turno]);

                    if ($stmt_chk->fetchColumn() == 0) {
                        $stmt_ins = $pdo->prepare("INSERT INTO programacion_guardias (personal_id, servicios_area_id, fecha, turno, tipo_asignacion) VALUES (?, ?, ?, ?, ?)");
                        $stmt_ins->execute([$p['personal_id'], $servicios_area_id, $fecha_destino, $turno, $p['tipo_asignacion']]);
                    }
                }
            }
            $mensaje = "¡Personal del Lunes replicado exitosamente para toda la semana en esta área!";
        } else {
            $mensaje_error = "No hay personal asignado el Lunes en esta área para poder replicar.";
        }
    }
    // 3. COPIAR SEMANA
    elseif ($accion === 'copiar_semana') {
        $semana_destino = $_POST['semana_destino'];
        $semana_origen = date('Y-m-d', strtotime($semana_destino . ' -7 days'));
        $turno = $_POST['turno'];

        for ($i = 0; $i < 6; $i++) {
            $f_orig = date('Y-m-d', strtotime("$semana_origen +$i days"));
            $f_dest = date('Y-m-d', strtotime("$semana_destino +$i days"));

            $stmt_copy = $pdo->prepare("INSERT INTO programacion_guardias (personal_id, servicios_area_id, fecha, turno, tipo_asignacion)
                                        SELECT personal_id, servicios_area_id, ?, turno, tipo_asignacion 
                                        FROM programacion_guardias 
                                        WHERE fecha = ? AND turno = ?");
            $stmt_copy->execute([$f_dest, $f_orig, $turno]);
        }
        $mensaje = "¡Semana copiada y adaptada exitosamente!";
    }
    // 4. ELIMINAR ASIGNACIÓN
    elseif ($accion === 'eliminar_asignacion') {
        $id_asignacion = $_POST['id_asignacion'];
        $stmt_del = $pdo->prepare("DELETE FROM programacion_guardias WHERE id = ?");
        $stmt_del->execute([$id_asignacion]);
        $mensaje = "Asignación retirada correctamente.";
    }
    // 5. GESTIÓN DE TURNOS
    elseif ($accion === 'guardar_turno') {
        $id_turno = $_POST['id_turno'] ?? '';
        $nombre_turno = trim($_POST['nombre_turno']);
        $hora_inicio = $_POST['hora_inicio'];
        $hora_fin = $_POST['hora_fin'];

        if (!empty($id_turno)) {
            $stmt = $pdo->prepare("UPDATE turnos_config SET nombre_turno = ?, hora_inicio = ?, hora_fin = ? WHERE id = ?");
            $stmt->execute([$nombre_turno, $hora_inicio, $hora_fin, $id_turno]);
            $mensaje = "¡Turno actualizado correctamente!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO turnos_config (nombre_turno, hora_inicio, hora_fin) VALUES (?, ?, ?)");
            $stmt->execute([$nombre_turno, $hora_inicio, $hora_fin]);
            $mensaje = "¡Turno creado exitosamente!";
        }
    }
    // 6. GESTIÓN DE PERSONAL
    elseif ($accion === 'guardar_personal') {
        $id_personal = $_POST['id_personal'] ?? '';
        $nombre_completo = trim($_POST['nombre_completo']);
        $categoria = $_POST['categoria'];
        $jornada = $_POST['jornada'];
        $tipo_personal = $_POST['tipo_personal'];

        if (!empty($id_personal)) {
            $stmt = $pdo->prepare("UPDATE personal SET nombre_completo = ?, categoria = ?, jornada = ?, tipo_personal = ? WHERE id_personal = ?");
            $stmt->execute([$nombre_completo, $categoria, $jornada, $tipo_personal, $id_personal]);
            $mensaje = "¡Datos del personal actualizados!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO personal (nombre_completo, categoria, jornada, tipo_personal) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre_completo, $categoria, $jornada, $tipo_personal]);
            $mensaje = "¡Nuevo personal registrado exitosamente!";
        }
    }
    // 7. GESTIÓN DE ÁREAS
    elseif ($accion === 'guardar_area') {
        $id_area = $_POST['id_area'] ?? '';
        $nombre_area = trim($_POST['nombre_area']);
        $dotacion_requerida = $_POST['dotacion_requerida'];
        $turno_asignado = $_POST['turno_asignado'];

        if (!empty($id_area)) {
            $stmt = $pdo->prepare("UPDATE servicios_area SET nombre_area = ?, dotacion_requerida = ?, turno_asignado = ? WHERE id = ?");
            $stmt->execute([$nombre_area, $dotacion_requerida, $turno_asignado, $id_area]);
            $mensaje = "¡Área actualizada correctamente!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO servicios_area (nombre_area, dotacion_requerida, turno_asignado) VALUES (?, ?, ?)");
            $stmt->execute([$nombre_area, $dotacion_requerida, $turno_asignado]);
            $mensaje = "¡Área de servicio agregada con éxito!";
        }
    }
    elseif ($accion === 'eliminar_area') {
        $id_area = $_POST['id_area'];
        $stmt = $pdo->prepare("DELETE FROM servicios_area WHERE id = ?");
        $stmt->execute([$id_area]);
        $mensaje = "Área eliminada correctamente.";
    }
}

// Parámetros de Vista (semana o mes)
$vista = $_GET['vista'] ?? 'semana';
$turno_seleccionado = $_GET['turno'] ?? 'Matutino';
$fecha_inicio_semana = $_GET['semana'] ?? date('Y-m-d', strtotime('monday this week'));
$mes_seleccionado = $_GET['mes'] ?? date('Y-m');

// Datos para vista semanal
$dias_semana = [];
for ($i = 0; $i < 6; $i++) {
    $dias_semana[] = date('Y-m-d', strtotime("$fecha_inicio_semana +$i days"));
}
$nombres_dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// Datos para vista mensual
$anio_mes = explode('-', $mes_seleccionado);
$anio_m = $anio_mes[0] ?? date('Y');
$mes_m = $anio_mes[1] ?? date('m');
$total_dias_mes = cal_days_in_month(CAL_GREGORIAN, $mes_m, $anio_m);

$turnos_cfg = $pdo->query("SELECT * FROM turnos_config ORDER BY id")->fetchAll();
$stmt_areas = $pdo->prepare("SELECT * FROM servicios_area ORDER BY id");
$stmt_areas->execute();
$areas = $stmt_areas->fetchAll();

$personal_lista = $pdo->query("SELECT id_personal, nombre_completo, categoria, jornada FROM personal ORDER BY nombre_completo ASC")->fetchAll();

$horas_opciones = [];
for ($h = 0; $h < 24; $h++) {
    $horas_opciones[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Jefatura de Enfermería - Hospital General Huimanguillo</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@x.x.x/dist/select2-bootstrap4.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar-custom { background-color: #1b4d3e; color: white; padding: 12px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card-header-custom { background-color: #2c5e3b; color: white; font-weight: bold; }
        .table-enfermeria th, .table-enfermeria td { vertical-align: middle !important; text-align: center; font-size: 12px; }
        .cell-asignacion { background: #fff; border: 1px dashed #ccc; border-radius: 6px; padding: 4px; margin-bottom: 3px; text-align: left; }
        .print-header { display: none; }
        .print-footer { display: none; }

        @media print {
            body { background-color: white !important; }
            .navbar-custom, .card-header-custom, form, .btn, .no-print { display: none !important; }
            .print-header { display: block !important; text-align: center; border-bottom: 2px solid #1b4d3e; padding-bottom: 10px; margin-bottom: 20px; }
            .print-footer { display: flex !important; justify-content: space-between; margin-top: 80px; page-break-inside: avoid; }
            .card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>

    <div class="navbar-custom d-flex justify-content-between align-items-center no-print">
        <div>
            <h4 class="m-0 font-weight-bold"><i class="fas fa-hospital-user mr-2"></i> IMSS-BIENESTAR | JEFATURA DE ENFERMERÍA</h4>
        </div>
        <div>
            <a href="trabajo_social.php" class="btn btn-light btn-sm font-weight-bold"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
        </div>
    </div>

    <div class="container-fluid py-4">

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
                <i class="fas fa-check-circle mr-2"></i> <?php echo $mensaje; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($mensaje_error)): ?>
            <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $mensaje_error; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
        <?php endif; ?>

        <!-- Panel Superior con Pestaña de Vista Semanal / Mensual -->
        <div class="card shadow-sm mb-4 no-print">
            <div class="card-body bg-white py-3">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <h3 class="text-dark font-weight-bold m-0"><i class="fas fa-calendar-alt text-success mr-2"></i> Cobertura de Guardias</h3>
                        <p class="text-muted m-0 small">Gestión y programación hospitalaria</p>
                    </div>
                    <div class="col-md-8 text-right">
                        <div class="btn-group mr-2" role="group">
                            <a href="?vista=semana&turno=<?php echo $turno_seleccionado; ?>&semana=<?php echo $fecha_inicio_semana; ?>" class="btn btn-sm <?php echo ($vista=='semana')?'btn-success':'btn-outline-success'; ?> font-weight-bold">Vista Semanal</a>
                            <a href="?vista=mes&turno=<?php echo $turno_seleccionado; ?>&mes=<?php echo $mes_seleccionado; ?>" class="btn btn-sm <?php echo ($vista=='mes')?'btn-success':'btn-outline-success'; ?> font-weight-bold">Vista Mensual</a>
                        </div>
                        <button class="btn btn-outline-info btn-sm font-weight-bold mr-1" data-toggle="modal" data-target="#modalPersonal"><i class="fas fa-users mr-1"></i> Personal</button>
                        <button class="btn btn-outline-warning btn-sm font-weight-bold mr-1 text-dark" data-toggle="modal" data-target="#modalAreas"><i class="fas fa-hospital mr-1"></i> Áreas</button>
                        <button class="btn btn-outline-secondary btn-sm font-weight-bold mr-1" data-toggle="modal" data-target="#modalTurnos"><i class="fas fa-clock mr-1"></i> Turnos</button>
                        <button class="btn btn-success btn-sm font-weight-bold" onclick="window.print();"><i class="fas fa-print mr-1"></i> Imprimir</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros Dinámicos según la Vista -->
        <form method="GET" class="card shadow-sm mb-4 p-3 bg-white no-print">
            <input type="hidden" name="vista" value="<?php echo $vista; ?>">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <label class="font-weight-bold text-secondary">Turno a Evaluar:</label>
                    <select name="turno" class="form-control" onchange="this.form.submit()">
                        <?php foreach($turnos_cfg as $t): ?>
                            <option value="<?php echo $t['nombre_turno']; ?>" <?php if($turno_seleccionado == $t['nombre_turno']) echo 'selected'; ?>>
                                <?php echo $t['nombre_turno']; ?> (<?php echo substr($t['hora_inicio'], 0, 5); ?> - <?php echo substr($t['hora_fin'], 0, 5); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($vista === 'semana'): ?>
                    <div class="col-md-4">
                        <label class="font-weight-bold text-secondary">Semana (Lunes):</label>
                        <input type="date" name="semana" class="form-control" value="<?php echo $fecha_inicio_semana; ?>" onchange="this.form.submit()">
                    </div>
                <?php else: ?>
                    <div class="col-md-4">
                        <label class="font-weight-bold text-secondary">Mes a Consultar:</label>
                        <input type="month" name="mes" class="form-control" value="<?php echo $mes_seleccionado; ?>" onchange="this.form.submit()">
                    </div>
                <?php endif; ?>
                <div class="col-md-4 text-right pt-4">
                    <span class="badge badge-pill badge-info p-2 font-weight-bold">Turno: <?php echo $turno_seleccionado; ?></span>
                </div>
            </div>
        </form>

        <!-- MEMBRETE OFICIAL PARA IMPRESIÓN -->
        <div class="print-header">
            <table width="100%">
                <tr>
                    <td width="20%" align="left"><img src="logo_hospital.png" alt="Logo" style="max-height: 55px;" onerror="this.style.display='none'"></td>
                    <td width="60%" align="center">
                        <h5 class="m-0 font-weight-bold">GOBIERNO DE MÉXICO | IMSS-BIENESTAR</h5>
                        <h6 class="m-0 font-weight-bold">HOSPITAL GENERAL HUIMANGUILLO</h6>
                        <p class="m-0 small font-weight-bold">REPORTE <?php echo strtoupper($vista); ?> DE COBERTURA DE GUARDIAS - TURNO <?php echo strtoupper($turno_seleccionado); ?></p>
                    </td>
                    <td width="20%" align="right"><img src="logo_gobierno.png" alt="Logo" style="max-height: 55px;" onerror="this.style.display='none'"></td>
                </tr>
            </table>
        </div>

        <!-- ================= VISTA SEMANAL ================= -->
        <?php if ($vista === 'semana'): ?>
        <div class="card shadow-sm">
            <div class="card-header card-header-custom d-flex justify-content-between align-items-center no-print">
                <span><i class="fas fa-th mr-2"></i> DISTRIBUCIÓN SEMANAL - TURNO <?php echo strtoupper($turno_seleccionado); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-enfermeria m-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 22%;">Servicio / Área</th>
                                <th style="width: 5%;">D.R.</th>
                                <?php foreach ($nombres_dias as $index => $dia_nombre): ?>
                                    <th style="width: 12.1%;">
                                        <?php echo $dia_nombre; ?><br><span class="text-muted small"><?php echo date('d/m', strtotime($dias_semana[$index])); ?></span>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($areas as $area): ?>
                                <tr>
                                    <td class="text-left bg-light">
                                        <div class="font-weight-bold text-dark mb-1"><i class="fas fa-hospital-symbol text-success mr-1 no-print"></i> <?php echo htmlspecialchars($area['nombre_area']); ?></div>
                                        <button class="btn btn-outline-primary btn-sm py-0 px-2 font-weight-bold no-print" style="font-size: 10px;" onclick="replicarLunesArea(<?php echo $area['id']; ?>, '<?php echo $dias_semana[0]; ?>', '<?php echo $turno_seleccionado; ?>', '<?php echo htmlspecialchars($area['nombre_area'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-fast-forward mr-1"></i> Replicar Lunes ➔ Sem.
                                        </button>
                                    </td>
                                    <td><span class="badge badge-secondary p-2"><?php echo $area['dotacion_requerida']; ?></span></td>
                                    <?php foreach ($dias_semana as $fecha_dia): 
                                        $stmt_asig = $pdo->prepare("SELECT pg.id, p.nombre_completo, pg.tipo_asignacion FROM programacion_guardias pg JOIN personal p ON pg.personal_id = p.id_personal WHERE pg.servicios_area_id = ? AND pg.fecha = ? AND pg.turno = ?");
                                        $stmt_asig->execute([$area['id'], $fecha_dia, $turno_seleccionado]);
                                        $asignados = $stmt_asig->fetchAll();
                                    ?>
                                        <td>
                                            <?php foreach ($asignados as $asig): ?>
                                                <div class="cell-asignacion d-flex justify-content-between align-items-center">
                                                    <span class="<?php echo ($asig['tipo_asignacion'] == 'EXTRAORDINARIA') ? 'text-primary font-weight-bold' : 'text-dark'; ?>" style="font-size: 11px;">
                                                        <?php echo htmlspecialchars($asig['nombre_completo']); ?>
                                                        <?php if($asig['tipo_asignacion'] == 'EXTRAORDINARIA'): ?><br><span class="badge badge-pill badge-primary" style="font-size:8px;">Extraordinaria</span><?php endif; ?>
                                                    </span>
                                                    <form method="POST" onsubmit="return confirm('¿Retirar a esta enfermera?');" style="margin:0;" class="no-print">
                                                        <input type="hidden" name="accion" value="eliminar_asignacion">
                                                        <input type="hidden" name="id_asignacion" value="<?php echo $asig['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1"><i class="fas fa-times" style="font-size: 9px;"></i></button>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                            <button class="btn btn-outline-success btn-block btn-add-cell mt-1 no-print" onclick="abrirModalAsignar(<?php echo $area['id']; ?>, '<?php echo htmlspecialchars($area['nombre_area'], ENT_QUOTES); ?>', '<?php echo $fecha_dia; ?>')"><i class="fas fa-plus"></i> Asignar</button>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================= VISTA MENSUAL ================= -->
        <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-header card-header-custom d-flex justify-content-between align-items-center no-print">
                <span><i class="fas fa-calendar-alt mr-2"></i> PROGRAMACIÓN MENSUAL COMPLETA - <?php echo strtoupper($mes_seleccionado); ?> (TURNO: <?php echo strtoupper($turno_seleccionado); ?>)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="overflow-x: auto;">
                    <table class="table table-bordered table-enfermeria m-0" style="min-width: 1500px;">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 180px; position: sticky; left: 0; background: #e9ecef; z-index: 2;">Servicio / Área</th>
                                <?php for ($d = 1; $d <= $total_dias_mes; $d++): 
                                    $fecha_actual_mes = sprintf('%04d-%02d-%02d', $anio_m, $mes_m, $d);
                                    $dia_semana_letra = date('D', strtotime($fecha_actual_mes));
                                ?>
                                    <th style="min-width: 110px;">
                                        <?php echo $d; ?><br><span class="text-muted" style="font-size:10px;"><?php echo $dia_semana_letra; ?></span>
                                    </th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($areas as $area): ?>
                                <tr>
                                    <td class="text-left bg-light font-weight-bold" style="position: sticky; left: 0; background: #f8f9fa; z-index: 1;">
                                        <?php echo htmlspecialchars($area['nombre_area']); ?>
                                    </td>
                                    <?php for ($d = 1; $d <= $total_dias_mes; $d++): 
                                        $fecha_actual_mes = sprintf('%04d-%02d-%02d', $anio_m, $mes_m, $d);
                                        $stmt_asig_mes = $pdo->prepare("SELECT p.nombre_completo, pg.tipo_asignacion FROM programacion_guardias pg JOIN personal p ON pg.personal_id = p.id_personal WHERE pg.servicios_area_id = ? AND pg.fecha = ? AND pg.turno = ?");
                                        $stmt_asig_mes->execute([$area['id'], $fecha_actual_mes, $turno_seleccionado]);
                                        $asignados_mes = $stmt_asig_mes->fetchAll();
                                    ?>
                                        <td style="vertical-align: top !important; padding: 4px;">
                                            <?php if (!empty($asignados_mes)): ?>
                                                <?php foreach ($asignados_mes as $am): ?>
                                                    <div style="font-size: 10px; background: #eef9f2; border: 1px solid #c3e6cb; border-radius: 4px; padding: 2px; margin-bottom: 2px; text-align: left;">
                                                        <b><?php echo htmlspecialchars($am['nombre_completo']); ?></b>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 9px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- PIE DE FIRMA OFICIAL -->
        <div class="print-footer">
            <div class="text-center" style="width: 40%;"><p class="m-0 font-weight-bold">___________________________________________________</p><p class="m-0 font-weight-bold">JEFE(A) DE ENFERMERÍA DE GUARDIA</p></div>
            <div class="text-center" style="width: 40%;"><p class="m-0 font-weight-bold">___________________________________________________</p><p class="m-0 font-weight-bold">MÉDICO(A) RESPONSABLE DEL ÁREA</p></div>
        </div>

    </div>

    <!-- MODAL ASIGNAR -->
    <div class="modal fade" id="modalAsignar" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-user-plus mr-2"></i> Asignar Personal</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="asignar">
                    <input type="hidden" name="servicios_area_id" id="modal_area_id">
                    <input type="hidden" name="fecha" id="modal_fecha">
                    <input type="hidden" name="turno" value="<?php echo $turno_seleccionado; ?>">
                    <div class="form-group"><label class="font-weight-bold">Área:</label><input type="text" id="modal_nombre_area" class="form-control bg-light" readonly></div>
                    <div class="form-group"><label class="font-weight-bold">Fecha:</label><input type="text" id="modal_texto_fecha" class="form-control bg-light" readonly></div>
                    <div class="form-group">
                        <label class="font-weight-bold">Enfermera:</label>
                        <select name="personal_id" id="selectPersonal" class="form-control select2-busqueda" style="width: 100%;" required>
                            <option value="">-- Buscar enfermera --</option>
                            <?php foreach($personal_lista as $p): ?>
                                <option value="<?php echo $p['id_personal']; ?>"><?php echo htmlspecialchars($p['nombre_completo']); ?> (<?php echo $p['categoria']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Tipo:</label>
                        <select name="tipo_asignacion" class="form-control"><option value="ORDINARIA">Ordinaria</option><option value="EXTRAORDINARIA">Extraordinaria</option></select>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success btn-sm font-weight-bold">Guardar</button></div>
            </form>
        </div>
    </div>

    <!-- MODAL PERSONAL -->
    <div class="modal fade" id="modalPersonal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white"><h5 class="modal-title font-weight-bold"><i class="fas fa-users mr-2"></i> Gestión de Personal</h5><button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                <div class="modal-body">
                    <form method="POST" class="bg-light p-3 rounded mb-4 border" id="formPersonal">
                        <input type="hidden" name="accion" value="guardar_personal"><input type="hidden" name="id_personal" id="p_id">
                        <h6 class="font-weight-bold text-info mb-3" id="tituloFormPersonal">Registrar Nuevo Personal</h6>
                        <div class="row">
                            <div class="col-md-4"><label class="small font-weight-bold">Nombre:</label><input type="text" name="nombre_completo" id="p_nombre" class="form-control form-control-sm" required></div>
                            <div class="col-md-3"><label class="small font-weight-bold">Categoría:</label><input type="text" name="categoria" id="p_categoria" class="form-control form-control-sm" required></div>
                            <div class="col-md-2"><label class="small font-weight-bold">Jornada:</label><input type="text" name="jornada" id="p_jornada" class="form-control form-control-sm" required></div>
                            <div class="col-md-2"><label class="small font-weight-bold">Tipo:</label><select name="tipo_personal" id="p_tipo" class="form-control form-control-sm"><option value="ENFERMERIA">ENFERMERIA</option><option value="MEDICO">MEDICO</option></select></div>
                            <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn btn-info btn-sm btn-block font-weight-bold">Salvar</button></div>
                        </div>
                    </form>
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-striped table-bordered text-center m-0" style="font-size: 12px;">
                            <thead class="thead-dark"><tr><th>ID</th><th>Nombre</th><th>Categoría</th><th>Acción</th></tr></thead>
                            <tbody>
                                <?php foreach($personal_lista as $per): ?>
                                    <tr><td><?php echo $per['id_personal']; ?></td><td class="text-left"><?php echo htmlspecialchars($per['nombre_completo']); ?></td><td><?php echo htmlspecialchars($per['categoria']); ?></td><td><button class="btn btn-primary btn-sm py-0 px-2" onclick="editarPersonal(<?php echo $per['id_personal']; ?>, '<?php echo addslashes($per['nombre_completo']); ?>', '<?php echo addslashes($per['categoria']); ?>', '<?php echo addslashes($per['jornada']); ?>')">Editar</button></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL ÁREAS -->
    <div class="modal fade" id="modalAreas" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark"><h5 class="modal-title font-weight-bold"><i class="fas fa-hospital mr-2"></i> Gestión de Áreas</h5><button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                <div class="modal-body">
                    <form method="POST" class="bg-light p-3 rounded mb-4 border" id="formArea">
                        <input type="hidden" name="accion" value="guardar_area"><input type="hidden" name="id_area" id="a_id">
                        <div class="row">
                            <div class="col-md-5"><label class="small font-weight-bold">Nombre:</label><input type="text" name="nombre_area" id="a_nombre" class="form-control form-control-sm" required></div>
                            <div class="col-md-2"><label class="small font-weight-bold">D.R.:</label><input type="number" name="dotacion_requerida" id="a_dotacion" class="form-control form-control-sm" value="1" min="1" required></div>
                            <div class="col-md-3"><label class="small font-weight-bold">Turno:</label><select name="turno_asignado" id="a_turno" class="form-control form-control-sm"><option value="Matutino">Matutino</option><option value="Vespertino">Vespertino</option><option value="Nocturno A">Nocturno A</option></select></div>
                            <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-warning btn-sm btn-block font-weight-bold">Guardar</button></div>
                        </div>
                    </form>
                    <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                        <table class="table table-striped table-bordered text-center m-0" style="font-size: 12px;">
                            <thead class="thead-dark"><tr><th>Área</th><th>D.R.</th><th>Turno</th><th>Acción</th></tr></thead>
                            <tbody>
                                <?php foreach($areas as $ar): ?>
                                    <tr><td class="text-left"><?php echo htmlspecialchars($ar['nombre_area']); ?></td><td><?php echo $ar['dotacion_requerida']; ?></td><td><?php echo $ar['turno_asignado']; ?></td><td><button class="btn btn-primary btn-sm py-0 px-2" onclick="editarArea(<?php echo $ar['id']; ?>, '<?php echo addslashes($ar['nombre_area']); ?>', <?php echo $ar['dotacion_requerida']; ?>, '<?php echo $ar['turno_asignado']; ?>')">Editar</button></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL TURNOS -->
    <div class="modal fade" id="modalTurnos" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white"><h5 class="modal-title font-weight-bold"><i class="fas fa-clock mr-2"></i> Turnos</h5><button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                <div class="modal-body">
                    <form method="POST" class="bg-light p-3 rounded mb-4 border">
                        <input type="hidden" name="accion" value="guardar_turno"><input type="hidden" name="id_turno" id="turno_id">
                        <div class="row">
                            <div class="col-md-4"><label class="small font-weight-bold">Turno:</label><input type="text" name="nombre_turno" id="nombre_turno" class="form-control form-control-sm" required></div>
                            <div class="col-md-3"><label class="small font-weight-bold">Inicio:</label><select name="hora_inicio" id="hora_inicio" class="form-control form-control-sm" required><option value="">Selecciona</option><?php foreach($horas_opciones as $h): ?><option value="<?php echo $h; ?>"><?php echo $h; ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-3"><label class="small font-weight-bold">Fin:</label><select name="hora_fin" id="hora_fin" class="form-control form-control-sm" required><option value="">Selecciona</option><?php foreach($horas_opciones as $h): ?><option value="<?php echo $h; ?>"><?php echo $h; ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-success btn-sm btn-block font-weight-bold">Guardar</button></div>
                        </div>
                    </form>
                    <table class="table table-striped table-bordered text-center m-0">
                        <thead class="thead-dark"><tr><th>Turno</th><th>Inicio</th><th>Fin</th></tr></thead>
                        <tbody>
                            <?php foreach($turnos_cfg as $tc): ?>
                                <tr><td><b><?php echo htmlspecialchars($tc['nombre_turno']); ?></b></td><td><?php echo substr($tc['hora_inicio'], 0, 5); ?></td><td><?php echo substr($tc['hora_fin'], 0, 5); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL COPIAR SEMANA -->
    <div class="modal fade" id="modalCopiarSemana" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white"><h5 class="modal-title font-weight-bold"><i class="fas fa-copy mr-2"></i> Copiar Semana</h5><button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="copiar_semana"><input type="hidden" name="turno" value="<?php echo $turno_seleccionado; ?>">
                    <div class="form-group"><label class="font-weight-bold">Semana Destino (Lunes):</label><input type="date" name="semana_destino" class="form-control" value="<?php echo $fecha_inicio_semana; ?>" required></div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary btn-sm font-weight-bold">Copiar</button></div>
            </form>
        </div>
    </div>

    <form id="formReplicarLunes" method="POST" style="display:none;">
        <input type="hidden" name="accion" value="replicar_dia_semana"><input type="hidden" name="servicios_area_id" id="rep_area_id"><input type="hidden" name="fecha_lunes" id="rep_fecha_lunes"><input type="hidden" name="turno" value="<?php echo $turno_seleccionado; ?>">
    </form>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2-busqueda').select2({ theme: 'bootstrap4', dropdownParent: $('#modalAsignar') });
        });
        function abrirModalAsignar(areaId, nombreArea, fecha) {
            document.getElementById('modal_area_id').value = areaId;
            document.getElementById('modal_nombre_area').value = nombreArea;
            document.getElementById('modal_fecha').value = fecha;
            document.getElementById('modal_texto_fecha').value = fecha;
            $('#selectPersonal').val(null).trigger('change');
            $('#modalAsignar').modal('show');
        }
        $('#modalAsignar').on('shown.bs.modal', function () { $('#selectPersonal').select2('open'); });
        function replicarLunesArea(areaId, fechaLunes, turno, nombreArea) {
            if (confirm('¿Deseas replicar todo el personal asignado el Lunes en "' + nombreArea + '" para toda la semana?')) {
                document.getElementById('rep_area_id').value = areaId;
                document.getElementById('rep_fecha_lunes').value = fechaLunes;
                document.getElementById('formReplicarLunes').submit();
            }
        }
        function editarPersonal(id, nombre, categoria, jornada) {
            document.getElementById('p_id').value = id;
            document.getElementById('p_nombre').value = nombre;
            document.getElementById('p_categoria').value = categoria;
            document.getElementById('p_jornada').value = jornada;
        }
        function editarArea(id, nombre, dotacion, turno) {
            document.getElementById('a_id').value = id;
            document.getElementById('a_nombre').value = nombre;
            document.getElementById('a_dotacion').value = dotacion;
            document.getElementById('a_turno').value = turno;
        }
    </script>
</body>
</html>