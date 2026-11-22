<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

// -------------------------
// Input / Filters
// -------------------------
$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : 0;
$year  = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : (int)date('Y');
$from  = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : '';
$to    = isset($_GET['to']) && $_GET['to'] !== '' ? $_GET['to'] : '';

// Determine mode
if ($from && $to) {
    $mode = 'daily_range';
    $start = $from; $end = $to;
} elseif ($month && $year) {
    $mode = 'daily_month';
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));
} else {
    $mode = 'monthly_year';
}

// -------------------------
// Build WHERE clauses
// -------------------------
$invWhere = '1=1'; $invParams = [];
$expWhere = '1=1'; $expParams = [];

if ($mode === 'monthly_year') {
    $invWhere .= " AND YEAR(invoice_date)=?"; $invParams[] = $year;
    $expWhere .= " AND YEAR(expense_date)=?"; $expParams[] = $year;
} else {
    $invWhere .= " AND invoice_date BETWEEN ? AND ?"; $invParams[] = $start; $invParams[] = $end;
    $expWhere .= " AND expense_date BETWEEN ? AND ?"; $expParams[] = $start; $expParams[] = $end;
}

// -------------------------
// Aggregated Data
// -------------------------
if ($mode === 'monthly_year') {
    $stmt = $pdo->prepare("SELECT MONTH(invoice_date) AS m, SUM(total_amount) AS total FROM invoices WHERE {$invWhere} GROUP BY m");
    $stmt->execute($invParams); $incomeRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = $pdo->prepare("SELECT MONTH(expense_date) AS m, SUM(amount) AS total FROM expenses WHERE {$expWhere} GROUP BY m");
    $stmt->execute($expParams); $expenseRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $labels = []; $incomeData = []; $expenseData = [];
    for($m=1;$m<=12;$m++){
        $labels[] = date('M', mktime(0,0,0,$m,1));
        $incomeData[] = isset($incomeRows[$m]) ? (float)$incomeRows[$m] : 0;
        $expenseData[] = isset($expenseRows[$m]) ? (float)$expenseRows[$m] : 0;
    }
} else {
    $stmt = $pdo->prepare("SELECT DATE(invoice_date) AS d, SUM(total_amount) AS total FROM invoices WHERE {$invWhere} GROUP BY d");
    $stmt->execute($invParams); $incomeRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = $pdo->prepare("SELECT DATE(expense_date) AS d, SUM(amount) AS total FROM expenses WHERE {$expWhere} GROUP BY d");
    $stmt->execute($expParams); $expenseRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $startDT = new DateTime($start); $endDT = new DateTime($end); $endDT->modify('+1 day');
    $period = new DatePeriod($startDT, new DateInterval('P1D'), $endDT);

    $labels=[]; $incomeData=[]; $expenseData=[];
    foreach($period as $dt){
        $d=$dt->format('Y-m-d');
        $labels[]=$dt->format('d M');
        $incomeData[]=isset($incomeRows[$d])?(float)$incomeRows[$d]:0;
        $expenseData[]=isset($expenseRows[$d])?(float)$expenseRows[$d]:0;
    }
}

// Totals
$totalIncome = array_sum($incomeData);
$totalExpense = array_sum($expenseData);
$profit = $totalIncome - $totalExpense;

// -------------------------
// Top Customers & Expense Categories
// -------------------------
$stmt = $pdo->prepare("SELECT c.name, SUM(i.total_amount) AS total FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE {$invWhere} GROUP BY c.id ORDER BY total DESC LIMIT 10");
$stmt->execute($invParams); $topCustomers = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE {$expWhere} GROUP BY category ORDER BY total DESC");
$stmt->execute($expParams); $expenseCats = $stmt->fetchAll();

// -------------------------
// Revenue Earned by Service Category (SMM vs Web Development vs Branding)
// -------------------------
$catStmt = $pdo->prepare("
    SELECT ii.name AS item_name, ii.line_total, i.total_amount AS invoice_total, i.amount_paid AS invoice_paid
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE {$invWhere} AND i.status != 'cancelled'
");
$catStmt->execute($invParams);
$catItems = $catStmt->fetchAll();

$serviceCategories = [
    'SMM & Digital Marketing' => ['invoiced' => 0, 'earned' => 0, 'color' => '#36a2eb', 'icon' => 'bi-megaphone'],
    'Web & App Development'   => ['invoiced' => 0, 'earned' => 0, 'color' => '#4bc0c0', 'icon' => 'bi-code-slash'],
    'Branding & Creative'     => ['invoiced' => 0, 'earned' => 0, 'color' => '#ffcd56', 'icon' => 'bi-palette'],
    'Other Retainers & Work'  => ['invoiced' => 0, 'earned' => 0, 'color' => '#9966ff', 'icon' => 'bi-box-seam']
];

$catGrandInvoiced = 0;
$catGrandEarned   = 0;

foreach ($catItems as $it) {
    $itemName = strtolower($it['item_name']);
    $lineAmt  = (float)$it['line_total'];
    $invTotal = (float)$it['invoice_total'];
    $invPaid  = (float)$it['invoice_paid'];

    $paidRatio = ($invTotal > 0) ? min(1.0, $invPaid / $invTotal) : 0;
    $earnedAmt = round($lineAmt * $paidRatio, 2);

    $catKey = 'Other Retainers & Work';
    if (preg_match('/(smm|social media|marketing|ads|facebook|instagram|digital marketing)/i', $itemName)) {
        $catKey = 'SMM & Digital Marketing';
    } elseif (preg_match('/(website|portal|development|platform|e-commerce|software|app|web)/i', $itemName)) {
        $catKey = 'Web & App Development';
    } elseif (preg_match('/(brand|design|logo|creative|graphic)/i', $itemName)) {
        $catKey = 'Branding & Creative';
    }

    $serviceCategories[$catKey]['invoiced'] += $lineAmt;
    $serviceCategories[$catKey]['earned']   += $earnedAmt;

    $catGrandInvoiced += $lineAmt;
    $catGrandEarned   += $earnedAmt;
}

// -------------------------
// Detailed Sales & Expenses
// -------------------------
$stmt = $pdo->prepare("SELECT i.invoice_no, i.invoice_date, c.name AS customer, i.total_amount FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE {$invWhere} ORDER BY i.invoice_date ASC");
$stmt->execute($invParams); $invoices = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT expense_date, category, description, amount FROM expenses WHERE {$expWhere} ORDER BY expense_date ASC");
$stmt->execute($expParams); $expenses = $stmt->fetchAll();

// Export Query
$exportQuery = http_build_query($_GET);
?>

<div class="container my-4">
  <div class="card p-3 shadow-sm mb-4" style="border-radius: 14px;">
    <h4 class="mb-3 fw-bold text-dark"><i class="bi bi-graph-up text-primary me-2"></i> Financial Performance Reports</h4>

    <!-- Filters -->
    <form method="get" class="row g-3 mb-3 align-items-end">
      <div class="col-md-2">
        <label class="form-label small fw-semibold">Month</label>
        <select name="month" class="form-select">
          <option value="">-- All Months --</option>
          <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?= $m ?>" <?= $month===$m?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold">Year</label>
        <select name="year" class="form-select">
          <?php for($y=date('Y');$y>=2020;$y--): ?>
            <option value="<?= $y ?>" <?= $year===$y?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">From Date</label>
        <input type="date" name="from" class="form-control" value="<?= e($from) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">To Date</label>
        <input type="date" name="to" class="form-control" value="<?= e($to) ?>">
      </div>
      <div class="col-md-1 d-grid">
        <button class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
      </div>
      <div class="col-md-1 d-grid">
        <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Clear</a>
      </div>
    </form>

    <!-- Summary cards -->
    <div class="row g-3">
      <div class="col-md-4"><div class="card p-3 bg-light border-start border-4 border-success rounded-3"><small class="text-muted text-uppercase fw-bold">Total Sales (Billed)</small><h3 class="fw-bold text-success mb-0 mt-1">₹<?= money($totalIncome) ?></h3></div></div>
      <div class="col-md-4"><div class="card p-3 bg-light border-start border-4 border-danger rounded-3"><small class="text-muted text-uppercase fw-bold">Total Expenses</small><h3 class="fw-bold text-danger mb-0 mt-1">₹<?= money($totalExpense) ?></h3></div></div>
      <div class="col-md-4"><div class="card p-3 bg-light border-start border-4 border-primary rounded-3"><small class="text-muted text-uppercase fw-bold">Net Profit</small><h3 class="fw-bold text-primary mb-0 mt-1">₹<?= money($profit) ?></h3></div></div>
    </div>

    <!-- Category Revenue Earned Breakdown Section (SMM vs Web Development vs Branding) -->
    <div class="card border-0 shadow-sm mt-4" style="border-radius: 14px; background: #ffffff;">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-pie-chart-fill text-primary me-2"></i>Revenue Earned by Service Category (SMM vs Development vs Branding)</h6>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Total Earned: ₹<?= money($catGrandEarned) ?></span>
      </div>
      <div class="card-body p-4">
        <div class="row g-4 align-items-center">
          <div class="col-lg-5 text-center">
            <div style="max-height: 240px; position: relative;">
              <canvas id="categoryRevenueChart"></canvas>
            </div>
          </div>
          <div class="col-lg-7">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                  <tr>
                    <th>Service Category</th>
                    <th class="text-end">Total Invoiced (₹)</th>
                    <th class="text-end">Revenue Earned (₹)</th>
                    <th class="text-end" style="width: 120px;">Share (%)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($serviceCategories as $catName => $cData): 
                    $pct = $catGrandInvoiced > 0 ? round(($cData['invoiced'] / $catGrandInvoiced) * 100, 1) : 0;
                  ?>
                    <tr>
                      <td>
                        <div class="d-flex align-items-center">
                          <i class="bi <?= $cData['icon'] ?> me-2 fs-5" style="color: <?= $cData['color'] ?>;"></i>
                          <span class="fw-bold text-dark"><?= e($catName) ?></span>
                        </div>
                      </td>
                      <td class="text-end fw-semibold">₹<?= money($cData['invoiced']) ?></td>
                      <td class="text-end fw-bold text-success">₹<?= money($cData['earned']) ?></td>
                      <td class="text-end">
                        <div class="d-flex align-items-center justify-content-end gap-2">
                          <div class="progress flex-grow-1" style="height: 6px; width: 60px;">
                            <div class="progress-bar" style="width: <?= $pct ?>%; background-color: <?= $cData['color'] ?>;"></div>
                          </div>
                          <span class="small fw-bold text-dark"><?= $pct ?>%</span>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Charts -->
    <div class="row g-4 mt-3">
      <div class="col-lg-8">
        <div class="card p-3 shadow-sm">
          <h6 class="mb-3">Income vs Expenses (<?= $mode==='monthly_year'?"Monthly - $year":($mode==='daily_month'?date('F Y',strtotime($start)):"$start → $end") ?>)</h6>
          <canvas id="incomeExpenseChart" height="140"></canvas>
        </div>
        <div class="card p-3 mt-3 shadow-sm">
          <h6 class="mb-2">Top Customers</h6>
          <canvas id="topCustomersChart" height="140"></canvas>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card p-3 shadow-sm mb-3">
          <h6 class="mb-3">Expense Categories</h6>
          <canvas id="expenseCatsChart" height="220"></canvas>
        </div>

        <div class="card p-3 shadow-sm">
          <h6 class="mb-2">Top Customers (List)</h6>
          <ul class="list-group">
            <?php if ($topCustomers): foreach($topCustomers as $c): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"><?= e($c['name']) ?><span class="badge bg-primary">₹<?= money($c['total']) ?></span></li>
            <?php endforeach; else: ?>
            <li class="list-group-item text-muted">No customer data</li>
            <?php endif; ?>
          </ul>
        </div>

       
      </div>
    </div>

  <div class="card p-3 shadow-sm mt-4">
  <ul class="nav nav-tabs" id="reportTabs" role="tablist">
    <li class="nav-item">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sales" type="button">Sales</button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#expenses" type="button">Expenses</button>
    </li>
  </ul>
  <div class="tab-content mt-3">
    <!-- Sales Table -->
    <div class="tab-pane fade show active" id="sales">
      <div class="table-responsive">
        <table class="table table-striped table-bordered">
          <thead>
            <tr>
              <th>#Invoice</th>
              <th>Date</th>
              <th>Customer</th>
              <th>Amount (₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php if($invoices): 
              $totalSales = 0;
              foreach($invoices as $inv): 
                $totalSales += $inv['total_amount'];
            ?>
              <tr>
                <td><?= e($inv['invoice_no']) ?></td>
                <td><?= date('d M Y', strtotime($inv['invoice_date'])) ?></td>
                <td><?= e($inv['customer']) ?></td>
                <td class="text-end"><?= money($inv['total_amount']) ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="text-center text-muted">No invoices found for this period</td></tr>
            <?php endif; ?>
          </tbody>
          <?php if($invoices): ?>
          <tfoot>
            <tr>
              <th colspan="3" class="text-end">Grand Total</th>
              <th class="text-end"><?= money($totalSales) ?></th>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <!-- Expenses Table -->
    <div class="tab-pane fade" id="expenses">
      <div class="table-responsive">
        <table class="table table-striped table-bordered">
          <thead>
            <tr>
              <th>Date</th>
              <th>Category</th>
              <th>Description</th>
              <th>Amount (₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php if($expenses): 
              $totalExpenses = 0;
              foreach($expenses as $exp): 
                $totalExpenses += $exp['amount'];
            ?>
              <tr>
                <td><?= date('d M Y', strtotime($exp['expense_date'])) ?></td>
                <td><?= e($exp['category']) ?></td>
                <td><?= e($exp['description']) ?></td>
                <td class="text-end"><?= money($exp['amount']) ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="text-center text-muted">No expenses found for this period</td></tr>
            <?php endif; ?>
          </tbody>
          <?php if($expenses): ?>
          <tfoot>
            <tr>
              <th colspan="3" class="text-end">Grand Total</th>
              <th class="text-end"><?= money($totalExpenses) ?></th>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
      
    </div>
  </div> 
  <div class="mt-3 text-center">
          <a class="btn btn-danger btn-sm" href="export_pdf.php?<?= $exportQuery ?>"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
          <a class="btn btn-success btn-sm" href="export_excel.php?<?= $exportQuery ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        </div>
</div>

    
 

<!-- Charts JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode($labels) ?>;
const incomeData = <?= json_encode(array_map('floatval', $incomeData)) ?>;
const expenseData = <?= json_encode(array_map('floatval', $expenseData)) ?>;

// Income vs Expenses Chart
new Chart(document.getElementById('incomeExpenseChart'), {
  type:'bar',
  data:{ labels: labels, datasets:[
    {label:'Income', data: incomeData, backgroundColor:'rgba(54,162,235,0.6)'},
    {label:'Expenses', data: expenseData, backgroundColor:'rgba(255,99,132,0.6)'},
    {label:'Profit', data: incomeData.map((v,i)=>v-(expenseData[i]||0)), type:'line', borderColor:'#0d6efd', backgroundColor:'transparent', tension:0.2, order:2}
  ]},
  options:{ responsive:true, interaction:{mode:'index', intersect:false}, scales:{ y:{ beginAtZero:true } } }
});

// Expense categories pie
new Chart(document.getElementById('expenseCatsChart'), {
  type:'pie',
  data:{ labels: <?= json_encode(array_column($expenseCats,'category')) ?>, datasets:[{ data: <?= json_encode(array_map('floatval',array_column($expenseCats,'total'))) ?>, backgroundColor:['#ff6384','#36a2eb','#ffcd56','#4bc0c0','#9966ff','#ff9f40'] }]},
  options:{ responsive:true }
});

// Top customers horizontal bar
new Chart(document.getElementById('topCustomersChart'), {
  type:'bar',
  data:{ labels: <?= json_encode(array_column($topCustomers,'name')) ?>, datasets:[{ label:'Revenue', data: <?= json_encode(array_map('floatval',array_column($topCustomers,'total'))) ?>, backgroundColor:'rgba(75,192,192,0.6)'}]},
  options:{ indexAxis:'y', responsive:true, scales:{ x:{ beginAtZero:true } } }
});

// Service Category Revenue Doughnut Chart
new Chart(document.getElementById('categoryRevenueChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_keys($serviceCategories)) ?>,
    datasets: [{
      data: <?= json_encode(array_column($serviceCategories, 'earned')) ?>,
      backgroundColor: <?= json_encode(array_column($serviceCategories, 'color')) ?>,
      borderWidth: 2
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom' }
    }
  }
});
</script>

<?php include '../../includes/footer.php'; ?>
