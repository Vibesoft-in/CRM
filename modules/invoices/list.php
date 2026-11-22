<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

// POST Handlers for Status, Cancel, and Restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $inv_id = (int)($_POST['invoice_id'] ?? 0);
        $action = $_POST['action'];

        if ($action === 'cancel_invoice') {
            $reason = trim($_POST['cancellation_reason'] ?? 'Cancelled via Invoice List');
            if (cancel_invoice($inv_id, $reason, $_SESSION['user_id'] ?? null)) {
                set_flash("Invoice marked as CANCELLED. Serial number remains preserved for CA.", "success");
            } else {
                set_flash("Failed to cancel invoice.", "danger");
            }
        } elseif ($action === 'restore_invoice') {
            if (restore_invoice($inv_id)) {
                set_flash("Invoice restored to active state successfully.", "success");
            } else {
                set_flash("Failed to restore invoice.", "danger");
            }
        } elseif ($action === 'quick_status_change') {
            $new_status = trim($_POST['new_status'] ?? '');
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$inv_id]);
            $inv = $stmt->fetch();
            
            if ($inv && in_array($new_status, ['sent', 'paid', 'partially_paid', 'overdue', 'cancelled'])) {
                if ($new_status === 'cancelled') {
                    cancel_invoice($inv_id, 'Status changed to Cancelled');
                    set_flash('Invoice ' . $inv['invoice_no'] . ' marked as CANCELLED.', 'success');
                } else {
                    if ($inv['status'] === 'cancelled') {
                        restore_invoice($inv_id);
                    }
                    if ($new_status === 'paid' && $inv['status'] !== 'paid') {
                        $dueAmt = (float)$inv['amount_due'];
                        if ($dueAmt > 0) {
                            record_client_payment(
                                $inv['customer_id'],
                                $dueAmt,
                                date('Y-m-d'),
                                'Bank Transfer',
                                'TXN-QUICK',
                                'Quick Mark Paid',
                                'Status updated to Paid via Invoice List',
                                [['invoice_id' => $inv_id, 'amount' => $dueAmt]]
                            );
                        } else {
                            $upd = $pdo->prepare("UPDATE invoices SET status = 'paid', amount_due = 0, amount_paid = total_amount WHERE id = ?");
                            $upd->execute([$inv_id]);
                        }
                    } else {
                        $upd = $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?");
                        $upd->execute([$new_status, $inv_id]);
                    }
                    set_flash('Invoice ' . $inv['invoice_no'] . ' status updated to ' . strtoupper($new_status), 'success');
                }
            }
        }
    }
    header('Location: list.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');

$where = ["1=1"];
$params = [];

if ($statusFilter !== '') {
    $where[] = "i.status = ?";
    $params[] = $statusFilter;
}

if ($typeFilter !== '') {
    $where[] = "i.invoice_type = ?";
    $params[] = $typeFilter;
}

if ($search !== '') {
    $where[] = "(i.invoice_no LIKE ? OR c.name LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("
  SELECT i.*, c.name AS customer_name, c.client_logo 
  FROM invoices i 
  LEFT JOIN customers c ON c.id = i.customer_id 
  WHERE $whereSql
  ORDER BY i.invoice_date DESC, i.id DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$flash = get_flash();
?>


<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-receipt text-primary me-2"></i>Invoice Management</h4>
      <p class="text-muted small mb-0">Create, track, change payment status, and manage client billing statements.</p>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary shadow-sm fw-semibold" href="create.php">
        <i class="bi bi-plus-circle me-1"></i> Create New Invoice
      </a>
      <a class="btn btn-success shadow-sm fw-semibold" href="../recurring_invoices/monthly_billing.php">
        <i class="bi bi-calendar-check me-1"></i> Monthly Billing Manager
      </a>
      <a class="btn btn-outline-success shadow-sm fw-semibold" href="record_payment.php">
        <i class="bi bi-cash-coin me-1"></i> Record Payment
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Navigation Tabs: All vs GST (CA) vs Personal -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <ul class="nav nav-pills gap-2 mb-0">
      <li class="nav-item">
        <a class="nav-link <?= empty($typeFilter) ? 'active fw-bold' : 'bg-white text-secondary border' ?>" href="list.php<?= $statusFilter ? '?status=' . urlencode($statusFilter) : '' ?>">
          <i class="bi bi-receipt me-1"></i> All Invoices
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $typeFilter === 'gst' ? 'active fw-bold bg-primary' : 'bg-white text-dark border' ?>" href="list.php?type=gst<?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?>">
          <i class="bi bi-building-check me-1 text-primary"></i> Company / GST Bills (CA Track)
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $typeFilter === 'personal' ? 'active fw-bold bg-warning text-dark' : 'bg-white text-dark border' ?>" href="list.php?type=personal<?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?>">
          <i class="bi bi-person-badge me-1 text-warning"></i> Personal Account Bills
        </a>
      </li>
    </ul>

    <a href="gst_registry.php" class="btn btn-outline-primary btn-sm fw-semibold shadow-sm">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i> CA GST Filing Registry & Export ↗
    </a>
  </div>

  <!-- Search & Filter Bar -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-2 align-items-center">
        <?php if (!empty($typeFilter)): ?>
          <input type="hidden" name="type" value="<?= e($typeFilter) ?>">
        <?php endif; ?>
        <div class="col-md-7">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Search by invoice number or client name..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Invoice Statuses</option>
            <option value="paid" <?= $statusFilter==='paid'?'selected':'' ?>>Paid</option>
            <option value="partially_paid" <?= $statusFilter==='partially_paid'?'selected':'' ?>>Partially Paid</option>
            <option value="sent" <?= $statusFilter==='sent'?'selected':'' ?>>Sent / Unpaid</option>
            <option value="overdue" <?= $statusFilter==='overdue'?'selected':'' ?>>Overdue</option>
            <option value="cancelled" <?= $statusFilter==='cancelled'?'selected':'' ?>>Cancelled</option>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
          <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
          <a href="list.php<?= !empty($typeFilter) ? '?type=' . urlencode($typeFilter) : '' ?>" class="btn btn-sm btn-light border"><i class="bi bi-x-circle"></i></a>
        </div>
      </form>
    </div>
  </div>

  <!-- Invoices Table Card -->
  <div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden;">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="bg-light text-muted small uppercase">
            <tr>
              <th class="ps-3">Invoice #</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Due Date</th>
              <th class="text-end">Total Amount</th>
              <th class="text-end">Paid Amount</th>
              <th class="text-end">Amount Due</th>
              <th>Status / Quick Change</th>
              <th class="text-end pe-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($invoices): ?>
              <?php foreach ($invoices as $inv): 
                $due = (float)$inv['amount_due'];
                $status = strtolower($inv['status']);
                $badgeBg = $status==='paid'?'success':($status==='partially_paid'?'warning':($status==='overdue'?'danger':($status==='cancelled'?'danger':'primary')));
                $invType = $inv['invoice_type'] ?? 'gst';
                $isCancelled = ($status === 'cancelled');
              ?>
                <tr class="<?= $isCancelled ? 'table-light' : '' ?>" style="<?= $isCancelled ? 'background-color: #fff5f5;' : '' ?>">
                  <td class="ps-3">
                    <div class="d-flex align-items-center gap-1">
                      <a href="view.php?id=<?= $inv['id'] ?>" class="fw-bold <?= $isCancelled ? 'text-decoration-line-through text-danger' : 'text-primary text-decoration-none' ?>">
                        <?= e($inv['invoice_no']) ?>
                      </a>
                    </div>
                    <div>
                      <?php if ($invType === 'personal'): ?>
                        <span class="badge bg-warning-subtle text-dark border fw-semibold" style="font-size: 0.65rem;">Personal</span>
                      <?php else: ?>
                        <span class="badge bg-primary-subtle text-primary border fw-semibold" style="font-size: 0.65rem;">CA</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="d-flex align-items-center">
                      <?= get_client_logo_html(['name' => $inv['customer_name'], 'client_logo' => $inv['client_logo']], 32) ?>
                      <span class="fw-semibold text-dark"><?= e($inv['customer_name'] ?: 'N/A') ?></span>
                    </div>
                  </td>

                  <td class="small text-muted"><?= date('d M Y', strtotime($inv['invoice_date'] ?: $inv['created_at'])) ?></td>
                  <td class="small text-muted"><?= !empty($inv['due_date']) ? date('d M Y', strtotime($inv['due_date'])) : '—' ?></td>
                  <td class="text-end fw-bold text-dark">₹<?= money($inv['total_amount']) ?></td>
                  <td class="text-end text-success fw-semibold">₹<?= money($inv['amount_paid']) ?></td>
                  <td class="text-end fw-bold text-<?= ($due > 0 && !$isCancelled) ? 'danger':'muted' ?>">₹<?= money($isCancelled ? 0 : $due) ?></td>
                  <td>
                    <?php if ($isCancelled): ?>
                      <div class="dropdown d-inline-block">
                        <button class="btn btn-sm btn-danger dropdown-toggle py-1 px-2 fw-semibold" type="button" data-bs-toggle="dropdown" style="font-size: 0.75rem;">
                          <i class="bi bi-x-circle me-1"></i>CANCELLED
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="font-size: 0.85rem;">
                          <li><h6 class="dropdown-header text-danger">Invoice is Cancelled</h6></li>
                          <?php if (!empty($inv['cancellation_reason'])): ?>
                            <li class="px-3 py-1 small text-muted font-monospace">"<?= e($inv['cancellation_reason']) ?>"</li>
                          <?php endif; ?>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                            <form method="post" class="d-inline">
                              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="restore_invoice">
                              <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                              <button type="submit" class="dropdown-item text-success fw-bold" onclick="return confirm('Restore this cancelled invoice?');">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Restore Invoice
                              </button>
                            </form>
                          </li>
                        </ul>
                      </div>
                    <?php else: ?>
                      <div class="dropdown d-inline-block">
                        <button class="btn btn-sm btn-<?= $badgeBg ?> dropdown-toggle py-1 px-2 fw-semibold" type="button" data-bs-toggle="dropdown" style="font-size: 0.75rem;">
                          <?= strtoupper(e(str_replace('_', ' ', $inv['status']))) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="font-size: 0.85rem;">
                          <li><h6 class="dropdown-header">Change Status</h6></li>
                          <?php if ($status !== 'paid'): ?>
                            <li>
                              <form method="post" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="quick_status_change">
                                <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                <input type="hidden" name="new_status" value="paid">
                                <button type="submit" class="dropdown-item text-success fw-bold">
                                  <i class="bi bi-check-circle me-2"></i>Mark as Paid (Full)
                                </button>
                              </form>
                            </li>
                          <?php endif; ?>
                          <li>
                            <a class="dropdown-item text-primary" href="record_payment.php?customer_id=<?= $inv['customer_id'] ?>&invoice_id=<?= $inv['id'] ?>">
                              <i class="bi bi-cash-coin me-2"></i>Record Partial/Custom Payment
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                            <form method="post">
                              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="quick_status_change">
                              <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                              <input type="hidden" name="new_status" value="sent">
                              <button type="submit" class="dropdown-item"><i class="bi bi-send me-2 text-primary"></i>Set Sent / Unpaid</button>
                            </form>
                          </li>
                          <li>
                            <form method="post">
                              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="quick_status_change">
                              <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                              <input type="hidden" name="new_status" value="overdue">
                              <button type="submit" class="dropdown-item text-danger"><i class="bi bi-clock-history me-2"></i>Set Overdue</button>
                            </form>
                          </li>
                          <li>
                            <button type="button" class="dropdown-item text-danger" onclick="openCancelModal(<?= $inv['id'] ?>, '<?= e($inv['invoice_no']) ?>')">
                              <i class="bi bi-x-circle me-2"></i>Cancel Invoice...
                            </button>
                          </li>
                        </ul>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end pe-3">
                    <div class="dropdown">
                      <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-three-dots-vertical"></i>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="view.php?id=<?= $inv['id'] ?>"><i class="bi bi-eye text-primary me-2"></i> View Invoice</a></li>
                        <li><a class="dropdown-item" href="download.php?id=<?= $inv['id'] ?>" target="_blank"><i class="bi bi-download text-info me-2"></i> Download PDF</a></li>
                        
                        <?php if ($isCancelled): ?>
                          <li><span class="dropdown-item text-muted disabled"><i class="bi bi-slash-circle me-2"></i> Edit Disabled (Cancelled)</span></li>
                          <li>
                            <form method="post" class="m-0">
                              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="restore_invoice">
                              <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                              <button type="submit" class="dropdown-item text-success" onclick="return confirm('Restore this cancelled invoice?');">
                                <i class="bi bi-arrow-counterclockwise me-2"></i> Restore Invoice
                              </button>
                            </form>
                          </li>
                        <?php else: ?>
                          <li><a class="dropdown-item" href="edit.php?id=<?= $inv['id'] ?>"><i class="bi bi-pencil text-secondary me-2"></i> Edit Invoice</a></li>
                          <li>
                            <button type="button" class="dropdown-item text-danger" onclick="openCancelModal(<?= $inv['id'] ?>, '<?= e($inv['invoice_no']) ?>')">
                              <i class="bi bi-x-circle me-2"></i> Cancel Invoice
                            </button>
                          </li>
                        <?php endif; ?>

                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <form method="post" action="delete.php" onsubmit="return confirm('⚠️ WARNING: Deleting creates an invoice number gap! Consider Cancelling instead. Are you sure you want to delete <?= e($inv['invoice_no']) ?>?');" class="m-0">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i> Delete Invoice</button>
                          </form>
                        </li>
                      </ul>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center py-5 text-muted">
                  <i class="bi bi-receipt fs-1 d-block mb-2 text-muted"></i>
                  No invoices found matching criteria.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Cancel Invoice Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title fw-bold" id="cancelModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i> Cancel Invoice</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="cancel_invoice">
        <input type="hidden" name="invoice_id" id="modalCancelInvoiceId" value="">
        <div class="modal-body p-4">
          <p class="mb-2">Are you sure you want to cancel invoice <strong id="modalCancelInvoiceNo" class="text-danger"></strong>?</p>
          <div class="alert alert-warning small mb-3">
            <i class="bi bi-shield-check me-1"></i> <strong>Audit Compliance:</strong> The serial number will be preserved in the CA sequence so there is zero gap. The invoice will be disabled, ledger debit will be removed, and a prominent red CANCELED watermark will be stamped.
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Reason for Cancellation <span class="text-danger">*</span></label>
            <textarea name="cancellation_reason" class="form-control" rows="3" placeholder="e.g. Order cancelled by customer, duplicate bill, incorrect amount" required></textarea>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-x-circle me-1"></i> Confirm Cancellation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openCancelModal(id, invoiceNo) {
  document.getElementById('modalCancelInvoiceId').value = id;
  document.getElementById('modalCancelInvoiceNo').innerText = invoiceNo;
  var myModal = new bootstrap.Modal(document.getElementById('cancelModal'));
  myModal.show();
}
</script>

<?php include '../../includes/footer.php'; ?>

