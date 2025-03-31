<?php
require_once 'login.php';

if (!isset($_GET['job_id'])) {
    die("No job ID provided.");
}

$job_id = $_GET['job_id'];
$is_subset = isset($_GET['subset']) ? 1 : 0;

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get job details and sequences
    $stmt = $pdo->prepare("SELECT protein_family, taxonomic_group, 
                          COALESCE(subset_sequences, sequences) AS sequences
                          FROM searches WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $row = $stmt->fetch();
    
    if (!$row) {
        die("No job found.");
    }

    // Check if motif analysis already exists
    $analysis_stmt = $pdo->prepare("SELECT * FROM motif_analyses 
                                   WHERE job_id = ? AND is_subset = ?");
    $analysis_stmt->execute([$job_id, $is_subset]);
    $analysis = $analysis_stmt->fetch();
    
    if (!$analysis) {
        // Create temporary FASTA file for processing
        $fasta_content = $row['sequences'];
        $temp_fasta = tempnam(sys_get_temp_dir(), 'fasta_');
        file_put_contents($temp_fasta, $fasta_content);
        
        // Create results directory
        $results_dir = sys_get_temp_dir() . "/{$job_id}_motifs_" . ($is_subset ? "subset" : "full");
        if (!is_dir($results_dir)) {
            mkdir($results_dir, 0777, true);
        }
        
        // Run motif analysis
        $output = shell_exec("/bin/bash run_motifs.sh " . 
                           escapeshellarg($temp_fasta) . " " . 
                           escapeshellarg($results_dir) . " 2>&1");
        
        // Process and store results in database
        if (file_exists("$results_dir/patmatmotifs_results.txt")) {
            $motif_results = file_get_contents("$results_dir/patmatmotifs_results.txt");
            
            // First create the analysis record
            $insert_stmt = $pdo->prepare("INSERT INTO motif_analyses 
                                        (job_id, is_subset, raw_results, created_at)
                                        VALUES (?, ?, ?, NOW())");
            $insert_stmt->execute([$job_id, $is_subset, $motif_results]);
            $analysis_id = $pdo->lastInsertId();
            
            // Parse and store individual motifs
            $pattern = '/Sequence: (.+?)\s+from: (\d+)\s+to: (\d+).*?HitCount: (\d+).*?Full: (.+?)Prune: (.+?)Data_file: (.+?)(?=Sequence|\Z)/ms';
            preg_match_all($pattern, $motif_results, $sequence_blocks, PREG_SET_ORDER);

            foreach ($sequence_blocks as $block) {
                $sequence_id = $block[1];
                $start_pos = $block[2];
                $end_pos = $block[3];
                $hit_count = $block[4];

                // Parse individual motifs
                $motif_pattern = '/Length = (\d+)\s+Start = position (\d+) of sequence\s+End = position (\d+) of sequence\s+Motif = (.+?)\s+([A-Za-z\s]+)\n\s+([| \n]+)/';
                preg_match_all($motif_pattern, $block[0], $motifs, PREG_SET_ORDER);

                foreach ($motifs as $motif) {
                    $length = $motif[1];
                    $motif_start = $motif[2];
                    $motif_end = $motif[3];
                    $motif_name = $motif[4];
                    $sequence_part = trim($motif[5]);
                    $visual_guide = trim($motif[6]);

                    // Generate enhanced visual guide
                    $enhanced_guide = '';
                    $chars = str_split($visual_guide);
                    $pos = $motif_start;
                    foreach ($chars as $char) {
                        if ($char === '|') {
                            $enhanced_guide .= '|' . $pos;
                            $pos = $motif_end;
                        } else {
                            $enhanced_guide .= $char;
                        }
                    }

                    // Store motif
                    $stmt = $pdo->prepare("INSERT INTO motif_results
                        (analysis_id, job_id, sequence_id, motif_name, length, 
                         start_pos, end_pos, sequence_part, visual_guide, enhanced_guide)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $analysis_id, $job_id, $sequence_id, $motif_name, $length,
                        $motif_start, $motif_end, $sequence_part, $visual_guide, $enhanced_guide
                    ]);
                }
            }
        }
        
        // Clean up
        unlink($temp_fasta);
        
        // Refresh analysis data
        $analysis_stmt->execute([$job_id, $is_subset]);
        $analysis = $analysis_stmt->fetch();
    }
    
    // Handle report generation
    if (isset($_GET['generate_report'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="motif_report_' . $job_id . '.txt"');
        
        echo "Motif Analysis Report\n";
        echo "====================\n\n";
        echo "Job ID: $job_id\n";
        echo "Protein Family: " . $row['protein_family'] . "\n";
        echo "Taxonomic Group: " . $row['taxonomic_group'] . "\n";
        echo "Date: " . date('Y-m-d H:i:s') . "\n";
        if ($is_subset) {
            echo "Subset: First " . ($_GET['subset'] ?? '?') . " sequences\n";
        }
        echo "\n";
        
        if (!empty($analysis['raw_results'])) {
            // Get summary info
            preg_match('/Sequence: (.+?)\s+from: (\d+)\s+to: (\d+).*?HitCount: (\d+)/ms', $analysis['raw_results'], $summary);
            
            echo "Summary\n";
            echo "-------\n";
            echo "Sequence: " . ($summary[1] ?? 'N/A') . "\n";
            echo "Sequence Range: " . ($summary[2] ?? 'N/A') . " to " . ($summary[3] ?? 'N/A') . "\n";
            echo "Total Motifs Found: " . ($summary[4] ?? '0') . "\n\n";
            
            // Get detailed motifs
            $motifs = $pdo->prepare("
                SELECT * FROM motif_results 
                WHERE job_id = ? AND analysis_id = ?
                ORDER BY sequence_id, start_pos
            ");
            $motifs->execute([$job_id, $analysis['id']]);
            $motifs = $motifs->fetchAll();
            
            if (!empty($motifs)) {
                $current_seq = null;
                foreach ($motifs as $motif) {
                    if ($current_seq !== $motif['sequence_id']) {
                        $current_seq = $motif['sequence_id'];
                        echo "\nSequence: $current_seq\n";
                        echo str_repeat("-", 50) . "\n";
                    }
                    
                    echo "\nMotif: " . $motif['motif_name'] . "\n";
                    echo "Length: " . $motif['length'] . " aa\n";
                    echo "Positions: " . $motif['start_pos'] . "-" . $motif['end_pos'] . "\n";
                    echo "Sequence: " . $motif['sequence_part'] . "\n";
                    echo "Visualization:\n" . $motif['enhanced_guide'] . "\n";
                }
            } else {
                echo "No motifs found in database.\n";
            }
        } else {
            echo "No motif results were found for this job.\n";
        }
        exit;
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Database schema needed:
/*
CREATE TABLE motif_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(255) NOT NULL,
    is_subset BOOLEAN NOT NULL,
    raw_results LONGTEXT,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (job_id) REFERENCES searches(job_id)
);

CREATE TABLE motif_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_id INT NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    sequence_id VARCHAR(255) NOT NULL,
    motif_name VARCHAR(255) NOT NULL,
    length INT NOT NULL,
    start_pos INT NOT NULL,
    end_pos INT NOT NULL,
    sequence_part TEXT NOT NULL,
    visual_guide TEXT NOT NULL,
    enhanced_guide TEXT NOT NULL,
    FOREIGN KEY (analysis_id) REFERENCES motif_analyses(id),
    FOREIGN KEY (job_id) REFERENCES searches(job_id)
);
*/
?>

<!DOCTYPE html>
<html>
<head>
    <title>Protein Motif Search</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; margin: 0; padding: 20px; color: #333; background-color: #f9f9f9; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .summary-section { background: #eef7ff; padding: 20px; border-radius: 8px; margin-bottom: 30px; border-left: 5px solid #3498db; }
        .summary-row { display: flex; margin-bottom: 10px; }
        .summary-label { font-weight: bold; min-width: 150px; color: #2c3e50; }
        .insights { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 3px solid #7f8c8d; }
        .sequence-block { margin-bottom: 40px; padding-bottom: 20px; }
        .motif-block { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid #3498db; }
        .motif-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        .motif-title { font-size: 1.2em; color: #2980b9; font-weight: 600; }
        .motif-details { color: #7f8c8d; font-size: 0.95em; }
        .motif-visualization { font-family: 'Courier New', monospace; line-height: 1.5; background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .sequence-part { color: #2c3e50; font-weight: bold; white-space: pre; }
        .visual-guide { color: #e74c3c; white-space: pre; }
        .download-btn { display: inline-block; background: #27ae60; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 20px; transition: all 0.3s; border: none; cursor: pointer; font-size: 1em; }
        .no-results { background: #fdecea; color: #e74c3c; padding: 15px; border-radius: 5px; text-align: center; }
        .subset-info { background: #e8f5e9; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Motif Search Results for Job: <?= htmlspecialchars($job_id) ?></h1>

        <?php if ($is_subset): ?>
            <div class="subset-info">
                <strong>Using subset:</strong> First <?= htmlspecialchars($_GET['subset'] ?? '?') ?> sequences
            </div>
        <?php endif; ?>

        <?php if (!empty($analysis)): ?>
            <?php
            // Get summary information
            preg_match('/Sequence: (.+?)\s+from: (\d+)\s+to: (\d+).*?HitCount: (\d+)/ms', $analysis['raw_results'], $summary);
            
            // Get all sequences with motifs
            $sequences = $pdo->prepare("
                SELECT DISTINCT sequence_id 
                FROM motif_results 
                WHERE job_id = ? AND analysis_id = ?
                ORDER BY sequence_id
            ");
            $sequences->execute([$job_id, $analysis['id']]);
            $sequences = $sequences->fetchAll();
            ?>
            
            <div class="summary-section">
                <h2>Analysis Summary</h2>
                <div class="summary-row">
                    <div class="summary-label">Protein Sequence:</div>
                    <div><?= htmlspecialchars($summary[1] ?? 'N/A') ?></div>
                </div>
                <div class="summary-row">
                    <div class="summary-label">Sequence Range:</div>
                    <div><?= htmlspecialchars($summary[2] ?? 'N/A') ?> to <?= htmlspecialchars($summary[3] ?? 'N/A') ?></div>
                </div>
                <div class="summary-row">
                    <div class="summary-label">Total Motifs Found:</div>
                    <div><?= htmlspecialchars($summary[4] ?? '0') ?></div>
                </div>
                <div class="summary-row">
                    <div class="summary-label">Analysis Date:</div>
                    <div><?= $analysis['created_at'] ?></div>
                </div>
            </div>

            <?php if (!empty($sequences)): ?>
                <?php foreach ($sequences as $seq): ?>
                    <?php
                    $motifs = $pdo->prepare("
                        SELECT * FROM motif_results
                        WHERE job_id = ? AND analysis_id = ? AND sequence_id = ?
                        ORDER BY start_pos
                    ");
                    $motifs->execute([$job_id, $analysis['id'], $seq['sequence_id']]);
                    $motifs = $motifs->fetchAll();
                    ?>
                    
                    <div class="sequence-block">
                        <h2>Detailed Motif Results for Sequence: <?= htmlspecialchars($seq['sequence_id']) ?></h2>
                        <p>Showing <?= count($motifs) ?> detected motifs:</p>

                        <?php foreach ($motifs as $motif): ?>
                            <div class="motif-block">
                                <div class="motif-header">
                                    <span class="motif-title"><?= htmlspecialchars($motif['motif_name']) ?> Motif</span>
                                    <span class="motif-details">
                                        Length: <?= $motif['length'] ?> aa |
                                        Positions: <?= $motif['start_pos'] ?>-<?= $motif['end_pos'] ?>
                                    </span>
                                </div>
                                <div class="motif-visualization">
                                    <div class="sequence-part"><?= htmlspecialchars($motif['sequence_part']) ?></div>
                                    <div class="visual-guide"><?= htmlspecialchars($motif['enhanced_guide']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <a href="?job_id=<?= $job_id ?>&subset=<?= $is_subset ? $_GET['subset'] : '' ?>&generate_report=1" class="download-btn">
                    Generate TXT Report
                </a>
            <?php else: ?>
                <div class="no-results">
                    No motifs were found in the database for this analysis.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-results">
                No motif analysis was found for this job. This could mean:
                <ul>
                    <li>The protein sequence doesn't contain any known PROSITE motifs</li>
                    <li>The search parameters were too restrictive</li>
                    <li>There was an error during motif scanning</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
