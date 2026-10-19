<?php
session_start();
require_once 'conexion.php';

header('Content-Type: application/json');

$clinica_id = $_GET['clinica_id'] ?? '';
$fecha_cita = $_GET['fecha_cita'] ?? '';
$id_cita_excluir = $_GET['id_cita_excluir'] ?? ''; // Útil al editar

if (empty($clinica_id) || empty($fecha_cita)) {
    echo json_encode([]);
    exit();
}

try {
    // Obtenemos el nombre de la clínica a partir del ID recibido
    $stmt_cli = $pdo->prepare("SELECT nombre_clinica FROM clinicas WHERE id_clinica = :id");
    $stmt_cli->execute(['id' => $clinica_id]);
    $nombre_clinica = $stmt_cli->fetchColumn();

    if (!$nombre_clinica) {
        echo json_encode([]);
        exit();
    }

    // Buscamos las horas ya ocupadas en la tabla citas
    $sql = "SELECT hora_cita FROM citas WHERE nombre_clinica = :nombre_clinica AND fecha_cita = :fecha_cita";
    $params = ['nombre_clinica' => $nombre_clinica, 'fecha_cita' => $fecha_cita];

    if (!empty($id_cita_excluir)) {
        $sql .= " AND id_cita <> :id_cita_excluir";
        $params['id_cita_excluir'] = $id_cita_excluir;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ocupadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Normalizar formato de horas (ej. 08:00:00 a 08:00)
    $horas_ocupadas = array_map(function($h) {
        return substr($h, 0, 5);
    }, $ocupadas);

    echo json_encode($horas_ocupadas);
} catch (Exception $e) {
    echo json_encode([]);
}