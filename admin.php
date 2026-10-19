<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y DOBLE CANDADO DE SEGURIDAD ESTRICTA
// ===================================================================
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Administración General</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-dorado: #9f2241; --imss-verde: #006847; --fondo: #f4f6f8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--fondo); }
        .navbar { background-color: var(--imss-dorado); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .grid-admin { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-top: 20px; }
        .admin-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-left: 5px solid var(--imss-dorado); cursor: pointer; transition: background 0.2s, transform 0.2s; }
        .admin-card:hover { background: #fdf3f5; transform: translateY(-3px); }
        .admin-card h3 { margin: 0 0 10px 0; color: #1c252e; }
        .admin-card i { font-size: 30px; color: var(--imss-dorado); margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-shield-halved"></i> <strong>Panel de Administración del Sistema</strong></div>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver al Inicio</a>
    </div>
    
    <div class="container">
        <h2 style="color: #1c252e;"><i class="fa-solid fa-database"></i> Catálogos y Mantenimiento (ABC)</h2>
        
        <div class="grid-admin">
            <!-- NUEVO MÓDULO: CONFIGURACIÓN Y MANTENIMIENTO -->
            <div class="admin-card" onclick="window.location.href='admin_mantenimiento.php'">
                <i class="fa-solid fa-server"></i>
                <h3>Mantenimiento y Respaldos</h3>
                <p style="color: #637381; font-size: 14px;">Respaldo completo de base de datos, compresión de archivos del sistema y estado de conexiones.</p>
            </div>

            <div class="admin-card" onclick="window.location.href='admin_usuarios.php'">
                <i class="fa-solid fa-users-gear"></i>
                <h3>Gestión de Usuarios</h3>
                <p style="color: #637381; font-size: 14px;">Altas, bajas, reseteo de contraseñas y permisos de acceso a módulos.</p>
            </div>
            
            <div class="admin-card" onclick="window.location.href='admin_pacientes.php'">
                <i class="fa-solid fa-hospital-user"></i>
                <h3>Catálogo de Pacientes</h3>
                <p style="color: #637381; font-size: 14px;">Mantenimiento y migración masiva desde Access de expedientes y datos demográficos.</p>
            </div>
            
            <div class="admin-card" onclick="window.location.href='admin_medicos.php'">
                <i class="fa-solid fa-user-doctor"></i>
                <h3>Catálogo de Médicos</h3>
                <p style="color: #637381; font-size: 14px;">Registrar especialistas, cédulas profesionales y datos de contacto.</p>
            </div>

            <div class="admin-card" onclick="window.location.href='admin_areas.php'">
                <i class="fa-solid fa-layer-group"></i>
                <h3>Catálogo de Áreas</h3>
                <p style="color: #637381; font-size: 14px;">Administrar áreas y servicios hospitalarios del sistema de ingresos y censo.</p>
            </div>

            <div class="admin-card" onclick="window.location.href='admin_clinicas.php'">
                <i class="fa-solid fa-house-medical"></i>
                <h3>Catálogo de Clínicas</h3>
                <p style="color: #637381; font-size: 14px;">Administrar unidades médicas, ubicaciones, comunidades y núcleos básicos.</p>
            </div>

            <div class="admin-card" onclick="window.location.href='admin_colonias.php'">
                <i class="fa-solid fa-map-location-dot"></i>
                <h3>Catálogo de Colonias</h3>
                <p style="color: #637381; font-size: 14px;">Administrar y vincular colonias con sus respectivas clínicas o unidades médicas.</p>
            </div>

            <div class="admin-card" onclick="window.location.href='admin_tipo_paciente.php'">
                <i class="fa-solid fa-users-rectangle"></i>
                <h3>Tipos de Paciente / Programas</h3>
                <p style="color: #637381; font-size: 14px;">Administrar programas de atención y clasificación de pacientes.</p>
            </div>

            <div class="admin-card" onclick="window.location.href='admin_festivos.php'">
                <i class="fa-solid fa-calendar-days"></i>
                <h3>Días Festivos Generales</h3>
                <p style="color: #637381; font-size: 14px;">Administrar días inhábiles o festivos oficiales en todo el sistema.</p>
            </div>
            
            <div class="admin-card" onclick="window.location.href='admin_citas.php'">
                <i class="fa-solid fa-calendar-check"></i>
                <h3>Catálogo de Citas</h3>
                <p style="color: #637381; font-size: 14px;">Control, migración masiva e historial de citas estructuradas desde Access.</p>
            </div>
        </div>
    </div>
</body>
</html>