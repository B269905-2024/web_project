<?php
require_once 'login.php';

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all past jobs sorted by most recent
    $stmt = $pdo->prepare("SELECT * FROM searches ORDER BY created_at DESC");
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Past Jobs</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #2196F3;
            padding-bottom: 10px;
        }
        .job-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .job-table th, .job-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .job-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .job-table tr:hover {
            background-color: #f5f5f5;
        }
        .status-pending {
            color: #FF9800;
        }
        .status-completed {
            color: #4CAF50;
        }
        .status-failed {
            color: #F44336;
        }
        .view-btn {
            background-color: #2196F3;
            color: white;
            padding: 6px 12px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .view-btn:hover {
            background-color: #0b7dda;
        }
        .back-btn {
            display: inline-block;
            margin-top: 20px;
            background-color: #555;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 4px;
        }
        .back-btn:hover {
            background-color: #333;
        }
    </style>
</head>
<body>
    <h1>Past Jobs</h1>
    
    <?php if (count($jobs) > 0): ?>
        <table class="job-table">
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>Protein Family</th>
                    <th>Taxonomic Group</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= htmlspecialchars($job['job_id']) ?></td>
                        <td><?= htmlspecialchars($job['protein_family']) ?></td>
                        <td><?= htmlspecialchars($job['taxonomic_group']) ?></td>
                        <td class="status-<?= htmlspecialchars($job['status']) ?>">
                            <?= ucfirst(htmlspecialchars($job['status'])) ?>
                        </td>
                        <td><?= htmlspecialchars($job['created_at']) ?></td>
                        <td>
                            <a href="results.php?job_id=<?= htmlspecialchars($job['job_id']) ?>" class="view-btn">
                                View Results
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No past jobs found.</p>
    <?php endif; ?>
    
    <a href="home.php" class="back-btn">Back to Search</a>
</body>
</html>
