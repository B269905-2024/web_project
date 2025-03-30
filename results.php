<?php
session_start();
require_once 'login.php';

// Redirect if no job_id provided
if (!isset($_GET['job_id'])) {
    header("Location: home.php");
    exit;
}

$job_id = $_GET['job_id'];

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get current job data
    $stmt = $pdo->prepare("SELECT * FROM searches WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $row = $stmt->fetch();
    
    if (!$row) {
        die("No job found with ID: " . htmlspecialchars($job_id));
    }
    
    // Handle actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['create_subset'])) {
            // Create subset from current dataset (could be full or existing subset)
            $current_content = $row['subset_sequences'] ?: $row['sequences'];
            $num_sequences = substr_count($current_content, '>');
            $num_requested = (int)$_POST['num_sequences'];
            
            if ($num_requested > 0 && $num_requested <= $num_sequences) {
                // Extract subset
                $subset_content = '';
                $count = 0;
                $lines = explode("\n", $current_content);
                
                foreach ($lines as $line) {
                    if (str_starts_with(trim($line), '>')) {
                        $count++;
                        if ($count > $num_requested) break;
                    }
                    if ($count > 0) {
                        $subset_content .= $line . "\n";
                    }
                }
                
                // Update database with new subset
                $update_stmt = $pdo->prepare("UPDATE searches SET subset_sequences = ?, subset_size = ? WHERE job_id = ?");
                $update_stmt->execute([trim($subset_content), $num_requested, $job_id]);
                
                // Refresh data
                $stmt->execute([$job_id]);
                $row = $stmt->fetch();
                $success = "Created subset with $num_requested sequences";
            } else {
                $error = "Invalid number of sequences (1-$num_sequences)";
            }
        }
    } elseif (isset($_GET['use_full'])) {
        // Switch back to full dataset
        $update_stmt = $pdo->prepare("UPDATE searches SET subset_sequences = NULL, subset_size = NULL WHERE job_id = ?");
        $update_stmt->execute([$job_id]);
        
        // Refresh data
        $stmt->execute([$job_id]);
        $row = $stmt->fetch();
        $success = "Now using full dataset";
    }
    
    // Determine current view
    $is_subset = !empty($row['subset_sequences']);
    $display_content = $is_subset ? $row['subset_sequences'] : $row['sequences'];
    $display_count = $is_subset ? $row['subset_size'] : substr_count($row['sequences'], '>');
    $total_sequences = substr_count($row['sequences'], '>');

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Protein Job Results</title>
</head>
<body>
    <h1>Results for Job: <?= htmlspecialchars($job_id) ?></h1>
    
    <p><strong>Protein Family:</strong> <?= htmlspecialchars($row['protein_family']) ?></p>
    <p><strong>Taxonomic Group:</strong> <?= htmlspecialchars($row['taxonomic_group']) ?></p>
    <p><strong>Total Sequences:</strong> <?= $total_sequences ?></p>
    <p><strong>Currently Viewing:</strong> <?= $display_count ?> sequences (<?= $is_subset ? 'Subset' : 'Full Set' ?>)</p>

    <?php if (isset($error)): ?>
        <p style="color: red;"><?= htmlspecialchars($error) ?></p>
    <?php elseif (isset($success)): ?>
        <p style="color: green;"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <h2>Dataset Options</h2>
    
    <form method="post">
        <label>
            Create subset (1-<?= $is_subset ? $row['subset_size'] : $total_sequences ?> sequences):
            <input type="number" name="num_sequences" 
                   min="1" max="<?= $is_subset ? $row['subset_size'] : $total_sequences ?>" 
                   value="<?= min(10, $is_subset ? $row['subset_size'] : $total_sequences) ?>">
        </label>
        <input type="submit" name="create_subset" value="Create Subset">
    </form>
    
    <?php if ($is_subset): ?>
        <p><a href="?job_id=<?= htmlspecialchars($job_id) ?>&use_full=true">Use Full Dataset (<?= $total_sequences ?> sequences)</a></p>
    <?php endif; ?>

    <h2>FASTA Sequences</h2>
    <pre><?= htmlspecialchars($display_content) ?></pre>

    <h2>Analysis Options</h2>
    <p>All analyses will use the <?= $is_subset ? 'subset' : 'full dataset' ?> shown above.</p>
    
    <ul>
        <li><a href="conservation.php?job_id=<?= htmlspecialchars($job_id) ?>">Conservation Analysis</a></li>
        <li><a href="motifs.php?job_id=<?= htmlspecialchars($job_id) ?>">Motif Analysis</a></li>
        <li><a href="content.php?job_id=<?= htmlspecialchars($job_id) ?>">Content Analysis</a></li>
    </ul>

    <p><a href="home.php">Back to Search</a></p>
</body>
</html>
