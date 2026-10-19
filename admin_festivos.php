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

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}
require_once 'conexion.php';

// ===================================================================
// 2. ASEGURAR TABLA Y NUEVAS COLUMNAS EN LA BASE DE DATOS
// ===================================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS festivos (
        id_festivo SERIAL PRIMARY KEY,
        fecha DATE NOT NULL UNIQUE,
        descripcion VARCHAR(255),
        tipo_festivo VARCHAR(50) NOT NULL DEFAULT 'general',
        clinicas_afectadas TEXT DEFAULT NULL
    )");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE festivos ADD COLUMN IF NOT EXISTS tipo_festivo VARCHAR(50) NOT NULL DEFAULT 'general'");
    $pdo->exec("ALTER TABLE festivos ADD COLUMN IF NOT EXISTS clinicas_afectadas TEXT DEFAULT NULL");
} catch (Exception $e) {}

$mensaje = '';
$error = '';

// ===================================================================
// 3. PROCESAR FORMULARIO (GUARDAR NUEVO FESTIVO / AFECTACIÓN)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $fecha = trim($_POST['fecha'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $tipo_festivo = trim($_POST['tipo_festivo'] ?? 'general');
    
    $clinicas_arr = $_POST['clinicas_afectadas'] ?? [];
    $clinicas_afectadas = !empty($clinicas_arr) ? implode(',', $clinicas_arr) : null;

    if ($accion === 'nuevo' && !empty($fecha)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO festivos (fecha, descripcion, tipo_festivo, clinicas_afectadas) VALUES (:f, :d, :t, :c)");
            $stmt->execute([
                'f' => $fecha, 
                'd' => $descripcion, 
                't' => $tipo_festivo,
                'c' => $clinicas_afectadas
            ]);
            $mensaje = "Registro de festivo/suspensión guardado correctamente.";
        } catch (Exception $e) {
            $error = "Error: La fecha ya está registrada en el sistema.";
        }
    }
}

// ===================================================================
// 4. ELIMINAR REGISTRO
// ===================================================================
if (isset($_GET['del'])) {
    $id = intval($_GET['del']);
    try {
        $pdo->prepare("DELETE FROM festivos WHERE id_festivo = :id")->execute(['id' => $id]);
    } catch (Exception $e) {}
    header("Location: admin_festivos.php");
    exit();
}

// ===================================================================
// 5. CONSULTAR LISTADOS (ORDENADOS ALFABÉTICAMENTE)
// ===================================================================
$festivos = [];
$lista_clinicas = [];
try {
    $festivos = $pdo->query("SELECT * FROM festivos ORDER BY fecha ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Ordenamiento alfabético estricto por nombre de clínica
    $lista_clinicas = $pdo->query("SELECT id_clinica, nombre_clinica, tipo_clinica FROM clinicas ORDER BY nombre_clinica ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>IMSS-BIENESTAR | Catálogo de Días Festivos y Suspensiones</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-dorado: #9f2241; --imss-verde: #006847; --fondo: #f4f6f8; --borde: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-dorado); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 1100px; margin: 25px auto; padding: 0 20px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-dorado); margin-bottom: 20px; }
        .form-control { width: 100%; padding: 8px 10px; border: 1px solid var(--borde); border-radius: 4px; box-sizing: border-box; font-size: 13px; background: #fff; }
        .btn { padding: 9px 15px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; color: white; background: var(--imss-verde); }
        .btn:hover { opacity: 0.9; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        th { background: #f1f3f5; text-align: left; padding: 10px; border-bottom: 2px solid var(--borde); color: #637381; }
        td { padding: 10px; border-bottom: 1px solid var(--borde); vertical-align: middle; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; display: inline-block; }
        .badge-general { background: #e4f8dd; color: var(--imss-verde); }
        .badge-administrativo { background: #fff3cd; color: #856404; }
        .badge-suspension { background: #fdf3f5; color: var(--imss-dorado); }
        .clinicas-box { display: none; margin-top: 15px; background: #fafbfc; border: 1px solid var(--borde); padding: 12px; border-radius: 6px; }
        .clinicas-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; margin-top: 8px; max-height: 150px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-calendar-days"></i> <strong>Gestión de Días Festivos y Cierres Programados</strong></div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver al Panel</a>
    </div>

    <div class="container">
        <?php if($mensaje): ?><div style="background:#e4f8dd; color:var(--imss-verde); padding:10px; border-radius:5px; margin-bottom:15px; font-weight:bold;"><?php echo $mensaje; ?></div><?php endif; ?>
        <?php if($error): ?><div style="background:#fdf3f5; color:var(--imss-dorado); padding:10px; border-radius:5px; margin-bottom:15px; font-weight:bold;"><?php echo $error; ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin-top:0; color:var(--imss-dorado);"><i class="fa-solid fa-plus-circle"></i> Registrar Nuevo Festivo o Suspensión</h3>
            <form method="POST">
                <input type="hidden" name="accion" value="nuevo">
                
                <div style="display: grid; grid-template-columns: 180px 1fr 280px; gap: 15px; align-items: start;">
                    <div>
                        <label style="font-weight:bold; font-size:12px; display:block; margin-bottom:5px; color:#637381;">Fecha:</label>
                        <input type="date" name="fecha" class="form-control" required>
                    </div>
                    <div>
                        <label style="font-weight:bold; font-size:12px; display:block; margin-bottom:5px; color:#637381;">Descripción del Motivo:</label>
                        <input type="text" name="descripcion" class="form-control" placeholder="Ej. Día de la Independencia, Entrega de Info Nucleos..." required>
                    </div>
                    <div>
                        <label style="font-weight:bold; font-size:12px; display:block; margin-bottom:5px; color:#637381;">Tipo de Afectación:</label>
                        <select name="tipo_festivo" id="tipo_festivo" class="form-control" onchange="toggleClinicasBox()" required>
                            <option value="general">1. Festivo General (Habilita Fines de Semana)</option>
                            <option value="administrativo_nucleos">2. Cierre Administrativo (Núcleos / Específicos)</option>
                            <option value="general_laboral">3. Suspensión General (Sin Fines de Semana)</option>
                        </select>
                    </div>
                </div>

                <!-- SECCIÓN DINÁMICA DE CLÍNICAS ORDENADAS ALFABÉTICAMENTE -->
                <div id="box_clinicas" class="clinicas-box">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label style="font-weight: bold; font-size: 12px; color: var(--imss-dorado);">
                            <i class="fa-solid fa-hospital"></i> Seleccione las clínicas que suspenderán labores este día:
                        </label>
                        <div>
                            <button type="button" onclick="marcarNucleos1Al6()" style="font-size: 11px; padding: 3px 8px; background: #e2e8f0; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Preseleccionar Núcleos 1 al 6</button>
                            <button type="button" onclick="marcarTodasClinicas(true)" style="font-size: 11px; padding: 3px 8px; background: #e2e8f0; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Marcar Todas</button>
                            <button type="button" onclick="marcarTodasClinicas(false)" style="font-size: 11px; padding: 3px 8px; background: #e2e8f0; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Desmarcar</button>
                        </div>
                    </div>
                    <div class="clinicas-grid">
                        <?php foreach($lista_clinicas as $cli): ?>
                            <label style="font-size: 12px; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" name="clinicas_afectadas[]" value="<?php echo htmlspecialchars($cli['nombre_clinica']); ?>" class="chk-clinica" data-nombre="<?php echo strtolower($cli['nombre_clinica']); ?>">
                                <?php echo htmlspecialchars($cli['nombre_clinica']); ?> <span style="font-size: 10px; color: #919eab;">(<?php echo htmlspecialchars($cli['tipo_clinica']); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top: 15px; text-align: right;">
                    <button type="submit" class="btn"><i class="fa-solid fa-save"></i> Guardar Registro</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-top:0;"><i class="fa-solid fa-list"></i> Festivos y Suspensiones Registradas</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Fecha</th>
                        <th>Descripción</th>
                        <th>Tipo de Afectación</th>
                        <th>Clínicas / Alcance</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($festivos) > 0): foreach($festivos as $f): 
                        $t_val = $f['tipo_festivo'] ?? 'general';
                        $badge_class = 'badge-general';
                        $t_label = 'Festivo General';
                        if($t_val === 'administrativo_nucleos') {
                            $badge_class = 'badge-administrativo';
                            $t_label = 'Cierre Administrativo';
                        } elseif($t_val === 'general_laboral') {
                            $badge_class = 'badge-suspension';
                            $t_label = 'Suspensión General';
                        }
                    ?>
                    <tr>
                        <td><?php echo $f['id_festivo']; ?></td>
                        <td><strong><?php echo $f['fecha']; ?></strong></td>
                        <td><?php echo htmlspecialchars($f['descripcion']); ?></td>
                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $t_label; ?></span></td>
                        <td>
                            <?php if(!empty($f['clinicas_afectadas'])): ?>
                                <span style="font-size: 11px; color: #333;" title="<?php echo htmlspecialchars($f['clinicas_afectadas']); ?>">
                                    <?php 
                                        $c_list = explode(',', $f['clinicas_afectadas']);
                                        if(count($c_list) > 2) {
                                            echo htmlspecialchars($c_list[0] . ", " . $c_list[1] . " y " . (count($c_list)-2) . " más...");
                                        } else {
                                            echo htmlspecialchars($f['clinicas_afectadas']);
                                        }
                                    ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size: 11px; color: #919eab; font-style: italic;">Aplica a todo el sistema</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="admin_festivos.php?del=<?php echo $f['id_festivo']; ?>" onclick="return confirm('¿Está seguro de eliminar este registro?');" style="color:var(--imss-dorado); text-decoration:none; font-weight:bold;"><i class="fa-solid fa-trash"></i> Eliminar</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6" style="text-align:center; color:#637381; padding:20px;">No hay días festivos o suspensiones registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function toggleClinicasBox() {
            const tipo = document.getElementById('tipo_festivo').value;
            const box = document.getElementById('box_clinicas');
            if(tipo === 'administrativo_nucleos') {
                box.style.display = 'block';
            } else {
                box.style.display = 'none';
                marcarTodasClinicas(false);
            }
        }

        function marcarTodasClinicas(estado) {
            document.querySelectorAll('.chk-clinica').forEach(chk => {
                chk.checked = estado;
            });
        }

        function marcarNucleos1Al6() {
            document.querySelectorAll('.chk-clinica').forEach(chk => {
                const nombre = chk.getAttribute('data-nombre');
                if((nombre.includes('nucleo') || nombre.includes('núcleo')) && (nombre.includes('1') || nombre.includes('2') || nombre.includes('3') || nombre.includes('4') || nombre.includes('5') || nombre.includes('6'))) {
                    chk.checked = true;
                } else {
                    chk.checked = false;
                }
            });
        }
    </script>
</body>
</html>