<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}
require_once 'conexion.php';

$es_admin = (isset($_SESSION['rol_id']) && $_SESSION['rol_id'] == 1);
$permisos_usuario = array_map('strtolower', $_SESSION['permisos'] ?? []);

if (!$es_admin && !in_array('central_enfermeria', $permisos_usuario) && !in_array('hospitalizacion', $permisos_usuario)) {
    header("Location: dashboard.php");
    exit();
}

// 1. Obtener EXCLUSIVAMENTE las áreas cuyo estatus sea ACTIVO en la base de datos
$areas_oficiales = [];
try {
    $stmt_ar = $pdo->query("
        SELECT nombre FROM areas_hospital 
        WHERE UPPER(TRIM(estatus)) = 'ACTIVO' 
        ORDER BY nombre ASC
    ");
    $areas_oficiales = $stmt_ar->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Respaldo por defecto si la consulta estuviera vacía
if (empty($areas_oficiales)) {
    $areas_oficiales = ['HOSPITALIZACIÓN', 'URGENCIAS / OBSERVACIÓN', 'LABOR', 'RECUPERACIÓN'];
}

// 2. Validar el área activa seleccionada por el usuario
$area_solicitada = trim($_GET['area'] ?? ($areas_oficiales[0] ?? ''));
$area_activa = $areas_oficiales[0];
foreach ($areas_oficiales as $ar) {
    if (strcasecmp($ar, $area_solicitada) === 0) {
        $area_activa = $ar;
        break;
    }
}

// 3. Consultar los pacientes ingresados desde la tabla 'censo' para el área activa
$pacientes_area = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, COALESCE(p.expediente, c.curp, 'S/N') as expediente_real,
               COALESCE(p.telefono, 'S/N') as telefono_real
        FROM censo c
        LEFT JOIN pacientes p ON c.id_paciente = p.id_paciente
        WHERE (c.estatus_censo IS NULL OR UPPER(TRIM(c.estatus_censo)) != 'DADO DE ALTA')
          AND TRIM(UPPER(c.area_actual)) = TRIM(UPPER(:area_activa))
        ORDER BY c.cama ASC
    ");
    $stmt->execute(['area_activa' => $area_activa]);
    $pacientes_area = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pacientes_area = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Central de Enfermería</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; 
            --imss-verde-oscuro: #004d35; 
            --imss-dorado: #9f2241; 
            --fondo: #f8fafc; 
            --texto-principal: #1e293b; 
            --texto-secundario: #64748b;
            --borde: #e2e8f0; 
        }
        
        body { 
            margin: 0; 
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; 
            background-color: var(--fondo); 
            color: var(--texto-principal); 
            text-transform: uppercase; 
        }

        .navbar { 
            background: linear-gradient(135deg, var(--imss-verde), var(--imss-verde-oscuro)); 
            color: white; 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .navbar h2 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; font-weight: 600; }
        .navbar a { color: white; text-decoration: none; font-weight: 600; font-size: 14px; background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 6px; transition: background 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .navbar a:hover { background: rgba(255,255,255,0.25); }

        .container { max-width: 1500px; margin: 30px auto; padding: 0 25px; }

        .areas-nav { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 12px; margin-bottom: 25px; scrollbar-width: thin; }
        .btn-area { 
            background: white; 
            color: var(--texto-secundario); 
            border: 1px solid var(--borde); 
            font-weight: 600; 
            padding: 11px 20px; 
            border-radius: 8px; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            font-size: 13px; 
            white-space: nowrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }
        .btn-area:hover { border-color: var(--imss-verde); color: var(--imss-verde); transform: translateY(-1px); }
        .btn-area.active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); box-shadow: 0 4px 12px rgba(0,104,71,0.2); }

        .card-main { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.04), 0 4px 6px -2px rgba(0,0,0,0.02); border-top: 5px solid var(--imss-verde); }
        
        .area-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--borde); padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .area-title { margin: 0; color: var(--imss-verde); font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .badge-total { background: #e0f2fe; color: #0369a1; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid #bae6fd; }

        .pacientes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
        
        .paciente-card { 
            background: white; 
            border: 1px solid var(--borde); 
            border-left: 5px solid var(--imss-verde); 
            border-radius: 10px; 
            padding: 18px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.02); 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .paciente-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.06); }

        .card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .badge-cama { background: #f0fdf4; color: var(--imss-verde); border: 1px solid #bbf7d0; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; }
        .badge-estado { background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; }
        
        .paciente-nombre { margin: 0 0 6px 0; color: var(--texto-principal); font-size: 16px; font-weight: 700; }
        .paciente-info { margin: 0 0 4px 0; color: var(--texto-secundario); font-size: 12px; display: flex; justify-content: space-between; }
        .paciente-diag { margin: 8px 0 0 0; color: var(--imss-dorado); font-size: 12px; font-weight: 700; background: #fdf2f8; padding: 6px 10px; border-radius: 6px; border: 1px solid #fce7f3; }
        .paciente-pendientes { margin: 6px 0 0 0; color: #b45309; font-size: 11px; font-weight: 600; background: #fffbeb; padding: 6px 10px; border-radius: 6px; border: 1px solid #fef3c7; }

        .card-footer { margin-top: 15px; border-top: 1px solid var(--borde); padding-top: 12px; }
        
        .btn-accion { 
            background-color: var(--imss-verde); 
            color: white; 
            padding: 9px 14px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 600; 
            font-size: 12px; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            gap: 6px; 
            width: 100%; 
            text-decoration: none; 
            transition: background 0.2s;
        }
        .btn-accion:hover { background-color: var(--imss-verde-oscuro); }

        .empty-state { text-align: center; color: var(--texto-secundario); padding: 50px 20px; }
        .empty-state i { font-size: 40px; color: #cbd5e1; margin-bottom: 12px; }
        .empty-state p { font-size: 14px; font-weight: 600; margin: 0; }
    </style>
</head>
<body>

    <div class="navbar">
        <h2><i class="fa-solid fa-user-nurse"></i> IMSS-BIENESTAR &mdash; Central de Enfermería</h2>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Menú Principal</a>
    </div>

    <div class="container">
        <div class="areas-nav">
            <?php foreach ($areas_oficiales as $area): ?>
                <a href="central_enfermeria.php?area=<?php echo urlencode($area); ?>" class="btn-area <?php echo (strcasecmp($area_activa, $area) === 0) ? 'active' : ''; ?>">
                    <i class="fa-solid fa-hospital-user"></i> <?php echo htmlspecialchars($area); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card-main">
            <div class="area-header">
                <h3 class="area-title"><i class="fa-solid fa-bed-pulse"></i> Área Actual: <?php echo htmlspecialchars($area_activa); ?></h3>
                <span class="badge-total"><i class="fa-solid fa-users"></i> <?php echo count($pacientes_area); ?> Pacientes Activos en Censo</span>
            </div>

            <?php if (!empty($pacientes_area)): ?>
                <div class="pacientes-grid">
                    <?php foreach ($pacientes_area as $p): 
                        $idP = $p['id_paciente'];
                        $idCenso = $p['id_censo'];
                        $nomP = $p['nombre_paciente'] ?? 'Sin Nombre';
                        $expP = $p['expediente_real'] ?? 'S/N';
                        $camaP = $p['cama'] ?? 'S/C';
                        $estadoSalud = $p['estado_salud'] ?? 'ESTABLE';
                        $edadP = $p['edad'] ?? 0;
                        $sexoP = $p['sexo'] ?? 'N/D';
                        $diagP = $p['diagnostico_ingreso'] ?? 'S/D';
                        $pendP = $p['pendientes'] ?? '';
                        $medicoP = $p['medico_tratante_actual'] ?? 'NO ASIGNADO';
                    ?>
                        <div class="paciente-card">
                            <div>
                                <div class="card-top">
                                    <span class="badge-cama"><i class="fa-solid fa-bed"></i> Cama: <?php echo htmlspecialchars($camaP); ?></span>
                                    <span class="badge-estado"><i class="fa-solid fa-heart-pulse"></i> <?php echo htmlspecialchars($estadoSalud); ?></span>
                                </div>
                                <h4 class="paciente-nombre"><?php echo htmlspecialchars($nomP); ?></h4>
                                <div class="paciente-info">
                                    <span>Exp: <strong><?php echo htmlspecialchars($expP); ?></strong></span>
                                    <span><?php echo $edadP; ?> Años | <?php echo htmlspecialchars($sexoP); ?></span>
                                </div>
                                <div class="paciente-info" style="margin-top: 4px;">
                                    <span>Médico: <strong><?php echo htmlspecialchars($medicoP); ?></strong></span>
                                </div>
                                <div class="paciente-diag">
                                    <i class="fa-solid fa-stethoscope"></i> Diag: <?php echo htmlspecialchars($diagP); ?>
                                </div>
                                <?php if (!empty($pendP)): ?>
                                    <div class="paciente-pendientes">
                                        <i class="fa-solid fa-triangle-exclamation"></i> Pendientes: <?php echo htmlspecialchars($pendP); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-footer">
                               <a href="enfermeria_hoja1.php?id_paciente=<?php echo $idP; ?>&id_censo=<?php echo $idCenso; ?>&area=<?php echo urlencode($area_activa); ?>" class="btn-accion">
                                    <i class="fa-solid fa-notes-medical"></i> Llenar Hojas Clínicas (4 Pág.)
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-circle-info"></i>
                    <p>No hay pacientes registrados ni activos en el censo para el área de <?php echo htmlspecialchars($area_activa); ?>.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>