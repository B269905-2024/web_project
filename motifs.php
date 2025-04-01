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

    // Count total sequences in the dataset
    $total_sequences = substr_count($row['sequences'], '>');
    $using_sequences = $is_subset ? substr_count($row['subset_sequences'], '>') : $total_sequences;

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

            $sequences_with_motifs = 0;
            $total_motifs = 0;

            foreach ($sequence_blocks as $block) {
                $sequence_id = trim($block[1]);
                $start_pos = $block[2];
                $end_pos = $block[3];
                $hit_count = $block[4];

                // Count sequences with motifs
                if ($hit_count > 0) {
                    $sequences_with_motifs++;
                }

                // Parse individual motifs
                $motif_pattern = '/Length = (\d+)\s+Start = position (\d+) of sequence\s+End = position (\d+) of sequence\s+Motif = (.+?)\s+([A-Za-z\s]+)\n\s+([| \n]+)/';
                preg_match_all($motif_pattern, $block[0], $motifs, PREG_SET_ORDER);

                foreach ($motifs as $motif) {
                    $length = $motif[1];
                    $motif_start = $motif[2];
                    $motif_end = $motif[3];
                    $motif_name = trim($motif[4]);
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
                    
                    $total_motifs++;
                }
            }

            // Update analysis with summary statistics
            $update_stmt = $pdo->prepare("UPDATE motif_analyses 
                                        SET sequences_analyzed = ?, 
                                            sequences_with_motifs = ?,
                                            total_motifs_found = ?
                                        WHERE id = ?");
            $update_stmt->execute([
                count($sequence_blocks),
                $sequences_with_motifs,
                $total_motifs,
                $analysis_id
            ]);
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

        // Get analysis statistics
        $stats_stmt = $pdo->prepare("SELECT sequences_analyzed, sequences_with_motifs, total_motifs_found 
                                   FROM motif_analyses WHERE id = ?");
        $stats_stmt->execute([$analysis['id']]);
        $stats = $stats_stmt->fetch();

        echo "Analysis Statistics\n";
        echo "-------------------\n";
        echo "Sequences analyzed: " . $stats['sequences_analyzed'] . "\n";
        echo "Sequences with motifs: " . $stats['sequences_with_motifs'] . "\n";
        echo "Total motifs found: " . $stats['total_motifs_found'] . "\n\n";

        // Get all sequences with motifs
        $sequences = $pdo->prepare("
            SELECT DISTINCT sequence_id
            FROM motif_results
            WHERE job_id = ? AND analysis_id = ?
            ORDER BY sequence_id
        ");
        $sequences->execute([$job_id, $analysis['id']]);
        $sequences = $sequences->fetchAll();

        if (!empty($sequences)) {
            $current_seq = null;
            foreach ($sequences as $seq) {
                $current_seq = $seq['sequence_id'];
                echo "\nSequence: $current_seq\n";
                echo str_repeat("-", 50) . "\n";

                // Get motifs for this sequence
                $motifs = $pdo->prepare("
                    SELECT * FROM motif_results
                    WHERE job_id = ? AND analysis_id = ? AND sequence_id = ?
                    ORDER BY start_pos
                ");
                $motifs->execute([$job_id, $analysis['id'], $current_seq]);
                $motifs = $motifs->fetchAll();

                foreach ($motifs as $motif) {
                    echo "\nMotif: " . $motif['motif_name'] . "\n";
                    echo "Length: " . $motif['length'] . " aa\n";
                    echo "Positions: " . $motif['start_pos'] . "-" . $motif['end_pos'] . "\n";
                    echo "Sequence: " . $motif['sequence_part'] . "\n";
                    echo "Visualization:\n" . $motif['enhanced_guide'] . "\n";
                }
            }
        } else {
            echo "No motifs found in database.\n";
        }
        exit;
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Protein Motif Search</title>
</head>
<body>
    <h1>Motif Search Results for Job: <?= htmlspecialchars($job_id) ?></h1>

    <?php if ($is_subset): ?>
        <p><strong>Using subset:</strong> First <?= htmlspecialchars($_GET['subset'] ?? '?') ?> sequences</p>
    <?php endif; ?>

    <?php if (!empty($analysis)): ?>
        <?php
        // Get analysis statistics
        $stats_stmt = $pdo->prepare("SELECT sequences_analyzed, sequences_with_motifs, total_motifs_found 
                                   FROM motif_analyses WHERE id = ?");
        $stats_stmt->execute([$analysis['id']]);
        $stats = $stats_stmt->fetch();

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

        <h2>Analysis Summary</h2>
        <p>Analyzed <?= $stats['sequences_analyzed'] ?> sequences out of <?= $using_sequences ?></p>
        <p>Found motifs in <?= $stats['sequences_with_motifs'] ?> sequences</p>
        <p>Total motifs found: <?= $stats['total_motifs_found'] ?></p>

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

                <h3>Sequence: <?= htmlspecialchars($seq['sequence_id']) ?></h3>
                <p>Found <?= count($motifs) ?> motifs:</p>

                <?php foreach ($motifs as $motif): ?>
                    <div>
                        <h4><?= htmlspecialchars($motif['motif_name']) ?> Motif</h4>
                        <p>Length: <?= $motif['length'] ?> aa</p>
                        <p>Positions: <?= $motif['start_pos'] ?>-<?= $motif['end_pos'] ?></p>
                        <pre><?= htmlspecialchars($motif['sequence_part']) ?></pre>
                        <pre><?= htmlspecialchars($motif['enhanced_guide']) ?></pre>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <p><a href="?job_id=<?= $job_id ?>&subset=<?= $is_subset ? $_GET['subset'] : '' ?>&generate_report=1">Generate TXT Report</a></p>
        <?php else: ?>
            <p>No motifs were found in the database for this analysis.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>No motif analysis was found for this job.</p>
    <?php endif; ?>
</body>
</html>
