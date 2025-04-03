<?php
session_start();

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
                    'output' => $sequence_output,
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
                'output' => '',
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motif Analysis</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="motifs.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
</head>
<body class="dark-mode">
    <!-- Dark Mode Toggle -->
    <button id="darkModeToggle" class="dark-mode-toggle">
        <span class="toggle-icon"></span>
    </button>

    <!-- Animated Background -->
    <div id="particles-js"></div>

    <!-- Top Navigation Bar -->
    <nav class="top-bar glass">
        <div class="logo-nav-container">
            <a href="home.php" class="logo-tab">
                <img src="images/full_logo.png" alt="Protein Analysis Suite" class="logo">
                <span>Protein Analysis Suite</span>
            </a>

            <div class="nav-links">
                <a href="home.php" class="nav-link"><span>New Search</span></a>
                <a href="past.php" class="nav-link"><span>Past Searches</span></a>
                <a href="example.php" class="nav-link"><span>Example Analysis</span></a>
                <a href="about.php" class="nav-link"><span>About</span></a>
                <a href="help.php" class="nav-link"><span>Help</span></a>
                <a href="credits.php" class="nav-link"><span>Credits</span></a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="motif-analysis-container glass">
            <div class="motif-header">
                <h1>Motif Analysis: <?= htmlspecialchars($job['search_term'] ?? 'Unknown') ?></h1>
                <div class="motif-actions">
                    <a href="results.php?job_id=<?= $job_id ?>" class="action-btn back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Results
                    </a>
                    <button onclick="downloadReport()" class="action-btn download-btn">
                        <i class="fas fa-download"></i> Download Full Report
                    </button>
                </div>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="error-message">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="analysis-summary glass">
                <h2>Analysis Summary</h2>
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="summary-label">Job ID:</span>
                        <span class="summary-value"><?= $job_id ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Taxonomic Group:</span>
                        <span class="summary-value"><?= htmlspecialchars($job['taxon'] ?? 'Unknown') ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Sequences Analyzed:</span>
                        <span class="summary-value"><?= $sequence_count ?></span>
                    </div>
                    <?php if (!empty($all_motifs)): ?>
                        <div class="summary-item">
                            <span class="summary-label">Total Motifs Found:</span>
                            <span class="summary-value"><?= count($all_motifs) ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Unique Motif Types:</span>
                            <span class="summary-value"><?= count(array_unique(array_column($all_motifs, 'motif_name'))) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sequence-results">
                <?php foreach ($sequences as $seq):
                    $seq_motifs = array_filter($all_motifs, function($m) use ($seq) {
                        return $m['sequence_id'] == $seq['sequence_id'];
                    });
                    $has_motifs = !empty($seq_motifs);
                    $report = $sequence_reports[$seq['ncbi_id']] ?? ['hitcount' => 0];
                ?>
                    <div class="sequence-card glass">
                        <div class="sequence-header">
                            <h3><?= htmlspecialchars($seq['ncbi_id']) ?></h3>
                            <span class="motif-count <?= $has_motifs ? 'has-motifs' : 'no-motifs' ?>">
                                <?= $has_motifs ? count($seq_motifs) . ' motifs found' : 'No motifs found' ?>
                            </span>
                        </div>

                        <?php if ($has_motifs): ?>
                            <div class="motifs-list">
                                <?php foreach ($seq_motifs as $motif):
                                    $start = max(0, $motif['start_pos'] - 10);
                                    $end = min(strlen($seq['sequence']), $motif['end_pos'] + 10);
                                    $segment = substr($seq['sequence'], $start, $end - $start);
                                    $highlight_start = $motif['start_pos'] - $start - 1;
                                    $highlight_length = $motif['end_pos'] - $motif['start_pos'] + 1;
                                ?>
                                    <div class="motif-item">
                                        <div class="motif-name"><?= htmlspecialchars($motif['motif_name']) ?></div>
                                        <div class="motif-positions">Positions: <?= $motif['start_pos'] ?> to <?= $motif['end_pos'] ?></div>
                                        <div class="motif-context">
                                            <pre class="motif-output"><?= 
                                                htmlspecialchars(substr($segment, 0, $highlight_start)) .
                                                '<span class="highlight">' . htmlspecialchars(substr($segment, $highlight_start, $highlight_length)) . '</span>' .
                                                htmlspecialchars(substr($segment, $highlight_start + $highlight_length)) . "\n" .
                                                str_repeat(' ', $highlight_start) . str_repeat('^', $highlight_length)
                                            ?></pre>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-motifs-message">No known motifs detected in this sequence</p>
                        <?php endif; ?>

                        <?php if (!empty($report['output'])): ?>
                            <div class="analysis-details">
                                <div class="toggle-output" onclick="toggleOutput('output-<?= $seq['sequence_id'] ?>')">
                                    <i class="fas fa-chevron-right"></i>
                                    <span>Show analysis details</span>
                                </div>
                                <pre id="output-<?= $seq['sequence_id'] ?>" class="output-content"><?= htmlspecialchars($report['output']) ?></pre>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer glass">
        <div class="footer-content">
            <p>Created as part of the postgraduate course Introduction to Website and Database Design @ the University of Edinburgh, this website reflects coursework submitted for academic assessment.</p>
            <a href="https://github.com/B269905-2024/web_project" target="_blank" class="github-link">
                <i class="fab fa-github"></i> View the source code on GitHub
            </a>
        </div>
    </footer>

    <script>
        // Initialize particles.js background
        particlesJS("particles-js", {
            particles: {
                number: { value: 80, density: { enable: true, value_area: 800 } },
                color: { value: "#8d9db6" },
                shape: { type: "circle" },
                opacity: { value: 0.5, random: true },
                size: { value: 3, random: true },
                line_linked: { enable: true, distance: 150, color: "#8d9db6", opacity: 0.2, width: 1 },
                move: { enable: true, speed: 2, direction: "none", random: true, straight: false, out_mode: "out" }
            },
            interactivity: {
                detect_on: "canvas",
                events: {
                    onhover: { enable: true, mode: "grab" },
                    onclick: { enable: true, mode: "push" }
                }
            }
        });

        // Dark Mode Toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        const body = document.body;

        darkModeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');

            if (body.classList.contains('dark-mode')) {
                localStorage.setItem('darkMode', 'enabled');
            } else {
                localStorage.setItem('darkMode', 'disabled');
            }
        });

        // Set dark mode as default if not set
        if (!localStorage.getItem('darkMode')) {
            localStorage.setItem('darkMode', 'enabled');
            body.classList.add('dark-mode');
        } else if (localStorage.getItem('darkMode') === 'disabled') {
            body.classList.remove('dark-mode');
        }

        function toggleOutput(id) {
            const element = document.getElementById(id);
            const toggle = element.previousElementSibling;
            const icon = toggle.querySelector('i');

            if (element.style.display === 'none') {
                element.style.display = 'block';
                toggle.querySelector('span').textContent = 'Hide analysis details';
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-down');
            } else {
                element.style.display = 'none';
                toggle.querySelector('span').textContent = 'Show analysis details';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-right');
            }
        }

        function downloadReport() {
            // Create report content
            let reportContent = `Motif Analysis Report\n`;
            reportContent += `========================\n\n`;
            reportContent += `Job Information:\n`;
            reportContent += `- Job ID: ${<?= $job_id ?>}\n`;
            reportContent += `- Search Term: ${'<?= htmlspecialchars($job['search_term'] ?? 'Unknown') ?>'}\n`;
            reportContent += `- Taxonomic Group: ${'<?= htmlspecialchars($job['taxon'] ?? 'Unknown') ?>'}\n`;
            reportContent += `- Sequences Analyzed: ${<?= $sequence_count ?>}\n`;
            reportContent += `- Total Motifs Found: ${<?= count($all_motifs) ?>}\n`;
            reportContent += `- Unique Motif Types: ${<?= count(array_unique(array_column($all_motifs, 'motif_name'))) ?>}\n\n`;

            // Add sequence details
            reportContent += `Sequence Details:\n`;
            reportContent += `========================\n\n`;

            <?php foreach ($sequences as $seq):
                $seq_motifs = array_filter($all_motifs, function($m) use ($seq) {
                    return $m['sequence_id'] == $seq['sequence_id'];
                });
                $report = $sequence_reports[$seq['ncbi_id']] ?? ['hitcount' => 0];
            ?>
                reportContent += `Sequence: ${'<?= htmlspecialchars($seq['ncbi_id']) ?>'}\n`;
                reportContent += `Motifs Found: ${<?= !empty($seq_motifs) ? count($seq_motifs) : 0 ?>}\n\n`;

                <?php if (!empty($seq_motifs)): ?>
                    <?php foreach ($seq_motifs as $motif):
                        $start = max(0, $motif['start_pos'] - 10);
                        $end = min(strlen($seq['sequence']), $motif['end_pos'] + 10);
                        $segment = substr($seq['sequence'], $start, $end - $start);
                        $highlight_start = $motif['start_pos'] - $start - 1;
                        $highlight_length = $motif['end_pos'] - $motif['start_pos'] + 1;
                    ?>
                        reportContent += `  Motif: ${'<?= htmlspecialchars($motif['motif_name']) ?>'}\n`;
                        reportContent += `  Positions: ${<?= $motif['start_pos'] ?>} to ${<?= $motif['end_pos'] ?>}\n`;
                        reportContent += `  Context: ${'<?= htmlspecialchars($segment) ?>'}\n`;
                        reportContent += `           ${'<?= str_repeat(' ', $highlight_start) . str_repeat('^', $highlight_length) ?>'}\n\n`;
                    <?php endforeach; ?>
                <?php else: ?>
                    reportContent += `  No known motifs detected in this sequence\n\n`;
                <?php endif; ?>
            <?php endforeach; ?>

            // Add timestamp
            reportContent += `Report generated on: ${new Date().toLocaleString()}\n`;

            // Create download link
            const blob = new Blob([reportContent], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `motif_report_job_${<?= $job_id ?>}_${new Date().toISOString().slice(0,10)}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Initialize all output sections as hidden
        document.addEventListener('DOMContentLoaded', function() {
            const outputSections = document.querySelectorAll('.output-content');
            outputSections.forEach(section => {
                section.style.display = 'none';
            });
        });
    </script>
</body>
</html>
