<?php
// ===================================================================
// ARCHIVO: farmacia.php
// Módulo Completo de Farmacia: Inventario, Surtido, Folio Automático, Historial y Filtros
// ===================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Capturar cualquier error crítico o de conexión para evitar el HTTP 500 silencioso
set_exception_handler(function($e) {
    echo "<div style='background:#fee2e2; color:#991b1b; border:1px solid #dc2626; padding:20px; margin:20px; font-family:Arial; border-radius:6px;'>";
    echo "<h3><i class='fa-solid fa-triangle-exclamation'></i> Error Crítico / Conexión en Farmacia.php</h3>";
    echo "<p><strong>Mensaje:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Archivo:</strong> " . htmlspecialchars($e->getFile()) . " en la línea " . $e->getLine() . "</p>";
    echo "</div>";
    exit();
});

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

if (!file_exists('conexion.php')) {
    throw new Exception("No se encuentra el archivo 'conexion.php'. Verifique que esté en el mismo directorio.");
}

require_once 'conexion.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new Exception("La variable \$pdo no está definida correctamente en 'conexion.php'.");
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Auto-creación y verificación de tablas y columnas necesarias
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS medicamentos (
        id_medicamento SERIAL PRIMARY KEY,
        dependencia VARCHAR(100),
        clave_cuadro_basico VARCHAR(100),
        descripcion TEXT,
        grupo VARCHAR(100),
        existencia INT DEFAULT 0,
        precio_unitario NUMERIC(10,2) DEFAULT 0,
        lote VARCHAR(100),
        proveedor VARCHAR(255),
        fecha_caducidad VARCHAR(50),
        orden_suministro VARCHAR(100)
    )");

    $columnas_med = ['orden_suministro', 'lote', 'fecha_caducidad', 'proveedor', 'dependencia', 'grupo', 'precio_unitario'];
    foreach ($columnas_med as $col) {
        try {
            $tipo = ($col === 'precio_unitario') ? 'NUMERIC(10,2)' : (($col === 'existencia') ? 'INT' : 'VARCHAR(255)');
            $pdo->exec("ALTER TABLE medicamentos ADD COLUMN IF NOT EXISTS $col $tipo");
        } catch (Exception $ex) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS recetas_express (
        folio VARCHAR(100) PRIMARY KEY,
        nombre_paciente VARCHAR(255),
        edad_paciente INT,
        medico_tratante VARCHAR(255),
        id_medicamento_1 INT,
        medicamento_1 TEXT,
        clave_1 VARCHAR(100),
        solicitada_1 INT DEFAULT 0,
        surtida_1 INT DEFAULT 0,
        id_medicamento_2 INT,
        medicamento_2 TEXT,
        clave_2 VARCHAR(100),
        solicitada_2 INT DEFAULT 0,
        surtida_2 INT DEFAULT 0,
        id_medicamento_3 INT,
        medicamento_3 TEXT,
        clave_3 VARCHAR(100),
        solicitada_3 INT DEFAULT 0,
        surtida_3 INT DEFAULT 0,
        estatus_surtido VARCHAR(50) DEFAULT 'POR SURTIR',
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    throw new Exception("Error al configurar las tablas en PostgreSQL: " . $e->getMessage());
}

// ===================================================================
// EXPORTAR A EXCEL: MEDICAMENTOS SURTIDOS POR CLAVE DE CUADRO BÁSICO
// ===================================================================
if (isset($_GET['export_excel_surtidos']) && $_GET['export_excel_surtidos'] === '1') {
    $filtro = $_GET['filtro'] ?? 'hoy';
    $fecha_ini = $_GET['fecha_ini'] ?? date('Y-m-d');
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

    $where = "WHERE 1=1";
    $params = [];
    $campo_fecha = "fecha_creacion";

    if ($filtro === 'hoy') {
        $where .= " AND CAST($campo_fecha AS DATE) = CURRENT_DATE";
    } elseif ($filtro === 'semana') {
        $where .= " AND $campo_fecha >= DATE_TRUNC('week', CURRENT_DATE)";
    } elseif ($filtro === 'mes') {
        $where .= " AND $campo_fecha >= DATE_TRUNC('month', CURRENT_DATE)";
    } elseif ($filtro === 'especifica') {
        $where .= " AND CAST($campo_fecha AS DATE) = :f_esp";
        $params['f_esp'] = $fecha_ini;
    } elseif ($filtro === 'rango') {
        $where .= " AND CAST($campo_fecha AS DATE) BETWEEN :f_ini AND :f_fin";
        $params['f_ini'] = $fecha_ini;
        $params['f_fin'] = $fecha_fin;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM recetas_express $where");
        $stmt->execute($params);
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $acumulador_medicamentos = [];

        foreach ($registros as $r) {
            if (!empty($r['medicamento_1']) && (int)($r['surtida_1'] ?? 0) > 0) {
                $clave = trim($r['clave_1'] ?? 'SIN CLAVE');
                $nombre = trim($r['medicamento_1']);
                $cantidad = (int)$r['surtida_1'];

                if (!isset($acumulador_medicamentos[$clave])) {
                    $acumulador_medicamentos[$clave] = ['clave' => $clave, 'descripcion' => $nombre, 'total_surtido' => 0];
                }
                $acumulador_medicamentos[$clave]['total_surtido'] += $cantidad;
            }

            if (!empty($r['medicamento_2']) && (int)($r['surtida_2'] ?? 0) > 0) {
                $clave = trim($r['clave_2'] ?? 'SIN CLAVE');
                $nombre = trim($r['medicamento_2']);
                $cantidad = (int)$r['surtida_2'];

                if (!isset($acumulador_medicamentos[$clave])) {
                    $acumulador_medicamentos[$clave] = ['clave' => $clave, 'descripcion' => $nombre, 'total_surtido' => 0];
                }
                $acumulador_medicamentos[$clave]['total_surtido'] += $cantidad;
            }

            if (!empty($r['medicamento_3']) && (int)($r['surtida_3'] ?? 0) > 0) {
                $clave = trim($r['clave_3'] ?? 'SIN CLAVE');
                $nombre = trim($r['medicamento_3']);
                $cantidad = (int)$r['surtida_3'];

                if (!isset($acumulador_medicamentos[$clave])) {
                    $acumulador_medicamentos[$clave] = ['clave' => $clave, 'descripcion' => $nombre, 'total_surtido' => 0];
                }
                $acumulador_medicamentos[$clave]['total_surtido'] += $cantidad;
            }
        }

        ksort($acumulador_medicamentos);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=medicamentos_surtidos_' . $filtro . '_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, ['CLAVE CUADRO BÁSICO', 'DESCRIPCIÓN DEL MEDICAMENTO', 'TOTAL SURTIDO']);

        foreach ($acumulador_medicamentos as $med) {
            fputcsv($output, [
                $med['clave'],
                $med['descripcion'],
                $med['total_surtido']
            ]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        die("Error al generar el reporte de Excel: " . $e->getMessage());
    }
}

// ===================================================================
// PROCESAMIENTO DE CARGA / ACTUALIZACIÓN DE INVENTARIO
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_cargar_inventario'])) {
    $modo_carga = $_POST['modo_carga'] ?? 'actualizar'; 
    $eliminar_caducados = isset($_POST['eliminar_caducados']) ? true : false;

    try {
        if (!isset($_FILES['archivo_inventario']) || $_FILES['archivo_inventario']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Debe seleccionar un archivo válido para cargar.");
        }

        $file_tmp = $_FILES['archivo_inventario']['tmp_name'];
        $file_name = $_FILES['archivo_inventario']['name'];
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $filas_datos = [];

        if ($extension === 'xlsx') {
            $zip = new ZipArchive();
            if ($zip->open($file_tmp) === TRUE) {
                $sharedStrings = [];
                if (($index = $zip->locateName('xl/sharedStrings.xml')) !== false) {
                    $xmlStr = $zip->getFromIndex($index);
                    $xml = @simplexml_load_string($xmlStr);
                    if ($xml && isset($xml->si)) {
                        foreach ($xml->si as $si) {
                            $sharedStrings[] = (string)$si->t;
                        }
                    }
                }
                if (($index = $zip->locateName('xl/worksheets/sheet1.xml')) !== false) {
                    $xmlStr = $zip->getFromIndex($index);
                    $xml = @simplexml_load_string($xmlStr);
                    if ($xml && isset($xml->sheetData->row)) {
                        foreach ($xml->sheetData->row as $row) {
                            $rowData = [];
                            foreach ($row->c as $c) {
                                $val = '';
                                $t = (string)$c['t'];
                                $v = isset($c->v) ? (string)$c->v : '';
                                if ($t === 's') {
                                    $val = isset($sharedStrings[(int)$v]) ? $sharedStrings[(int)$v] : '';
                                } else {
                                    $val = $v;
                                }
                                $rowData[] = trim((string)$val);
                            }
                            $filas_datos[] = $rowData;
                        }
                    }
                }
                $zip->close();
            } else {
                throw new Exception("No se pudo abrir el archivo Excel comprimido (.xlsx).");
            }
        } else {
            if (($handle = fopen($file_tmp, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 2000, ",")) !== FALSE || ($data = fgetcsv($handle, 2000, ";")) !== FALSE) {
                    $rowData = [];
                    foreach ($data as $cell) {
                        $rowData[] = trim(mb_convert_encoding((string)$cell, 'UTF-8', 'UTF-8, ISO-8859-1, WINDOWS-1252'));
                    }
                    $filas_datos[] = $rowData;
                }
                fclose($handle);
            } else {
                throw new Exception("No se pudo leer el archivo CSV.");
            }
        }

        if (empty($filas_datos)) {
            throw new Exception("El archivo está vacío o no contiene datos legibles.");
        }

        array_shift($filas_datos);

        $pdo->beginTransaction();

        if ($eliminar_caducados) {
            $pdo->exec("DELETE FROM medicamentos WHERE fecha_caducidad IS NOT NULL AND fecha_caducidad <> '' AND CAST(fecha_caducidad AS DATE) < CURRENT_DATE");
        }

        if ($modo_carga === 'sustituir') {
            $pdo->exec("TRUNCATE TABLE medicamentos RESTART IDENTITY CASCADE");
        }

        foreach ($filas_datos as $data) {
            if (count(array_filter($data)) < 3) continue;

            $clave       = trim((string)($data[0] ?? ''));
            $descripcion = trim((string)($data[1] ?? ''));
            $grupo       = trim((string)($data[2] ?? ''));
            $existencia  = (int)($data[3] ?? 0);
            $precio      = (float)($data[4] ?? 0);
            $lote        = trim((string)($data[6] ?? ''));
            $proveedor   = trim((string)($data[7] ?? ''));
            $almacen     = trim((string)($data[9] ?? 'IMSS-BIENESTAR'));
            $raw_cad     = trim((string)($data[12] ?? ''));
            $orden_sum   = trim((string)($data[13] ?? ''));

            if (empty($descripcion)) continue;

            $fecha_cad = null;
            if (!empty($raw_cad)) {
                if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $raw_cad, $matches)) {
                    $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $anio = $matches[3];
                    if (strlen($anio) == 2) {
                        $anio = '20' . $anio;
                    }
                    $fecha_cad = "$anio-$mes-$dia";
                } else {
                    $ts = strtotime($raw_cad);
                    if ($ts !== false && $ts > 0) {
                        $fecha_cad = date('Y-m-d', $ts);
                    }
                }
            }

            if ($modo_carga === 'actualizar') {
                $stmt_chk = $pdo->prepare("
                    SELECT id_medicamento, existencia FROM medicamentos 
                    WHERE (clave_cuadro_basico = :cve OR descripcion = :desc) 
                    AND COALESCE(fecha_caducidad, '') = COALESCE(:cad, '')
                    AND COALESCE(proveedor, '') = COALESCE(:prov, '')
                    AND COALESCE(orden_suministro, '') = COALESCE(:ord, '')
                    LIMIT 1
                ");
                $stmt_chk->execute([
                    'cve' => $clave,
                    'desc' => $descripcion,
                    'cad' => $fecha_cad,
                    'prov' => $proveedor,
                    'ord' => $orden_sum
                ]);
                $existente = $stmt_chk->fetch(PDO::FETCH_ASSOC);

                if ($existente) {
                    $nueva_existencia = (int)$existente['existencia'] + $existencia;
                    $stmt_upd = $pdo->prepare("
                        UPDATE medicamentos SET existencia = :ext, precio_unitario = :pre, lote = :lote, grupo = :grupo, dependencia = :dep, fecha_caducidad = :cad 
                        WHERE id_medicamento = :id
                    ");
                    $stmt_upd->execute([
                        'ext' => $nueva_existencia,
                        'pre' => $precio,
                        'lote' => $lote,
                        'grupo' => $grupo,
                        'dep' => !empty($almacen) ? $almacen : 'IMSS-BIENESTAR',
                        'cad' => $fecha_cad,
                        'id' => $existente['id_medicamento']
                    ]);
                } else {
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO medicamentos (dependencia, clave_cuadro_basico, descripcion, grupo, existencia, precio_unitario, lote, proveedor, fecha_caducidad, orden_suministro) 
                        VALUES (:dep, :cve, :desc, :grupo, :ext, :pre, :lote, :prov, :cad, :ord)
                    ");
                    $stmt_ins->execute([
                        'dep' => !empty($almacen) ? $almacen : 'IMSS-BIENESTAR',
                        'cve' => $clave,
                        'desc' => $descripcion,
                        'grupo' => $grupo,
                        'ext' => $existencia,
                        'pre' => $precio,
                        'lote' => $lote,
                        'prov' => $proveedor,
                        'cad' => $fecha_cad,
                        'ord' => $orden_sum
                    ]);
                }
            } else {
                $stmt_ins = $pdo->prepare("
                    INSERT INTO medicamentos (dependencia, clave_cuadro_basico, descripcion, grupo, existencia, precio_unitario, lote, proveedor, fecha_caducidad, orden_suministro) 
                    VALUES (:dep, :cve, :desc, :grupo, :ext, :pre, :lote, :prov, :cad, :ord)
                ");
                $stmt_ins->execute([
                    'dep' => !empty($almacen) ? $almacen : 'IMSS-BIENESTAR',
                    'cve' => $clave,
                    'desc' => $descripcion,
                    'grupo' => $grupo,
                    'ext' => $existencia,
                    'pre' => $precio,
                    'lote' => $lote,
                    'prov' => $proveedor,
                    'cad' => $fecha_cad,
                    'ord' => $orden_sum
                ]);
            }
        }

        $pdo->commit();
        $mensaje_exito = "Inventario procesado y actualizado correctamente.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_inventario = "Error al procesar el archivo: " . $e->getMessage();
    }
}

// Procesar el Surtido o Edición de Receta por Folio vía AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_surtir'])) {
    header('Content-Type: application/json');
    $folio = trim($_POST['folio_surtir'] ?? '');
    $lote_surtir = $_POST['lote_surtir'] ?? [];

    try {
        $pdo->beginTransaction();

        $stmt_r = $pdo->prepare("SELECT * FROM recetas_express WHERE folio = :folio");
        $stmt_r->execute(['folio' => $folio]);
        $receta = $stmt_r->fetch(PDO::FETCH_ASSOC);

        if (!$receta) {
            throw new Exception("No se encontró la receta con el folio especificado.");
        }

        $ya_surtida_1 = (int)($receta['surtida_1'] ?? 0);
        $ya_surtida_2 = (int)($receta['surtida_2'] ?? 0);
        $ya_surtida_3 = (int)($receta['surtida_3'] ?? 0);

        $solicitada_1 = (int)($receta['solicitada_1'] ?? 0);
        $solicitada_2 = (int)($receta['solicitada_2'] ?? 0);
        $solicitada_3 = (int)($receta['solicitada_3'] ?? 0);

        $ids_med_1 = [];
        $ids_med_2 = [];
        $ids_med_3 = [];

        if (!empty($receta['clave_1']) || !empty($receta['medicamento_1'])) {
            $st = $pdo->prepare("SELECT id_medicamento FROM medicamentos WHERE clave_cuadro_basico = :cve OR descripcion = :desc");
            $st->execute(['cve' => $receta['clave_1'], 'desc' => $receta['medicamento_1']]);
            $ids_med_1 = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        if (!empty($receta['clave_2']) || !empty($receta['medicamento_2'])) {
            $st = $pdo->prepare("SELECT id_medicamento FROM medicamentos WHERE clave_cuadro_basico = :cve OR descripcion = :desc");
            $st->execute(['cve' => $receta['clave_2'], 'desc' => $receta['medicamento_2']]);
            $ids_med_2 = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        if (!empty($receta['clave_3']) || !empty($receta['medicamento_3'])) {
            $st = $pdo->prepare("SELECT id_medicamento FROM medicamentos WHERE clave_cuadro_basico = :cve OR descripcion = :desc");
            $st->execute(['cve' => $receta['clave_3'], 'desc' => $receta['medicamento_3']]);
            $ids_med_3 = $st->fetchAll(PDO::FETCH_COLUMN);
        }

        $adicional_1 = 0;
        $adicional_2 = 0;
        $adicional_3 = 0;

        foreach ($lote_surtir as $id_med => $qty) {
            $qty = (int)$qty;
            if ($qty <= 0) continue;

            $id_med = (int)$id_med;
            if (in_array($id_med, $ids_med_1)) {
                $adicional_1 += $qty;
            } elseif (in_array($id_med, $ids_med_2)) {
                $adicional_2 += $qty;
            } elseif (in_array($id_med, $ids_med_3)) {
                $adicional_3 += $qty;
            }

            $stmt_stock = $pdo->prepare("UPDATE medicamentos SET existencia = existencia - :delta WHERE id_medicamento = :id AND existencia >= :delta");
            $stmt_stock->execute(['delta' => $qty, 'id' => $id_med]);
            if ($stmt_stock->rowCount() == 0) {
                throw new Exception("Stock insuficiente en inventario para uno de los lotes seleccionados.");
            }
        }

        $nuevo_surtido_1 = $ya_surtida_1 + $adicional_1;
        $nuevo_surtido_2 = $ya_surtida_2 + $adicional_2;
        $nuevo_surtido_3 = $ya_surtida_3 + $adicional_3;

        if ($nuevo_surtido_1 > $solicitada_1 || $nuevo_surtido_2 > $solicitada_2 || $nuevo_surtido_3 > $solicitada_3) {
            throw new Exception("Error: La cantidad total surtida no puede exceder la cantidad solicitada en ningún medicamento.");
        }

        $total_solicitado = $solicitada_1 + $solicitada_2 + $solicitada_3;
        $total_surtido_general = $nuevo_surtido_1 + $nuevo_surtido_2 + $nuevo_surtido_3;
        $estatus = ($total_surtido_general >= $total_solicitado && $total_solicitado > 0) ? 'SURTIDA' : (($total_surtido_general > 0) ? 'SURTIDA PARCIAL' : 'POR SURTIR');

        $stmt_upd = $pdo->prepare("
            UPDATE recetas_express SET 
                surtida_1 = :s1, surtida_2 = :s2, surtida_3 = :s3, estatus_surtido = :est
            WHERE folio = :folio
        ");
        $stmt_upd->execute([
            's1' => $nuevo_surtido_1, 's2' => $nuevo_surtido_2, 's3' => $nuevo_surtido_3,
            'est' => $estatus, 'folio' => $folio
        ]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Receta surtida por lote correctamente.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Procesar Guardado de Receta Externa / Manual Nueva
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_guardar_externa'])) {
    header('Content-Type: application/json');
    $folio = trim($_POST['nuevo_folio'] ?? '');
    $paciente = trim($_POST['nuevo_paciente'] ?? '') !== '' ? trim($_POST['nuevo_paciente']) : 'S/N';
    $edad = (int)($_POST['nuevo_edad'] ?? 0);
    $medico = trim($_POST['nuevo_medico'] ?? '') !== '' ? trim($_POST['nuevo_medico']) : 'S/N';

    $id_med_1 = !empty($_POST['id_med_1']) ? (int)$_POST['id_med_1'] : null;
    $nom_med_1 = trim($_POST['nom_med_1'] ?? '');
    $cve_med_1 = trim($_POST['cve_med_1'] ?? '');
    $sol_1 = (int)($_POST['sol_1'] ?? 0);
    $sur_1 = (int)($_POST['sur_1'] ?? 0);

    $id_med_2 = !empty($_POST['id_med_2']) ? (int)$_POST['id_med_2'] : null;
    $nom_med_2 = trim($_POST['nom_med_2'] ?? '');
    $cve_med_2 = trim($_POST['cve_med_2'] ?? '');
    $sol_2 = (int)($_POST['sol_2'] ?? 0);
    $sur_2 = (int)($_POST['sur_2'] ?? 0);

    $id_med_3 = !empty($_POST['id_med_3']) ? (int)$_POST['id_med_3'] : null;
    $nom_med_3 = trim($_POST['nom_med_3'] ?? '');
    $cve_med_3 = trim($_POST['cve_med_3'] ?? '');
    $sol_3 = (int)($_POST['sol_3'] ?? 0);
    $sur_3 = (int)($_POST['sur_3'] ?? 0);

    try {
        if (empty($folio)) throw new Exception("Debe generar o ingresar un número de folio.");

        $pdo->beginTransaction();

        $chk = $pdo->prepare("SELECT folio FROM recetas_express WHERE folio = :f");
        $chk->execute(['f' => $folio]);
        if ($chk->fetch()) {
            throw new Exception("El folio $folio ya se encuentra registrado. Intente con otro.");
        }

        if ($sur_1 > $sol_1) throw new Exception("La cantidad surtida del medicamento 1 no puede exceder la solicitada.");
        if ($sur_2 > $sol_2) throw new Exception("La cantidad surtida del medicamento 2 no puede exceder la solicitada.");
        if ($sur_3 > $sol_3) throw new Exception("La cantidad surtida del medicamento 3 no puede exceder la solicitada.");

        if ($sur_1 > 0 && $id_med_1) {
            $stk = $pdo->prepare("UPDATE medicamentos SET existencia = existencia - :del WHERE id_medicamento = :id AND existencia >= :del");
            $stk->execute(['del' => $sur_1, 'id' => $id_med_1]);
            if ($stk->rowCount() == 0) throw new Exception("Stock insuficiente para el medicamento 1 seleccionado.");
        }
        if ($sur_2 > 0 && $id_med_2) {
            $stk = $pdo->prepare("UPDATE medicamentos SET existencia = existencia - :del WHERE id_medicamento = :id AND existencia >= :del");
            $stk->execute(['del' => $sur_2, 'id' => $id_med_2]);
            if ($stk->rowCount() == 0) throw new Exception("Stock insuficiente para el medicamento 2 seleccionado.");
        }
        if ($sur_3 > 0 && $id_med_3) {
            $stk = $pdo->prepare("UPDATE medicamentos SET existencia = existencia - :del WHERE id_medicamento = :id AND existencia >= :del");
            $stk->execute(['del' => $sur_3, 'id' => $id_med_3]);
            if ($stk->rowCount() == 0) throw new Exception("Stock insuficiente para el medicamento 3 seleccionado.");
        }

        $total_sol = $sol_1 + $sol_2 + $sol_3;
        $total_sur = $sur_1 + $sur_2 + $sur_3;
        $estatus = ($total_sur >= $total_sol && $total_sol > 0) ? 'SURTIDA' : (($total_sur > 0) ? 'SURTIDA PARCIAL' : 'POR SURTIR');

        $stmt_ins = $pdo->prepare("
            INSERT INTO recetas_express (
                folio, nombre_paciente, edad_paciente, medico_tratante, 
                id_medicamento_1, medicamento_1, clave_1, solicitada_1, surtida_1,
                id_medicamento_2, medicamento_2, clave_2, solicitada_2, surtida_2,
                id_medicamento_3, medicamento_3, clave_3, solicitada_3, surtida_3,
                estatus_surtido, fecha_creacion
            ) VALUES (
                :folio, :pac, :edad, :medico,
                :idm1, :nom1, :cve1, :sol1, :sur1,
                :idm2, :nom2, :cve2, :sol2, :sur2,
                :idm3, :nom3, :cve3, :sol3, :sur3,
                :est, NOW()
            )
        ");
        $stmt_ins->execute([
            'folio' => $folio, 'pac' => $paciente, 'edad' => $edad, 'medico' => $medico,
            'idm1' => $id_med_1, 'nom1' => $nom_med_1, 'cve1' => $cve_med_1, 'sol1' => $sol_1, 'sur1' => $sur_1,
            'idm2' => $id_med_2, 'nom2' => $nom_med_2, 'cve2' => $cve_med_2, 'sol2' => $sol_2, 'sur2' => $sur_2,
            'idm3' => $id_med_3, 'nom3' => $nom_med_3, 'cve3' => $cve_med_3, 'sol3' => $sol_3, 'sur3' => $sur_3,
            'est' => $estatus
        ]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Receta externa registrada y surtida correctamente.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Endpoint AJAX para buscar medicamentos en tiempo real (Incluyendo semáforo gráfico de caducidad)
if (isset($_GET['ajax_buscar_medicamento'])) {
    header('Content-Type: application/json');
    $term = trim($_GET['term'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT id_medicamento, clave_cuadro_basico, descripcion, existencia, lote, fecha_caducidad FROM medicamentos WHERE descripcion ILIKE :t OR clave_cuadro_basico ILIKE :t LIMIT 15");
        $stmt->execute(['t' => "%$term%"]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($resultados as &$m) {
            $f_cad = $m['fecha_caducidad'] ?? '';
            $estado_semaforo = 'VERDE';
            $dias_restantes = 0;
            if (!empty($f_cad)) {
                $timestamp_cad = strtotime($f_cad);
                $ahora = time();
                $dias_restantes = (int)round(($timestamp_cad - $ahora) / (60 * 60 * 24));
                if ($dias_restantes < 0 || $dias_restantes <= 180) {
                    $estado_semaforo = 'ROJO';
                } elseif ($dias_restantes <= 365) {
                    $estado_semaforo = 'AMARILLO';
                } else {
                    $estado_semaforo = 'VERDE';
                }
            }
            $m['semaforo_estado'] = $estado_semaforo;
            $m['dias_restantes'] = $dias_restantes;
        }

        echo json_encode($resultados);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit();
}

// Endpoint AJAX para eliminar receta y devolver stock
if (isset($_GET['ajax_eliminar_receta'])) {
    header('Content-Type: application/json');
    $folio = trim($_GET['folio'] ?? '');
    try {
        $pdo->beginTransaction();

        $stmt_r = $pdo->prepare("SELECT * FROM recetas_express WHERE folio = :folio");
        $stmt_r->execute(['folio' => $folio]);
        $receta = $stmt_r->fetch(PDO::FETCH_ASSOC);

        if ($receta) {
            $sur_1 = (int)($receta['surtida_1'] ?? 0);
            $sur_2 = (int)($receta['surtida_2'] ?? 0);
            $sur_3 = (int)($receta['surtida_3'] ?? 0);

            if ($sur_1 > 0 && !empty($receta['id_medicamento_1'])) {
                $pdo->prepare("UPDATE medicamentos SET existencia = existencia + :val WHERE id_medicamento = :id")->execute(['val' => $sur_1, 'id' => $receta['id_medicamento_1']]);
            }
            if ($sur_2 > 0 && !empty($receta['id_medicamento_2'])) {
                $pdo->prepare("UPDATE medicamentos SET existencia = existencia + :val WHERE id_medicamento = :id")->execute(['val' => $sur_2, 'id' => $receta['id_medicamento_2']]);
            }
            if ($sur_3 > 0 && !empty($receta['id_medicamento_3'])) {
                $pdo->prepare("UPDATE medicamentos SET existencia = existencia + :val WHERE id_medicamento = :id")->execute(['val' => $sur_3, 'id' => $receta['id_medicamento_3']]);
            }

            $stmt_del = $pdo->prepare("DELETE FROM recetas_express WHERE folio = :folio");
            $stmt_del->execute(['folio' => $folio]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Receta eliminada y stock devuelto al inventario correctamente.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Endpoint AJAX para buscar receta por folio y sus lotes disponibles ordenados por caducidad
if (isset($_GET['ajax_buscar_receta'])) {
    header('Content-Type: application/json');
    $folio = trim($_GET['folio'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT * FROM recetas_express WHERE folio = :folio");
        $stmt->execute(['folio' => $folio]);
        $receta = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($receta) {
            $process_lotes = function($cve, $desc) use ($pdo) {
                if (empty($cve) && empty($desc)) return [];
                $stmt_l = $pdo->prepare("
                    SELECT * FROM medicamentos 
                    WHERE clave_cuadro_basico = :cve OR descripcion = :desc 
                    ORDER BY fecha_caducidad ASC NULLS LAST
                ");
                $stmt_l->execute(['cve' => $cve, 'desc' => $desc]);
                $lotes = $stmt_l->fetchAll(PDO::FETCH_ASSOC);

                $res = [];
                foreach ($lotes as $med) {
                    $f_cad = $med['fecha_caducidad'] ?? '';
                    $estado_semaforo = 'VERDE';
                    $dias_restantes = 0;
                    if (!empty($f_cad)) {
                        $timestamp_cad = strtotime($f_cad);
                        $ahora = time();
                        $dias_restantes = (int)round(($timestamp_cad - $ahora) / (60 * 60 * 24));
                        if ($dias_restantes < 0 || $dias_restantes <= 180) {
                            $estado_semaforo = 'ROJO';
                        } elseif ($dias_restantes <= 365) {
                            $estado_semaforo = 'AMARILLO';
                        } else {
                            $estado_semaforo = 'VERDE';
                        }
                    }
                    $med['semaforo_estado'] = $estado_semaforo;
                    $med['dias_restantes'] = $dias_restantes;
                    $res[] = $med;
                }
                return $res;
            };

            $receta['lotes_1'] = $process_lotes($receta['clave_1'] ?? '', $receta['medicamento_1'] ?? '');
            $receta['lotes_2'] = $process_lotes($receta['clave_2'] ?? '', $receta['medicamento_2'] ?? '');
            $receta['lotes_3'] = $process_lotes($receta['clave_3'] ?? '', $receta['medicamento_3'] ?? '');
        }

        echo json_encode(['encontrada' => (bool)$receta, 'receta' => $receta]);
    } catch (Exception $e) {
        echo json_encode(['encontrada' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Endpoint AJAX para filtrar recetas surtidas
if (isset($_GET['ajax_filtrar_recetas'])) {
    header('Content-Type: application/json');
    $filtro = $_GET['filtro'] ?? 'hoy';
    $fecha_ini = $_GET['fecha_ini'] ?? date('Y-m-d');
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

    $where = "WHERE 1=1";
    $params = [];
    $campo_fecha = "fecha_creacion";

    if ($filtro === 'hoy') {
        $where .= " AND CAST($campo_fecha AS DATE) = CURRENT_DATE";
    } elseif ($filtro === 'semana') {
        $where .= " AND $campo_fecha >= DATE_TRUNC('week', CURRENT_DATE)";
    } elseif ($filtro === 'mes') {
        $where .= " AND $campo_fecha >= DATE_TRUNC('month', CURRENT_DATE)";
    } elseif ($filtro === 'especifica') {
        $where .= " AND CAST($campo_fecha AS DATE) = :f_esp";
        $params['f_esp'] = $fecha_ini;
    } elseif ($filtro === 'rango') {
        $where .= " AND CAST($campo_fecha AS DATE) BETWEEN :f_ini AND :f_fin";
        $params['f_ini'] = $fecha_ini;
        $params['f_fin'] = $fecha_fin;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM recetas_express $where ORDER BY folio DESC");
        $stmt->execute($params);
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'datos' => $registros]);
    } catch (Exception $e) {
        try {
            $stmt2 = $pdo->query("SELECT * FROM recetas_express ORDER BY folio DESC");
            echo json_encode(['success' => true, 'datos' => $stmt2->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $ex) {
            echo json_encode(['success' => false, 'datos' => [], 'error' => $e->getMessage()]);
        }
    }
    exit();
}

// Obtener inventario de medicamentos
$busqueda = trim($_GET['q'] ?? '');
$dependencia_filtro = trim($_GET['dependencia'] ?? '');
$semaforo_filtro = trim($_GET['semaforo'] ?? '');

$sql_inv = "SELECT * FROM medicamentos WHERE 1=1";
$params_inv = [];

if (!empty($busqueda)) {
    $sql_inv .= " AND (descripcion ILIKE :bus OR clave_cuadro_basico ILIKE :bus OR lote ILIKE :bus OR proveedor ILIKE :bus)";
    $params_inv['bus'] = "%$busqueda%";
}
if (!empty($dependencia_filtro)) {
    $sql_inv .= " AND dependencia = :dep";
    $params_inv['dep'] = $dependencia_filtro;
}
$sql_inv .= " ORDER BY descripcion ASC";

$medicamentos_raw = [];
try {
    $stmt_inv = $pdo->prepare($sql_inv);
    $stmt_inv->execute($params_inv);
    $medicamentos_raw = $stmt_inv->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$medicamentos = [];
foreach ($medicamentos_raw as $med) {
    $f_cad = $med['fecha_caducidad'] ?? '';
    $estado_semaforo = 'VERDE';
    $dias_restantes = 0;
    
    if (!empty($f_cad)) {
        $timestamp_cad = strtotime($f_cad);
        $ahora = time();
        $dias_restantes = (int)round(($timestamp_cad - $ahora) / (60 * 60 * 24));

        if ($dias_restantes < 0) {
            $estado_semaforo = 'ROJO';
        } elseif ($dias_restantes <= 180) {
            $estado_semaforo = 'ROJO';
        } elseif ($dias_restantes <= 365) {
            $estado_semaforo = 'AMARILLO';
        } else {
            $estado_semaforo = 'VERDE';
        }
    }

    if (!empty($semaforo_filtro)) {
        if (strtolower($semaforo_filtro) !== strtolower($estado_semaforo)) {
            continue;
        }
    }

    $med['semaforo_estado'] = $estado_semaforo;
    $med['dias_restantes'] = $dias_restantes;
    $medicamentos[] = $med;
}

$dependencias = [];
try {
    $dep_q = $pdo->query("SELECT DISTINCT dependencia FROM medicamentos WHERE dependencia IS NOT NULL AND dependencia != '' ORDER BY dependencia ASC");
    $dependencias = $dep_q->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Módulo de Farmacia e Inventario</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f3f4f6; font-family: 'Arial', sans-serif; margin: 0; padding: 20px; font-size: 12px; color: #111; text-transform: uppercase; }
        .container { max-width: 1550px; margin: 0 auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        .header-top { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #006847; padding-bottom: 15px; margin-bottom: 20px; }
        .header-top h2 { color: #006847; margin: 0; font-size: 22px; }

        .btn { padding: 10px 20px; background-color: #006847; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
        .btn:hover { background-color: #005338; }
        .btn-secundario { background-color: #0284c7; }
        .btn-secundario:hover { background-color: #0369a1; }
        .btn-alerta { background-color: #9f2241; }
        .btn-alerta:hover { background-color: #7f1d34; }
        .btn-exito { background-color: #16a34a; }
        .btn-exito:hover { background-color: #15803d; }
        .btn-excel-verde { background-color: #217346; }
        .btn-excel-verde:hover { background-color: #1a5c38; }

        .btn-icono { padding: 6px 10px; border-radius: 4px; color: white; border: none; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; }
        .btn-icono:hover { opacity: 0.85; }

        .toolbar-farmacia { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .form-control { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: Arial; text-transform: uppercase; box-sizing: border-box; width: 100%; }

        .tabla-general { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .tabla-general th, .tabla-general td { border: 1px solid #e5e7eb; padding: 9px 12px; text-align: left; vertical-align: middle; }
        .tabla-general th { background-color: #f1f5f9; color: #334155; font-weight: bold; cursor: pointer; user-select: none; }
        .tabla-general th:hover { background-color: #e2e8f0; }
        .tabla-general th i { margin-left: 5px; color: #64748b; font-size: 10px; }

        .badge-verde { background-color: #16a34a; color: white; padding: 5px 10px; border-radius: 4px; font-weight: bold; font-size: 10px; display: inline-block; }
        .badge-amarillo { background-color: #ca8a04; color: white; padding: 5px 10px; border-radius: 4px; font-weight: bold; font-size: 10px; display: inline-block; }
        .badge-rojo { background-color: #dc2626; color: white; padding: 5px 10px; border-radius: 4px; font-weight: bold; font-size: 10px; display: inline-block; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: #fff; width: 95%; max-width: 1050px; border-radius: 8px; overflow: hidden; box-shadow: 0 5px 25px rgba(0,0,0,0.3); }
        .modal-header { background: #006847; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; font-size: 16px; font-weight: bold; }
        .modal-close { background: none; border: none; color: white; font-size: 20px; cursor: pointer; }
        .modal-body { padding: 20px; max-height: 80vh; overflow-y: auto; }

        .tabla-surtido { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .tabla-surtido th, .tabla-surtido td { border: 1px solid #d1d5db; padding: 10px; vertical-align: middle; }
        .tabla-surtido th { background-color: #f9fafb; font-size: 11px; font-weight: bold; text-align: center; }

        .badge { padding: 5px 10px; border-radius: 4px; font-size: 10px; font-weight: bold; display: inline-block; }
        .badge-por-surtir { background-color: #fef9c3; color: #854d0e; border: 1px solid #ca8a04; }
        .badge-surtida { background-color: #dcfce7; color: #166534; border: 1px solid #15803d; }
        .badge-parcial { background-color: #e0f2fe; color: #0369a1; border: 1px solid #0284c7; }

        .historial-box { margin-top: 40px; background: #fdfdfd; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; }
        .filtros-grid { display: grid; grid-template-columns: 2fr 1.5fr 1.5fr auto auto auto auto; gap: 12px; align-items: end; margin-bottom: 20px; }

        .autocomplete-container { position: relative; }
        .autocomplete-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #cbd5e1; border-top: none; max-height: 180px; overflow-y: auto; z-index: 1050; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .autocomplete-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 11px; }
        .autocomplete-item:hover { background-color: #f1f5f9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-top">
            <h2><i class="fa-solid fa-pills"></i> Módulo de Farmacia e Inventario</h2>
            <div>
                <a href="dashboard.php" class="btn" style="background-color: #64748b;"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
            </div>
        </div>

        <?php if (!empty($mensaje_exito)): ?>
            <div style="background: #dcfce7; color: #166534; border: 1px solid #15803d; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
                <i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($mensaje_exito); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_inventario)): ?>
            <div style="background: #fee2e2; color: #991b1b; border: 1px solid #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error_inventario); ?>
            </div>
        <?php endif; ?>

        <!-- BARRA SUPERIOR: BUSCADOR, DEPENDENCIAS, SEMÁFORO Y EXCEL -->
        <form method="GET" action="" class="toolbar-farmacia">
            <div style="display: flex; gap: 10px; align-items: center; background: white; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 6px; flex: 2;">
                <i class="fa-solid fa-search" style="color: #64748b;"></i>
                <input type="text" name="q" class="form-control" placeholder="CLAVE, DESCRIPCIÓN, LOTE, PROVEEDOR..." value="<?= htmlspecialchars($busqueda); ?>" style="border: none; outline: none;">
            </div>

            <select name="dependencia" class="form-control" style="flex: 1;">
                <option value="">-- TODAS LAS DEPENDENCIAS --</option>
                <?php foreach ($dependencias as $dep): ?>
                    <option value="<?= htmlspecialchars($dep); ?>" <?= ($dependencia_filtro === $dep) ? 'selected' : ''; ?>><?= htmlspecialchars($dep); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="semaforo" class="form-control" style="flex: 1;">
                <option value="">-- SEMAFORIZACIÓN CADUCIDAD --</option>
                <option value="verde" <?= ($semaforo_filtro === 'verde') ? 'selected' : ''; ?>>VERDE (> 365 DÍAS)</option>
                <option value="amarillo" <?= ($semaforo_filtro === 'amarillo') ? 'selected' : ''; ?>>AMARILLO (180 A 365 DÍAS)</option>
                <option value="rojo" <?= ($semaforo_filtro === 'rojo') ? 'selected' : ''; ?>>ROJO (< 180 DÍAS / CADUCADO)</option>
            </select>

            <button type="submit" class="btn" style="background-color: #006847; padding: 8px 16px;"><i class="fa-solid fa-filter"></i> Filtrar</button>
            <a href="farmacia.php" class="btn" style="background-color: #64748b; padding: 8px 16px;"><i class="fa-solid fa-rotate-right"></i> Limpiar</a>

            <div style="margin-left: auto;">
                <button type="button" class="btn btn-secundario" onclick="abrirModalCargarInventario()" style="padding: 8px 16px;">
                    <i class="fa-solid fa-file-excel"></i> Cargar / Actualizar Inventario
                </button>
            </div>
        </form>

        <!-- TABLA DE INVENTARIO CON ORDENAMIENTO -->
        <div style="max-height: 400px; overflow-y: auto; margin-bottom: 30px;">
            <table class="tabla-general" id="tablaInventario">
                <thead>
                    <tr>
                        <th onclick="sortTable('tablaInventario', 0)">Dependencia <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 1)">Clave <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 2)">Descripción <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 3)">Grupo <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 4)">Existencia <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 5)">Precio Unitario <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 6)">Lote <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 7)">Proveedor <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 8)">F. Caducidad <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 9)">Orden Suministro <i class="fa-solid fa-sort"></i></th>
                        <th onclick="sortTable('tablaInventario', 10)">Semaforización (Días) <i class="fa-solid fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($medicamentos)): ?>
                        <?php foreach ($medicamentos as $med): ?>
                            <?php 
                                $f_cad = $med['fecha_caducidad'] ?? '';
                                $estado = $med['semaforo_estado'];
                                $dias = $med['dias_restantes'];
                                $badge_class = 'badge-verde';
                                
                                if ($estado === 'AMARILLO') {
                                    $badge_class = 'badge-amarillo';
                                } elseif ($estado === 'ROJO') {
                                    $badge_class = 'badge-rojo';
                                }

                                $texto_dias = ($dias < 0) ? 'CADUCADO (HACE ' . abs($dias) . ' DÍAS)' : $dias . ' DÍAS RESTANTES';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($med['dependencia'] ?? 'IMSS-BIENESTAR'); ?></td>
                                <td style="font-weight: bold;"><?= htmlspecialchars($med['clave_cuadro_basico'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($med['descripcion'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($med['grupo'] ?? ''); ?></td>
                                <td style="font-weight: bold; text-align: center;"><?= number_format($med['existencia'] ?? 0); ?></td>
                                <td>$<?= number_format($med['precio_unitario'] ?? 0, 2); ?></td>
                                <td><?= htmlspecialchars($med['lote'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($med['proveedor'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($f_cad); ?></td>
                                <td><?= htmlspecialchars($med['orden_suministro'] ?? ''); ?></td>
                                <td style="text-align: center;">
                                    <span class="<?= $badge_class; ?>" title="Fecha de Caducidad: <?= $f_cad ?>"><?= $texto_dias; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" style="text-align: center; color: #64748b; padding: 25px;">No se encontraron medicamentos en el inventario.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- MÓDULO DE HISTORIAL DE RECETAS SURTIDAS -->
        <div class="historial-box">
            <h3 style="margin-top:0; color:#006847;"><i class="fa-solid fa-clock-rotate-left"></i> Consulta de Recetas Surtidas y Parciales</h3>
            
            <div class="filtros-grid">
                <div>
                    <label style="font-weight:bold; margin-bottom:5px; display:block;">Filtrar por Periodo:</label>
                    <select id="filtroPeriodo" class="form-control" onchange="cambiarFiltroPeriodo(this.value)">
                        <option value="hoy">HOY</option>
                        <option value="semana">ESTA SEMANA</option>
                        <option value="mes">ESTE MES</option>
                        <option value="especifica">FECHA ESPECÍFICA</option>
                        <option value="rango">RANGO DE FECHAS</option>
                        <option value="todos">TODOS LOS REGISTROS</option>
                    </select>
                </div>
                <div id="divFechaIni" style="display:none;">
                    <label style="font-weight:bold; margin-bottom:5px; display:block;" id="lblFechaIni">Fecha:</label>
                    <input type="date" id="inputFechaIni" class="form-control" value="<?= date('Y-m-d'); ?>">
                </div>
                <div id="divFechaFin" style="display:none;">
                    <label style="font-weight:bold; margin-bottom:5px; display:block;">Fecha Fin:</label>
                    <input type="date" id="inputFechaFin" class="form-control" value="<?= date('Y-m-d'); ?>">
                </div>
                <div>
                    <button type="button" class="btn" onclick="cargarHistorialRecetas()"><i class="fa-solid fa-search"></i> Consultar</button>
                </div>
                <div>
                    <button type="button" class="btn btn-excel-verde" onclick="exportarExcelSurtidos()"><i class="fa-solid fa-file-excel"></i> Exportar Surtidos (Excel)</button>
                </div>
                <div>
                    <button type="button" class="btn btn-secundario" onclick="abrirModalRecetaNueva()"><i class="fa-solid fa-file-circle-plus"></i> Surtir Receta Nueva</button>
                </div>
                <div>
                    <button type="button" class="btn" style="background-color: #15803d;" onclick="abrirModalSurtido()"><i class="fa-solid fa-notes-medical"></i> Surtir por Folio</button>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="tabla-general" id="tablaHistorial">
                    <thead>
                        <tr>
                            <th onclick="sortTable('tablaHistorial', 0)">Folio <i class="fa-solid fa-sort"></i></th>
                            <th onclick="sortTable('tablaHistorial', 1)">Fecha Creación <i class="fa-solid fa-sort"></i></th>
                            <th onclick="sortTable('tablaHistorial', 2)">Paciente <i class="fa-solid fa-sort"></i></th>
                            <th onclick="sortTable('tablaHistorial', 3)">Médico Tratante <i class="fa-solid fa-sort"></i></th>
                            <th onclick="sortTable('tablaHistorial', 4)">Estatus <i class="fa-solid fa-sort"></i></th>
                            <th>Medicamentos y Cantidades</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tablaHistorialBody">
                        <tr>
                            <td colspan="7" style="text-align: center; color: #64748b; padding: 20px;">Cargando registros...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL DE CARGA / ACTUALIZACIÓN DE INVENTARIO -->
    <div class="modal-overlay" id="modalCargarInventario">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header" style="background-color: #0284c7;">
                <span><i class="fa-solid fa-file-excel"></i> Asistente de Carga de Inventario</span>
                <button class="modal-close" onclick="cerrarModalCargarInventario()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="accion_cargar_inventario" value="1">
                    
                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: bold; display: block; margin-bottom: 8px;">Seleccione el archivo (.XLSX / .CSV):</label>
                        <input type="file" name="archivo_inventario" class="form-control" accept=".xlsx, .xls, .csv" required style="padding: 6px;">
                        <small style="color: #64748b; display: block; margin-top: 4px;">Estructura configurada: Clave Cuadro Básico, Descripción, Grupo, Existencia, Precio Unitario, Importe, Lote, Proveedor, RFC, Almacén, Clues, Fuente, Caducidad, Orden Suministro, etc.</small>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: bold; display: block; margin-bottom: 8px;">Modo de Carga:</label>
                        <select name="modo_carga" class="form-control" required>
                            <option value="actualizar">ACTUALIZAR / SUMAR CANTIDADES (Respeta catálogo existente, suma por lote, proveedor, orden de suministro y caducidad)</option>
                            <option value="sustituir">SUSTITUIR TODA LA TABLA (Elimina inventario actual y carga el archivo nuevo)</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 20px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <label style="font-weight: bold; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="eliminar_caducados" value="1" style="width: 16px; height: 16px;"> 
                            Eliminar registros de medicamentos ya caducados del inventario
                        </label>
                    </div>

                    <div style="text-align: right;">
                        <button type="button" class="btn btn-alerta" onclick="cerrarModalCargarInventario()" style="margin-right: 10px;">Cancelar</button>
                        <button type="submit" class="btn btn-secundario"><i class="fa-solid fa-upload"></i> Procesar Inventario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL DE SURTIDO POR FOLIO EXISTENTE -->
    <div class="modal-overlay" id="modalSurtido">
        <div class="modal-content" style="max-width: 1100px;">
            <div class="modal-header">
                <span id="modalTitulo"><i class="fa-solid fa-prescription-bottle-medical"></i> Surtir Receta Médica por Folio y Selección de Lote</span>
                <button class="modal-close" onclick="cerrarModalSurtido()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: flex; gap: 10px; margin-bottom: 20px;" id="buscadorFolioContainer">
                    <input type="text" id="inputFolioBuscar" class="form-control" placeholder="INGRESE O BUSQUE EL FOLIO DE LA RECETA..." style="font-size: 14px; font-weight: bold;">
                    <button type="button" class="btn" onclick="buscarRecetaFolio()"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                </div>

                <div id="detalleRecetaContainer" style="display: none;">
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; margin-bottom: 15px;">
                        <strong>Paciente:</strong> <span id="lblPaciente" style="font-weight: normal;"></span><br>
                        <strong>Médico:</strong> <span id="lblMedico" style="font-weight: normal;"></span><br>
                        <strong>Estatus Actual:</strong> <span id="lblEstatusActual"></span>
                    </div>

                    <form id="formSurtirReceta" onsubmit="guardarSurtidoReceta(event)">
                        <input type="hidden" name="accion_surtir" value="1">
                        <input type="hidden" name="folio_surtir" id="inputFolioSurtir">

                        <table class="tabla-surtido">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Medicamento / Clave</th>
                                    <th style="width: 10%; text-align: center;">Estatus</th>
                                    <th style="width: 10%; text-align: center;">Solicitada</th>
                                    <th style="width: 50%;">Lotes Disponibles (Ordenados por Caducidad Próxima)</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyMedicamentos">
                                <!-- Dinámico -->
                            </tbody>
                        </table>

                        <div style="text-align: right; margin-top: 20px;">
                            <button type="button" class="btn btn-alerta" onclick="cerrarModalSurtido()" style="margin-right: 10px;">Cancelar</button>
                            <button type="submit" class="btn btn-exito"><i class="fa-solid fa-check"></i> Guardar y Descontar Inventario por Lote</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DE SURTIDO DE RECETA NUEVA / EXTERNA -->
    <div class="modal-overlay" id="modalRecetaNueva">
        <div class="modal-content" style="max-width: 1100px;">
            <div class="modal-header" style="background-color: #0284c7;">
                <span><i class="fa-solid fa-file-circle-plus"></i> Surtir Receta Nueva o Externa (Manual)</span>
                <button class="modal-close" onclick="cerrarModalRecetaNueva()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="formRecetaNueva" onsubmit="guardarRecetaNueva(event)">
                    <input type="hidden" name="accion_guardar_externa" value="1">

                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
                        <div>
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">Folio Automático:</label>
                            <input type="text" name="nuevo_folio" id="inputFolioAutomatico" class="form-control" readonly style="font-weight: bold; background: #f1f5f9; color: #9f2241;">
                        </div>
                        <div style="grid-column: span 2;">
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">Nombre del Paciente (Opcional):</label>
                            <input type="text" name="nuevo_paciente" class="form-control" placeholder="NOMBRE COMPLETO (S/N SI SE OMITE)">
                        </div>
                        <div>
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">Edad (Opcional):</label>
                            <input type="number" name="nuevo_edad" class="form-control" min="0" max="120" value="0" placeholder="0">
                        </div>
                        <div style="grid-column: span 4;">
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">Médico Tratante (Opcional):</label>
                            <input type="text" name="nuevo_medico" class="form-control" placeholder="NOMBRE DEL MÉDICO (S/N SI SE OMITE)">
                        </div>
                    </div>

                    <h4 style="color: #006847; border-bottom: 1px solid #cbd5e1; padding-bottom: 5px; margin-bottom: 15px;">Captura de Medicamentos (Búsqueda en Inventario con Semáforo de Caducidad)</h4>

                    <table class="tabla-surtido">
                        <thead>
                            <tr>
                                <th style="width: 50%;">Medicamento (Buscar Descripción o Clave)</th>
                                <th style="width: 15%; text-align: center;">Cant. Solicitada</th>
                                <th style="width: 15%; text-align: center;">Cant. a Surtir</th>
                                <th style="width: 20%; text-align: center;">Stock Disponible</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="autocomplete-container">
                                        <input type="text" id="bus_med_1" class="form-control" placeholder="BUSCAR MEDICAMENTO 1..." onkeyup="buscarMedicamentosAutocomplete(this, 1)">
                                        <input type="hidden" name="id_med_1" id="id_med_1">
                                        <input type="hidden" name="cve_med_1" id="cve_med_1">
                                        <input type="hidden" name="nom_med_1" id="nom_med_1">
                                        <div id="results_1" class="autocomplete-results"></div>
                                    </div>
                                </td>
                                <td><input type="number" name="sol_1" min="0" value="0" class="form-control" style="text-align: center; font-weight: bold;"></td>
                                <td><input type="number" name="sur_1" min="0" value="0" class="form-control" style="text-align: center; font-weight: bold; background: #f0fdf4;"></td>
                                <td style="text-align: center; font-weight: bold; color: #166534;" id="stock_1">--</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="autocomplete-container">
                                        <input type="text" id="bus_med_2" class="form-control" placeholder="BUSCAR MEDICAMENTO 2 (OPCIONAL)..." onkeyup="buscarMedicamentosAutocomplete(this, 2)">
                                        <input type="hidden" name="id_med_2" id="id_med_2">
                                        <input type="hidden" name="cve_med_2" id="cve_med_2">
                                        <input type="hidden" name="nom_med_2" id="nom_med_2">
                                        <div id="results_2" class="autocomplete-results"></div>
                                    </div>
                                </td>
                                <td><input type="number" name="sol_2" min="0" value="0" class="form-control" style="text-align: center; font-weight: bold;"></td>
                                <td><input type="number" name="sur_2" min="0" value="0" class="form-control" style="text-align: center; font-weight: bold; background: #f0fdf4;"></td>
                                <td style="text-align: center; font-weight: bold; color: #166534;" id="stock_2">--</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="autocomplete-container">
                                        <input type="text" id="bus_med_3" class="form-control" placeholder="BUSCAR MEDICAMENTO 3 (OPCIONAL)..." onkeyup="buscarMedicamentosAutocomplete(this, 3)">
                                        <input type="hidden" name="id_med_3" id="id_med_3">
                                        <input type="hidden" name="cve_med_3" id="cve_med_3">
                                        <input type="hidden" name="nom_med_3" id="nom_med_3">
                                        <div id="results_3" class="autocomplete-results"></div>
                                    </div>
                                </td>
                                <td><input type="number" name="sol_3" min="0" value="0" class="form-control" style="text-align: center; font-weight: bold;"></td>
                                <td><input type="number" name="sur_3" min="0" value="0" class="form-control" style="text-align: center; font-weight: bold; background: #f0fdf4;"></td>
                                <td style="text-align: center; font-weight: bold; color: #166534;" id="stock_3">--</td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="text-align: right; margin-top: 25px;">
                        <button type="button" class="btn btn-alerta" onclick="cerrarModalRecetaNueva()" style="margin-right: 10px;">Cancelar</button>
                        <button type="submit" class="btn btn-secundario"><i class="fa-solid fa-check"></i> Registrar y Surtir Receta Externa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function abrirModalCargarInventario() {
            document.getElementById('modalCargarInventario').style.display = 'flex';
        }
        function cerrarModalCargarInventario() {
            document.getElementById('modalCargarInventario').style.display = 'none';
        }

        function exportarExcelSurtidos() {
            let filtro = document.getElementById('filtroPeriodo').value;
            let fechaIni = document.getElementById('inputFechaIni').value;
            let fechaFin = document.getElementById('inputFechaFin').value;

            let url = `farmacia.php?export_excel_surtidos=1&filtro=${filtro}&fecha_ini=${fechaIni}&fecha_fin=${fechaFin}`;
            window.location.href = url;
        }

        function abrirModalSurtido(folioEditar = null) {
            document.getElementById('modalSurtido').style.display = 'flex';
            document.getElementById('detalleRecetaContainer').style.display = 'none';
            document.getElementById('buscadorFolioContainer').style.display = 'flex';
            document.getElementById('inputFolioBuscar').value = '';
            if (folioEditar) {
                document.getElementById('inputFolioBuscar').value = folioEditar;
                buscarRecetaFolio();
            }
        }
        function cerrarModalSurtido() {
            document.getElementById('modalSurtido').style.display = 'none';
        }

        function abrirModalRecetaNueva() {
            document.getElementById('formRecetaNueva').reset();
            let fechaActual = new Date();
            let yyyy = fechaActual.getFullYear();
            let mm = String(fechaActual.getMonth() + 1).padStart(2, '0');
            let dd = String(fechaActual.getDate()).padStart(2, '0');
            let randNum = Math.floor(1000 + Math.random() * 9000);
            let folioAuto = `EXT-${yyyy}${mm}${dd}-${randNum}`;

            document.getElementById('inputFolioAutomatico').value = folioAuto;
            document.getElementById('id_med_1').value = '';
            document.getElementById('id_med_2').value = '';
            document.getElementById('id_med_3').value = '';
            document.getElementById('stock_1').innerText = '--';
            document.getElementById('stock_2').innerText = '--';
            document.getElementById('stock_3').innerText = '--';
            document.getElementById('modalRecetaNueva').style.display = 'flex';
        }
        function cerrarModalRecetaNueva() {
            document.getElementById('modalRecetaNueva').style.display = 'none';
        }

        function buscarMedicamentosAutocomplete(input, index) {
            let term = input.value.trim();
            let resultsDiv = document.getElementById('results_' + index);
            if (term.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }

            $.ajax({
                url: 'farmacia.php',
                type: 'GET',
                data: { ajax_buscar_medicamento: 1, term: term },
                dataType: 'json',
                success: function(data) {
                    resultsDiv.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(m => {
                            let item = document.createElement('div');
                            item.className = 'autocomplete-item';

                            let badgeColor = 'badge-verde';
                            if (m.semaforo_estado === 'AMARILLO') badgeColor = 'badge-amarillo';
                            if (m.semaforo_estado === 'ROJO') badgeColor = 'badge-rojo';

                            let cadText = m.fecha_caducidad ? `${m.fecha_caducidad} (${m.dias_restantes < 0 ? 'Caducado' : m.dias_restantes + ' días'})` : 'S/Cad';

                            item.innerHTML = `<strong>${m.clave_cuadro_basico || 'S/C'}</strong> - ${m.descripcion}<br>
                                              <span style="color:#0284c7; font-size:10px;">Stock: <strong>${m.existencia}</strong> | Lote: <strong>${m.lote || 'S/N'}</strong> | <span class="${badgeColor}" style="font-size:9px; padding:2px 5px;">Cad: ${cadText}</span></span>`;

                            item.onclick = function() {
                                document.getElementById('bus_med_' + index).value = `[${m.clave_cuadro_basico || 'S/C'}] ${m.descripcion} (Lote: ${m.lote || 'N/D'} - Cad: ${m.fecha_caducidad || 'N/D'})`;
                                document.getElementById('id_med_' + index).value = m.id_medicamento;
                                document.getElementById('cve_med_' + index).value = m.clave_cuadro_basico || '';
                                document.getElementById('nom_med_' + index).value = m.descripcion;
                                document.getElementById('stock_' + index).innerText = m.existencia;
                                resultsDiv.style.display = 'none';
                            };
                            resultsDiv.appendChild(item);
                        });
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.style.display = 'none';
                    }
                }
            });
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.autocomplete-container')) {
                document.querySelectorAll('.autocomplete-results').forEach(d => d.style.display = 'none');
            }
        });

        function guardarRecetaNueva(e) {
            e.preventDefault();
            let formData = $('#formRecetaNueva').serialize();

            $.ajax({
                url: 'farmacia.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        alert(res.message);
                        cerrarModalRecetaNueva();
                        cargarHistorialRecetas();
                    } else {
                        alert('Error: ' + res.message);
                    }
                }
            });
        }

        function cambiarFiltroPeriodo(val) {
            document.getElementById('divFechaIni').style.display = 'none';
            document.getElementById('divFechaFin').style.display = 'none';
            if (val === 'especifica') {
                document.getElementById('divFechaIni').style.display = 'block';
                document.getElementById('lblFechaIni').innerText = 'Fecha Específica:';
            } else if (val === 'rango') {
                document.getElementById('divFechaIni').style.display = 'block';
                document.getElementById('divFechaFin').style.display = 'block';
                document.getElementById('lblFechaIni').innerText = 'Fecha Inicio:';
            }
        }

        function cargarHistorialRecetas() {
            let filtro = document.getElementById('filtroPeriodo').value;
            let fechaIni = document.getElementById('inputFechaIni').value;
            let fechaFin = document.getElementById('inputFechaFin').value;

            $.ajax({
                url: 'farmacia.php',
                type: 'GET',
                data: { ajax_filtrar_recetas: 1, filtro: filtro, fecha_ini: fechaIni, fecha_fin: fechaFin },
                dataType: 'json',
                success: function(response) {
                    let tbody = document.getElementById('tablaHistorialBody');
                    tbody.innerHTML = '';
                    if (response.success && response.datos.length > 0) {
                        response.datos.forEach(r => {
                            let badgeClass = r.estatus_surtido === 'SURTIDA' ? 'badge-surtida' : (r.estatus_surtido === 'SURTIDA PARCIAL' ? 'badge-parcial' : 'badge-por-surtir');
                            let meds = [];
                            if (r.medicamento_1) meds.push(`• ${r.medicamento_1} (Sol: ${r.solicitada_1}, Surt: ${r.surtida_1 || 0})`);
                            if (r.medicamento_2) meds.push(`• ${r.medicamento_2} (Sol: ${r.solicitada_2}, Surt: ${r.surtida_2 || 0})`);
                            if (r.medicamento_3) meds.push(`• ${r.medicamento_3} (Sol: ${r.solicitada_3}, Surt: ${r.surtida_3 || 0})`);

                            let fechaCreacion = r.fecha_creacion || 'S/F';

                            let tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td style="font-weight:bold; color:#9f2241;">${r.folio}</td>
                                <td>${fechaCreacion}</td>
                                <td>${r.nombre_paciente || 'S/N'}</td>
                                <td>${r.medico_tratante || 'S/N'}</td>
                                <td><span class="badge ${badgeClass}">${r.estatus_surtido || 'POR SURTIR'}</span></td>
                                <td style="font-size: 11px; line-height: 1.4;">${meds.join('<br>') || 'Sin medicamentos'}</td>
                                <td style="text-align: center; white-space: nowrap;">
                                    <button type="button" class="btn-icono btn-secundario" title="Editar / Surtir Receta" onclick="abrirModalSurtido('${r.folio}')"><i class="fa-solid fa-pen-to-square"></i></button>
                                    <button type="button" class="btn-icono btn-alerta" title="Eliminar Receta y Devolver Stock" onclick="eliminarReceta('${r.folio}')"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    } else {
                        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: #64748b; padding: 20px;">No se encontraron recetas surtidas en este periodo.</td></tr>`;
                    }
                }
            });
        }

        function eliminarReceta(folio) {
            if (confirm('¿Está seguro de eliminar la receta con folio ' + folio + '? Esta acción devolverá los medicamentos surtidos al inventario.')) {
                $.ajax({
                    url: 'farmacia.php',
                    type: 'GET',
                    data: { ajax_eliminar_receta: 1, folio: folio },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            alert(res.message);
                            cargarHistorialRecetas();
                        } else {
                            alert('Error: ' + res.message);
                        }
                    }
                });
            }
        }

        function buscarRecetaFolio() {
            let folio = document.getElementById('inputFolioBuscar').value.trim();
            if (!folio) {
                alert('Por favor ingrese un número de folio.');
                return;
            }

            $.ajax({
                url: 'farmacia.php',
                type: 'GET',
                data: { ajax_buscar_receta: 1, folio: folio },
                dataType: 'json',
                success: function(res) {
                    if (res.encontrada) {
                        let r = res.receta;
                        document.getElementById('inputFolioSurtir').value = r.folio;
                        document.getElementById('lblPaciente').innerText = (r.nombre_paciente || 'S/N') + ' (EDAD: ' + (r.edad_paciente || '0') + ')';
                        document.getElementById('lblMedico').innerText = r.medico_tratante || 'S/N';
                        
                        let badgeClass = r.estatus_surtido === 'SURTIDA' ? 'badge-surtida' : (r.estatus_surtido === 'SURTIDA PARCIAL' ? 'badge-parcial' : 'badge-por-surtir');
                        document.getElementById('lblEstatusActual').innerHTML = `<span class="badge ${badgeClass}">${r.estatus_surtido || 'POR SURTIR'}</span>`;

                        let tbody = document.getElementById('tbodyMedicamentos');
                        tbody.innerHTML = '';

                        let items = [
                            { med: r.medicamento_1, cve: r.clave_1, sol: parseInt(r.solicitada_1 || 0), sur: parseInt(r.surtida_1 || 0), lotes: r.lotes_1 || [], num: 1 },
                            { med: r.medicamento_2, cve: r.clave_2, sol: parseInt(r.solicitada_2 || 0), sur: parseInt(r.surtida_2 || 0), lotes: r.lotes_2 || [], num: 2 },
                            { med: r.medicamento_3, cve: r.clave_3, sol: parseInt(r.solicitada_3 || 0), sur: parseInt(r.surtida_3 || 0), lotes: r.lotes_3 || [], num: 3 }
                        ];

                        items.forEach(item => {
                            if (item.med && item.sol > 0) {
                                let faltaPorSurtir = Math.max(0, item.sol - item.sur);
                                let estatusItem = item.sur >= item.sol ? 'SURTIDA' : (item.sur > 0 ? 'PARCIAL' : 'POR SURTIR');
                                let badgeItem = item.sur >= item.sol ? 'badge-surtida' : (item.sur > 0 ? 'badge-parcial' : 'badge-por-surtir');
                                
                                let htmlLotes = '';
                                if (item.lotes.length > 0) {
                                    htmlLotes = `<table style="width:100%; border-collapse:collapse; background:#fff;">
                                        <thead>
                                            <tr style="background:#f1f5f9; font-size:10px;">
                                                <th style="padding:5px; border:1px solid #cbd5e1;">Lote / Prov.</th>
                                                <th style="padding:5px; border:1px solid #cbd5e1; text-align:center;">F. Caducidad / Semáforo</th>
                                                <th style="padding:5px; border:1px solid #cbd5e1; text-align:center;">Stock</th>
                                                <th style="padding:5px; border:1px solid #cbd5e1; text-align:center; width:100px;">A Surtir</th>
                                            </tr>
                                        </thead>
                                        <tbody>`;
                                    
                                    item.lotes.forEach(lote => {
                                        let badgeColor = 'badge-verde';
                                        if (lote.semaforo_estado === 'AMARILLO') badgeColor = 'badge-amarillo';
                                        if (lote.semaforo_estado === 'ROJO') badgeColor = 'badge-rojo';
                                        
                                        let diasTexto = lote.dias_restantes < 0 ? `CADUCADO` : `${lote.dias_restantes} DÍAS`;

                                        htmlLotes += `<tr>
                                            <td style="padding:5px; border:1px solid #e2e8f0; font-size:10px;">
                                                <strong>Lote:</strong> ${lote.lote || 'S/N'}<br>
                                                <span style="color:#64748b;">${lote.proveedor || ''}</span>
                                            </td>
                                            <td style="padding:5px; border:1px solid #e2e8f0; text-align:center;">
                                                <span class="${badgeColor}" style="font-size:9px; padding:3px 6px;">${lote.fecha_caducidad || 'S/F'} (${diasTexto})</span>
                                            </td>
                                            <td style="padding:5px; border:1px solid #e2e8f0; text-align:center; font-weight:bold;">${lote.existencia}</td>
                                            <td style="padding:5px; border:1px solid #e2e8f0; text-align:center;">
                                                <input type="number" name="lote_surtir[${lote.id_medicamento}]" min="0" max="${Math.min(lote.existencia, faltaPorSurtir)}" value="0" class="form-control" style="text-align:center; font-weight:bold; padding:4px;">
                                            </td>
                                        </tr>`;
                                    });
                                    htmlLotes += `</tbody></table>`;
                                } else {
                                    htmlLotes = `<span style="color:#dc2626; font-size:11px;">No hay lotes registrados en inventario para este medicamento.</span>`;
                                }

                                let tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>
                                        <div style="font-weight: bold; font-size: 11px;">${item.med}</div>
                                        <div style="font-size: 10px; color: #6b7280;">CLAVE: ${item.cve || 'S/N'}</div>
                                    </td>
                                    <td style="text-align: center;"><span class="badge ${badgeItem}">${estatusItem}</span></td>
                                    <td style="text-align: center; font-weight: bold;">
                                        ${item.sol}<br>
                                        <small style="font-size:9px; color:#555;">Surtido: ${item.sur}</small>
                                    </td>
                                    <td>${htmlLotes}</td>
                                `;
                                tbody.appendChild(tr);
                            }
                        });

                        document.getElementById('buscadorFolioContainer').style.display = 'none';
                        document.getElementById('detalleRecetaContainer').style.display = 'block';
                    } else {
                        alert('No se encontró ninguna receta registrada con el folio: ' + folio);
                    }
                }
            });
        }

        function guardarSurtidoReceta(e) {
            e.preventDefault();
            let formData = $('#formSurtirReceta').serialize();

            $.ajax({
                url: 'farmacia.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        alert(res.message);
                        cerrarModalSurtido();
                        cargarHistorialRecetas();
                    } else {
                        alert('Error: ' + res.message);
                    }
                }
            });
        }

        function sortTable(tableId, colIndex) {
            let table = document.getElementById(tableId);
            let switching = true;
            let dir = "asc";
            let switchCount = 0;

            while (switching) {
                switching = false;
                let rows = table.rows;
                for (let i = 1; i < (rows.length - 1); i++) {
                    let shouldSwitch = false;
                    let x = rows[i].getElementsByTagName("TD")[colIndex];
                    let y = rows[i + 1].getElementsByTagName("TD")[colIndex];
                    if (!x || !y) continue;

                    let xVal = x.textContent || x.innerText;
                    let yVal = y.textContent || y.innerText;

                    if (dir === "asc") {
                        if (xVal.toLowerCase() > yVal.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    } else if (dir === "desc") {
                        if (xVal.toLowerCase() < yVal.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                }
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    switchCount++;
                } else {
                    if (switchCount === 0 && dir === "asc") {
                        dir = "desc";
                        switching = true;
                    }
                }
            }
        }

        $(document).ready(function() {
            cargarHistorialRecetas();
        });
    </script>
</body>
</html>