<?php
if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM sequences WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $sequences = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['download'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="protein_sequences_'.$job_id.'.fasta"');

        foreach ($sequences as $seq) {
            echo ">{$seq['ncbi_id']} {$seq['description']}\n";
            echo chunk_split($seq['sequence'], 80, "\n");
        }
        exit();
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results | Protein Analysis Suite</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="results.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                <a href="home.php" class="nav-link">New Search</a>
                <a href="past.php" class="nav-link">Past Searches</a>
                <a href="example.php" class="nav-link">Example Analysis</a>
                <a href="about.php" class="nav-link">About</a>
                <a href="help.php" class="nav-link">Help</a>
                <a href="credits.php" class="nav-link">Credits</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="results-container glass">
            <!-- Results Header -->
            <div class="results-header">
                <h1>Search Results</h1>
                <div class="job-meta">
                    <span><?php echo htmlspecialchars($job['search_term']); ?></span>
                    <span><?php echo htmlspecialchars($job['taxon']); ?></span>
                    <span><?php echo count($sequences); ?> sequences</span>
                    <span><?php echo date('M j, Y g:i a', strtotime($job['created_at'])); ?></span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="results.php?job_id=<?php echo $job_id; ?>&download=1" class="action-btn download-btn">Download FASTA</a>
                <div class="analysis-buttons">
                    <a href="conservation.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis-btn">Conservation</a>
                    <a href="motifs.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis-btn">Motifs</a>
                    <a href="content.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis-btn">Amino Acids</a>
                </div>
                <a href="past.php" class="action-btn back-btn">Past Searches</a>
            </div>

            <!-- Sequence Viewer -->
            <div class="sequence-viewer-container">
                <div class="sequence-controls">
                    <div class="view-options">
                        <button class="view-option active" data-view="full">Full</button>
                        <button class="view-option" data-view="compact">Compact</button>
                        <button class="view-option" data-view="headers">Headers Only</button>
                    </div>
                </div>

                <div class="sequence-scroller" id="sequenceScroller">
                    <?php if (!empty($sequences)): ?>
                        <?php foreach ($sequences as $seq): ?>
                            <div class="sequence-card glass">
                                <div class="sequence-header">
                                    <h3>
                                        <?php echo htmlspecialchars($seq['ncbi_id']); ?>
                                        <span class="sequence-length"><?php echo strlen($seq['sequence']); ?> aa</span>
                                    </h3>
                                    <p class="sequence-desc"><?php echo htmlspecialchars($seq['description']); ?></p>
                                </div>
                                <div class="sequence-content">
                                    <div class="sequence-data">
                                        <?php echo chunk_split($seq['sequence'], 80, "<br>"); ?>
                                    </div>
                                    <div class="sequence-actions">
                                        <button class="copy-btn" data-sequence="<?php echo htmlspecialchars($seq['ncbi_id']); ?>">Copy</button>
                                        <button class="expand-btn">Expand</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-sequences glass">
                            <p>No sequences were found for this search.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bottom Action Buttons -->
            <div class="action-buttons bottom-actions">
                <a href="results.php?job_id=<?php echo $job_id; ?>&download=1" class="action-btn download-btn">Download FASTA</a>
                <div class="analysis-buttons">
                    <a href="conservation.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis-btn">Conservation</a>
                    <a href="motifs.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis-btn">Motifs</a>
                    <a href="content.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis-btn">Amino Acids</a>
                </div>
                <a href="past.php" class="action-btn back-btn">Past Searches</a>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer glass">
        <div class="footer-content">
            <p>Created as part of the postgraduate course Introduction to Website and Database Design @ the University of Edinburgh</p>
            <a href="https://github.com/B269905-2024/web_project" target="_blank" class="github-link">
                <i class="fab fa-github"></i> View the source code on GitHub
            </a>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Initialize particles.js background
        function initParticles() {
            particlesJS("particles-js", {
                particles: {
                    number: { 
                        value: 80, 
                        density: { 
                            enable: true, 
                            value_area: 800 
                        } 
                    },
                    color: { 
                        value: body.classList.contains('dark-mode') ? "#8d9db6" : "#667292"
                    },
                    shape: { 
                        type: "circle" 
                    },
                    opacity: { 
                        value: 0.5, 
                        random: true 
                    },
                    size: { 
                        value: 3, 
                        random: true 
                    },
                    line_linked: { 
                        enable: true, 
                        distance: 150, 
                        color: body.classList.contains('dark-mode') ? "#8d9db6" : "#667292",
                        opacity: 0.2, 
                        width: 1 
                    },
                    move: { 
                        enable: true, 
                        speed: 2, 
                        direction: "none", 
                        random: true, 
                        straight: false, 
                        out_mode: "out" 
                    }
                },
                interactivity: {
                    detect_on: "canvas",
                    events: {
                        onhover: { 
                            enable: true, 
                            mode: "grab" 
                        },
                        onclick: { 
                            enable: true, 
                            mode: "push" 
                        }
                    }
                }
            });
        }

        // Dark Mode Toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        const body = document.body;

        darkModeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            
            // Update particles.js colors based on mode
            if (typeof pJSDom !== 'undefined' && pJSDom.length > 0) {
                const particles = pJSDom[0].pJS;
                const isDarkMode = body.classList.contains('dark-mode');
                
                particles.particles.color.value = isDarkMode ? "#8d9db6" : "#667292";
                particles.particles.line_linked.color = isDarkMode ? "#8d9db6" : "#667292";
                particles.pJS.fn.particlesRefresh();
            }

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

        // Initialize particles after checking dark mode
        document.addEventListener('DOMContentLoaded', initParticles);

        // View Options
        document.querySelectorAll('.view-option').forEach(option => {
            option.addEventListener('click', () => {
                document.querySelectorAll('.view-option').forEach(btn => btn.classList.remove('active'));
                option.classList.add('active');
                
                const viewType = option.dataset.view;
                document.querySelectorAll('.sequence-card').forEach(card => {
                    card.classList.remove('full-view', 'compact-view', 'headers-view');
                    card.classList.add(`${viewType}-view`);
                });
            });
        });

        // Copy Sequence Functionality
        document.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const sequenceId = btn.dataset.sequence;
                const sequenceCard = btn.closest('.sequence-card');
                const sequenceText = sequenceCard.querySelector('.sequence-data').textContent.replace(/\s+/g, '');
                
                navigator.clipboard.writeText(sequenceText).then(() => {
                    const originalText = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(() => {
                        btn.textContent = originalText;
                    }, 2000);
                });
            });
        });

        // Expand/Collapse Functionality
        document.querySelectorAll('.expand-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const sequenceCard = btn.closest('.sequence-card');
                sequenceCard.classList.toggle('expanded');
                
                if (sequenceCard.classList.contains('expanded')) {
                    btn.textContent = 'Collapse';
                } else {
                    btn.textContent = 'Expand';
                }
            });
        });

    </script>
</body>
</html>
