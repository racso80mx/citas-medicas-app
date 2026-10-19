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

$error_msg = '';
$exito_msg = '';
$vista_duplicados = isset($_GET['duplicados']) && $_GET['duplicados'] === '1';

// ===================================================================
// 1.0 PROCESAMIENTO DE CAMBIO RÁPIDO DE ESTATUS A "BAJA"
// ===================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'cambiar_baja' && !empty($_GET['id'])) {
    $id_baja = intval($_GET['id']);
    try {
        $stmt_baja = $pdo->prepare("UPDATE pacientes SET estatus = 'Baja' WHERE id_paciente = :id");
        $stmt_baja->execute(['id' => $id_baja]);
        
        $redir = $vista_duplicados ? "archivo.php?duplicados=1&baja_exito=1" : "archivo.php?baja_exito=1";
        header("Location: " . $redir);
        exit();
    } catch (Exception $e) {
        $error_msg = "Error al dar de baja al paciente: " . $e->getMessage();
    }
}

// ===================================================================
// 1.1 PROCESAMIENTO DE ELIMINACIÓN MASIVA
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_duplicados') {
    $ids_a_eliminar = $_POST['ids_eliminacion'] ?? [];
    if (!empty($ids_a_eliminar) && is_array($ids_a_eliminar)) {
        try {
            $placeholders = implode(',', array_fill(0, count($ids_a_eliminar), '?'));
            $stmt_del = $pdo->prepare("DELETE FROM pacientes WHERE id_paciente IN ($placeholders)");
            $stmt_del->execute($ids_a_eliminar);
            
            $redir = $vista_duplicados ? "archivo.php?duplicados=1&eliminados=1" : "archivo.php?eliminados=1";
            header("Location: " . $redir);
            exit();
        } catch (Exception $e) {
            $error_msg = "Error al eliminar los registros seleccionados: " . $e->getMessage();
        }
    }
}

// ===================================================================
// 1.2 PROCESAMIENTO DE EDICIÓN DE PACIENTE
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_paciente') {
    $id_paciente      = trim($_POST['id_paciente'] ?? '');
    $expediente        = strtoupper(trim($_POST['expediente'] ?? ''));
    $nombre            = strtoupper(trim($_POST['nombre'] ?? ''));
    $apaterno          = strtoupper(trim($_POST['apaterno'] ?? ''));
    $amaterno          = strtoupper(trim($_POST['amaterno'] ?? ''));
    $curp              = strtoupper(trim($_POST['curp'] ?? ''));
    $fnacimiento       = trim($_POST['fnacimiento'] ?? '');
    $year_nacimiento   = trim($_POST['year_nacimiento'] ?? '');
    $edad              = intval($_POST['edad'] ?? 0);
    $edad_actual       = intval($_POST['edad_actual'] ?? $edad);
    $sexo              = strtoupper(trim($_POST['sexo'] ?? ''));
    $estado            = strtoupper(trim($_POST['estado'] ?? ''));
    $domicilio         = strtoupper(trim($_POST['domicilio'] ?? ''));
    $colonia           = strtoupper(trim($_POST['colonia'] ?? ''));
    $nombre_padre      = strtoupper(trim($_POST['nombre_padre'] ?? ''));
    $nombre_madre      = strtoupper(trim($_POST['nombre_madre'] ?? ''));
    $edad_padre        = trim($_POST['edad_padre'] ?? '');
    $edad_madre        = trim($_POST['edad_madre'] ?? '');
    $fecha_apertura    = trim($_POST['fecha_apertura'] ?? '');
    $municipio         = strtoupper(trim($_POST['municipio'] ?? ''));
    $telefono          = trim($_POST['telefono'] ?? '');
    $derechohabiencia  = strtoupper(trim($_POST['derechohabiencia'] ?? ''));
    $estatus           = trim($_POST['estatus'] ?? 'Vigente');

    if (empty($id_paciente)) {
        $error_msg = "ID de paciente no válido para actualización.";
    } elseif (empty($telefono) || !preg_match('/^\d{10}$/', $telefono)) {
        $error_msg = "El teléfono es obligatorio y debe contener exactamente 10 dígitos numéricos.";
    } elseif (empty($curp)) {
        $error_msg = "El CURP es obligatorio.";
    } else {
        try {
            $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp AND id_paciente <> :id");
            $stmt_val->execute(['curp' => $curp, 'id' => $id_paciente]);
            if ($stmt_val->fetchColumn() > 0) {
                $error_msg = "El CURP ingresado ya pertenece a otro registro en el sistema.";
            } else {
                $nombre_completo = trim("$nombre $apaterno $amaterno");
                $nombre_apellidos = trim("$nombre $apaterno $amaterno");
                if (empty($year_nacimiento) && !empty($fnacimiento)) {
                    $year_nacimiento = substr($fnacimiento, 0, 4);
                }

                $stmt_upd = $pdo->prepare("
                    UPDATE pacientes SET 
                        expediente = :exp, nombre = :nom, apaterno = :apat, amaterno = :amat, 
                        curp = :curp, fnacimiento = :fnac, year_nacimiento = :ynac, edad = :edad, 
                        edad_actual = :eact, sexo = :sexo, estado = :edo, domicilio = :dom, 
                        colonia = :col, nombre_padre = :npad, nombre_madre = :nmad, 
                        edad_padre = :epad, edad_madre = :emad, fecha_apertura = :fape, 
                        estatus = :est, municipio = :mun, telefono = :tel, 
                        nombre_completo = :ncomp, nombre_apellidos = :napat, derechohabiencia = :derech
                    WHERE id_paciente = :id_pac
                ");
                $stmt_upd->execute([
                    'exp'     => $expediente,
                    'nom'     => $nombre,
                    'apat'    => $apaterno,
                    'amat'    => $amaterno,
                    'curp'    => $curp,
                    'fnac'    => $fnacimiento,
                    'ynac'    => $year_nacimiento,
                    'edad'    => $edad,
                    'eact'    => $edad_actual,
                    'sexo'    => $sexo,
                    'edo'     => $estado,
                    'dom'     => $domicilio,
                    'col'     => $colonia,
                    'npad'    => $nombre_padre,
                    'nmad'    => $nombre_madre,
                    'epad'    => $edad_padre,
                    'emad'    => $edad_madre,
                    'fape'    => $fecha_apertura,
                    'est'     => $estatus,
                    'mun'     => $municipio,
                    'tel'     => $telefono,
                    'ncomp'   => $nombre_completo,
                    'napat'   => $nombre_apellidos,
                    'derech'  => $derechohabiencia,
                    'id_pac'  => $id_paciente
                ]);
                $redirect_url = $vista_duplicados ? "archivo.php?duplicados=1&editado=1" : "archivo.php?editado=1";
                header("Location: " . $redirect_url);
                exit();
            }
        } catch (Exception $e) {
            $error_msg = "Error al actualizar el paciente: " . $e->getMessage();
        }
    }
}

// ===================================================================
// 2. PROCESAMIENTO DE NUEVO PACIENTE (AJAX O POST)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'nuevo_paciente') {
    if (isset($_POST['validar_curp_ajax']) && $_POST['validar_curp_ajax'] === '1') {
        header('Content-Type: application/json');
        $curp_val = strtoupper(trim($_POST['curp'] ?? ''));
        $excluir_id = intval($_POST['excluir_id'] ?? 0);
        try {
            if ($excluir_id > 0) {
                $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp AND id_paciente <> :id");
                $stmt_val->execute(['curp' => $curp_val, 'id' => $excluir_id]);
            } else {
                $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp");
                $stmt_val->execute(['curp' => $curp_val]);
            }
            $existe = $stmt_val->fetchColumn() > 0;
            echo json_encode(['existe' => $existe]);
        } catch (Exception $e) {
            echo json_encode(['existe' => false]);
        }
        exit();
    }

    $expediente        = strtoupper(trim($_POST['expediente'] ?? ''));
    $nombre            = strtoupper(trim($_POST['nombre'] ?? ''));
    $apaterno          = strtoupper(trim($_POST['apaterno'] ?? ''));
    $amaterno          = strtoupper(trim($_POST['amaterno'] ?? ''));
    $curp              = strtoupper(trim($_POST['curp'] ?? ''));
    $fnacimiento       = trim($_POST['fnacimiento'] ?? '');
    $year_nacimiento   = trim($_POST['year_nacimiento'] ?? '');
    $edad              = intval($_POST['edad'] ?? 0);
    $edad_actual       = intval($_POST['edad_actual'] ?? $edad);
    $sexo              = strtoupper(trim($_POST['sexo'] ?? ''));
    $estado            = strtoupper(trim($_POST['estado'] ?? ''));
    $domicilio         = strtoupper(trim($_POST['domicilio'] ?? ''));
    $colonia           = strtoupper(trim($_POST['colonia'] ?? ''));
    $nombre_padre      = strtoupper(trim($_POST['nombre_padre'] ?? ''));
    $nombre_madre      = strtoupper(trim($_POST['nombre_madre'] ?? ''));
    $edad_padre        = trim($_POST['edad_padre'] ?? '');
    $edad_madre        = trim($_POST['edad_madre'] ?? '');
    $fecha_apertura    = trim($_POST['fecha_apertura'] ?? '');
    $municipio         = strtoupper(trim($_POST['municipio'] ?? ''));
    $telefono          = trim($_POST['telefono'] ?? '');
    $derechohabiencia  = strtoupper(trim($_POST['derechohabiencia'] ?? ''));
    $estatus           = trim($_POST['estatus'] ?? 'Vigente');

    if (empty($telefono) || !preg_match('/^\d{10}$/', $telefono)) {
        $error_msg = "El teléfono es obligatorio y debe contener exactamente 10 dígitos numéricos.";
    } elseif (empty($curp)) {
        $error_msg = "El CURP es obligatorio.";
    } else {
        try {
            $stmt_val = $pdo->prepare("SELECT COUNT(*) FROM pacientes WHERE curp = :curp");
            $stmt_val->execute(['curp' => $curp]);
            if ($stmt_val->fetchColumn() > 0) {
                $error_msg = "El CURP ingresado ya se encuentra registrado en el sistema.";
            } else {
                $nombre_completo = trim("$nombre $apaterno $amaterno");
                $nombre_apellidos = trim("$nombre $apaterno $amaterno");
                if (empty($year_nacimiento) && !empty($fnacimiento)) {
                    $year_nacimiento = substr($fnacimiento, 0, 4);
                }

                $stmt_ins = $pdo->prepare("
                    INSERT INTO pacientes (
                        expediente, nombre, apaterno, amaterno, curp, fnacimiento, year_nacimiento, 
                        edad, edad_actual, sexo, estado, domicilio, colonia, nombre_padre, nombre_madre, 
                        edad_padre, edad_madre, fecha_apertura, estatus, municipio, telefono, 
                        nombre_completo, nombre_apellidos, derechohabiencia, fecha_registro
                    ) VALUES (
                        :exp, :nom, :apat, :amat, :curp, :fnac, :ynac, 
                        :edad, :eact, :sexo, :edo, :dom, :col, :npad, :nmad, 
                        :epad, :emad, :fape, :est, :mun, :tel, 
                        :ncomp, :napat, :derech, CURRENT_TIMESTAMP
                    )
                ");
                $stmt_ins->execute([
                    'exp'     => $expediente,
                    'nom'     => $nombre,
                    'apat'    => $apaterno,
                    'amat'    => $amaterno,
                    'curp'    => $curp,
                    'fnac'    => $fnacimiento,
                    'ynac'    => $year_nacimiento,
                    'edad'    => $edad,
                    'eact'    => $edad_actual,
                    'sexo'    => $sexo,
                    'edo'     => $estado,
                    'dom'     => $domicilio,
                    'col'     => $colonia,
                    'npad'    => $nombre_padre,
                    'nmad'    => $nombre_madre,
                    'epad'    => $edad_padre,
                    'emad'    => $edad_madre,
                    'fape'    => $fecha_apertura,
                    'est'     => $estatus,
                    'mun'     => $municipio,
                    'tel'     => $telefono,
                    'ncomp'   => $nombre_completo,
                    'napat'   => $nombre_apellidos,
                    'derech'  => $derechohabiencia
                ]);
                header("Location: archivo.php?exito=1");
                exit();
            }
        } catch (Exception $e) {
            $error_msg = "Error al guardar el paciente: " . $e->getMessage();
        }
    }
}

// ===================================================================
// 3. RECEPCIÓN DE PARÁMETROS Y LÍMITE
// ===================================================================
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$search = strtoupper(trim($_GET['search'] ?? ''));
$search_dup = strtoupper(trim($_GET['search_dup'] ?? ''));
$estatus_filtro = $_GET['estatus'] ?? '';
$accion = $_GET['accion'] ?? '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
if (!in_array($limit, [25, 50, 100, 500])) {
    $limit = 25;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$sort_by = $_GET['sort_by'] ?? 'id_paciente';
$order = $_GET['order'] ?? 'DESC';

$valid_columns = ['id_paciente', 'expediente', 'nombre', 'apaterno', 'amaterno', 'curp', 'sexo', 'edad', 'telefono', 'estatus', 'fecha_registro'];
if (!in_array($sort_by, $valid_columns)) {
    $sort_by = 'id_paciente';
}

$order = ($order === 'ASC') ? 'ASC' : 'DESC';
$next_order = ($order === 'ASC') ? 'DESC' : 'ASC';

// ===================================================================
// 4. CONSTRUCCIÓN DINÁMICA DE LA CONSULTA
// ===================================================================
$where_clauses = [];
$params = [];

if (!empty($desde) && !empty($hasta)) {
    $where_clauses[] = "fecha_registro BETWEEN :desde AND :hasta";
    $params['desde'] = $desde;
    $params['hasta'] = $hasta;
}

if (!empty($search)) {
    $search_term = '%' . trim($search) . '%';
    $where_clauses[] = "(
        curp ILIKE :search_curp OR 
        expediente ILIKE :search_exp OR 
        nombre ILIKE :search_nom OR 
        apaterno ILIKE :search_apat OR 
        amaterno ILIKE :search_amat OR
        CONCAT_WS(' ', COALESCE(nombre,''), COALESCE(apaterno,''), COALESCE(amaterno,'')) ILIKE :search_full
    )";
    $params['search_curp']  = $search_term;
    $params['search_exp']   = $search_term;
    $params['search_nom']   = $search_term;
    $params['search_apat']  = $search_term;
    $params['search_amat']  = $search_term;
    $params['search_full']  = $search_term;
}

if ($estatus_filtro === 'Sin_Expediente') {
    $where_clauses[] = "(expediente IS NULL OR TRIM(expediente) = '' OR UPPER(TRIM(expediente)) = 'NULL' OR UPPER(TRIM(expediente)) LIKE 'SE%' OR UPPER(TRIM(expediente)) LIKE 'EX%')";
} elseif (!empty($estatus_filtro)) {
    $where_clauses[] = "TRIM(estatus) ILIKE :estatus";
    $params['estatus'] = "%$estatus_filtro%";
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// ===================================================================
// 5. EJECUCIÓN DE CONSULTAS
// ===================================================================
try {
    if ($accion === 'exportar_excel') {
        $sql_export = "SELECT id_paciente, expediente, nombre, apaterno, amaterno, curp, sexo, edad, telefono, municipio, estatus, fecha_registro FROM pacientes $where_sql ORDER BY $sort_by $order";
        $stmt_export = $pdo->prepare($sql_export);
        $stmt_export->execute($params);
        $datos_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=modulo_archivo_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['ID', 'Expediente', 'Nombre', 'Apellido Paterno', 'Apellido Materno', 'CURP', 'Sexo', 'Edad', 'Teléfono', 'Municipio', 'Estatus', 'Fecha Registro']);
        
        foreach ($datos_export as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    }

    $lista_duplicados = [];
    if ($vista_duplicados) {
        $sort_by_dup = $_GET['sort_by'] ?? 'id_paciente';
        $valid_columns_dup = ['id_paciente', 'expediente', 'nombre', 'curp', 'telefono', 'estatus', 'fecha_registro', 'total_citas'];
        if (!in_array($sort_by_dup, $valid_columns_dup)) {
            $sort_by_dup = 'id_paciente';
        }
        $order_dup = ($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $where_dup_search = "";
        $params_dup = [];
        if (!empty($search_dup)) {
            $where_dup_search = " WHERE (
                sub_dup.curp ILIKE :s_curp OR 
                sub_dup.expediente ILIKE :s_exp OR 
                sub_dup.nombre ILIKE :s_nom OR 
                sub_dup.apaterno ILIKE :s_apat OR 
                sub_dup.amaterno ILIKE :s_amat OR
                CONCAT_WS(' ', COALESCE(sub_dup.nombre,''), COALESCE(sub_dup.apaterno,''), COALESCE(sub_dup.amaterno,'')) ILIKE :s_full
            )";
            $term_dup = '%' . $search_dup . '%';
            $params_dup = [
                's_curp' => $term_dup,
                's_exp'  => $term_dup,
                's_nom'  => $term_dup,
                's_apat' => $term_dup,
                's_amat' => $term_dup,
                's_full' => $term_dup
            ];
        }

        $sql_dup = "
            SELECT * FROM (
                SELECT p.*, COUNT(c.id_cita) as total_citas 
                FROM pacientes p
                INNER JOIN (
                    SELECT nombre, apaterno, amaterno FROM pacientes 
                    WHERE nombre IS NOT NULL AND TRIM(nombre) <> ''
                      AND apaterno IS NOT NULL AND TRIM(apaterno) <> ''
                      AND amaterno IS NOT NULL AND TRIM(amaterno) <> ''
                    GROUP BY nombre, apaterno, amaterno HAVING COUNT(*) > 1
                ) dup_nom ON p.nombre = dup_nom.nombre AND p.apaterno = dup_nom.apaterno AND p.amaterno = dup_nom.amaterno
                LEFT JOIN citas c ON c.id_paciente::text = p.id_paciente::text AND (c.estatus IS NULL OR TRIM(UPPER(c.estatus)) != 'CANCELADA')
                GROUP BY p.id_paciente
            ) AS sub_dup
            $where_dup_search
            ORDER BY sub_dup.$sort_by_dup $order_dup
        ";
        $stmt_dup = $pdo->prepare($sql_dup);
        $stmt_dup->execute($params_dup);
        $lista_duplicados = $stmt_dup->fetchAll(PDO::FETCH_ASSOC);
    }

    $sql_count = "SELECT COUNT(*) FROM pacientes $where_sql";
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    if ($total_pages == 0) $total_pages = 1;

    $sql = "SELECT * FROM pacientes $where_sql ORDER BY $sort_by $order LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $val) {
        $stmt->bindValue(":$key", $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt_todas_clinicas = $pdo->query("SELECT id_clinica, nombre_clinica, tipo_clinica FROM clinicas ORDER BY nombre_clinica ASC");
    $todas_clinicas = $stmt_todas_clinicas->fetchAll(PDO::FETCH_ASSOC);
    $tipos_clinicas = array_unique(array_column($todas_clinicas, 'tipo_clinica'));
    sort($tipos_clinicas);

    $stmt_derechohabiencias = $pdo->query("SELECT nombre_derechohabiencia FROM derechohabiencia ORDER BY nombre_derechohabiencia ASC");
    $lista_derechohabiencias = $stmt_derechohabiencias->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    $pacientes = [];
    $lista_duplicados = [];
    $total_pages = 1;
    $todas_clinicas = [];
    $tipos_clinicas = [];
    $lista_derechohabiencias = [];
}

function buildUrl($params_to_change) {
    $current_params = $_GET;
    foreach ($params_to_change as $key => $value) {
        if ($value === null) {
            unset($current_params[$key]);
        } else {
            $current_params[$key] = $value;
        }
    }
    return '?' . http_build_query($current_params);
}

function formatearFechaDMY($fechaStr) {
    if (empty($fechaStr)) return '';
    $timestamp = strtotime($fechaStr);
    if ($timestamp !== false) {
        return date('d/m/Y', $timestamp);
    }
    return $fechaStr;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMSS-BIENESTAR | Módulo de Archivo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --imss-verde: #006847; --imss-dorado: #9f2241; --imss-gris-fondo: #f4f6f8; 
            --borde: #dfe3e8; --excel-verde: #217346; 
            --btn-azul: #1976d2; --btn-naranja: #f57c00; --btn-rojo: #d32f2f;
        }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .container { max-width: 1400px; margin: 25px auto; padding: 0 20px; }
        
        .control-panel { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid var(--imss-verde); display: flex; flex-direction: column; gap: 12px; }
        
        input[type="text"], input[type="date"], input[type="number"], select { padding: 5px 8px; border: 1px solid var(--borde); border-radius: 4px; font-size: 12px; outline: none; box-sizing: border-box; width: 100%; height: 30px; text-transform: uppercase; }
        input[type="text"]:focus, input[type="date"]:focus, input[type="number"]:focus, select:focus { border-color: var(--imss-verde); }
        
        .btn { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px; display: inline-flex; align-items: center; gap: 5px; color: white; text-decoration: none; transition: opacity 0.2s;}
        .btn:hover { opacity: 0.9; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-excel { background-color: var(--excel-verde); }
        .btn-secondary { background-color: #637381; }
        .btn-warning { background-color: #d97706; }
        
        .btn-estatus { padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; border: 1px solid var(--borde); background: #fff; color: #637381; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
        .btn-estatus.active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); }
        
        .btn-icon { width: 32px; height: 32px; border: none; border-radius: 6px; cursor: pointer; color: white; display: inline-flex; justify-content: center; align-items: center; margin: 0 2px; text-decoration: none; }
        .btn-blue { background-color: var(--btn-azul); }
        .btn-orange { background-color: var(--btn-naranja); }
        .btn-red { background-color: var(--btn-rojo); }

        .import-panel-toggle { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: bold; color: var(--imss-verde); cursor: pointer; }
        .import-box { display: none; background: #f8f9fa; padding: 10px 15px; border-radius: 6px; border: 1px dashed var(--imss-verde); width: 100%; align-items: center; gap: 15px; flex-wrap: wrap; box-sizing: border-box; margin-top: 5px; }
        .import-box.show { display: flex; }

        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background-color: #f1f3f5; text-align: left; padding: 12px 10px; border-bottom: 2px solid var(--borde); white-space: nowrap; }
        th a { color: #637381; text-decoration: none; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        th a:hover { color: var(--imss-verde); }
        th i { font-size: 11px; opacity: 0.5; }
        th a.active i { opacity: 1; color: var(--imss-verde); }
        td { padding: 10px; border-bottom: 1px solid var(--borde); white-space: nowrap; vertical-align: middle;}
        tr:hover { background-color: #f8f9fa; }
        .acciones-col { text-align: center; }

        .pagination { display: flex; justify-content: center; align-items: center; gap: 5px; margin-top: 20px; padding: 10px; }
        .pagination a, .pagination span { padding: 6px 12px; border-radius: 4px; font-size: 13px; text-decoration: none; border: 1px solid var(--borde); background: white; color: #1c252e; }
        .pagination a:hover { background: #f1f3f5; }
        .pagination .active { background-color: var(--imss-verde); color: white; border-color: var(--imss-verde); }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; width: 950px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.25); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column; }
        .modal-header { background: var(--imss-verde); color: white; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
        .modal-header h3 { margin: 0; font-size: 15px; }
        .modal-header-actions { display: flex; align-items: center; gap: 10px; }
        .close-btn { background: none; border: none; color: white; font-size: 18px; cursor: pointer; }
        .modal-body { padding: 20px; overflow-y: auto; max-height: calc(90vh - 70px); flex: 1 1 auto; }
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .form-group { margin-bottom: 8px; }
        .form-group label { display: block; margin-bottom: 2px; font-weight: bold; font-size: 11px; color: #637381; }
    </style>
</head>
<body>
    <div class="navbar">
        <div><i class="fa-solid fa-folder-open"></i> <strong>Módulo de Archivo</strong></div>
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver al Dashboard</a>
    </div>

    <div class="container">
        <?php if (!empty($error_msg)): ?>
            <div style="background: #ffebee; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['exito'])): ?>
            <div style="background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> Paciente registrado exitosamente.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['editado'])): ?>
            <div style="background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> Paciente actualizado correctamente.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['baja_exito'])): ?>
            <div style="background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> El estatus del paciente ha sido actualizado a "Baja".
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['eliminados'])): ?>
            <div style="background: #e4f8dd; color: var(--imss-verde); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> Registros seleccionados eliminados correctamente.
            </div>
        <?php endif; ?>

        <div class="control-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                <label class="import-panel-toggle">
                    <input type="checkbox" id="toggleImport" onchange="toggleImportBox()" style="cursor: pointer;"> 
                    <i class="fa-solid fa-file-excel"></i> HERRAMIENTAS DE IMPORTACIÓN
                </label>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php if ($vista_duplicados): ?>
                        <a href="archivo.php" class="btn btn-secondary">
                            <i class="fa-solid fa-arrow-left"></i> VOLVER A LISTADO GENERAL
                        </a>
                    <?php else: ?>
                        <a href="archivo.php?duplicados=1" class="btn btn-warning">
                            <i class="fa-solid fa-clone"></i> EXPEDIENTES DUPLICADOS
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevoPaciente()">
                        <i class="fa-solid fa-user-plus"></i> NUEVO PACIENTE +
                    </button>
                    <a href="<?= buildUrl(['accion' => 'exportar_excel']) ?>" class="btn btn-excel">
                        <i class="fa-solid fa-file-arrow-down"></i> DESCARGAR EXCEL
                    </a>
                </div>
            </div>

            <div id="importBox" class="import-box">
                <a href="descargar_plantilla_pacientes.php" class="btn btn-excel"><i class="fa-solid fa-file-arrow-down"></i> Descargar Plantilla</a>
                <form action="importar_pacientes.php" method="POST" enctype="multipart/form-data" style="display: inline-flex; gap: 8px; align-items: center; margin: 0;">
                    <input type="file" name="archivo_excel" accept=".xlsx, .xls, .csv" required style="font-size: 12px;">
                    <button type="submit" class="btn btn-excel"><i class="fa-solid fa-upload"></i> Subir a BD</button>
                </form>
            </div>

            <?php if (!$vista_duplicados): ?>
            <form id="searchForm" method="GET" action="archivo.php" style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 12px; margin-top: 5px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; flex: 1;">
                    <div style="position: relative; width: 280px; display: flex; gap: 5px;">
                        <div style="position: relative; flex: 1;">
                            <i class="fa-solid fa-search" style="position: absolute; left: 9px; top: 10px; color: #637381; font-size: 12px; pointer-events: none;"></i>
                            <input type="text" id="searchInput" name="search" placeholder="NOMBRE, CURP, EXPEDIENTE..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding-left: 28px; padding-right: <?= !empty($search) ? '28px' : '8px' ?>; text-transform: uppercase;" autocomplete="off">
                            <?php if (!empty($search)): ?>
                                <a href="archivo.php" title="Limpiar búsqueda" style="position: absolute; right: 8px; top: 8px; color: #637381; text-decoration: none; font-size: 12px; background: #e2e8f0; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-xmark" style="font-size: 10px;"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 30px; padding: 0 10px;" title="Buscar">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </div>

                    <input type="hidden" name="estatus" id="inputEstatus" value="<?= htmlspecialchars($estatus_filtro) ?>">
                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="btn-estatus <?= $estatus_filtro === 'Vigente' ? 'active' : '' ?>" onclick="filtrarEstatus('Vigente')">
                            <i class="fa-solid fa-check-circle"></i> VIGENTES
                        </button>
                        <button type="button" class="btn-estatus <?= $estatus_filtro === 'Baja' ? 'active' : '' ?>" onclick="filtrarEstatus('Baja')">
                            <i class="fa-solid fa-ban"></i> DE BAJA
                        </button>
                        <button type="button" class="btn-estatus <?= $estatus_filtro === 'Sin_Expediente' ? 'active' : '' ?>" onclick="filtrarEstatus('Sin_Expediente')">
                            <i class="fa-solid fa-file-circle-xmark"></i> SIN EXPEDIENTE
                        </button>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #637381;">
                    <span>Mostrar:</span>
                    <select name="limit" onchange="document.getElementById('searchForm').submit()" style="padding: 5px; width: auto; height: 30px;">
                        <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    <span>registros</span>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($vista_duplicados): ?>
            <!-- VISTA DE DUPLICADOS -->
            <div style="background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h3 style="margin: 0; color: var(--imss-verde);"><i class="fa-solid fa-clone"></i> GESTIÓN DUPLICADOS</h3>
                    
                    <!-- BARRA DE BÚSQUEDA ESPECÍFICA PARA DUPLICADOS -->
                    <form method="GET" action="archivo.php" style="display: flex; gap: 5px; align-items: center; margin: 0;">
                        <input type="hidden" name="duplicados" value="1">
                        <div style="position: relative; width: 280px;">
                            <i class="fa-solid fa-search" style="position: absolute; left: 9px; top: 10px; color: #637381; font-size: 12px; pointer-events: none;"></i>
                            <input type="text" name="search_dup" placeholder="BUSCAR EN DUPLICADOS..." value="<?php echo htmlspecialchars($search_dup); ?>" style="width: 100%; padding-left: 28px; padding-right: <?= !empty($search_dup) ? '28px' : '8px' ?>; text-transform: uppercase;" autocomplete="off">
                            <?php if (!empty($search_dup)): ?>
                                <a href="archivo.php?duplicados=1" title="Limpiar búsqueda" style="position: absolute; right: 8px; top: 8px; color: #637381; text-decoration: none; font-size: 12px; background: #e2e8f0; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-xmark" style="font-size: 10px;"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 30px; padding: 0 10px;" title="Buscar">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </form>

                    <span style="font-size: 13px; color: #637381;">TOTAL ENCONTRADOS: <?= count($lista_duplicados) ?> REGISTROS</span>
                </div>

                <?php if (count($lista_duplicados) > 0): ?>
                    <form action="archivo.php?duplicados=1" method="POST" onsubmit="return confirm('¿Está seguro de eliminar los registros seleccionados?');">
                        <input type="hidden" name="accion" value="eliminar_duplicados">
                        <div style="margin-bottom: 15px; display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-red"><i class="fa-solid fa-trash"></i> ELIMINAR SELECCIONADOS</button>
                            <button type="button" class="btn btn-secondary" onclick="seleccionarTodos(true)">SELECCIONAR TODOS</button>
                            <button type="button" class="btn btn-secondary" onclick="seleccionarTodos(false)">DESELECCIONAR TODOS</button>
                        </div>

                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="text-align: center; width: 40px;"><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'id_paciente', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'id_paciente' ? 'active' : '' ?>">
                                                ID <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='id_paciente' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='id_paciente' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'expediente', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'expediente' ? 'active' : '' ?>">
                                                Expediente <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='expediente' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='expediente' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'nombre', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'nombre' ? 'active' : '' ?>">
                                                Nombre Completo <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='nombre' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='nombre' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'curp', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'curp' ? 'active' : '' ?>">
                                                CURP <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='curp' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='curp' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'telefono', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'telefono' ? 'active' : '' ?>">
                                                Teléfono <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='telefono' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='telefono' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'total_citas', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'total_citas' ? 'active' : '' ?>">
                                                Total Citas <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='total_citas' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='total_citas' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'estatus', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'estatus' ? 'active' : '' ?>">
                                                Estatus <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='estatus' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='estatus' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?= buildUrl(['duplicados' => 1, 'sort_by' => 'fecha_registro', 'order' => $next_order]) ?>" class="<?= ($sort_by_dup ?? '') == 'fecha_registro' ? 'active' : '' ?>">
                                                Fecha Creación <span><i class="fa-solid fa-arrow-up-long" style="color: <?= (($sort_by_dup ?? '')=='fecha_registro' && $order_dup=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= (($sort_by_dup ?? '')=='fecha_registro' && $order_dup=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                            </a>
                                        </th>
                                        <th class="acciones-col">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lista_duplicados as $p): 
                                        $id_paciente = htmlspecialchars($p['id_paciente'] ?? $p['id'] ?? '');
                                    ?>
                                        <tr>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="ids_eliminacion[]" value="<?= $id_paciente ?>" class="checkbox-dup">
                                            </td>
                                            <td><?= $id_paciente ?></td>
                                            <td><?= htmlspecialchars($p['expediente'] ?? 'S/N') ?></td>
                                            <td><?= htmlspecialchars(trim(($p['nombre'] ?? '') . ' ' . ($p['apaterno'] ?? '') . ' ' . ($p['amaterno'] ?? ''))) ?></td>
                                            <td><strong><?= htmlspecialchars($p['curp'] ?? 'N/D') ?></strong></td>
                                            <td><?= htmlspecialchars($p['telefono'] ?? '') ?></td>
                                            <td style="text-align: center;">
                                                <span style="background: #eef2f5; padding: 3px 8px; border-radius: 4px; font-weight: bold; color: var(--imss-verde);">
                                                    <?= intval($p['total_citas'] ?? 0) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($p['estatus'] ?? '') ?></td>
                                            <td><?= htmlspecialchars(formatearFechaDMY($p['fecha_registro'] ?? '')) ?></td>
                                            <td class="acciones-col">
                                                <button type="button" class="btn-icon btn-orange" onclick='abrirModalEditarPaciente(<?= json_encode($p) ?>)' title="Ver / Editar Información">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <a href="archivo.php?duplicados=1&accion=cambiar_baja&id=<?= $id_paciente ?>" class="btn-icon btn-warning" onclick="return confirm('¿Desea cambiar el estatus de este paciente a Baja?');" title="Cambiar a Baja">
                                                    <i class="fa-solid fa-ban"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #637381;">
                        <i class="fa-solid fa-circle-check" style="font-size: 40px; color: var(--imss-verde); margin-bottom: 10px;"></i>
                        <p style="font-size: 15px; font-weight: bold; margin: 0;">No se encontraron registros duplicados con nombre y apellidos exactos en el sistema.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- VISTA GENERAL DE PACIENTES -->
            <form action="archivo.php" method="POST" onsubmit="return confirm('¿Está seguro de eliminar todos los pacientes seleccionados?');">
                <input type="hidden" name="accion" value="eliminar_duplicados">

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="<?= buildUrl(['sort_by' => 'id_paciente', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='id_paciente' ? 'active' : '' ?>">
                                        ID <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='id_paciente' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='id_paciente' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= buildUrl(['sort_by' => 'expediente', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='expediente' ? 'active' : '' ?>">
                                        Expediente <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='expediente' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='expediente' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= buildUrl(['sort_by' => 'nombre', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='nombre' ? 'active' : '' ?>">
                                        Nombre <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='nombre' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='nombre' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= buildUrl(['sort_by' => 'apaterno', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='apaterno' ? 'active' : '' ?>">
                                        Apellido Paterno <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='apaterno' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='apaterno' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= buildUrl(['sort_by' => 'amaterno', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='amaterno' ? 'active' : '' ?>">
                                        Apellido Materno <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='amaterno' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='amaterno' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= buildUrl(['sort_by' => 'curp', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='curp' ? 'active' : '' ?>">
                                        CURP <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='curp' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='curp' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                    </a>
                                </th>
                                <th>Sexo</th>
                                <th>Teléfono</th>
                                <th>Estatus</th>
                                <th>
                                    <a href="<?= buildUrl(['sort_by' => 'fecha_registro', 'order' => $next_order, 'page' => 1]) ?>" class="<?= $sort_by=='fecha_registro' ? 'active' : '' ?>">
                                        F.Creación <span><i class="fa-solid fa-arrow-up-long" style="color: <?= ($sort_by=='fecha_registro' && $order=='ASC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i><i class="fa-solid fa-arrow-down-long" style="color: <?= ($sort_by=='fecha_registro' && $order=='DESC') ? 'var(--imss-verde)' : '#ccc' ?>;"></i></span>
                                    </a>
                                </th>
                                <th class="acciones-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($pacientes) > 0): ?>
                                <?php foreach ($pacientes as $p): 
                                    $id_paciente = htmlspecialchars($p['id_paciente'] ?? $p['id'] ?? '');
                                    $nombre_completo_modal = htmlspecialchars(trim(($p['nombre'] ?? '') . ' ' . ($p['apaterno'] ?? '') . ' ' . ($p['amaterno'] ?? '')));
                                ?>
                                    <tr>
                                        <td><?php echo $id_paciente; ?></td>
                                        <td><strong><?php echo htmlspecialchars(str_replace('"', '', $p['expediente'] ?? '')); ?></strong></td>
                                        <td><?php echo htmlspecialchars(str_replace('"', '', $p['nombre'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('"', '', $p['apaterno'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('"', '', $p['amaterno'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('"', '', $p['curp'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('"', '', $p['sexo'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('"', '', $p['telefono'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('"', '', $p['estatus'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(formatearFechaDMY(str_replace('"', '', $p['fecha_registro'] ?? ''))); ?></td>
                                        <td class="acciones-col">
                                            <button type="button" class="btn-icon btn-blue" onclick="abrirModalCita('<?= $id_paciente ?>', '<?= addslashes($nombre_completo_modal) ?>')" title="Asignar Cita">
                                                <i class="fa-solid fa-calendar-days"></i>
                                            </button>
                                            <button type="button" class="btn-icon btn-orange" onclick='abrirModalEditarPaciente(<?= json_encode($p) ?>)' title="Editar">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <a href="archivo.php?accion=cambiar_baja&id=<?= $id_paciente ?>" class="btn-icon btn-warning" onclick="return confirm('¿Desea cambiar el estatus de este paciente a Baja?');" title="Cambiar a Baja">
                                                <i class="fa-solid fa-ban"></i>
                                            </a>
                                            <button type="button" class="btn-icon btn-red" onclick="eliminarPaciente('<?= $id_paciente ?>')" title="Eliminar">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="11" style="text-align: center; padding: 30px; color: #637381;">No se encontraron registros de pacientes.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= buildUrl(['page' => $page - 1]) ?>"><i class="fa-solid fa-chevron-left"></i> Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= buildUrl(['page' => $i]) ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?= buildUrl(['page' => $page + 1]) ?>">Siguiente <i class="fa-solid fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- MODAL PARA NUEVO / EDITAR PACIENTE -->
    <div id="modalNuevoPaciente" class="modal-overlay">
        <div class="modal-content">
            <form action="archivo.php<?= $vista_duplicados ? '?duplicados=1' : '' ?>" method="POST" id="formNuevoPaciente">
                <input type="hidden" name="accion" id="inputAccionForm" value="nuevo_paciente">
                <input type="hidden" name="id_paciente" id="inputEditIdPaciente" value="">
                
                <div class="modal-header">
                    <h3 id="modalTitulo"><i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente</h3>
                    <div class="modal-header-actions">
                        <button type="submit" class="btn btn-primary" id="btnGuardarPaciente" style="background-color: #217346; height: 30px;"><i class="fa-solid fa-save"></i> Guardar Paciente</button>
                        <button type="button" class="btn btn-secondary" onclick="cerrarModalNuevoPaciente()" style="height: 30px;">Cerrar</button>
                        <button type="button" class="close-btn" onclick="cerrarModalNuevoPaciente()"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>

                <div class="modal-body">
                    <div id="curpErrorMsg" style="display:none; background:#ffebee; color:#c62828; padding:8px; border-radius:4px; margin-bottom:10px; font-size:12px; font-weight:bold;">
                        <i class="fa-solid fa-triangle-exclamation"></i> El CURP ingresado ya existe en la base de datos de pacientes.
                    </div>

                    <div class="form-grid-3">
                        <div class="form-group">
                            <label>Expediente:</label>
                            <input type="text" name="expediente" id="inputEditExp" required>
                        </div>
                        <div class="form-group">
                            <label>CURP (Valida al salir):</label>
                            <input type="text" name="curp" id="inputCurp" maxlength="18" onblur="validarCurpBlur(this.value)" style="text-transform: uppercase;" required>
                        </div>
                        <div class="form-group">
                            <label>Nombre:</label>
                            <input type="text" name="nombre" id="inputEditNombre" required>
                        </div>
                        <div class="form-group">
                            <label>Apellido Paterno:</label>
                            <input type="text" name="apaterno" id="inputEditApat" required>
                        </div>
                        <div class="form-group">
                            <label>Apellido Materno:</label>
                            <input type="text" name="amaterno" id="inputEditAmat">
                        </div>
                        <div class="form-group">
                            <label>Fecha de Nacimiento:</label>
                            <input type="date" name="fnacimiento" id="inputFnac">
                        </div>
                        <div class="form-group">
                            <label>Año de Nacimiento:</label>
                            <input type="text" name="year_nacimiento" id="inputYearNac" placeholder="Ej. 1980">
                        </div>
                        <div class="form-group">
                            <label>Edad:</label>
                            <input type="number" name="edad" id="inputEdad">
                        </div>
                        <div class="form-group">
                            <label>Edad Actual:</label>
                            <input type="number" name="edad_actual" id="inputEdadActual">
                        </div>
                        <div class="form-group">
                            <label>Sexo:</label>
                            <select name="sexo" id="selectSexo">
                                <option value="">Seleccione...</option>
                                <option value="HOMBRE">HOMBRE</option>
                                <option value="MUJER">MUJER</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estado de Nacimiento:</label>
                            <input type="text" name="estado" id="inputEstado">
                        </div>
                        <div class="form-group">
                            <label>Teléfono (10 dígitos):</label>
                            <input type="text" name="telefono" id="inputEditTel" maxlength="10" pattern="\d{10}" title="Exactamente 10 dígitos" placeholder="Ej. 9931234567" required>
                        </div>
                        <div class="form-group">
                            <label>Municipio:</label>
                            <input type="text" name="municipio" id="inputEditMunicipio" placeholder="EJ. CENTRO">
                        </div>
                        <div class="form-group">
                            <label>Domicilio:</label>
                            <input type="text" name="domicilio" id="inputEditDom">
                        </div>
                        <div class="form-group">
                            <label>Colonia:</label>
                            <input type="text" name="colonia" id="inputEditCol">
                        </div>
                        <div class="form-group">
                            <label>Nombre del Padre:</label>
                            <input type="text" name="nombre_padre" id="inputEditNpad">
                        </div>
                        <div class="form-group">
                            <label>Edad del Padre:</label>
                            <input type="text" name="edad_padre" id="inputEditEpad">
                        </div>
                        <div class="form-group">
                            <label>Nombre de la Madre:</label>
                            <input type="text" name="nombre_madre" id="inputEditNmad">
                        </div>
                        <div class="form-group">
                            <label>Edad de la Madre:</label>
                            <input type="text" name="edad_madre" id="inputEditEmad">
                        </div>
                        <div class="form-group">
                            <label>Fecha de Apertura:</label>
                            <input type="date" name="fecha_apertura" id="inputEditFape">
                        </div>

                        <div class="form-group">
                            <label>Derechohabiencia:</label>
                            <select name="derechohabiencia" id="inputEditDerech">
                                <option value="">Seleccione opción...</option>
                                <?php foreach ($lista_derechohabiencias as $der): ?>
                                    <option value="<?= htmlspecialchars($der) ?>"><?= htmlspecialchars($der) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estatus:</label>
                            <select name="estatus" id="inputEditEst">
                                <option value="Vigente">Vigente</option>
                                <option value="Baja">Baja</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para Asignar Cita -->
    <div id="modalCita" class="modal-overlay">
        <div class="modal-content" style="width: 480px; max-height: 85vh;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-calendar-check"></i> Asignar Cita Médica</h3>
                <button class="close-btn" onclick="cerrarModalCita()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="procesar_cita.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="cita_paciente_id" name="paciente_id">
                    
                    <div class="form-group">
                        <label>Paciente:</label>
                        <input type="text" id="cita_paciente_nombre" readonly style="background-color: #f1f3f5; border: none;">
                    </div>
                    
                    <div class="form-group">
                        <label>Tipo de Clínica:</label>
                        <select id="select_tipo_clinica" name="tipo_clinica" required onchange="filtrarClinicasPorTipo()">
                            <option value="">Seleccione tipo de clínica...</option>
                            <?php foreach ($tipos_clinicas as $tipo): ?>
                                <option value="<?= htmlspecialchars($tipo) ?>"><?= htmlspecialchars($tipo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Clínica:</label>
                        <select id="select_clinica" name="clinica_id" required onchange="cargarHorariosDisponibles()">
                            <option value="">Primero seleccione tipo de clínica...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Fecha de la Cita:</label>
                        <input type="date" id="input_fecha" name="fecha_cita" required onchange="cargarHorariosDisponibles()">
                    </div>

                    <div class="form-group">
                        <label>Horarios Disponibles:</label>
                        <select id="select_hora" name="hora_cita" required>
                            <option value="">Primero seleccione clínica y fecha...</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Observaciones (Opcional):</label>
                        <input type="text" name="observaciones" placeholder="Ej. Presentar análisis previos">
                    </div>
                </div>
                <div class="modal-footer" style="padding: 10px 20px; background: #f1f3f5; display: flex; justify-content: space-between; border-top: 1px solid var(--borde);">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalCita()">Cerrar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Cita</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        const todasLasClinicas = <?= json_encode($todas_clinicas) ?>;

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
            
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('searchForm').submit();
                }
            });
        }

        document.addEventListener('input', function (e) {
            if (e.target && e.target.tagName === 'INPUT' && e.target.type === 'text') {
                e.target.value = e.target.value.toUpperCase();
            }
        });

        function toggleAll(source) {
            checkboxes = document.getElementsByClassName('checkbox-dup');
            for(let i=0; i<checkboxes.length; i++) {
                checkboxes[i].checked = source.checked;
            }
        }

        function seleccionarTodos(estado) {
            checkboxes = document.getElementsByClassName('checkbox-dup');
            for(let i=0; i<checkboxes.length; i++) {
                checkboxes[i].checked = estado;
            }
            document.getElementById('checkAll').checked = estado;
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

        function filtrarEstatus(estatus) {
            const inputEstatus = document.getElementById('inputEstatus');
            if (inputEstatus.value === estatus) {
                inputEstatus.value = '';
            } else {
                inputEstatus.value = estatus;
            }
            document.getElementById('searchForm').submit();
        }

        function abrirModalNuevoPaciente() {
            document.getElementById('modalTitulo').innerHTML = '<i class="fa-solid fa-user-plus"></i> Registrar Nuevo Paciente';
            document.getElementById('inputAccionForm').value = 'nuevo_paciente';
            document.getElementById('inputEditIdPaciente').value = '';
            document.getElementById('formNuevoPaciente').reset();
            document.getElementById('curpErrorMsg').style.display = 'none';
            document.getElementById('btnGuardarPaciente').disabled = false;
            document.getElementById('btnGuardarPaciente').style.opacity = '1';
            document.getElementById('modalNuevoPaciente').style.display = 'flex';
        }

        function abrirModalEditarPaciente(p) {
            document.getElementById('modalTitulo').innerHTML = '<i class="fa-solid fa-user-pen"></i> Ver / Editar Información de Paciente (ID: ' + (p.id_paciente || p.id) + ')';
            document.getElementById('inputAccionForm').value = 'editar_paciente';
            document.getElementById('inputEditIdPaciente').value = p.id_paciente || p.id || '';
            
            document.getElementById('inputEditExp').value = p.expediente || '';
            document.getElementById('inputCurp').value = p.curp || '';
            document.getElementById('inputEditNombre').value = p.nombre || '';
            document.getElementById('inputEditApat').value = p.apaterno || '';
            document.getElementById('inputEditAmat').value = p.amaterno || '';
            document.getElementById('inputFnac').value = p.fnacimiento || '';
            document.getElementById('inputYearNac').value = p.year_nacimiento || '';
            document.getElementById('inputEdad').value = p.edad || '';
            document.getElementById('inputEdadActual').value = p.edad_actual || '';
            document.getElementById('selectSexo').value = p.sexo || '';
            document.getElementById('inputEstado').value = p.estado || '';
            document.getElementById('inputEditTel').value = p.telefono || '';
            document.getElementById('inputEditMunicipio').value = p.municipio || '';
            document.getElementById('inputEditDom').value = p.domicilio || '';
            document.getElementById('inputEditCol').value = p.colonia || '';
            document.getElementById('inputEditNpad').value = p.nombre_padre || '';
            document.getElementById('inputEditNmad').value = p.nombre_madre || '';
            document.getElementById('inputEditEpad').value = p.edad_padre || '';
            document.getElementById('inputEditEmad').value = p.edad_madre || '';
            document.getElementById('inputEditFape').value = p.fecha_apertura || '';
            document.getElementById('inputEditDerech').value = p.derechohabiencia || '';
            document.getElementById('inputEditEst').value = p.estatus || 'Vigente';

            document.getElementById('curpErrorMsg').style.display = 'none';
            document.getElementById('btnGuardarPaciente').disabled = false;
            document.getElementById('btnGuardarPaciente').style.opacity = '1';

            document.getElementById('modalNuevoPaciente').style.display = 'flex';
        }

        function cerrarModalNuevoPaciente() {
            document.getElementById('modalNuevoPaciente').style.display = 'none';
        }

        function validarCurpBlur(curp) {
            curp = curp.toUpperCase().trim();
            if (curp.length === 0) return;

            if (curp.length >= 11) {
                let sexoChar = curp.charAt(10);
                let selectSexo = document.getElementById('selectSexo');
                if (sexoChar === 'H') selectSexo.value = 'HOMBRE';
                else if (sexoChar === 'M') selectSexo.value = 'MUJER';

                let yy = curp.substr(4, 2);
                let mm = curp.substr(6, 2);
                let dd = curp.substr(8, 2);
                
                let anioActual = new Date().getFullYear() % 100;
                let siglo = (parseInt(yy) > anioActual) ? '19' : '20';
                let anioCompleto = siglo + yy;

                if (mm >= 1 && mm <= 12 && dd >= 1 && dd <= 31) {
                    let fechaNac = `${anioCompleto}-${mm}-${dd}`;
                    document.getElementById('inputFnac').value = fechaNac;
                    document.getElementById('inputYearNac').value = anioCompleto;

                    let birthDate = new Date(fechaNac);
                    let today = new Date();
                    let age = today.getFullYear() - birthDate.getFullYear();
                    let m = today.getMonth() - birthDate.getMonth();
                    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }
                    if (age >= 0) {
                        document.getElementById('inputEdad').value = age;
                        document.getElementById('inputEdadActual').value = age;
                    }
                }

                let entidad = curp.substr(11, 2);
                const entidadesMap = {
                    'AS':'AGUASCALIENTES', 'BC':'BAJA CALIFORNIA', 'BS':'BAJA CALIFORNIA SUR', 'CC':'CAMPECHE', 'CS':'CHIAPAS', 'CH':'CHIHUAHUA', 'CL':'COAHUILA', 'CM':'COLIMA', 'DF':'CIUDAD DE MÉXICO', 'DG':'DURANGO', 'GT':'GUANAJUATO', 'GR':'GUERRERO', 'HG':'HIDALGO', 'JC':'JALISCO', 'MC':'MÉXICO', 'MN':'MICHOACÁN', 'MS':'MORELOS', 'NT':'NAYARIT', 'NL':'NUEVO LEÓN', 'OC':'OAXACA', 'PL':'PUEBLA', 'QT':'QUERÉTARO', 'QR':'QUINTANA ROO', 'SP':'SAN LUIS POTOSÍ', 'SL':'SINALOA', 'SR':'SONORA', 'TC':'TABASCO', 'TS':'TAMAULIPAS', 'TL':'TLAXCALA', 'VZ':'VERACRuz', 'YN':'YUCATÁN', 'ZS':'ZACATECAS'
                };
                if (entidadesMap[entidad]) {
                    document.getElementById('inputEstado').value = entidadesMap[entidad];
                }
            }

            let accionActual = document.getElementById('inputAccionForm').value;
            let excluirId = document.getElementById('inputEditIdPaciente').value;

            let formData = new FormData();
            formData.append('accion', accionActual);
            formData.append('validar_curp_ajax', '1');
            formData.append('curp', curp);
            if(excluirId) {
                formData.append('excluir_id', excluirId);
            }

            fetch('archivo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const errorDiv = document.getElementById('curpErrorMsg');
                const btnGuardar = document.getElementById('btnGuardarPaciente');
                if (data.existe) {
                    errorDiv.style.display = 'block';
                    btnGuardar.disabled = true;
                    btnGuardar.style.opacity = '0.5';
                } else {
                    errorDiv.style.display = 'none';
                    btnGuardar.disabled = false;
                    btnGuardar.style.opacity = '1';
                }
            })
            .catch(error => console.error('Error en validación CURP:', error));
        }

        function abrirModalCita(id, nombre) {
            document.getElementById('cita_paciente_id').value = id;
            document.getElementById('cita_paciente_nombre').value = nombre;
            document.getElementById('select_tipo_clinica').value = "";
            document.getElementById('select_clinica').innerHTML = '<option value="">Primero seleccione tipo de clínica...</option>';
            document.getElementById('input_fecha').value = "";
            document.getElementById('select_hora').innerHTML = '<option value="">Primero seleccione clínica y fecha...</option>';
            document.getElementById('modalCita').style.display = 'flex';
        }

        function cerrarModalCita() {
            document.getElementById('modalCita').style.display = 'none';
        }

        function filtrarClinicasPorTipo() {
            const tipoSeleccionado = document.getElementById('select_tipo_clinica').value;
            const selectClinica = document.getElementById('select_clinica');

            selectClinica.innerHTML = '<option value="">Seleccione una clínica...</option>';

            if (!tipoSeleccionado) {
                selectClinica.innerHTML = '<option value="">Primero seleccione tipo de clínica...</option>';
                return;
            }

            const clinicasFiltradas = todasLasClinicas.filter(c => c.tipo_clinica === tipoSeleccionado);

            if (clinicasFiltradas.length > 0) {
                clinicasFiltradas.forEach(c => {
                    let opt = document.createElement('option');
                    opt.value = c.id_clinica;
                    opt.textContent = c.nombre_clinica;
                    selectClinica.appendChild(opt);
                });
            } else {
                selectClinica.innerHTML = '<option value="">No hay clínicas disponibles para este tipo</option>';
            }
        }

        function cargarHorariosDisponibles() {
            const clinicaId = document.getElementById('select_clinica').value;
            const fecha = document.getElementById('input_fecha').value;
            const selectHora = document.getElementById('select_hora');

            if (!clinicaId || !fecha) {
                selectHora.innerHTML = '<option value="">Complete clínica y fecha para ver horarios...</option>';
                return;
            }

            selectHora.innerHTML = '<option value="">Cargando horarios...</option>';
            
            setTimeout(() => {
                selectHora.innerHTML = '<option value="">Seleccione una hora...</option>';
                const horarios = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00'];
                horarios.forEach(h => {
                    let opt = document.createElement('option');
                    opt.value = h;
                    opt.textContent = h + ' hrs';
                    selectHora.appendChild(opt);
                });
            }, 200);
        }

        function eliminarPaciente(id) {
            if(confirm('¿Está seguro de eliminar este paciente?')) {
                window.location.href = 'eliminar_paciente.php?id=' + id;
            }
        }

        window.onclick = function(event) {
            var modalCita = document.getElementById('modalCita');
            var modalNuevo = document.getElementById('modalNuevoPaciente');
            if (event.target == modalCita) cerrarModalCita();
            if (event.target == modalNuevo) cerrarModalNuevoPaciente();
        }
    </script>
</body>
</html>