<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y SEGURIDAD ESTRICTA
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}
require_once 'conexion.php';

// Auto-creación de tabla colonias y registros semilla
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colonias (
        id_colonia SERIAL PRIMARY KEY,
        nombre_colonia VARCHAR(255) NOT NULL,
        nombre_clinica VARCHAR(255) NOT NULL,
        activo BOOLEAN DEFAULT TRUE
    )");

    $count = $pdo->query("SELECT COUNT(*) FROM colonias")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("INSERT INTO colonias (nombre_colonia, nombre_clinica) VALUES 
            ('Col. Centro', 'Nucleo Basico 1'),
            ('Col. Espejo', 'Nucleo Basico 1'),
            ('Col. Centro', 'Gumercindo -Ginecología y Obstetricia Vesp'),
            ('Col. Obrera', 'Gumercindo -Ginecología y Obstetricia Vesp'),
            ('Col. Aviación', 'Nucleo Basico 1')");
    }
} catch (Exception $e) {}

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_colonia') {
        $nombre_colonia = trim($_POST['nombre_colonia'] ?? '');
        $nombre_clinica = trim($_POST['nombre_clinica'] ?? '');

        if (!empty($nombre_colonia) && !empty($nombre_clinica)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO colonias (nombre_colonia, nombre_clinica) VALUES (:col, :cli)");
                $stmt->execute(['col' => $nombre_colonia, 'cli' => $nombre_clinica]);
                $mensaje = "Colonia agregada y vinculada a la clínica correctamente.";
            } catch (Exception $e) {
                $error = "Error al registrar la colonia: " . $e->getMessage();
            }
        } else {
            $error = "Debe proporcionar el nombre de la colonia y seleccionar una clínica.";
        }
    }

    if ($accion === 'editar_colonia') {
        $id_colonia     = intval($_POST['id_colonia'] ?? 0);
        $nombre_colonia = trim($_POST['nombre_colonia'] ?? '');
        $nombre_clinica = trim($_POST['nombre_clinica'] ?? '');

        if ($id_colonia > 0 && !empty($nombre_colonia) && !empty($nombre_clinica)) {
            try {
                $stmt = $pdo->prepare("UPDATE colonias SET nombre_colonia = :col, nombre_clinica = :cli WHERE id_colonia = :id");
                $stmt->execute(['col' => $nombre_colonia, 'cli' => $nombre_clinica, 'id' => $id_colonia]);
                $mensaje = "Colonia actualizada correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar la colonia: " . $e->getMessage();
            }
        } else {
            $error = "Todos los campos son obligatorios para la edición.";
        }
    }

    if ($accion === 'eliminar_colonia') {
        $id_colonia = intval($_POST['id_colonia'] ?? 0);
        if ($id_colonia > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM colonias WHERE id_colonia = :id");
                $stmt->execute(['id' => $id_colonia]);
                $mensaje = "Colonia eliminada correctamente.";
            } catch (Exception $e) {
                $error = "Error al eliminar la colonia.";
            }
        }
    }
}

// Obtener listado de clínicas y colonias
$clinicas = [];
$colonias_lista = [];
try {
    $clinicas = $pdo->query("SELECT * FROM clinicas ORDER BY nombre_clinica ASC")->fetchAll(PDO::FETCH_ASSOC);
    $colonias_lista = $pdo->query("SELECT * FROM colonias ORDER BY nombre_clinica ASC, nombre_colonia ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Colonias</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-verde-hover: #004d34; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde-color: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: var(--texto-oscuro); }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: 700; font-size: 16px; }
        .container { max-width: 1100px; margin: 25px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-header h1 { font-size: 24px; margin: 0; color: var(--imss-verde); }
        
        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); margin-bottom: 25px; }
        .card-title { font-size: 18px; font-weight: 700; margin-top: 0; margin-bottom: 20px; color: var(--texto-oscuro); display: flex; align-items: center; gap: 10px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end; }
        .form-group { margin-bottom: 0; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 10px 12px; font-size: 14px; border: 2px solid var(--borde-color); border-radius: 6px; box-sizing: border-box; outline: none; background: #fff; }
        .form-control:focus { border-color: var(--imss-verde); }

        .btn-action { background-color: var(--imss-verde); color: white; border: none; padding: 10px 20px; font-size: 14px; font-weight: 700; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-action:hover { background-color: var(--imss-verde-hover); }
        
        .btn-edit { background-color: #0288d1; color: white; border: none; padding: 6px 12px; font-size: 12px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-weight: bold; }
        .btn-edit:hover { background-color: #01579b; }

        .btn-delete { background-color: var(--imss-dorado); color: white; border: none; padding: 6px 12px; font-size: 12px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-weight: bold; }
        .btn-delete:hover { background-color: #7d1b33; }

        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 10px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 12px; font-weight: 700; border-bottom: 2px solid var(--borde-color); }
        table td { padding: 12px; border-bottom: 1px solid var(--borde-color); }
        table tr:hover { background-color: #f8f9fa; }

        /* Estilos Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 500px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde-color); padding-bottom: 10px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: var(--imss-verde); font-size: 18px; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #637381; }
    </style>
</head>
<body>

    <div class="navbar">
        <div><i class="fa-solid fa-hospital"></i> <strong>IMSS-BIENESTAR</strong> | Catálogo de Colonias</div>
        <div>
            <a href="admin.php" style="margin-right: 15px;"><i class="fa-solid fa-arrow-left"></i> Volver a Catálogos</a>
            <a href="dashboard.php"><i class="fa-solid fa-house"></i> Menú</a>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1><i class="fa-solid fa-map-location-dot"></i> Gestión de Colonias por Clínica</h1>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- FORMULARIO NUEVA COLONIA -->
        <div class="card">
            <h2 class="card-title"><i class="fa-solid fa-plus-circle"></i> Vincular Nueva Colonia a Clínica</h2>
            <form action="admin_colonias.php" method="POST">
                <input type="hidden" name="accion" value="agregar_colonia">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nombre de la Colonia:</label>
                        <input type="text" name="nombre_colonia" class="form-control" required placeholder="Ej. Col. Centro" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Clínica Asignada:</label>
                        <select name="nombre_clinica" class="form-control" required>
                            <option value="">-- Seleccionar Clínica --</option>
                            <?php foreach($clinicas as $cli): 
                                $nombre_c = $cli['nombre_clinica'] ?? $cli['nombre'] ?? '';
                            ?>
                                <option value="<?php echo htmlspecialchars($nombre_c); ?>"><?php echo htmlspecialchars($nombre_c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-action">
                            <i class="fa-solid fa-save"></i> Guardar Colonia
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- LISTADO DE COLONIAS -->
        <div class="card">
            <h2 class="card-title"><i class="fa-solid fa-list"></i> Colonias Registradas en el Sistema</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Colonia</th>
                            <th>Clínica Vinculada</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($colonias_lista) > 0): ?>
                            <?php foreach($colonias_lista as $col): ?>
                                <tr>
                                    <td><?php echo $col['id_colonia']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($col['nombre_colonia']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($col['nombre_clinica']); ?></td>
                                    <td style="text-align: center; display: flex; gap: 8px; justify-content: center;">
                                        <button type="button" class="btn-edit" onclick="abrirModalEditar(<?php echo $col['id_colonia']; ?>, '<?php echo htmlspecialchars($col['nombre_colonia'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($col['nombre_clinica'], ENT_QUOTES); ?>')">
                                            <i class="fa-solid fa-pen-to-square"></i> Editar
                                        </button>
                                        <form action="admin_colonias.php" method="POST" onsubmit="return confirm('¿Está seguro de eliminar esta vinculación de colonia?');" style="margin:0;">
                                            <input type="hidden" name="accion" value="eliminar_colonia">
                                            <input type="hidden" name="id_colonia" value="<?php echo $col['id_colonia']; ?>">
                                            <button type="submit" class="btn-delete"><i class="fa-solid fa-trash"></i> Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #637381; padding: 20px;">No hay colonias registradas.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR COLONIA -->
    <div class="modal-overlay" id="modalEditar">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Colonia</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditar()">&times;</button>
            </div>
            <form action="admin_colonias.php" method="POST">
                <input type="hidden" name="accion" value="editar_colonia">
                <input type="hidden" name="id_colonia" id="editIdColonia">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Nombre de la Colonia:</label>
                    <input type="text" name="nombre_colonia" id="editNombreColonia" class="form-control" required style="text-transform: uppercase;">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Clínica Asignada:</label>
                    <select name="nombre_clinica" id="editNombreClinica" class="form-control" required>
                        <option value="">-- Seleccionar Clínica --</option>
                        <?php foreach($clinicas as $cli): 
                            $nombre_c = $cli['nombre_clinica'] ?? $cli['nombre'] ?? '';
                        ?>
                            <option value="<?php echo htmlspecialchars($nombre_c); ?>"><?php echo htmlspecialchars($nombre_c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="text-align: right;">
                    <button type="button" class="btn-action" style="background:#637381; margin-right: 10px;" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn-action"><i class="fa-solid fa-save"></i> Actualizar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalEditar(id, colonia, clinica) {
            document.getElementById('editIdColonia').value = id;
            document.getElementById('editNombreColonia').value = colonia;
            document.getElementById('editNombreClinica').value = clinica;
            document.getElementById('modalEditar').style.display = 'flex';
        }

        function cerrarModalEditar() {
            document.getElementById('modalEditar').style.display = 'none';
        }
    </script>
</body>
</html>