<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Help - Protein Sequence Analysis Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
            max-width: 1000px;
            margin: 0 auto;
        }
        .nav-links {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .nav-links a {
            margin-right: 15px;
            text-decoration: none;
            color: #0366d6;
        }
        .nav-links a:hover {
            text-decoration: underline;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #2980b9;
            margin-top: 30px;
        }
        .help-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 25px;
            border-left: 4px solid #3498db;
        }
        .concept {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .example {
            font-style: italic;
            color: #7f8c8d;
            margin: 10px 0 10px 20px;
        }
        .important {
            font-weight: bold;
            color: #c0392b;
        }
    </style>
</head>
<body>
    <div class="nav-links">
        <a href="home.php">New Search</a>
        <a href="past.php">Past Searches</a>
        <a href="example.php">Example Analysis</a>
        <a href="about.php">About</a>
        <a href="help.php">Help</a>
    </div>

    <h1>Biological Help & Context</h1>
    <p>This guide explains the biological rationale behind the tools in this protein sequence analysis platform.</p>

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
</body>
</html>
