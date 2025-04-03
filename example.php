<?php
// Set infinite session cookie
setcookie('protein_search_session', 'permanent_session', [
    'expires' => time() + (10 * 365 * 24 * 60 * 60),
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Configure session
ini_set('session.gc_maxlifetime', 60*60*24*365);
session_set_cookie_params(60*60*24*365);
session_start();

$job_id = 90; // Fixed example job ID
require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Get job info
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $job_info = $stmt->fetch();

    // Get sequences
    $stmt = $pdo->prepare("SELECT * FROM sequences WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $sequences = $stmt->fetchAll();
    $sequence_count = count($sequences);

    // Get conservation analysis
    $stmt = $pdo->prepare("SELECT * FROM conservation_jobs WHERE job_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$job_id]);
    $conservation_job = $stmt->fetch();

    if ($conservation_job) {
        $stmt = $pdo->prepare("SELECT position, entropy, plotcon_score FROM conservation_results WHERE conservation_id = ? ORDER BY position");
        $stmt->execute([$conservation_job['conservation_id']]);
        $conservation_results = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM conservation_alignments WHERE conservation_id = ?");
        $stmt->execute([$conservation_job['conservation_id']]);
        $conservation_alignments = $stmt->fetchAll();
    }

    // Prepare visualization data
    $entropy_data = [];
    $plotcon_data = [];
    $aa_data = [];

    if ($conservation_results) {
        $entropy_data = [
            'data' => [[
                'y' => array_column($conservation_results, 'entropy'),
                'type' => 'line',
                'name' => 'Entropy',
                'line' => ['color' => '#667292']
            ]],
            'layout' => [
                'title' => 'Shannon Entropy',
                'xaxis' => ['title' => 'Position'],
                'yaxis' => ['title' => 'Entropy (bits)']
            ]
        ];

        if (!empty(array_column($conservation_results, 'plotcon_score'))) {
            $plotcon_data = [
                'data' => [[
                    'y' => array_column($conservation_results, 'plotcon_score'),
                    'type' => 'line',
                    'name' => 'Conservation Score',
                    'line' => ['color' => '#8d9db6']
                ]],
                'layout' => [
                    'title' => 'Plotcon Conservation',
                    'xaxis' => ['title' => 'Position'],
                    'yaxis' => ['title' => 'Score']
                ]
            ];
        }
    }

    // Prepare amino acid data
    $amino_acids = str_split('ACDEFGHIKLMNPQRSTVWY');
    foreach ($sequences as $seq) {
        $counts = array_fill_keys($amino_acids, 0);
        foreach (str_split(strtoupper($seq['sequence'])) as $aa) {
            if (isset($counts[$aa])) $counts[$aa]++;
        }
        $total = strlen($seq['sequence']);
        foreach ($counts as $aa => $count) {
            $aa_data[$seq['ncbi_id']][$aa] = $total > 0 ? ($count / $total) * 100 : 0;
        }
    }
    $aa_data_json = json_encode($aa_data);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Example Protein Analysis</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="example.css">
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

    <!-- Navigation -->
    <nav class="top-bar glass">
        <div class="logo-nav-container">
            <a href="home.php" class="logo-tab">
                <img src="images/full_logo.png" alt="Protein Analysis Suite" class="logo">
                <span>Protein Analysis Suite</span>
            </a>
            <div class="nav-links">
                <a href="home.php" class="nav-link"><span>New Search</span></a>
                <a href="past.php" class="nav-link"><span>Past Searches</span></a>
                <a href="example.php" class="nav-link active"><span>Example Analysis</span></a>
                <a href="about.php" class="nav-link"><span>About</span></a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="example-container glass">
            <h1>Example Protein Analysis</h1>
            
            <!-- Horizontal Job Info -->
            <div class="job-info-horizontal">
                <div class="info-pair">
                    <span class="info-label">Search Term:</span>
                    <span class="info-value"><?= htmlspecialchars($job_info['search_term']) ?></span>
                </div>
                <div class="info-pair">
                    <span class="info-label">Taxon:</span>
                    <span class="info-value"><?= htmlspecialchars($job_info['taxon']) ?></span>
                </div>
                <div class="info-pair">
                    <span class="info-label">Sequences:</span>
                    <span class="info-value"><?= $sequence_count ?></span>
                </div>
                <div class="info-pair">
                    <span class="info-label">Date:</span>
                    <span class="info-value"><?= date('M j, Y g:i a', strtotime($job_info['created_at'])) ?></span>
                </div>
            </div>

            <!-- Conservation Analysis -->
            <section class="analysis-section">
                <h2>Conservation Analysis</h2>
                <div class="plots-row">
                    <?php if ($conservation_job): ?>
                        <div class="plot-wrapper">
                            <div id="entropyPlot"></div>
                        </div>
                        <?php if ($plotcon_data): ?>
                            <div class="plot-wrapper">
                                <div id="plotconPlot"></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <?php if ($conservation_alignments): ?>
                    <div class="alignment-container glass">
                        <h3>Aligned Sequences</h3>
                        <div class="scrollable-box">
                            <?php foreach ($conservation_alignments as $aln): ?>
                                <span class="sequence-header">><?= htmlspecialchars($aln['ncbi_id']) ?></span><br>
                                <?= chunk_split($aln['sequence'], 80, "<br>") ?><br><br>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Amino Acid Analysis -->
            <section class="analysis-section">
                <h2>Amino Acid Composition</h2>
                <div class="plot-wrapper">
                    <div id="aaChart"></div>
                </div>
            </section>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer glass">
        <div class="footer-content">
            <p>Created for Introduction to Website and Database Design @ University of Edinburgh</p>
            <a href="https://github.com/B269905-2024/web_project" target="_blank" class="github-link">
                <i class="fab fa-github"></i> View on GitHub
            </a>
        </div>
    </footer>

    <script>
        // Initialize particles
        particlesJS("particles-js", {
            particles: {
                number: { value: 80, density: { enable: true, value_area: 800 } },
                color: { value: "#8d9db6" },
                shape: { type: "circle" },
                opacity: { value: 0.5, random: true },
                size: { value: 3, random: true },
                line_linked: { 
                    enable: true, 
                    distance: 150, 
                    color: "#8d9db6", 
                    opacity: 0.2, 
                    width: 1 
                },
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

        // Dark mode toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        const body = document.body;
        
        darkModeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', body.classList.contains('dark-mode') ? 'enabled' : 'disabled');
            updatePlotStyles();
        });

        // Initialize dark mode
        if (!localStorage.getItem('darkMode') || localStorage.getItem('darkMode') === 'enabled') {
            body.classList.add('dark-mode');
        } else {
            body.classList.remove('dark-mode');
        }

        // Update plot styles for theme
        function updatePlotStyles() {
            const isDarkMode = body.classList.contains('dark-mode');
            const textColor = isDarkMode ? '#e1e8f0' : '#222222';
            const bgColor = 'rgba(0,0,0,0)';
            const gridColor = isDarkMode ? 'rgba(225, 232, 240, 0.2)' : 'rgba(34, 34, 34, 0.2)';
            
            const layout = {
                plot_bgcolor: bgColor,
                paper_bgcolor: bgColor,
                font: { color: textColor },
                xaxis: { gridcolor: gridColor, linecolor: textColor, tickcolor: textColor },
                yaxis: { gridcolor: gridColor, linecolor: textColor, tickcolor: textColor }
            };

            <?php if ($entropy_data): ?>
                Plotly.relayout('entropyPlot', layout);
            <?php endif; ?>
            
            <?php if ($plotcon_data): ?>
                Plotly.relayout('plotconPlot', layout);
            <?php endif; ?>
            
            if (typeof aaChart !== 'undefined') {
                Plotly.relayout('aaChart', layout);
            }
        }

        // Initialize plots
        <?php if ($entropy_data): ?>
            Plotly.newPlot("entropyPlot", <?= json_encode($entropy_data['data']) ?>, <?= json_encode($entropy_data['layout']) ?>);
        <?php endif; ?>

        <?php if ($plotcon_data): ?>
            Plotly.newPlot("plotconPlot", <?= json_encode($plotcon_data['data']) ?>, <?= json_encode($plotcon_data['layout']) ?>);
        <?php endif; ?>

        // Amino acid chart
        const aaData = <?= $aa_data_json ?>;
        let aaChart;

        function updateAAChart(selectedId) {
            const data = [{
                x: Object.keys(aaData[selectedId]),
                y: Object.values(aaData[selectedId]),
                type: 'bar',
                marker: { color: body.classList.contains('dark-mode') ? '#8d9db6' : '#667292' }
            }];

            const layout = {
                title: `Amino Acids: ${selectedId}`,
                xaxis: { title: 'Amino Acid' },
                yaxis: { title: 'Percentage (%)' },
                plot_bgcolor: 'rgba(0,0,0,0)',
                paper_bgcolor: 'rgba(0,0,0,0)'
            };

            if (aaChart) {
                Plotly.react('aaChart', data, layout);
            } else {
                aaChart = Plotly.newPlot('aaChart', data, layout);
            }
            updatePlotStyles();
        }

        // Initialize AA chart
        updateAAChart(Object.keys(aaData)[0]);

        // Sequence selector
        const sequenceSelector = document.createElement('select');
        sequenceSelector.className = 'sequence-selector glass';
        Object.keys(aaData).forEach(id => {
            const option = document.createElement('option');
            option.value = id;
            option.textContent = id;
            sequenceSelector.appendChild(option);
        });
        sequenceSelector.addEventListener('change', (e) => {
            updateAAChart(e.target.value);
        });
        document.querySelector('#aaChart').before(sequenceSelector);

        // Responsive plots
        window.addEventListener('resize', () => {
            <?php if ($entropy_data): ?> Plotly.Plots.resize('entropyPlot'); <?php endif; ?>
            <?php if ($plotcon_data): ?> Plotly.Plots.resize('plotconPlot'); <?php endif; ?>
            if (typeof aaChart !== 'undefined') Plotly.Plots.resize('aaChart');
        });
    </script>
</body>
</html>
