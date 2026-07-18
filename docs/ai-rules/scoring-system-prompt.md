# SKILL: Upwork Bid/No-Bid Scorer (SkillLeo)

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

---

## STEP 2 — WEIGHTED SCORE (only if no auto-reject)

- CLIENT QUALITY (30%): payment verified; spend tier; hire rate >70%; avg rate paid near target; fair reviews; account history
- COMPETITION & TIMING (25%): job age <2h + <10 proposals = full marks; degrades with age/count; interviewing = near-zero
- SKILL/STACK FIT (20%): exact niche (Odoo/Laravel) = full; core stack = strong; edge = partial; must deliver ≥80%
- BUDGET & ECONOMICS (15%): realistic budget; fixed-price small job (newcomer-friendly); rate ≥ floor; Connect cost sane
- POST QUALITY (10%): detailed brief, specific tech named, "ongoing/long-term/phase-2", existing codebase, screening questions

Green-flag bonuses: invitation, phased scope, named tech, long-term potential
Soft cautions (lower, don't reject): vague post (may be Uma-generated), budget unspecified

---

## STEP 3 — INVITATION OVERRIDE

If invitation AND client passes Step 1 → SCORE = max(score, 8), BID: yes
Still costs Connects (since Jun 2024) — never override an auto-reject.

---

## STEP 4 — DECISION THRESHOLDS

- SCORE ≥ 9 → BID: yes, BOOST: yes ONLY IF contract value ≥$1,000 AND job fresh <24h AND fit is exact; else BOOST: no
- SCORE 7–8 → BID: yes, BOOST: no
- SCORE ≤ 6 → BID: no (exception: exact rare Odoo/Laravel niche, few competitors → may upgrade)

---

## OUTPUT FORMAT (always exactly this)

```
SCORE: n/10
REASON: <one line — biggest driver, e.g. "Verified client 92% hire rate, 3h old, <5 proposals, exact Laravel fit">
BID: yes/no
BOOST: yes/no
```

Then optionally add 2–3 lines of actionable notes (what to emphasize in the proposal, red flags to watch, rate suggestion).

---

# FULL SCORING RUBRIC (follow completely — nothing here is optional)

---
name: upwork-scoring-rubric
description: Scoring rubric for evaluating Upwork jobs. Use when deciding whether to bid. Outputs SCORE: n/10, BID: yes/no, BOOST: yes/no.
metadata:
  type: reference
---

# The SkillLeo Upwork Bid/No-Bid Scoring System — A Decision Rubric for a Brand-New Account

## TL;DR

Bid selectively, not broadly. For a zero-JSS, zero-review account bidding from Pakistan, the winning strategy is a small number of surgically targeted proposals to fresh jobs (posted <2 hours ago, <10 proposals) from payment-verified, high-hire-rate clients who did NOT restrict to "US-only" — not volume. The rubric below scores each job 1–10 on client quality, competition/timing, budget economics, post quality, and new-account winnability, with hard auto-rejects that cap a score at 3 regardless of other merits.

The threshold: bid at 7+, bid-and-boost only at 9–10 on high-value fits, skip everything ≤6. At $0.15/Connect and 4–16 Connects per bid, the goal is protecting Connects for the ~20–30% of jobs that are genuinely winnable for a newcomer. Roughly half of all Upwork jobs never hire anyone, and true "U.S.-only" jobs are invisible to Pakistani accounts anyway — so the rubric's job is to filter the visible pool down to real, winnable opportunities.

A paste-ready SKILL.md agent instruction set is included in Section D so OpenClaw/Claude can read a Vollna brief + client stats and output SCORE: n/10, REASON: one line, BID: yes/no, BOOST: yes/no consistently.

---

## Key Findings

1. **Job selection matters more than proposal quality.** Every serious practitioner converges on this: if you skip the job-selection step and bid on stale, crowded, or mismatched jobs, no proposal saves you. The single highest-ROI behavior is speed — being in the first handful of applicants.

2. **Speed is the great equalizer for a new account.** When a client has 3 proposals (all from the first 15 minutes), they judge on merit and your lack of reviews matters less. When they have 40 proposals, they filter by JSS, badges, and reviews — and a zero-JSS account is filtered out before being read. Practitioner data indicates proposals sent within the first ~2 hours convert at roughly 3x the rate of those sent after 24 hours, and (per SnipeWork's 2026 proposal analysis) ~78% of clients only read the first 12–15 proposals.

3. **Client quality signals predict whether the budget is even real.** Payment-verified + real total spend + high hire rate + reasonable average hourly paid is the "trifecta+" that separates a real job from a ghost post. Hire rate matters more than headline budget: a client with a 90% hire rate has real work and follows through; one at 15% posts and ghosts.

4. **Connects are a marketing budget, and refunds are rare.** Connects cost $0.15 each; a proposal costs 4–16 Connects and a boost adds 20–40+. Per Upwork's official Connects documentation ("Understanding and using Connects"), Connects are only refunded when a client cancels a project before a contract is formed, or when Upwork removes a job post for violating its Terms of Service — NOT when a proposal is rejected, another freelancer is chosen, or a job expires unfilled. So the connect is gone the moment you bid on a dead post.

5. **Location is a hard gate, not just a soft bias.** True "U.S.-only" jobs are literally invisible to a Pakistan account — per Upwork's official help doc ("Choose a freelancer location when posting a job"): "If you choose U.S. only, then only freelancers in the U.S. will be able to see your post and submit a proposal." So they can never waste Connects. But "preferred location" jobs are visible and show a red "!" flag next to Location — applying to those is usually a wasted Connect. And there is a real rate gap and reply-rate disadvantage for South Asian freelancers on generic US/UK jobs (GigRadar's 2026 rate analysis benchmarks a South Asia seat at ~0.6x the global median versus ~1.2x for a US/Western-European seat — roughly a 2x gap for the same skill), beaten only by niche specialization and proof.

6. **Experts genuinely disagree on two things, and the rubric treats both honestly:** (a) whether to bid low/cheap to buy first reviews vs. hold rates; and (b) whether screening questions and boosting help or hurt. Both are addressed below.

---

## Details

### A) The Underlying Bidding Philosophy (Why the Rubric Works)

The core mental model: a Connect is a bet, and you are managing a portfolio of bets with a thin bankroll. Every proposal costs real money and cannot be recovered if the job dies. Since a new account wins a small fraction of what it bids on, the entire game is raising the quality of each bet — bidding only where the expected value (probability-of-win × contract-value) clearly exceeds the Connect cost.

**Five pillars the best practitioners agree on:**

1. **Job selection > proposal craft.** The consensus is blunt: most freelancers "treat it like a numbers game, send generic proposals to every job, burn through Connects, and wonder why nobody responds." Winning starts by disqualifying jobs. One experienced practitioner (Lilach Bullock) applies a five-point filter (payment verified, hire rate >70%, average rate paid, post freshness, description detail) and bids on nothing that fails any of them — she won 1 of 12 tightly-filtered bids, a far better return than spraying.

2. **Speed neutralizes the new-account handicap.** This is the most important single insight for SkillLeo. Because clients read proposals top-down and shortlist from the first batch, and because a fresh post has few competitors, arriving in the first minutes lets a zero-review account be judged on the proposal and fit rather than filtered out on badges. The Vollna→Laravel→OpenClaw pipeline is a genuine competitive weapon here: it can score and surface a fresh, high-fit job within minutes. The rubric therefore weights job age and proposal count heavily.

3. **Client quality gates everything.** Hire rate is the truth serum for the budget. Total spend proves the client pays. Payment-verified is table stakes. Average hourly rate paid reveals the client's real anchor — if they've historically paid $6/hr, a $30/hr bid is a wasted Connect no matter how good.

4. **Specialize; don't generalize.** The Upwork Best Match algorithm learns which categories you convert in and shows you there. Bidding narrowly (e.g., only Laravel/Next.js/Odoo jobs) actually improves feed quality and interview rates over time; spraying across web/mobile/scraping confuses the algorithm and dilutes positioning. For SkillLeo, Odoo and Laravel are relatively defensible niches; generic "build me a website / MERN" is the most crowded, AI-compressed, race-to-the-bottom lane.

5. **Protect the downside.** A bad client can damage the JSS you don't yet have — and your first JSS is disproportionately fragile (one bad contract on a near-empty record is catastrophic). So for a new account, client safety is weighted even more heavily than for an established freelancer. Avoiding a bad first client is as valuable as landing a good one.

**Where experts disagree (presented honestly):**

- **Bid low to buy reviews vs. hold your rate.** School A (build momentum): take small, well-scoped fixed-price jobs ($100–$500) slightly below market to break the "no reviews" barrier — "your first review matters more than your first payment," and the second client is far easier than the first. School B (protect positioning): low rates signal low quality on Upwork ("clients associate extremely low rates with low quality, not bargain value"), attract exploitative clients, and are hard to climb out of. A Top Rated Upwork freelancer with 700+ completed projects put School B bluntly on Quora: "Absolutely NOT... Bid the budget. You do not want a client trying to cut corners with money. They won't respect you." The rubric's resolution: for the first ~3–5 contracts, modestly discount (not bottom-feed) on small, clearly-scoped, low-risk fixed-price jobs to manufacture reviews, then raise. Reject genuinely exploitative rates always. This is a deliberate, time-boxed phase, not a permanent identity.

- **Screening questions — help or hurt?** Some practitioners see them as friction that new freelancers should avoid; others (and Upwork officially) say questions surface serious clients and, answered well, are where you win. Resolution: treat the presence of thoughtful screening questions as a mild GREEN flag (it filters out lazy spray-bidders and signals a prepared client) — but they neither auto-qualify nor disqualify a job. They affect proposal work, not the bid/no-bid score much.

- **Boosting.** Upwork's official Boosted Proposal help page states verbatim that "Boosting your proposal can increase your chance of being hired up to 24%" and that boosted proposals are "17% more likely to be seen by clients," appearing in the first four result slots. Skeptics (e.g., GigUpHQ's 2026 analysis) note premium clients develop "ad blindness" to boosted slots and that boosting a weak-fit proposal just raises the cost of losing. Resolution: boost only high-value, high-fit, fresh jobs where you'd win organically anyway (see threshold logic).

---

### B) Client-Quality, Competition, Budget, Post-Text, and Fit — the evidence behind each factor

**Client quality thresholds**

- **Payment verified:** near-mandatory. Unverified + $0 spent + zero hires is the "verification triad" of highest scam/ghost risk. Hourly Payment Protection doesn't even apply without a verified billing method.
- **Total spend:** $0 = unproven (caution, not always fatal); $1k–$10k = solid; $10k–$100k+ = strong, proven payer. The bigger the spend, the more real the job.
- **Hire rate:** >70% is a green flag; a client with many posts and few hires (e.g., 15%) lowers the expected value of your bid dramatically regardless of budget. Below ~40% is a serious caution.
- **Average hourly paid:** if consistently $4–8/hr, a professional developer's bid is wasted. Look for clients whose paid rates are near your target.
- **Reviews received & reviews the client LEFT for others:** read what previous freelancers said (unresponsive, scope-creep, difficult = skip) and whether the client leaves fair 5-star feedback (good sign) or sub-5-star/no feedback (caution).
- **Client age / member-since:** older accounts with history are safer; brand-new client accounts are riskier but not disqualifying if payment-verified.
- **Brand-new $0-spend but verified client — bid or skip?** Experts split. Pro-bid: new clients are less experienced buyers, often more willing to take a chance on a new freelancer, and less crowded. Anti-bid: unproven, may never actually hire (over half of jobs never hire), higher ghost risk. Resolution for SkillLeo: a payment-verified new client with a detailed, specific brief is bid-worthy (this is exactly the newcomer-friendly segment); a $0/unverified/vague-brief new client is a skip.
- **Enterprise / Upwork Enterprise clients:** generally out of reach for a zero-JSS account; the Expert-Vetted badge that gates much enterprise work requires a JSS ≥90% for 13 of the last 16 weeks (and $20k+ earnings for agencies). Don't spend Connects chasing them yet.

**Competition & timing**

- Proposal-count tiers: <5 = excellent; 5–10 = good; 10–15 = borderline; 15–20 = competitive (bid only with a sharp niche angle); 20–50 = weak for a new account; 50+ = skip unless you are one of very few with the exact niche.
- Job age: <2 hours (ideally <30 min) = prime; same-day = OK; 2–4 days = declining; >1 week = usually dead. Freshness compounds with proposal count.
- "Interviewing"/ hires already made: if the client is already interviewing several freelancers or has made a hire, a newcomer's odds collapse — usually skip.
- Realistic conversion: well-targeted reply rates run ~18–45%; win rates ~6–20% across categories, with custom development at the lower end. GigRadar's agency benchmarks target reply 20–35%, shortlist 10–20%, win 5–12%. A new account should expect the low end initially and plan Connects accordingly.

**Budget & economics**

- Fixed vs hourly: fixed-price ($100–$500) is the safer entry for a new account — lower client risk, higher conversion, no early hourly-log disputes, and Upwork's fixed-price escrow protects you. Move to hourly after 3–5 reviews.
- Budget floors: skip jobs whose budget is below ~70% of your rate floor (SnipeWork's recommended cutoff). Suggested floors for SkillLeo as a new account: fixed ≥ $150 for a real micro-project; hourly ≥ ~$12–15/hr (see rate reality below). "Uber clone for $200" = scope/budget mismatch, auto-low.
- "Budget not specified": neutral-to-mild-caution; lean on the client's average-paid history and description detail to judge.
- Connect ROI math: a 6-Connect bid = $0.90; winning a $500 job is a ~550x return, so the bar is "is this winnable and real," not "is the Connect cheap." Boosting to a top slot can cost 20–40 Connects ($3–$6); rule of thumb from practitioners (Zenlance/UpHunt) — only boost when expected contract value ≥ ~50x the boost cost (i.e., $300+ minimum, realistically $1k+ to be clearly positive).

**The job post itself (psychology)**

GREEN flags: clear scope/phases, specific tech named (Laravel, Odoo, Next.js…), "ongoing / long-term / phase 2," existing codebase, realistic timeline, mention of their team/process, thoughtful screening questions.

RED flags / auto-reject language: "free test / unpaid sample," "revenue share / equity only," "simple job, should take an hour," "need it done today," "we've had bad experiences with previous devs" (pre-loaded conflict), excessive NDA demands before contract, and any push to WhatsApp/Telegram/email before a contract (per Upwork's own scam guidance and UpAlerts' 2026 analysis, this is the single most reliable scam signal and voids payment protection).

Description length: one-line lazy posts correlate with scope-creep and low hire intent; detailed briefs signal prepared, serious clients.

Experience level requested: target Intermediate and Expert (serious clients, real budgets); Entry-level often means low budgets and bargain-hunters.

Requirements that hard-gate a Pakistan newcomer: US-timezone mandatory overlap, on-site presence, specific certifications you lack, or 90%+ JSS / Top Rated required = skip.

**Fit & winnability for a zero-JSS account**

- Auto-skip: jobs requiring Top Rated / 90%+ JSS / Expert-Vetted; jobs with a "preferred location" that excludes Pakistan (red "!" flag); jobs where you can't confidently deliver 80%+ of the scope.
- Rising Talent: complete the profile to earn it; it's a modest early trust signal — use it, but don't over-rely on it (OutBid and Upwork both note the algorithmic boost for new accounts is modest).
- Invitations vs public bids: invitations rank you at the top of the client's list and mean your profile was hand-picked — treat any relevant invite as a high-priority, near-auto-bid. Note: per Upwork's official Product Update, "Beginning June 6, 2024, when clients invite you to apply to a job, you'll need to use Connects to submit a proposal," so still vet the client. Getting an interview with an established client can also award free Connects — Upwork's Connects help page states "You may receive free Connects when you get an interview with an established client," but explicitly warns that if the client is brand new with zero spending history you get no bonus.
- Niche > generalist: Odoo and Laravel jobs are more defensible for SkillLeo than generic MERN/website work, which is the most crowded and rate-compressed.

**Stack-specific notes (SkillLeo's exact skills)**

- **Odoo** — a genuine specialist niche; ERP clients (SMEs, Gulf/EU manufacturers, POS/inventory) value expertise over location, competition is thinner, and rates run higher than generic web. Highest-conversion lane for SkillLeo.
- **Laravel/PHP** — steady demand; strong for SaaS, dashboards, API integrations, legacy PHP rescue/migration. Mid competition. A solid core lane.
- **Next.js / React / Vue / MERN** — high demand but the most crowded and AI-compressed; per UpHunt's 2026 niche data, full-stack sits around a $60–$130/hr band globally with most volume at the low end. Win here only with speed + a specific angle (e.g., "SaaS MVP for founders," a named vertical).
- **Flutter / React Native / Dart** — solid mobile demand; cross-platform MVPs convert well; moderate competition.
- **Python/Django/FastAPI** — good, especially paired with API integration and (increasingly) practical AI-API integration work, which Vibeworker's 2026 developer-niche analysis calls a fast-growing, budget-rich, less-saturated niche adjacent to SkillLeo's stack.

**Rate reality for a new Pakistan account:** Upwork's own published medians are ~$25/hr for full-stack and ~$30/hr for web developers; a new Pakistan account realistically wins in the ~$10–20/hr band initially (many start $8–15 to break the no-review barrier), with a credible path to $25–40+/hr after building JSS; established Pakistani Top-Rated Plus devs command $35–75+/hr. Pricing too low signals low quality — discount modestly, never bottom-feed.

---

### C) 2026-current Upwork changes that affect bidding (and outdated advice to ignore)

- **Connects cost $0.15 each** (unchanged since the 2024 pricing update). Confirmed by Upwork's official Connects Calculator: "Each Connect costs $0.15 (USD)… sold in bundles or a custom amount (10 minimum)." Purchased Connects expire after one year and there is no bulk discount. But Connects-per-proposal has crept up — competitive tech jobs now sometimes demand up to ~16 (and boosts push single bids to 32–40+). Budget accordingly.

- **Freelancer Plus is $19.99/month** and includes 100 Connects/month (after one month on the plan), versus 10 free monthly Connects on Basic — per Upwork's official "What is Freelancer Plus?" page. It also unlocks competitor bid-range visibility, useful for pricing decisions.

- **Invitations now cost Connects to accept** (since June 6, 2024). Old advice that "invites are always free" is outdated — you still don't pay to receive them, but you pay to submit the proposal.

- **Best Match is a behavioral prediction engine.** Per GigRadar's algorithm analysis, it ranks by predicted contract success (your proposal-to-interview rate, category earnings history, JSS, submission timing, repeat-client rate) — not cover-letter prose. Consistently failing to convert in a category suppresses your visibility there. Agencies carry a slight default visibility penalty vs. independent profiles in generic searches.

- **Uma (Upwork's AI)** now drafts many job posts and runs some interviews, so more posts read as vague/templated even when legitimate — don't over-penalize templated wording alone; weight client stats more.

- **Boost "ad blindness"** is a reported 2026 phenomenon among premium clients — reinforcing selective, not habitual, boosting. Boost mechanics to remember: first-time boosters get a one-time 10-Connect credit; a withdrawn boosted proposal can't be re-submitted to the same job; and the auction closes after seven days or upon first hire, whichever comes first.

- **Outdated folk wisdom to discard:** "always bid to fill your Connects," "boost everything," "spray 30 proposals a day," "bid rock-bottom to win," and "invites are free." Also discard any pre-2024 claim about flat/lower Connect counts.

---

## The Scoring Rubric (Section C + how factors combine)

### Hard auto-reject rules (force SCORE 1–3, BID: no) — check these FIRST

Any one of these triggers an automatic low score regardless of other merits:

1. Payment method not verified AND ($0 spent OR no prior hires). (The scam/ghost triad.)
2. Any request to communicate/pay off-platform (WhatsApp/Telegram/email) before a contract. → SCORE 1.
3. "Free test," "unpaid sample," "equity/revenue-share only," or "exposure" as the pay. → SCORE 1–2.
4. Location preference excludes Pakistan (red "!" on Location / "prefer US/UK talent"). → SCORE 2–3. (True US-only jobs won't appear at all.)
5. Requires Top Rated / 90%+ JSS / Expert-Vetted / specific cert / on-site / mandatory US-timezone shift that SkillLeo can't meet. → SCORE 2–3.
6. Skill outside SkillLeo's stack (can't deliver 80%+ confidently). → SCORE 1–3.
7. Clear budget/scope mismatch ("Uber clone for $200"), or budget below floor (fixed <$150 / hourly <$12). → SCORE 2–3.
8. Stale/crowded: posted >1 week ago, OR 50+ proposals, OR client already interviewing/hired. → SCORE 2–3.

### Weighted scoring for jobs that pass all auto-rejects (start from a 10-point build)

Score each dimension, sum, then map to 1–10.

| Factor | Weight | What earns the points |
|--------|--------|-----------------------|
| Client quality | 30% | Payment verified (mandatory); total spend tier; hire rate >70%; avg rate paid near target; fair reviews given/received; account history |
| Competition & timing | 25% | Job age <2h and proposals <10 = full marks; degrades with age/proposal count; interviewing already = near-zero |
| Skill/stack fit | 20% | Exact niche match (Odoo/Laravel = premium; core stack = strong; edge of stack = partial) + can deliver 80%+ |
| Budget & economics | 15% | Realistic budget for scope; fixed-price small job (new-account friendly); rate at/above floor; connect-cost vs value sane |
| Post quality & green flags | 10% | Detailed brief, specific tech, long-term/ongoing/phase-2, existing codebase, serious screening questions |

### Score definitions

**10** — Fresh (<1h), <5 proposals, payment-verified client with $10k+ spend and >80% hire rate, exact Odoo/Laravel fit, realistic budget, detailed brief, ideally an invitation or non-US client with no location gate. Bid + consider boost.

**9** — Fresh (<2h), <10 proposals, verified client with solid spend/hire rate, strong stack fit, good budget, detailed brief. Bid; boost if contract value ≥ ~$1k.

**8** — Strong fit and good client, but one soft weakness (e.g., ~10–15 proposals, or budget unspecified but client pays well historically, or slightly outside core niche). Bid.

**7** — Solid, winnable, but two soft weaknesses (e.g., posted same-day + 15 proposals, or new verified client with detailed brief but $0 spend). Bid — this is the minimum-bid threshold.

**5–6** — Marginal: real job but crowded, aging, mediocre client stats, or generic/high-competition niche. Skip and preserve Connects (bid only if you have surplus Connects and a genuinely unique angle).

**3–4** — Weak: multiple red flags, poor client quality, stale/crowded, weak fit. Skip.

**1–2** — Auto-reject territory (scam signals, off-platform, exploitative pay, location-gated, outside stack). Never bid.

### The BID / DON'T BID / BOOST thresholds (tuned for a new account + limited Connects)

- **SCORE ≥ 9** → BID + BOOST, but only if contract value ≥ ~$1,000 AND job is fresh (<24h, boost auction still open) AND fit is exact. Otherwise bid without boosting. (Boost rule of thumb: expected value ≥ 50× boost cost.)
- **SCORE 7–8** → BID (no boost). These are the bread-and-butter winnable jobs; win them with speed and specificity, not spent Connects.
- **SCORE ≤ 6** → SKIP. Preserve Connects. Exception: if you're an exact-niche fit (e.g., a clean Odoo customization) and few others can claim it, a 6 can be upgraded to a bid.
- **Invitations** → near-auto-bid if the client passes basic safety checks (verified, sane brief), because you start at the top of the list and it's the highest-converting channel — but you now spend Connects to accept, so still vet.
- **Weekly discipline:** cap at ~5–10 high-quality bids/week. Track reply/interview/win rates monthly; if reply rate is <15% after 40+ well-targeted bids, the bottleneck is profile/proposal, not job selection.

---

## Section D — Paste-ready agent instruction set (SKILL.md / AGENTS.md style)

```markdown
# SKILL: Upwork Bid/No-Bid Scorer (SkillLeo — brand-new account, Pakistan-based)

## ROLE
You score one Upwork job brief + client stats and output whether SkillLeo should bid.
Context: SkillLeo is a NEW Upwork account — zero Job Success Score, zero reviews, zero
earnings. Senior skills but NO Upwork social proof. Based in Pakistan; bids on US/UK/
EU/Australia/Gulf clients. Buys Connects monthly — CONNECTS MUST NOT BE WASTED.
Has an Upwork Agency (SkillLeo) and an independent profile.

## SKILL STACK (in-scope). Jobs needing skills outside this score LOW.
PHP, Laravel, Python, Django, FastAPI, Odoo, React, Next.js, Vue, Node, Express,
MERN, TypeScript, Flutter, React Native, Dart, MySQL, PostgreSQL, Firebase.
Domains: web app dev, mobile app dev, API integration, SaaS.
NICHE PRIORITY (higher win-rate for new account): Odoo > Laravel/PHP > Python/Django/
FastAPI + API/AI-integration > Flutter/React Native > Next.js/React/Vue/MERN (most crowded)

## STEP 1 — HARD AUTO-REJECT CHECK (run first). If ANY is true, cap SCORE <= 3, BID: no
- Payment method NOT verified AND (client total spend = $0 OR client has 0 hires).
- Any request to move off-platform (WhatsApp/Telegram/email/phone) before a contract.
- Pay is "free test", "unpaid sample", "equity only", "revenue share", or "exposure".
- Location preference/requirement excludes Pakistan (e.g. "US only", "prefer US/UK talent",
  location shows a red "!" mismatch) -> SCORE 2-3.
- Requires Top Rated / JSS >= 90% / Expert-Vetted / specific certification SkillLeo lacks /
  on-site presence / mandatory full US-timezone shift -> SCORE 2-3.
- Primary skill is OUTSIDE the stack, or SkillLeo cannot confidently deliver >=80% of scope.
- Budget/scope mismatch (e.g. huge build for tiny budget), OR fixed budget < $150,
  OR hourly rate < $12 -> SCORE 2-3.
- Stale/crowded: posted > 7 days ago, OR proposals >= 50, OR client already interviewing.

## STEP 2 — WEIGHTED SCORE (only if no auto-reject). Score 0-100, then /10.
CLIENT QUALITY (30): payment verified (required); spend tier ($0=low..$10k+=high);
hire rate (>70% full, 40-70% half, <40% low); avg hourly paid near SkillLeo target;
fair reviews given & good reviews received; account age/history.
COMPETITION & TIMING (25): job age (<2h full, same-day good, 2-4d low, >4d near-zero);
proposal count (<5 full, 5-10 good, 10-15 half, 15-20 low, >20 near-zero);
if client is interviewing already -> near-zero.
SKILL/STACK FIT (20): exact niche (Odoo/Laravel) = full; core stack = strong;
edge of stack = partial; must be able to deliver >=80%.
BUDGET & ECONOMICS (15): realistic budget for scope; fixed-price small job = new-account
friendly bonus; rate >= floor; connect-cost sane vs expected value.
POST QUALITY (10): detailed brief, specific tech named, "ongoing/long-term/phase 2",
existing codebase, realistic timeline, thoughtful screening questions.
GREEN-FLAG BONUS (+): clear phased scope, named tech, long-term potential, invitation.
SOFT-CAUTION (do not reject, just lower): vague/templated post (may be Uma-generated),
budget "not specified" (judge via client's avg-paid history), brand-new but verified client.

## STEP 3 — INVITATION OVERRIDE
If this is an INVITATION and client passes STEP 1 safety checks -> SCORE = max(score, 8),
BID: yes (starts at top of client list, highest-converting channel). Still costs Connects
since Jun-2024, so never override an auto-reject.

## STEP 4 — DECISION THRESHOLDS
- SCORE >= 9 -> BID: yes, BOOST: yes ONLY IF expected contract value >= $1000 AND job fresh
  AND fit is exact; else BOOST: no.
- SCORE 7-8 -> BID: yes, BOOST: no.
- SCORE <= 6 -> BID: no (exception: exact rare-niche Odoo/Laravel fit with few competitors
  may be upgraded to BID: yes).

## OUTPUT FORMAT (exactly)
SCORE: n/10
REASON: <one line naming the biggest driver>
BID: yes/no
BOOST: yes/no
```

---

## Recommendations

### Stage 1 — First 30 days / first 3–5 reviews (momentum phase)

- Bid only at SCORE ≥ 7. Prioritize Odoo and Laravel fixed-price jobs ($150–$800) from payment-verified, high-hire-rate, non-US-gated clients, posted <2 hours ago with <10 proposals.
- Price modestly below established competitors (roughly $12–20/hr equivalent) to break the no-review barrier — but never accept exploitative rates or free tests.
- Use the independent profile for solo-friendly small jobs (roughly 15–20% of jobs say "freelancers only, no agencies"); use the agency profile for larger team-scale jobs. Bid per-job with whichever fits.
- Treat every relevant invitation as a priority bid.
- Cap at 5–10 bids/week. Do NOT boost yet — spend Connects on volume of quality bids, not visibility.

### Stage 2 — After ~5 reviews and a JSS

- Raise the bid threshold's rate floor; begin selective boosting on SCORE 9–10 jobs worth ≥$1k.
- Shift more toward hourly contracts and larger fixed projects; start bidding Next.js/MERN with a sharpened niche angle.
- Begin targeting AI-API integration work (Python/Django + LLM APIs) — a growing, budget-rich, less-saturated adjacent niche.

### Client-region targeting (given the location reality)

- Deprioritize generic US/UK jobs where you compete head-on against US-based talent; you carry a ~2x rate disadvantage there unless you own an exact niche.
- Prioritize UK/EU, Australia, and Gulf/MENA clients (better timezone overlap with Pakistan, weaker location bias against South Asians), plus small US startups/solo founders who value skill+price over location.

### Metrics that change the strategy (benchmarks)

- Reply rate <15% after 40+ well-targeted bids → problem is profile/proposal, not job selection; fix those before bidding more.
- Win rate settling 6–12% → healthy for the account stage; push rates up.
- Connects burning with zero interviews → tighten to SCORE ≥ 8 only and re-check the auto-reject gate.
- If Odoo/Laravel consistently out-convert other lanes → specialize the profile title/skills further into them (Best Match will compound the advantage).

---

## Caveats

- **Practitioner data is largely self-reported or vendor-published.** Many concrete figures (reply/win-rate benchmarks, the "3x faster within 2 hours," "78% read first 12–15 proposals," "2x South Asia rate gap," "24% boost lift") come from freelance-tool vendors (GigRadar, UpHunt, SnipeWork, Vollna) and Upwork's own marketing, not audited third-party data. Treat them as directional, not precise. The 24% boost lift is Upwork's own experiment result, not a guarantee for any individual account.
- **Upwork does not publish its Best Match algorithm** or its exact scam/ghost criteria, so all algorithm claims are informed inference from observed patterns.
- **The location bias figure (~2x rate gap)** is directional; no source provided a hard, audited reply/hire-rate percentage for Pakistani applicants specifically. The hard US-only invisibility rule, however, is from Upwork's own documentation and is reliable.
- **Connect counts and pricing shift.** $0.15/Connect held through 2024–2026, but per-proposal Connect requirements are dynamic and can change while a post is live — the agent should read the live Connect cost, not assume.
- **Over half of Upwork jobs reportedly never hire anyone.** This is the structural reason the rubric is strict: most of the visible pool is not winnable by anyone, so aggressive filtering is rational, not overly cautious.
- This rubric is a decision/scoring system only — by design it contains no proposal-writing, cover-letter, or template guidance, per scope.

---

# OUTPUT OVERRIDE (this supersedes any output format above)

Output JSON only, nothing before or after it:
{"score": n, "bid": true/false, "boost": true/false, "reason": "one line — biggest driver"}