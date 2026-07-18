# AI scoring & proposal rules — source of record

Three files, three very different jobs:

- `scoring-system-prompt.md` — the Bid/No-Bid rubric. Sent to the AI on
  every scoring call (`scoring_system_prompt` setting).
- `proposal-skill.md` — SKILL.md v2, the operative drafting procedure
  (~9KB). This plus the `project_facts` setting plus the code-side
  format spec (ProposalService::FORMAT_SPEC) is the ONLY text any
  proposal call ever sends. Lives in the `proposal_skill` setting.
- `proposal-reference.md` — the teaching document (guide + Top 1%
  addendum). Operator reading ONLY, stored in `proposal_reference`.
  It is NEVER included in any prompt, on purpose: it's written in the
  exact analytical, em-dash-heavy style the rules ban, and models
  imitate the style of their context more than they obey instructions
  inside it.

The LIVE copies are the settings (Settings → AI models & prompts), loaded
on every AI call. Edit rules THERE (browser, no deploy). These repo files
are the versioned backup / re-seed source — `php artisan ai:sync-prompts`
pushes them into the live settings (overwriting browser edits, so sync
deliberately). `php artisan ai:show-prompt {lead_id}` prints the exact
prompt a proposal call would send, for verifying what the model sees.
