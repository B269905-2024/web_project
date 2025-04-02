<?php
// Start session only if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enhanced error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_motifs_error.log');

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.*, u.user_id FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch();

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    // Check for existing motif job
    $stmt = $pdo->prepare("SELECT * FROM motif_jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $motif_job = $stmt->fetch();

    if (!$motif_job) {
        // Create new motif job
        $stmt = $pdo->prepare("INSERT INTO motif_jobs (job_id) VALUES (?)");
        $stmt->execute([$job_id]);
        $motif_id = $pdo->lastInsertId();

        // Get sequences from database
        $stmt = $pdo->prepare("SELECT sequence_id, ncbi_id, sequence FROM sequences WHERE job_id = ?");
        $stmt->execute([$job_id]);
        $sequences = $stmt->fetchAll();
        $sequence_count = count($sequences);

        if (empty($sequences)) {
            $stmt = $pdo->prepare("DELETE FROM motif_jobs WHERE motif_id = ?");
            $stmt->execute([$motif_id]);
            header("Location: results.php?job_id=$job_id");
            exit();
        }

        // Initialize debug info
        $_SESSION['motif_debug'] = [
            'sequences_count' => $sequence_count,
            'processed_sequences' => [],
            'fasta_content' => ''
        ];

        $output_dir = sys_get_temp_dir();
        $motif_count = 0;
        
        // Create combined FASTA content
        $fasta_content = '';
        foreach ($sequences as $seq) {
            $fasta_content .= ">{$seq['ncbi_id']}\n{$seq['sequence']}\n";
            // Store each processed sequence for debugging
            $_SESSION['motif_debug']['processed_sequences'][] = [
                'ncbi_id' => $seq['ncbi_id'],
                'sequence' => $seq['sequence']
            ];
        }
        $_SESSION['motif_debug']['fasta_content'] = $fasta_content;

        // Create temporary FASTA file with proper permissions
        $fasta_file = tempnam(sys_get_temp_dir(), 'motif_');
        file_put_contents($fasta_file, $fasta_content);
        chmod($fasta_file, 0644);

        // Build command with explicit output directory
        $command = "/usr/bin/patmatmotifs -sequence $fasta_file -full Y -outfile $output_dir/patmatmotifs_output.txt -auto 2>&1";

        // Execute command
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);
        $output_text = implode("\n", $output);

        // Store complete debug info
        $_SESSION['motif_debug']['command'] = $command;
        $_SESSION['motif_debug']['output'] = $output_text;
        $_SESSION['motif_debug']['return_code'] = $return_var;

        // Clean up
        if (file_exists($fasta_file)) {
            unlink($fasta_file);
        }

        if ($return_var !== 0) {
            throw new Exception("patmatmotifs failed with code $return_var");
        }

        // Parse results if successful
        $current_seq = '';
        $motif_count = 0;
        
        foreach ($output as $line) {
            if (preg_match('/^Sequence: (.+?)\s+from:/', $line, $matches)) {
                $current_seq = $matches[1];
            }
            elseif (preg_match('/^Hit: (\S+)\s+(PS\d+);(.*?);(\d+)-(\d+); Score: ([\d.]+); P-value: ([\d.]+)/', $line, $matches)) {
                $stmt = $pdo->prepare("INSERT INTO motif_results 
                    (motif_id, sequence_id, motif_name, motif_id_code, description, start_pos, end_pos, score, p_value)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $motif_id,
                    $current_seq,
                    trim($matches[1]),
                    $matches[2],
                    trim($matches[3]),
                    $matches[4],
                    $matches[5],
                    $matches[6],
                    $matches[7]
                ]);
                $motif_count++;
            }
        }
        
        // Generate report
        $report_text = "Motif Analysis Report\n===========================\n\n";
        $report_text .= "Job ID: $job_id\n";
        $report_text .= "Search Term: " . $job['search_term'] . "\n";
        $report_text .= "Taxonomic Group: " . $job['taxon'] . "\n";
        $report_text .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
        $report_text .= "Sequences analyzed: $sequence_count\n";
        $report_text .= "Total motifs found: $motif_count\n";
        
        $stmt = $pdo->prepare("INSERT INTO motif_reports (motif_id, report_text) VALUES (?, ?)");
        $stmt->execute([$motif_id, $report_text]);
    }

    // Get analysis results
    $stmt = $pdo->prepare("
        SELECT mr.*, s.ncbi_id 
        FROM motif_results mr
        JOIN sequences s ON mr.sequence_id = s.sequence_id
        WHERE mr.motif_id = (SELECT motif_id FROM motif_jobs WHERE job_id = ?)
        ORDER BY mr.sequence_id, mr.start_pos
    ");
    $stmt->execute([$job_id]);
    $results = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Motif analysis error: " . $e->getMessage());
    $_SESSION['error'] = "Motif analysis failed: " . $e->getMessage();
    header("Location: results.php?job_id=$job_id");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Motif Analysis</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; margin: 0; padding: 20px; color: #333; background-color: #f9f9f9; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .debug-info { background: #f5f5f5; padding: 20px; border-radius: 8px; margin-top: 20px; }
        pre { white-space: pre-wrap; background: #fff; padding: 10px; border-radius: 4px; border: 1px solid #ddd; overflow-x: auto; }
        .file-info { margin-bottom: 15px; }
        .file-info h4 { margin-bottom: 5px; }
        .nav-links { margin-bottom: 20px; }
        .nav-links a { margin-right: 15px; text-decoration: none; color: #3498db; }
        .no-results { background: #fdecea; padding: 20px; border-radius: 8px; }
        .full-fasta { max-height: 500px; overflow-y: auto; }
        .sequence-block { margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
        .error { color: #e74c3c; background: #fdecea; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="home.php">New Search</a>
            <a href="past.php">Past Searches</a>
            <a href="results.php?job_id=<?= $job_id ?>">Back to Results</a>
        </div>

        <h1>Motif Analysis: <?= htmlspecialchars($job['search_term'] ?? 'Unknown') ?></h1>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="summary-section">
            <h2>Analysis Summary</h2>
            <p><strong>Job ID:</strong> <?= $job_id ?></p>
            <p><strong>Taxonomic Group:</strong> <?= htmlspecialchars($job['taxon'] ?? 'Unknown') ?></p>
            <p><strong>Sequences Analyzed:</strong> <?= $_SESSION['motif_debug']['sequences_count'] ?? '0' ?></p>
            <p><strong>Total Motifs Found:</strong> <?= count($results ?? []) ?></p>
        </div>

        <div class="sequences-analyzed">
            <h2>Sequences Analyzed</h2>
            <?php if (!empty($_SESSION['motif_debug']['processed_sequences'])): ?>
                <?php foreach ($_SESSION['motif_debug']['processed_sequences'] as $seq): ?>
                    <div class="sequence-block">
                        <h3><?= htmlspecialchars($seq['ncbi_id']) ?></h3>
                        <pre><?= htmlspecialchars($seq['sequence']) ?></pre>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No sequence information available.</p>
            <?php endif; ?>
        </div>

        <?php if (!empty($results)): ?>
            <h2>Motif Results</h2>
            <!-- Your existing results display code here -->
        <?php else: ?>
            <div class="no-results">
                <h3>No Motifs Found</h3>
                <?php if (isset($_SESSION['motif_debug'])): ?>
                    <div class="debug-info">
                        <h4>Debug Information</h4>
                        <p><strong>Command Executed:</strong></p>
                        <pre><?= htmlspecialchars($_SESSION['motif_debug']['command'] ?? '') ?></pre>
                        <p><strong>Output:</strong></p>
                        <pre><?= htmlspecialchars($_SESSION['motif_debug']['output'] ?? '') ?></pre>
                        <p><strong>Return Code:</strong> <?= $_SESSION['motif_debug']['return_code'] ?? '' ?></p>
                        <h4>Complete FASTA Content:</h4>
                        <pre><?= htmlspecialchars($_SESSION['motif_debug']['fasta_content'] ?? '') ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
// Clear debug info after displaying
unset($_SESSION['motif_debug']);
?>
