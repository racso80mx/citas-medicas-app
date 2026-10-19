<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol_id'] != 1) {
    exit("Acceso denegado");
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Plantilla Pacientes");

// Encabezados idénticos a los campos de Access
$headers = [
    'Expediente', 'Nombre', 'Apaterno', 'Amaterno', 'FNacimiento', 
    'YearNacimiento', 'Edad', 'EdadActual', 'Sexo', 'Estado', 
    'Domicilio', 'Colonia', 'NombrePadre', 'NombreMadre', 'EdadPadre', 
    'EdadMadre', 'FechaApertura', 'Estatus', 'SeguroPopular', 
    'Telefono', 'NombreCompleto', 'CURP', 'NombreApellidos'
];

$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// Configurar cabeceras HTTP para forzar la descarga del archivo Excel
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Plantilla_Access_Pacientes.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();