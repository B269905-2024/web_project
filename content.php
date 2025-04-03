<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];
$subset = isset($_GET['subset']) ? (int)$_GET['subset'] : null;

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

    // Check if we need to generate a report
    if (isset($_GET['generate_report'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="aa_content_report_' . $job_id . '.txt"');

        // Get sequences for this job
        $sql = "SELECT ncbi_id, description, sequence FROM sequences WHERE job_id = ?";
        if ($subset) {
            $sql .= " LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$job_id, $subset]);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$job_id]);
        }
        $sequences = $stmt->fetchAll();

        // Basic report header
        echo "Amino Acid Content Analysis Report\n";
        echo "=================================\n\n";
        echo "Job ID: $job_id\n";
        echo "Search Term: " . $job['search_term'] . "\n";
        echo "Taxonomic Group: " . $job['taxon'] . "\n";
        echo "Date: " . date('Y-m-d H:i:s') . "\n";
        if ($subset) {
            echo "Subset: First $subset sequences\n";
        }
        echo "\n";

        $amino_acids = str_split('ACDEFGHIKLMNPQRSTVWY');

        // Generate report content
        foreach ($sequences as $seq) {
            $id = $seq['ncbi_id'];
            $sequence = strtoupper($seq['sequence']);
            $total = strlen($sequence);
            $counts = array_fill_keys($amino_acids, 0);

            foreach (str_split($sequence) as $aa) {
                if (isset($counts[$aa])) {
                    $counts[$aa]++;
                }
            }

            echo "Sequence: $id\n";
            echo "Description: " . $seq['description'] . "\n";
            echo "Length: $total amino acids\n";
            echo "Amino Acid Composition:\n";

            foreach ($counts as $aa => $count) {
                $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                echo sprintf("  %s: %6.2f%% (%d/%d)\n", $aa, $percentage, $count, $total);
            }

            echo str_repeat("-", 60) . "\n\n";
        }
        exit;
    }

    // Get sequences for analysis
    $sql = "SELECT ncbi_id, description, sequence FROM sequences WHERE job_id = ?";
    if ($subset) {
        $sql .= " LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$job_id, $subset]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$job_id]);
    }
    $sequences = $stmt->fetchAll();

    // Calculate amino acid percentages
    $aa_data = [];
    $amino_acids = str_split('ACDEFGHIKLMNPQRSTVWY');
    $descriptions = [];

    foreach ($sequences as $seq) {
        $sequence = strtoupper($seq['sequence']);
        $total = strlen($sequence);
        $counts = array_fill_keys($amino_acids, 0);

        foreach (str_split($sequence) as $aa) {
            if (isset($counts[$aa])) {
                $counts[$aa]++;
            }
        }

        $percentages = [];
        foreach ($counts as $aa => $count) {
            $percentages[$aa] = $total > 0 ? ($count / $total) * 100 : 0;
        }

        $aa_data[$seq['ncbi_id']] = $percentages;
        $descriptions[$seq['ncbi_id']] = $seq['description'];
    }

    $aa_data_json = json_encode($aa_data);
    $descriptions_json = json_encode($descriptions);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amino Acid Content Analysis | Protein Analysis Suite</title>
    <link rel="stylesheet" href="content.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" href="images/logo.png" type="image/png">
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <style>
        :root {
            --bar-color-light: #667292;
            --bar-color-dark: #8d9db6;
            --text-color-light: #222222;
            --text-color-dark: #e1e8f0;
            --grid-color-light: rgba(0,0,0,0.1);
            --grid-color-dark: rgba(255,255,255,0.1);
            --hover-bg-light: rgba(102, 114, 146, 0.8);
            --hover-bg-dark: rgba(141, 157, 182, 0.8);
        }

        body.dark-mode {
            --bar-color: var(--bar-color-dark);
            --text-color: var(--text-color-dark);
            --grid-color: var(--grid-color-dark);
            --hover-bg: var(--hover-bg-dark);
        }

        body:not(.dark-mode) {
            --bar-color: var(--bar-color-light);
            --text-color: var(--text-color-light);
            --grid-color: var(--grid-color-light);
            --hover-bg: var(--hover-bg-light);
        }

        /* Transparent chart container */
        .transparent-chart-container {
            width: 100%;
            height: 500px;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
        }

        /* Plotly modebar styling */
        .modebar-container {
            background: rgba(0,0,0,0.7) !important;
            border-radius: 4px !important;
            padding: 5px !important;
        }

        .modebar-btn svg {
            fill: var(--text-color) !important;
        }

        .modebar-btn:hover {
            background-color: rgba(255,255,255,0.2) !important;
        }
    </style>
</head>
<body class="dark-mode">
    <!-- Animated Background -->
    <div id="particles-js"></div>

    <!-- Dark Mode Toggle -->
    <button id="darkModeToggle" class="dark-mode-toggle">
        <span class="toggle-icon"></span>
    </button>

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
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="analysis-container glass">
            <div class="action-buttons">
                <a href="results.php?job_id=<?= $job_id ?>" class="action-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back to Results
                </a>
                <a href="content.php?job_id=<?= $job_id ?>&subset=<?= $subset ?>&generate_report=1" class="action-btn">
                    <i class="fas fa-download"></i> Download TXT Report
                </a>
            </div>

            <h1>Amino Acid Content Analysis</h1>
            <div class="job-info">
                <div class="info-card">
                    <h3>Search Term</h3>
                    <p><?= htmlspecialchars($job['search_term']) ?></p>
                </div>
                <div class="info-card">
                    <h3>Job ID</h3>
                    <p><?= htmlspecialchars($job_id) ?></p>
                </div>
                <div class="info-card">
                    <h3>Taxonomic Group</h3>
                    <p><?= htmlspecialchars($job['taxon']) ?></p>
                </div>
            </div>

            <?php if ($subset): ?>
                <div class="subset-info glass">
                    <i class="fas fa-info-circle"></i>
                    <strong>Analysis Subset:</strong> First <?= $subset ?> sequences
                </div>
            <?php endif; ?>

            <div class="sequence-selector-container">
                <label for="sequenceSelector">Select Sequence:</label>
                <select id="sequenceSelector" class="glass">
                    <?php foreach (array_keys($aa_data) as $id): ?>
                        <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($id) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sequence-description">
                <h3>Description:</h3>
                <p id="currentDescription"><?= htmlspecialchars($descriptions[array_key_first($aa_data)] ?? 'No description available') ?></p>
            </div>

            <!-- Transparent chart container -->
            <div class="transparent-chart-container">
                <div id="aaChart"></div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer glass">
        <div class="footer-content">
            <p>Created as part of the postgraduate course Introduction to Website and Database Design @ the University of Edinburgh</p>
            <a href="https://github.com/B269905-2024/web_project" target="_blank" class="github-link">
                <i class="fab fa-github"></i> View on GitHub
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

        // Dark Mode Management
        const darkModeToggle = document.getElementById('darkModeToggle');
        const body = document.body;

        // Initialize dark mode from localStorage
        function initDarkMode() {
            const darkModePref = localStorage.getItem('darkMode');
            if (darkModePref === 'disabled') {
                body.classList.remove('dark-mode');
            } else if (darkModePref === null) {
                localStorage.setItem('darkMode', 'enabled');
            }
        }

        // Toggle dark mode
        function toggleDarkMode() {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
            updateChartColors();
        }

        // Chart Management
        const aaData = <?= $aa_data_json ?>;
        const descriptions = <?= $descriptions_json ?>;
        const sequenceIds = Object.keys(aaData);
        const aminoAcids = Object.keys(aaData[sequenceIds[0]]);
        let currentChartId = sequenceIds[0];
        let chartInstance = null;

        // Get current color scheme
        function getColorScheme() {
            return {
                barColor: getComputedStyle(document.documentElement).getPropertyValue('--bar-color').trim(),
                textColor: getComputedStyle(document.documentElement).getPropertyValue('--text-color').trim(),
                gridColor: getComputedStyle(document.documentElement).getPropertyValue('--grid-color').trim(),
                hoverBg: getComputedStyle(document.documentElement).getPropertyValue('--hover-bg').trim()
            };
        }

        // Initialize the chart with transparent background
        function initChart() {
            currentChartId = sequenceIds[0];
            const colors = getColorScheme();

            const data = [{
                x: aminoAcids,
                y: Object.values(aaData[currentChartId]),
                type: 'bar',
                marker: {
                    color: colors.barColor,
                    line: {
                        color: colors.textColor,
                        width: 1.5
                    }
                },
                hoverinfo: 'y',
                hovertemplate: '%{y:.2f}%<extra></extra>'
            }];

            const layout = {
                title: {
                    text: `Amino Acid Composition: ${currentChartId}`,
                    font: {
                        color: colors.textColor,
                        size: 18
                    }
                },
                plot_bgcolor: 'rgba(0,0,0,0)',
                paper_bgcolor: 'rgba(0,0,0,0)',
                font: {
                    color: colors.textColor
                },
                xaxis: {
                    title: {
                        text: 'Amino Acid',
                        font: {
                            color: colors.textColor,
                            size: 14
                        }
                    },
                    tickfont: {
                        color: colors.textColor,
                        size: 12
                    },
                    gridcolor: colors.gridColor,
                    tickangle: -45
                },
                yaxis: {
                    title: {
                        text: 'Percentage (%)',
                        font: {
                            color: colors.textColor,
                            size: 14
                        }
                    },
                    tickfont: {
                        color: colors.textColor,
                        size: 12
                    },
                    gridcolor: colors.gridColor,
                    range: [0, Math.max(...Object.values(aaData[currentChartId])) * 1.1]
                },
                hoverlabel: {
                    bgcolor: colors.hoverBg,
                    font: {
                        color: '#ffffff',
                        size: 12
                    },
                    bordercolor: 'transparent'
                },
                margin: { t: 80, l: 80, r: 40, b: 100 },
                showlegend: false
            };

            const config = {
                responsive: true,
                displayModeBar: true,
                displaylogo: false,
                modeBarButtonsToRemove: ['toImage', 'sendDataToCloud']
            };

            chartInstance = Plotly.newPlot('aaChart', data, layout, config);

            // Set initial description
            document.getElementById('currentDescription').textContent =
                descriptions[currentChartId] || 'No description available';
        }

        // Update chart colors when mode changes
        function updateChartColors() {
            if (!chartInstance) return;

            const colors = getColorScheme();
            const update = {
                marker: { color: colors.barColor }
            };

            const layoutUpdate = {
                title: { font: { color: colors.textColor } },
                font: { color: colors.textColor },
                xaxis: {
                    title: { font: { color: colors.textColor } },
                    tickfont: { color: colors.textColor },
                    gridcolor: colors.gridColor
                },
                yaxis: {
                    title: { font: { color: colors.textColor } },
                    tickfont: { color: colors.textColor },
                    gridcolor: colors.gridColor
                },
                hoverlabel: { bgcolor: colors.hoverBg }
            };

            Plotly.update('aaChart', update, layoutUpdate);
        }

        // Update chart when sequence selection changes - maintains transparency
        function updateChart(sequenceId) {
            currentChartId = sequenceId;
            const colors = getColorScheme();

            // Update description
            document.getElementById('currentDescription').textContent =
                descriptions[currentChartId] || 'No description available';

            Plotly.react('aaChart', [{
                x: aminoAcids,
                y: Object.values(aaData[currentChartId]),
                type: 'bar',
                marker: {
                    color: colors.barColor,
                    line: {
                        color: colors.textColor,
                        width: 1.5
                    }
                },
                hoverinfo: 'y',
                hovertemplate: '%{y:.2f}%<extra></extra>'
            }], {
                title: `Amino Acid Composition: ${currentChartId}`,
                plot_bgcolor: 'rgba(0,0,0,0)',
                paper_bgcolor: 'rgba(0,0,0,0)',
                yaxis: {
                    range: [0, Math.max(...Object.values(aaData[currentChartId])) * 1.1]
                },
                hoverlabel: {
                    bgcolor: colors.hoverBg
                }
            });
        }

        // Initialize everything when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            initDarkMode();
            initChart();

            // Set up event listeners
            darkModeToggle.addEventListener('click', toggleDarkMode);

            document.getElementById('sequenceSelector').addEventListener('change', function(e) {
                updateChart(e.target.value);
            });

            // Handle window resize
            window.addEventListener('resize', function() {
                Plotly.Plots.resize('aaChart');
            });
        });
    </script>
</body>
</html>
