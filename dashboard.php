<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y SEGURIDAD BASE
// ===================================================================
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.html");
    exit();
}

$es_admin = ($_SESSION['rol_id'] == 1);

// ===================================================================
// 2. RECUPERACIÓN DE PERMISOS Y CLÍNICA ASIGNADA DESDE LA SESIÓN
// ===================================================================
$permisos_usuario_raw = $_SESSION['permisos'] ?? [];
$permisos_usuario = array_map('strtolower', $permisos_usuario_raw);

// ===================================================================
// 3. DICCIONARIO CENTRAL DE MÓDULOS DEL SISTEMA
// ===================================================================
$catalogo_modulos = [
    'consulta_express'     => ['url' => 'consulta_express.php', 'icon' => 'fa-stethoscope', 'title' => 'Consulta Express'],
    'citas_medicas'        => ['url' => 'agendar.php', 'icon' => 'fa-calendar-check', 'title' => 'Citas Médicas'],
    'admin_citas'          => ['url' => 'admin_citas.php', 'icon' => 'fa-calendar-days', 'title' => 'Gestión de Citas'],
    'admin_clinicas'       => ['url' => 'admin_clinicas.php', 'icon' => 'fa-hospital', 'title' => 'Gestión de Clínicas'],
    'registro_citas'       => ['url' => 'registro_citas_clinica.php', 'icon' => 'fa-notes-medical', 'title' => 'Registro de Citas'],
    'vacunas'              => ['url' => 'vacunas.php', 'icon' => 'fa-syringe', 'title' => 'Citas de Vacunas'],
    'laboratorio'          => ['url' => 'laboratorio.php', 'icon' => 'fa-flask', 'title' => 'Laboratorio'],
    'rayosx'               => ['url' => 'rayosx.php', 'icon' => 'fa-x-ray', 'title' => 'Rayos X'],
    'ultrasonido'          => ['url' => 'ultrasonido.php', 'icon' => 'fa-satellite-dish', 'title' => 'Ultrasonido'],
    'farmacia'             => ['url' => 'farmacia.php', 'icon' => 'fa-pills', 'title' => 'Farmacia'],
    'ingresos'             => ['url' => 'ingresos.php', 'icon' => 'fa-bed-pulse', 'title' => 'Ingresos'],
    'citas_clinica'        => ['url' => 'citas_clinica.php', 'icon' => 'fa-hospital-user', 'title' => 'Citas de Clínica'],
    'almacen'              => ['url' => 'almacen.php', 'icon' => 'fa-boxes-stacked', 'title' => 'Almacén'],
    'archivo'              => ['url' => 'archivo.php', 'icon' => 'fa-folder-open', 'title' => 'Archivo'],
    'trabajo_social'       => ['url' => 'trabajo_social.php', 'icon' => 'fa-handshake-angle', 'title' => 'Trabajo Social'],
    'jefatura_enfermeria'  => ['url' => 'jefatura_enfermeria.php', 'icon' => 'fa-user-nurse', 'title' => 'Jefatura de Enfermería'],
    'central_enfermeria'   => ['url' => 'central_enfermeria.php', 'icon' => 'fa-notes-medical', 'title' => 'Central de Enfermería']
    ];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Panel Principal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --texto-oscuro: #1c252e; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar .brand { font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 12px; }
        .navbar .brand img.hospital-logo { max-height: 45px; width: auto; object-fit: contain; }
        .btn-logout { background-color: var(--imss-dorado); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; text-decoration: none; font-weight: bold; }
        .container { max-width: 1400px; margin: 40px auto; padding: 0 20px; }
        .modules-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .module-card { background: white; border-radius: 12px; padding: 25px 15px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-bottom: 5px solid var(--imss-verde); text-decoration: none; color: inherit; transition: transform 0.2s; display: block; }
        .module-card:hover { transform: translateY(-5px); }
        .module-card i { font-size: 45px; color: var(--imss-verde); margin-bottom: 15px; }
        .module-card h3 { margin: 0; font-size: 18px; color: var(--texto-oscuro); }
        .admin-card { border-bottom: 5px solid var(--imss-dorado); grid-column: 1 / -1; max-width: 400px; margin: 20px auto 0 auto; }
        .admin-card i { color: var(--imss-dorado); font-size: 50px; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="brand">
            <img src="logo_hospitalbc.png" alt="Logo Hospital General de Huimanguillo" class="hospital-logo" onerror="this.style.display='none'">
        </div>
        <div>
            <span><i class="fa-solid fa-user-doctor"></i> Hola, <strong><?php echo htmlspecialchars($_SESSION['nombre_usuario'] ?? 'Usuario'); ?></strong></span>
            <a href="index.html" class="btn-logout" style="margin-left: 15px;"><i class="fa-solid fa-power-off"></i> Salir</a>
        </div>
    </div>

    <div class="container">
        <h2 style="color: var(--texto-oscuro); margin-bottom: 25px;">Módulos Asignados:</h2>
        
        <div class="modules-grid">
            <?php
            // ===================================================================
            // 4. MOTOR DE RENDERIZADO DINÁMICO
            // ===================================================================
            foreach ($catalogo_modulos as $clave_modulo => $datos) {
                $tiene_permiso = $es_admin || 
                               in_array(strtolower($clave_modulo), $permisos_usuario) || 
                               in_array(strtolower($datos['title']), $permisos_usuario) ||
                               ($clave_modulo === 'ingresos' && (in_array('hospitalizacion', $permisos_usuario) || in_array('urgencias', $permisos_usuario)));

                if ($tiene_permiso) {
                    echo '
                    <a href="' . $datos['url'] . '" class="module-card">
                        <i class="fa-solid ' . $datos['icon'] . '"></i>
                        <h3>' . $datos['title'] . '</h3>
                    </a>';
                }
            }
            ?>
        </div>

        <?php 
        // ===================================================================
        // 5. BOTÓN DE ADMINISTRACIÓN (EXCLUSIVO SUPERADMIN)
        // ===================================================================
        if ($es_admin): 
        ?>
        <div style="text-align: center; margin-top: 40px;">
            <a href="admin.php" class="module-card admin-card">
                <i class="fa-solid fa-shield-halved"></i>
                <h2>Panel de Administración</h2>
                <p style="color: #637381; font-size: 13px;">Generación de Recetas, Órdenes y Referencias Rápidas</p>
            </a>
        </div>
        <?php endif; ?>

    </div>
</body>
</html>