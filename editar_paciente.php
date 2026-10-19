<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: archivo.php");
    exit();
}

$mensaje = '';
$error = '';

// ===================================================================
// 2. PROCESAR ACTUALIZACIÓN DE DATOS (POST)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expediente       = trim($_POST['expediente'] ?? '');
    $nombre           = trim($_POST['nombre'] ?? '');
    $apaterno         = trim($_POST['apaterno'] ?? '');
    $amaterno         = trim($_POST['amaterno'] ?? '');
    $curp             = strtoupper(trim($_POST['curp'] ?? ''));
    $fnacimiento      = trim($_POST['fnacimiento'] ?? '');
    $year_nacimiento  = trim($_POST['year_nacimiento'] ?? '');
    $edad             = !empty($_POST['edad']) ? (int)$_POST['edad'] : null;
    $edad_actual      = !empty($_POST['edad_actual']) ? (int)$_POST['edad_actual'] : null;
    $sexo             = trim($_POST['sexo'] ?? '');
    $estado           = trim($_POST['estado'] ?? '');
    $domicilio        = trim($_POST['domicilio'] ?? '');
    $colonia          = trim($_POST['colonia'] ?? '');
    $nombre_padre     = trim($_POST['nombre_padre'] ?? '');
    $nombre_madre     = trim($_POST['nombre_madre'] ?? '');
    $edad_padre       = trim($_POST['edad_padre'] ?? '');
    $edad_madre       = trim($_POST['edad_madre'] ?? '');
    $fecha_apertura   = trim($_POST['fecha_apertura'] ?? '');
    $seguro_popular   = trim($_POST['seguro_popular'] ?? '');
    $telefono         = trim($_POST['telefono'] ?? '');
    $derechohabiencia = trim($_POST['derechohabiencia'] ?? '');
    $estatus          = trim($_POST['estatus'] ?? 'Vigente');

    if (empty($telefono) || !preg_match('/^\d{10}$/', $telefono)) {
        $error = "El teléfono es obligatorio y debe contener exactamente 10 dígitos numéricos.";
    } elseif (empty($curp)) {
        $error = "El CURP es obligatorio.";
    } else {
        try {
            $nombre_completo  = trim("$nombre $apaterno $amaterno");
            $nombre_apellidos = trim("$nombre $apaterno $amaterno");
            if (empty($year_nacimiento) && !empty($fnacimiento)) {
                $year_nacimiento = substr($fnacimiento, 0, 4);
            }

            $sql_update = "UPDATE pacientes SET 
                            expediente = :expediente, 
                            nombre = :nombre, 
                            apaterno = :apaterno, 
                            amaterno = :amaterno, 
                            curp = :curp, 
                            fnacimiento = :fnacimiento,
                            year_nacimiento = :year_nacimiento,
                            edad = :edad, 
                            edad_actual = :edad_actual,
                            sexo = :sexo, 
                            estado = :estado,
                            domicilio = :domicilio,
                            colonia = :colonia,
                            nombre_padre = :nombre_padre,
                            nombre_madre = :nombre_madre,
                            edad_padre = :edad_padre,
                            edad_madre = :edad_madre,
                            fecha_apertura = :fecha_apertura,
                            seguro_popular = :seguro_popular,
                            telefono = :telefono, 
                            derechohabiencia = :derechohabiencia,
                            nombre_completo = :nombre_completo,
                            nombre_apellidos = :nombre_apellidos,
                            estatus = :estatus 
                           WHERE id_paciente = :id";
            
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([
                'expediente'       => $expediente,
                'nombre'           => $nombre,
                'apaterno'         => $apaterno,
                'amaterno'         => $amaterno,
                'curp'             => $curp,
                'fnacimiento'      => $fnacimiento,
                'year_nacimiento'  => $year_nacimiento,
                'edad'             => $edad,
                'edad_actual'      => $edad_actual,
                'sexo'             => $sexo,
                'estado'           => $estado,
                'domicilio'        => $domicilio,
                'colonia'          => $colonia,
                'nombre_padre'     => $nombre_padre,
                'nombre_madre'     => $nombre_madre,
                'edad_padre'       => $edad_padre,
                'edad_madre'       => $edad_madre,
                'fecha_apertura'   => $fecha_apertura,
                'seguro_popular'   => $seguro_popular,
                'telefono'         => $telefono,
                'derechohabiencia' => $derechohabiencia,
                'nombre_completo'  => $nombre_completo,
                'nombre_apellidos' => $nombre_apellidos,
                'estatus'          => $estatus,
                'id'               => $id
            ]);

            $mensaje = "¡Paciente actualizado correctamente!";
        } catch (Exception $e) {
            $error = "Error al actualizar: " . $e->getMessage();
        }
    }
}

// ===================================================================
// 3. OBTENER DATOS ACTUALES DEL PACIENTE
// ===================================================================
try {
    $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
    $stmt->execute(['id' => $id]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        header("Location: archivo.php");
        exit();
    }

    $lista_derechohabiencias = [];
    try {
        $stmt_der = $pdo->query("SELECT nombre_derechohabiencia FROM derechohabiencia ORDER BY nombre_derechohabiencia ASC");
        $lista_derechohabiencias = $stmt_der->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $ex) {
        // Si la tabla derechohabiencia no existe todavía, se mantiene vacío sin romper la página
    }

} catch (Exception $e) {
    header("Location: archivo.php");
    exit();
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
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --btn-azul: #1976d2; 
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 950px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-top: 4px solid var(--imss-verde); }
        h2 { margin-top: 0; color: var(--imss-verde); display: flex; align-items: center; gap: 10px; font-size: 20px; }
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .form-group { margin-bottom: 12px; }
        label { display: block; margin-bottom: 3px; font-weight: bold; font-size: 11px; color: #637381; }
        input, select { width: 100%; padding: 6px 8px; border: 1px solid var(--borde); border-radius: 4px; font-size: 12px; box-sizing: border-box; outline: none; height: 30px; }
        input:focus, select:focus { border-color: var(--imss-verde); }
        .btn-container { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--borde); }
        .btn { padding: 7px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-secondary { background-color: #637381; }
        .alert-success { background: #e8f5e9; color: #2e7d32; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; border: 1px solid #ffcdd2; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-user-pen"></i> <strong>Editar Información del Paciente</strong></div>
        <a href="admin_pacientes.php"><i class="fa-solid fa-arrow-left"></i> Volver al Módulo de Paciente</a>
    </div>

    <div class="container">
        <div class="card">
            <h2><i class="fa-solid fa-pen-to-square"></i> Modificar Datos Completos del Paciente</h2>

            <?php if (!empty($mensaje)): ?>
                <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?= $mensaje ?></div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= $error ?></div>
            <?php endif; ?>

            <form action="editar_paciente.php?id=<?= $id ?>" method="POST">
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Expediente:</label>
                        <input type="text" name="expediente" value="<?= htmlspecialchars($paciente['expediente'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>CURP:</label>
                        <input type="text" name="curp" value="<?= htmlspecialchars(trim($paciente['curp'] ?? '')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Nombre(s):</label>
                        <input type="text" name="nombre" value="<?= htmlspecialchars($paciente['nombre'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Apellido Paterno:</label>
                        <input type="text" name="apaterno" value="<?= htmlspecialchars($paciente['apaterno'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Apellido Materno:</label>
                        <input type="text" name="amaterno" value="<?= htmlspecialchars($paciente['amaterno'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Fecha de Nacimiento:</label>
                        <input type="date" name="fnacimiento" value="<?= htmlspecialchars($paciente['fnacimiento'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Año de Nacimiento:</label>
                        <input type="text" name="year_nacimiento" value="<?= htmlspecialchars($paciente['year_nacimiento'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Edad:</label>
                        <input type="number" name="edad" value="<?= htmlspecialchars($paciente['edad'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Edad Actual:</label>
                        <input type="number" name="edad_actual" value="<?= htmlspecialchars($paciente['edad_actual'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Sexo:</label>
                        <select name="sexo">
                            <option value="HOMBRE" <?= (strtoupper($paciente['sexo'] ?? '') === 'HOMBRE' || strtoupper($paciente['sexo'] ?? '') === 'MASCULINO') ? 'selected' : '' ?>>HOMBRE</option>
                            <option value="MUJER" <?= (strtoupper($paciente['sexo'] ?? '') === 'MUJER' || strtoupper($paciente['sexo'] ?? '') === 'FEMENINO') ? 'selected' : '' ?>>MUJER</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Estado de Nacimiento:</label>
                        <input type="text" name="estado" value="<?= htmlspecialchars($paciente['estado'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Teléfono (10 dígitos):</label>
                        <input type="text" name="telefono" maxlength="10" pattern="\d{10}" value="<?= htmlspecialchars($paciente['telefono'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Domicilio:</label>
                        <input type="text" name="domicilio" value="<?= htmlspecialchars($paciente['domicilio'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Colonia:</label>
                        <input type="text" name="colonia" value="<?= htmlspecialchars($paciente['colonia'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Nombre del Padre:</label>
                        <input type="text" name="nombre_padre" value="<?= htmlspecialchars($paciente['nombre_padre'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Nombre de la Madre:</label>
                        <input type="text" name="nombre_madre" value="<?= htmlspecialchars($paciente['nombre_madre'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Edad del Padre:</label>
                        <input type="text" name="edad_padre" value="<?= htmlspecialchars($paciente['edad_padre'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Edad de la Madre:</label>
                        <input type="text" name="edad_madre" value="<?= htmlspecialchars($paciente['edad_madre'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Fecha de Apertura:</label>
                        <input type="date" name="fecha_apertura" value="<?= htmlspecialchars($paciente['fecha_apertura'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Seguro Popular:</label>
                        <input type="text" name="seguro_popular" value="<?= htmlspecialchars($paciente['seguro_popular'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Derechohabiencia:</label>
                        <select name="derechohabiencia">
                            <option value="">Seleccione opción...</option>
                            <?php foreach ($lista_derechohabiencias as $der): ?>
                                <option value="<?= htmlspecialchars($der) ?>" <?= (($paciente['derechohabiencia'] ?? '') === $der) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($der) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Estatus:</label>
                        <select name="estatus" required>
                            <option value="Vigente" <?= ($paciente['estatus'] ?? '') === 'Vigente' ? 'selected' : '' ?>>Vigente</option>
                            <option value="Baja" <?= ($paciente['estatus'] ?? '') === 'Baja' ? 'selected' : '' ?>>Baja</option>
                        </select>
                    </div>
                </div>

                <div class="btn-container">
                    <a href="archivo.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Cancelar</a>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>