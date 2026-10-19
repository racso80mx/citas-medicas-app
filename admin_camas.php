<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y SEGURIDAD ESTRICTA (ROL SUPERADMIN = 1)
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

// Verificación y autorreparación de la tabla camas y sus relaciones en PostgreSQL
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS camas (
        id_cama SERIAL PRIMARY KEY,
        id_area INT NOT NULL,
        numero_cama VARCHAR(50) NOT NULL,
        estatus VARCHAR(30) DEFAULT 'DISPONIBLE',
        observaciones TEXT
    )");
    $pdo->exec("ALTER TABLE camas ADD COLUMN IF NOT EXISTS id_area INT");
    $pdo->exec("ALTER TABLE camas ADD COLUMN IF NOT EXISTS numero_cama VARCHAR(50)");
    $pdo->exec("ALTER TABLE camas ADD COLUMN IF NOT EXISTS estatus VARCHAR(30) DEFAULT 'DISPONIBLE'");
    $pdo->exec("ALTER TABLE camas ADD COLUMN IF NOT EXISTS observaciones TEXT");
} catch (Exception $e) {}

$mensaje = '';
$error = '';

// ===================================================================
// 2. PROCESAMIENTO DE ACCIONES (POST: CREAR, EDITAR, ELIMINAR)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $id_area     = intval($_POST['id_area'] ?? 0);
        $numero_cama = mb_strtoupper(trim($_POST['numero_cama'] ?? ''), 'UTF-8');
        $estatus     = mb_strtoupper(trim($_POST['estatus'] ?? 'DISPONIBLE'), 'UTF-8');
        $observaciones = mb_strtoupper(trim($_POST['observaciones'] ?? ''), 'UTF-8');

        if ($id_area > 0 && !empty($numero_cama)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO camas (id_area, numero_cama, estatus, observaciones) VALUES (:id_area, :numero_cama, :estatus, :observaciones)");
                $stmt->execute([
                    'id_area'       => $id_area,
                    'numero_cama'   => $numero_cama,
                    'estatus'       => $estatus,
                    'observaciones' => $observaciones
                ]);
                $mensaje = "Cama registrada exitosamente en el área seleccionada.";
            } catch (Exception $e) {
                $error = "Error al registrar la cama: " . $e->getMessage();
            }
        } else {
            $error = "Debe seleccionar un área y especificar el número o nombre de la cama.";
        }
    }

    if ($accion === 'editar') {
        $id_cama     = intval($_POST['id_cama'] ?? 0);
        $id_area     = intval($_POST['id_area'] ?? 0);
        $numero_cama = mb_strtoupper(trim($_POST['numero_cama'] ?? ''), 'UTF-8');
        $estatus     = mb_strtoupper(trim($_POST['estatus'] ?? 'DISPONIBLE'), 'UTF-8');
        $observaciones = mb_strtoupper(trim($_POST['observaciones'] ?? ''), 'UTF-8');

        if ($id_cama > 0 && $id_area > 0 && !empty($numero_cama)) {
            try {
                $stmt = $pdo->prepare("UPDATE camas SET id_area = :id_area, numero_cama = :numero_cama, estatus = :estatus, observaciones = :observaciones WHERE id_cama = :id_cama");
                $stmt->execute([
                    'id_area'       => $id_area,
                    'numero_cama'   => $numero_cama,
                    'estatus'       => $estatus,
                    'observaciones' => $observaciones,
                    'id_cama'       => $id_cama
                ]);
                $mensaje = "Cama actualizada correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar la cama: " . $e->getMessage();
            }
        } else {
            $error = "Datos incompletos para actualizar la cama.";
        }
    }

    if ($accion === 'eliminar') {
        $id_cama = intval($_POST['id_cama'] ?? 0);
        if ($id_cama > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM camas WHERE id_cama = :id_cama");
                $stmt->execute(['id_cama' => $id_cama]);
                $mensaje = "Cama eliminada del sistema.";
            } catch (Exception $e) {
                $error = "No se pudo eliminar la cama: " . $e->getMessage();
            }
        }
    }
}

// ===================================================================
// 3. CONSULTAS DE CATÁLOGOS (ÁREAS Y CAMAS)
// ===================================================================
$areas_hospital = [];
try {
    $areas_hospital = $pdo->query("SELECT * FROM areas_hospital ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$filtro_area = intval($_GET['filtro_area'] ?? 0);
$camas = [];
try {
    if ($filtro_area > 0) {
        $stmtC = $pdo->prepare("
            SELECT c.*, a.nombre AS nombre_area 
            FROM camas c 
            JOIN areas_hospital a ON c.id_area = a.id 
            WHERE c.id_area = :id_area 
            ORDER BY c.numero_cama ASC
        ");
        $stmtC->execute(['id_area' => $filtro_area]);
        $camas = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtC = $pdo->query("
            SELECT c.*, a.nombre AS nombre_area 
            FROM camas c 
            JOIN areas_hospital a ON c.id_area = a.id 
            ORDER BY a.nombre ASC, c.numero_cama ASC
        ");
        $camas = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Fallback si la tabla areas_hospital usa otro ID
    try {
        $camas = $pdo->query("SELECT * FROM camas ORDER BY id_cama ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        $error = "Error al cargar las camas: " . $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Camas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-dorado: #9f2241; --imss-verde: #006847; --fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--fondo); color: var(--texto-oscuro); text-transform: uppercase; }
        
        .navbar { background-color: var(--imss-dorado); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { margin: 0; color: var(--texto-oscuro); display: flex; align-items: center; gap: 10px; }
        
        .btn-principal { background-color: var(--imss-dorado); color: white; border: none; padding: 10px 18px; font-size: 13px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-principal:hover { background-color: #7d1b33; }

        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .filter-card { background: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        
        .card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-dorado); }
        .table-responsive { overflow-x: auto; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; font-weight: 700; border-bottom: 2px solid var(--borde); color: #4b5563; white-space: nowrap; }
        table td { padding: 12px 10px; border-bottom: 1px solid var(--borde); vertical-align: middle; }
        table tr:hover { background-color: #f8f9fa; }

        .badge-disponible { background: #e4f8dd; color: var(--imss-verde); padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
        .badge-ocupada { background: #fdf3f5; color: var(--imss-dorado); padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
        .badge-mantenimiento { background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }

        .btn-accion { padding: 6px 10px; border-radius: 4px; border: none; font-size: 12px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; color: white; margin-right: 3px; }
        .btn-editar { background-color: #0284c7; }
        .btn-eliminar { background-color: #d32f2f; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 600px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde); padding-bottom: 12px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: var(--imss-dorado); font-size: 18px; }
        .close-modal { background: none; border: none; font-size: 22px; cursor: pointer; color: #637381; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 9px 12px; font-size: 13px; border: 2px solid var(--borde); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; text-transform: uppercase; }
        .form-control:focus { border-color: var(--imss-dorado); }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-shield-halved"></i> <strong>Panel de Administración</strong> | Catálogo de Camas y Cubículos</div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver al Panel</a>
    </div>

    <div class="container">
        <div class="page-header">
            <h2><i class="fa-solid fa-bed-pulse"></i> Gestión de Camas por Área Hospitalaria</h2>
            <button type="button" class="btn-principal" onclick="abrirModalCrear()">
                <i class="fa-solid fa-plus"></i> Registrar Nueva Cama
            </button>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Filtro por Área -->
        <div class="filter-card">
            <form method="GET" action="admin_camas.php" style="display: flex; align-items: center; gap: 15px; width: 100%; flex-wrap: wrap;">
                <label style="font-weight: 700; font-size: 13px;"><i class="fa-solid fa-filter"></i> Filtrar por Área:</label>
                <select name="filtro_area" class="form-control" style="max-width: 350px;" onchange="this.form.submit()">
                    <option value="0">-- TODAS LAS ÁREAS HOSPITALARIAS --</option>
                    <?php foreach ($areas_hospital as $ar): 
                        $id_a = $ar['id'] ?? $ar['id_area'] ?? 0;
                    ?>
                        <option value="<?php echo $id_a; ?>" <?php echo ($filtro_area == $id_a) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ar['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filtro_area > 0): ?>
                    <a href="admin_camas.php" class="btn-accion" style="background: #637381; padding: 8px 12px;">Ver Todas</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-top:0; font-size: 15px; color: var(--texto-oscuro);"><i class="fa-solid fa-list"></i> Listado de Camas Registradas</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Área Hospitalaria</th>
                            <th>Número / Identificador de Cama</th>
                            <th>Estatus</th>
                            <th>Observaciones</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($camas) > 0): ?>
                            <?php foreach ($camas as $c): 
                                $estatus = strtoupper(trim($c['estatus'] ?? 'DISPONIBLE'));
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $c['id_cama']; ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($c['nombre_area'] ?? 'S/A'); ?></strong></td>
                                    <td><strong><i class="fa-solid fa-bed"></i> <?php echo htmlspecialchars($c['numero_cama']); ?></strong></td>
                                    <td>
                                        <?php if ($estatus === 'DISPONIBLE'): ?>
                                            <span class="badge-disponible">DISPONIBLE</span>
                                        <?php elseif ($estatus === 'OCUPADA'): ?>
                                            <span class="badge-ocupada">OCUPADA</span>
                                        <?php else: ?>
                                            <span class="badge-mantenimiento">MANTENIMIENTO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['observaciones'] ?? 'NINGUNA'); ?></td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <button type="button" class="btn-accion btn-editar" title="Editar Cama" onclick='abrirModalEditar(<?php echo json_encode($c); ?>)'>
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿CONFIRMA ELIMINAR ESTA CAMA?');">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id_cama" value="<?php echo $c['id_cama']; ?>">
                                            <button type="submit" class="btn-accion btn-eliminar" title="Eliminar Cama">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #637381; padding: 30px;">
                                    NO HAY CAMAS REGISTRADAS EN ESTE FILTRO O ÁREA.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR CAMA -->
    <div class="modal-overlay" id="modalCrear">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-plus-circle"></i> Registrar Nueva Cama</h3>
                <button type="button" class="close-modal" onclick="cerrarModalCrear()">&times;</button>
            </div>
            <form action="admin_camas.php" method="POST">
                <input type="hidden" name="accion" value="crear">
                
                <div class="form-group">
                    <label>Área Hospitalaria de Destino <span style="color:var(--imss-dorado);">*</span></label>
                    <select name="id_area" class="form-control" required>
                        <option value="">-- SELECCIONAR ÁREA --</option>
                        <?php foreach ($areas_hospital as $ar): 
                            $id_a = $ar['id'] ?? $ar['id_area'] ?? 0;
                        ?>
                            <option value="<?php echo $id_a; ?>"><?php echo htmlspecialchars($ar['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Número o Identificador de la Cama <span style="color:var(--imss-dorado);">*</span></label>
                    <input type="text" name="numero_cama" class="form-control" placeholder="EJ. CAMA 01, CUBÍCULO A-4..." required oninput="this.value = this.value.toUpperCase();">
                </div>

                <div class="form-group">
                    <label>Estatus Inicial</label>
                    <select name="estatus" class="form-control">
                        <option value="DISPONIBLE">DISPONIBLE</option>
                        <option value="OCUPADA">OCUPADA</option>
                        <option value="MANTENIMIENTO">MANTENIMIENTO</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Observaciones / Detalles</label>
                    <textarea name="observaciones" class="form-control" rows="3" placeholder="EQUIPAMIENTO ESPECIAL, CAMA ADULTOS/PEDIÁTRICA..." oninput="this.value = this.value.toUpperCase();"></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalCrear()">Cancelar</button>
                    <button type="submit" class="btn-principal">Guardar Cama</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR CAMA -->
    <div class="modal-overlay" id="modalEditar">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Datos de Cama</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditar()">&times;</button>
            </div>
            <form action="admin_camas.php" method="POST">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id_cama" id="editIdCama">
                
                <div class="form-group">
                    <label>Área Hospitalaria <span style="color:var(--imss-dorado);">*</span></label>
                    <select name="id_area" id="editIdArea" class="form-control" required>
                        <?php foreach ($areas_hospital as $ar): 
                            $id_a = $ar['id'] ?? $ar['id_area'] ?? 0;
                        ?>
                            <option value="<?php echo $id_a; ?>"><?php echo htmlspecialchars($ar['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Número o Identificador de la Cama <span style="color:var(--imss-dorado);">*</span></label>
                    <input type="text" name="numero_cama" id="editNumeroCama" class="form-control" required oninput="this.value = this.value.toUpperCase();">
                </div>

                <div class="form-group">
                    <label>Estatus</label>
                    <select name="estatus" id="editEstatus" class="form-control">
                        <option value="DISPONIBLE">DISPONIBLE</option>
                        <option value="OCUPADA">OCUPADA</option>
                        <option value="MANTENIMIENTO">MANTENIMIENTO</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Observaciones / Detalles</label>
                    <textarea name="observaciones" id="editObservaciones" class="form-control" rows="3" oninput="this.value = this.value.toUpperCase();"></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn-principal">Actualizar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCRIPTS DE MODALES -->
    <script>
        function abrirModalCrear() { document.getElementById('modalCrear').style.display = 'flex'; }
        function cerrarModalCrear() { document.getElementById('modalCrear').style.display = 'none'; }

        function abrirModalEditar(c) {
            document.getElementById('editIdCama').value = c.id_cama;
            document.getElementById('editIdArea').value = c.id_area;
            document.getElementById('editNumeroCama').value = c.numero_cama || '';
            document.getElementById('editEstatus').value = c.estatus ? c.estatus.trim().toUpperCase() : 'DISPONIBLE';
            document.getElementById('editObservaciones').value = c.observaciones || '';
            document.getElementById('modalEditar').style.display = 'flex';
        }
        function cerrarModalEditar() { document.getElementById('modalEditar').style.display = 'none'; }
    </script>
</body>
</html>