# AI scoring & proposal rules — source of record

These two files are the FULL rule sets (skill instructions + complete research
documents) that every lead score and every proposal must follow:

- `scoring-system-prompt.md` — the Bid/No-Bid rubric (hard auto-rejects,
  30/25/20/15/10 weights, invitation override, BID/BOOST thresholds)
- `proposal-system-prompt.md` — the proposal-writing guide (225-char preview
  rule, six opener patterns, banned phrases/AI tells, real-project proof list)

The LIVE copies are the `scoring_system_prompt` / `proposal_system_prompt`
settings (Settings → AI models & prompts), loaded on every AI call by
App\Services\Ai\ScoringService and ProposalService. Edit rules THERE (browser,
no deploy). These repo files are the versioned backup / re-seed source — if you
change the live prompts substantially, update these files to match.
