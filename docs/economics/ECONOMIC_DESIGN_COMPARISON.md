# Selected production allocation — November 14, 2026

15/25/30/25/5 is selected for a fresh 1-billion-GWD genesis: security 150 million,
grants 250 million, subsidies 300 million, Institute treasury 250 million and
operations 50 million. Six-decimal precision, no later minting and no burning remain.
The earlier two designs remain explicit historical comparisons in the JSON model.

Using the protocol's 525,960-block nominal year, the first sixteen nominal years
consume 52,596,000 GWD, leaving 97,404,000 GWD in security if no other withdrawals
occur. This uses 365.25-day years, so it differs from the proposal's rounded
52.56-million figure. Continued rewards require finalized blocks and available
reserve balances; no affordability or liquid market value is inferred.

The selected reserve exhausts after
201.192790
nominal years at uninterrupted target cadence and no additional withdrawals.

Allocation selection grants no spending authority. Spending ceilings, operating
budgets, custody, recovery and formal Institute ratification remain unresolved.
The production template intentionally leaves spending limits unset. The proposed
300,000-GWD subsidy window ceiling is not an approved budget. Epoch 0 customer-funded
operator services must not draw independent-attestation subsidies.

Reproduce: `python3 scripts/compare-economic-designs.py`.
