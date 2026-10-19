<?php
// ===================================================================
// 1. CONFIGURACIÓN Y SEGURIDAD
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}
require_once 'conexion.php';

// Auto-creación de tabla por seguridad
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tipo_paciente (
        id_tipo_paciente SERIAL PRIMARY KEY,
        nombre_tipo VARCHAR(100) NOT NULL UNIQUE,
        descripcion VARCHAR(255),
        activo BOOLEAN DEFAULT TRUE
    )");
} catch (Exception $e) {}

// ===================================================================
// 2. PROCESAMIENTO DE FORMULARIOS (CREAR Y EDITAR)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $nombre = trim($_POST['nombre_tipo'] ?? '');
    $desc   = trim($_POST['descripcion'] ?? '');

    if ($accion == 'nuevo') {
        try {
            $stmt = $pdo->prepare("INSERT INTO tipo_paciente (nombre_tipo, descripcion, activo) VALUES (:nom, :desc, TRUE)");
            $stmt->execute(['nom' => $nombre, 'desc' => $desc]);
            echo "<script>alert('Tipo de paciente registrado con éxito.'); window.location.href='admin_tipo_paciente.php';</script>";
            exit();
        } catch (PDOException $e) {
            echo "<script>alert('Error: El tipo de paciente ya existe o hay un problema en la BD.');</script>";
        }
    }

    if ($accion == 'editar') {
        $id = intval($_POST['id_tipo_paciente'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE tipo_paciente SET nombre_tipo = :nom, descripcion = :desc WHERE id_tipo_paciente = :id");
            $stmt->execute(['nom' => $nombre, 'desc' => $desc, 'id' => $id]);
            echo "<script>alert('Tipo de paciente actualizado correctamente.'); window.location.href='admin_tipo_paciente.php';</script>";
            exit();
        } catch (PDOException $e) {
            echo "<script>alert('Error al actualizar.');</script>";
        }
    }
}

// ===================================================================
// 3. CAMBIAR ESTATUS (ACTIVAR / DESACTIVAR)
// ===================================================================
if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    try {
        $pdo->prepare("UPDATE tipo_paciente SET activo = NOT activo WHERE id_tipo_paciente = :id")->execute(['id' => $id]);
    } catch (Exception $e) {}
    header("Location: admin_tipo_paciente.php");
    exit();
}

// ===================================================================
// 4. CONSULTA DE REGISTROS
// ===================================================================
$tipos = [];
try {
    $tipos = $pdo->query("SELECT * FROM tipo_paciente ORDER BY id_tipo_paciente DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tipos = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Tipos de Paciente</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; 
            --imss-dorado: #9f2241; 
            --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-dorado); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 1100px; margin: 25px auto; padding: 0 20px; }
        
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; justify-content: space-between; align-items: center; }
        
        .btn { padding: 9px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-outline { background-color: white; color: #1c252e; border: 1px solid var(--borde); }
        .btn:hover { opacity: 0.9; }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 15px; border-bottom: 2px solid var(--borde); color: #637381; }
        td { padding: 12px 15px; border-bottom: 1px solid var(--borde); }
        tr:hover { background-color: #f8f9fa; }
        
        .action-btn { background: none; border: none; cursor: pointer; font-size: 15px; margin-right: 8px; color: #637381; }
        .action-btn.edit:hover { color: var(--imss-verde); }
        .action-btn.delete:hover { color: var(--imss-dorado); }

        .badge { background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-weight: bold; font-size: 11px; display: inline-block; }
        .badge.inactive { background: #f8d7da; color: #721c24; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 25px; border-radius: 8px; width: 100%; max-width: 500px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 13px; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 8px; border: 1px solid var(--borde); border-radius: 4px; box-sizing: border-box; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-users-rectangle"></i> <strong>Catálogo: Tipos de Paciente / Programas</strong></div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver a Catálogos</a>
    </div>

    <div class="container">
        <div class="control-panel">
            <h3 style="margin: 0; color: var(--imss-verde);"><i class="fa-solid fa-list-check"></i> Listado de Programas de Atención</h3>
            <button class="btn btn-primary" onclick="abrirModalNuevo()">
                <i class="fa-solid fa-plus"></i> Nuevo Tipo de Paciente
            </button>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre del Programa / Tipo</th>
                        <th>Descripción</th>
                        <th>Estatus</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($tipos) > 0): ?>
                        <?php foreach ($tipos as $t): ?>
                            <tr>
                                <td><?php echo $t['id_tipo_paciente']; ?></td>
                                <td><strong><?php echo htmlspecialchars($t['nombre_tipo']); ?></strong></td>
                                <td><?php echo htmlspecialchars($t['descripcion'] ?: 'Sin descripción'); ?></td>
                                <td>
                                    <?php if ($t['activo']): ?>
                                        <span class="badge">Activo</span>
                                    <?php else: ?>
                                        <span class="badge inactive">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="action-btn edit" title="Editar" onclick='abrirModalEditar(<?php echo json_encode($t); ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
                                    <a href="admin_tipo_paciente.php?toggle=<?php echo $t['id_tipo_paciente']; ?>" class="action-btn delete" title="Cambiar Estatus" onclick="return confirm('¿Deseas cambiar el estatus de este registro?');"><i class="fa-solid fa-power-off"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #637381; padding: 20px;">No hay tipos de paciente registrados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL (CREAR / EDITAR) -->
    <div class="modal" id="modalTipo">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid var(--borde); padding-bottom: 10px;">
                <h3 id="modalTitulo" style="margin: 0; color: #1c252e;">Nuevo Tipo de Paciente</h3>
                <button onclick="cerrarModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #637381;">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="accion" id="formAccion" value="nuevo">
                <input type="hidden" name="id_tipo_paciente" id="formId">
                
                <div class="form-group">
                    <label>Nombre del Programa / Tipo</label>
                    <input type="text" name="nombre_tipo" id="formNombre" required placeholder="Ej. Crónico, Embarazadas, General">
                </div>

                <div class="form-group">
                    <label>Descripción / Observaciones</label>
                    <textarea name="descripcion" id="formDesc" rows="3" placeholder="Breve descripción del programa..."></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalNuevo() {
            document.getElementById('modalTitulo').innerText = 'Registrar Tipo de Paciente';
            document.getElementById('formAccion').value = 'nuevo';
            document.getElementById('formId').value = '';
            document.getElementById('formNombre').value = '';
            document.getElementById('formDesc').value = '';
            document.getElementById('modalTipo').classList.add('active');
        }

        function abrirModalEditar(item) {
            document.getElementById('modalTitulo').innerText = 'Editar Tipo de Paciente';
            document.getElementById('formAccion').value = 'editar';
            document.getElementById('formId').value = item.id_tipo_paciente;
            document.getElementById('formNombre').value = item.nombre_tipo;
            document.getElementById('formDesc').value = item.descripcion || '';
            document.getElementById('modalTipo').classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalTipo').classList.remove('active');
        }
    </script>
</body>
</html>