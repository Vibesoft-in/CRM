
<?php
// includes/header.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>VibeSoft CMS</title>
  <meta name="robots" content="noindex, nofollow, noarchive">

  <!-- Bootstrap CSS -->
  <link href="<?= BASE_URL ?>assets/css/bootstrap.min.css" rel="stylesheet">

  <!-- Custom CSS -->
  <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">

  <!-- Favicon -->
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>


</head>
<body class="bg-light">

<!-- Navbar Start -->
<nav class="navbar navbar-expand-lg   shadow-sm black">
  <div class="container">
    <!-- Logo -->
    <a class="navbar-brand d-flex align-items-center" href="<?= BASE_URL ?>">
      <img src="<?= get_company_logo() ?>" alt="Logo" height="40" class="me-2" style="max-width: 160px; object-fit: contain;">
      <span class="fw-bold">VibeSoft CMS</span>
    </a>

    <!-- Toggle Button for Mobile -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Navbar Links -->
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto me-3">
     
      </ul>

      <!-- Right Side Buttons -->
      <div class="d-flex">
        <?php if (is_logged_in()): ?>
          <a href="<?= BASE_URL ?>modules/auth/logout.php" class="btn btn-danger">Logout</a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>modules/auth/login.php" class="btn btn-light text-primary">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<!-- Navbar End -->
