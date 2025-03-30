<?php
require_once 'login.php';

if (!isset($_GET['job_id'])) {
    die("No job ID provided.");
}

$job_id = $_GET['job_id'];
$window_size = isset($_GET['window_size']) ? (int)$_GET['window_size'] : 4;
$window_size = max(1, min(20, $window_size));

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get job details and sequences - fixed query syntax
    $stmt = $pdo->prepare("SELECT protein_family, taxonomic_group, 
                          COALESCE(subset_sequences, sequences) AS sequences,
                          CASE WHEN subset_sequences IS NULL THEN 0 ELSE 1 END AS is_subset,
                          (LENGTH(COALESCE(subset_sequences, sequences)) - 
                           LENGTH(REPLACE(COALESCE(subset_sequences, sequences), '>', ''))) AS seq_count
                          FROM searches WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $row = $stmt->fetch();
    
    if (!$row) {
        die("No job found.");
    }

    $protein_family = $row['protein_family'];
    $taxonomic_group = $row['taxonomic_group'];
    $is_subset = $row['is_subset'];
    $seq_count = $row['seq_count'];
    
    // Check if analysis already exists in database
    $analysis_stmt = $pdo->prepare("SELECT * FROM conservation_analysis 
                                   WHERE job_id = ? AND window_size = ? AND is_subset = ?");
    $analysis_stmt->execute([$job_id, $window_size, $is_subset]);
    $analysis = $analysis_stmt->fetch();
    
    if (!$analysis) {
        // Create temporary FASTA file for processing
        $fasta_content = $row['sequences'];
        $temp_fasta = tempnam(sys_get_temp_dir(), 'fasta_');
        file_put_contents($temp_fasta, $fasta_content);
        
        // Create results directory
        $results_dir = sys_get_temp_dir() . "/{$job_id}_results_" . ($is_subset ? "subset" : "full");
        if (!is_dir($results_dir)) {
            mkdir($results_dir, 0777, true);
        }
        
        // Run conservation analysis
        $output = shell_exec("/bin/bash run_conservation.sh " . 
                           escapeshellarg($temp_fasta) . " " . 
                           escapeshellarg("$results_dir/alignment.aln") . " " . 
                           escapeshellarg($results_dir) . " " . 
                           $window_size . " 2>&1");
        
        // Store results in database
        $entropy_json = file_exists("$results_dir/entropy.json") ? file_get_contents("$results_dir/entropy.json") : null;
        $entropy_csv = file_exists("$results_dir/entropy.csv") ? file_get_contents("$results_dir/entropy.csv") : null;
        $alignment = file_exists("$results_dir/alignment.txt") ? file_get_contents("$results_dir/alignment.txt") : null;
        $report = file_exists("$results_dir/report.txt") ? file_get_contents("$results_dir/report.txt") : null;
        $plotcon = file_exists("$results_dir/plotcon.png") ? file_get_contents("$results_dir/plotcon.png") : null;
        $entropy_png = file_exists("$results_dir/entropy.png") ? file_get_contents("$results_dir/entropy.png") : null;
        
        $insert_stmt = $pdo->prepare("INSERT INTO conservation_analysis 
                                    (job_id, window_size, is_subset, entropy_json, entropy_csv, 
                                     alignment, report, plotcon, entropy_png, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $insert_stmt->execute([
            $job_id, $window_size, $is_subset, 
            $entropy_json, $entropy_csv, $alignment, $report, $plotcon, $entropy_png
        ]);
        
        // Clean up temp files
        unlink($temp_fasta);
        
        // Refresh analysis data
        $analysis_stmt->execute([$job_id, $window_size, $is_subset]);
        $analysis = $analysis_stmt->fetch();
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Conservation Analysis</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        .analysis-container { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px; }
        .visualization { flex: 1; min-width: 400px; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
        .sequence-viewer { height: 500px; overflow-y: auto; font-family: monospace; background: #f5f5f5; padding: 10px; white-space: pre; }
        .insights { background-color: #f8f9fa; padding: 15px; border-left: 4px solid #6c757d; margin: 20px 0; }
        .plot-container { height: 400px; }
        .download-buttons { margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; }
        .download-btn {
            padding: 8px 12px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }
        .download-btn:hover { background: #45a049; }
        .download-btn.secondary {
            background: #2196F3;
        }
        .download-btn.secondary:hover {
            background: #0b7dda;
        }
    </style>
</head>
<body>
    <h1>Conservation Analysis for <?= htmlspecialchars($protein_family) ?></h1>
    <p><strong>Taxonomic Group:</strong> <?= htmlspecialchars($taxonomic_group) ?></p>
    <p><strong>Window Size:</strong> <?= $window_size ?></p>
    <p><strong>Sequences:</strong> <?= $seq_count ?> (<?= $is_subset ? 'Subset' : 'Full Set' ?>)</p>
    <p><strong>Analysis Date:</strong> <?= $analysis['created_at'] ?></p>

    <div class="analysis-container">
        <div class="visualization">
            <h2>Shannon Entropy Analysis</h2>
            <div class="plot-container" id="entropyPlot"></div>
            <?php if (!empty($analysis['entropy_json'])): ?>
                <div class="download-buttons">
                    <a href="download.php?type=conservation&id=<?= $analysis['id'] ?>&file=entropy_json" class="download-btn">
                        Download JSON
                    </a>
                    <a href="download.php?type=conservation&id=<?= $analysis['id'] ?>&file=entropy_csv" class="download-btn secondary">
                        Download CSV
                    </a>
                    <?php if (!empty($analysis['entropy_png'])): ?>
                        <a href="download.php?type=conservation&id=<?= $analysis['id'] ?>&file=entropy_png" class="download-btn">
                            Download PNG
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($analysis['report'])): ?>
                        <a href="download.php?type=conservation&id=<?= $analysis['id'] ?>&file=report" class="download-btn secondary">
                            Download Full Report
                        </a>
                    <?php endif; ?>
                </div>
                <script>
                    var plotData = <?= $analysis['entropy_json'] ?>;
                    Plotly.newPlot("entropyPlot", plotData.data, plotData.layout);
                </script>
            <?php else: ?>
                <p style="color:red;">Entropy analysis failed to generate. Try smaller dataset.</p>
            <?php endif; ?>
        </div>

        <div class="visualization">
            <h2>Aligned Sequences</h2>
            <div class="sequence-viewer">
                <?= !empty($analysis['alignment']) ? htmlspecialchars($analysis['alignment']) : "Alignment data not available." ?>
            </div>
            <div class="download-buttons">
                <?php if (!empty($analysis['alignment'])): ?>
                    <a href="download.php?type=conservation&id=<?= $analysis['id'] ?>&file=alignment" class="download-btn">
                        Download Alignment
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="visualization">
        <h2>EMBOSS Plotcon Analysis</h2>
        <?php if (!empty($analysis['plotcon'])): ?>
            <img src="data:image/png;base64,<?= base64_encode($analysis['plotcon']) ?>" style="max-width:100%;">
            <div class="download-buttons">
                <a href="download.php?type=conservation&id=<?= $analysis['id'] ?>&file=plotcon" class="download-btn">
                    Download Plot
                </a>
            </div>
        <?php else: ?>
            <p style="color:red;">Plotcon analysis failed to generate.</p>
        <?php endif; ?>
    </div>

    <div class="insights">
        <h3>Interpretation Guide</h3>
        <p><strong>Shannon Entropy:</strong></p>
        <ul>
            <li>0 = Perfectly conserved position</li>
            <li>Higher values = More diversity at that position</li>
            <li>Look for conserved regions (low values) that may indicate functional importance</li>
        </ul>
        <p><strong>Plotcon:</strong></p>
        <ul>
            <li>Shows smoothed conservation over <?= $window_size ?>-residue windows</li>
            <li>High scores = Conserved regions</li>
            <li>Low scores = Variable regions</li>
        </ul>
    </div>
</body>
</html>
