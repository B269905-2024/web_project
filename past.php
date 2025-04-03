<?php
session_start();

if (!isset($_COOKIE['protein_search_session'])) {
    header("Location: home.php");
    exit();
}

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
    $stmt->execute([$_COOKIE['protein_search_session']]);
    $user = $stmt->fetch();

    if (!$user) {
        header("Location: home.php");
        exit();
    }

    $user_id = $user['user_id'];

    $stmt = $pdo->prepare("
        SELECT j.job_id, j.search_term, j.taxon, j.max_results, j.created_at,
               COUNT(s.sequence_id) as sequence_count
        FROM jobs j
        LEFT JOIN sequences s ON j.job_id = s.job_id
        WHERE j.user_id = ?
        GROUP BY j.job_id
        ORDER BY j.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $past_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Past Protein Searches</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="past.css">
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
                <a href="past.php" class="nav-link active"><span>Past Searches</span></a>
                <a href="example.php" class="nav-link"><span>Example Analysis</span></a>
                <a href="about.php" class="nav-link"><span>About</span></a>
                <a href="help.php" class="nav-link"><span>Help</span></a>
                <a href="credits.php" class="nav-link"><span>Credits</span></a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="past-searches-container glass">
            <h1>Your Past Protein Searches</h1>

            <?php if (empty($past_jobs)): ?>
                <div class="no-searches">
                    <p>You haven't performed any searches yet.</p>
                    <a href="home.php" class="submit-btn">Start a New Search</a>
                </div>
            <?php else: ?>
                <div class="jobs-list">
                    <?php foreach ($past_jobs as $job): ?>
                        <div class="job-card glass">
                            <div class="job-header">
                                <h3><?php echo htmlspecialchars($job['search_term']); ?> in <?php echo htmlspecialchars($job['taxon']); ?></h3>
                                <span class="job-date"><?php echo date('M j, Y g:i a', strtotime($job['created_at'])); ?></span>
                            </div>
                            <div class="job-details">
                                <div class="detail-item">
                                    <span class="detail-label">Max Results:</span>
                                    <span class="detail-value"><?php echo $job['max_results']; ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Sequences Found:</span>
                                    <span class="detail-value"><?php echo $job['sequence_count']; ?></span>
                                </div>
                            </div>
                            <div class="job-actions">
                                <a href="results.php?job_id=<?php echo $job['job_id']; ?>" class="action-btn view-btn">View Results</a>
                                <form method="post" action="delete_job.php" class="delete-form">
                                    <input type="hidden" name="job_id" value="<?php echo $job['job_id']; ?>">
                                    <button type="submit" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this search?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
    </script>
</body>
</html>
