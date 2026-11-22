<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/audit_helper.php';
require_login();
if (!is_admin()) {
    set_flash("Access Denied: Only administrators can access database backups.", "danger");
    header("Location: " . BASE_URL . "index.php");
    exit;
}

$backupDir = __DIR__ . '/../../database/backups/';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

// Handle Manual Backup Trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_backup') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $filename = "backup_" . date('Y-m-d_H-i-s') . ".sql";
        $filepath = $backupDir . $filename;
        
        try {
            // Fetch database tables and generate dump
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $dump = "-- Vibesoft Agency ERP Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
            
            foreach ($tables as $table) {
                $createSql = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
                $dump .= $createSql['Create Table'] . ";\n\n";
                
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $keys = array_map(function($k) { return "`$k`"; }, array_keys($row));
                    $vals = array_map(function($v) use ($pdo) {
                        return $v === null ? "NULL" : $pdo->quote($v);
                    }, array_values($row));
                    
                    $dump .= "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $dump .= "\n";
            }
            $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
            
            file_put_contents($filepath, $dump);
            
            log_audit('Backup System', 'Created Database Backup', null, ['filename' => $filename]);
            set_flash("Database backup created successfully: $filename", 'success');
            header('Location: backup.php');
            exit;
        } catch (Exception $e) {
            $err = "Backup failed: " . $e->getMessage();
        }
    }
}

// Fetch existing backups
$backupFiles = glob($backupDir . "*.sql");
rsort($backupFiles);
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-database-gear text-primary me-2"></i>Database Backup & Safety</h4>
      <p class="text-muted small mb-0">Create full SQL database snapshots including encrypted credential vault backups.</p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="create_backup">
      <button type="submit" class="btn btn-success shadow-sm fw-semibold">
        <i class="bi bi-download me-1"></i> Create Manual Backup
      </button>
    </form>
  </div>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Backup History Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="card-header bg-white py-3">
      <h6 class="fw-bold mb-0">Backup History & Snapshots</h6>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Filename</th>
            <th>Creation Date</th>
            <th>File Size</th>
            <th class="text-center pe-3">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($backupFiles): ?>
            <?php foreach ($backupFiles as $f): 
              $name = basename($f);
              $size = filesize($f);
              $date = date("F d, Y H:i:s", filemtime($f));
            ?>
              <tr>
                <td class="ps-3 fw-bold font-monospace text-primary"><?= e($name) ?></td>
                <td><?= e($date) ?></td>
                <td><?= round($size / 1024, 2) ?> KB</td>
                <td class="text-center pe-3">
                  <a href="../../database/backups/<?= e($name) ?>" download class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-download me-1"></i> Download SQL
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" class="text-center py-5 text-muted">No database backups generated yet. Click "Create Manual Backup" to make your first snapshot.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
