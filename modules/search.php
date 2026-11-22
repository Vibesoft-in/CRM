<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$q = trim($_GET['q'] ?? '');
$customers = [];

if ($q !== '') {
    $searchTerm = "%$q%";
    // Clean numbers in case user searches formatted phone like 98765-43210
    $cleanNum = preg_replace('/[^0-9]/', '', $q);
    $cleanSearch = $cleanNum ? "%$cleanNum%" : $searchTerm;

    // Search customers by name, phone, alternate phone, whatsapp, contact person, or email
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id) AS total_invoices,
               (SELECT COALESCE(SUM(amount_due), 0) FROM invoices WHERE customer_id = c.id AND status != 'cancelled') AS total_due
        FROM customers c
        WHERE c.name LIKE ? 
           OR c.contact_person LIKE ? 
           OR c.phone LIKE ? 
           OR c.alternate_phone LIKE ? 
           OR c.whatsapp LIKE ?
           OR c.email LIKE ?
        ORDER BY 
          CASE 
            WHEN c.name LIKE ? THEN 1
            WHEN c.phone LIKE ? THEN 2
            ELSE 3
          END,
          c.name ASC
    ");
    $stmt->execute([
        $searchTerm, $searchTerm, $cleanSearch, $cleanSearch, $cleanSearch, $searchTerm,
        "$q%", "$q%"
    ]);
    $customers = $stmt->fetchAll();
}
?>

<div class="container-fluid py-3">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1">
        <i class="bi bi-people text-primary me-2"></i>Client Search Results
      </h4>
      <p class="text-muted small mb-0">
        <?php if ($q): ?>
          Showing matching clients for search query: <span class="badge bg-primary fs-6 px-2 py-1"><?= e($q) ?></span>
        <?php else: ?>
          Enter a client name or phone number in the top search bar to find customer details.
        <?php endif; ?>
      </p>
    </div>
    <div>
      <a href="<?= BASE_URL ?>modules/customers/list.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-people me-1"></i> All Clients
      </a>
      <a href="<?= BASE_URL ?>modules/customers/add.php" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus me-1"></i> Add New Client
      </a>
    </div>
  </div>

  <!-- Search Input Bar on Page -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
    <div class="card-body p-3">
      <form method="get" action="search.php" class="row g-2 align-items-center">
        <div class="col-md-10">
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Search by customer name, phone number, or contact person..." value="<?= e($q) ?>" required autofocus>
          </div>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary w-100 fw-semibold">
            <i class="bi bi-search me-1"></i> Search Client
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($q !== ''): ?>
    <?php if (empty($customers)): ?>
      <div class="card border-0 shadow-sm text-center py-5" style="border-radius: 14px;">
        <div class="card-body py-5">
          <i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i>
          <h5 class="fw-bold text-dark">No clients found matching "<?= e($q) ?>"</h5>
          <p class="text-muted small mb-3">Check the phone number or name spelling, or add a new customer record.</p>
          <a href="<?= BASE_URL ?>modules/customers/add.php" class="btn btn-primary btn-sm">
            <i class="bi bi-person-plus me-1"></i> Add New Client
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
          <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-check2-circle text-success me-1"></i> Found <?= count($customers) ?> matching client<?= count($customers) > 1 ? 's' : '' ?>
          </h6>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light text-muted small uppercase">
                <tr>
                  <th class="ps-4">Client / Company Name</th>
                  <th>Contact Person</th>
                  <th>Primary Phone</th>
                  <th>WhatsApp / Alt Phone</th>
                  <th>Email & City</th>
                  <th class="text-end">Total Due</th>
                  <th class="text-center">Total Bills</th>
                  <th class="text-end pe-4">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($customers as $c): 
                  $due = (float)($c['total_due'] ?? 0);
                  $invCount = (int)($c['total_invoices'] ?? 0);
                ?>
                  <tr>
                    <td class="ps-4">
                      <div class="d-flex align-items-center">
                        <?= get_client_logo_html(['name' => $c['name'], 'client_logo' => $c['client_logo']], 36) ?>
                        <div class="ms-2">
                          <a href="<?= BASE_URL ?>modules/customers/view.php?id=<?= $c['id'] ?>" class="fw-bold text-primary text-decoration-none d-block">
                            <?= e($c['name']) ?>
                          </a>
                          <?php if (!empty($c['gstin'])): ?>
                            <small class="badge bg-light text-secondary border font-monospace" style="font-size:0.65rem;">GSTIN: <?= e($c['gstin']) ?></small>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="text-dark fw-medium"><?= e($c['contact_person'] ?: '—') ?></span>
                    </td>
                    <td>
                      <?php if (!empty($c['phone'])): ?>
                        <a href="tel:<?= e($c['phone']) ?>" class="text-decoration-none fw-semibold text-dark">
                          <i class="bi bi-telephone text-primary me-1"></i><?= e($c['phone']) ?>
                        </a>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($c['whatsapp'])): ?>
                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $c['whatsapp']) ?>" target="_blank" class="text-decoration-none text-success fw-semibold me-2" title="WhatsApp Message">
                          <i class="bi bi-whatsapp"></i> <?= e($c['whatsapp']) ?>
                        </a>
                      <?php endif; ?>
                      <?php if (!empty($c['alternate_phone']) && $c['alternate_phone'] !== $c['whatsapp']): ?>
                        <small class="text-muted d-block"><i class="bi bi-phone"></i> <?= e($c['alternate_phone']) ?></small>
                      <?php endif; ?>
                      <?php if (empty($c['whatsapp']) && empty($c['alternate_phone'])): ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($c['email'])): ?>
                        <small class="text-muted d-block"><i class="bi bi-envelope me-1"></i><?= e($c['email']) ?></small>
                      <?php endif; ?>
                      <?php if (!empty($c['city']) || !empty($c['state'])): ?>
                        <small class="text-muted d-block"><i class="bi bi-geo-alt me-1"></i><?= e(trim(($c['city'] ?? '') . ', ' . ($c['state'] ?? ''), ', ')) ?></small>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <span class="fw-bold <?= $due > 0 ? 'text-danger' : 'text-success' ?>">
                        ₹<?= money($due) ?>
                      </span>
                    </td>
                    <td class="text-center">
                      <span class="badge bg-light text-dark border"><?= $invCount ?></span>
                    </td>
                    <td class="text-end pe-4">
                      <div class="d-flex justify-content-end gap-1">
                        <a href="<?= BASE_URL ?>modules/customers/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary fw-semibold" title="View Full Client Profile">
                          <i class="bi bi-person me-1"></i> Profile
                        </a>
                        <a href="<?= BASE_URL ?>modules/customers/ledger.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View Ledger">
                          <i class="bi bi-journal-text"></i>
                        </a>
                        <a href="<?= BASE_URL ?>modules/invoices/create.php?customer_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-success" title="New Invoice">
                          <i class="bi bi-plus-lg"></i>
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
