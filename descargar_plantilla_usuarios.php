<?php
// ===================================================================
// 1. SEGURIDAD Y PERMISOS (SOLO SUPERADMIN)
// ===================================================================
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    exit("Acceso denegado");
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Plantilla Usuarios");

// Encabezados requeridos para la carga masiva
$headers = [
    'Usuario / Matrícula', 'Contraseña', 'Nombre Completo', 
    'Correo', 'Rol (1=Admin, 2=Operativo)', 'Clave Clinica', 'Permisos (separados por coma)'
];

$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// Fila de ejemplo orientativa
$sheet->setCellValue('A2', 'medico01');
$sheet->setCellValue('B2', '123456');
$sheet->setCellValue('C2', 'Dra. María López');
$sheet->setCellValue('D2', 'mlopez@imss.gob.mx');
$sheet->setCellValue('E2', '2');
$sheet->setCellValue('F2', 'CLI-01');
$sheet->setCellValue('G2', 'registro_citas,citas_medicas');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Plantilla_Importacion_Usuarios.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();