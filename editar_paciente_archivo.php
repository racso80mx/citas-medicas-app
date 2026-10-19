<?php
// ===================================================================
// ARCHIVO: editar_paciente_archivo.php
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_paciente = $_GET['id'] ?? $_GET['id_paciente'] ?? '';
if (empty($id_paciente)) {
    die("ID de paciente no especificado.");
}

$mensaje = "";
$error = "";

// Procesar formulario de actualización cuando se envía por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre     = trim($_POST['nombre'] ?? '');
    $apaterno   = trim($_POST['apaterno'] ?? '');
    $amaterno   = trim($_POST['amaterno'] ?? '');
    $expediente = trim($_POST['expediente'] ?? '');
    $curp       = trim($_POST['curp'] ?? '');
    $telefono   = trim($_POST['telefono'] ?? '');
    $domicilio  = trim($_POST['domicilio'] ?? '');
    $colonia    = trim($_POST['colonia'] ?? '');
    $edad       = trim($_POST['edad'] ?? '');
    $sexo       = trim($_POST['sexo'] ?? '');

    try {
        $sql_update = "UPDATE pacientes SET 
                        nombre = :nombre, 
                        apaterno = :apaterno, 
                        amaterno = :amaterno, 
                        expediente = :expediente, 
                        curp = :curp, 
                        telefono = :telefono, 
                        domicilio = :domicilio, 
                        colonia = :colonia, 
                        edad = :edad, 
                        sexo = :sexo 
                       WHERE id_paciente = :id_paciente";
        
        $stmt_up = $pdo->prepare($sql_update);
        $stmt_up->execute([
            'nombre'     => $nombre,
            'apaterno'   => $apaterno,
            'amaterno'   => $amaterno,
            'expediente' => $expediente,
            'curp'       => $curp,
            'telefono'   => $telefono,
            'domicilio'  => $domicilio,
            'colonia'    => $colonia,
            'edad'       => $edad,
            'sexo'       => $sexo,
            'id_paciente'=> $id_paciente
        ]);

        $mensaje = "Paciente actualizado correctamente.";
    } catch (Exception $e) {
        $error = "Error al actualizar los datos: " . $e->getMessage();
    }
}

// Consultar datos actuales del paciente
try {
    $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id_paciente LIMIT 1");
    $stmt->execute(['id_paciente' => $id_paciente]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        die("No se encontró el paciente especificado en la base de datos.");
    }
} catch (Exception $e) {
    die("Error en la base de datos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Editar Paciente</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-gris-fondo: #f4f6f8; --borde: #dfe3e8; 
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 800px; margin: 30px auto; padding: 0 15px; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 25px; border-top: 4px solid var(--imss-verde); }
        h2 { color: var(--imss-verde); margin-top: 0; display: flex; align-items: center; gap: 10px; font-size: 20px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 12px; color: #637381; }
        .form-group input, .form-group select { width: 100%; padding: 9px 12px; box-sizing: border-box; border: 1px solid var(--borde); border-radius: 6px; font-size: 13px; outline: none; }
        .form-group input:focus, .form-group select:focus { border-color: var(--imss-verde); }
        .btn-container { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #637381; }
        .alert-success { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; }
        .alert-danger { background: #f8d7da; color: #721c24; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-hospital-user"></i> <strong>Módulo de Archivo - Edición de Pacientes</strong></div>
        <a href="pacientes_archivo.php"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
    </div>

    <div class="container">
        <div class="card">
            <h2><i class="fa-solid fa-user-pen"></i> Editar Información del Paciente</h2>

            <?php if (!empty($mensaje)): ?>
                <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo $mensaje; ?></div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nombre(s):</label>
                        <input type="text" name="nombre" value="<?php echo htmlspecialchars($paciente['nombre'] ?? ''); ?>" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Apellido Paterno:</label>
                        <input type="text" name="apaterno" value="<?php echo htmlspecialchars($paciente['apaterno'] ?? ''); ?>" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Apellido Materno:</label>
                        <input type="text" name="amaterno" value="<?php echo htmlspecialchars($paciente['amaterno'] ?? ''); ?>" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>No. Expediente:</label>
                        <input type="text" name="expediente" value="<?php echo htmlspecialchars($paciente['expediente'] ?? $paciente['no_expediente'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>CURP:</label>
                        <input type="text" name="curp" value="<?php echo htmlspecialchars($paciente['curp'] ?? ''); ?>" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Teléfono:</label>
                        <input type="text" name="telefono" value="<?php echo htmlspecialchars($paciente['telefono'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Edad:</label>
                        <input type="text" name="edad" value="<?php echo htmlspecialchars($paciente['edad'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Sexo:</label>
                        <select name="sexo">
                            <option value="MASCULINO" <?php echo (strtoupper($paciente['sexo'] ?? '') === 'MASCULINO') ? 'selected' : ''; ?>>MASCULINO</option>
                            <option value="FEMENINO" <?php echo (strtoupper($paciente['sexo'] ?? '') === 'FEMENINO') ? 'selected' : ''; ?>>FEMENINO</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Domicilio:</label>
                        <input type="text" name="domicilio" value="<?php echo htmlspecialchars($paciente['domicilio'] ?? ''); ?>" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group full">
                        <label>Colonia:</label>
                        <input type="text" name="colonia" value="<?php echo htmlspecialchars($paciente['colonia'] ?? ''); ?>" style="text-transform: uppercase;">
                    </div>
                </div>

                <div class="btn-container">
                    <a href="pacientes_archivo.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Cancelar</a>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>