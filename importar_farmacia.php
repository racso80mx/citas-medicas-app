<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'conexion.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dependencia = $_POST['dependencia'] ?? 'IMSS-BIENESTAR';
    
    if (isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['archivo_excel']['tmp_name'];
        
        try {
            $spreadsheet = IOFactory::load($fileTmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            
            if (count($rows) <= 1) {
                header("Location: farmacia.php?error=vacio");
                exit();
            }
            
            // Función para normalizar textos de encabezados (eliminar acentos y espacios extra)
            $normalizarTexto = function($str) {
                $str = mb_strtoupper(trim($str), 'UTF-8');
                $search  = ['Á', 'É', 'Í', 'Ó', 'Ú', 'À', 'È', 'Ì', 'Ò', 'Ù', 'Ä', 'Ë', 'Ï', 'Ö', 'Ü', 'Ñ'];
                $replace = ['A', 'E', 'I', 'O', 'U', 'A', 'E', 'I', 'O', 'U', 'A', 'E', 'I', 'O', 'U', 'N'];
                $str = str_replace($search, $replace, $str);
                return preg_replace('/\s+/', ' ', $str);
            };

            // Mapear encabezados de la fila 1 normalizados
            $rawHeader = $rows[1];
            $headers = [];
            foreach ($rawHeader as $colKey => $colName) {
                $headers[$colKey] = $normalizarTexto($colName);
            }
            
            // Borrar registros previos de esa dependencia
            $stmt_del = $pdo->prepare("DELETE FROM medicamentos WHERE dependencia = :dep");
            $stmt_del->execute(['dep' => $dependencia]);
            
            $pdo->beginTransaction();
            
            $sql = "INSERT INTO medicamentos (
                clave_cuadro_basico, descripcion, grupo, existencia, precio_unitario, 
                total_importe, lote, proveedor, rfc_proveedor, almacen, clues, 
                fuente_financiamiento, fecha_caducidad, orden_suministro, tipo_insumo, 
                numero_contrato, folio, fecha_entrada, dependencia
            ) VALUES (
                :clave, :desc, :grupo, :existencia, :precio, :total, :lote, :prov, 
                :rfc, :almacen, :clues, :fuente, :caducidad, :orden, :tipo, :contrato, 
                :folio, :fentrada, :dependencia
            )";
            $stmt = $pdo->prepare($sql);
            
            $parseFecha = function($val) {
                if (empty($val)) return null;
                if (is_numeric($val)) {
                    try {
                        $dt = SharedDate::excelToDateTimeObject($val);
                        return $dt->format('Y-m-d');
                    } catch (Exception $e) {
                        return null;
                    }
                }
                $valStr = trim((string)$val);
                if ($valStr === '' || $valStr === '0000-00-00' || $valStr === '00/00/0000') return null;
                
                if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $valStr, $matches)) {
                    $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $anio = $matches[3];
                    if (checkdate((int)$mes, (int)$dia, (int)$anio)) {
                        return "$anio-$mes-$dia";
                    }
                }
                $timestamp = strtotime($valStr);
                if ($timestamp !== false && $timestamp > 0) {
                    return date('Y-m-d', $timestamp);
                }
                return null;
            };
            
            for ($i = 2; $i <= count($rows); $i++) {
                $row = $rows[$i];
                
                // Función flexible para buscar coincidencias ignorando acentos
                $getData = function($busqueda) use ($row, $headers, $normalizarTexto) {
                    $busquedaNorm = $normalizarTexto($busqueda);
                    foreach ($headers as $key => $hName) {
                        if ($hName === $busquedaNorm || strpos($hName, $busquedaNorm) !== false) {
                            return trim($row[$key] ?? '');
                        }
                    }
                    return '';
                };
                
                $clave      = $getData('CLAVE DE CUADRO BASICO');
                $descripcion= $getData('DESCRIPCION'); // Detectará DESCRIPCIÓN con o sin tilde
                $grupo      = $getData('GRUPO');
                $existencia = floatval(str_replace(',', '', $getData('EXISTENCIA')));
                $precio     = floatval(str_replace(',', '', $getData('PRECIO UNITARIO')));
                $importe    = floatval(str_replace(',', '', $getData('TOTAL IMPORTE')));
                $lote       = $getData('LOTE');
                $proveedor  = $getData('PROVEEDOR');
                $rfc        = $getData('RFC PROVEE') ?: $getData('RFC PROVEEDOR');
                $almacen    = $getData('ALMACEN');
                $clues      = $getData('CLUES');
                $fuente     = $getData('FUENTE FINANCIAMIENTO');
                
                $caducidad  = $parseFecha($getData('FECHA CADUCIDAD'));
                $fentrada   = $parseFecha($getData('FECHA ENTRADA'));
                
                $orden      = $getData('ORDEN SUMINISTRO');
                $tipo_ins   = $getData('TIPO INSUMO') ?: $getData('TIPO_INSUMO');
                $contrato   = $getData('NUMERO DE CONTRATO');
                $folio      = $getData('FOLIO');
                
                if (empty($clave) && empty($descripcion)) continue;
                
                $stmt->execute([
                    'clave'       => $clave,
                    'desc'        => $descripcion,
                    'grupo'       => $grupo,
                    'existencia'  => $existencia,
                    'precio'      => $precio,
                    'total'       => $importe,
                    'lote'        => $lote,
                    'prov'        => $proveedor,
                    'rfc'         => $rfc,
                    'almacen'     => $almacen,
                    'clues'       => $clues,
                    'fuente'      => $fuente,
                    'caducidad'   => $caducidad,
                    'orden'       => $orden,
                    'tipo'        => $tipo_ins,
                    'contrato'    => $contrato,
                    'folio'       => $folio,
                    'fentrada'    => $fentrada,
                    'dependencia' => $dependencia
                ]);
            }
            
            $pdo->commit();
            header("Location: farmacia.php?exito=1");
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header("Location: farmacia.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    }
}