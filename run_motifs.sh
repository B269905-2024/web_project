#!/bin/bash

# Input parameters
FASTA_FILE="$1"
RESULTS_DIR="$2"

# Check if input file exists
if [ ! -f "$FASTA_FILE" ]; then
    echo "FASTA file not found!"
    exit 1
fi

# Create results directory if it doesn't exist
mkdir -p "$RESULTS_DIR"

# Output file for motif search results
OUTPUT_FILE="$RESULTS_DIR/patmatmotifs_results.txt"

# Count number of sequences in the input file
SEQ_COUNT=$(grep -c '^>' "$FASTA_FILE")
echo "Processing $SEQ_COUNT sequences..."

# Run EMBOSS patmatmotifs to scan sequences for motifs
patmatmotifs -sequence "$FASTA_FILE" -outfile "$OUTPUT_FILE" -full Y

# Check if patmatmotifs ran successfully
if [ ! -s "$OUTPUT_FILE" ]; then
    echo "ERROR: No motifs found or patmatmotifs failed to run correctly." > "$OUTPUT_FILE"
    exit 1
fi

# Count number of sequences with motifs found
MOTIF_SEQ_COUNT=$(grep -c '^Sequence:' "$OUTPUT_FILE")
echo "Found motifs in $MOTIF_SEQ_COUNT out of $SEQ_COUNT sequences."

# Success message
echo "Motif search completed. Results saved to $OUTPUT_FILE."
exit 0
