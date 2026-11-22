<?php
// database/reset_and_seed_data.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ledger_helper.php';

echo "====================================================\n";
echo "    CMS RE-INITIALIZATION WITH EXACT BILLING DAYS   \n";
echo "====================================================\n\n";

try {
    // 1. Wipe Old Client & Financial Data (Clean Reset)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tablesToTruncate = [
        'payment_allocations',
        'payments',
        'invoice_items',
        'invoices',
        'billing_cycles',
        'client_ledger',
        'extra_work',
        'client_quotations',
        'customer_services',
        'projects',
        'credentials',
        'credential_password_history',
        'customers'
    ];

    foreach ($tablesToTruncate as $tbl) {
        $pdo->exec("TRUNCATE TABLE {$tbl}");
        echo "✔ Truncated {$tbl}\n";
    }

    // Reset Invoice Counter to starting number 10000 (First invoice INV-10001)
    $pdo->exec("UPDATE invoice_counter SET last_number = 10000 WHERE id = 1");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✔ Clean DB Reset completed. Auto-increments reset.\n\n";

    // Helper to auto-find logo file in uploads/client_Logo/
    $findLogo = function($clientName) {
        $clientDir = __DIR__ . '/../uploads/client_Logo/';
        if (!is_dir($clientDir)) return null;
        $files = scandir($clientDir);
        $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $clientName));
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $cleanFile = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($file, PATHINFO_FILENAME)));
            if (!empty($cleanName) && !empty($cleanFile) && (strpos($cleanName, $cleanFile) !== false || strpos($cleanFile, $cleanName) !== false)) {
                return 'uploads/client_Logo/' . $file;
            }
        }
        return null;
    };

    // Client Dataset Definition with Exact Onboarding Dates & Corrected Kimmaya Salary (₹8,000)
    $clientsData = [
        [
            'name' => 'Kamal Singhal (Hiring Ad)',
            'type' => 'one_time',
            'onboard_date' => '2026-06-15',
            'monthly_amount' => 0,
            'projects' => [
                ['name' => 'Meta ads', 'amount' => 2500, 'type' => 'One-Time']
            ]
        ],
        [
            'name' => 'Arun Mehandi',
            'type' => 'monthly',
            'onboard_date' => '2026-07-22',
            'monthly_amount' => 12000,
            'service_name' => 'Mehandi Marketing & SMM Package',
            'projects' => []
        ],
        [
            'name' => 'EzyBuzz',
            'type' => 'one_time',
            'onboard_date' => '2026-07-24',
            'monthly_amount' => 0,
            'projects' => [
                ['name' => 'Digital Branding & Project', 'amount' => 15000, 'type' => 'One-Time']
            ]
        ],
        [
            'name' => 'JJM',
            'type' => 'one_time',
            'onboard_date' => '2025-10-01',
            'monthly_amount' => 0,
            'projects' => [
                ['name' => 'JJM Enterprise Platform', 'amount' => 1500000, 'type' => 'One-Time']
            ]
        ],
        [
            'name' => 'Tru Pahadi',
            'type' => 'one_time',
            'onboard_date' => '2026-07-23',
            'monthly_amount' => 0,
            'projects' => [
                ['name' => 'Pahadi Brand & E-Commerce', 'amount' => 15000, 'type' => 'One-Time']
            ]
        ],
        [
            'name' => 'Genie',
            'type' => 'monthly',
            'onboard_date' => '2026-01-05',
            'monthly_amount' => 6000,
            'service_name' => 'Digital Marketing Package',
            'projects' => []
        ],
        [
            'name' => 'Riva',
            'type' => 'monthly',
            'onboard_date' => '2026-08-01',
            'monthly_amount' => 10000,
            'service_name' => 'Social Media Management',
            'projects' => []
        ],
        [
            'name' => 'Skin Lazer & Dental',
            'type' => 'monthly',
            'onboard_date' => '2026-07-23',
            'monthly_amount' => 6500,
            'service_name' => 'Clinic Digital Marketing & SMM',
            'projects' => []
        ],
        [
            'name' => 'Eye Contact',
            'type' => 'monthly',
            'onboard_date' => '2026-07-17',
            'monthly_amount' => 5000,
            'service_name' => 'Opticals Marketing Retainer',
            'projects' => []
        ],
        [
            'name' => 'Angel Croshet',
            'type' => 'monthly',
            'onboard_date' => '2026-03-09',
            'monthly_amount' => 8000,
            'service_name' => 'E-Commerce Retainer Package',
            'projects' => [
                ['name' => 'Custom Website Landing Page', 'amount' => 12000, 'type' => 'One-Time', 'date' => '2026-05-15']
            ]
        ],
        [
            'name' => 'Surya',
            'type' => 'monthly',
            'onboard_date' => '2026-03-15',
            'monthly_amount' => 8000,
            'service_name' => 'Brand Retainer & Advertising',
            'projects' => []
        ],
        [
            'name' => 'MIBO Foods',
            'type' => 'monthly',
            'onboard_date' => '2026-07-08',
            'monthly_amount' => 13333,
            'service_name' => 'Food Brand Digital Marketing',
            'projects' => []
        ],
        [
            'name' => 'The Mountain Front',
            'type' => 'monthly',
            'onboard_date' => '2026-05-10',
            'monthly_amount' => 10000,
            'service_name' => 'Resort SMM & Branding',
            'projects' => []
        ],
        [
            'name' => 'Sooper Buddy',
            'type' => 'monthly',
            'onboard_date' => '2026-03-13',
            'monthly_amount' => 15000,
            'service_name' => 'Digital Retainer Package',
            'projects' => []
        ],
        [
            'name' => 'mithlash jewelers',
            'type' => 'monthly',
            'onboard_date' => '2026-03-31',
            'monthly_amount' => 5000,
            'service_name' => 'Jewelry Brand SMM',
            'projects' => []
        ],
        [
            'name' => 'kimmaya',
            'type' => 'monthly',
            'onboard_date' => '2026-04-14',
            'monthly_amount' => 8000, // CORRECTED TO ₹8,000 (was ₹80,000)
            'service_name' => 'E-Commerce Retainer & Ads Management',
            'projects' => []
        ],
        [
            'name' => 'Dm Cooling',
            'type' => 'monthly',
            'onboard_date' => '2026-03-12',
            'monthly_amount' => 10000,
            'service_name' => 'HVAC Marketing Package',
            'projects' => [
                ['name' => 'Website Development Project', 'amount' => 25000, 'type' => 'One-Time', 'date' => '2026-04-20']
            ]
        ],
        [
            'name' => 'royalmobislo',
            'type' => 'monthly',
            'onboard_date' => '2026-01-01',
            'monthly_amount' => 8000,
            'service_name' => 'Mobile Store Digital Marketing',
            'projects' => []
        ],
        [
            'name' => 'sky pc',
            'type' => 'one_time',
            'onboard_date' => '2026-08-14',
            'monthly_amount' => 0,
            'projects' => [
                ['name' => 'PC Build E-Commerce Website', 'amount' => 14000, 'type' => 'One-Time']
            ]
        ],
        [
            'name' => 'ask',
            'type' => 'one_time',
            'onboard_date' => '2026-06-10',
            'monthly_amount' => 0,
            'projects' => [
                ['name' => 'Branding & Digital Project', 'amount' => 14000, 'type' => 'One-Time']
            ]
        ],
        [
            'name' => 'sahota',
            'type' => 'one_time',
            'onboard_date' => '2026-08-16',
            'monthly_amount' => 0,
            'projects' => [
                ['name' => 'Corporate Portal Development', 'amount' => 12000, 'type' => 'One-Time']
            ]
        ]
    ];

    echo "▶ Seeding Fresh Client Records with Onboard Billing Days...\n";

    $insCust = $pdo->prepare("INSERT INTO customers (name, client_type, status, onboard_date, billing_day, monthly_service_name, monthly_amount, auto_invoice, client_logo, created_at) VALUES (?, ?, 'active', ?, ?, ?, ?, 1, ?, NOW())");
    $insServ = $pdo->prepare("INSERT INTO customer_services (customer_id, project_id, service_name, billing_type, amount, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
    $insProj = $pdo->prepare("INSERT INTO projects (customer_id, project_name, project_type, start_date, agreed_amount, status, description, created_at) VALUES (?, ?, ?, ?, ?, 'Completed', ?, NOW())");

    foreach ($clientsData as $c) {
        $logo = $findLogo($c['name']);
        $servName = $c['service_name'] ?? ($c['monthly_amount'] > 0 ? 'Monthly Service Package' : null);
        $billingDay = (int)date('j', strtotime($c['onboard_date']));

        $insCust->execute([
            $c['name'],
            $c['type'],
            $c['onboard_date'],
            $billingDay,
            $servName,
            $c['monthly_amount'],
            $logo
        ]);
        $customer_id = $pdo->lastInsertId();

        echo "📌 Client #{$customer_id}: {$c['name']} (" . strtoupper($c['type']) . ") | Onboard: {$c['onboard_date']} | Billing Day: {$billingDay}";
        if ($logo) echo " [Logo Linked]";
        echo "\n";

        // Create Monthly Service Package if monthly client
        if ($c['monthly_amount'] > 0 && !empty($servName)) {
            $insProj->execute([
                $customer_id,
                $servName,
                'Monthly',
                $c['onboard_date'],
                $c['monthly_amount'],
                "Monthly Retainer Package"
            ]);
            $p_id = $pdo->lastInsertId();

            $insServ->execute([
                $customer_id,
                $p_id,
                $servName,
                'monthly',
                $c['monthly_amount']
            ]);
        }

        // Create Additional One-Time Projects if any
        $oneTimeProjects = [];
        if (!empty($c['projects'])) {
            foreach ($c['projects'] as $proj) {
                $pDate = $proj['date'] ?? $c['onboard_date'];
                $insProj->execute([
                    $customer_id,
                    $proj['name'],
                    $proj['type'],
                    $pDate,
                    $proj['amount'],
                    "One-Time Project Work"
                ]);
                $proj_id = $pdo->lastInsertId();

                $insServ->execute([
                    $customer_id,
                    $proj_id,
                    $proj['name'],
                    'one_time',
                    $proj['amount']
                ]);

                $oneTimeProjects[] = [
                    'project_id' => $proj_id,
                    'name' => $proj['name'],
                    'amount' => $proj['amount'],
                    'date' => $pDate
                ];
            }
        }

        // -------------------------------------------------------------------
        // INVOICE GENERATION STRICTLY ON ONBOARD BILLING DAY UP TO 31 JULY 2026
        // -------------------------------------------------------------------
        $onboardTime = strtotime($c['onboard_date']);
        $onboardMonth = (int)date('n', $onboardTime);
        $onboardYear = (int)date('Y', $onboardTime);

        // Case A: Monthly Clients -> Generate month by month from onboard date up to July 31, 2026 (or Aug 1 if onboard date is 1 Aug)
        if ($c['type'] === 'monthly' && $c['monthly_amount'] > 0) {
            $currM = $onboardMonth;
            $currY = $onboardYear;

            while (true) {
                $monthName = date('F', mktime(0, 0, 0, $currM, 1, $currY));
                $daysInM = (int)date('t', mktime(0, 0, 0, $currM, 1, $currY));
                $actualDay = min($billingDay, $daysInM);
                $startDate = sprintf('%04d-%02d-%02d', $currY, $currM, $actualDay);
                $invDate   = date('Y-m-d', strtotime('+1 month', strtotime($startDate)));

                // Stop condition: If invoice date is after 23 August 2026, stop generating extra future invoices
                if (strtotime($invDate) > strtotime('2026-08-23')) {
                    break;
                }

                // Fetch invoice counter
                $ctrRow = $pdo->query("SELECT last_number FROM invoice_counter WHERE id = 1 FOR UPDATE")->fetch();
                $newNum = ($ctrRow ? (int)$ctrRow['last_number'] : 10000) + 1;
                $pdo->exec("UPDATE invoice_counter SET last_number = {$newNum} WHERE id = 1");
                $invoice_no = 'INV-' . $newNum;

                $dueDate = date('Y-m-d', strtotime("$invDate + 10 days"));
                
                // Attach any one-time project occurring in this month
                $monthExtras = 0;
                $extraItems = [];
                foreach ($oneTimeProjects as $k => $otp) {
                    $otpTime = strtotime($otp['date']);
                    if ((int)date('n', $otpTime) === $currM && (int)date('Y', $otpTime) === $currY) {
                        $monthExtras += $otp['amount'];
                        $extraItems[] = $otp;
                        unset($oneTimeProjects[$k]); // attach once
                    }
                }

                $monthlySubtotal = (float)$c['monthly_amount'];
                $totalInvAmount = $monthlySubtotal + $monthExtras;

                $notes = "Monthly Retainer Service (" . date('d M Y', strtotime($startDate)) . " - " . date('d M Y', strtotime($invDate)) . ")";

                // Create Invoice with completion date (End of 1-Month Service Period)
                $insInv = $pdo->prepare("INSERT INTO invoices (invoice_no, customer_id, invoice_date, due_date, notes, taxable_value, total_amount, amount_due, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW())");
                $insInv->execute([$invoice_no, $customer_id, $invDate, $dueDate, $notes, $totalInvAmount, $totalInvAmount, $totalInvAmount]);
                $invoice_id = $pdo->lastInsertId();

                $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total) VALUES (?, ?, 1, ?, ?)");
                $insItem->execute([$invoice_id, "{$servName} (" . date('d M Y', strtotime($startDate)) . " - " . date('d M Y', strtotime($invDate)) . ")", $monthlySubtotal, $monthlySubtotal]);

                foreach ($extraItems as $ex) {
                    $insItem->execute([$invoice_id, "Project: " . $ex['name'], $ex['amount'], $ex['amount']]);
                }

                // Record Billing Cycle
                $pdo->prepare("INSERT INTO billing_cycles (customer_id, billing_month, billing_year, invoice_id, status) VALUES (?, ?, ?, ?, 'generated')")
                    ->execute([$customer_id, $currM, $currY, $invoice_id]);

                // Post to Ledger
                post_invoice_to_ledger($invoice_id);

                // RULE: If invoice completion date is on or before 23 August 2026, MARK AS PAID!
                if (strtotime($invDate) <= strtotime('2026-08-23')) {
                    $payDate = $invDate;

                    record_client_payment(
                        $customer_id,
                        $totalInvAmount,
                        $payDate,
                        'Bank Transfer',
                        'TXN-AUTO-' . rand(100000, 999999),
                        'Settled for ' . $notes,
                        'Auto-settled payment',
                        [['invoice_id' => $invoice_id, 'amount' => $totalInvAmount]]
                    );
                    echo "   └─ Invoice {$invoice_no} Date: {$invDate} (₹" . money($totalInvAmount) . ") -> [PAID ON {$payDate}]\n";
                } else {
                    echo "   └─ Invoice {$invoice_no} Date: {$invDate} (₹" . money($totalInvAmount) . ") -> [PENDING/SENT]\n";
                }

                // Increment month
                $currM++;
                if ($currM > 12) {
                    $currM = 1;
                    $currY++;
                }

                // Safety break if we pass August 2026
                if ($currY > 2026 || ($currY === 2026 && $currM > 8)) break;
            }
        } else {
            // Case B: One-Time / Project Clients -> Create Invoice for each project
            foreach ($oneTimeProjects as $otp) {
                $pDate = $otp['date'];

                $ctrRow = $pdo->query("SELECT last_number FROM invoice_counter WHERE id = 1 FOR UPDATE")->fetch();
                $newNum = ($ctrRow ? (int)$ctrRow['last_number'] : 10000) + 1;
                $pdo->exec("UPDATE invoice_counter SET last_number = {$newNum} WHERE id = 1");
                $invoice_no = 'INV-' . $newNum;

                $dueDate = date('Y-m-d', strtotime("$pDate + 10 days"));
                $totAmt = (float)$otp['amount'];

                $insInv = $pdo->prepare("INSERT INTO invoices (invoice_no, customer_id, invoice_date, due_date, taxable_value, total_amount, amount_due, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', NOW())");
                $insInv->execute([$invoice_no, $customer_id, $pDate, $dueDate, $totAmt, $totAmt, $totAmt]);
                $invoice_id = $pdo->lastInsertId();

                $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total) VALUES (?, ?, 1, ?, ?)");
                $insItem->execute([$invoice_id, "Project: " . $otp['name'], $totAmt, $totAmt]);

                post_invoice_to_ledger($invoice_id);

                // RULE: If project date is on or before 31 July 2026, MARK AS PAID!
                if (strtotime($pDate) <= strtotime('2026-07-31')) {
                    $payDate = date('Y-m-d', strtotime("$pDate + 2 days"));
                    if (strtotime($payDate) > strtotime('2026-07-31')) $payDate = '2026-07-31';

                    record_client_payment(
                        $customer_id,
                        $totAmt,
                        $payDate,
                        'Bank Transfer',
                        'TXN-AUTO-' . rand(100000, 999999),
                        'Settled Project Payment',
                        'Auto-settled 31 July payment',
                        [['invoice_id' => $invoice_id, 'amount' => $totAmt]]
                    );
                    echo "   └─ Project Invoice {$invoice_no} Date: {$pDate} ({$otp['name']}) (₹" . money($totAmt) . ") -> [PAID ON {$payDate}]\n";
                } else {
                    echo "   └─ Project Invoice {$invoice_no} Date: {$pDate} ({$otp['name']}) (₹" . money($totAmt) . ") -> [PENDING/SENT]\n";
                }
            }
        }
        echo "\n";
    }

    echo "====================================================\n";
    echo "✔ EXACT BILLING DAY RE-INITIALIZATION COMPLETE!\n";
    echo "====================================================\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
