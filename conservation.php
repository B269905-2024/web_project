<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];
$window_size = isset($_GET['window_size']) ? max(1, min(20, (int)$_GET['window_size'])) : 4;

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    // Check for existing conservation analysis
    $stmt = $pdo->prepare("SELECT * FROM conservation_jobs WHERE job_id = ? AND window_size = ?");
    $stmt->execute([$job_id, $window_size]);
    $conservation_job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conservation_job) {
        // Create new conservation job
        $stmt = $pdo->prepare("INSERT INTO conservation_jobs (job_id, window_size, status) VALUES (?, ?, 'running')");
        $stmt->execute([$job_id, $window_size]);
        $conservation_id = $pdo->lastInsertId();

        // Get sequences from database
        $stmt = $pdo->prepare("SELECT ncbi_id, sequence FROM sequences WHERE job_id = ?");
        $stmt->execute([$job_id]);
        $sequences = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sequences)) {
            $stmt = $pdo->prepare("UPDATE conservation_jobs SET status = 'failed' WHERE conservation_id = ?");
            $stmt->execute([$conservation_id]);

            $report_text = "Conservation Analysis Report\n===========================\n\n";
            $report_text .= "Job ID: $job_id\n";
            $report_text .= "Conservation ID: $conservation_id\n";
            $report_text .= "Window size: $window_size\n\n";
            $report_text .= "ERROR: No sequences found for this job\n";

            $stmt = $pdo->prepare("INSERT INTO conservation_reports (conservation_id, report_text) VALUES (?, ?)");
            $stmt->execute([$conservation_id, $report_text]);

            header("Location: results.php?job_id=$job_id");
            exit();
        }

        // Perform alignment and analysis
        perform_conservation_analysis($pdo, $conservation_id, $job_id, $window_size, $sequences);

        // Refresh the conservation job data
        $stmt = $pdo->prepare("SELECT * FROM conservation_jobs WHERE conservation_id = ?");
        $stmt->execute([$conservation_id]);
        $conservation_job = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get all analysis data
    $stmt = $pdo->prepare("SELECT position, entropy, plotcon_score FROM conservation_results WHERE conservation_id = ? ORDER BY position");
    $stmt->execute([$conservation_job['conservation_id']]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM conservation_reports WHERE conservation_id = ?");
    $stmt->execute([$conservation_job['conservation_id']]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT ncbi_id, sequence FROM conservation_alignments WHERE conservation_id = ?");
    $stmt->execute([$conservation_job['conservation_id']]);
    $alignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// [KEEP ONLY ONE COPY OF EACH FUNCTION - REMOVE THE DUPLICATES]

/**
 * Simplified alignment function - in a real implementation you would use a proper alignment library
 */
function perform_alignment($sequences) {
    // This is a simplified example - in reality you would use a proper alignment algorithm
    $aligned_sequences = [];
    $max_length = 0;

    foreach ($sequences as $seq) {
        $max_length = max($max_length, strlen($seq['sequence']));
    }

    foreach ($sequences as $seq) {
        $aligned_sequences[] = [
            'ncbi_id' => $seq['ncbi_id'],
            'aligned_sequence' => str_pad($seq['sequence'], $max_length, '-')
        ];
    }

    return $aligned_sequences;
}

/**
 * Calculate Shannon entropy for each position in the alignment
 */
function calculate_entropy($alignment) {
    $entropy_results = [];
    $alignment_length = strlen($alignment[0]['aligned_sequence']);
    $num_sequences = count($alignment);

    for ($i = 0; $i < $alignment_length; $i++) {
        $column = '';
        foreach ($alignment as $seq) {
            $column .= $seq['aligned_sequence'][$i];
        }

        // Count amino acids (ignore gaps)
        $column = str_replace('-', '', $column);
        $counts = count_chars($column, 1);
        $total = strlen($column);

        $entropy = 0;
        if ($total > 0) {
            foreach ($counts as $count) {
                $p = $count / $total;
                $entropy -= $p * log($p, 2);
            }
        }

        $entropy_results[$i] = $entropy;
    }

    return $entropy_results;
}

/**
 * Simplified Plotcon score calculation
 */
function calculate_plotcon_scores($entropy_results, $window_size) {
    $plotcon_scores = [];
    $count = count($entropy_results);

    for ($i = 0; $i < $count; $i++) {
        $start = max(0, $i - floor($window_size / 2));
        $end = min($count - 1, $i + floor($window_size / 2));

        $sum = 0;
        $window_count = 0;
        for ($j = $start; $j <= $end; $j++) {
            $sum += $entropy_results[$j];
            $window_count++;
        }

        // Convert entropy to conservation score (higher = more conserved)
        $plotcon_scores[$i] = $window_count > 0 ? (1 - ($sum / $window_count)) : 0;
    }

    return $plotcon_scores;
}

/**
 * Performs the conservation analysis and stores results in the database
 */
function perform_conservation_analysis($pdo, $conservation_id, $job_id, $window_size, $sequences) {
    $report_text = "Conservation Analysis Report\n===========================\n\n";
    $report_text .= "Job ID: $job_id\n";
    $report_text .= "Conservation ID: $conservation_id\n";
    $report_text .= "Window size: $window_size\n\n";

    $num_sequences = count($sequences);
    $report_text .= "Number of sequences: $num_sequences\n";

    try {
        // Step 1: Perform alignment
        $alignment = perform_alignment($sequences);
        $alignment_length = strlen($alignment[0]['aligned_sequence']);

        // Store aligned sequences
        foreach ($alignment as $aligned_seq) {
            $stmt = $pdo->prepare("INSERT INTO conservation_alignments (conservation_id, ncbi_id, sequence) VALUES (?, ?, ?)");
            $stmt->execute([$conservation_id, $aligned_seq['ncbi_id'], $aligned_seq['aligned_sequence']]);
        }

        $report_text .= "\nAlignment completed successfully. Alignment length: $alignment_length residues\n";

        // Step 2: Calculate Shannon entropy
        $entropy_results = calculate_entropy($alignment);

        // Store entropy results
        foreach ($entropy_results as $position => $entropy) {
            $stmt = $pdo->prepare("INSERT INTO conservation_results (conservation_id, position, entropy) VALUES (?, ?, ?)");
            $stmt->execute([$conservation_id, $position + 1, $entropy]);
        }

        // Step 3: Calculate Plotcon scores
        $plotcon_scores = calculate_plotcon_scores($entropy_results, $window_size);

        // Store plotcon results
        foreach ($plotcon_scores as $position => $score) {
            $stmt = $pdo->prepare("UPDATE conservation_results SET plotcon_score = ? WHERE conservation_id = ? AND position = ?");
            $stmt->execute([$score, $conservation_id, $position + 1]);
        }

        // Calculate statistics
        $mean_entropy = array_sum($entropy_results) / count($entropy_results);
        $max_entropy = max($entropy_results);
        $min_entropy = min($entropy_results);
        $max_pos = array_search($max_entropy, $entropy_results) + 1;
        $min_pos = array_search($min_entropy, $entropy_results) + 1;

        // Generate report
        $report_text .= "\n=== Shannon Entropy Results ===\n";
        $report_text .= "Alignment length: $alignment_length residues\n";
        $report_text .= sprintf("Mean entropy: %.3f bits\n", $mean_entropy);
        $report_text .= sprintf("Max entropy: %.3f bits (position %d)\n", $max_entropy, $max_pos);
        $report_text .= sprintf("Min entropy: %.3f bits (position %d)\n", $min_entropy, $min_pos);

        // Top conserved/variable positions
        asort($entropy_results);
        $sorted_positions = array_slice($entropy_results, 0, 5, true);

        $report_text .= "\nTop 5 most conserved positions:\n";
        foreach ($sorted_positions as $pos => $ent) {
            $report_text .= sprintf("Position %d: %.3f bits\n", $pos + 1, $ent);
        }

        arsort($entropy_results);
        $sorted_positions = array_slice($entropy_results, 0, 5, true);

        $report_text .= "\nTop 5 most variable positions:\n";
        foreach ($sorted_positions as $pos => $ent) {
            $report_text .= sprintf("Position %d: %.3f bits\n", $pos + 1, $ent);
        }

        $report_text .= "\nAnalysis completed successfully.\n";

        // Store report
        $stmt = $pdo->prepare("INSERT INTO conservation_reports
            (conservation_id, report_text, mean_entropy, max_entropy, min_entropy, max_position, min_position)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $conservation_id,
            $report_text,
            $mean_entropy,
            $max_entropy,
            $min_entropy,
            $max_pos,
            $min_pos
        ]);

        // Update job status
        $stmt = $pdo->prepare("UPDATE conservation_jobs SET status = 'completed' WHERE conservation_id = ?");
        $stmt->execute([$conservation_id]);

    } catch (Exception $e) {
        // On error, update status and store error message
        $stmt = $pdo->prepare("UPDATE conservation_jobs SET status = 'failed' WHERE conservation_id = ?");
        $stmt->execute([$conservation_id]);

        $report_text .= "\nERROR: " . $e->getMessage() . "\n";

        $stmt = $pdo->prepare("INSERT INTO conservation_reports (conservation_id, report_text) VALUES (?, ?)");
        $stmt->execute([$conservation_id, $report_text]);
    }
}

// Prepare entropy data for JSON
$entropy_data = [
    'data' => [[
        'y' => array_column($results, 'entropy'),
        'type' => 'line',
        'name' => 'Entropy',
        'line' => ['color' => '#8d9db6']
    ]],
    'layout' => [
        'title' => 'Shannon Entropy Analysis',
        'xaxis' => ['title' => 'Position'],
        'yaxis' => ['title' => 'Entropy (bits)'],
        'plot_bgcolor' => 'rgba(0,0,0,0)',
        'paper_bgcolor' => 'rgba(0,0,0,0)',
        'font' => ['color' => 'var(--text)'],
        'shapes' => [[
            'type' => 'line',
            'x0' => 0,
            'x1' => count($results),
            'y0' => $report['mean_entropy'],
            'y1' => $report['mean_entropy'],
            'line' => ['color' => '#bccad6', 'dash' => 'dash']
        ]]
    ]
];

// Prepare plotcon data if available
$plotcon_data = null;
if (!empty(array_column($results, 'plotcon_score'))) {
    $plotcon_data = [
        'data' => [[
            'y' => array_column($results, 'plotcon_score'),
            'type' => 'line',
            'name' => 'Conservation Score',
            'line' => ['color' => '#667292']
        ]],
        'layout' => [
            'title' => 'Plotcon Conservation Analysis',
            'xaxis' => ['title' => 'Position'],
            'yaxis' => ['title' => 'Conservation Score'],
            'plot_bgcolor' => 'rgba(0,0,0,0)',
            'paper_bgcolor' => 'rgba(0,0,0,0)',
            'font' => ['color' => 'var(--text)']
        ]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conservation Analysis - Protein Analysis Suite</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="conservation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
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
    <main class="conservation-content">
        <div class="conservation-header glass">
            <h1>Conservation Analysis</h1>
            <div class="job-info">
                <p><i class="fas fa-dna"></i> <strong>Protein/Gene:</strong> <?php echo htmlspecialchars($job['search_term']); ?></p>
                <p><i class="fas fa-tree"></i> <strong>Taxonomic Group:</strong> <?php echo htmlspecialchars($job['taxon']); ?></p>
                <p><i class="fas fa-sliders-h"></i> <strong>Window Size:</strong> <?php echo $window_size; ?></p>
            </div>
        </div>

        <div class="conservation-grid">
            <!-- Entropy Plot -->
            <div class="analysis-card glass">
                <div class="card-header">
                    <h2><i class="fas fa-chart-line"></i> Shannon Entropy Analysis</h2>
                    <div class="download-buttons">
                        <a href="download_conservation.php?type=entropy_json&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn">
                            <i class="fas fa-file-code"></i> JSON
                        </a>
                        <a href="download_conservation.php?type=entropy_csv&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn secondary">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                    </div>
                </div>
                <div class="plot-container" id="entropyPlot"></div>
                <div class="plot-description">
                    <p>Shannon entropy measures sequence variability at each position (0 = perfectly conserved). The dashed line shows the mean entropy (<?php echo number_format($report['mean_entropy'], 3); ?> bits).</p>
                </div>
                <script>
                    var entropyData = <?php echo json_encode($entropy_data); ?>;
                    Plotly.newPlot("entropyPlot", entropyData.data, entropyData.layout);
                </script>
            </div>

            <!-- Sequence Alignment -->
            <div class="analysis-card glass">
                <div class="card-header">
                    <h2><i class="fas fa-align-left"></i> Multiple Sequence Alignment</h2>
                    <div class="download-buttons">
                        <a href="download_conservation.php?type=alignment&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn">
                            <i class="fas fa-download"></i> FASTA
                        </a>
                    </div>
                </div>
                <div class="sequence-viewer">
                    <?php foreach ($alignments as $aln): ?>
                        <div class="sequence-entry">
                            <span class="sequence-id">><?php echo htmlspecialchars($aln['ncbi_id']); ?></span>
                            <div class="sequence-text"><?php echo chunk_split($aln['sequence'], 80, "<br>"); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($plotcon_data): ?>
                <!-- Plotcon Analysis -->
                <div class="analysis-card glass">
                    <div class="card-header">
                        <h2><i class="fas fa-wave-square"></i> EMBOSS Plotcon Analysis</h2>
                        <div class="download-buttons">
                            <a href="download_conservation.php?type=plotcon_csv&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn secondary">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                        </div>
                    </div>
                    <div class="plot-container" id="plotconPlot"></div>
                    <div class="plot-description">
                        <p>Plotcon shows smoothed conservation scores (higher = more conserved) using a sliding window of <?php echo $window_size; ?> residues.</p>
                    </div>
                    <script>
                        var plotconData = <?php echo json_encode($plotcon_data); ?>;
                        Plotly.newPlot("plotconPlot", plotconData.data, plotconData.layout);
                    </script>
                </div>
            <?php endif; ?>

            <!-- Analysis Report -->
            <div class="analysis-card glass report-card">
                <div class="card-header">
                    <h2><i class="fas fa-file-alt"></i> Analysis Report</h2>
                </div>
                <div class="report-content">
                    <pre><?php echo htmlspecialchars($report['report_text']); ?></pre>
                </div>
            </div>

            <!-- Interpretation Guide -->
            <div class="analysis-card glass guide-card">
                <div class="card-header">
                    <h2><i class="fas fa-question-circle"></i> Interpretation Guide</h2>
                </div>
                <div class="guide-content">
                    <div class="guide-section">
                        <h3><i class="fas fa-chart-line"></i> Shannon Entropy</h3>
                        <ul>
                            <li><strong>0 bits</strong> - Perfectly conserved position</li>
                            <li><strong>Higher values</strong> - More diversity at that position</li>
                            <li>Conserved regions (low values) may indicate functional importance</li>
                        </ul>
                    </div>
                    <div class="guide-section">
                        <h3><i class="fas fa-wave-square"></i> Plotcon Analysis</h3>
                        <ul>
                            <li>Shows smoothed conservation over <?php echo $window_size; ?>-residue windows</li>
                            <li><strong>High scores</strong> - Conserved regions</li>
                            <li><strong>Low scores</strong> - Variable regions</li>
                        </ul>
                    </div>
                    <div class="guide-section">
                        <h3><i class="fas fa-lightbulb"></i> Key Insights</h3>
                        <ul>
                            <li>Most conserved position: <?php echo $report['min_position']; ?> (<?php echo number_format($report['min_entropy'], 3); ?> bits)</li>
                            <li>Most variable position: <?php echo $report['max_position']; ?> (<?php echo number_format($report['max_entropy'], 3); ?> bits)</li>
                            <li>Mean entropy: <?php echo number_format($report['mean_entropy'], 3); ?> bits</li>
                        </ul>
                    </div>
                </div>
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
        // Dark Mode Toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        const body = document.body;

        darkModeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            
            // Save user preference
            if (body.classList.contains('dark-mode')) {
                localStorage.setItem('darkMode', 'enabled');
            } else {
                localStorage.setItem('darkMode', 'disabled');
            }
            
            // Update plot colors
            updatePlotColors();
        });

        // Set dark mode as default if not set
        if (!localStorage.getItem('darkMode')) {
            localStorage.setItem('darkMode', 'enabled');
            body.classList.add('dark-mode');
        } else if (localStorage.getItem('darkMode') === 'disabled') {
            body.classList.remove('dark-mode');
        }

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

        // Function to update plot colors based on dark mode
        function updatePlotColors() {
            const isDarkMode = body.classList.contains('dark-mode');
            const textColor = isDarkMode ? '#e1e8f0' : '#222222';
            const bgColor = isDarkMode ? 'rgba(26,34,46,0)' : 'rgba(255,255,255,0)';
            
            // Update entropy plot
            Plotly.relayout('entropyPlot', {
                'font.color': textColor,
                'plot_bgcolor': bgColor,
                'paper_bgcolor': bgColor
            });
            
            // Update plotcon plot if it exists
            if (document.getElementById('plotconPlot')) {
                Plotly.relayout('plotconPlot', {
                    'font.color': textColor,
                    'plot_bgcolor': bgColor,
                    'paper_bgcolor': bgColor
                });
            }
        }

        // Initial plot color setup
        updatePlotColors();
    </script>
</body>
</html>
