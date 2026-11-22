<?php
require_once '../../includes/functions.php';
require_admin_panel();

require '../../vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Filters
$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : 0;
$year  = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : (int)date('Y');
$from  = $_GET['from'] ?? '';
$to    = $_GET['to'] ?? '';

$whereInv = "1=1"; $paramsInv=[];
$whereExp = "1=1"; $paramsExp=[];

if ($from && $to) {
    $whereInv .= " AND invoice_date BETWEEN ? AND ?";
    $whereExp .= " AND expense_date BETWEEN ? AND ?";
    $paramsInv = $paramsExp = [$from,$to];
} elseif ($month && $year) {
    $whereInv .= " AND MONTH(invoice_date)=? AND YEAR(invoice_date)=?";
    $whereExp .= " AND MONTH(expense_date)=? AND YEAR(expense_date)=?";
    $paramsInv = $paramsExp = [$month,$year];
} else {
    $whereInv .= " AND YEAR(invoice_date)=?";
    $whereExp .= " AND YEAR(expense_date)=?";
    $paramsInv = $paramsExp = [$year];
}

// Fetch
$stmt=$pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE $whereInv");
$stmt->execute($paramsInv);
$totalIncome = $stmt->fetchColumn() ?: 0;

$stmt=$pdo->prepare("SELECT SUM(amount) FROM expenses WHERE $whereExp");
$stmt->execute($paramsExp);
$totalExpense = $stmt->fetchColumn() ?: 0;

$profit = $totalIncome - $totalExpense;

$stmt=$pdo->prepare("SELECT category, SUM(amount) total FROM expenses WHERE $whereExp GROUP BY category");
$stmt->execute($paramsExp);
$cats=$stmt->fetchAll();

// Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setCellValue('A1','Vibesoft Invoice - Financial Report');
$sheet->mergeCells('A1:C1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

$sheet->setCellValue('A3','Total Sales');
$sheet->setCellValue('B3',$totalIncome);

$sheet->setCellValue('A4','Total Expenses');
$sheet->setCellValue('B4',$totalExpense);

$sheet->setCellValue('A5','Net Profit');
$sheet->setCellValue('B5',$profit);

$sheet->setCellValue('A7','Expense Category');
$sheet->setCellValue('B7','Amount');
$row=8;
foreach($cats as $c){
    $sheet->setCellValue("A{$row}",$c['category']);
    $sheet->setCellValue("B{$row}",$c['total']);
    $row++;
}

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="report.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
