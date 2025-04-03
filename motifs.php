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
    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
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

    // Get sequences from database for count
    $stmt = $pdo->prepare("SELECT sequence_id, ncbi_id, sequence FROM sequences WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $sequences = $stmt->fetchAll();
    $sequence_count = count($sequences);

    $motifs = [];
    $output_content = '';
    $output_file = '';

    if (!$motif_job && !empty($sequences)) {
        // Create new motif job
        $stmt = $pdo->prepare("INSERT INTO motif_jobs (job_id) VALUES (?)");
        $stmt->execute([$job_id]);
        $motif_id = $pdo->lastInsertId();

        $output_dir = sys_get_temp_dir();
        
        // Create combined FASTA content
        $fasta_content = '';
        foreach ($sequences as $seq) {
            $fasta_content .= ">{$seq['ncbi_id']}\n{$seq['sequence']}\n";
        }

        // Create temporary FASTA file
        $fasta_file = tempnam($output_dir, 'motif_');
        file_put_contents($fasta_file, $fasta_content);
        chmod($fasta_file, 0644);

        // Build command
        $output_file = "$output_dir/patmatmotifs_output_$job_id.txt";
        $command = "/usr/bin/patmatmotifs -sequence $fasta_file -full Y -outfile $output_file -auto 2>&1";

        // Execute command
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);

        // Clean up
        if (file_exists($fasta_file)) {
            unlink($fasta_file);
        }

        if ($return_var !== 0) {
            throw new Exception("patmatmotifs failed with code $return_var");
        }

        // Store output content
        if (file_exists($output_file)) {
            $output_content = file_get_contents($output_file);
            
            // Parse the output file to extract motifs
            $lines = explode("\n", $output_content);
            $current_sequence = '';
            $current_motif = [];
            
            foreach ($lines as $line) {
                if (preg_match('/^Sequence: (.+?)\s+from:/', $line, $matches)) {
                    $current_sequence = $matches[1];
                } 
                elseif (strpos($line, 'Motif = ') === 0) {
                    $current_motif['motif_name'] = trim(substr($line, 8));
                }
                elseif (strpos($line, 'Start = position ') === 0) {
                    $current_motif['start_pos'] = (int)substr($line, 17, strpos($line, ' of sequence') - 17);
                }
                elseif (strpos($line, 'End = position ') === 0) {
                    $current_motif['end_pos'] = (int)substr($line, 15, strpos($line, ' of sequence') - 15);
                    
                    // We have a complete motif record
                    if ($current_sequence && !empty($current_motif)) {
                        // Find sequence_id for this ncbi_id
                        $sequence_id = null;
                        foreach ($sequences as $seq) {
                            if ($seq['ncbi_id'] === $current_sequence) {
                                $sequence_id = $seq['sequence_id'];
                                break;
                            }
                        }
                        
                        if ($sequence_id) {
                            $motifs[] = [
                                'sequence_id' => $sequence_id,
                                'ncbi_id' => $current_sequence,
                                'motif_name' => $current_motif['motif_name'],
                                'start_pos' => $current_motif['start_pos'],
                                'end_pos' => $current_motif['end_pos']
                            ];
                            
                            // Store in database
                            $stmt = $pdo->prepare("INSERT INTO motif_results 
                                (motif_id, sequence_id, motif_name, start_pos, end_pos)
                                VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $motif_id,
                                $sequence_id,
                                $current_motif['motif_name'],
                                $current_motif['start_pos'],
                                $current_motif['end_pos']
                            ]);
                        }
                        
                        $current_motif = [];
                    }
                }
            }
        }
        
        // Generate report
        $report_text = "Motif Analysis Report\n===========================\n\n";
        $report_text .= "Job ID: $job_id\n";
        $report_text .= "Search Term: " . $job['search_term'] . "\n";
        $report_text .= "Taxonomic Group: " . $job['taxon'] . "\n";
        $report_text .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
        $report_text .= "Sequences analyzed: $sequence_count\n";
        $report_text .= "Total motifs found: " . count($motifs) . "\n";
        
        $stmt = $pdo->prepare("INSERT INTO motif_reports (motif_id, report_text) VALUES (?, ?)");
        $stmt->execute([$motif_id, $report_text]);
        
        // Update jobs table with motif info
        $stmt = $pdo->prepare("UPDATE jobs SET motif_results = ?, motif_report = ? WHERE job_id = ?");
        $stmt->execute([json_encode($motifs), $report_text, $job_id]);
    } elseif ($motif_job) {
        // Get existing results from database
        $stmt = $pdo->prepare("
            SELECT mr.*, s.ncbi_id, s.sequence 
            FROM motif_results mr
            JOIN sequences s ON mr.sequence_id = s.sequence_id
            WHERE mr.motif_id = ?
            ORDER BY s.ncbi_id, mr.start_pos
        ");
        $stmt->execute([$motif_job['motif_id']]);
        $motifs = $stmt->fetchAll();
    }

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
        .nav-links { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .nav-links a { margin-right: 15px; text-decoration: none; color: #3498db; }
        .error { color: #e74c3c; background: #fdecea; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:hover { background-color: #f5f5f5; }
        .motif-details { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-top: 10px; }
        .motif-sequence { font-family: monospace; background: white; padding: 5px; overflow-x: auto; }
        .motif-markers { font-family: monospace; letter-spacing: 8px; padding-left: 5px; color: #e74c3c; }
        .sequence-selector { margin: 20px 0; }
        .btn { background: #3498db; color: white; border: none; padding: 8px 15px; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #2980b9; }
        .btn-download { background: #2ecc71; margin-left: 10px; }
        .btn-download:hover { background: #27ae60; }
        .no-results { background: #f5f5f5; padding: 20px; border-radius: 5px; text-align: center; }
        .motif-hit { font-weight: bold; color: #2ecc71; background-color: #e8f8f0; padding: 2px 4px; border-radius: 3px; }
        .motif-visualization { margin-top: 10px; }
        .sequence-segment { font-family: monospace; white-space: pre-wrap; margin-bottom: 5px; }
        .motif-summary-card { background: #f8f9fa; border-radius: 5px; padding: 15px; margin-bottom: 20px; }
        .motif-summary-card h3 { margin-top: 0; color: #3498db; }
        .motif-stats { display: flex; gap: 20px; margin-top: 10px; }
        .motif-stat { background: white; padding: 10px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); flex: 1; }
        .motif-stat h4 { margin: 0 0 5px 0; color: #7f8c8d; }
        .motif-stat p { margin: 0; font-size: 1.2em; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <div>
                <a href="home.php">New Search</a>
                <a href="past.php">Past Searches</a>
                <a href="results.php?job_id=<?= $job_id ?>">Back to Results</a>
            </div>
            <?php if (!empty($motifs)): ?>
                <a href="download_motifs.php?job_id=<?= $job_id ?>" class="btn btn-download">Download Results</a>
            <?php endif; ?>
        </div>

        <h1>Motif Analysis: <?= htmlspecialchars($job['search_term'] ?? 'Unknown') ?></h1>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="motif-summary-card">
            <h2>Analysis Summary</h2>
            <div class="motif-stats">
                <div class="motif-stat">
                    <h4>Job ID</h4>
                    <p><?= $job_id ?></p>
                </div>
                <div class="motif-stat">
                    <h4>Taxonomic Group</h4>
                    <p><?= htmlspecialchars($job['taxon'] ?? 'Unknown') ?></p>
                </div>
                <div class="motif-stat">
                    <h4>Sequences Analyzed</h4>
                    <p><?= $sequence_count ?></p>
                </div>
                <div class="motif-stat">
                    <h4>Motifs Found</h4>
                    <p class="motif-hit"><?= count($motifs) ?></p>
                </div>
            </div>
        </div>

        <?php if (!empty($motifs)): ?>
            <div class="sequence-selector">
                <label for="sequence-select"><strong>View motifs for sequence:</strong></label>
                <select id="sequence-select" onchange="filterMotifs(this.value)">
                    <option value="">All Sequences</option>
                    <?php 
                    $unique_sequences = array_unique(array_column($motifs, 'ncbi_id'));
                    foreach ($unique_sequences as $seq): ?>
                        <option value="<?= htmlspecialchars($seq) ?>"><?= htmlspecialchars($seq) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <table id="motifs-table">
                <thead>
                    <tr>
                        <th>Sequence ID</th>
                        <th>Motif Name</th>
                        <th>Positions</th>
                        <th>Sequence Segment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($motifs as $index => $motif): 
                        $full_sequence = $motif['sequence'];
                        $motif_length = $motif['end_pos'] - $motif['start_pos'] + 1;
                        $segment_start = max(0, $motif['start_pos'] - 10);
                        $segment_end = min(strlen($full_sequence), $motif['end_pos'] + 10);
                        $segment = substr($full_sequence, $segment_start, $segment_end - $segment_start);
                        $motif_in_segment_start = $motif['start_pos'] - $segment_start - 1;
                        
                        // Create markers string
                        $markers = str_repeat(' ', $motif_in_segment_start) . str_repeat('^', $motif_length);
                        ?>
                        <tr class="motif-row" data-sequence="<?= htmlspecialchars($motif['ncbi_id']) ?>">
                            <td><?= htmlspecialchars($motif['ncbi_id']) ?></td>
                            <td><span class="motif-hit"><?= htmlspecialchars($motif['motif_name']) ?></span></td>
                            <td><?= $motif['start_pos'] ?>-<?= $motif['end_pos'] ?></td>
                            <td>
                                <div class="motif-visualization">
                                    <div class="sequence-segment">
                                        <?= 
                                            htmlspecialchars(substr($segment, 0, $motif_in_segment_start)) .
                                            '<span class="motif-hit">' . 
                                            htmlspecialchars(substr($segment, $motif_in_segment_start, $motif_length)) . 
                                            '</span>' .
                                            htmlspecialchars(substr($segment, $motif_in_segment_start + $motif_length))
                                        ?>
                                    </div>
                                    <div class="motif-markers"><?= $markers ?></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-results">
                <h3>No Motifs Found</h3>
                <p>The analysis completed successfully but no known motifs were detected in the sequences.</p>
                
                <?php if (!empty($output_content)): ?>
                    <div class="output-preview">
                        <h4>Analysis Output</h4>
                        <pre><?= htmlspecialchars($output_content) ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterMotifs(sequenceId) {
            const rows = document.querySelectorAll('.motif-row');
            rows.forEach(row => {
                if (!sequenceId || row.dataset.sequence === sequenceId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Initialize - show all rows initially
        document.addEventListener('DOMContentLoaded', function() {
            filterMotifs('');
        });
    </script>
</body>
</html>
