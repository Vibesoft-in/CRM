<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid Client ID");

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) die("Client not found");

// Re-calculate running balance before display to ensure complete accuracy
$finalBalance = recalculate_customer_ledger($id);

$stmt = $pdo->prepare("SELECT * FROM client_ledger WHERE customer_id = ? ORDER BY entry_date ASC, id ASC");
$stmt->execute([$id]);
$entries = $stmt->fetchAll();
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-journal-text text-primary me-2"></i>Financial Ledger: <?= e($client['name']) ?></h4>
      <p class="text-muted small mb-0">Complete historical account balance statement with running totals.</p>
    </div>
    <div class="d-flex gap-2">
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i> Print Statement</button>
      <a href="view.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-person me-1"></i> Back to Profile</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3 bg-light rounded-3 d-flex justify-content-between align-items-center">
      <div>
        <span class="text-muted small d-block">ACCOUNT HOLDER</span>
        <h5 class="fw-bold mb-0 text-dark"><?= e($client['name']) ?></h5>
      </div>
      <div class="text-end">
        <span class="text-muted small d-block">CURRENT NET OUTSTANDING BALANCE</span>
        <h4 class="fw-bold mb-0 text-<?= $finalBalance > 0 ? 'danger':'success' ?>">₹<?= money($finalBalance) ?></h4>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Date</th>
            <th>Description</th>
            <th>Ref Type</th>
            <th class="text-end">Debit (Billed ₹)</th>
            <th class="text-end">Credit (Paid ₹)</th>
            <th class="text-end pe-3">Running Balance (₹)</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($entries): ?>
            <?php foreach ($entries as $e): ?>
              <tr>
                <td class="ps-3 fw-medium"><?= e($e['entry_date']) ?></td>
                <td><?= e($e['description']) ?></td>
                <td><span class="badge bg-secondary"><?= strtoupper(e($e['reference_type'])) ?></span></td>
                <td class="text-end text-danger fw-semibold">
                  <?= $e['debit'] > 0 ? '₹' . money($e['debit']) : '—' ?>
                </td>
                <td class="text-end text-success fw-semibold">
                  <?= $e['credit'] > 0 ? '₹' . money($e['credit']) : '—' ?>
                </td>
                <td class="text-end pe-3 fw-bold text-dark">
                  ₹<?= money($e['balance']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center py-4 text-muted">No ledger transactions posted for this client yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
