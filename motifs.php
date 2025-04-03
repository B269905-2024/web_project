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

    // Get all sequences for this job
    $stmt = $pdo->prepare("SELECT sequence_id, ncbi_id, sequence FROM sequences WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $sequences = $stmt->fetchAll();
    $sequence_count = count($sequences);

    // Check for existing motif job
    $stmt = $pdo->prepare("SELECT * FROM motif_jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $motif_job = $stmt->fetch();

    $all_motifs = [];
    $sequence_reports = [];

    if (!$motif_job && !empty($sequences)) {
        // Create new motif job
        $stmt = $pdo->prepare("INSERT INTO motif_jobs (job_id) VALUES (?)");
        $stmt->execute([$job_id]);
        $motif_id = $pdo->lastInsertId();

        $output_dir = sys_get_temp_dir();

        foreach ($sequences as $seq) {
            $sequence_motifs = [];
            $sequence_output = '';

            // Create temporary FASTA file for this sequence
            $fasta_content = ">{$seq['ncbi_id']}\n{$seq['sequence']}\n";
            $fasta_file = tempnam($output_dir, 'motif_');
            file_put_contents($fasta_file, $fasta_content);
            chmod($fasta_file, 0644);

            // Build command for this sequence
            $output_file = "$output_dir/patmatmotifs_output_{$job_id}_{$seq['sequence_id']}.txt";
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
                error_log("patmatmotifs failed for sequence {$seq['ncbi_id']} with code $return_var");
                continue;
            }

            // Parse output if exists
            if (file_exists($output_file)) {
                $output_content = file_get_contents($output_file);
                $sequence_output = $output_content;

                // Store report in database
                $stmt = $pdo->prepare("INSERT INTO motif_reports (motif_id, report_text) VALUES (?, ?)");
                $stmt->execute([$motif_id, $output_content]);

                // Parse the output for this sequence
                $lines = explode("\n", $output_content);
                $current_hitcount = 0;
                $motif_block = [];
                $in_motif = false;

                foreach ($lines as $line) {
                    if (preg_match('/^HitCount: (\d+)/', $line, $matches)) {
                        $current_hitcount = (int)$matches[1];
                    }
                    elseif (preg_match('/^Motif = (\S+)/', $line, $matches)) {
                        $motif_block['motif_name'] = $matches[1];
                        $in_motif = true;
                    }
                    elseif (preg_match('/^Start = position (\d+) of sequence/', $line, $matches)) {
                        $motif_block['start_pos'] = (int)$matches[1];
                    }
                    elseif (preg_match('/^End = position (\d+) of sequence/', $line, $matches)) {
                        $motif_block['end_pos'] = (int)$matches[1];
                        $motif_block['sequence'] = $seq['ncbi_id'];
                        $motif_block['sequence_id'] = $seq['sequence_id'];

                        // Only store complete motif blocks
                        if ($in_motif && !empty($motif_block['motif_name'])) {
                            $sequence_motifs[] = $motif_block;

                            // Store in database
                            $stmt = $pdo->prepare("INSERT INTO motif_results
                                (motif_id, sequence_id, motif_name, start_pos, end_pos)
                                VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $motif_id,
                                $seq['sequence_id'],
                                $motif_block['motif_name'],
                                $motif_block['start_pos'],
                                $motif_block['end_pos']
                            ]);
                        }
                        $motif_block = [];
                        $in_motif = false;
                    }
                }

                $all_motifs = array_merge($all_motifs, $sequence_motifs);

                // Create sequence report
                $sequence_reports[$seq['ncbi_id']] = [
                    'motifs' => $sequence_motifs,
                    'hitcount' => $current_hitcount
                ];
            }
        }
    } elseif ($motif_job) {
        // Get existing results from database
        $stmt = $pdo->prepare("
            SELECT mr.*, s.ncbi_id as sequence
            FROM motif_results mr
            JOIN sequences s ON mr.sequence_id = s.sequence_id
            WHERE mr.motif_id = ?
            ORDER BY mr.sequence_id, mr.start_pos
        ");
        $stmt->execute([$motif_job['motif_id']]);
        $db_motifs = $stmt->fetchAll();

        foreach ($db_motifs as $db_motif) {
            $all_motifs[] = [
                'sequence' => $db_motif['sequence'],
                'sequence_id' => $db_motif['sequence_id'],
                'motif_name' => $db_motif['motif_name'],
                'start_pos' => $db_motif['start_pos'],
                'end_pos' => $db_motif['end_pos']
            ];
        }

        // Group motifs by sequence for reporting
        foreach ($sequences as $seq) {
            $sequence_motifs = array_filter($all_motifs, function($m) use ($seq) {
                return $m['sequence_id'] == $seq['sequence_id'];
            });

            $sequence_reports[$seq['ncbi_id']] = [
                'motifs' => $sequence_motifs,
                'hitcount' => count($sequence_motifs)
            ];
        }
    }

} catch (Exception $e) {
    error_log("Motif analysis error: " . $e->getMessage());
    $_SESSION['error'] = "Motif analysis failed: " . $e->getMessage();
    header("Location: results.php?job_id=$job_id");
    exit();
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Motif Analysis</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; margin: 20px; }
        .sequence { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; }
        .motif { background-color: #f5f5f5; padding: 10px; margin: 10px 0; }
        .highlight { background-color: #ffeb3b; padding: 2px; }
        .download-btn {
            background-color: #4CAF50;
            color: white;
            padding: 8px 16px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            margin: 4px 2px;
            cursor: pointer;
            border: none;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div>
        <div>
            <a href="home.php">New Search</a> |
            <a href="past.php">Past Searches</a> |
            <a href="results.php?job_id=<?= $job_id ?>">Back to Results</a>
        </div>

        <h1>Motif Analysis: <?= htmlspecialchars($job['search_term'] ?? 'Unknown') ?></h1>

        <?php if (isset($_SESSION['error'])): ?>
            <div style="color: red; margin-bottom: 20px;">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div>
            <h2>Analysis Summary</h2>
            <p><strong>Job ID:</strong> <?= $job_id ?></p>
            <p><strong>Taxonomic Group:</strong> <?= htmlspecialchars($job['taxon'] ?? 'Unknown') ?></p>
            <p><strong>Sequences Analyzed:</strong> <?= $sequence_count ?></p>
            <?php if (!empty($all_motifs)): ?>
                <p><strong>Total Motifs Found:</strong> <?= count($all_motifs) ?></p>
                <p><strong>Unique Motif Types:</strong> <?= count(array_unique(array_column($all_motifs, 'motif_name'))) ?></p>
                <a href="download_motifs.php?job_id=<?= $job_id ?>" class="download-btn">Download All Motif Results</a>
            <?php endif; ?>
        </div>

        <div>
            <?php foreach ($sequences as $seq):
                $seq_motifs = array_filter($all_motifs, function($m) use ($seq) {
                    return $m['sequence_id'] == $seq['sequence_id'];
                });
                $has_motifs = !empty($seq_motifs);
                $report = $sequence_reports[$seq['ncbi_id']] ?? ['hitcount' => 0];
            ?>
                <div class="sequence">
                    <h3><?= htmlspecialchars($seq['ncbi_id']) ?>
                        <span style="font-weight:normal">(<?= $has_motifs ? count($seq_motifs) . ' motifs' : 'No motifs' ?>)</span>
                        <?php if ($has_motifs): ?>
                            <a href="download_motifs.php?job_id=<?= $job_id ?>&sequence_id=<?= $seq['sequence_id'] ?>" class="download-btn">Download Details</a>
                        <?php endif; ?>
                    </h3>

                    <?php if ($has_motifs): ?>
                        <?php foreach ($seq_motifs as $motif):
                            $start = max(0, $motif['start_pos'] - 10);
                            $end = min(strlen($seq['sequence']), $motif['end_pos'] + 10);
                            $segment = substr($seq['sequence'], $start, $end - $start);
                            $highlight_start = $motif['start_pos'] - $start - 1;
                            $highlight_length = $motif['end_pos'] - $motif['start_pos'] + 1;
                        ?>
                            <div class="motif">
                                <div><strong><?= htmlspecialchars($motif['motif_name']) ?></strong></div>
                                <div>Positions: <?= $motif['start_pos'] ?> to <?= $motif['end_pos'] ?></div>
                                <div style="font-family: monospace; margin: 5px 0;">
                                    <?= substr($segment, 0, $highlight_start) ?>
                                    <span class="highlight"><?= substr($segment, $highlight_start, $highlight_length) ?></span>
                                    <?= substr($segment, $highlight_start + $highlight_length) ?>
                                </div>
                                <div style="font-family: monospace; color: #666;">
                                    <?= str_repeat('&nbsp;', $highlight_start) ?>
                                    <?= str_repeat('^', $highlight_length) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No known motifs detected in this sequence</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
<?php ob_end_flush(); ?>
