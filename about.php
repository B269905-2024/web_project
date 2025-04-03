<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About - Protein Sequence Analysis Tool</title>
    <link rel="icon" href="images/logo.png" type="image/png">
    <link rel="stylesheet" href="general.css">
    <link rel="stylesheet" href="about.css">
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
                <a href="about.php" class="nav-link active"><span>About</span></a>
                <a href="help.php" class="nav-link"><span>Help</span></a>
                <a href="credits.php" class="nav-link"><span>Credits</span></a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="about-container glass">
            <h1>About the Protein Sequence Analysis Tool</h1>

            <h2>Implementation Overview</h2>
            <p class="overview">The system follows a modular PHP architecture with a MySQL backend, implementing session-based user tracking and job processing. The frontend utilizes vanilla JavaScript for dynamic interactions while maintaining progressive enhancement. Database operations are normalized across 11 tables with proper foreign key relationships to ensure data integrity. Background processing is handled through shell scripts triggered by PHP, allowing long-running analysis tasks to complete asynchronously. The architecture separates concerns between data collection (NCBI queries), analysis (EMBOSS/Clustal tools), and visualization (Plotly.js), with each component storing results in dedicated database tables. AJAX endpoints provide real-time status updates without page reloads, and the system includes comprehensive error handling at both the application and database levels.</p>

            <h2>System Architecture</h2>
            <div class="diagram">
├── config.php
│
├── CSS Files
│   ├── about.css
│   ├── conservation.css
│   ├── content.css
│   ├── credits.css
│   ├── example.css
│   ├── general.css
│   ├── help.css
│   ├── home.css
│   ├── motifs.css
│   ├── past.css
│   └── results.css
│
├── PHP Scripts
│   ├── about.php
│   ├── conservation.php
│   ├── content.php
│   ├── credits.php
│   ├── delete_job.php
│   ├── download.php
│   ├── download_conservation.php
│   ├── example.php
│   ├── help.php
│   ├── home.php
│   ├── motifs.php
│   ├── past.php
│   └── results.php
│
├── Shell Scripts
│   ├── run_conservation.sh
│   ├── run_motifs.sh
│   └── run_search.sh
│
└── images/
    ├── logo.png
    └── full_logo.png
            </div>

            <h2>Database Schema</h2>
            <button type="button" class="collapsible">Show/Hide Database Schema</button>
            <div class="collapsible-content">
                <div class="diagram-container">
                    <div class="diagram">
Database Schema:
┌───────────────────────────────┐
│            users              │
├───────────────┬───────────────┤
│ user_id (PK)  │ session_id    │
└───────┬───────┴───────────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│            jobs               │
├───────────────┬───────────────┤
│ job_id (PK)   │ user_id (FK)  │
└───────┬───────┴───────────────┘
        │
        │ 1:n
        ├───────────────────────┐
        ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│   sequences     │    │   temp_fasta    │
├───────┬─────────┤    ├───────┬─────────┤
│ seq_id│ job_id  │    │ id    │ job_id  │
└───────┴─────────┘    └───────┴─────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│        motif_results          │
├───────────────┬───────────────┤
│ result_id (PK)│ sequence_id   │
└───────┬───────┴───────────────┘
        │
        │ n:1
        ▼
┌───────────────────────────────┐
│        motif_jobs             │
├───────────────┬───────────────┤
│ motif_id (PK) │ job_id (FK)   │
└───────┬───────┴───────────────┘
        │
        │ 1:1
        ▼
┌───────────────────────────────┐
│       motif_reports           │
├───────────────┬───────────────┤
│ report_id (PK)│ motif_id (FK) │
└───────────────┴───────────────┘

┌───────────────────────────────┐
│    conservation_jobs          │
├───────────────┬───────────────┤
│ cons_id (PK)  │ job_id (FK)   │
└───────┬───────┴───────────────┘
        │
        │ 1:1
        ├───────────────────────┐
        ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│ cons_alignments │    │ cons_reports    │
├───────┬─────────┤    ├───────┬─────────┤
│ aln_id│ cons_id │    │ rep_id│ cons_id │
└───────┴─────────┘    └───────┴─────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│    conservation_results       │
├───────────────┬───────────────┤
│ result_id (PK)│ cons_id (FK)  │
└───────────────┴───────────────┘
                    </div>
                </div>
            </div>

            <h3>Database Tables</h3>
            <div class="selector-container glass">
                <select id="tableSelector" onchange="showTableInfo()">
                    <option value="">-- Select a table to view details --</option>
                    <option value="users">users</option>
                    <option value="jobs">jobs</option>
                    <option value="sequences">sequences</option>
                    <option value="motif_jobs">motif_jobs</option>
                    <option value="motif_results">motif_results</option>
                    <option value="motif_reports">motif_reports</option>
                    <option value="conservation_jobs">conservation_jobs</option>
                    <option value="conservation_alignments">conservation_alignments</option>
                    <option value="conservation_results">conservation_results</option>
                    <option value="conservation_reports">conservation_reports</option>
                    <option value="temp_fasta">temp_fasta</option>
                </select>
            </div>

            <div id="tableInfo" class="info-container glass" style="display:none;">
                <h3 id="tableTitle"></h3>
                <div id="tableDetails"></div>
            </div>

            <h3>Script Descriptions</h3>
            <div class="selector-container glass">
                <select id="scriptSelector" onchange="showScriptInfo()">
                    <option value="">-- Select a script to learn more --</option>
                    <option value="home.php">home.php</option>
                    <option value="run_search.sh">run_search.sh</option>
                    <option value="results.php">results.php</option>
                    <option value="conservation.php">conservation.php</option>
                    <option value="run_conservation.sh">run_conservation.sh</option>
                    <option value="motifs.php">motifs.php</option>
                    <option value="run_motifs.sh">run_motifs.sh</option>
                    <option value="content.php">content.php</option>
                    <option value="past.php">past.php</option>
                    <option value="delete_job.php">delete_job.php</option>
                    <option value="example.php">example.php</option>
                    <option value="download.php">download.php</option>
                </select>
            </div>

            <div id="scriptInfo" class="info-container glass" style="display:none;">
                <h3 id="scriptTitle"></h3>
                <ul id="scriptDetails"></ul>
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

        // Table data
        const tableData = {
            "users": {
                "fields": [
                    {"name": "user_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "session_id", "type": "varchar(255)", "key": "Unique"},
                    {"name": "created_at", "type": "timestamp", "key": ""}
                ],
                "description": "Stores user session information for tracking search history"
            },
            "jobs": {
                "fields": [
                    {"name": "job_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "user_id", "type": "int", "key": "Foreign Key (users)"},
                    {"name": "search_term", "type": "varchar(255)", "key": ""},
                    {"name": "taxon", "type": "varchar(255)", "key": ""},
                    {"name": "max_results", "type": "int", "key": ""},
                    {"name": "status", "type": "enum", "key": ""},
                    {"name": "error_message", "type": "text", "key": ""},
                    {"name": "created_at", "type": "timestamp", "key": ""},
                    {"name": "fasta_data", "type": "longtext", "key": ""},
                    {"name": "motif_results", "type": "longtext", "key": ""},
                    {"name": "motif_report", "type": "longtext", "key": ""}
                ],
                "description": "Main table storing search job information and status"
            },
            "sequences": {
                "fields": [
                    {"name": "sequence_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "job_id", "type": "int", "key": "Foreign Key (jobs)"},
                    {"name": "ncbi_id", "type": "varchar(50)", "key": ""},
                    {"name": "description", "type": "text", "key": ""},
                    {"name": "sequence", "type": "text", "key": ""}
                ],
                "description": "Stores individual protein sequences from NCBI searches"
            },
            "motif_jobs": {
                "fields": [
                    {"name": "motif_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "job_id", "type": "int", "key": "Foreign Key (jobs)"},
                    {"name": "created_at", "type": "timestamp", "key": ""}
                ],
                "description": "Tracks motif analysis jobs linked to search jobs"
            },
            "motif_results": {
                "fields": [
                    {"name": "result_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "motif_id", "type": "int", "key": "Foreign Key (motif_jobs)"},
                    {"name": "sequence_id", "type": "int", "key": "Foreign Key (sequences)"},
                    {"name": "motif_name", "type": "varchar(255)", "key": ""},
                    {"name": "motif_id_code", "type": "varchar(20)", "key": ""},
                    {"name": "description", "type": "text", "key": ""},
                    {"name": "start_pos", "type": "int", "key": ""},
                    {"name": "end_pos", "type": "int", "key": ""},
                    {"name": "score", "type": "decimal(10,3)", "key": ""},
                    {"name": "p_value", "type": "decimal(10,5)", "key": ""}
                ],
                "description": "Stores individual motif matches found in protein sequences"
            },
            "motif_reports": {
                "fields": [
                    {"name": "report_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "motif_id", "type": "int", "key": "Foreign Key (motif_jobs)"},
                    {"name": "report_text", "type": "text", "key": ""},
                    {"name": "created_at", "type": "timestamp", "key": ""}
                ],
                "description": "Contains summary reports for motif analysis jobs"
            },
            "conservation_jobs": {
                "fields": [
                    {"name": "conservation_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "job_id", "type": "int", "key": "Foreign Key (jobs)"},
                    {"name": "window_size", "type": "int", "key": ""},
                    {"name": "status", "type": "enum", "key": ""},
                    {"name": "created_at", "type": "timestamp", "key": ""},
                    {"name": "updated_at", "type": "timestamp", "key": ""}
                ],
                "description": "Tracks conservation analysis jobs linked to search jobs"
            },
            "conservation_alignments": {
                "fields": [
                    {"name": "alignment_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "conservation_id", "type": "int", "key": "Foreign Key (conservation_jobs)"},
                    {"name": "ncbi_id", "type": "varchar(50)", "key": ""},
                    {"name": "sequence", "type": "text", "key": ""},
                    {"name": "created_at", "type": "timestamp", "key": ""}
                ],
                "description": "Stores aligned sequences used for conservation analysis"
            },
            "conservation_results": {
                "fields": [
                    {"name": "result_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "conservation_id", "type": "int", "key": "Foreign Key (conservation_jobs)"},
                    {"name": "position", "type": "int", "key": ""},
                    {"name": "entropy", "type": "float", "key": ""},
                    {"name": "plotcon_score", "type": "float", "key": ""},
                    {"name": "created_at", "type": "timestamp", "key": ""}
                ],
                "description": "Contains position-specific conservation scores"
            },
            "conservation_reports": {
                "fields": [
                    {"name": "report_id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "conservation_id", "type": "int", "key": "Foreign Key (conservation_jobs)"},
                    {"name": "report_text", "type": "text", "key": ""},
                    {"name": "mean_entropy", "type": "float", "key": ""},
                    {"name": "max_entropy", "type": "float", "key": ""},
                    {"name": "min_entropy", "type": "float", "key": ""},
                    {"name": "max_position", "type": "int", "key": ""},
                    {"name": "min_position", "type": "int", "key": ""},
                    {"name": "created_at", "type": "timestamp", "key": ""}
                ],
                "description": "Contains summary reports for conservation analysis"
            },
            "temp_fasta": {
                "fields": [
                    {"name": "id", "type": "int (PK)", "key": "Primary Key"},
                    {"name": "job_id", "type": "int", "key": "Foreign Key (jobs)"},
                    {"name": "fasta_content", "type": "longtext", "key": ""},
                    {"name": "created_at", "type": "datetime", "key": ""}
                ],
                "description": "Temporary storage for FASTA data during processing"
            }
        };

        function showTableInfo() {
            const selector = document.getElementById('tableSelector');
            const tableName = selector.value;
            const infoDiv = document.getElementById('tableInfo');

            if (!tableName) {
                infoDiv.style.display = 'none';
                return;
            }

            document.getElementById('tableTitle').textContent = tableName;
            const detailsDiv = document.getElementById('tableDetails');
            detailsDiv.innerHTML = '';

            // Add description
            const desc = document.createElement('p');
            desc.textContent = tableData[tableName].description;
            detailsDiv.appendChild(desc);

            // Create table
            const table = document.createElement('table');
            table.className = 'database-table';

            // Create header
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            ['Field', 'Type', 'Key'].forEach(text => {
                const th = document.createElement('th');
                th.textContent = text;
                headerRow.appendChild(th);
            });
            thead.appendChild(headerRow);
            table.appendChild(thead);

            // Create body
            const tbody = document.createElement('tbody');
            tableData[tableName].fields.forEach(field => {
                const row = document.createElement('tr');
                [field.name, field.type, field.key].forEach(text => {
                    const td = document.createElement('td');
                    td.textContent = text;
                    row.appendChild(td);
                });
                tbody.appendChild(row);
            });
            table.appendChild(tbody);

            detailsDiv.appendChild(table);
            infoDiv.style.display = 'block';
        }

        const scriptData = {
            "home.php": [
                "Main entry point for the application",
                "Provides search form for protein sequences",
                "Creates user session if none exists",
                "Initiates search jobs via run_search.sh"
            ],
            "run_search.sh": [
                "Background script for NCBI protein searches",
                "Queries NCBI protein database using E-utilities",
                "Processes FASTA format results",
                "Stores sequences in database"
            ],
            "results.php": [
                "Displays search results from NCBI",
                "Shows protein sequences in FASTA format",
                "Provides links to analysis tools",
                "Offers FASTA download option"
            ],
            "conservation.php": [
                "Conservation analysis interface",
                "Calculates Shannon entropy and conservation scores",
                "Visualizes results with Plotly.js",
                "Links to run_conservation.sh for processing"
            ],
            "run_conservation.sh": [
                "Performs conservation analysis",
                "Uses Clustal Omega for alignment",
                "Calculates entropy and plotcon scores",
                "Stores results in database"
            ],
            "motifs.php": [
                "Motif discovery interface",
                "Uses EMBOSS patmatmotifs",
                "Displays PROSITE motif matches",
                "Provides detailed sequence views"
            ],
            "run_motifs.sh": [
                "Executes motif analysis",
                "Processes sequences with patmatmotifs",
                "Parses PROSITE motif results",
                "Stores motif positions and details"
            ],
            "content.php": [
                "Amino acid composition analysis",
                "Calculates percentage of each amino acid",
                "Interactive visualization with Plotly.js",
                "Generates downloadable reports"
            ],
            "past.php": [
                "Displays user's search history",
                "Shows job status and sequence counts",
                "Links to previous results",
                "Provides delete functionality"
            ],
            "delete_job.php": [
                "Handles job deletion",
                "Removes job and associated sequences",
                "Cleans up related analysis data",
                "Returns to past.php after completion"
            ],
            "example.php": [
                "Permanent example analysis",
                "Shows all analysis types pre-computed",
                "Uses fixed job ID (90)",
                "Helpful for demonstration purposes"
            ],
            "download.php": [
                "Legacy download handler",
                "Provides various download formats",
                "Handles conservation analysis results",
                "Being replaced by specific download scripts"
            ]
        };

        function showScriptInfo() {
            const selector = document.getElementById('scriptSelector');
            const scriptName = selector.value;
            const infoDiv = document.getElementById('scriptInfo');

            if (!scriptName) {
                infoDiv.style.display = 'none';
                return;
            }

            document.getElementById('scriptTitle').textContent = scriptName;
            const detailsList = document.getElementById('scriptDetails');
            detailsList.innerHTML = '';

            scriptData[scriptName].forEach(item => {
                const li = document.createElement('li');
                li.textContent = item;
                detailsList.appendChild(li);
            });

            infoDiv.style.display = 'block';
        }

        // Collapsible functionality
        var coll = document.getElementsByClassName("collapsible");
        for (var i = 0; i < coll.length; i++) {
            coll[i].addEventListener("click", function() {
                this.classList.toggle("active");
                var content = this.nextElementSibling;
                if (content.style.display === "block") {
                    content.style.display = "none";
                } else {
                    content.style.display = "block";
                }
            });
        }
    </script>
</body>
</html>
