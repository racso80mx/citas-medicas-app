<?php
// ===================================================================
// 1. SEGURIDAD Y CONEXIÓN
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';

$id_paciente = isset($_GET['id_paciente']) ? intval($_GET['id_paciente']) : 0;
if ($id_paciente <= 0) {
    header("Location: trabajo_social.php");
    exit();
}

$id_estudio = isset($_GET['id_estudio']) ? intval($_GET['id_estudio']) : 0;

$error_msg = '';
$exito_msg = '';

// Obtener datos del paciente para precargar la ficha
try {
    $stmt_paciente = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
    $stmt_paciente->execute(['id' => $id_paciente]);
    $paciente = $stmt_paciente->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        header("Location: trabajo_social.php");
        exit();
    }
} catch (Exception $e) {
    die("Error al consultar el paciente: " . $e->getMessage());
}

// Obtener listado de estudios previos de este paciente para el combo desplegable
try {
    $stmt_lista = $pdo->prepare("SELECT id_estudio, fecha_estudio FROM estudio_socioeconomico WHERE id_paciente = :id_paciente ORDER BY fecha_estudio DESC, id_estudio DESC");
    $stmt_lista->execute(['id_paciente' => $id_paciente]);
    $estudios_previos = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $estudios_previos = [];
}

// Si se solicita un estudio específico, cargarlo
$estudio = null;
if ($id_estudio > 0) {
    try {
        $stmt_est = $pdo->prepare("SELECT * FROM estudio_socioeconomico WHERE id_estudio = :id_estudio AND id_paciente = :id_paciente");
        $stmt_est->execute(['id_estudio' => $id_estudio, 'id_paciente' => $id_paciente]);
        $estudio = $stmt_est->fetch(PDO::FETCH_ASSOC);
        if (!$estudio) {
            $id_estudio = 0;
        }
    } catch (Exception $e) {
        $id_estudio = 0;
    }
}

// Procesar el formulario de guardado del estudio socioeconómico y actualizar pacientes si falta info
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_estudio') {
    try {
        $limpiar_moneda = function($val) {
            return floatval(str_replace(',', '', $val ?? '0'));
        };

        $txt = function($campo) {
            return mb_strtoupper(trim($_POST[$campo] ?? ''), 'UTF-8');
        };

        $id_estudio_post = isset($_POST['id_estudio']) ? intval($_POST['id_estudio']) : 0;
        
        $curp_post = $txt('curp');
        $fecha_nac_post = $_POST['fecha_nacimiento'] ?? null;
        $edad_post = intval($_POST['edad'] ?? 0);
        $genero_post = $txt('genero');
        $lugar_nac_post = $txt('lugar_nacimiento');
        $telefono_post = $_POST['telefono'] ?? '';
        $domicilio_post = $txt('domicilio');
        $colonia_post = $txt('colonia');
        $municipio_post = $txt('municipio');

        // ===============================================================
        // ACTUALIZAR O COMPLETAR DATOS EN LA TABLA PACIENTES SI ESTÁN VACÍOS
        // ===============================================================
        $stmt_upd_pac = $pdo->prepare("
            UPDATE pacientes SET 
                curp = CASE WHEN curp IS NULL OR TRIM(curp) = '' THEN :curp ELSE curp END,
                fnacimiento = CASE WHEN fnacimiento IS NULL OR TRIM(fnacimiento::text) = '' THEN :fnac ELSE fnacimiento END,
                year_nacimiento = CASE WHEN (year_nacimiento IS NULL OR TRIM(year_nacimiento) = '') AND :fnac <> '' THEN SUBSTRING(:fnac, 1, 4) ELSE year_nacimiento END,
                edad = CASE WHEN edad IS NULL OR TRIM(edad::text) = '' OR TRIM(edad::text) = '0' THEN :edad::text ELSE edad END,
                edad_actual = CASE WHEN edad_actual IS NULL OR TRIM(edad_actual::text) = '' OR TRIM(edad_actual::text) = '0' THEN :edad::text ELSE edad_actual END,
                sexo = CASE WHEN sexo IS NULL OR TRIM(sexo) = '' THEN :genero ELSE sexo END,
                estado = CASE WHEN estado IS NULL OR TRIM(estado) = '' THEN :lugar ELSE estado END,
                telefono = CASE WHEN telefono IS NULL OR TRIM(telefono) = '' THEN :tel ELSE telefono END,
                domicilio = CASE WHEN domicilio IS NULL OR TRIM(domicilio) = '' THEN :dom ELSE domicilio END,
                colonia = CASE WHEN colonia IS NULL OR TRIM(colonia) = '' THEN :col ELSE colonia END,
                municipio = CASE WHEN municipio IS NULL OR TRIM(municipio) = '' THEN :mun ELSE municipio END
            WHERE id_paciente = :id_pac
        ");
        $stmt_upd_pac->execute([
            'curp' => $curp_post,
            'fnac' => $fecha_nac_post,
            'edad' => $edad_post,
            'genero' => $genero_post,
            'lugar' => $lugar_nac_post,
            'tel' => $telefono_post,
            'dom' => $domicilio_post,
            'col' => $colonia_post,
            'mun' => $municipio_post,
            'id_pac' => $id_paciente
        ]);

        if ($id_estudio_post > 0) {
            // ACTUALIZAR ESTUDIO EXISTENTE
            $stmt_upd = $pdo->prepare("
                UPDATE estudio_socioeconomico SET 
                    fecha_estudio = :fecha_estudio, nivel_socioeconomico = :nivel_socioeconomico, paciente_referido = :paciente_referido, unidad_refiere = :unidad_refiere,
                    nombre_completo = :nombre_completo, edad = :edad, genero = :genero, estado_civil = :estado_civil, fecha_nacimiento = :fecha_nacimiento, lugar_nacimiento = :lugar_nacimiento, religion = :religion, curp = :curp,
                    habla_dialecto = :habla_dialecto, cual_dialecto = :cual_dialecto, imss = :imss, isset = :isset, issste = :issste, naval = :naval, pemex = :pemex, imss_bienestar = :imss_bienestar,
                    escolaridad = :escolaridad, telefono = :telefono, domicilio = :domicilio, colonia = :colonia, municipio = :municipio, lugar_trabajo = :lugar_trabajo,
                    procedimiento_interfiere_creencias = :procedimiento_interfiere_creencias, cual_creencia_interferencia = :cual_creencia_interferencia,
                    nombre_padre = :nombre_padre, edad_padre = :edad_padre, escolaridad_padre = :escolaridad_padre, telefono_padre = :telefono_padre,
                    nombre_madre = :nombre_madre, edad_madre = :edad_madre, escolaridad_madre = :escolaridad_madre, telefono_madre = :telefono_madre, domicilio_padres = :domicilio_padres,
                    nombre_responsable = :nombre_responsable, parentesco_responsable = :parentesco_responsable, escolaridad_responsable = :escolaridad_responsable, telefono_responsable = :telefono_responsable,
                    ingreso_esposo = :ingreso_esposo, ingreso_esposa = :ingreso_esposa, ingreso_hijos = :ingreso_hijos, ingreso_programa_gobierno = :ingreso_programa_gobierno, ingreso_pensionado = :ingreso_pensionado, ingreso_otros = :ingreso_otros, ingreso_total = :ingreso_total,
                    egreso_alimentacion = :egreso_alimentacion, egreso_agua = :egreso_agua, egreso_luz = :egreso_luz, egreso_educacion = :egreso_educacion, egreso_vestido_calzado = :egreso_vestido_calzado, egreso_renta = :egreso_renta, egreso_otros = :egreso_otros, egreso_total = :egreso_total,
                    tipo_vivienda = :tipo_vivienda, techo_loza = :techo_loza, techo_lamina = :techo_lamina, techo_teja = :techo_teja, techo_guano = :techo_guano,
                    piso_cemento_rustico = :piso_cemento_rustico, piso_tierra = :piso_tierra, piso_mosaico = :piso_mosaico, piso_piso_fraguado = :piso_piso_fraguado,
                    servicio_agua_potable = :servicio_agua_potable, servicio_fosa_septica = :servicio_fosa_septica, servicio_tv_cable = :servicio_tv_cable, servicio_agua_pozo = :servicio_agua_pozo, servicio_luz = :servicio_luz, servicio_drenaje = :servicio_drenaje, servicio_internet = :servicio_internet, servicio_otros = :servicio_otros,
                    diagnostico_social = :diagnostico_social, nombre_trabajador_social = :nombre_trabajador_social, cedula_profesional_ts = :cedula_profesional_ts
                WHERE id_estudio = :id_estudio AND id_paciente = :id_paciente
            ");

            $stmt_upd->execute([
                'id_estudio' => $id_estudio_post,
                'id_paciente' => $id_paciente,
                'fecha_estudio' => $_POST['fecha_estudio'] ?? null,
                'nivel_socioeconomico' => $txt('nivel_socioeconomico'),
                'paciente_referido' => isset($_POST['paciente_referido']) ? 1 : 0,
                'unidad_refiere' => $txt('unidad_refiere'),
                'nombre_completo' => $txt('nombre_completo'),
                'edad' => $edad_post,
                'genero' => $genero_post,
                'estado_civil' => $txt('estado_civil'),
                'fecha_nacimiento' => $fecha_nac_post,
                'lugar_nacimiento' => $lugar_nac_post,
                'religion' => $txt('religion'),
                'curp' => $curp_post,
                'habla_dialecto' => isset($_POST['habla_dialecto']) ? 1 : 0,
                'cual_dialecto' => $txt('cual_dialecto'),
                'imss' => isset($_POST['imss']) ? 1 : 0,
                'isset' => isset($_POST['isset']) ? 1 : 0,
                'issste' => isset($_POST['issste']) ? 1 : 0,
                'naval' => isset($_POST['naval']) ? 1 : 0,
                'pemex' => isset($_POST['pemex']) ? 1 : 0,
                'imss_bienestar' => isset($_POST['imss_bienestar']) ? 1 : 0,
                'escolaridad' => $txt('escolaridad'),
                'telefono' => $telefono_post,
                'domicilio' => $domicilio_post,
                'colonia' => $colonia_post,
                'municipio' => $municipio_post,
                'lugar_trabajo' => $txt('lugar_trabajo'),
                'procedimiento_interfiere_creencias' => isset($_POST['procedimiento_interfiere_creencias']) ? 1 : 0,
                'cual_creencia_interferencia' => $txt('cual_creencia_interferencia'),
                'nombre_padre' => $txt('nombre_padre'),
                'edad_padre' => intval($_POST['edad_padre'] ?? 0),
                'escolaridad_padre' => $txt('escolaridad_padre'),
                'telefono_padre' => $_POST['telefono_padre'] ?? '',
                'nombre_madre' => $txt('nombre_madre'),
                'edad_madre' => intval($_POST['edad_madre'] ?? 0),
                'escolaridad_madre' => $txt('escolaridad_madre'),
                'telefono_madre' => $_POST['telefono_madre'] ?? '',
                'domicilio_padres' => $txt('domicilio_padres'),
                'nombre_responsable' => $txt('nombre_responsable'),
                'parentesco_responsable' => $txt('parentesco_responsable'),
                'escolaridad_responsable' => $txt('escolaridad_responsable'),
                'telefono_responsable' => $_POST['telefono_responsable'] ?? '',
                'ingreso_esposo' => $limpiar_moneda($_POST['ingreso_esposo'] ?? 0),
                'ingreso_esposa' => $limpiar_moneda($_POST['ingreso_esposa'] ?? 0),
                'ingreso_hijos' => $limpiar_moneda($_POST['ingreso_hijos'] ?? 0),
                'ingreso_programa_gobierno' => $limpiar_moneda($_POST['ingreso_programa_gobierno'] ?? 0),
                'ingreso_pensionado' => $limpiar_moneda($_POST['ingreso_pensionado'] ?? 0),
                'ingreso_otros' => $limpiar_moneda($_POST['ingreso_otros'] ?? 0),
                'ingreso_total' => $limpiar_moneda($_POST['ingreso_total'] ?? 0),
                'egreso_alimentacion' => $limpiar_moneda($_POST['egreso_alimentacion'] ?? 0),
                'egreso_agua' => $limpiar_moneda($_POST['egreso_agua'] ?? 0),
                'egreso_luz' => $limpiar_moneda($_POST['egreso_luz'] ?? 0),
                'egreso_educacion' => $limpiar_moneda($_POST['egreso_educacion'] ?? 0),
                'egreso_vestido_calzado' => $limpiar_moneda($_POST['egreso_vestido_calzado'] ?? 0),
                'egreso_renta' => $limpiar_moneda($_POST['egreso_renta'] ?? 0),
                'egreso_otros' => $limpiar_moneda($_POST['egreso_otros'] ?? 0),
                'egreso_total' => $limpiar_moneda($_POST['egreso_total'] ?? 0),
                'tipo_vivienda' => $txt('tipo_vivienda'),
                'techo_loza' => isset($_POST['techo_loza']) ? 1 : 0,
                'techo_lamina' => isset($_POST['techo_lamina']) ? 1 : 0,
                'techo_teja' => isset($_POST['techo_teja']) ? 1 : 0,
                'techo_guano' => isset($_POST['techo_guano']) ? 1 : 0,
                'piso_cemento_rustico' => isset($_POST['piso_cemento_rustico']) ? 1 : 0,
                'piso_tierra' => isset($_POST['piso_tierra']) ? 1 : 0,
                'piso_mosaico' => isset($_POST['piso_mosaico']) ? 1 : 0,
                'piso_piso_fraguado' => isset($_POST['piso_piso_fraguado']) ? 1 : 0,
                'servicio_agua_potable' => isset($_POST['servicio_agua_potable']) ? 1 : 0,
                'servicio_fosa_septica' => isset($_POST['servicio_fosa_septica']) ? 1 : 0,
                'servicio_tv_cable' => isset($_POST['servicio_tv_cable']) ? 1 : 0,
                'servicio_agua_pozo' => isset($_POST['servicio_agua_pozo']) ? 1 : 0,
                'servicio_luz' => isset($_POST['servicio_luz']) ? 1 : 0,
                'servicio_drenaje' => isset($_POST['servicio_drenaje']) ? 1 : 0,
                'servicio_internet' => isset($_POST['servicio_internet']) ? 1 : 0,
                'servicio_otros' => $txt('servicio_otros'),
                'diagnostico_social' => $txt('diagnostico_social'),
                'nombre_trabajador_social' => $txt('nombre_trabajador_social'),
                'cedula_profesional_ts' => $txt('cedula_profesional_ts')
            ]);

            $id_estudio = $id_estudio_post;
            $exito_msg = "Estudio socioeconómico actualizado correctamente.";
        } else {
            // INSERTAR NUEVO ESTUDIO
            $stmt_ins = $pdo->prepare("
                INSERT INTO estudio_socioeconomico (
                    id_paciente, fecha_estudio, nivel_socioeconomico, paciente_referido, unidad_refiere,
                    nombre_completo, edad, genero, estado_civil, fecha_nacimiento, lugar_nacimiento, religion, curp,
                    habla_dialecto, cual_dialecto, imss, isset, issste, naval, pemex, imss_bienestar,
                    escolaridad, telefono, domicilio, colonia, municipio, lugar_trabajo,
                    procedimiento_interfiere_creencias, cual_creencia_interferencia,
                    nombre_padre, edad_padre, escolaridad_padre, telefono_padre,
                    nombre_madre, edad_madre, escolaridad_madre, telefono_madre, domicilio_padres,
                    nombre_responsable, parentesco_responsable, escolaridad_responsable, telefono_responsable,
                    ingreso_esposo, ingreso_esposa, ingreso_hijos, ingreso_programa_gobierno, ingreso_pensionado, ingreso_otros, ingreso_total,
                    egreso_alimentacion, egreso_agua, egreso_luz, egreso_educacion, egreso_vestido_calzado, egreso_renta, egreso_otros, egreso_total,
                    tipo_vivienda, techo_loza, techo_lamina, techo_teja, techo_guano,
                    piso_cemento_rustico, piso_tierra, piso_mosaico, piso_piso_fraguado,
                    servicio_agua_potable, servicio_fosa_septica, servicio_tv_cable, servicio_agua_pozo, servicio_luz, servicio_drenaje, servicio_internet, servicio_otros,
                    diagnostico_social, nombre_trabajador_social, cedula_profesional_ts
                ) VALUES (
                    :id_paciente, :fecha_estudio, :nivel_socioeconomico, :paciente_referido, :unidad_refiere,
                    :nombre_completo, :edad, :genero, :estado_civil, :fecha_nacimiento, :lugar_nacimiento, :religion, :curp,
                    :habla_dialecto, :cual_dialecto, :imss, :isset, :issste, :naval, :pemex, :imss_bienestar,
                    :escolaridad, :telefono, :domicilio, :colonia, :municipio, :lugar_trabajo,
                    :procedimiento_interfiere_creencias, :cual_creencia_interferencia,
                    :nombre_padre, :edad_padre, :escolaridad_padre, :telefono_padre,
                    :nombre_madre, :edad_madre, :escolaridad_madre, :telefono_madre, :domicilio_padres,
                    :nombre_responsable, :parentesco_responsable, :escolaridad_responsable, :telefono_responsable,
                    :ingreso_esposo, :ingreso_esposa, :ingreso_hijos, :ingreso_programa_gobierno, :ingreso_pensionado, :ingreso_otros, :ingreso_total,
                    :egreso_alimentacion, :egreso_agua, :egreso_luz, :egreso_educacion, :egreso_vestido_calzado, :egreso_renta, :egreso_otros, :egreso_total,
                    :tipo_vivienda, :techo_loza, :techo_lamina, :techo_teja, :techo_guano,
                    :piso_cemento_rustico, :piso_tierra, :piso_mosaico, :piso_piso_fraguado,
                    :servicio_agua_potable, :servicio_fosa_septica, :servicio_tv_cable, :servicio_agua_pozo, :servicio_luz, :servicio_drenaje, :servicio_internet, :servicio_otros,
                    :diagnostico_social, :nombre_trabajador_social, :cedula_profesional_ts
                )
            ");

            $stmt_ins->execute([
                'id_paciente' => $id_paciente,
                'fecha_estudio' => $_POST['fecha_estudio'] ?? null,
                'nivel_socioeconomico' => $txt('nivel_socioeconomico'),
                'paciente_referido' => isset($_POST['paciente_referido']) ? 1 : 0,
                'unidad_refiere' => $txt('unidad_refiere'),
                'nombre_completo' => $txt('nombre_completo'),
                'edad' => $edad_post,
                'genero' => $genero_post,
                'estado_civil' => $txt('estado_civil'),
                'fecha_nacimiento' => $fecha_nac_post,
                'lugar_nacimiento' => $lugar_nac_post,
                'religion' => $txt('religion'),
                'curp' => $curp_post,
                'habla_dialecto' => isset($_POST['habla_dialecto']) ? 1 : 0,
                'cual_dialecto' => $txt('cual_dialecto'),
                'imss' => isset($_POST['imss']) ? 1 : 0,
                'isset' => isset($_POST['isset']) ? 1 : 0,
                'issste' => isset($_POST['issste']) ? 1 : 0,
                'naval' => isset($_POST['naval']) ? 1 : 0,
                'pemex' => isset($_POST['pemex']) ? 1 : 0,
                'imss_bienestar' => isset($_POST['imss_bienestar']) ? 1 : 0,
                'escolaridad' => $txt('escolaridad'),
                'telefono' => $telefono_post,
                'domicilio' => $domicilio_post,
                'colonia' => $colonia_post,
                'municipio' => $municipio_post,
                'lugar_trabajo' => $txt('lugar_trabajo'),
                'procedimiento_interfiere_creencias' => isset($_POST['procedimiento_interfiere_creencias']) ? 1 : 0,
                'cual_creencia_interferencia' => $txt('cual_creencia_interferencia'),
                'nombre_padre' => $txt('nombre_padre'),
                'edad_padre' => intval($_POST['edad_padre'] ?? 0),
                'escolaridad_padre' => $txt('escolaridad_padre'),
                'telefono_padre' => $_POST['telefono_padre'] ?? '',
                'nombre_madre' => $txt('nombre_madre'),
                'edad_madre' => intval($_POST['edad_madre'] ?? 0),
                'escolaridad_madre' => $txt('escolaridad_madre'),
                'telefono_madre' => $_POST['telefono_madre'] ?? '',
                'domicilio_padres' => $txt('domicilio_padres'),
                'nombre_responsable' => $txt('nombre_responsable'),
                'parentesco_responsable' => $txt('parentesco_responsable'),
                'escolaridad_responsable' => $txt('escolaridad_responsable'),
                'telefono_responsable' => $_POST['telefono_responsable'] ?? '',
                'ingreso_esposo' => $limpiar_moneda($_POST['ingreso_esposo'] ?? 0),
                'ingreso_esposa' => $limpiar_moneda($_POST['ingreso_esposa'] ?? 0),
                'ingreso_hijos' => $limpiar_moneda($_POST['ingreso_hijos'] ?? 0),
                'ingreso_programa_gobierno' => $limpiar_moneda($_POST['ingreso_programa_gobierno'] ?? 0),
                'ingreso_pensionado' => $limpiar_moneda($_POST['ingreso_pensionado'] ?? 0),
                'ingreso_otros' => $limpiar_moneda($_POST['ingreso_otros'] ?? 0),
                'ingreso_total' => $limpiar_moneda($_POST['ingreso_total'] ?? 0),
                'egreso_alimentacion' => $limpiar_moneda($_POST['egreso_alimentacion'] ?? 0),
                'egreso_agua' => $limpiar_moneda($_POST['egreso_agua'] ?? 0),
                'egreso_luz' => $limpiar_moneda($_POST['egreso_luz'] ?? 0),
                'egreso_educacion' => $limpiar_moneda($_POST['egreso_educacion'] ?? 0),
                'egreso_vestido_calzado' => $limpiar_moneda($_POST['egreso_vestido_calzado'] ?? 0),
                'egreso_renta' => $limpiar_moneda($_POST['egreso_renta'] ?? 0),
                'egreso_otros' => $limpiar_moneda($_POST['egreso_otros'] ?? 0),
                'egreso_total' => $limpiar_moneda($_POST['egreso_total'] ?? 0),
                'tipo_vivienda' => $txt('tipo_vivienda'),
                'techo_loza' => isset($_POST['techo_loza']) ? 1 : 0,
                'techo_lamina' => isset($_POST['techo_lamina']) ? 1 : 0,
                'techo_teja' => isset($_POST['techo_teja']) ? 1 : 0,
                'techo_guano' => isset($_POST['techo_guano']) ? 1 : 0,
                'piso_cemento_rustico' => isset($_POST['piso_cemento_rustico']) ? 1 : 0,
                'piso_tierra' => isset($_POST['piso_tierra']) ? 1 : 0,
                'piso_mosaico' => isset($_POST['piso_mosaico']) ? 1 : 0,
                'piso_piso_fraguado' => isset($_POST['piso_piso_fraguado']) ? 1 : 0,
                'servicio_agua_potable' => isset($_POST['servicio_agua_potable']) ? 1 : 0,
                'servicio_fosa_septica' => isset($_POST['servicio_fosa_septica']) ? 1 : 0,
                'servicio_tv_cable' => isset($_POST['servicio_tv_cable']) ? 1 : 0,
                'servicio_agua_pozo' => isset($_POST['servicio_agua_pozo']) ? 1 : 0,
                'servicio_luz' => isset($_POST['servicio_luz']) ? 1 : 0,
                'servicio_drenaje' => isset($_POST['servicio_drenaje']) ? 1 : 0,
                'servicio_internet' => isset($_POST['servicio_internet']) ? 1 : 0,
                'servicio_otros' => $txt('servicio_otros'),
                'diagnostico_social' => $txt('diagnostico_social'),
                'nombre_trabajador_social' => $txt('nombre_trabajador_social'),
                'cedula_profesional_ts' => $txt('cedula_profesional_ts')
            ]);

            $id_estudio = $pdo->lastInsertId();
            $exito_msg = "Estudio socioeconómico guardado correctamente.";
            
            $stmt_lista = $pdo->prepare("SELECT id_estudio, fecha_estudio FROM estudio_socioeconomico WHERE id_paciente = :id_paciente ORDER BY fecha_estudio DESC, id_estudio DESC");
            $stmt_lista->execute(['id_paciente' => $id_paciente]);
            $estudios_previos = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);
        }

        // Recargar datos actualizados del paciente y del estudio
        $stmt_paciente = $pdo->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
        $stmt_paciente->execute(['id' => $id_paciente]);
        $paciente = $stmt_paciente->fetch(PDO::FETCH_ASSOC);

        $stmt_est = $pdo->prepare("SELECT * FROM estudio_socioeconomico WHERE id_estudio = :id_estudio");
        $stmt_est->execute(['id_estudio' => $id_estudio]);
        $estudio = $stmt_est->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error_msg = "Error al guardar el estudio: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Ficha Inicial Socioeconómico 2026</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; --borde: #94a3b8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        
        .formatos-nav { background: #e2e8f0; padding: 10px 25px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #cbd5e1; flex-wrap: wrap; gap: 10px; font-size: 12px; }
        .formato-tab { padding: 5px 12px; background: white; border-radius: 4px; text-decoration: none; color: #334155; font-weight: bold; border: 1px solid #94a3b8; }
        .formato-tab.active { background: var(--imss-verde); color: white; border-color: var(--imss-verde); }

        .header-logos { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--imss-verde); padding-bottom: 10px; margin-bottom: 15px; }
        .header-logos img { height: 50px; object-fit: contain; }
        .header-title { text-align: center; flex: 1; margin: 0 15px; }
        .header-title h3 { margin: 2px 0; font-size: 12px; font-weight: bold; color: #1c252e; }
        .header-title h4 { margin: 2px 0; font-size: 11px; font-weight: bold; color: var(--imss-verde); }

        .container { max-width: 950px; margin: 25px auto; padding: 40px; background: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        
        .section-title { background: #e2e8f0; font-weight: bold; font-size: 12px; padding: 4px 8px; margin: 15px 0 10px 0; border: 1px solid #cbd5e1; text-align: center; text-transform: uppercase; }
        
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        
        .form-group { margin-bottom: 8px; font-size: 12px; }
        .form-group label { font-weight: bold; display: inline-block; margin-right: 5px; }
        
        input[type="text"], select, textarea { width: 100%; padding: 4px 6px; border: 1px solid var(--borde); border-radius: 3px; font-size: 12px; box-sizing: border-box; background: #fff; text-transform: uppercase; }
        input[type="date"], input[type="number"] { width: 100%; padding: 4px 6px; border: 1px solid var(--borde); border-radius: 3px; font-size: 12px; box-sizing: border-box; background: #fff; }
        textarea { resize: vertical; }
        
        .checkbox-group { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-size: 12px; }
        .checkbox-group label { font-weight: normal; display: inline-flex; align-items: center; gap: 3px; cursor: pointer; }
        
        .table-economica { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .table-economica th, .table-economica td { border: 1px solid #000; padding: 4px 6px; text-align: left; font-size: 11px; }
        .table-economica th { background: #f1f3f5; text-align: center; }
        
        .btn-container { text-align: right; margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; color: white; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-print { background-color: #0284c7; }
        .btn:hover { opacity: 0.9; }

        @media print {
            .navbar, .formatos-nav, .btn-container, .form-control-view { display: none !important; }
            body { background: white; font-size: 11px; color: #000; }
            .container { box-shadow: none; margin: 0; padding: 0; max-width: 100%; width: 100%; }
            input, select, textarea { border: none !important; border-bottom: 1px solid #000 !important; background: transparent !important; padding: 0 !important; border-radius: 0 !important; }
        }
    </style>
</head>
<body>
    <div class="navbar form-control-view">
        <div><i class="fa-solid fa-file-invoice-dollar"><strong> Ficha Inicial Socioeconómico 2026</strong></i></div>
        <a href="trabajo_social.php"><i class="fa-solid fa-arrow-left"></i> Volver al Listado</a>
    </div>

    <!-- BARRA UNIFICADA: 4 FORMATOS Y COMBO DE REGISTROS -->
    <div class="formatos-nav form-control-view">
        <div style="display: flex; gap: 5px; align-items: center; flex-wrap: wrap;">
            <strong>Formatos:</strong>
            <a href="rph.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">1. RPH</a>
            <a href="estudio_socioeconomico.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab active">2. Socioeconómico</a>
            <a href="nota_trabajo_social.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">3. Nota Trabajo Social</a>
            <a href="estructura_familiar.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">4. Estructura Familiar</a>
        </div>

        <div style="display: flex; gap: 8px; align-items: center;">
            <strong>Registros:</strong>
            <select id="selectorEstudios" onchange="cambiarEstudio(this)" style="padding: 4px 8px; border-radius: 4px; border: 1px solid #94a3b8; font-size: 12px; background: white; width: auto;">
                <option value="">-- SELECCIONE UN REGISTRO --</option>
                <?php 
                $total_est = count($estudios_previos);
                foreach ($estudios_previos as $index => $ep): 
                    $num_est = $total_est - $index;
                    $selected = ($id_estudio == $ep['id_estudio']) ? 'selected' : '';
                ?>
                    <option value="estudio_socioeconomico.php?id_paciente=<?= $id_paciente ?>&id_estudio=<?= $ep['id_estudio'] ?>" <?= $selected ?>>
                        Registro #<?= $num_est ?> (<?= date('d/m/Y', strtotime($ep['fecha_estudio'] ?? 'now')) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="estudio_socioeconomico.php?id_paciente=<?= $id_paciente ?>" class="btn-nuevo-estudio" style="background: #0284c7; color: white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: bold; display: inline-flex; align-items: center; gap: 4px;">
                <i class="fa-solid fa-plus"></i> Nuevo
            </a>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($error_msg)): ?>
            <div class="form-control-view" style="background: #ffebee; color: #c62828; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($exito_msg)): ?>
            <div class="form-control-view" style="background: #e4f8dd; color: var(--imss-verde); padding: 10px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($exito_msg) ?>
            </div>
        <?php endif; ?>

        <!-- ENCABEZADO CON LOGOS -->
        <div class="header-logos">
            <div style="display: flex; gap: 8px; align-items: center;">
                <img src="logo_gobierno.png" alt="Gobierno de México" onerror="this.style.display='none'">
                <img src="logo_imss.png" alt="IMSS-Bienestar" onerror="this.style.display='none'">
            </div>
            <div class="header-title">
                <h3>HOSPITAL GENERAL DE HUIMANGUILLO - DR. ADELFO S. AGUIRRE</h3>
                <h3>IMSS-BIENESTAR - DEPARTAMENTO DE TRABAJO SOCIAL</h3>
                <h4>FICHA INICIAL SOCIOECONÓMICO 2026</h4>
            </div>
            <div>
                <img src="logo_hospital.png" alt="Hospital" onerror="this.style.display='none'">
            </div>
        </div>

        <form action="estudio_socioeconomico.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" method="POST" id="formSocioeconomico">
            <input type="hidden" name="accion" value="guardar_estudio">
            <input type="hidden" name="id_estudio" value="<?= $id_estudio ?>">

            <!-- DATOS GENERALES -->
            <div class="grid-3">
                <div class="form-group">
                    <label>FECHA:</label>
                    <input type="date" name="fecha_estudio" value="<?= htmlspecialchars($estudio['fecha_estudio'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label>NÚMERO DE EXPEDIENTE:</label>
                    <input type="text" name="expediente" value="<?= htmlspecialchars($paciente['expediente'] ?? '') ?>" readonly style="background:#e9ecef;">
                </div>
                <div class="form-group">
                    <label>NIVEL SOCIOECONÓMICO:</label>
                    <input type="text" name="nivel_socioeconomico" value="<?= htmlspecialchars($estudio['nivel_socioeconomico'] ?? '') ?>" placeholder="BAJO / MEDIO / ALTO">
                </div>
            </div>
            <div class="grid-2" style="margin-top: 5px;">
                <div class="form-group checkbox-group">
                    <label><strong>PACIENTE REFERIDO:</strong></label>
                    <label><input type="checkbox" name="paciente_referido" value="1" <?= (($estudio['paciente_referido'] ?? 0) == 1) ? 'checked' : '' ?>> SÍ ( )</label>
                    <label>NO ( )</label>
                </div>
                <div class="form-group">
                    <label>UNIDAD QUE REFIERE:</label>
                    <input type="text" name="unidad_refiere" value="<?= htmlspecialchars($estudio['unidad_refiere'] ?? '') ?>">
                </div>
            </div>

            <div class="section-title">DATOS PERSONALES</div>
            <div class="grid-3">
                <div class="form-group" style="grid-column: span 2;">
                    <label>NOMBRE:</label>
                    <input type="text" name="nombre_completo" value="<?= htmlspecialchars($estudio['nombre_completo'] ?? trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apaterno'] ?? '') . ' ' . ($paciente['amaterno'] ?? ''))) ?>" required>
                </div>
                <div class="form-group">
                    <label>FECHA DE NACIMIENTO:</label>
                    <input type="date" name="fecha_nacimiento" id="inputFnac" value="<?= htmlspecialchars($estudio['fecha_nacimiento'] ?? ($paciente['fnacimiento'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>EDAD:</label>
                    <input type="number" name="edad" id="inputEdad" value="<?= htmlspecialchars($estudio['edad'] ?? ($paciente['edad'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>GÉNERO:</label>
                    <select name="genero" id="selectSexo">
                        <?php $gen_val = $estudio['genero'] ?? ($paciente['sexo'] ?? ''); ?>
                        <option value="">SELECCIONE...</option>
                        <option value="MASCULINO" <?= ($gen_val === 'MASCULINO' || $gen_val === 'HOMBRE') ? 'selected' : '' ?>>MASCULINO</option>
                        <option value="FEMENINO" <?= ($gen_val === 'FEMENINO' || $gen_val === 'MUJER') ? 'selected' : '' ?>>FEMENINO</option>
                        <option value="OTRO" <?= ($gen_val === 'OTRO') ? 'selected' : '' ?>>OTRO</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>ESTADO CIVIL:</label>
                    <select name="estado_civil">
                        <?php $ec_val = $estudio['estado_civil'] ?? ''; ?>
                        <option value="">SELECCIONE...</option>
                        <option value="SOLTERO/A" <?= ($ec_val === 'SOLTERO/A') ? 'selected' : '' ?>>SOLTERO/A</option>
                        <option value="CASADO/A" <?= ($ec_val === 'CASADO/A') ? 'selected' : '' ?>>CASADO/A</option>
                        <option value="UNIÓN LIBRE" <?= ($ec_val === 'UNIÓN LIBRE') ? 'selected' : '' ?>>UNIÓN LIBRE</option>
                        <option value="DIVORCIADO/A" <?= ($ec_val === 'DIVORCIADO/A') ? 'selected' : '' ?>>DIVORCIADO/A</option>
                        <option value="VIUDO/A" <?= ($ec_val === 'VIUDO/A') ? 'selected' : '' ?>>VIUDO/A</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>RELIGIÓN:</label>
                    <select name="religion">
                        <?php $rel_val = $estudio['religion'] ?? ''; ?>
                        <option value="">SELECCIONE...</option>
                        <option value="CATÓLICA" <?= ($rel_val === 'CATÓLICA') ? 'selected' : '' ?>>CATÓLICA</option>
                        <option value="CRISTIANA / EVANGÉLICA" <?= ($rel_val === 'CRISTIANA / EVANGÉLICA') ? 'selected' : '' ?>>CRISTIANA / EVANGÉLICA</option>
                        <option value="TESTIGO DE JEHOVÁ" <?= ($rel_val === 'TESTIGO DE JEHOVÁ') ? 'selected' : '' ?>>TESTIGO DE JEHOVÁ</option>
                        <option value="ADVENTISTA" <?= ($rel_val === 'ADVENTISTA') ? 'selected' : '' ?>>ADVENTISTA</option>
                        <option value="MORMONA" <?= ($rel_val === 'MORMONA') ? 'selected' : '' ?>>MORMONA</option>
                        <option value="NINGUNA" <?= ($rel_val === 'NINGUNA') ? 'selected' : '' ?>>NINGUNA</option>
                        <option value="OTRA" <?= ($rel_val === 'OTRA') ? 'selected' : '' ?>>OTRA</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>LUGAR DE NACIMIENTO:</label>
                    <input type="text" name="lugar_nacimiento" id="inputEstado" value="<?= htmlspecialchars($estudio['lugar_nacimiento'] ?? ($paciente['estado'] ?? '')) ?>">
                </div>
                <div class="form-group" style="grid-column: span 3;">
                    <label>CURP (Autocalcula fecha, edad, género y estado):</label>
                    <input type="text" name="curp" id="inputCurp" maxlength="18" oninput="procesarCurp(this.value)" value="<?= htmlspecialchars($estudio['curp'] ?? ($paciente['curp'] ?? '')) ?>" required style="text-transform: uppercase;">
                </div>
            </div>

            <div class="section-title">DERECHOHABIENCIA Y CULTURALES</div>
            <div class="form-group checkbox-group" style="margin-bottom: 5px;">
                <label><input type="checkbox" name="imss" value="1" <?= (($estudio['imss'] ?? 0) == 1) ? 'checked' : '' ?>> IMSS ( )</label>
                <label><input type="checkbox" name="isset" value="1" <?= (($estudio['isset'] ?? 0) == 1) ? 'checked' : '' ?>> ISSET ( )</label>
                <label><input type="checkbox" name="issste" value="1" <?= (($estudio['issste'] ?? 0) == 1) ? 'checked' : '' ?>> ISSSTE ( )</label>
                <label><input type="checkbox" name="naval" value="1" <?= (($estudio['naval'] ?? 0) == 1) ? 'checked' : '' ?>> NAVAL ( )</label>
                <label><input type="checkbox" name="pemex" value="1" <?= (($estudio['pemex'] ?? 0) == 1) ? 'checked' : '' ?>> PEMEX ( )</label>
                <label><input type="checkbox" name="imss_bienestar" value="1" <?= (isset($estudio) && ($estudio['imss_bienestar'] ?? 0) == 1) ? 'checked' : 'checked' ?>> <strong>IMSS BIENESTAR (X)</strong></label>
            </div>
            <div class="grid-2">
                <div class="form-group checkbox-group">
                    <label><strong>¿HABLA ALGÚN DIALECTO?</strong></label>
                    <label><input type="checkbox" name="habla_dialecto" value="1" <?= (($estudio['habla_dialecto'] ?? 0) == 1) ? 'checked' : '' ?>> SÍ ( )</label>
                    <label>NO ( )</label>
                </div>
                <div class="form-group">
                    <label>¿CUÁL?</label>
                    <input type="text" name="cual_dialecto" value="<?= htmlspecialchars($estudio['cual_dialecto'] ?? '') ?>">
                </div>
            </div>

            <div class="grid-3" style="margin-top: 5px;">
                <div class="form-group">
                    <label>ESCOLARIDAD:</label>
                    <input type="text" name="escolaridad" value="<?= htmlspecialchars($estudio['escolaridad'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>OCUPACIÓN:</label>
                    <input type="text" name="lugar_trabajo" value="<?= htmlspecialchars($estudio['lugar_trabajo'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>NÚM. DE TELÉFONO (10 dígitos):</label>
                    <input type="text" name="telefono" value="<?= htmlspecialchars($estudio['telefono'] ?? ($paciente['telefono'] ?? '')) ?>" maxlength="10" pattern="[0-9]{10}" title="Debe ingresar exactamente 10 dígitos numéricos">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>DOMICILIO:</label>
                    <input type="text" name="domicilio" value="<?= htmlspecialchars($estudio['domicilio'] ?? ($paciente['domicilio'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>COLONIA:</label>
                    <input type="text" name="colonia" value="<?= htmlspecialchars($estudio['colonia'] ?? ($paciente['colonia'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>MUNICIPIO:</label>
                    <input type="text" name="municipio" value="<?= htmlspecialchars($estudio['municipio'] ?? '') ?>">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>LUGAR DE TRABAJO:</label>
                    <input type="text" name="lugar_trabajo" value="<?= htmlspecialchars($estudio['lugar_trabajo'] ?? '') ?>">
                </div>
            </div>

            <div class="grid-2" style="margin-top: 5px;">
                <div class="form-group checkbox-group">
                    <label><strong>¿EXISTE ALGÚN PROCEDIMIENTO HOSPITALARIO QUE INTERFIERA EN SUS CREENCIAS?</strong></label>
                    <label><input type="checkbox" name="procedimiento_interfiere_creencias" value="1" <?= (($estudio['procedimiento_interfiere_creencias'] ?? 0) == 1) ? 'checked' : '' ?>> SÍ ( )</label>
                    <label>NO ( )</label>
                </div>
                <div class="form-group">
                    <label>¿CUÁL?</label>
                    <input type="text" name="cual_creencia_interferencia" value="<?= htmlspecialchars($estudio['cual_creencia_interferencia'] ?? '') ?>">
                </div>
            </div>

            <div class="section-title">DATOS FAMILIARES</div>
            <div class="grid-4">
                <div class="form-group" style="grid-column: span 2;">
                    <label>NOMBRE DEL PADRE:</label>
                    <input type="text" name="nombre_padre" value="<?= htmlspecialchars($estudio['nombre_padre'] ?? ($paciente['nombre_padre'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>EDAD:</label>
                    <input type="number" name="edad_padre" value="<?= htmlspecialchars($estudio['edad_padre'] ?? ($paciente['edad_padre'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>TELÉFONO (10 dígitos):</label>
                    <input type="text" name="telefono_padre" value="<?= htmlspecialchars($estudio['telefono_padre'] ?? '') ?>" maxlength="10" pattern="[0-9]{10}" title="Debe ingresar exactamente 10 dígitos numéricos">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>NOMBRE DE LA MADRE:</label>
                    <input type="text" name="nombre_madre" value="<?= htmlspecialchars($estudio['nombre_madre'] ?? ($paciente['nombre_madre'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>EDAD:</label>
                    <input type="number" name="edad_madre" value="<?= htmlspecialchars($estudio['edad_madre'] ?? ($paciente['edad_madre'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>ESCOLARIDAD:</label>
                    <input type="text" name="escolaridad_madre" value="<?= htmlspecialchars($estudio['escolaridad_madre'] ?? '') ?>">
                </div>
                <div class="form-group" style="grid-column: span 4;">
                    <label>DOMICILIO:</label>
                    <input type="text" name="domicilio_padres" value="<?= htmlspecialchars($estudio['domicilio_padres'] ?? '') ?>">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>NOMBRE DEL FAMILIAR RESPONSABLE Y/O INFORMANTE:</label>
                    <input type="text" name="nombre_responsable" value="<?= htmlspecialchars($estudio['nombre_responsable'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>PARENTESCO:</label>
                    <input type="text" name="parentesco_responsable" value="<?= htmlspecialchars($estudio['parentesco_responsable'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>TELÉFONO (10 dígitos):</label>
                    <input type="text" name="telefono_responsable" value="<?= htmlspecialchars($estudio['telefono_responsable'] ?? '') ?>" maxlength="10" pattern="[0-9]{10}" title="Debe ingresar exactamente 10 dígitos numéricos">
                </div>
            </div>

            <div class="section-title">SITUACIÓN ECONÓMICA</div>
            <div class="grid-2">
                <div>
                    <table class="table-economica">
                        <tr><th colspan="2">INGRESOS</th></tr>
                        <tr><td>ESPOSO $</td><td><input type="text" class="input-moneda" name="ingreso_esposo" value="<?= number_format($estudio['ingreso_esposo'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>ESPOSA $</td><td><input type="text" class="input-moneda" name="ingreso_esposa" value="<?= number_format($estudio['ingreso_esposa'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>HIJOS $</td><td><input type="text" class="input-moneda" name="ingreso_hijos" value="<?= number_format($estudio['ingreso_hijos'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>PROGRAMA DE GOBIERNO $</td><td><input type="text" class="input-moneda" name="ingreso_programa_gobierno" value="<?= number_format($estudio['ingreso_programa_gobierno'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>PENSIONADO $</td><td><input type="text" class="input-moneda" name="ingreso_pensionado" value="<?= number_format($estudio['ingreso_pensionado'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>OTROS $</td><td><input type="text" class="input-moneda" name="ingreso_otros" value="<?= number_format($estudio['ingreso_otros'] ?? 0, 2) ?>"></td></tr>
                        <tr><td><strong>TOTAL $</strong></td><td><input type="text" class="input-moneda" id="ingreso_total" name="ingreso_total" value="<?= number_format($estudio['ingreso_total'] ?? 0, 2) ?>" readonly style="background:#e9ecef; font-weight:bold;"></td></tr>
                    </table>
                </div>
                <div>
                    <table class="table-economica">
                        <tr><th colspan="2">EGRESOS</th></tr>
                        <tr><td>ALIMENTACIÓN $</td><td><input type="text" class="input-moneda" name="egreso_alimentacion" value="<?= number_format($estudio['egreso_alimentacion'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>AGUA $</td><td><input type="text" class="input-moneda" name="egreso_agua" value="<?= number_format($estudio['egreso_agua'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>LUZ $</td><td><input type="text" class="input-moneda" name="egreso_luz" value="<?= number_format($estudio['egreso_luz'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>EDUCACIÓN $</td><td><input type="text" class="input-moneda" name="egreso_educacion" value="<?= number_format($estudio['egreso_educacion'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>VESTIDO Y CALZADO $</td><td><input type="text" class="input-moneda" name="egreso_vestido_calzado" value="<?= number_format($estudio['egreso_vestido_calzado'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>RENTA $</td><td><input type="text" class="input-moneda" name="egreso_renta" value="<?= number_format($estudio['egreso_renta'] ?? 0, 2) ?>"></td></tr>
                        <tr><td>OTROS $</td><td><input type="text" class="input-moneda" name="egreso_otros" value="<?= number_format($estudio['egreso_otros'] ?? 0, 2) ?>"></td></tr>
                        <tr><td><strong>TOTAL $</strong></td><td><input type="text" class="input-moneda" id="egreso_total" name="egreso_total" value="<?= number_format($estudio['egreso_total'] ?? 0, 2) ?>" readonly style="background:#e9ecef; font-weight:bold;"></td></tr>
                    </table>
                </div>
            </div>

            <div class="section-title">CARACTERÍSTICAS DE LA VIVIENDA (MARCAR CON UNA X)</div>
            <div class="form-group">
                <label>TIPO DE VIVIENDA:</label>
                <select name="tipo_vivienda">
                    <option value="">SELECCIONE...</option>
                    <option value="CASA PROPIA" <?= (($estudio['tipo_vivienda'] ?? '') === 'CASA PROPIA') ? 'selected' : '' ?>>CASA PROPIA ( )</option>
                    <option value="CASA DE RENTA" <?= (($estudio['tipo_vivienda'] ?? '') === 'CASA DE RENTA') ? 'selected' : '' ?>>CASA DE RENTA ( )</option>
                    <option value="CASA PRESTADA" <?= (($estudio['tipo_vivienda'] ?? '') === 'CASA PRESTADA') ? 'selected' : '' ?>>CASA PRESTADA ( )</option>
                    <option value="POSADA" <?= (($estudio['tipo_vivienda'] ?? '') === 'POSADA') ? 'selected' : '' ?>>POSADA ( )</option>
                    <option value="CASA DE INTERÉS SOCIAL" <?= (($estudio['tipo_vivienda'] ?? '') === 'CASA DE INTERÉS SOCIAL') ? 'selected' : '' ?>>CASA DE INTERÉS SOCIAL ( )</option>
                </select>
            </div>
            <div class="grid-3" style="font-size: 11px;">
                <div>
                    <strong>MATERIAL DEL TECHO:</strong><br>
                    <label><input type="checkbox" name="techo_loza" value="1" <?= (($estudio['techo_loza'] ?? 0) == 1) ? 'checked' : '' ?>> LOZA ( )</label><br>
                    <label><input type="checkbox" name="techo_lamina" value="1" <?= (($estudio['techo_lamina'] ?? 0) == 1) ? 'checked' : '' ?>> LÁMINA ( )</label><br>
                    <label><input type="checkbox" name="techo_teja" value="1" <?= (($estudio['techo_teja'] ?? 0) == 1) ? 'checked' : '' ?>> TEJA ( )</label><br>
                    <label><input type="checkbox" name="techo_guano" value="1" <?= (($estudio['techo_guano'] ?? 0) == 1) ? 'checked' : '' ?>> GUANO ( )</label>
                </div>
                <div>
                    <strong>MATERIAL DEL PISO:</strong><br>
                    <label><input type="checkbox" name="piso_cemento_rustico" value="1" <?= (($estudio['piso_cemento_rustico'] ?? 0) == 1) ? 'checked' : '' ?>> CEMENTO RÚSTICO ( )</label><br>
                    <label><input type="checkbox" name="piso_tierra" value="1" <?= (($estudio['piso_tierra'] ?? 0) == 1) ? 'checked' : '' ?>> TIERRA ( )</label><br>
                    <label><input type="checkbox" name="piso_mosaico" value="1" <?= (($estudio['piso_mosaico'] ?? 0) == 1) ? 'checked' : '' ?>> MOSAICO ( )</label><br>
                    <label><input type="checkbox" name="piso_piso_fraguado" value="1" <?= (($estudio['piso_piso_fraguado'] ?? 0) == 1) ? 'checked' : '' ?>> PISO FRAGUADO ( )</label>
                </div>
                <div>
                    <strong>SERVICIOS CON LOS QUE CUENTA:</strong><br>
                    <label><input type="checkbox" name="servicio_agua_potable" value="1" <?= (($estudio['servicio_agua_potable'] ?? 0) == 1) ? 'checked' : '' ?>> AGUA POTABLE</label><br>
                    <label><input type="checkbox" name="servicio_fosa_septica" value="1" <?= (($estudio['servicio_fosa_septica'] ?? 0) == 1) ? 'checked' : '' ?>> FOSA SÉPTICA</label><br>
                    <label><input type="checkbox" name="servicio_tv_cable" value="1" <?= (($estudio['servicio_tv_cable'] ?? 0) == 1) ? 'checked' : '' ?>> TV CABLE</label><br>
                    <label><input type="checkbox" name="servicio_agua_pozo" value="1" <?= (($estudio['servicio_agua_pozo'] ?? 0) == 1) ? 'checked' : '' ?>> AGUA DE POZO</label><br>
                    <label><input type="checkbox" name="servicio_luz" value="1" <?= (($estudio['servicio_luz'] ?? 0) == 1) ? 'checked' : '' ?>> LUZ</label><br>
                    <label><input type="checkbox" name="servicio_drenaje" value="1" <?= (($estudio['servicio_drenaje'] ?? 0) == 1) ? 'checked' : '' ?>> DRENAJE</label><br>
                    <label><input type="checkbox" name="servicio_internet" value="1" <?= (($estudio['servicio_internet'] ?? 0) == 1) ? 'checked' : '' ?>> INTERNET</label>
                </div>
            </div>

            <div class="section-title">DIAGNÓSTICO SOCIAL</div>
            <div class="form-group">
                <textarea name="diagnostico_social" rows="4" placeholder="Escribir de manera técnica el contexto del paciente y el tipo de familia..." style="text-transform: uppercase;"><?= htmlspecialchars($estudio['diagnostico_social'] ?? '') ?></textarea>
            </div>

            <!-- FIRMAS OFICIALES -->
            <div class="grid-2" style="margin-top: 40px; text-align: center; font-size: 11px;">
                <div style="border-top: 1px solid #000; padding-top: 5px; margin: 0 30px;">
                    NOMBRE Y FIRMA DEL PACIENTE Y/O FAMILIAR RESPONSABLE
                </div>
                <div style="border-top: 1px solid #000; padding-top: 5px; margin: 0 30px;">
                    NOMBRE COMPLETO, CÉDULA PROFESIONAL Y FIRMA DEL TRABAJADOR SOCIAL
                </div>
            </div>

            <div class="btn-container form-control-view">
                <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir Informe Oficial</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Estudio</button>
            </div>
        </form>
    </div>

    <script>
    function cambiarEstudio(select) {
        if (select.value) {
            window.location.href = select.value;
        }
    }

    function procesarCurp(curp) {
        curp = curp.toUpperCase().trim();
        if (curp.length < 10) return;

        // 1. Género / Sexo (posición 10: H o M)
        if (curp.length >= 11) {
            let sexoChar = curp.charAt(10);
            let selectSexo = document.getElementById('selectSexo');
            if (sexoChar === 'H') selectSexo.value = 'MASCULINO';
            else if (sexoChar === 'M') selectSexo.value = 'FEMENINO';
        }

        // 2. Fecha de nacimiento y edad (posiciones 4 a 9: AAMMDD)
        if (curp.length >= 10) {
            let yy = curp.substr(4, 2);
            let mm = curp.substr(6, 2);
            let dd = curp.substr(8, 2);
            
            let anioActual = new Date().getFullYear() % 100;
            let siglo = (parseInt(yy) > anioActual) ? '19' : '20';
            let anioCompleto = siglo + yy;

            if (mm >= 1 && mm <= 12 && dd >= 1 && dd <= 31) {
                let fechaNac = `${anioCompleto}-${mm}-${dd}`;
                document.getElementById('inputFnac').value = fechaNac;

                let birthDate = new Date(fechaNac);
                let today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                let m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                if (age >= 0) {
                    document.getElementById('inputEdad').value = age;
                }
            }
        }

        // 3. Lugar de nacimiento / Estado (posiciones 11 y 12)
        if (curp.length >= 13) {
            let entidad = curp.substr(11, 2);
            const entidadesMap = {
                'AS':'AGUASCALIENTES', 'BC':'BAJA CALIFORNIA', 'BS':'BAJA CALIFORNIA SUR', 'CC':'CAMPECHE', 'CS':'CHIAPAS', 'CH':'CHIHUAHUA', 'CL':'COAHUILA', 'CM':'COLIMA', 'DF':'CIUDAD DE MÉXICO', 'DG':'DURANGO', 'GT':'GUANAJUATO', 'GR':'GUERRERO', 'HG':'HIDALGO', 'JC':'JALISCO', 'MC':'MÉXICO', 'MN':'MICHOACÁN', 'MS':'MORELOS', 'NT':'NAYARIT', 'NL':'NUEVO LEÓN', 'OC':'OAXACA', 'PL':'PUEBLA', 'QT':'QUERÉTARO', 'QR':'QUINTANA ROO', 'SP':'SAN LUIS POTOSÍ', 'SL':'SINALOA', 'SR':'SONORA', 'TC':'TABASCO', 'TS':'TAMAULIPAS', 'TL':'TLAXCALA', 'VZ':'VERACRUZ', 'YN':'YUCATÁN', 'ZS':'ZACATECAS'
            };
            if (entidadesMap[entidad]) {
                document.getElementById('inputEstado').value = entidadesMap[entidad];
            }
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        // Ejecutar procesamiento de CURP al cargar si ya tiene valor precargado
        const inputCurpCargada = document.getElementById('inputCurp');
        if (inputCurpCargada && inputCurpCargada.value.trim() !== '') {
            procesarCurp(inputCurpCargada.value);
        }

        const inputsTexto = document.querySelectorAll('input[type="text"], textarea');
        inputsTexto.forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });

        const inputsTelefono = document.querySelectorAll('input[name="telefono"], input[name="telefono_padre"], input[name="telefono_responsable"]');
        inputsTelefono.forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
            });
        });

        const inputsMoneda = document.querySelectorAll('.input-moneda:not([readonly])');

        function formatearNumero(valor) {
            let numero = valor.replace(/[^\d.]/g, '');
            let partes = numero.split('.');
            if (partes.length > 2) {
                partes = [partes[0], partes.slice(1).join('')];
            }
            if (partes[1] !== undefined) {
                partes[1] = partes[1].slice(0, 2);
            }
            let entero = partes[0];
            entero = entero.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return partes.length > 1 ? entero + '.' + partes[1] : entero;
        }

        function calcularTotales() {
            let ingresosTotales = 0;
            document.querySelectorAll('input[name^="ingreso_"]:not(#ingreso_total)').forEach(input => {
                let val = parseFloat(input.value.replace(/,/g, '')) || 0;
                ingresosTotales += val;
            });
            document.getElementById('ingreso_total').value = ingresosTotales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            let egresosTotales = 0;
            document.querySelectorAll('input[name^="egreso_"]:not(#egreso_total)').forEach(input => {
                let val = parseFloat(input.value.replace(/,/g, '')) || 0;
                egresosTotales += val;
            });
            document.getElementById('egreso_total').value = egresosTotales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        inputsMoneda.forEach(input => {
            input.addEventListener('input', function(e) {
                let cursorPosition = this.selectionStart;
                let originalLength = this.value.length;
                
                this.value = formatearNumero(this.value);
                
                let newLength = this.value.length;
                cursorPosition = cursorPosition + (newLength - originalLength);
                this.setSelectionRange(cursorPosition, cursorPosition);

                calcularTotales();
            });
        });

        calcularTotales();

        document.getElementById('formSocioeconomico').addEventListener('submit', function() {
            document.querySelectorAll('.input-moneda').forEach(input => {
                input.value = input.value.replace(/,/g, '');
            });
        });
    });
    </script>
</body>
</html>