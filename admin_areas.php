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

// Verificación y autorreparación de tablas y columnas en PostgreSQL
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS areas_hospital (
        id SERIAL PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL UNIQUE
    )");
    $pdo->exec("ALTER TABLE areas_hospital ADD COLUMN IF NOT EXISTS descripcion TEXT");
    $pdo->exec("ALTER TABLE areas_hospital ADD COLUMN IF NOT EXISTS estatus VARCHAR(20) DEFAULT 'ACTIVO'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS camas (
        id_cama SERIAL PRIMARY KEY,
        id_area INT NOT NULL,
        numero_cama VARCHAR(50) NOT NULL,
        estatus VARCHAR(50) DEFAULT 'DISPONIBLE',
        observaciones TEXT
    )");
} catch (Exception $e) {}

// Detectar cuál es el nombre de la llave primaria en areas_hospital
$pk_col = 'id';
try {
    $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'areas_hospital'")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('id_area', $cols)) {
        $pk_col = 'id_area';
    } elseif (!in_array('id', $cols) && count($cols) > 0) {
        $pdo->exec("ALTER TABLE areas_hospital ADD COLUMN id SERIAL");
    }
} catch (Exception $e) {}

$mensaje = '';
$error = '';

// ===================================================================
// 2. BLOQUE AJAX: GESTIÓN DE CAMAS SIN SALIR DEL MÓDULO
// ===================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'obtener_camas') {
    header('Content-Type: application/json');
    $id_area = intval($_GET['id_area'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM camas WHERE id_area = :id_area ORDER BY numero_cama ASC");
        $stmt->execute(['id_area' => $id_area]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] === 'guardar_cama_ajax') {
    header('Content-Type: application/json');
    $id_cama     = intval($_POST['id_cama'] ?? 0);
    $id_area     = intval($_POST['id_area'] ?? 0);
    $numero_cama = mb_strtoupper(trim($_POST['numero_cama'] ?? ''), 'UTF-8');
    $estatus     = mb_strtoupper(trim($_POST['estatus'] ?? 'DISPONIBLE'), 'UTF-8');
    $obs         = mb_strtoupper(trim($_POST['observaciones'] ?? ''), 'UTF-8');

    if ($id_area > 0 && !empty($numero_cama)) {
        try {
            if ($id_cama > 0) {
                // Actualizar cama existente
                $stmtU = $pdo->prepare("UPDATE camas SET numero_cama = :num, estatus = :est, observaciones = :obs WHERE id_cama = :id_cama");
                $stmtU->execute(['num' => $numero_cama, 'est' => $estatus, 'obs' => $obs, 'id_cama' => $id_cama]);
                echo json_encode(['success' => true, 'message' => 'Cama actualizada correctamente.']);
            } else {
                // Registrar nueva cama
                $stmtC = $pdo->prepare("INSERT INTO camas (id_area, numero_cama, estatus, observaciones) VALUES (:id_area, :numero_cama, :estatus, :obs)");
                $stmtC->execute(['id_area' => $id_area, 'numero_cama' => $numero_cama, 'estatus' => $estatus, 'obs' => $obs]);
                echo json_encode(['success' => true, 'message' => 'Cama registrada exitosamente.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'El número o identificador de la cama es obligatorio.']);
    }
    exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] === 'eliminar_cama_ajax') {
    header('Content-Type: application/json');
    $id_cama = intval($_POST['id_cama'] ?? 0);
    if ($id_cama > 0) {
        try {
            $stmtDelC = $pdo->prepare("DELETE FROM camas WHERE id_cama = :id_cama");
            $stmtDelC->execute(['id_cama' => $id_cama]);
            echo json_encode(['success' => true, 'message' => 'Cama eliminada correctamente.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'ID de cama inválido.']);
    }
    exit;
}

// ===================================================================
// 3. PROCESAMIENTO DE ACCIONES (POST: ÁREAS)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
        $descripcion = mb_strtoupper(trim($_POST['descripcion'] ?? ''), 'UTF-8');
        $estatus = trim($_POST['estatus'] ?? 'ACTIVO');

        if (!empty($nombre)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO areas_hospital (nombre, descripcion, estatus) VALUES (:nombre, :descripcion, :estatus)");
                $stmt->execute(['nombre' => $nombre, 'descripcion' => $descripcion, 'estatus' => $estatus]);
                $mensaje = "Área hospitalaria registrada exitosamente.";
            } catch (Exception $e) {
                $error = "Error al registrar: " . $e->getMessage();
            }
        } else {
            $error = "El nombre del área es obligatorio.";
        }
    }

    if ($accion === 'editar') {
        $id = intval($_POST['id'] ?? 0);
        $nombre = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
        $descripcion = mb_strtoupper(trim($_POST['descripcion'] ?? ''), 'UTF-8');
        $estatus = trim($_POST['estatus'] ?? 'ACTIVO');

        if ($id > 0 && !empty($nombre)) {
            try {
                $stmt = $pdo->prepare("UPDATE areas_hospital SET nombre = :nombre, descripcion = :descripcion, estatus = :estatus WHERE $pk_col = :id");
                $stmt->execute(['nombre' => $nombre, 'descripcion' => $descripcion, 'estatus' => $estatus, 'id' => $id]);
                $mensaje = "Área actualizada correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar: " . $e->getMessage();
            }
        } else {
            $error = "Datos inválidos para la actualización (ID: $id).";
        }
    }

    if ($accion === 'eliminar') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Eliminar primero las camas asociadas para evitar conflictos de integridad
                $stmtCamas = $pdo->prepare("DELETE FROM camas WHERE id_area = :id");
                $stmtCamas->execute(['id' => $id]);

                $stmt = $pdo->prepare("DELETE FROM areas_hospital WHERE $pk_col = :id");
                $stmt->execute(['id' => $id]);
                $mensaje = "Área y sus camas asociadas eliminadas del catálogo.";
            } catch (Exception $e) {
                $error = "No se pudo eliminar el área: " . $e->getMessage();
            }
        }
    }
}

// ===================================================================
// 4. CONSULTA DE ÁREAS REGISTRADAS
// ===================================================================
$areas = [];
try {
    $areas = $pdo->query("SELECT * FROM areas_hospital ORDER BY $pk_col ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error al cargar áreas: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Áreas y Camas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-dorado: #9f2241; --imss-verde: #006847; --fondo: #f4f6f8; --texto-oscuro: #1c252e; --borde: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--fondo); color: var(--texto-oscuro); text-transform: uppercase; }
        
        .navbar { background-color: var(--imss-dorado); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { margin: 0; color: var(--texto-oscuro); display: flex; align-items: center; gap: 10px; }
        
        .btn-principal { background-color: var(--imss-dorado); color: white; border: none; padding: 10px 18px; font-size: 13px; font-weight: bold; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-principal:hover { background-color: #7d1b33; }

        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-dorado); }
        .table-responsive { overflow-x: auto; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; font-weight: 700; border-bottom: 2px solid var(--borde); color: #4b5563; white-space: nowrap; }
        table td { padding: 12px 10px; border-bottom: 1px solid var(--borde); vertical-align: middle; }
        table tr:hover { background-color: #f8f9fa; }

        .badge-activo { background: #e4f8dd; color: var(--imss-verde); padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
        .badge-inactivo { background: #fdf3f5; color: var(--imss-dorado); padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }

        .btn-accion { padding: 6px 10px; border-radius: 4px; border: none; font-size: 12px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; color: white; margin-right: 3px; }
        .btn-camas { background-color: #0d9488; }
        .btn-editar { background-color: #0284c7; }
        .btn-eliminar { background-color: #d32f2f; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 700px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto; }
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
        <div><i class="fa-solid fa-shield-halved"></i> <strong>Panel de Administración</strong> | Catálogo de Áreas Hospitalarias</div>
        <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver al Panel</a>
    </div>

    <div class="container">
        <div class="page-header">
            <h2><i class="fa-solid fa-layer-group"></i> Gestión de Áreas y Servicios</h2>
            <button type="button" class="btn-principal" onclick="abrirModalCrear()">
                <i class="fa-solid fa-plus"></i> Nueva Área Hospitalaria
            </button>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-top:0; font-size: 15px; color: var(--texto-oscuro);"><i class="fa-solid fa-list"></i> Áreas Registradas en el Sistema</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre del Área</th>
                            <th>Descripción / Observaciones</th>
                            <th>Estatus</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($areas) > 0): ?>
                            <?php foreach ($areas as $ar): 
                                $row_id =$ar[$pk_col] ?? $ar['id'] ?? 0;
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $row_id; ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($ar['nombre'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($ar['descripcion'] ?? 'SIN DESCRIPCIÓN'); ?></td>
                                    <td>
                                        <?php if (strtoupper(trim($ar['estatus'] ?? 'ACTIVO')) === 'ACTIVO'): ?>
                                            <span class="badge-activo">ACTIVO</span>
                                        <?php else: ?>
                                            <span class="badge-inactivo">INACTIVO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <button type="button" class="btn-accion btn-camas" title="Gestionar Camas" onclick="abrirModalCamas(<?php echo $row_id; ?>, '<?php echo addslashes($ar['nombre']); ?>')">
                                            <i class="fa-solid fa-bed"></i> Camas
                                        </button>
                                        <button type="button" class="btn-accion btn-editar" title="Editar Área" onclick='abrirModalEditar(<?php echo json_encode(array_merge($ar, ['id' =>$row_id])); ?>)'>
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿CONFIRMA ELIMINAR ESTA ÁREA Y SUS CAMAS ASOCIADAS?');">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id" value="<?php echo $row_id; ?>">
                                            <button type="submit" class="btn-accion btn-eliminar" title="Eliminar Área">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #637381; padding: 30px;">
                                    NO HAY ÁREAS HOSPITALARIAS REGISTRADAS.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL GESTIÓN DE CAMAS (CON AJAX INTEGRADO PARA NO SALIR DEL MÓDULO) -->
    <div class="modal-overlay" id="modalCamas">
        <div class="modal-content" style="max-width: 780px;">
            <div class="modal-header">
                <h3 id="tituloModalCamas"><i class="fa-solid fa-bed" style="color: #0d9488;"></i> Gestión de Camas</h3>
                <button type="button" class="close-modal" onclick="cerrarModalCamas()">&times;</button>
            </div>
            
            <form id="formCamaAjax" onsubmit="guardarCamaAjax(event)" style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid var(--borde); margin-bottom: 20px;">
                <input type="hidden" name="ajax" value="guardar_cama_ajax">
                <input type="hidden" name="id_area" id="camaIdArea" required>
                <input type="hidden" name="id_cama" id="camaIdCama" value="">

                <div style="font-weight: bold; color: var(--imss-dorado); margin-bottom: 10px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
                    <span id="tituloFormCama"><i class="fa-solid fa-plus-circle"></i> Agregar Nueva Cama a esta Área</span>
                    <button type="button" id="btnCancelarEdicionCama" style="display:none; background:#637381; color:white; border:none; padding:3px 8px; border-radius:4px; font-size:11px; cursor:pointer;" onclick="resetearFormCama()">Cancelar Edición</button>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Número / Identificador de Cama *</label>
                        <input type="text" name="numero_cama" id="camaNumeroInput" class="form-control" placeholder="EJ. CAMA 01, CÚBICULO A..." required oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Estatus</label>
                        <select name="estatus" id="camaEstatusSelect" class="form-control">
                            <option value="DISPONIBLE">DISPONIBLE</option>
                            <option value="MANTENIMIENTO">MANTENIMIENTO</option>
                            <option value="OCUPADA">OCUPADA</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 10px;">
                    <label>Observaciones</label>
                    <input type="text" name="observaciones" id="camaObsInput" class="form-control" placeholder="DETALLES..." oninput="this.value = this.value.toUpperCase();">
                </div>

                <div style="text-align: right; margin-top: 10px;">
                    <button type="submit" id="btnSubmitCama" class="btn-principal" style="background-color: #0d9488; padding: 8px 15px;">
                        <i class="fa-solid fa-plus"></i> Registrar Cama
                    </button>
                </div>
            </form>

            <h4 style="margin-bottom: 10px; font-size: 14px; color: var(--texto-oscuro);"><i class="fa-solid fa-list"></i> Camas Registradas en el Área</h4>
            <div style="max-height: 280px; overflow-y: auto; border: 1px solid var(--borde); border-radius: 6px; background: #fff;" id="contenedorListaCamas">
                <div style="padding: 20px; text-align: center; color: #637381;">Cargando camas...</div>
            </div>

            <div style="margin-top: 20px; text-align: right;">
                <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 8px 15px;" onclick="cerrarModalCamas()">Cerrar Ventana</button>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR ÁREA -->
    <div class="modal-overlay" id="modalCrear">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-plus-circle"></i> Registrar Nueva Área</h3>
                <button type="button" class="close-modal" onclick="cerrarModalCrear()">&times;</button>
            </div>
            <form action="admin_areas.php" method="POST">
                <input type="hidden" name="accion" value="crear">
                
                <div class="form-group">
                    <label>Nombre del Área <span style="color:var(--imss-dorado);">*</span></label>
                    <input type="text" name="nombre" class="form-control" placeholder="EJ. URGENCIAS, PEDIATRÍA..." required oninput="this.value = this.value.toUpperCase();">
                </div>

                <div class="form-group">
                    <label>Descripción / Observaciones</label>
                    <textarea name="descripcion" class="form-control" rows="3" placeholder="DETALLES O SERVICIOS QUE COMPRENDE EL ÁREA..." oninput="this.value = this.value.toUpperCase();"></textarea>
                </div>

                <div class="form-group">
                    <label>Estatus</label>
                    <select name="estatus" class="form-control">
                        <option value="ACTIVO">ACTIVO</option>
                        <option value="INACTIVO">INACTIVO</option>
                    </select>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalCrear()">Cancelar</button>
                    <button type="submit" class="btn-principal">Guardar Área</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR ÁREA -->
    <div class="modal-overlay" id="modalEditar">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Área Hospitalaria</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditar()">&times;</button>
            </div>
            <form action="admin_areas.php" method="POST">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id" id="editId">
                
                <div class="form-group">
                    <label>Nombre del Área <span style="color:var(--imss-dorado);">*</span></label>
                    <input type="text" name="nombre" id="editNombre" class="form-control" required oninput="this.value = this.value.toUpperCase();">
                </div>

                <div class="form-group">
                    <label>Descripción / Observaciones</label>
                    <textarea name="descripcion" id="editDescripcion" class="form-control" rows="3" oninput="this.value = this.value.toUpperCase();"></textarea>
                </div>

                <div class="form-group">
                    <label>Estatus</label>
                    <select name="estatus" id="editEstatus" class="form-control">
                        <option value="ACTIVO">ACTIVO</option>
                        <option value="INACTIVO">INACTIVO</option>
                    </select>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-accion" style="background:#637381; color:white; padding: 10px 15px;" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn-principal">Actualizar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function abrirModalCrear() { document.getElementById('modalCrear').style.display = 'flex'; }
        function cerrarModalCrear() { document.getElementById('modalCrear').style.display = 'none'; }

        function abrirModalEditar(ar) {
            document.getElementById('editId').value = ar.id;
            document.getElementById('editNombre').value = ar.nombre || '';
            document.getElementById('editDescripcion').value = ar.descripcion || '';
            document.getElementById('editEstatus').value = ar.estatus ? ar.estatus.trim().toUpperCase() : 'ACTIVO';
            document.getElementById('modalEditar').style.display = 'flex';
        }
        function cerrarModalEditar() { document.getElementById('modalEditar').style.display = 'none'; }

        let areaActualCamasId = 0;
        let nombreAreaActualGlobal = '';

        function abrirModalCamas(idArea, nombreArea) {
            areaActualCamasId = idArea;
            nombreAreaActualGlobal = nombreArea;
            document.getElementById('camaIdArea').value = idArea;
            document.getElementById('tituloModalCamas').innerHTML = `<i class="fa-solid fa-bed" style="color: #0d9488;"></i> GESTIÓN DE CAMAS - ${nombreArea}`;
            resetearFormCama();
            document.getElementById('modalCamas').style.display = 'flex';
            cargarCamasArea(idArea);
        }

        function cerrarModalCamas() {
            document.getElementById('modalCamas').style.display = 'none';
        }

        function resetearFormCama() {
            document.getElementById('camaIdCama').value = '';
            document.getElementById('camaNumeroInput').value = '';
            document.getElementById('camaEstatusSelect').value = 'DISPONIBLE';
            document.getElementById('camaObsInput').value = '';
            document.getElementById('tituloFormCama').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Agregar Nueva Cama a esta Área';
            document.getElementById('btnSubmitCama').innerHTML = '<i class="fa-solid fa-plus"></i> Registrar Cama';
            document.getElementById('btnCancelarEdicionCama').style.display = 'none';
        }

        function cargarCamasArea(idArea) {
            const contenedor = document.getElementById('contenedorListaCamas');
            contenedor.innerHTML = '<div style="padding: 20px; text-align: center; color: #637381;">Cargando camas...</div>';

            fetch(`admin_areas.php?ajax=obtener_camas&id_area=${idArea}`)
                .then(res => res.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        html = '<table style="width:100%; border-collapse:collapse;"><tbody>';
                        data.forEach(c => {
                            let badgeColor = '#16a34a';
                            let estatusUpper = (c.estatus || 'DISPONIBLE').toUpperCase();
                            if(estatusUpper === 'MANTENIMIENTO') badgeColor = '#0284c7';
                            if(estatusUpper === 'OCUPADA') badgeColor = '#9f2241';

                            let camaJson = encodeURIComponent(JSON.stringify(c));

                            html += `<tr style="border-bottom:1px solid #dfe3e8;">` +
                                `<td style="padding:10px;"><strong><i class="fa-solid fa-bed"></i> ${c.numero_cama}</strong></td>` +
                                `<td style="padding:10px;"><span style="background:${badgeColor}; color:white; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:bold;">${estatusUpper}</span></td>` +
                                `<td style="padding:10px; color:#637381; font-size:12px;">${c.observaciones || 'NINGUNA'}</td>` +
                                `<td style="padding:10px; text-align:right; white-space:nowrap;">` +
                                    `<button type="button" class="btn-accion btn-editar" title="Editar Cama" onclick="prepararEdicionCama('${camaJson}')">` +
                                        `<i class="fa-solid fa-pen-to-square"></i>` +
                                    `</button>` +
                                    `<button type="button" class="btn-accion btn-eliminar" title="Eliminar Cama" onclick="eliminarCamaAjax(${c.id_cama})">` +
                                        `<i class="fa-solid fa-trash"></i>` +
                                    `</button>` +
                                `</td>` +
                            `</tr>`;
                        });
                        html += '</tbody></table>';
                    } else {
                        html = '<div style="padding: 25px; text-align: center; color: #637381;">NO HAY CAMAS REGISTRADAS EN ESTE ÁREA.</div>';
                    }
                    contenedor.innerHTML = html;
                })
                .catch(err => {
                    contenedor.innerHTML = '<div style="padding: 20px; text-align: center; color: #9f2241;">Error al cargar las camas.</div>';
                });
        }

        function prepararEdicionCama(camaEnc) {
            let c = JSON.parse(decodeURIComponent(camaEnc));
            document.getElementById('camaIdCama').value = c.id_cama;
            document.getElementById('camaNumeroInput').value = c.numero_cama;
            document.getElementById('camaEstatusSelect').value = c.estatus ? c.estatus.trim().toUpperCase() : 'DISPONIBLE';
            document.getElementById('camaObsInput').value = c.observaciones || '';
            document.getElementById('tituloFormCama').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Editando Cama: ${c.numero_cama}`;
            document.getElementById('btnSubmitCama').innerHTML = '<i class="fa-solid fa-save"></i> Actualizar Cama';
            document.getElementById('btnCancelarEdicionCama').style.display = 'inline-block';
            document.getElementById('camaNumeroInput').focus();
        }

        function guardarCamaAjax(event) {
            event.preventDefault();
            const form = document.getElementById('formCamaAjax');
            const formData = new FormData(form);

            $.ajax({
                url: 'admin_areas.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if(response && response.success) {
                        resetearFormCama();
                        cargarCamasArea(areaActualCamasId);
                    } else {
                        alert('Error: ' + (response && response.error ? response.error : 'No se pudo guardar'));
                    }
                },
                error: function() {
                    alert('Error de conexión con el servidor.');
                }
            });
        }

        function eliminarCamaAjax(idCama) {
            if(!confirm('¿CONFIRMA ELIMINAR ESTA CAMA?')) return;

            $.ajax({
                url: 'admin_areas.php',
                type: 'POST',
                data: {
                    ajax: 'eliminar_cama_ajax',
                    id_cama: idCama
                },
                dataType: 'json',
                success: function(response) {
                    if(response && response.success) {
                        cargarCamasArea(areaActualCamasId);
                    } else {
                        alert('Error al eliminar: ' + (response && response.error ? response.error : 'Desconocido'));
                    }
                },
                error: function() {
                    alert('Error de conexión al eliminar la cama.');
                }
            });
        }
    </script>
</body>
</html>