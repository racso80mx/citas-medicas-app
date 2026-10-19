<?php
// ===================================================================
// 1. CONFIGURACIÓN DE ERRORES Y SEGURIDAD
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.html");
    exit();
}

$es_admin = ($_SESSION['rol_id'] == 1);
$usuario_actual_id = $_SESSION['usuario_id'];

require_once 'conexion.php';

// ===================================================================
// 2. PROCESAMIENTO DE FORMULARIOS (CREAR Y EDITAR)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // --- ACCIÓN: CREAR NUEVO USUARIO (Solo Admin) ---
    if ($accion == 'nuevo_usuario' && $es_admin) {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? ''); 
        $nombre   = trim($_POST['nombre_completo'] ?? '');
        $correo   = trim($_POST['correo'] ?? '');
        $id_rol   = intval($_POST['id_rol'] ?? 2);
        $clinica  = trim($_POST['clave_clinica'] ?? '');

        $permisos_array = isset($_POST['permisos']) ? $_POST['permisos'] : [];
        $permisos_json  = json_encode($permisos_array);

        $areas_array = isset($_POST['areas_autorizadas']) ? $_POST['areas_autorizadas'] : [];
        $areas_json  = json_encode($areas_array);

        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS areas_autorizadas JSONB DEFAULT '[]'");

            $stmt = $pdo->prepare("
                INSERT INTO usuarios (username, password_hash, nombre_completo, correo, id_rol, clave_clinica, permisos, areas_autorizadas, activo) 
                VALUES (:usr, :pwd, :nom, :mail, :rol, :clin, :perm::jsonb, :areas::jsonb, TRUE)
            ");
            $stmt->execute([
                'usr'   => $username,
                'pwd'   => password_hash($password, PASSWORD_DEFAULT),
                'nom'   => $nombre,
                'mail'  => !empty($correo) ? $correo : null,
                'rol'   => $id_rol,
                'clin'  => !empty($clinica) ? $clinica : null,
                'perm'  => $permisos_json,
                'areas' => $areas_json
            ]);
            header("Location: admin_usuarios.php");
            exit();
        } catch (PDOException $e) {
            $error_msj = urlencode($e->getMessage());
            header("Location: admin_usuarios.php?error=" . $error_msj);
            exit();
        }
    }

    // --- ACCIÓN: EDITAR USUARIO EXISTENTE ---
    if ($accion == 'editar_usuario') {
        $id_usuario = intval($_POST['id_usuario'] ?? 0);

        // Si no es admin, un usuario solo puede editarse a sí mismo
        if (!$es_admin && $id_usuario != $usuario_actual_id) {
            header("Location: admin_usuarios.php");
            exit();
        }

        $username   = trim($_POST['username'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $nombre     = trim($_POST['nombre_completo'] ?? '');
        $correo     = trim($_POST['correo'] ?? '');
        
        // Los usuarios normales no pueden cambiar su propio rol o clínica a menos que sean admin
        $id_rol     = $es_admin ? intval($_POST['id_rol'] ?? 2) : 2;
        $clinica    = $es_admin ? trim($_POST['clave_clinica'] ?? '') : ($_POST['clave_clinica_oculta'] ?? '');

        $permisos_array = $es_admin ? (isset($_POST['permisos']) ? $_POST['permisos'] : []) : [];
        $permisos_json  = json_encode($permisos_array);

        $areas_array = isset($_POST['areas_autorizadas']) ? $_POST['areas_autorizadas'] : [];
        $areas_json  = json_encode($areas_array);

        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS areas_autorizadas JSONB DEFAULT '[]'");

            if (!empty($password)) {
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET username = :usr, password_hash = :pwd, nombre_completo = :nom, correo = :mail, id_rol = :rol, clave_clinica = :clin, permisos = :perm::jsonb, areas_autorizadas = :areas::jsonb 
                    WHERE id_usuario = :id
                ");
                $stmt->execute([
                    'usr'   => $username,
                    'pwd'   => password_hash($password, PASSWORD_DEFAULT),
                    'nom'   => $nombre,
                    'mail'  => !empty($correo) ? $correo : null,
                    'rol'   => $id_rol,
                    'clin'  => !empty($clinica) ? $clinica : null,
                    'perm'  => $permisos_json,
                    'areas' => $areas_json,
                    'id'    => $id_usuario
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET username = :usr, nombre_completo = :nom, correo = :mail, id_rol = :rol, clave_clinica = :clin, permisos = :perm::jsonb, areas_autorizadas = :areas::jsonb 
                    WHERE id_usuario = :id
                ");
                $stmt->execute([
                    'usr'   => $username,
                    'nom'   => $nombre,
                    'mail'  => !empty($correo) ? $correo : null,
                    'rol'   => $id_rol,
                    'clin'  => !empty($clinica) ? $clinica : null,
                    'perm'  => $permisos_json,
                    'areas' => $areas_json,
                    'id'    => $id_usuario
                ]);
            }
            header("Location: admin_usuarios.php");
            exit();
        } catch (PDOException $e) {
            $error_msj = urlencode($e->getMessage());
            header("Location: admin_usuarios.php?error=" . $error_msj);
            exit();
        }
    }
}

// ===================================================================
// 3. ACCIONES GET: CAMBIAR ESTADO Y ELIMINAR (Solo Admin)
// ===================================================================
if (isset($_GET['toggle']) && $es_admin) {
    $id = intval($_GET['toggle']);
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET activo = NOT activo WHERE id_usuario = :id");
        $stmt->execute(['id' => $id]);
    } catch (Exception $e) {}
    header("Location: admin_usuarios.php");
    exit();
}

if (isset($_GET['eliminar']) && $es_admin) {
    $id = intval($_GET['eliminar']);
    if ($id != $usuario_actual_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id_usuario = :id");
            $stmt->execute(['id' => $id]);
        } catch (Exception $e) {}
    }
    header("Location: admin_usuarios.php");
    exit();
}

// ===================================================================
// 4. CONSULTAS PARA LA VISTA
// ===================================================================
$clinicas = [];
try {
    $stmtClinicas = $pdo->query("SELECT * FROM clinicas ORDER BY nombre_clinica ASC");
    $clinicas = $stmtClinicas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clinicas = [];
}

$areas_hospital_catalogo = [];
try {
    $stmt_areas_cat = $pdo->query("SELECT DISTINCT nombre FROM areas_hospital WHERE estatus IS NULL OR UPPER(TRIM(estatus)) = 'ACTIVO' ORDER BY nombre ASC");
    $areas_hospital_catalogo = $stmt_areas_cat->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

if (empty($areas_hospital_catalogo)) {
    $areas_hospital_catalogo = ['Triage', 'Sala de Choque', 'Sala de Observación', 'UCI (Cuidados Intensivos)', 'UCIN (Neonatales)', 'Piso de Hospitalización', 'Quirófano'];
}

// Si es admin ve todos; si es usuario regular, SOLO se ve a sí mismo
$usuarios = [];
try {
    if ($es_admin) {
        $stmtUsuarios = $pdo->query("SELECT * FROM usuarios ORDER BY id_usuario DESC");
        $usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtUsuarios = $pdo->prepare("SELECT * FROM usuarios WHERE id_usuario = :id");
        $stmtUsuarios->execute(['id' => $usuario_actual_id]);
        $usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $usuarios = [];
}

$catalogo_modulos = [
    'consulta_express'  => 'Consulta Express',
    'citas_medicas'     => 'Citas Médicas',
    'admin_citas'       => 'Gestión de Citas',
    'admin_clinicas'    => 'Gestión de Clínicas',
    'vacunas'           => 'Vacunas',
    'laboratorio'       => 'Laboratorio',
    'rayosx'            => 'Rayos X',
    'ultrasonido'       => 'Ultrasonido',
    'psicologia'        => 'Psicología', 
    'farmacia'          => 'Farmacia',
    'hospitalizacion'   => 'Ingresos',
    'registro_citas'    => 'Registro de Citas',
    'citas_clinica'     => 'Citas de Clínica',
    'almacen'           => 'Almacén',
    'archivo'           => 'Archivo',
    'trabajo_social'    => 'Trabajo Social',
    'jefatura_enfermeria' => 'Jefatura de Enfermería'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Gestión de Usuarios</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; 
            --imss-dorado: #9f2241; 
            --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8;
            --excel-verde: #217346;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }

        .navbar { background-color: var(--imss-dorado); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }

        .container { max-width: 1300px; margin: 25px auto; padding: 0 20px; }

        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }

        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-outline { background-color: white; color: #1c252e; border: 1px solid var(--borde); }
        .btn:hover { opacity: 0.9; }

        .import-panel-toggle { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: bold; color: var(--imss-verde); cursor: pointer; }
        .import-box { display: none; background: #f8f9fa; padding: 10px 15px; border-radius: 6px; border: 1px dashed var(--imss-verde); width: 100%; align-items: center; gap: 15px; flex-wrap: wrap; box-sizing: border-box; margin-top: 5px; }
        .import-box.show { display: flex; }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 15px; border-bottom: 2px solid var(--borde); color: #637381; }
        td { padding: 12px 15px; border-bottom: 1px solid var(--borde); }
        tr:hover { background-color: #f8f9fa; }

        .action-btn { background: none; border: none; cursor: pointer; font-size: 15px; margin-right: 8px; color: #637381; }
        .action-btn.edit:hover { color: var(--imss-verde); }
        .action-btn.status:hover { color: #f57c00; }
        .action-btn.delete:hover { color: var(--imss-dorado); }

        .badge { background: #e4f8dd; color: var(--imss-verde); padding: 3px 6px; border-radius: 4px; font-weight: bold; font-size: 11px; margin: 2px; display: inline-block; }
        .badge.admin { background: #fdf3f5; color: var(--imss-dorado); }
        .badge.inactive { background: #f8d7da; color: #721c24; }
        .badge.area { background: #e0f2fe; color: #0369a1; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 25px; border-radius: 8px; width: 100%; max-width: 650px; max-height: 90vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 13px; }
        .form-group input[type="text"], .form-group input[type="password"], .form-group input[type="email"], .form-group select { width: 100%; padding: 8px; border: 1px solid var(--borde); border-radius: 4px; box-sizing: border-box; }
        .grid-checkboxes { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #f9fafb; padding: 15px; border: 1px solid var(--borde); border-radius: 4px; max-height: 180px; overflow-y: auto; }
        .grid-checkboxes label { font-weight: normal; font-size: 13px; display: flex; align-items: center; gap: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-users-gear"></i> <strong>Gestión de Usuarios y Accesos</strong></div>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver al Panel</a>
    </div>

    <div class="container">
        <?php if (isset($_GET['error'])): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> Error en la BD: <?php echo htmlspecialchars(urldecode($_GET['error'])); ?>
            </div>
        <?php endif; ?>

        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($es_admin): ?>
                    <label class="import-panel-toggle">
                        <input type="checkbox" id="toggleImport" onchange="toggleImportBox()" style="cursor: pointer;"> 
                        <i class="fa-solid fa-file-excel"></i> Mostrar importación
                    </label>
                    <button class="btn btn-primary" onclick="abrirModalNuevo()">
                        <i class="fa-solid fa-plus"></i> Nuevo Usuario
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Campo de búsqueda instantánea -->
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-magnifying-glass" style="color: #637381;"></i>
                    <input type="text" id="inputBusqueda" placeholder="Buscar por usuario, nombre..." onkeyup="filtrarUsuarios()" style="padding: 8px 12px; border: 1px solid var(--borde); border-radius: 6px; width: 260px; font-size: 13px;">
                </div>
            </div>

            <?php if ($es_admin): ?>
            <div id="importBox" class="import-box">
                <a href="descargar_plantilla_usuarios.php" class="btn btn-excel">
                    <i class="fa-solid fa-file-arrow-down"></i> Descargar Plantilla Usuarios
                </a>
                <form action="importar_usuarios.php" method="POST" enctype="multipart/form-data" style="display: inline-flex; gap: 8px; align-items: center; margin: 0;">
                    <input type="file" name="archivo_excel" accept=".xlsx, .xls, .csv" required style="font-size: 12px;">
                    <button type="submit" class="btn btn-excel">
                        <i class="fa-solid fa-upload"></i> Subir a BD
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <table id="tablaUsuarios">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario / Matrícula</th>
                        <th>Nombre Completo</th>
                        <th>Rol</th>
                        <th>Clínica Asignada</th>
                        <th>Módulos Permitidos</th>
                        <th>Áreas Autorizadas (Hospital)</th>
                        <th>Estatus</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($usuarios) > 0): ?>
                        <?php foreach ($usuarios as $u): 
                            $id_u   = $u['id_usuario'];
                            $usr_u  = $u['username'];
                            $nom_u  = $u['nombre_completo'];
                            $rol_u  = $u['id_rol'];
                            $clin_u = $u['clave_clinica'] ?? '';
                            $act_u  = isset($u['activo']) ? $u['activo'] : true;
                        ?>
                            <tr>
                                <td><?php echo $id_u; ?></td>
                                <td><strong><?php echo htmlspecialchars($usr_u); ?></strong></td>
                                <td><?php echo htmlspecialchars($nom_u); ?></td>
                                <td>
                                    <?php if ($rol_u == 1): ?>
                                        <span class="badge admin">SuperAdmin</span>
                                    <?php else: ?>
                                        <span class="badge">Usuario</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($clin_u ?: 'N/A'); ?></td>
                                <td style="max-width: 200px; white-space: normal;">
                                    <?php 
                                    $permisos = json_decode($u['permisos'] ?? '[]', true);
                                    if ($rol_u == 1) {
                                        echo '<span class="badge admin">Acceso Total</span>';
                                    } elseif (is_array($permisos) && count($permisos) > 0) {
                                        foreach ($permisos as $p) {
                                            echo '<span class="badge">' . ($catalogo_modulos[$p] ?? $p) . '</span> ';
                                        }
                                    } else {
                                        echo '<span style="color: #999; font-size: 12px;">Sin accesos</span>';
                                    }
                                    ?>
                                </td>
                                <td style="max-width: 200px; white-space: normal;">
                                    <?php 
                                    $areas_aut = json_decode($u['areas_autorizadas'] ?? '[]', true);
                                    if ($rol_u == 1) {
                                        echo '<span class="badge admin">Todas las Áreas</span>';
                                    } elseif (is_array($areas_aut) && count($areas_aut) > 0) {
                                        foreach ($areas_aut as $ar_aut) {
                                            echo '<span class="badge area">' . htmlspecialchars($ar_aut) . '</span> ';
                                        }
                                    } else {
                                        echo '<span style="color: #999; font-size: 12px;">Todas (Sin restricción)</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($act_u): ?>
                                        <span class="badge" style="background: #e4f8dd; color: var(--imss-verde);">Activo</span>
                                    <?php else: ?>
                                        <span class="badge inactive">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="action-btn edit" title="Editar" onclick='abrirModalEditar(<?php echo json_encode($u); ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
                                    <?php if ($es_admin): ?>
                                        <a href="admin_usuarios.php?toggle=<?php echo $id_u; ?>" class="action-btn status" title="Cambiar Estatus" onclick="return confirm('¿Deseas cambiar el estatus de este usuario?');"><i class="fa-solid fa-power-off"></i></a>
                                        <a href="admin_usuarios.php?eliminar=<?php echo $id_u; ?>" class="action-btn delete" title="Eliminar Usuario" onclick="return confirm('¿Estás seguro de eliminar permanentemente a este usuario?');"><i class="fa-solid fa-trash"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #637381; padding: 20px;">No se encontró información del usuario.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL ÚNICO (CREAR / EDITAR) -->
    <div class="modal" id="modalUsuario">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid var(--borde); padding-bottom: 10px;">
                <h3 id="modalTitulo" style="margin: 0; color: #1c252e;">Registrar Usuario</h3>
                <button type="button" onclick="cerrarModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #637381;">&times;</button>
            </div>

            <form method="POST">
                <input type="hidden" name="accion" id="formAccion" value="nuevo_usuario">
                <input type="hidden" name="id_usuario" id="formIdUsuario">
                <input type="hidden" name="clave_clinica_oculta" id="formClinicaOculta">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Usuario o Matrícula</label>
                        <input type="text" name="username" id="formUsername" required>
                    </div>
                    <div class="form-group">
                        <label id="labelPassword">Contraseña Provisional</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <input type="password" name="password" id="formPassword" style="padding-right: 35px; width: 100%;">
                            <i class="fa-solid fa-eye" id="togglePassword" style="position: absolute; right: 12px; cursor: pointer; color: #637381;" title="Mostrar / Ocultar contraseña"></i>
                        </div>
                        <small id="pwdHelp" style="color: #637381; display:none;">Déjalo en blanco si no deseas cambiarla.</small>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Nombre Completo</label>
                        <input type="text" name="nombre_completo" id="formNombre" required>
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico</label>
                        <input type="email" name="correo" id="formCorreo">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;" id="adminCamposRolClinica">
                    <div class="form-group">
                        <label>Nivel de Acceso</label>
                        <select name="id_rol" id="formRol" required>
                            <option value="2">Personal Médico / Operativo</option>
                            <option value="1">SuperAdministrador</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Clínica Asignada</label>
                        <select name="clave_clinica" id="formClinica">
                            <option value="">-- Sin clínica específica --</option>
                            <?php foreach ($clinicas as $c): 
                                $c_nombre = $c['nombre_clinica'] ?? '';
                            ?>
                                <option value="<?php echo htmlspecialchars($c_nombre); ?>">
                                    <?php echo htmlspecialchars($c_nombre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="adminSeccionPermisos">
                    <label style="color: var(--imss-dorado);"><i class="fa-solid fa-check-double"></i> Módulos Autorizados</label>
                    <div class="grid-checkboxes">
                        <?php foreach ($catalogo_modulos as $clave => $nombre): ?>
                            <label>
                                <input type="checkbox" name="permisos[]" value="<?php echo htmlspecialchars($clave); ?>" class="check-permiso"> 
                                <?php echo htmlspecialchars($nombre); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label style="color: #0369a1;"><i class="fa-solid fa-bed-pulse"></i> Áreas de Hospitalización Autorizadas <small style="font-weight:normal; color:#637381;">(Si no selecciona ninguna, tendrá acceso a todas)</small></label>
                    <div class="grid-checkboxes">
                        <?php foreach ($areas_hospital_catalogo as $ar_cat): ?>
                            <label>
                                <input type="checkbox" name="areas_autorizadas[]" value="<?php echo htmlspecialchars($ar_cat); ?>" class="check-area-aut"> 
                                <?php echo htmlspecialchars($ar_cat); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const esAdminSistema = <?php echo $es_admin ? 'true' : 'false'; ?>;

        const togglePassword = document.querySelector('#togglePassword');
        const passwordInput = document.querySelector('#formPassword');

        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        }

        function toggleImportBox() {
            const box = document.getElementById('importBox');
            const checkbox = document.getElementById('toggleImport');
            if (checkbox.checked) {
                box.classList.add('show');
            } else {
                box.classList.remove('show');
            }
        }

        // Función de filtrado en tiempo real para la tabla de usuarios
        function filtrarUsuarios() {
            let input = document.getElementById('inputBusqueda').value.toLowerCase();
            let filas = document.querySelectorAll('#tablaUsuarios tbody tr');
            
            filas.forEach(fila => {
                let textoFila = fila.textContent.toLowerCase();
                if (textoFila.includes(input)) {
                    fila.style.display = '';
                } else {
                    fila.style.display = 'none';
                }
            });
        }

        function abrirModalNuevo() {
            if (!esAdminSistema) return;
            document.getElementById('modalTitulo').innerText = 'Registrar Usuario y Accesos';
            document.getElementById('formAccion').value = 'nuevo_usuario';
            document.getElementById('formIdUsuario').value = '';
            document.getElementById('formUsername').value = '';
            document.getElementById('formPassword').value = '';
            document.getElementById('formPassword').required = true;
            document.getElementById('labelPassword').innerText = 'Contraseña Provisional';
            document.getElementById('pwdHelp').style.display = 'none';
            document.getElementById('formNombre').value = '';
            document.getElementById('formCorreo').value = '';
            document.getElementById('formRol').value = '2';
            document.getElementById('formClinica').value = '';
            document.getElementById('formClinicaOculta').value = '';

            document.getElementById('adminCamposRolClinica').style.display = 'grid';
            document.getElementById('adminSeccionPermisos').style.display = 'block';

            document.querySelectorAll('.check-permiso').forEach(chk => chk.checked = false);
            document.querySelectorAll('.check-area-aut').forEach(chk => chk.checked = false);
            document.getElementById('modalUsuario').classList.add('active');
        }

        function abrirModalEditar(usuario) {
            document.getElementById('modalTitulo').innerText = 'Editar Usuario';
            document.getElementById('formAccion').value = 'editar_usuario';
            document.getElementById('formIdUsuario').value = usuario.id_usuario || '';
            document.getElementById('formUsername').value = usuario.username || '';
            document.getElementById('formPassword').value = '';
            document.getElementById('formPassword').required = false;
            document.getElementById('labelPassword').innerText = 'Nueva Contraseña (Opcional)';
            document.getElementById('pwdHelp').style.display = 'block';
            document.getElementById('formNombre').value = usuario.nombre_completo || '';
            document.getElementById('formCorreo').value = usuario.correo || '';
            document.getElementById('formRol').value = usuario.id_rol || 2;
            document.getElementById('formClinica').value = usuario.clave_clinica || '';
            document.getElementById('formClinicaOculta').value = usuario.clave_clinica || '';

            if (!esAdminSistema) {
                document.getElementById('adminCamposRolClinica').style.display = 'none';
                document.getElementById('adminSeccionPermisos').style.display = 'none';
            } else {
                document.getElementById('adminCamposRolClinica').style.display = 'grid';
                document.getElementById('adminSeccionPermisos').style.display = 'block';
            }

            let permisosGuardados = [];
            try {
                permisosGuardados = JSON.parse(usuario.permisos || '[]');
            } catch(e) {
                permisosGuardados = [];
            }
            document.querySelectorAll('.check-permiso').forEach(chk => {
                chk.checked = permisosGuardados.includes(chk.value);
            });

            let areasGuardadas = [];
            try {
                areasGuardadas = JSON.parse(usuario.areas_autorizadas || '[]');
            } catch(e) {
                areasGuardadas = [];
            }
            document.querySelectorAll('.check-area-aut').forEach(chk => {
                chk.checked = areasGuardadas.includes(chk.value);
            });

            document.getElementById('modalUsuario').classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalUsuario').classList.remove('active');
        }
    </script>
</body>
</html>