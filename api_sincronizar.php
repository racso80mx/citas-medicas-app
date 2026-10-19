<?php
// api_sincronizar.php
header('Content-Type: application/json');
require_once 'conexion.php'; // Asegúrate de que este archivo conecta tu base de datos PostgreSQL

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'mensaje' => 'No se recibieron datos.']);
    exit();
}

try {
    $sincronizados = 0;
    foreach ($input as $p) {
        $expediente = trim($p['expediente'] ?? '');
        $nombre = trim($p['nombre'] ?? '');
        $sexo = trim($p['sexo'] ?? '');
        $medico = trim($p['medico'] ?? '');
        $motivo = trim($p['motivo'] ?? '');

        if (empty($expediente)) continue;

        // Verificar si el paciente ya existe en tu BD por número de expediente
        $stmt = $pdo->prepare("SELECT id_personal FROM pacientes WHERE expediente = :exp"); // Ajusta el nombre de tu tabla de pacientes si es diferente
        $stmt->execute(['exp' => $expediente]);
        $existe = $stmt->fetch();

        if ($existe) {
            // Si ya existe, actualizamos sus datos por si cambiaron
            $update = $pdo->prepare("
                UPDATE pacientes 
                SET medico_responsable = :medico, motivo_admision = :motivo 
                WHERE expediente = :exp
            ");
            $update->execute([
                'medico' => $medico,
                'motivo' => $motivo,
                'exp' => $expediente
            ]);
        } else {
            // Si no existe, lo insertamos como nuevo paciente del día
            $insert = $pdo->prepare("
                INSERT INTO pacientes (expediente, nombre_completo, sexo, medico_responsable, motivo_admision, fecha_registro) 
                VALUES (:exp, :nombre, :sexo, :medico, :motivo, NOW())
            ");
            $insert->execute([
                'exp' => $expediente,
                'nombre' => $nombre,
                'sexo' => $sexo,
                'medico' => $medico,
                'motivo' => $motivo
            ]);
        }
        $sincronizados++;
    }

    echo json_encode(['success' => true, 'mensaje' => "¡Se sincronizaron $sincronizados pacientes correctamente!"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'mensaje' => 'Error en BD: ' . $e->getMessage()]);
}