<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help - Protein Sequence Analysis Tool</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="help.css">
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
                <a href="help.php" class="nav-link active"><span>Help</span></a>
                <a href="credits.php" class="nav-link"><span>Credits</span></a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="help-container glass">
            <h1>Biological Help & Context</h1>
            <p class="intro">This guide explains the biological rationale behind the tools in this protein sequence analysis platform.</p>

            <div class="help-section">
                <h2>1. Protein Sequence Retrieval</h2>
                <div class="concept">
                    <h3>Why search by protein/gene name and taxon?</h3>
                    <p>Proteins often have conserved functions across species, but their sequences can vary. Searching within a specific taxonomic group helps:</p>
                    <ul>
                        <li>Identify orthologs (same gene in different species)</li>
                        <li>Study evolutionary conservation</li>
                        <li>Find species-specific adaptations</li>
                    </ul>
                    <div class="example">
                        Example: Searching for "hemoglobin" in Aves (birds) will retrieve avian-specific hemoglobin variants that may have adaptations for high-altitude oxygen binding.
                    </div>
                </div>
            </div>

            <div class="help-section">
                <h2>2. Conservation Analysis</h2>
                <div class="concept">
                    <h3>Shannon Entropy and Conservation Scores</h3>
                    <p>These metrics help identify functionally important regions:</p>
                    <ul>
                        <li><span class="important">Low entropy positions</span> are evolutionarily conserved and often critical for structure/function</li>
                        <li><span class="important">High entropy positions</span> may indicate regions under less selective pressure</li>
                        <li>Window-based analysis smooths noise while highlighting conserved domains</li>
                    </ul>
                    <div class="example">
                        Example: In a multiple sequence alignment of cytochrome c, the heme-binding residues will show very low entropy (high conservation).
                    </div>
                </div>
            </div>

            <div class="help-section">
                <h2>3. Motif Analysis</h2>
                <div class="concept">
                    <h3>Why identify protein motifs?</h3>
                    <p>Motifs are short, conserved sequence patterns that often:</p>
                    <ul>
                        <li>Represent functional domains (e.g., kinase domains)</li>
                        <li>Indicate post-translational modification sites</li>
                        <li>Serve as binding sites for other molecules</li>
                        <li>Help classify proteins into families</li>
                    </ul>
                    <div class="example">
                        Example: The PROSITE database identifies motifs like the "ATP/GTP-binding site motif A" (P-loop) that's crucial for nucleotide binding in many enzymes.
                    </div>
                </div>
            </div>

            <div class="help-section">
                <h2>4. Amino Acid Composition</h2>
                <div class="concept">
                    <h3>What does composition tell us?</h3>
                    <p>Amino acid percentages can reveal:</p>
                    <ul>
                        <li><span class="important">Hydrophobicity</span> - Important for membrane proteins</li>
                        <li><span class="important">Charge distribution</span> - Affects protein interactions</li>
                        <li><span class="important">Structural tendencies</span> - e.g., Proline-rich regions often form turns</li>
                        <li><span class="important">Functional specialization</span> - e.g., Histidine-rich regions in metal-binding proteins</li>
                    </ul>
                    <div class="example">
                        Example: A protein with >20% hydrophobic residues (Val, Ile, Leu) might be membrane-associated, while one with >25% charged residues (Asp, Glu, Lys, Arg) is likely soluble.
                    </div>
                </div>
            </div>

            <div class="help-section">
                <h2>Interpretation Guidelines</h2>
                <div class="concept">
                    <h3>How to approach your results:</h3>
                    <ol>
                        <li><span class="important">Start with conservation analysis</span> to identify potentially important regions</li>
                        <li><span class="important">Check for known motifs</span> that might explain function</li>
                        <li><span class="important">Examine composition</span> for structural insights</li>
                        <li><span class="important">Compare across species</span> to identify conserved vs. variable features</li>
                    </ol>
                    <p>Remember: Computational predictions should be validated experimentally when possible.</p>
                </div>
            </div>

            <div class="help-section">
                <h2>Biological Databases Used</h2>
                <p>This tool integrates with several key biological resources:</p>
                <ul>
                    <li><strong>NCBI Protein Database</strong> - For sequence retrieval</li>
                    <li><strong>PROSITE/EMBL-EBI</strong> - For motif identification</li>
                    <li><strong>Conserved Domain Database (CDD)</strong> - For domain annotation</li>
                </ul>
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
    </script>
</body>
</html>
