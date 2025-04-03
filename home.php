<?php
session_start();

if (!isset($_COOKIE['protein_search_session'])) {
    $session_id = bin2hex(random_bytes(32));
    setcookie('protein_search_session', $session_id, time() + 86400 * 30, "/");
    $_COOKIE['protein_search_session'] = $session_id;
}

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
    $stmt->execute([$_COOKIE['protein_search_session']]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (session_id) VALUES (?)");
        $stmt->execute([$_COOKIE['protein_search_session']]);
        $user_id = $pdo->lastInsertId();
    } else {
        $user_id = $user['user_id'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $search_term = trim($_POST['search_term']);
        $taxon = trim($_POST['taxon']);
        $max_results = min((int)$_POST['max_results'], 100);

        $stmt = $pdo->prepare("INSERT INTO jobs (user_id, search_term, taxon, max_results, status) VALUES (?, ?, ?, ?, 'completed')");
        $stmt->execute([$user_id, $search_term, $taxon, $max_results]);
        $job_id = $pdo->lastInsertId();

        $search_term_encoded = urlencode($search_term);
        $taxon_encoded = urlencode($taxon);

        $search_url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=protein&term={$search_term_encoded}+AND+{$taxon_encoded}[Organism]&retmax={$max_results}&api_key={$ncbi_api_key}";
        $search_response = file_get_contents($search_url);

        preg_match_all('/<Id>([0-9]+)<\/Id>/', $search_response, $matches);
        $ids = $matches[1] ?? [];

        if (!empty($ids)) {
            $id_list = implode(',', $ids);
            $fetch_url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=protein&id={$id_list}&rettype=fasta&retmode=text&api_key={$ncbi_api_key}";
            $fasta_response = file_get_contents($fetch_url);

            $current_id = $current_desc = $current_seq = '';
            foreach (explode("\n", $fasta_response) as $line) {
                if (strpos($line, '>') === 0) {
                    if ($current_id !== '') {
                        $stmt = $pdo->prepare("INSERT INTO sequences (job_id, ncbi_id, description, sequence) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$job_id, $current_id, $current_desc, $current_seq]);
                    }
                    $header = substr($line, 1);
                    $header_parts = explode(' ', $header, 2);
                    $current_id = $header_parts[0];
                    $current_desc = $header_parts[1] ?? '';
                    $current_seq = '';
                } else {
                    $current_seq .= trim($line);
                }
            }
            if ($current_id !== '') {
                $stmt->execute([$job_id, $current_id, $current_desc, $current_seq]);
            }
        }

        header("Location: results.php?job_id=$job_id");
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
    <title>Protein Sequence Search</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="home.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/ngl@2.0.0-dev.38/dist/ngl.js"></script>
</head>
<body class="dark-mode">
    <!-- Cookie Consent Modal -->
    <div id="cookieConsent" class="cookie-consent">
        <div class="cookie-overlay"></div>
        <div class="cookie-content glass">
            <span class="cookie-icon">🍪</span>
            <h3>We use cookies!</h3>
            <p>This website uses cookies to remember your previous protein searches and make your experience smoother.</p>
            <p>By continuing, you agree to our use of cookies. Do you consent?</p>
            <div class="cookie-buttons">
                <button id="acceptCookies" class="cookie-btn accept-btn">Accept Cookies</button>
                <button id="rejectCookies" class="cookie-btn reject-btn">Reject</button>
            </div>
        </div>
    </div>

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
                <a href="home.php" class="nav-link active"><span>New Search</span></a>
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
        <div class="form-container glass">
            <h1>Protein Sequence Search</h1>

            <form method="post" class="analysis-form">
                <div class="form-group">
                    <label for="search_term">Protein or Gene Name:</label>
                    <input type="text" id="search_term" name="search_term" required placeholder="e.g., glucose-6-phosphatase, ABC transporters">
                </div>

                <div class="form-group">
                    <label for="taxon">Taxonomic Group:</label>
                    <input type="text" id="taxon" name="taxon" required placeholder="e.g., Aves, Mammalia, Rodentia">
                </div>

                <div class="form-group">
                    <label for="max_results">Maximum Results (1-100):</label>
                    <input type="number" id="max_results" name="max_results" min="1" max="100" value="10" required>
                </div>

                <button type="submit" class="submit-btn">Search</button>
            </form>

            <!-- Analysis Tools Section -->
            <div class="tools-section">
                <h2>Bioinformatics Analysis Tools</h2>
                <p class="tools-description">Our platform provides comprehensive computational tools for protein characterization:</p>

                <div class="analysis-cards">
                    <div class="card">
                        <div class="card-front">
                            <h3>Conservation Analysis</h3>
                        </div>
                        <div class="card-back">
                            <p>Identifies evolutionarily conserved protein regions using Shannon entropy metrics and multiple sequence alignment.</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-front">
                            <h3>Motif Scan</h3>
                        </div>
                        <div class="card-back">
                            <p>Detects functional protein motifs using PROSITE patterns and regular expressions.</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-front">
                            <h3>Content Analysis</h3>
                        </div>
                        <div class="card-back">
                            <p>Quantifies amino acid composition to reveal structural and functional protein properties.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="viewer-container glass">
            <div class="viewer-header">
                <h2>Protein Structure Visualization</h2>
                <p class="viewer-description">ABC Transporter (PDB: 6NNG | Chain F) - This is the first sequence you would find when searching for ABC transporters in Aves. The structure shown is an example of the type of results you can expect from your searches.</p>
            </div>
            <div id="protein-viewer"></div>
            <div class="reference">
                <p>Kumar, G., Wang, Y., Li, W., & White, S. W. (2019). Tubulin-RB3_SLD-TTL in complex with compound DJ95 [Protein Data Bank entry 6NNG]. RCSB Protein Data Bank. https://doi.org/10.2210/pdb6nng/pdb<br>
                (Original work published July 10, 2019)</p>
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
        // [Previous JavaScript remains the same]
        // Cookie Consent Functionality
        const cookieConsent = document.getElementById('cookieConsent');
        const acceptCookies = document.getElementById('acceptCookies');
        const rejectCookies = document.getElementById('rejectCookies');
        const body = document.body;

        // Check if cookies are accepted
        if (!localStorage.getItem('cookiesAccepted')) {
            document.getElementById('cookieConsent').style.display = 'flex';
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        }

        // Handle cookie acceptance
        acceptCookies.addEventListener('click', function() {
            localStorage.setItem('cookiesAccepted', 'true');
            hideCookieConsent();
        });

        // Handle cookie rejection
        rejectCookies.addEventListener('click', function() {
            localStorage.setItem('cookiesRejected', 'true');
            hideCookieConsent();
        });

        function hideCookieConsent() {
            document.getElementById('cookieConsent').style.display = 'none';
            document.body.style.overflow = 'auto'; // Re-enable scrolling
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

        // Initialize NGL Viewer with 6NNG structure
        const stage = new NGL.Stage("protein-viewer", {
            backgroundColor: body.classList.contains('dark-mode') ? "black" : "white"
        });
        let component;

        stage.loadFile("https://files.rcsb.org/download/6NNG.pdb").then(function (comp) {
            component = comp;
            // Show only Chain F
            const selection = new NGL.Selection(":F");
            component.addRepresentation("cartoon", {
                color: "residueindex",
                sele: selection.string
            });
            component.autoView();
            component.setSpin(true);
        });

        // Dark Mode Toggle
        const darkModeToggle = document.getElementById('darkModeToggle');

        darkModeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');

            // Update NGL viewer background
            stage.setParameters({
                backgroundColor: body.classList.contains('dark-mode') ? "black" : "white"
            });

            // Save user preference
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
