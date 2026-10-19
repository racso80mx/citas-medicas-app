<?php
require_once 'conexion.php';
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sin_cedula   = isset($_POST['sin_cedula']) && $_POST['sin_cedula'] === '1';
    $cedula       = $sin_cedula ? 'MEDICO PASANTE' : trim($_POST['cedula_profesional'] ?? '');
    $nombre       = trim($_POST['nombre'] ?? '');
    $apaterno     = trim($_POST['apaterno'] ?? '');
    $amaterno     = trim($_POST['amaterno'] ?? '');
    $especialidad = trim($_POST['especialidad'] ?? 'MEDICINA GENERAL');
    $telefono     = trim($_POST['telefono'] ?? '');
    $correo       = trim($_POST['correo'] ?? '');

    if (!empty($cedula) && !empty($nombre) && !empty($apaterno)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO medicos (cedula_profesional, nombre, apaterno, amaterno, especialidad, telefono, correo, estatus, fecha_registro) VALUES (:ced, :nom, :apat, :amat, :esp, :tel, :corr, 'ACTIVO', NOW())");
            $stmt->execute([
                'ced'  => mb_strtoupper($cedula, 'UTF-8'), 
                'nom'  => mb_strtoupper($nombre, 'UTF-8'), 
                'apat' => mb_strtoupper($apaterno, 'UTF-8'), 
                'amat' => mb_strtoupper($amaterno, 'UTF-8'),
                'esp'  => mb_strtoupper($especialidad, 'UTF-8'), 
                'tel'  => $telefono, 
                'corr' => $correo
            ]);
            $mensaje = "¡Registro exitoso! Ya puede cerrar esta ventana o regresar al sistema.";
        } catch (Exception $e) {
            $error = "Error: La Cédula Profesional ya se encuentra registrada en el sistema.";
        }
    } else {
        $error = "Por favor complete los campos obligatorios.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto-Registro de Médico | IMSS-BIENESTAR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --borde: #dfe3e8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card-registro { background: white; width: 100%; max-width: 600px; border-radius: 10px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-top: 5px solid var(--imss-verde); }
        .h2-title { color: var(--imss-verde); margin-top: 0; font-size: 22px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid var(--borde); padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 10px 12px; font-size: 13px; border: 2px solid var(--borde); border-radius: 6px; box-sizing: border-box; outline: none; }
        .form-control:focus { border-color: var(--imss-verde); }
        .btn-guardar { background-color: var(--imss-verde); color: white; border: none; padding: 12px 20px; font-size: 14px; font-weight: bold; border-radius: 6px; cursor: pointer; width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .btn-guardar:hover { background-color: #004d34; }
        .alert-success { background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold; }
        .alert-error { background: #fdf3f5; color: var(--imss-dorado); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card-registro">
        <h2 class="h2-title"><i class="fa-solid fa-user-doctor"></i> Auto-Registro de Médico Institucional</h2>
        <p style="font-size: 13px; color: #637381; margin-bottom: 20px;">Por favor, ingrese sus datos profesionales para dar alta su perfil en el censo hospitalario.</p>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="registro_medico.php" method="POST">
            <div class="form-group" style="background: #f8f9fa; padding: 12px; border-radius: 6px; border: 1px solid var(--borde);">
                <label style="color: var(--imss-dorado); cursor: pointer; display: flex; align-items: center; gap: 8px; margin: 0;">
                    <input type="checkbox" name="sin_cedula" id="sinCedula" value="1" onchange="togglePasanteReg()" style="width: 16px; height: 16px; cursor: pointer;">
                    <strong>Sin Cédula Profesional (Médico Pasante)</strong>
                </label>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label>Cédula Profesional *</label>
                    <input type="text" name="cedula_profesional" id="regCedula" class="form-control" required style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <label>Especialidad</label>
                    <input type="text" name="especialidad" class="form-control" value="MEDICINA GENERAL" style="text-transform: uppercase;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label>Nombre(s) *</label>
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

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label>Teléfono de Contacto</label>
                    <input type="text" name="telefono" class="form-control">
                </div>
                <div class="form-group">
                    <label>Correo Electrónico</label>
                    <input type="email" name="correo" class="form-control" style="text-transform: none;">
                </div>
            </div>

            <button type="submit" class="btn-guardar" style="margin-top: 15px;">
                <i class="fa-solid fa-paper-plane"></i> Registrarse en el Sistema
            </button>
        </form>
    </div>

    <script>
        function togglePasanteReg() {
            const chk = document.getElementById('sinCedula');
            const inputCedula = document.getElementById('regCedula');
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
    </script>
</body>
</html>