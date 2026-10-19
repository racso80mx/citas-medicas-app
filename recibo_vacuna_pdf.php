<?php
// recibo_vacuna_pdf.php
require_once 'conexion.php';

$id_cita = $_GET['id'] ?? 0;

// Aquí debes hacer la consulta a citas_vacunas, pacientes, y citas_vacunas_detalle
// simularemos los datos para la plantilla
$paciente_nombre = "AARON GARCIA MORALES";
$expediente = "60950";
$fecha_cita = "07/09/2026";
$hora_cita = "08:15 hrs";
$vacunas_asignadas = [
    ['abrev' => 'BCG', 'nombre' => 'Bacilo de Calmette-Guérin', 'rango' => 'Recién nacidos'],
    ['abrev' => 'Hepatitis B', 'nombre' => 'Antihepatitis B', 'rango' => 'Recién nacidos']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante de Cita de Vacunación</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #555; padding: 20px; }
        .hoja { width: 148mm; min-height: 210mm; background: white; margin: 0 auto; padding: 10mm; box-sizing: border-box; } /* Formato A5 o Media Carta */
        .header { text-align: center; border-bottom: 2px solid #006847; padding-bottom: 10px; margin-bottom: 15px; }
        .header img { max-height: 60px; }
        .header h3 { margin: 5px 0 0 0; color: #006847; }
        .info-box { border: 1px solid #dfe3e8; padding: 10px; border-radius: 6px; margin-bottom: 15px; background: #f9fafb; font-size: 13px; }
        .info-box p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 30px; }
        th { background-color: #006847; color: white; padding: 8px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #dfe3e8; }
        .firmas { display: flex; justify-content: space-between; margin-top: 50px; text-align: center; font-size: 12px; }
        .linea-firma { border-top: 1px solid #333; width: 45%; padding-top: 5px; }
        @media print { body { background: none; padding: 0; } .hoja { box-shadow: none; width: 100%; margin: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="hoja">
        <div class="header">
            <img src="logo_hospital.png" alt="IMSS-BIENESTAR">
            <h3>Comprobante de Cita de Vacunación</h3>
        </div>

        <div class="info-box">
            <p><strong>Paciente:</strong> <?php echo $paciente_nombre; ?></p>
            <p><strong>No. Expediente:</strong> <?php echo $expediente; ?></p>
            <p><strong>Fecha Programada:</strong> <?php echo $fecha_cita; ?></p>
            <p><strong>Hora de Atención:</strong> <?php echo $hora_cita; ?></p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Abrev.</th>
                    <th>Vacuna Asignada</th>
                    <th>Rango de Edad / Indicación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vacunas_asignadas as $vacuna): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($vacuna['abrev']); ?></strong></td>
                    <td><?php echo htmlspecialchars($vacuna['nombre']); ?></td>
                    <td><?php echo htmlspecialchars($vacuna['rango']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="font-size: 11px; color: #666; text-align: justify;">
            Favor de presentarse 10 minutos antes de su hora asignada. Es obligatorio presentar su Cartilla Nacional de Salud original el día de la cita.
        </p>

        <div class="firmas">
            <div class="linea-firma">Firma de Enfermería / Vacunador</div>
            <div class="linea-firma">Firma del Responsable / Paciente</div>
        </div>

        <div class="no-print" style="text-align: center; margin-top: 30px;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #006847; color: white; border: none; border-radius: 4px; cursor: pointer;">
                Imprimir Recibo
            </button>
        </div>
    </div>
</body>
</html>