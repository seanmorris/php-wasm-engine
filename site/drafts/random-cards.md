# Random streams and card piles — drafting outline

Source: `docs/random-cards.md`. Lead with repeatable game operations, then explain storage and compatibility. Keep the algorithm and security limits precise without requiring readers to understand the byte format before using the API.

I. Explain the purpose and limits
    A. Repeat dice, selections, and shuffles from an explicit seed
    B. Distinguish reproducibility from multiplayer agreement and hidden information
II. Generate repeatable results
    A. Create a stream with a purpose string
        1. Roll a die and select a zero-based index
        2. Shuffle or sample without replacement
    B. Save and restore the exact stream position
    C. Preserve operation order
        1. Use separate purposes for deck and bot decisions
        2. Await JavaScript operations sequentially on each stream
III. Track cards across piles
    A. Distinguish card instances from their definitions
    B. Explain ordered and random sections with a small inventory
    C. Peek, draw, and discard without losing or duplicating cards
    D. Compare moves, cuts, and partial shuffles
    E. Preserve resolved order and stream position in snapshots
IV. Keep private online dealing separate
    A. Identify the full card identities retained by this backend
    B. Explain the separate agreement, proof, and authorization requirements
    C. Assign revision validation and atomic recording to the caller
V. Document compatibility details
    A. Specify the versioned SHA-256 byte format
    B. Explain rejection sampling and its supported bounds
    C. List the cross-runtime and inventory verification commands
