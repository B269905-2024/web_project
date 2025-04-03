<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits - Protein Sequence Analysis Tool</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="credits.css">
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
                <a href="credits.php" class="nav-link active"><span>Credits</span></a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="credits-container glass">
            <h1>Project Credits and Attributions</h1>

            <div class="credit-section">
                <h2>Code Sources and Libraries</h2>

                <div class="credit-item">
                    <div class="credit-title">Particles.js</div>
                    <div class="credit-description">Used for the interactive background animation throughout the site</div>
                    <a href="https://github.com/VincentGarreau/particles.js/" class="credit-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> https://github.com/VincentGarreau/particles.js/
                    </a>
                    <div class="credit-license">MIT License</div>
                </div>

                <div class="credit-item">
                    <div class="credit-title">Font Awesome</div>
                    <div class="credit-description">Provides the icon set used for UI elements</div>
                    <a href="https://fontawesome.com/" class="credit-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> https://fontawesome.com/
                    </a>
                    <div class="credit-license">Free License (with attribution)</div>
                </div>

                <div class="credit-item">
                    <div class="credit-title">EMBOSS Suite</div>
                    <div class="credit-description">Used for motif analysis through patmatmotifs</div>
                    <a href="https://emboss.sourceforge.net/" class="credit-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> https://emboss.sourceforge.net/
                    </a>
                    <div class="credit-license">GPL License</div>
                </div>
            </div>

            <div class="credit-section">
                <h2>AI Tools Used</h2>

                <div class="credit-item">
                    <div class="credit-title">GitHub Copilot</div>
                    <div class="credit-description">Used for generating initial code structure and boilerplate code</div>
                    <a href="https://github.com/features/copilot" class="credit-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> https://github.com/features/copilot
                    </a>
                </div>

                <div class="credit-item">
                    <div class="credit-title">ChatGPT</div>
                    <div class="credit-description">Used for debugging assistance and code optimization suggestions</div>
                    <a href="https://openai.com/chatgpt" class="credit-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> https://openai.com/chatgpt
                    </a>
                </div>
            </div>

            <div class="credit-section">
                <h2>Data Sources</h2>

                <div class="credit-item">
                    <div class="credit-title">NCBI Protein Database</div>
                    <div class="credit-description">Primary source for protein sequence data</div>
                    <a href="https://www.ncbi.nlm.nih.gov/protein" class="credit-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> https://www.ncbi.nlm.nih.gov/protein
                    </a>
                    <div class="credit-license">Public domain data</div>
                </div>

                <div class="credit-item">
                    <div class="credit-title">PROSITE Database</div>
                    <div class="credit-description">Source for protein motif patterns</div>
                    <a href="https://prosite.expasy.org/" class="credit-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> https://prosite.expasy.org/
                    </a>
                    <div class="credit-license">Free academic use</div>
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
