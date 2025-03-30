#!/bin/bash

# Input parameters
FASTA_FILE="$1"
ALIGN_FILE="$2"
RESULTS_DIR="$3"
WINDOW_SIZE="${4:-4}"

# Create results directory
mkdir -p "$RESULTS_DIR"

# Initialize report file
REPORT_FILE="$RESULTS_DIR/report.txt"
echo "Conservation Analysis Report" > "$REPORT_FILE"
echo "===========================" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

# 1. Run Clustal Omega alignment if not already done
if [ ! -f "$ALIGN_FILE" ]; then
    echo "Running Clustal Omega alignment..." >> "$REPORT_FILE"
    clustalo -i "$FASTA_FILE" -o "$ALIGN_FILE" --force --threads=1 --outfmt=clustal 2>&1 | tee -a "$REPORT_FILE"

    if [ ! -s "$ALIGN_FILE" ]; then
        echo "ERROR: Clustal Omega alignment failed" >> "$REPORT_FILE"
        exit 1
    fi
    echo "Alignment completed successfully." >> "$REPORT_FILE"
    echo "" >> "$REPORT_FILE"
fi

# 2. Run Plotcon analysis
echo "Running EMBOSS Plotcon (window size: $WINDOW_SIZE)..." >> "$REPORT_FILE"
plotcon -sequences "$ALIGN_FILE" -graph png -winsize "$WINDOW_SIZE" -goutfile "$RESULTS_DIR/plotcon" 2>&1 | tee -a "$REPORT_FILE"

if [ -f "$RESULTS_DIR/plotcon.1.png" ]; then
    mv "$RESULTS_DIR/plotcon.1.png" "$RESULTS_DIR/plotcon.png"
    echo "Plotcon analysis completed. Graph saved as plotcon.png." >> "$REPORT_FILE"
else
    echo "WARNING: Plotcon did not generate expected output." >> "$REPORT_FILE"
fi
echo "" >> "$REPORT_FILE"

# 3. Run Shannon Entropy analysis
echo "Running Shannon Entropy analysis..." >> "$REPORT_FILE"
python3 - <<EOF | tee -a "$REPORT_FILE"
import numpy as np
import matplotlib.pyplot as plt
from Bio import AlignIO
from collections import Counter
import json

def calculate_entropy(column):
    counts = Counter(column)
    total = len(column)
    return -sum((count/total) * np.log2(count/total) for count in counts.values())

# Read alignment
alignment = AlignIO.read("$ALIGN_FILE", "clustal")
num_sequences = len(alignment)
alignment_length = alignment.get_alignment_length()

# Calculate entropy for each position
entropy = []
for i in range(alignment_length):
    column = str(alignment[:, i]).replace('-', '')  # Ignore gaps
    if column:
        entropy.append(calculate_entropy(column))
    else:
        entropy.append(0)  # All gaps = 0 entropy

# Generate statistics
mean_entropy = np.mean(entropy)
max_entropy = np.max(entropy)
min_entropy = np.min(entropy)
max_pos = np.argmax(entropy) + 1
min_pos = np.argmin(entropy) + 1

# Print report data
print("\n=== Shannon Entropy Results ===")
print(f"Number of sequences: {num_sequences}")
print(f"Alignment length: {alignment_length} residues")
print(f"Mean entropy: {mean_entropy:.3f} bits")
print(f"Max entropy: {max_entropy:.3f} bits (position {max_pos})")
print(f"Min entropy: {min_entropy:.3f} bits (position {min_pos})")

# Save visualization files
plt.figure(figsize=(12, 6))
plt.plot(entropy, color='blue')
plt.axhline(y=mean_entropy, color='r', linestyle='--', label='Mean entropy')
plt.title('Shannon Entropy (gap positions excluded)')
plt.xlabel('Position')
plt.ylabel('Entropy (bits)')
plt.legend()
plt.tight_layout()
plt.savefig("$RESULTS_DIR/entropy.png")

# Save JSON for interactive plot
plot_data = {
    "data": [{
        "y": entropy,
        "type": "line",
        "name": "Entropy",
        "line": {"color": "blue"}
    }],
    "layout": {
        "title": "Shannon Entropy Analysis",
        "xaxis": {"title": "Position"},
        "yaxis": {"title": "Entropy (bits)"},
        "shapes": [{
            "type": "line",
            "x0": 0,
            "x1": len(entropy),
            "y0": mean_entropy,
            "y1": mean_entropy,
            "line": {"color": "red", "dash": "dash"}
        }]
    }
}

with open("$RESULTS_DIR/entropy.json", 'w') as f:
    json.dump(plot_data, f)

# Generate simplified alignment view
with open("$RESULTS_DIR/alignment.txt", 'w') as f:
    for record in alignment:
        f.write(f">{record.id}\n")
        f.write(f"{str(record.seq)}\n\n")

# Append conservation summary to report
sorted_positions = sorted(enumerate(entropy), key=lambda x: x[1])
print("\nTop 5 most conserved positions:")
for pos, ent in sorted_positions[:5]:
    print(f"Position {pos+1}: {ent:.3f} bits")

print("\nTop 5 most variable positions:")
for pos, ent in sorted(sorted_positions[-5:], key=lambda x: -x[1]):
    print(f"Position {pos+1}: {ent:.3f} bits")
EOF

# Finalize report
echo "" >> "$REPORT_FILE"
echo "Analysis completed." >> "$REPORT_FILE"
echo "Results saved in: $RESULTS_DIR" >> "$REPORT_FILE"

exit 0

