<?php
require_once '../../includes/functions.php';
require_admin_panel();
require_once '../../vendor/autoload.php'; // TCPDF via composer

// ----------------- Apply Filters -----------------
$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : 0;
$year  = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : (int)date('Y');
$from  = $_GET['from'] ?? '';
$to    = $_GET['to'] ?? '';

$whereInv = "1=1"; $paramsInv=[];
$whereExp = "1=1"; $paramsExp=[];

if ($from && $to) {
    $whereInv .= " AND invoice_date BETWEEN ? AND ?";
    $whereExp .= " AND expense_date BETWEEN ? AND ?";
    $paramsInv = $paramsExp = [$from, $to];
} elseif ($month && $year) {
    $whereInv .= " AND MONTH(invoice_date)=? AND YEAR(invoice_date)=?";
    $whereExp .= " AND MONTH(expense_date)=? AND YEAR(expense_date)=?";
    $paramsInv = $paramsExp = [$month, $year];
} else {
    $whereInv .= " AND YEAR(invoice_date)=?";
    $whereExp .= " AND YEAR(expense_date)=?";
    $paramsInv = $paramsExp = [$year];
}

// ----------------- Totals -----------------
$stmt = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE $whereInv");
$stmt->execute($paramsInv);
$totalIncome = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE $whereExp");
$stmt->execute($paramsExp);
$totalExpense = $stmt->fetchColumn() ?: 0;

$profit = $totalIncome - $totalExpense;

// Category breakdown
$stmt = $pdo->prepare("SELECT category, SUM(amount) total FROM expenses WHERE $whereExp GROUP BY category");
$stmt->execute($paramsExp);
$cats = $stmt->fetchAll();

// ----------------- Generate PDF -----------------
// ----------------- Generate PDF -----------------
class MYPDF extends TCPDF {
    public $reportPeriod = '';

    // Page header
    public function Header() {
        $this->SetFont('helvetica','B',16);
        $this->Cell(0,10,"Vibesoft Invoice - Financial Report",0,1,'C');

        $this->SetFont('helvetica','',12);
        if($this->reportPeriod){
            $this->Cell(0,8,"Report Period: ".$this->reportPeriod,0,1,'C');
        }

        $this->Ln(2);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica','I',8);
        $this->Cell(0,10,'Page '.$this->getAliasNumPage().' of '.$this->getAliasNbPages(),0,0,'C');
    }
}

// ----------------- Determine Report Period -----------------
if($from && $to){
    $reportPeriod = date('d M Y', strtotime($from))." to ".date('d M Y', strtotime($to));
} elseif($month && $year){
    $reportPeriod = date('F, Y', mktime(0,0,0,$month,1,$year));
} else {
    $reportPeriod = $year;
}

// ----------------- Create PDF -----------------
$pdf = new MYPDF();
$pdf->reportPeriod = $reportPeriod; // set period for header
$pdf->AddPage();


// ----------------- Summary Section -----------------
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,10,"Summary",0,1);
$pdf->SetFont('helvetica','',12);

$pdf->Cell(60,8,"Total Sales:",0,0);
$pdf->Cell(0,8,"₹".number_format($totalIncome,2),0,1);

$pdf->Cell(60,8,"Total Expenses:",0,0);
$pdf->Cell(0,8,"₹".number_format($totalExpense,2),0,1);

$pdf->Cell(60,8,"Net Profit:",0,0);
$pdf->Cell(0,8,"₹".number_format($profit,2),0,1);

$pdf->Ln(5);

// ----------------- Expense Categories Table -----------------
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,10,"Expense Categories",0,1);

$pdf->SetFont('helvetica','B',12);
$pdf->SetFillColor(230,230,230);
$pdf->Cell(120,8,"Category",1,0,'C',1);
$pdf->Cell(60,8,"Amount (₹)",1,1,'C',1);

$pdf->SetFont('helvetica','',12);
foreach($cats as $c){
    $pdf->Cell(120,8,$c['category'],1,0);
    $pdf->Cell(60,8,number_format($c['total'],2),1,1,'R');
}

// ----------------- Optional: Add footer note -----------------
$pdf->Ln(5);
$pdf->SetFont('helvetica','I',10);
$pdf->MultiCell(0,6,"Note: This is a system-generated financial report. Please verify all transactions in your accounting records.",0,'L');

// ----------------- Output PDF -----------------
$pdf->Output("Financial_Report_".date('Ymd_His').".pdf","D");
