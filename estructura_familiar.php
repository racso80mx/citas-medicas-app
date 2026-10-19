<?php
// ===================================================================
// 4. ESTRUCTURA FAMILIAR Y FAMILIOGRAMA - FORMATO 4
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

// Obtener datos del paciente
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

// Obtener listado de estudios previos
try {
    $stmt_lista = $pdo->prepare("SELECT id_estudio, fecha_estudio FROM estudio_socioeconomico WHERE id_paciente = :id_paciente ORDER BY fecha_estudio DESC, id_estudio DESC");
    $stmt_lista->execute(['id_paciente' => $id_paciente]);
    $estudios_previos = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $estudios_previos = [];
}

if ($id_estudio <= 0 && !empty($estudios_previos)) {
    $id_estudio = $estudios_previos[0]['id_estudio'];
}

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

// Procesar guardado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_formato4') {
    try {
        $txt = function($campo) {
            return mb_strtoupper(trim($_POST[$campo] ?? ''), 'UTF-8');
        };

        $fecha_null = function($campo) {
            $val = trim($_POST[$campo] ?? '');
            return ($val === '') ? null : $val;
        };

        $id_estudio_post = isset($_POST['id_estudio']) ? intval($_POST['id_estudio']) : 0;
        if ($id_estudio_post <= 0) {
            $id_estudio_post = isset($_GET['id_estudio']) ? intval($_GET['id_estudio']) : 0;
        }

        $params = [
            'id_estudio' => $id_estudio_post,
            'id_paciente' => $id_paciente,
            'nombre_completo' => $txt('nombre_completo'),
            'edad' => intval($_POST['edad'] ?? 0),
            'fecha_nacimiento' => $fecha_null('fecha_nacimiento'),
            'domicilio' => $txt('domicilio'),
            'formato4_observaciones_familiograma' => $txt('formato4_observaciones_familiograma'),
            'formato4_entrevistado_nombre' => $txt('formato4_entrevistado_nombre')
        ];

        $sql_familiares_sets = [];
        for ($i = 1; $i <= 8; $i++) {
            $params["f{$i}_nombre"] = mb_strtoupper(trim($_POST['fam_nombre'][$i] ?? ''), 'UTF-8');
            $params["f{$i}_parentesco"] = mb_strtoupper(trim($_POST['fam_parentesco'][$i] ?? ''), 'UTF-8');
            $params["f{$i}_edad"] = intval($_POST['fam_edad'][$i] ?? 0);
            $params["f{$i}_genero"] = mb_strtoupper(trim($_POST['fam_genero'][$i] ?? ''), 'UTF-8');
            $params["f{$i}_escolaridad"] = mb_strtoupper(trim($_POST['fam_escolaridad'][$i] ?? ''), 'UTF-8');
            $params["f{$i}_edo_civil"] = mb_strtoupper(trim($_POST['fam_edo_civil'][$i] ?? ''), 'UTF-8');
            $params["f{$i}_ocupacion"] = mb_strtoupper(trim($_POST['fam_ocupacion'][$i] ?? ''), 'UTF-8');
            $params["f{$i}_telefono"] = preg_replace('/[^0-9]/', '', $_POST['fam_telefono'][$i] ?? '');

            $sql_familiares_sets[] = "
                f{$i}_nombre = :f{$i}_nombre,
                f{$i}_parentesco = :f{$i}_parentesco,
                f{$i}_edad = :f{$i}_edad,
                f{$i}_genero = :f{$i}_genero,
                f{$i}_escolaridad = :f{$i}_escolaridad,
                f{$i}_edo_civil = :f{$i}_edo_civil,
                f{$i}_ocupacion = :f{$i}_ocupacion,
                f{$i}_telefono = :f{$i}_telefono
            ";
        }

        if ($id_estudio_post > 0) {
            $sql = "UPDATE estudio_socioeconomico SET 
                nombre_completo = :nombre_completo,
                edad = :edad,
                fecha_nacimiento = :fecha_nacimiento,
                domicilio = :domicilio,
                formato4_observaciones_familiograma = :formato4_observaciones_familiograma,
                formato4_entrevistado_nombre = :formato4_entrevistado_nombre,
                " . implode(", ", $sql_familiares_sets) . "
                WHERE id_estudio = :id_estudio AND id_paciente = :id_paciente";

            $stmt_upd = $pdo->prepare($sql);
            $stmt_upd->execute($params);
            $id_estudio = $id_estudio_post;
            $exito_msg = "Estructura familiar actualizada correctamente.";
        } else {
            unset($params['id_estudio']);
            $cols = array_keys($params);
            $vals = array_map(fn($c) => ":$c", $cols);

            $sql = "INSERT INTO estudio_socioeconomico (fecha_estudio, " . implode(", ", $cols) . ") 
                    VALUES (CURRENT_DATE, " . implode(", ", $vals) . ")";

            $stmt_ins = $pdo->prepare($sql);
            $stmt_ins->execute($params);
            $id_estudio = $pdo->lastInsertId();
            $exito_msg = "Estructura familiar guardada correctamente.";

            $stmt_lista = $pdo->prepare("SELECT id_estudio, fecha_estudio FROM estudio_socioeconomico WHERE id_paciente = :id_paciente ORDER BY fecha_estudio DESC, id_estudio DESC");
            $stmt_lista->execute(['id_paciente' => $id_paciente]);
            $estudios_previos = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt_est = $pdo->prepare("SELECT * FROM estudio_socioeconomico WHERE id_estudio = :id_estudio");
        $stmt_est->execute(['id_estudio' => $id_estudio]);
        $estudio = $stmt_est->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error_msg = "Error al guardar: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ESTRUCTURA FAMILIAR Y FAMILIOGRAMA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --imss-verde: #006847; --imss-gris-fondo: #f4f6f8; --borde: #94a3b8; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: var(--imss-gris-fondo); color: #1c252e; }
        .navbar { background-color: var(--imss-verde); color: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; font-weight: bold; }
        .formatos-nav { background: #e2e8f0; padding: 10px 25px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #cbd5e1; flex-wrap: wrap; gap: 10px; font-size: 12px; }
        .formato-tab { padding: 5px 12px; background: white; border-radius: 4px; text-decoration: none; color: #334155; font-weight: bold; border: 1px solid #94a3b8; }
        .formato-tab.active { background: var(--imss-verde); color: white; border-color: var(--imss-verde); }

        /* ESTILOS PARA ENCABEZADO CON LOGOS */
        .header-logos { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--imss-verde); padding-bottom: 10px; margin-bottom: 15px; }
        .header-logos img { height: 50px; object-fit: contain; }
        .header-title { text-align: center; flex: 1; margin: 0 15px; }
        .header-title h3 { margin: 2px 0; font-size: 12px; font-weight: bold; color: #1c252e; }
        .header-title h4 { margin: 2px 0; font-size: 11px; font-weight: bold; color: var(--imss-verde); }

        .container { max-width: 1100px; margin: 25px auto; padding: 30px; background: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .form-group { margin-bottom: 8px; font-size: 12px; }
        .form-group label { font-weight: bold; display: inline-block; margin-right: 5px; }
        input[type="text"], input[type="date"], select, textarea { width: 100%; padding: 4px 6px; border: 1px solid var(--borde); border-radius: 3px; font-size: 11px; text-transform: uppercase; text-align: left !important; box-sizing: border-box; }
        
        .tabla-familiar { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11px; }
        .tabla-familiar th, .tabla-familiar td { border: 1px solid #64748b; padding: 4px; text-align: left; }
        .tabla-familiar th { background-color: #e2e8f0; font-weight: bold; text-align: center; }
        .tabla-familiar input[type="text"], .tabla-familiar input[type="number"] { border: none; text-align: left !important; width: 100%; font-size: 11px; background: transparent; text-transform: uppercase; }
        .tabla-familiar input:focus { outline: 1px solid var(--imss-verde); background: white; }
        .tabla-familiar td.radio-cell { text-align: center; }

        .btn-container { text-align: right; margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; color: white; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .btn-primary { background-color: var(--imss-verde); }
        .btn-print { background-color: #0284c7; }

        @media print {
            .navbar, .formatos-nav, .btn-container, .form-control-view { display: none !important; }
            body { background: white; font-size: 10px; color: #000; }
            .container { box-shadow: none; margin: 0; padding: 0; max-width: 100%; width: 100%; }
            input, select, textarea { border: none !important; background: transparent !important; }
        }
    </style>
</head>
<body>
    <div class="navbar form-control-view">
        <div><strong>SECRETARÍA DE SALUD | TABASCO</strong></div>
        <a href="trabajo_social.php"><i class="fa-solid fa-arrow-left"></i> Volver al Listado</a>
    </div>

    <div class="formatos-nav form-control-view">
        <div style="display: flex; gap: 5px; align-items: center; flex-wrap: wrap;">
            <strong>Formatos:</strong>
            <a href="rph.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">1. RPH</a>
            <a href="estudio_socioeconomico.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">2. Socioeconómico</a>
            <a href="nota_trabajo_social.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab">3. Nota Trabajo Social</a>
            <a href="estructura_familiar.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" class="formato-tab active">4. Estructura Familiar</a>
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
                    <option value="estructura_familiar.php?id_paciente=<?= $id_paciente ?>&id_estudio=<?= $ep['id_estudio'] ?>" <?= $selected ?>>
                        Registro #<?= $num_est ?> (<?= date('d/m/Y', strtotime($ep['fecha_estudio'] ?? 'now')) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($error_msg)): ?>
            <div style="background: #ffebee; color: #c62828; padding: 10px; margin-bottom: 15px; font-weight: bold;"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($exito_msg)): ?>
            <div style="background: #e4f8dd; color: var(--imss-verde); padding: 10px; margin-bottom: 15px; font-weight: bold;"><?= htmlspecialchars($exito_msg) ?></div>
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
                <h4>ESTRUCTURA FAMILIAR Y FAMILIOGRAMA</h4>
            </div>
            <div>
                <img src="logo_hospital.png" alt="Hospital" onerror="this.style.display='none'">
            </div>
        </div>

        <form action="estructura_familiar.php?id_paciente=<?= $id_paciente ?><?= $id_estudio > 0 ? '&id_estudio=' . $id_estudio : '' ?>" method="POST">
            <input type="hidden" name="accion" value="guardar_formato4">
            <input type="hidden" name="id_estudio" value="<?= $id_estudio ?>">

            <div class="grid-3" style="margin-bottom: 10px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label>PACIENTE:</label>
                    <input type="text" name="nombre_completo" value="<?= htmlspecialchars($estudio['nombre_completo'] ?? trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apaterno'] ?? '') . ' ' . ($paciente['amaterno'] ?? ''))) ?>" required>
                </div>
                <div class="form-group">
                    <label>FECHA DE NAC.:</label>
                    <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($estudio['fecha_nacimiento'] ?? ($paciente['fecha_nacimiento'] ?? '')) ?>">
                </div>
            </div>

            <div class="grid-3" style="margin-bottom: 15px;">
                <div class="form-group">
                    <label>EDAD:</label>
                    <input type="number" name="edad" value="<?= htmlspecialchars($estudio['edad'] ?? ($paciente['edad'] ?? '')) ?>">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>DOMICILIO:</label>
                    <input type="text" name="domicilio" value="<?= htmlspecialchars($estudio['domicilio'] ?? ($paciente['domicilio'] ?? '')) ?>">
                </div>
            </div>

            <!-- TABLA DE ESTRUCTURA FAMILIAR (8 FILAS ESTÁTICAS) -->
            <table class="tabla-familiar">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 40px; text-align: center;">N°</th>
                        <th rowspan="2">NOMBRE</th>
                        <th rowspan="2" style="width: 120px;">GRADO DE PARENTESCO</th>
                        <th rowspan="2" style="width: 50px; text-align: center;">EDAD</th>
                        <th colspan="2" style="width: 90px; text-align: center;">GÉNERO</th>
                        <th rowspan="2" style="width: 100px;">ESCOLARIDAD</th>
                        <th rowspan="2" style="width: 90px;">EDO. CIVIL</th>
                        <th rowspan="2" style="width: 110px;">OCUPACION</th>
                        <th rowspan="2" style="width: 110px;">TELEFONO</th>
                    </tr>
                    <tr>
                        <th style="width: 45px; text-align: center;">H</th>
                        <th style="width: 45px; text-align: center;">M</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 1; $i <= 8; $i++): 
                        $gen = $estudio["f{$i}_genero"] ?? '';
                    ?>
                    <tr>
                        <td style="text-align: center;"><strong><?= $i ?></strong></td>
                        <td><input type="text" name="fam_nombre[<?= $i ?>]" value="<?= htmlspecialchars($estudio["f{$i}_nombre"] ?? '') ?>"></td>
                        <td><input type="text" name="fam_parentesco[<?= $i ?>]" value="<?= htmlspecialchars($estudio["f{$i}_parentesco"] ?? '') ?>"></td>
                        <td><input type="number" name="fam_edad[<?= $i ?>]" value="<?= htmlspecialchars($estudio["f{$i}_edad"] ?? '') ?>" style="text-align: center !important;"></td>
                        <td class="radio-cell"><input type="radio" name="fam_genero[<?= $i ?>]" value="H" <?= $gen === 'H' ? 'checked' : '' ?>></td>
                        <td class="radio-cell"><input type="radio" name="fam_genero[<?= $i ?>]" value="M" <?= $gen === 'M' ? 'checked' : '' ?>></td>
                        <td><input type="text" name="fam_escolaridad[<?= $i ?>]" value="<?= htmlspecialchars($estudio["f{$i}_escolaridad"] ?? '') ?>"></td>
                        <td><input type="text" name="fam_edo_civil[<?= $i ?>]" value="<?= htmlspecialchars($estudio["f{$i}_edo_civil"] ?? '') ?>"></td>
                        <td><input type="text" name="fam_ocupacion[<?= $i ?>]" value="<?= htmlspecialchars($estudio["f{$i}_ocupacion"] ?? '') ?>"></td>
                        <td><input type="text" name="fam_telefono[<?= $i ?>]" value="<?= htmlspecialchars($estudio["f{$i}_telefono"] ?? '') ?>" maxlength="10" placeholder="10 DÍGITOS"></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <div style="margin-top: 15px; font-size: 11px; text-align: justify; font-weight: bold; background: #e2e8f0; padding: 6px; border: 1px solid #cbd5e1;">
                SEGÚN INFORMACIÓN DE LA PERSONA ENTREVISTADA, ELABORE: FAMILIOGRAMA (ÚNICAMENTE DE LA FAMILIA NUCLEAR) REGISTRANDO LÍNEAS DE ACCIÓN (+ Y -) PACIENTES IDENTIFICADOS.
            </div>

            <div class="form-group" style="margin-top: 10px;">
                <textarea name="formato4_observaciones_familiograma" rows="7" placeholder="Describa aquí las observaciones del familiograma y líneas de acción..."><?= htmlspecialchars($estudio['formato4_observaciones_familiograma'] ?? '') ?></textarea>
            </div>

            <div class="grid-2" style="margin-top: 20px;">
                <div class="form-group">
                    <label>NOMBRE COMPLETO Y FIRMA DE LA TRABAJADORA SOCIAL Y CÉDULA PROFESIONAL:</label>
                    <input type="text" value="<?= htmlspecialchars($estudio['nombre_trabajador_social'] ?? '') ?>" readonly style="background: #f1f5f9;">
                </div>
                <div class="form-group">
                    <label>FAMILIAR QUE SE ENTREVISTA (NOMBRE COMPLETO Y FIRMAS):</label>
                    <input type="text" name="formato4_entrevistado_nombre" value="<?= htmlspecialchars($estudio['formato4_entrevistado_nombre'] ?? '') ?>">
                </div>
            </div>

            <div class="btn-container">
                <button type="button" class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir Formato</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Estructura</button>
            </div>
        </form>
    </div>

    <script>
    function cambiarEstudio(select) {
        if (select.value) {
            window.location.href = select.value;
        }
    }
    </script>
</body>
</html>