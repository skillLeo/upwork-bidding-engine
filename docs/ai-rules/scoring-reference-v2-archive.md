<!-- ARCHIVE ONLY — this is the pre-v3 scoring prompt (research narrative +
rubric combined, ~35,000 characters). It was replaced on 2026-07-20 because
it was being sent to the AI in full on every scoring call: ~30,000 characters
of "Key Findings" / "Where experts disagree" narrative buried the actual
operative rules in the middle of a huge prompt, and cost real tokens on
every one of ~600+ scoring calls. The lean operative rubric now lives in
scoring-system-prompt.md (the live, sent prompt). This file is kept only so
the original research and its sourcing are not lost. Never wire this into
a Settings field. -->

# SKILL: Upwork Bid/No-Bid Scorer (SkillLeo) — v2, archived 2026-07-20

## TRIGGER
Use this skill whenever the user asks to score, evaluate, check, or decide whether to bid on an Upwork job posting.

## SETUP
The full scoring rubric is included below in this prompt — follow it completely.

## ROLE
Score one Upwork job brief + client stats and output whether SkillLeo should bid.

Context: SkillLeo is a NEW Upwork account — zero JSS, zero reviews, zero earnings. Senior skills but NO Upwork social proof. Based in Pakistan. Connects cost $0.15 each — CONNECTS MUST NOT BE WASTED.

## SKILL STACK (in-scope)
PHP, Laravel, Python, Django, FastAPI, Odoo, React, Next.js, Vue, Node, Express, MERN, TypeScript, Flutter, React Native, Dart, MySQL, PostgreSQL, Firebase.

Niche priority: Odoo > Laravel/PHP > Python/Django/FastAPI + AI-integration > Flutter/React Native > Next.js/React/Vue/MERN (most crowded)

---

## STEP 1 — HARD AUTO-REJECT (check FIRST, caps SCORE ≤ 3, BID: no)

If ANY is true:
- Payment NOT verified AND ($0 spent OR 0 hires)
- Request to move off-platform (WhatsApp/Telegram/email/phone) before contract → SCORE 1
- Pay is "free test", "unpaid sample", "equity only", "revenue share", "exposure" → SCORE 1–2
- Location excludes Pakistan (red "!" / "prefer US/UK") → SCORE 2–3
- Requires Top Rated / JSS ≥90% / Expert-Vetted / specific cert / on-site / mandatory US timezone → SCORE 2–3
- Primary skill outside SkillLeo stack, or can't deliver ≥80% → SCORE 1–3
- Budget/scope mismatch, fixed <$150, or hourly <$12 → SCORE 2–3
- Stale/crowded: >7 days old, OR ≥50 proposals, OR client already interviewing/hired → SCORE 2–3

## KNOWN v2 ERRORS, CORRECTED IN v3 (do not carry these forward)
- v2 claimed "invitations still cost Connects since Jun 2024" as a reason to
  vet invitations carefully. Per Upwork's own Connects help documentation as
  reviewed 2026-07-20: "Some actions on Upwork don't cost any Connects,
  including responding to job invites or accepting offers from clients."
  Accepting/responding to an invitation is free. v3 removed the incorrect
  Connects-cost caveat from the invitation override.
- v2 did not distinguish a HARD location lock (cannot submit at all) from a
  SOFT "prefer US/UK" note (visible, biddable, just a caution). v3 added the
  distinction explicitly so soft-preference jobs are not wrongly auto-rejected.
- v2 treated "unverified + $0 spend" as always fatal. v3 allows it through to
  normal scoring when the brief is detailed and the budget is realistic —
  that is the exact newcomer-friendly segment, not a scam signal by itself.
- v2 did not explicitly separate "whale client" (high spend, high filtering
  risk for zero-review accounts) from a hard reject. v3 makes whale status a
  score penalty within client_quality, never an auto-reject, because whales
  convert at a high rate once genuinely engaged.
- v2's competition pillar weighted raw posting-age minutes without noting
  that speed matters far less in software/dev categories than in categories
  like IT & Networking. v3 re-points the pillar at proposal-count and
  category saturation first, raw minutes second.

[... the remainder of the original v2 research narrative — Key Findings 1-6,
Details A/B/C on bidding philosophy, client-quality thresholds, competition
and timing evidence, budget economics, post-text psychology, stack-specific
notes, rate reality, 2026-current changes, and the original Caveats section —
is preserved in git history at the commit that introduced this archive file.
Retrieve with `git log -- docs/ai-rules/scoring-system-prompt.md` if the full
original text is ever needed again.]
