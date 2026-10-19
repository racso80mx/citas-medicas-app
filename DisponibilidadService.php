<?php
/**
 * Servicio Centralizado de Validaciones y Disponibilidad
 * IMSS-BIENESTAR
 */
class DisponibilidadService {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene el ID exacto de la clínica a partir del nombre o ID recibido
     */
    public function obtenerInfoClinica($clinica_id_o_nombre) {
        if (is_numeric($clinica_id_o_nombre)) {
            $stmt = $this->pdo->prepare("SELECT * FROM clinicas WHERE id_clinica = :val LIMIT 1");
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM clinicas WHERE nombre_clinica = :val LIMIT 1");
        }
        $stmt->execute(['val' => trim($clinica_id_o_nombre)]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? array_change_key_case($res, CASE_LOWER) : null;
    }

    /**
     * Revisa si una fecha específica está bloqueada para una clínica por cualquier motivo.
     * Retorna array ['disponible' => bool, 'motivo' => string]
     */
    public function validarFecha($fecha, $clinica_id_o_nombre) {
        $clinica = $this->obtenerInfoClinica($clinica_id_o_nombre);
        if (!$clinica) {
            return ['disponible' => false, 'motivo' => 'Clínica no encontrada'];
        }

        $id_clinica = $clinica['id_clinica'] ?? 0;
        $nombre_clinica = $clinica['nombre_clinica'] ?? '';

        // 1. Validar Día Inactivo de la clínica (Lunes, Martes, etc.)
        $dia_semana_num = date('N', strtotime($fecha)); // 1..7
        $dias_map = [1=>'Lunes', 2=>'Martes', 3=>'Miércoles', 4=>'Jueves', 5=>'Viernes', 6=>'Sábado', 7=>'Domingo'];
        $nombre_dia = $dias_map[$dia_semana_num] ?? '';

        $dia_inactivo = trim($clinica['dia_inactivo'] ?? '');
        if (!empty($dia_inactivo) && strcasecmp($nombre_dia, $dia_inactivo) === 0) {
            return ['disponible' => false, 'motivo' => "Día Inactivo Configurado ($dia_inactivo)"];
        }

        // 2. Validar Fin de Semana
        $atiende_fines = $clinica['atiende_fin_de_semana'] ?? $clinica['atiende_fines'] ?? null;
        if ($atiende_fines !== null) {
            $val_upper = strtoupper(trim((string)$atiende_fines));
            if (in_array($val_upper, ['FALSO', 'FALSE', '0', 'NO', 'OFF']) || $atiende_fines === false) {
                if ($dia_semana_num == 6 || $dia_semana_num == 7) {
                    return ['disponible' => false, 'motivo' => 'No atiende en Fin de Semana'];
                }
            }
        }

        // 3. Validar Festivos Generales y Locales
        $stmtF = $this->pdo->prepare("SELECT descripcion FROM festivos WHERE fecha = :f LIMIT 1");
        $stmtF->execute(['f' => $fecha]);
        if ($festivo = $stmtF->fetchColumn()) {
            return ['disponible' => false, 'motivo' => "Día Festivo: $festivo"];
        }

        // 4. Validar Excepciones / Bloqueos por Clínica (Todo el día o Parciales)
        $stmtE = $this->pdo->prepare("
            SELECT motivo, todo_el_dia, hora_inicio, hora_fin 
            FROM clinica_excepciones 
            WHERE fecha = :f 
              AND ((id_clinica = :id_c AND :id_c != 0) OR nombre_clinica ILIKE :c OR clinica ILIKE :c OR nombre ILIKE :c)
        ");
        $stmtE->execute([
            'f' => $fecha,
            'id_c' => intval($id_clinica),
            'c' => $nombre_clinica
        ]);
        $excepciones = $stmtE->fetchAll(PDO::FETCH_ASSOC);

        foreach ($excepciones as $exc) {
            $todo_el_dia = $exc['todo_el_dia'] ?? null;
            $motivo = !empty($exc['motivo']) ? $exc['motivo'] : 'Bloqueo / Excepción Registrada';

            // Si está marcado como todo el día o no tiene horarios especificados
            if ($todo_el_dia == 1 || $todo_el_dia === true || (empty($exc['hora_inicio']) && empty($exc['hora_fin']))) {
                return ['disponible' => false, 'motivo' => "Excepción: $motivo"];
            }
        }

        return ['disponible' => true, 'motivo' => 'Disponible'];
    }

    /**
     * Retorna todas las fechas bloqueadas de una clínica dentro de un rango para el calendario (Flatpickr)
     */
    public function obtenerFechasBloqueadas($clinica_id_o_nombre) {
        $clinica = $this->obtenerInfoClinica($clinica_id_o_nombre);
        if (!$clinica) return ['festivos' => [], 'excepciones' => [], 'dia_inactivo' => '', 'no_atiende_fin' => false];

        $id_clinica = $clinica['id_clinica'] ?? 0;
        $nombre_clinica = $clinica['nombre_clinica'] ?? '';

        // Festivos
        $festivos = $this->pdo->query("SELECT DISTINCT TO_CHAR(fecha, 'YYYY-MM-DD') FROM festivos")->fetchAll(PDO::FETCH_COLUMN);

        // Excepciones completas
        $stmtE = $this->pdo->prepare("
            SELECT DISTINCT TO_CHAR(fecha, 'YYYY-MM-DD') FROM clinica_excepciones 
            WHERE ((id_clinica = :id_c AND :id_c != 0) OR nombre_clinica ILIKE :c OR clinica ILIKE :c OR nombre ILIKE :c)
              AND (todo_el_dia = 1 OR todo_el_dia IS TRUE OR hora_inicio IS NULL OR hora_inicio = '')
        ");
        $stmtE->execute(['id_c' => intval($id_clinica), 'c' => $nombre_clinica]);
        $excepciones = $stmtE->fetchAll(PDO::FETCH_COLUMN);

        $atiende_fines = $clinica['atiende_fin_de_semana'] ?? $clinica['atiende_fines'] ?? null;
        $no_atiende_fin = false;
        if ($atiende_fines !== null) {
            $val_upper = strtoupper(trim((string)$atiende_fines));
            if (in_array($val_upper, ['FALSO', 'FALSE', '0', 'NO', 'OFF']) || $atiende_fines === false) {
                $no_atiende_fin = true;
            }
        }

        return [
            'festivos' => array_values(array_filter($festivos)),
            'excepciones' => array_values(array_filter($excepciones)),
            'dia_inactivo' => trim($clinica['dia_inactivo'] ?? ''),
            'no_atiende_fin' => $no_atiende_fin
        ];
    }

    /**
     * Genera los slots de horarios calculando bloqueos parciales y citas ocupadas
     */
    public function calcularSlotsHorarios($fecha, $clinica_id_o_nombre) {
        $valFecha = $this->validarFecha($fecha, $clinica_id_o_nombre);
        if (!$valFecha['disponible']) {
            return ['status' => 'cerrado', 'mensaje' => $valFecha['motivo']];
        }

        $clinica = $this->obtenerInfoClinica($clinica_id_o_nombre);
        $id_clinica = $clinica['id_clinica'] ?? 0;
        $nombre_clinica = $clinica['nombre_clinica'] ?? '';

        $hora_inicio = $clinica['hora_inicio'] ?? '08:00';
        $hora_fin    = $clinica['hora_fin'] ?? '14:00';
        $duracion    = intval($clinica['duracion_consulta'] ?? 25);
        if ($duracion <= 0) $duracion = 25;

        // Excepciones con rango de hora (Ej. Permiso de 13:30 a 15:00)
        $stmtE = $this->pdo->prepare("
            SELECT hora_inicio, hora_fin FROM clinica_excepciones 
            WHERE fecha = :f 
              AND ((id_clinica = :id_c AND :id_c != 0) OR nombre_clinica ILIKE :c OR clinica ILIKE :c OR nombre ILIKE :c)
              AND todo_el_dia != 1 AND hora_inicio IS NOT NULL AND hora_fin IS NOT NULL
        ");
        $stmtE->execute(['f' => $fecha, 'id_c' => intval($id_clinica), 'c' => $nombre_clinica]);
        $bloqueos_horarios = $stmtE->fetchAll(PDO::FETCH_ASSOC);

        // Citas agendadas
        $stmtC = $this->pdo->prepare("SELECT hora_cita, nombre_paciente FROM citas WHERE fecha_cita = :f AND TRIM(nombre_clinica) ILIKE :c AND (estatus != 'CANCELADA' OR estatus IS NULL)");
        $stmtC->execute(['f' => $fecha, 'c' => trim($nombre_clinica)]);
        $citas_registradas = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $citas_map = [];
        foreach ($citas_registradas as $cr) {
            $h_clean = substr(trim($cr['hora_cita']), 0, 5);
            $partes = explode(' ', trim($cr['nombre_paciente']));
            $citas_map[$h_clean] = strtoupper($partes[0] ?? $cr['nombre_paciente']);
        }

        $slots = [];
        $current = strtotime($hora_inicio);
        $end = strtotime($hora_fin);

        while ($current < $end) {
            $slot_str = date('H:i', $current);
            $slot_ts = $current;

            $disponible = true;
            $motivo_ocupado = '';

            // Revisar si cae dentro de un permiso por horas
            foreach ($bloqueos_horarios as $b) {
                $b_ini = strtotime($b['hora_inicio']);
                $b_fin = strtotime($b['hora_fin']);
                if ($slot_ts >= $b_ini && $slot_ts < $b_fin) {
                    $disponible = false;
                    $motivo_ocupado = 'BLOQUEADO';
                    break;
                }
            }

            // Revisar si ya hay una cita
            if (isset($citas_map[$slot_str])) {
                $disponible = false;
                $motivo_ocupado = $citas_map[$slot_str];
            }

            $slots[] = [
                'hora' => $slot_str,
                'disponible' => $disponible,
                'paciente' => $motivo_ocupado
            ];

            $current = strtotime("+$duracion minutes", $current);
        }

        return ['status' => 'abierto', 'slots' => $slots, 'duracion' => $duracion];
    }
}