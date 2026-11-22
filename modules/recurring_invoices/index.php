<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$recurring = $pdo->query("
    SELECT r.*, c.name AS customer_name
    FROM recurring_invoices r
    JOIN customers c ON r.customer_id = c.id
    ORDER BY r.id DESC
")->fetchAll();
?>

<div class="container my-4">
    <h4>Recurring Invoices</h4>
    <a href="create.php" class="btn btn-primary mb-3">+ Create Recurring Invoice</a>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Frequency</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Next Invoice</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($recurring as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['customer_name']) ?></td>
                    <td><?= number_format($r['amount'], 2) ?></td>
                    <td><?= ucfirst($r['frequency']) ?></td>
                    <td><?= $r['start_date'] ?></td>
                    <td><?= $r['end_date'] ?: 'N/A' ?></td>
                    <td><?= $r['next_invoice_date'] ?></td>
                    <td>
                        <?php if($r['status'] == 'active'): ?>
                            <span class="badge bg-success">Active</span>
                        <?php elseif($r['status'] == 'paused'): ?>
                            <span class="badge bg-warning">Paused</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Cancelled</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li>
                                    <a class="dropdown-item" href="edit.php?id=<?= $r['id'] ?>">
                                        <i class="bi bi-pencil text-primary me-2"></i> Edit
                                    </a>
                                </li>
                                <?php if($r['status'] == 'active'): ?>
                                    <li>
                                        <a class="dropdown-item text-warning" href="pause.php?id=<?= $r['id'] ?>">
                                            <i class="bi bi-pause-circle me-2"></i> Pause
                                        </a>
                                    </li>
                                <?php elseif($r['status'] == 'paused'): ?>
                                    <li>
                                        <a class="dropdown-item text-success" href="resume.php?id=<?= $r['id'] ?>">
                                            <i class="bi bi-play-circle me-2"></i> Resume
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="cancel.php?id=<?= $r['id'] ?>" onclick="return confirm('Cancel this recurring schedule?');">
                                        <i class="bi bi-x-circle me-2"></i> Cancel
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
