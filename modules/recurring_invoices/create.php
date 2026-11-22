<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $customer_id = $_POST['customer_id'];
    $amount = $_POST['amount'];
    $start_date = $_POST['start_date'];              // YYYY-MM-DD
    $end_date = $_POST['end_date'] ?: null;
    $frequency = $_POST['frequency'];                // daily, weekly, monthly, quarterly, yearly

    // map frequency to PHP DateInterval spec
    $intervalMap = [
        'daily'     => 'P1D',
        'weekly'    => 'P7D',
        'monthly'   => 'P1M',
        'quarterly' => 'P3M',
        'yearly'    => 'P1Y'
    ];

    if (!isset($intervalMap[$frequency])) {
        die('Invalid frequency');
    }

    // Compute next_invoice_date = start_date + interval (so next occurrence)
    $dt = new DateTime($start_date);
    $dt->add(new DateInterval($intervalMap[$frequency]));
    $next_invoice_date = $dt->format('Y-m-d');

    // If you prefer the first invoice to be generated on start_date (not the next),
    // you can set $next_invoice_date = $start_date instead. This example chooses the "next" behaviour.

    $stmt = $pdo->prepare("
        INSERT INTO recurring_invoices
            (customer_id, amount, start_date, end_date, frequency, next_invoice_date, status)
        VALUES (?, ?, ?, ?, ?, ?, 'active')
    ");
    $stmt->execute([$customer_id, $amount, $start_date, $end_date, $frequency, $next_invoice_date]);

    header('Location: index.php?created=1');
    exit;
}

// show form below (same as before)...
?>
<!-- HTML form: customer_id, amount, start_date, end_date, frequency -->


<div class="container my-4">
    <h4>Create Recurring Invoice</h4>
    <form method="POST">
        <div class="mb-3">
            <label>Customer</label>
            <select name="customer_id" class="form-control" required>
                <?php
                $customers = $pdo->query("SELECT id, name FROM customers")->fetchAll();
                foreach($customers as $c){
                    echo "<option value='{$c['id']}'>{$c['name']}</option>";
                }
                ?>
            </select>
        </div>
        <div class="mb-3">
            <label>Amount</label>
            <input type="number" name="amount" step="0.01" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Start Date</label>
            <input type="date" name="start_date" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>End Date</label>
            <input type="date" name="end_date" class="form-control">
        </div>
        <div class="mb-3">
            <label>Frequency</label>
            <select name="frequency" class="form-control" required>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
