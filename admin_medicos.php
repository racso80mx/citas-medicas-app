<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

// Asegurar que exista la columna universidad en la tabla medicos
try {
    $pdo->exec("ALTER TABLE medicos ADD COLUMN IF NOT EXISTS universidad VARCHAR(150)");
} catch (Exception $e) {}

// ===================================================================
// EXPORTAR A EXCEL (CSV)
// ===================================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=catalogo_medicos_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para UTF-8 en Excel
    
    fputcsv($output, ['ID', 'Cedula Profesional', 'Nombre', 'Apellido Paterno', 'Apellido Materno', 'Especialidad', 'Universidad', 'Telefono', 'Correo', 'Estatus', 'Fecha Registro']);
    
    $stmtExp = $pdo->query("SELECT * FROM medicos ORDER BY id_medico DESC");
    while ($row = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id_medico'],
            $row['cedula_profesional'],
            $row['nombre'],
            $row['apaterno'],
            $row['amaterno'],
            $row['especialidad'],
            $row['universidad'] ?? '',
            $row['telefono'],
            $row['correo'],
            $row['estatus'],
            $row['fecha_registro'] ?? ''
        ]);
    }
    fclose($output);
    exit();
}

$mensaje = '';
$error = '';

// ===================================================================
// PROCESAMIENTO POST (ALTAS, EDICIONES, BAJAS)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_medico') {
        $sin_cedula   = isset($_POST['sin_cedula']) && $_POST['sin_cedula'] === '1';
        $cedula       = $sin_cedula ? 'MEDICO PASANTE' : trim($_POST['cedula_profesional'] ?? '');
        $nombre       = trim($_POST['nombre'] ?? '');
        $apaterno     = trim($_POST['apaterno'] ?? '');
        $amaterno     = trim($_POST['amaterno'] ?? '');
        $especialidad = trim($_POST['especialidad'] ?? '');
        $universidad  = trim($_POST['universidad'] ?? '');
        $telefono     = trim($_POST['telefono'] ?? '');
        $correo       = trim($_POST['correo'] ?? '');
        $estatus      = trim($_POST['estatus'] ?? 'ACTIVO');

        if (!empty($cedula) && !empty($nombre) && !empty($apaterno)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO medicos (cedula_profesional, nombre, apaterno, amaterno, especialidad, universidad, telefono, correo, estatus, fecha_registro) VALUES (:ced, :nom, :apat, :amat, :esp, :univ, :tel, :corr, :est, NOW())");
                $stmt->execute([
                    'ced'  => mb_strtoupper($cedula, 'UTF-8'), 
                    'nom'  => mb_strtoupper($nombre, 'UTF-8'), 
                    'apat' => mb_strtoupper($apaterno, 'UTF-8'), 
                    'amat' => mb_strtoupper($amaterno, 'UTF-8'),
                    'esp'  => mb_strtoupper($especialidad, 'UTF-8'), 
                    'univ' => mb_strtoupper($universidad, 'UTF-8'),
                    'tel'  => $telefono, 
                    'corr' => $correo, 
                    'est'  => $estatus
                ]);
                $mensaje = "Médico registrado exitosamente.";
            } catch (Exception $e) {
                $error = "Error: La cédula profesional o registro ya se encuentra registrado.";
            }
        } else {
            $error = "Los campos obligatorios (Cédula o Pasante, Nombre y Apellido Paterno) deben completarse.";
        }
    }

    if ($accion === 'editar_medico') {
        $id_medico    = intval($_POST['id_medico'] ?? 0);
        $sin_cedula   = isset($_POST['sin_cedula']) && $_POST['sin_cedula'] === '1';
        $cedula       = $sin_cedula ? 'MEDICO PASANTE' : trim($_POST['cedula_profesional'] ?? '');
        $nombre       = trim($_POST['nombre'] ?? '');
        $apaterno     = trim($_POST['apaterno'] ?? '');
        $amaterno     = trim($_POST['amaterno'] ?? '');
        $especialidad = trim($_POST['especialidad'] ?? '');
        $universidad  = trim($_POST['universidad'] ?? '');
        $telefono     = trim($_POST['telefono'] ?? '');
        $correo       = trim($_POST['correo'] ?? '');
        $estatus      = trim($_POST['estatus'] ?? 'ACTIVO');

        if ($id_medico > 0 && !empty($cedula) && !empty($nombre)) {
            try {
                $stmt = $pdo->prepare("UPDATE medicos SET cedula_profesional = :ced, nombre = :nom, apaterno = :apat, amaterno = :amat, especialidad = :esp, universidad = :univ, telefono = :tel, correo = :corr, estatus = :est WHERE id_medico = :id");
                $stmt->execute([
                    'ced' => mb_strtoupper($cedula, 'UTF-8'), 
                    'nom' => mb_strtoupper($nombre, 'UTF-8'), 
                    'apat' => mb_strtoupper($apaterno, 'UTF-8'), 
                    'amat' => mb_strtoupper($amaterno, 'UTF-8'),
                    'esp' => mb_strtoupper($especialidad, 'UTF-8'),
                    'univ'=> mb_strtoupper($universidad, 'UTF-8'),
                    'tel' => $telefono, 
                    'corr' => $correo, 
                    'est' => $estatus, 
                    'id' => $id_medico
                ]);
                $mensaje = "Médico actualizado correctamente.";
            } catch (Exception $e) {
                $error = "Error al actualizar el médico.";
            }
        } else {
            $error = "Datos incompletos para la edición.";
        }
    }

    if ($accion === 'eliminar_medico') {
        $id_medico = intval($_POST['id_medico'] ?? 0);
        if ($id_medico > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM medicos WHERE id_medico = :id");
                $stmt->execute(['id' => $id_medico]);
                $mensaje = "Médico eliminado correctamente.";
            } catch (Exception $e) {
                $error = "Error al eliminar el médico.";
            }
        }
    }
}

// Obtener todos los médicos sin filtros de fecha
$stmt = $pdo->query("SELECT * FROM medicos ORDER BY id_medico DESC");
$medicos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Catálogo de Médicos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-verde-hover: #004d34; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --borde: #dfe3e8; --excel-verde: #217346; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-dorado); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 1400px; margin: 25px auto; padding: 0 20px; }
        
        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-verde); }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid var(--imss-dorado); }

        .control-panel { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px; border-top: 4px solid var(--imss-verde); align-items: center; }
        .btn { padding: 10px 15px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-primary:hover { background-color: var(--imss-verde-hover); }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-excel:hover { opacity: 0.9; }
        .btn-temp { background-color: #0284c7; }
        .btn-temp:hover { background-color: #0369a1; }
        
        .btn-edit { background-color: #0288d1; color: white; border: none; padding: 6px 10px; font-size: 12px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: bold; }
        .btn-edit:hover { background-color: #01579b; }
        .btn-delete { background-color: var(--imss-dorado); color: white; border: none; padding: 6px 10px; font-size: 12px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: bold; }
        .btn-delete:hover { background-color: #7d1b33; }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); color: #637381; cursor: pointer; user-select: none; white-space: nowrap; }
        th:hover { background-color: #e9ecef; }
        th i { margin-left: 5px; font-size: 12px; color: #adb5bd; }
        td { padding: 12px 10px; border-bottom: 1px solid var(--borde); }
        tr:hover { background-color: #f8f9fa; }

        /* Estilos Modales */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 100%; max-width: 800px; border-radius: 10px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde); padding-bottom: 10px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: var(--imss-verde); font-size: 18px; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #637381; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 9px 12px; font-size: 13px; border: 2px solid var(--borde); border-radius: 6px; box-sizing: border-box; outline: none; }
        .form-control:focus { border-color: var(--imss-verde); }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-user-doctor"></i> <strong>Catálogo y Mantenimiento de Médicos</strong></div>
        <div style="display:flex; gap:15px; align-items:center;">
            <a href="registro_medico.php" target="_blank" style="background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius:4px; font-size:13px;"><i class="fa-solid fa-user-plus"></i> Enlace Auto-Registro Médicos</a>
            <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Volver a Catálogos</a>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="control-panel">
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; width: 100%; justify-content: space-between;">
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevo()">
                        <i class="fa-solid fa-plus"></i> Nuevo Médico
                    </button>
                    
                    <a href="registro_medico.php" target="_blank" class="btn btn-temp">
                        <i class="fa-solid id-card"></i> Vista Auto-Registro Médicos
                    </a>

                    <a href="admin_medicos.php?export=excel" class="btn btn-excel">
                        <i class="fa-solid fa-file-excel"></i> Descargar Excel
                    </a>
                </div>

                <!-- CAMPO DE BÚSQUEDA RÁPIDA -->
                <div style="display: flex; align-items: center; gap: 8px; background: #fff; border: 2px solid var(--borde); border-radius: 6px; padding: 6px 12px;">
                    <i class="fa-solid fa-magnifying-glass" style="color: #637381;"></i>
                    <input type="text" id="inputBuscarMedico" placeholder="BUSCAR MÉDICO..." onkeyup="filtrarTablaMedicos()" style="border: none; outline: none; font-size: 13px; text-transform: uppercase; width: 220px;">
                </div>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <!-- Toggle Mostrar Importación -->
                    <div style="display: inline-flex; align-items: center; gap: 6px; background: #f8f9fa; padding: 6px 12px; border: 1px solid var(--borde); border-radius: 6px;">
                        <input type="checkbox" id="chkImportar" onchange="toggleImportacion()" style="cursor: pointer; width: 16px; height: 16px;">
                        <label for="chkImportar" style="font-size: 14px; font-weight: bold; cursor: pointer; color: var(--excel-verde); display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fa-solid fa-file-excel"></i> Mostrar importación
                        </label>
                    </div>

                    <!-- Panel de Importación (Oculto por defecto) -->
                    <div id="panelImportacion" style="display: none; align-items: center; gap: 10px;">
                        <a href="descargar_plantilla_medicos.php" class="btn btn-excel">
                            <i class="fa-solid fa-download"></i> Plantilla
                        </a>
                        <form action="importar_medicos.php" method="POST" enctype="multipart/form-data" style="display: inline-flex; gap: 5px; align-items: center; margin: 0;">
                            <input type="file" name="archivo_excel" accept=".xlsx, .xls, .csv" required style="font-size: 12px; max-width: 180px;">
                            <button type="submit" class="btn btn-excel"><i class="fa-solid fa-upload"></i> Subir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table id="tablaMedicos">
                <thead>
                    <tr>
                        <th onclick="ordenarTabla(0)">ID <i class="fa-solid fa-sort"></i></th>
                        <th onclick="ordenarTabla(1)">Cédula Profesional <i class="fa-solid fa-sort"></i></th>
                        <th onclick="ordenarTabla(2)">Nombre Completo <i class="fa-solid fa-sort"></i></th>
                        <th onclick="ordenarTabla(3)">Especialidad <i class="fa-solid fa-sort"></i></th>
                        <th onclick="ordenarTabla(4)">Universidad <i class="fa-solid fa-sort"></i></th>
                        <th onclick="ordenarTabla(5)">Teléfono <i class="fa-solid fa-sort"></i></th>
                        <th onclick="ordenarTabla(6)">Correo <i class="fa-solid fa-sort"></i></th>
                        <th onclick="ordenarTabla(7)">Estatus <i class="fa-solid fa-sort"></i></th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($medicos) > 0): ?>
                        <?php foreach ($medicos as $m): ?>
                            <tr>
                                <td><?php echo $m['id_medico']; ?></td>
                                <td><strong><?php echo htmlspecialchars($m['cedula_profesional']); ?></strong></td>
                                <td><?php echo htmlspecialchars($m['nombre'] . ' ' . $m['apaterno'] . ' ' . $m['amaterno']); ?></td>
                                <td><?php echo htmlspecialchars($m['especialidad']); ?></td>
                                <td style="font-size: 12px;"><?php echo htmlspecialchars($m['universidad'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($m['telefono']); ?></td>
                                <td><?php echo htmlspecialchars($m['correo']); ?></td>
                                <td><span style="background:#eef2f5; padding: 2px 6px; border-radius: 4px; font-size:12px;"><?php echo htmlspecialchars($m['estatus']); ?></span></td>
                                <td style="text-align: center; display: flex; gap: 6px; justify-content: center;">
                                    <button type="button" class="btn-edit" onclick="abrirModalEditar(<?php echo $m['id_medico']; ?>, '<?php echo htmlspecialchars($m['cedula_profesional'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['nombre'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['apaterno'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['amaterno'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['especialidad'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['universidad'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['telefono'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['correo'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['estatus'], ENT_QUOTES); ?>')">
                                        <i class="fa-solid fa-pen-to-square"></i> Editar
                                    </button>
                                    <form action="admin_medicos.php" method="POST" onsubmit="return confirm('¿Está seguro de eliminar este médico?');" style="margin:0;">
                                        <input type="hidden" name="accion" value="eliminar_medico">
                                        <input type="hidden" name="id_medico" value="<?php echo $m['id_medico']; ?>">
                                        <button type="submit" class="btn-delete"><i class="fa-solid fa-trash"></i> Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #637381; padding: 30px;">No hay médicos registrados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL NUEVO MÉDICO -->
    <div class="modal-overlay" id="modalNuevo">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-plus"></i> Registrar Nuevo Médico</h3>
                <button type="button" class="close-modal" onclick="cerrarModalNuevo()">&times;</button>
            </div>
            <form action="admin_medicos.php" method="POST">
                <input type="hidden" name="accion" value="agregar_medico">
                
                <div class="form-group" style="background: #f8f9fa; padding: 10px; border-radius: 6px; border: 1px solid var(--borde);">
                    <label style="color: var(--imss-dorado); cursor: pointer; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <input type="checkbox" name="sin_cedula" id="nuevoSinCedula" value="1" onchange="togglePasante('nuevo')" style="width: 16px; height: 16px; cursor: pointer;">
                        <strong>Sin Cédula Profesional (Seleccionar Médico Pasante)</strong>
                    </label>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Cédula Profesional *</label>
                        <input type="text" name="cedula_profesional" id="nuevoCedula" class="form-control" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Especialidad</label>
                        <input type="text" name="especialidad" class="form-control" style="text-transform: uppercase;" value="MEDICINA GENERAL">
                    </div>
                    <div class="form-group">
                        <label>Universidad de Egreso</label>
                        <input type="text" name="universidad" class="form-control" style="text-transform: uppercase;" placeholder="OPCIONAL">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" class="form-control" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Apellido Paterno *</label>
                        <input type="text" name="apaterno" class="form-control" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Apellido Materno</label>
                        <input type="text" name="amaterno" class="form-control" style="text-transform: uppercase;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico</label>
                        <input type="email" name="correo" class="form-control" style="text-transform: none;">
                    </div>
                    <div class="form-group">
                        <label>Estatus</label>
                        <select name="estatus" class="form-control">
                            <option value="ACTIVO">ACTIVO</option>
                            <option value="INACTIVO">INACTIVO</option>
                        </select>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" style="background:#637381; margin-right: 10px;" onclick="cerrarModalNuevo()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Médico</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR MÉDICO -->
    <div class="modal-overlay" id="modalEditar">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Médico</h3>
                <button type="button" class="close-modal" onclick="cerrarModalEditar()">&times;</button>
            </div>
            <form action="admin_medicos.php" method="POST">
                <input type="hidden" name="accion" value="editar_medico">
                <input type="hidden" name="id_medico" id="editIdMedico">
                
                <div class="form-group" style="background: #f8f9fa; padding: 10px; border-radius: 6px; border: 1px solid var(--borde);">
                    <label style="color: var(--imss-dorado); cursor: pointer; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <input type="checkbox" name="sin_cedula" id="editSinCedula" value="1" onchange="togglePasante('edit')" style="width: 16px; height: 16px; cursor: pointer;">
                        <strong>Sin Cédula Profesional (Seleccionar Médico Pasante)</strong>
                    </label>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Cédula Profesional *</label>
                        <input type="text" name="cedula_profesional" id="editCedula" class="form-control" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Especialidad</label>
                        <input type="text" name="especialidad" id="editEspecialidad" class="form-control" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Universidad de Egreso</label>
                        <input type="text" name="universidad" id="editUniversidad" class="form-control" style="text-transform: uppercase;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" id="editNombre" class="form-control" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Apellido Paterno *</label>
                        <input type="text" name="apaterno" id="editApaterno" class="form-control" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Apellido Materno</label>
                        <input type="text" name="amaterno" id="editAmaterno" class="form-control" style="text-transform: uppercase;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono" id="editTelefono" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico</label>
                        <input type="email" name="correo" id="editCorreo" class="form-control" style="text-transform: none;">
                    </div>
                    <div class="form-group">
                        <label>Estatus</label>
                        <select name="estatus" id="editEstatus" class="form-control">
                            <option value="ACTIVO">ACTIVO</option>
                            <option value="INACTIVO">INACTIVO</option>
                        </select>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" style="background:#637381; margin-right: 10px;" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Actualizar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Función de búsqueda en tiempo real
        function filtrarTablaMedicos() {
            let input = document.getElementById('inputBuscarMedico');
            let filter = input.value.toUpperCase();
            let table = document.getElementById('tablaMedicos');
            let tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                let tdCedula = tr[i].getElementsByTagName('td')[1];
                let tdNombre = tr[i].getElementsByTagName('td')[2];
                let tdEspecialidad = tr[i].getElementsByTagName('td')[3];
                let tdUniversidad = tr[i].getElementsByTagName('td')[4];
                
                if (tdCedula || tdNombre || tdEspecialidad || tdUniversidad) {
                    let txtCedula = tdCedula ? (tdCedula.textContent || tdCedula.innerText) : '';
                    let txtNombre = tdNombre ? (tdNombre.textContent || tdNombre.innerText) : '';
                    let txtEspecialidad = tdEspecialidad ? (tdEspecialidad.textContent || tdEspecialidad.innerText) : '';
                    let txtUniversidad = tdUniversidad ? (tdUniversidad.textContent || tdUniversidad.innerText) : '';

                    if (txtCedula.toUpperCase().indexOf(filter) > -1 || 
                        txtNombre.toUpperCase().indexOf(filter) > -1 || 
                        txtEspecialidad.toUpperCase().indexOf(filter) > -1 || 
                        txtUniversidad.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        function toggleImportacion() {
            const chk = document.getElementById('chkImportar');
            const panel = document.getElementById('panelImportacion');
            panel.style.display = chk.checked ? 'inline-flex' : 'none';
        }

        function togglePasante(tipo) {
            const chk = document.getElementById(tipo + 'SinCedula');
            const inputCedula = document.getElementById(tipo + 'Cedula');
            if (chk.checked) {
                inputCedula.value = 'MEDICO PASANTE';
                inputCedula.readOnly = true;
                inputCedula.style.background = '#e9ecef';
            } else {
                inputCedula.value = '';
                inputCedula.readOnly = false;
                inputCedula.style.background = '#fff';
            }
        }

        function abrirModalNuevo() {
            document.getElementById('nuevoSinCedula').checked = false;
            const inputCedula = document.getElementById('nuevoCedula');
            inputCedula.value = '';
            inputCedula.readOnly = false;
            inputCedula.style.background = '#fff';
            document.getElementById('modalNuevo').style.display = 'flex';
        }
        function cerrarModalNuevo() {
            document.getElementById('modalNuevo').style.display = 'none';
        }

        function abrirModalEditar(id, cedula, nombre, apaterno, amaterno, especialidad, universidad, telefono, correo, estatus) {
            document.getElementById('editIdMedico').value = id;
            document.getElementById('editCedula').value = cedula;
            document.getElementById('editNombre').value = nombre;
            document.getElementById('editApaterno').value = apaterno;
            document.getElementById('editAmaterno').value = amaterno;
            document.getElementById('editEspecialidad').value = especialidad;
            document.getElementById('editUniversidad').value = universidad;
            document.getElementById('editTelefono').value = telefono;
            document.getElementById('editCorreo').value = correo;
            document.getElementById('editEstatus').value = estatus;

            const chkPasante = document.getElementById('editSinCedula');
            const inputCedula = document.getElementById('editCedula');
            if (cedula === 'MEDICO PASANTE') {
                chkPasante.checked = true;
                inputCedula.readOnly = true;
                inputCedula.style.background = '#e9ecef';
            } else {
                chkPasante.checked = false;
                inputCedula.readOnly = false;
                inputCedula.style.background = '#fff';
            }

            document.getElementById('modalEditar').style.display = 'flex';
        }
        function cerrarModalEditar() {
            document.getElementById('modalEditar').style.display = 'none';
        }

        function ordenarTabla(n) {
            var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
            table = document.getElementById("tablaMedicos");
            switching = true;
            dir = "asc";
            while (switching) {
                switching = false;
                rows = table.rows;
                for (i = 1; i < (rows.length - 1); i++) {
                    shouldSwitch = false;
                    x = rows[i].getElementsByTagName("TD")[n];
                    y = rows[i + 1].getElementsByTagName("TD")[n];
                    if (x && y) {
                        var xVal = x.textContent || x.innerText;
                        var yVal = y.textContent || y.innerText;
                        if (!isNaN(xVal) && !isNaN(yVal)) {
                            if (dir == "asc" && Number(xVal) > Number(yVal)) { shouldSwitch = true; break; }
                            else if (dir == "desc" && Number(xVal) < Number(yVal)) { shouldSwitch = true; break; }
                        } else {
                            if (dir == "asc" && xVal.toLowerCase() > yVal.toLowerCase()) { shouldSwitch = true; break; }
                            else if (dir == "desc" && xVal.toLowerCase() < yVal.toLowerCase()) { shouldSwitch = true; break; }
                        }
                    }
                }
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    switchcount++;
                } else {
                    if (switchcount == 0 && dir == "asc") {
                        dir = "desc";
                        switching = true;
                    }
                }
            }
        }
    </script>
</body>
</html>