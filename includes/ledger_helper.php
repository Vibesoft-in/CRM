<?php
// includes/ledger_helper.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

/**
 * Update an invoice's status based on total_amount and amount_paid
 */
function update_invoice_status($invoice_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT total_amount, amount_paid, due_date, status FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch();
    if (!$inv) return;
    
    // Sum total allocations for this invoice
    $allocStmt = $pdo->prepare("SELECT SUM(amount) FROM payment_allocations WHERE invoice_id = ?");
    $allocStmt->execute([$invoice_id]);
    $totalPaid = (float) ($allocStmt->fetchColumn() ?: 0);
    
    $totalAmount = (float) $inv['total_amount'];
    $amountDue = max(0, $totalAmount - $totalPaid);
    
    $status = 'sent';
    if ($totalPaid >= $totalAmount && $totalAmount > 0) {
        $status = 'paid';
    } elseif ($totalPaid > 0) {
        $status = 'partially_paid';
    } elseif (!empty($inv['due_date']) && strtotime($inv['due_date']) < strtotime(date('Y-m-d'))) {
        $status = 'overdue';
    }
    
    $updateStmt = $pdo->prepare("UPDATE invoices SET amount_paid = ?, amount_due = ?, status = ? WHERE id = ?");
    $updateStmt->execute([$totalPaid, $amountDue, $status, $invoice_id]);
}

/**
 * Post debit entry for new invoice to customer ledger
 */
function post_invoice_to_ledger($invoice_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id, invoice_no, customer_id, total_amount, invoice_date, created_at FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch();
    if (!$inv) return;
    
    $date = !empty($inv['invoice_date']) ? $inv['invoice_date'] : date('Y-m-d', strtotime($inv['created_at']));
    
    // Check if already in ledger
    $chk = $pdo->prepare("SELECT id FROM client_ledger WHERE reference_type = 'invoice' AND reference_id = ?");
    $chk->execute([$invoice_id]);
    if ($chk->fetch()) {
        // Update debit
        $upd = $pdo->prepare("UPDATE client_ledger SET debit = ?, entry_date = ? WHERE reference_type = 'invoice' AND reference_id = ?");
        $upd->execute([$inv['total_amount'], $date, $invoice_id]);
    } else {
        $ins = $pdo->prepare("INSERT INTO client_ledger (customer_id, entry_date, description, reference_type, reference_id, debit, credit) VALUES (?, ?, ?, 'invoice', ?, ?, 0)");
        $ins->execute([
            $inv['customer_id'],
            $date,
            "Invoice " . $inv['invoice_no'],
            $invoice_id,
            $inv['total_amount']
        ]);
    }
    
    recalculate_customer_ledger($inv['customer_id']);
}

/**
 * Record payment from client, allocate to invoices, update ledger
 */
function record_client_payment($customer_id, $amount, $payment_date, $payment_method, $transaction_id = '', $reference = '', $notes = '', $allocations = []) {
    global $pdo;
    
    $pdo->beginTransaction();
    try {
        // Generate payment_no
        $prefix = "PAY-" . date('Ym', strtotime($payment_date)) . "-";
        $cntStmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE payment_no LIKE '$prefix%'");
        $seq = (int)$cntStmt->fetchColumn() + 1;
        $payment_no = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
        
        $userId = $_SESSION['user_id'] ?? null;
        $firstInvId = (!empty($allocations) && isset($allocations[0]['invoice_id'])) ? (int)$allocations[0]['invoice_id'] : null;
        if ($firstInvId <= 0) $firstInvId = null;

        // Insert Payment Record
        $stmt = $pdo->prepare("INSERT INTO payments (payment_no, customer_id, invoice_id, amount, payment_date, payment_mode, payment_method, transaction_id, reference, notes, unallocated_amount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?)");
        $stmt->execute([
            $payment_no,
            $customer_id,
            $firstInvId,
            $amount,
            $payment_date,
            $payment_method,
            $payment_method,
            $transaction_id,
            $reference,
            $notes,
            $amount, // starts full, then decreases as allocated
            $userId
        ]);
        $payment_id = $pdo->lastInsertId();
        
        $remainingPayment = (float)$amount;
        
        // Handle allocations: array of ['invoice_id' => X, 'amount' => Y]
        foreach ($allocations as $alloc) {
            $invId = (int)$alloc['invoice_id'];
            $allocAmt = (float)$alloc['amount'];
            
            if ($invId > 0 && $allocAmt > 0 && $remainingPayment > 0) {
                $actualAlloc = min($allocAmt, $remainingPayment);
                
                $allocStmt = $pdo->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
                $allocStmt->execute([$payment_id, $invId, $actualAlloc]);
                
                $remainingPayment -= $actualAlloc;
                update_invoice_status($invId);
            }
        }
        
        // Update unallocated amount
        $updUnalloc = $pdo->prepare("UPDATE payments SET unallocated_amount = ? WHERE id = ?");
        $updUnalloc->execute([$remainingPayment, $payment_id]);
        
        // Add to client ledger
        $ledgerStmt = $pdo->prepare("INSERT INTO client_ledger (customer_id, entry_date, description, reference_type, reference_id, debit, credit) VALUES (?, ?, ?, 'payment', ?, 0, ?)");
        $ledgerStmt->execute([
            $customer_id,
            $payment_date,
            "Payment Received (" . $payment_no . " - " . $payment_method . ")",
            $payment_id,
            $amount
        ]);
        
        recalculate_customer_ledger($customer_id);
        
        $pdo->commit();
        return $payment_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Re-calculate running balance in client_ledger for a customer
 */
function recalculate_customer_ledger($customer_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM client_ledger WHERE customer_id = ? ORDER BY entry_date ASC, id ASC");
    $stmt->execute([$customer_id]);
    $entries = $stmt->fetchAll();
    
    $runningBalance = 0;
    $upd = $pdo->prepare("UPDATE client_ledger SET balance = ? WHERE id = ?");
    
    foreach ($entries as $row) {
        $runningBalance += ((float)$row['debit'] - (float)$row['credit']);
        $upd->execute([$runningBalance, $row['id']]);
    }
    
    return $runningBalance;
}

/**
 * Get Centralized Financial Summary for date range
 */
function get_financial_summary($start_date = null, $end_date = null) {
    global $pdo;
    
    $whereInv = "status != 'cancelled'";
    $wherePay = "status = 'Completed'";
    $whereExp = "status = 'Paid'";
    $wherePayr = "status = 'Paid'";
    $wherePend = "status NOT IN ('paid', 'cancelled') AND amount_due > 0";
    
    $paramsInv = [];
    $paramsPay = [];
    $paramsExp = [];
    $paramsPayr = [];
    $paramsPend = [];
    
    if (!empty($start_date) && !empty($end_date)) {
        $whereInv .= " AND COALESCE(invoice_date, DATE(created_at)) BETWEEN ? AND ?";
        $paramsInv = [$start_date, $end_date];
        
        $wherePay .= " AND COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?";
        $paramsPay = [$start_date, $end_date];
        
        $whereExp .= " AND COALESCE(expense_date, DATE(created_at)) BETWEEN ? AND ?";
        $paramsExp = [$start_date, $end_date];
        
        // Match salary month/period from Payroll & Slips tab
        $wherePayr .= " AND STR_TO_DATE(CONCAT(payroll_year, '-', LPAD(payroll_month, 2, '00'), '-01'), '%Y-%m-%d') <= ? AND LAST_DAY(STR_TO_DATE(CONCAT(payroll_year, '-', LPAD(payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) >= ?";
        $paramsPayr = [$end_date, $start_date];
        
        $wherePend .= " AND COALESCE(invoice_date, DATE(created_at)) BETWEEN ? AND ?";
        $paramsPend = [$start_date, $end_date];
    }
    
    // Total Revenue (Total billed in date range)
    $stmtInv = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE $whereInv");
    $stmtInv->execute($paramsInv);
    $totalRevenue = (float) ($stmtInv->fetchColumn() ?: 0);
    
    // Total Payments Received
    $stmtPay = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE $wherePay");
    $stmtPay->execute($paramsPay);
    $totalReceived = (float) ($stmtPay->fetchColumn() ?: 0);
    
    // Total Pending Receivable across invoices in date range (or all-time active if no range)
    $stmtPend = $pdo->prepare("SELECT SUM(amount_due) FROM invoices WHERE $wherePend");
    $stmtPend->execute($paramsPend);
    $totalPending = (float) ($stmtPend->fetchColumn() ?: 0);
    
    // Total Expenses
    $stmtExp = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE $whereExp");
    $stmtExp->execute($paramsExp);
    $totalExpenses = (float) ($stmtExp->fetchColumn() ?: 0);
    
    // Team Payroll Expense (using Payroll & Slips business logic: paid net_salary in applicable period)
    $stmtPayr = $pdo->prepare("SELECT SUM(COALESCE(NULLIF(amount_paid, 0), net_salary)) FROM payroll WHERE $wherePayr");
    $stmtPayr->execute($paramsPayr);
    $teamPayroll = (float) ($stmtPayr->fetchColumn() ?: 0);
    
    // Total Outflow (Expenses + Team Payroll)
    $totalOutflow = $totalExpenses + $teamPayroll;

    // Net Profit: Only actual money received during the selected period is treated as revenue/income.
    // Unreceived/invoiced revenue is not counted as received profit.
    // NET PROFIT = TOTAL RECEIVED - (TOTAL EXPENSES + TEAM PAYROLL)
    $netProfit = $totalReceived - $totalOutflow;
    $profitMargin = $totalReceived > 0 ? round(($netProfit / $totalReceived) * 100, 2) : 0.0;
    
    return [
        'total_revenue'  => $totalRevenue,
        'total_received' => $totalReceived,
        'total_pending'  => $totalPending,
        'total_expenses' => $totalExpenses,
        'team_payroll'   => $teamPayroll,
        'total_outflow'  => $totalOutflow,
        'net_profit'     => $netProfit,
        'profit_margin'  => $profitMargin,
    ];
}

/**
 * Delete payment record, remove allocations, update invoice statuses, and recalculate customer ledger
 */
function delete_client_payment($payment_id) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();
    if (!$payment) return false;

    $customerId = (int)$payment['customer_id'];

    $pdo->beginTransaction();
    try {
        // Find all invoices affected by allocations from this payment
        $allocStmt = $pdo->prepare("SELECT DISTINCT invoice_id FROM payment_allocations WHERE payment_id = ?");
        $allocStmt->execute([$payment_id]);
        $affectedInvoiceIds = $allocStmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete payment allocations
        $delAlloc = $pdo->prepare("DELETE FROM payment_allocations WHERE payment_id = ?");
        $delAlloc->execute([$payment_id]);

        // Update status and amounts for affected invoices
        foreach ($affectedInvoiceIds as $invId) {
            update_invoice_status($invId);
        }

        // Delete client ledger entry
        $delLedger = $pdo->prepare("DELETE FROM client_ledger WHERE reference_type = 'payment' AND reference_id = ?");
        $delLedger->execute([$payment_id]);

        // Delete payment row
        $delPay = $pdo->prepare("DELETE FROM payments WHERE id = ?");
        $delPay->execute([$payment_id]);

        // Recalculate customer ledger balance
        recalculate_customer_ledger($customerId);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Update payment record, update ledger entry, re-evaluate invoice allocations if amount changed, and recalculate customer ledger
 */
function update_client_payment($payment_id, $amount, $payment_date, $payment_method, $transaction_id = '', $reference = '', $notes = '', $status = 'Completed') {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
    $stmt->execute([$payment_id]);
    $oldPay = $stmt->fetch();
    if (!$oldPay) return false;

    $customerId = (int)$oldPay['customer_id'];
    $amount = (float)$amount;

    $pdo->beginTransaction();
    try {
        // Check current allocations
        $allocStmt = $pdo->prepare("SELECT SUM(amount) FROM payment_allocations WHERE payment_id = ?");
        $allocStmt->execute([$payment_id]);
        $allocatedSum = (float)($allocStmt->fetchColumn() ?: 0);

        // If new amount is less than already allocated sum, scale down allocations proportionally or cap
        if ($amount < $allocatedSum) {
            $getAlloc = $pdo->prepare("SELECT id, invoice_id, amount FROM payment_allocations WHERE payment_id = ? ORDER BY id ASC");
            $getAlloc->execute([$payment_id]);
            $allocs = $getAlloc->fetchAll();

            $rem = $amount;
            foreach ($allocs as $a) {
                if ($rem > 0) {
                    $newAllocAmt = min($a['amount'], $rem);
                    $updAlloc = $pdo->prepare("UPDATE payment_allocations SET amount = ? WHERE id = ?");
                    $updAlloc->execute([$newAllocAmt, $a['id']]);
                    $rem -= $newAllocAmt;
                } else {
                    $delAlloc = $pdo->prepare("DELETE FROM payment_allocations WHERE id = ?");
                    $delAlloc->execute([$a['id']]);
                }
                update_invoice_status($a['invoice_id']);
            }
            $unallocated = 0;
        } else {
            $unallocated = $amount - $allocatedSum;
        }

        // Update payment row
        $updPay = $pdo->prepare("UPDATE payments SET amount = ?, payment_date = ?, payment_mode = ?, payment_method = ?, transaction_id = ?, reference = ?, notes = ?, unallocated_amount = ?, status = ? WHERE id = ?");
        $updPay->execute([
            $amount,
            $payment_date,
            $payment_method,
            $payment_method,
            $transaction_id,
            $reference,
            $notes,
            $unallocated,
            $status,
            $payment_id
        ]);

        // Update ledger entry
        $updLedger = $pdo->prepare("UPDATE client_ledger SET entry_date = ?, description = ?, credit = ? WHERE reference_type = 'payment' AND reference_id = ?");
        $updLedger->execute([
            $payment_date,
            "Payment Received (" . $oldPay['payment_no'] . " - " . $payment_method . ")",
            $amount,
            $payment_id
        ]);

        // Recalculate customer ledger balance
        recalculate_customer_ledger($customerId);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
