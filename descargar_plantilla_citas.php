<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y SEGURIDAD (SOLO SUPERADMIN)
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    exit("Acceso denegado");
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Inicializar la hoja de cálculo
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Plantilla Citas");

// ===================================================================
// 2. DEFINICIÓN DE ENCABEZADOS IDÉNTICOS A ACCESS
// ===================================================================
$headers = [
    'IdPaciente', 'FechaCita', 'HoraCita', 'TipoClinica', 
    'NombreClinica', 'Colonia', 'TipoPaciente', 'NombrePaciente', 
    'NoExpediente', 'CURP', 'IdCitas', 'Telefono', 'Estatus', 'FechaCreacion'
];

$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// Fila de ejemplo orientativa basada en la estructura real
$sheet->setCellValue('A2', '1025');
$sheet->setCellValue('B2', '2026-09-07');
$sheet->setCellValue('C2', '09:30:00');
$sheet->setCellValue('D2', 'Unidad Móvil');
$sheet->setCellValue('E2', 'Núcleo Básico 1');
$sheet->setCellValue('F2', 'Centro');
$sheet->setCellValue('G2', 'General');
$sheet->setCellValue('H2', 'JUAN PÉREZ LÓPEZ');
$sheet->setCellValue('I2', '61568');
$sheet->setCellValue('J2', 'PELJ800711HCSGRL01');
$sheet->setCellValue('K2', 'AUTO');
$sheet->setCellValue('L2', '9371000000');
$sheet->setCellValue('M2', 'Agendada');
$sheet->setCellValue('N2', '2026-09-05 10:00:00');

// Configuración de cabeceras HTTP para forzar la descarga del archivo .xlsx
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Plantilla_Access_Citas.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();