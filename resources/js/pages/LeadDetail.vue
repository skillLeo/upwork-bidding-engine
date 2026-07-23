<script setup>
import { ref, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import {
  AlertTriangle,
  ArrowLeft,
  Coins,
  Copy,
  Eye,
  EyeOff,
  ExternalLink,
  Globe2,
  History,
  MessageSquare,
  Pencil,
  RefreshCw,
  Save,
  Send,
  Sparkles,
  ShieldCheck,
  ShieldOff,
  Star,
  Users,
  Wallet,
  X,
} from "@lucide/vue";
import {
  useLead,
  updateLeadStatus,
  regenerateLeadScore,
  regenerateLeadProposal,
  saveLeadProposal,
  fetchProposalVersions,
  aiEditProposal,
  acceptAiEditProposal,
  toggleLeadViewed,
  updateLeadOutcome,
  LEAD_OUTCOMES,
} from "@/composables/useLead";
import Textarea from "@/components/ui/Textarea.vue";
import Input from "@/components/ui/Input.vue";
import { Wand2, Check as CheckIcon } from "@lucide/vue";
import { startAiTask, finishAiTask, failAiTask } from "@/stores/aiProgress";
import { useSavedFilters } from "@/composables/useSavedFilters";
import PageContainer from "@/components/layout/PageContainer.vue";
import Card from "@/components/ui/Card.vue";
import Button from "@/components/ui/Button.vue";
import ScoreBadge from "@/components/ui/ScoreBadge.vue";
import StatusPill from "@/components/ui/StatusPill.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SkeletonText from "@/components/ui/SkeletonText.vue";
import EmptyState from "@/components/ui/EmptyState.vue";
import { cn, relativeTime } from "@/lib/utils";
import { apiErrorMessage } from "@/lib/api-client";

const route = useRoute();
const router = useRouter();

const statusActions = [
  { status: "sent", label: "Mark Sent" },
  { status: "replied", label: "Mark Replied" },
  { status: "won", label: "Mark Won" },
  { status: "archived", label: "Archive" },
];

const filterId = computed(() => route.query.filter ?? null);
const { filters: savedFilters } = useSavedFilters();
const activeFilter = computed(() =>
  filterId.value ? savedFilters.value.find((f) => f.id === Number(filterId.value)) : undefined,
);

const { lead, isLoading, refetch } = useLead(
  () => route.params.id,
  () => activeFilter.value?.criteria,
);
const actionLoading = ref(null);

const statBlocks = computed(() => {
  if (!lead.value) return [];
  return [
    { icon: Wallet, label: "Budget", value: lead.value.budget ?? "—" },
    { icon: Globe2, label: "Client country", value: lead.value.client_country ?? "—" },
    { icon: Users, label: "Proposals so far", value: String(lead.value.proposal_count) },
    {
      icon: Coins,
      label: "Connects required",
      value: lead.value.connects_required != null ? String(lead.value.connects_required) : "—",
    },
    { icon: Wallet, label: "Client spend", value: lead.value.client_spend ?? "—" },
    { icon: Users, label: "Hire rate", value: lead.value.client_hire_rate ?? "—" },
    {
      icon: lead.value.payment_verified ? ShieldCheck : ShieldOff,
      label: "Payment",
      value: lead.value.payment_verified ? "Verified" : "Unverified",
      tone: lead.value.payment_verified ? "success" : "neutral",
    },
    {
      icon: Star,
      label: "Client rating",
      value:
        lead.value.client_rating != null
          ? `${lead.value.client_rating}${lead.value.client_reviews != null ? ` (${lead.value.client_reviews} reviews)` : ""}`
          : "—",
    },
  ];
});

// Same match-highlighting as the leads list's row preview (LeadRow.vue) -
// a skill matching the active saved filter's keywords stands out here too,
// instead of the detail page being the one place that context is lost.
function isMatchedSkill(skill) {
  const keywords = activeFilter.value?.criteria.include_keywords ?? [];
  return keywords.some((kw) => skill.toLowerCase().includes(kw.toLowerCase()));
}

async function handleStatus(status) {
  if (!lead.value) return;
  actionLoading.value = status;
  try {
    const updated = await updateLeadStatus(lead.value.id, status);
    lead.value = updated;
    toast.success(`Lead marked ${status}.`);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update status."));
  } finally {
    actionLoading.value = null;
  }
}


async function handleCopy() {
  if (!lead.value?.proposal_text) return;
  await navigator.clipboard.writeText(lead.value.proposal_text);
  toast.success("Proposal copied to clipboard.");
}

// Outcome tracking — independent of status, two clicks each.
const viewedLoading = ref(false);
const outcomeLoading = ref(false);

async function handleToggleViewed() {
  if (!lead.value || viewedLoading.value) return;
  viewedLoading.value = true;
  try {
    lead.value = await toggleLeadViewed(lead.value.id);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update."));
  } finally {
    viewedLoading.value = false;
  }
}

async function handleOutcomeChange(event) {
  if (!lead.value) return;
  const value = event.target.value || null;
  outcomeLoading.value = true;
  try {
    lead.value = await updateLeadOutcome(lead.value.id, value);
    toast.success(value ? "Outcome recorded." : "Outcome cleared.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update outcome."));
  } finally {
    outcomeLoading.value = false;
  }
}

// --- Manual editing + version history ---
const editing = ref(false);
const draft = ref("");
const savingProposal = ref(false);

const draftWordCount = computed(() =>
  draft.value.trim() ? draft.value.trim().split(/\s+/).length : 0,
);

function startEdit() {
  draft.value = lead.value?.proposal_text ?? "";
  editing.value = true;
}

function cancelEdit() {
  editing.value = false;
  draft.value = "";
}

async function saveEdit() {
  if (!lead.value || savingProposal.value || !draft.value.trim()) return;
  savingProposal.value = true;
  try {
    lead.value = await saveLeadProposal(lead.value.id, draft.value);
    editing.value = false;
    // The edit created a new version; refresh the timeline if it's open.
    if (showHistory.value) await loadVersions();
    const warnings = lead.value.proposal_warnings?.length ?? 0;
    warnings === 0
      ? toast.success("Saved. Passes every rule.")
      : toast.warning(`Saved with ${warnings} rule ${warnings === 1 ? "violation" : "violations"}.`);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save the edit."));
  } finally {
    savingProposal.value = false;
  }
}

const showHistory = ref(false);
const versions = ref([]);
const versionsLoading = ref(false);
const openVersionId = ref(null);

const editTypeLabels = {
  initial_draft: "First draft",
  rewrite: "AI rewrite",
  manual_edit: "Manual edit",
};

async function loadVersions() {
  if (!lead.value) return;
  versionsLoading.value = true;
  try {
    versions.value = await fetchProposalVersions(lead.value.id);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load history."));
  } finally {
    versionsLoading.value = false;
  }
}

async function toggleHistory() {
  showHistory.value = !showHistory.value;
  if (showHistory.value && versions.value.length === 0) await loadVersions();
}

// --- AI-assisted edit ---
const proposalRef = ref(null);
const instruction = ref("");
const selection = ref(null); // { start, end, text }
const aiEditLoading = ref(false);
const aiPreview = ref(null); // { old_text, new_text, edit_type, linter_violations, model }

const canAiEdit = computed(
  () =>
    !!lead.value?.proposal_text &&
    !editing.value &&
    !["sent", "replied", "won"].includes(lead.value?.status),
);

// Character offsets of the highlighted span within proposal_text, computed
// robustly by measuring the text length before the selection start.
function captureSelection() {
  const el = proposalRef.value;
  const sel = window.getSelection();
  if (!el || !sel || sel.rangeCount === 0 || sel.isCollapsed) return;
  const range = sel.getRangeAt(0);
  if (!el.contains(range.startContainer) || !el.contains(range.endContainer)) return;
  const pre = document.createRange();
  pre.selectNodeContents(el);
  pre.setEnd(range.startContainer, range.startOffset);
  const start = pre.toString().length;
  const text = range.toString();
  if (!text.trim()) return;
  selection.value = { start, end: start + text.length, text };
}

function clearSelection() {
  selection.value = null;
  window.getSelection()?.removeAllRanges();
}

// Word-level diff (LCS) so the preview shows exactly what changed.
function wordDiff(a, b) {
  const A = a.split(/(\s+)/);
  const B = b.split(/(\s+)/);
  const n = A.length;
  const m = B.length;
  const dp = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
  for (let i = n - 1; i >= 0; i--)
    for (let j = m - 1; j >= 0; j--)
      dp[i][j] = A[i] === B[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
  const out = [];
  const push = (type, text) => {
    const last = out[out.length - 1];
    if (last && last.type === type) last.text += text;
    else out.push({ type, text });
  };
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (A[i] === B[j]) push("same", A[i++]), j++;
    else if (dp[i + 1][j] >= dp[i][j + 1]) push("removed", A[i++]);
    else push("added", B[j++]);
  }
  while (i < n) push("removed", A[i++]);
  while (j < m) push("added", B[j++]);
  return out;
}

const diffSegments = computed(() =>
  aiPreview.value ? wordDiff(aiPreview.value.old_text, aiPreview.value.new_text) : [],
);

async function runAiEdit() {
  if (!lead.value || aiEditLoading.value || !instruction.value.trim()) return;
  aiEditLoading.value = true;
  startAiTask("AI is editing your proposal…", 30000);
  try {
    const payload = { instruction: instruction.value };
    if (selection.value) {
      payload.selection_start = selection.value.start;
      payload.selection_end = selection.value.end;
    }
    aiPreview.value = await aiEditProposal(lead.value.id, payload);
    finishAiTask();
  } catch (error) {
    failAiTask();
    toast.error(apiErrorMessage(error, "Could not run the AI edit."));
  } finally {
    aiEditLoading.value = false;
  }
}

async function acceptAiEdit() {
  if (!lead.value || !aiPreview.value || aiEditLoading.value) return;
  aiEditLoading.value = true;
  try {
    const p = aiPreview.value;
    lead.value = await acceptAiEditProposal(lead.value.id, {
      proposal_text: p.new_text,
      edit_type: p.edit_type,
      instruction: instruction.value,
      model: p.model,
      selection_start: selection.value?.start ?? null,
      selection_end: selection.value?.end ?? null,
    });
    aiPreview.value = null;
    instruction.value = "";
    clearSelection();
    if (showHistory.value) await loadVersions();
    toast.success("AI edit applied.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not apply the edit."));
  } finally {
    aiEditLoading.value = false;
  }
}

function discardAiEdit() {
  aiPreview.value = null;
}

const regenLoading = ref(null);

const subScoreBlocks = computed(() => {
  const subScores = lead.value?.sub_scores;
  if (!subScores) return [];
  return [
    { key: "client_quality", label: "Client quality", weight: "30%" },
    { key: "competition", label: "Competition & timing", weight: "25%" },
    { key: "stack_fit", label: "Stack fit", weight: "20%" },
    { key: "budget", label: "Budget", weight: "15%" },
    { key: "post_quality", label: "Post quality", weight: "10%" },
  ]
    .filter((block) => subScores[block.key] != null)
    .map((block) => ({ ...block, value: subScores[block.key] }));
});

async function handleRegenerateScore() {
  if (!lead.value || regenLoading.value) return;
  regenLoading.value = "score";
  // Live scoring runs 5-20s (rubric + sub-scores on the current model).
  startAiTask("Re-scoring under your rubric…", 18000);
  try {
    lead.value = await regenerateLeadScore(lead.value.id);
    finishAiTask();
    toast.success(`Re-scored: ${lead.value.score}/10.`);
  } catch (error) {
    failAiTask();
    toast.error(apiErrorMessage(error, "Could not re-score."));
  } finally {
    regenLoading.value = null;
  }
}

async function handleRegenerateProposal() {
  if (!lead.value || regenLoading.value) return;
  regenLoading.value = "proposal";
  // Draft + rule review + possible auto-revision — longer than a plain draft.
  startAiTask("Writing & rule-checking your proposal…", 40000);
  try {
    lead.value = await regenerateLeadProposal(lead.value.id);
    finishAiTask();
    if (showHistory.value) await loadVersions();
    toast.success("Fresh proposal written under your rules.");
  } catch (error) {
    failAiTask();
    toast.error(apiErrorMessage(error, "Could not write the proposal."));
  } finally {
    regenLoading.value = null;
  }
}
</script>

<template>
  <PageContainer v-if="isLoading" class="max-w-[760px]">
    <Skeleton class="mb-4 h-5 w-32" />
    <Card class="p-6">
      <SkeletonText :lines="6" />
    </Card>
  </PageContainer>

  <PageContainer v-else-if="!lead" class="max-w-[760px]">
    <EmptyState title="Lead not found" description="It may have been removed.">
      <template #action>
        <Button @click="router.push('/leads')">Back to leads</Button>
      </template>
    </EmptyState>
  </PageContainer>

  <PageContainer v-else class="max-w-[1600px]">
    <router-link
      to="/leads"
      class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-text-secondary hover:text-primary"
    >
      <ArrowLeft class="h-4 w-4" /> Back to leads
    </router-link>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_320px] lg:items-start">
      <!-- Main: what a bidder actually reads, top to bottom -->
      <div class="min-w-0 space-y-4">
        <Card class="p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h1 class="text-xl font-semibold text-text-primary">{{ lead.title }}</h1>
              <p class="mt-1 text-xs text-text-tertiary">
                Posted {{ relativeTime(lead.posted_at) }} · External ID {{ lead.external_id }}
              </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <span
                v-if="lead.notify_error"
                class="inline-flex items-center gap-1 rounded-pill border border-danger/40 bg-danger-bg px-2 py-0.5 text-xs font-semibold text-danger"
                :title="lead.notify_error"
              >
                <AlertTriangle class="h-3 w-3" /> WhatsApp alert failed
              </span>
              <span
                v-else-if="lead.notification_skipped_reason"
                class="rounded-pill border border-border bg-neutral-bg px-2 py-0.5 text-xs font-medium text-text-tertiary"
                :title="lead.notification_skipped_reason"
              >
                not sent to phone — stale
              </span>
              <StatusPill :status="lead.status" />
              <ScoreBadge :score="lead.score" />
            </div>
          </div>

          <a
            v-if="lead.url"
            :href="lead.url"
            target="_blank"
            rel="noreferrer"
            class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
          >
            View on Upwork <ExternalLink class="h-3.5 w-3.5" />
          </a>

          <div
            v-if="lead.matches_filter === false && lead.filter_fail_reasons"
            class="mt-4 rounded-md border border-danger-border bg-danger-bg px-4 py-3"
          >
            <p class="flex items-center gap-1.5 text-sm font-semibold text-danger">
              <AlertTriangle class="h-4 w-4" /> Why this job isn't in your filter
            </p>
            <ul class="mt-2 space-y-1">
              <li
                v-for="reason in lead.filter_fail_reasons"
                :key="reason"
                class="flex gap-1.5 text-sm text-danger/90"
              >
                <span aria-hidden="true">•</span>
                {{ reason }}
              </li>
            </ul>
          </div>

          <div v-if="lead.skills?.length" class="mt-4 flex flex-wrap items-center gap-1.5">
            <span
              v-for="skill in lead.skills"
              :key="skill"
              :class="
                cn(
                  'rounded-pill border px-2.5 py-1 text-xs font-medium',
                  isMatchedSkill(skill)
                    ? 'border-warning-border bg-warning-bg text-warning'
                    : 'border-border-strong text-text-secondary',
                )
              "
            >
              {{ skill }}
            </span>
          </div>

          <div class="mt-5 border-t border-border pt-5">
            <p class="text-sm font-semibold text-text-primary">Full brief</p>
            <p class="mt-2 text-sm whitespace-pre-wrap text-text-secondary">{{ lead.full_brief }}</p>
          </div>
        </Card>

        <Card class="p-6">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span
                v-if="regenLoading === 'score'"
                class="inline-flex items-center gap-1.5 rounded-pill bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
              >
                <RefreshCw class="h-3.5 w-3.5 animate-spin" /> Scoring…
              </span>
              <ScoreBadge v-else-if="lead.score !== null" :score="lead.score" />
              <p class="text-sm font-semibold text-text-primary">
                {{ lead.score !== null ? "Why this score" : "Not scored yet" }}
              </p>
              <span
                v-if="lead.boost"
                class="rounded-pill border border-success/30 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success"
              >
                BOOST
              </span>
            </div>
            <Button
              variant="secondary"
              size="sm"
              :disabled="regenLoading !== null"
              title="Score this lead under your current rubric"
              @click="handleRegenerateScore"
            >
              <RefreshCw :class="['h-3.5 w-3.5', regenLoading === 'score' && 'animate-spin']" />
              {{
                regenLoading === "score" ? "Scoring…" : lead.score !== null ? "Re-score" : "Score now"
              }}
            </Button>
          </div>
          <p
            v-if="lead.score_reason"
            :class="[
              'mt-2 text-sm text-text-secondary transition-opacity',
              regenLoading === 'score' && 'animate-pulse opacity-50',
            ]"
          >
            {{ lead.score_reason }}
          </p>
          <p v-else-if="lead.score === null && regenLoading !== 'score'" class="mt-2 text-sm text-text-tertiary">
            Run the rubric to get the score, verdict, and the five-pillar breakdown.
          </p>

          <div
            v-if="subScoreBlocks.length"
            class="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-4 sm:grid-cols-5"
          >
            <div v-for="block in subScoreBlocks" :key="block.key">
              <div class="flex items-baseline justify-between gap-1">
                <p class="truncate text-xs font-medium text-text-tertiary" :title="block.label">
                  {{ block.label }}
                </p>
                <span class="text-[10px] text-text-tertiary/70">{{ block.weight }}</span>
              </div>
              <p class="mt-0.5 text-sm font-semibold text-text-primary">{{ block.value }}/10</p>
              <div class="mt-1 h-1.5 overflow-hidden rounded-pill bg-neutral-bg">
                <div
                  :class="[
                    'h-full rounded-pill transition-all',
                    block.value >= 7 ? 'bg-success' : block.value >= 5 ? 'bg-warning' : 'bg-danger',
                  ]"
                  :style="{ width: `${block.value * 10}%` }"
                />
              </div>
            </div>
          </div>
        </Card>

        <Card v-if="lead.proposal_text || lead.score !== null" class="border-l-[3px] border-l-primary p-6">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <p class="text-sm font-semibold text-text-primary">Proposal</p>
              <span
                v-if="lead.proposal_warnings?.length"
                class="inline-flex items-center gap-1 rounded-pill border border-danger/40 bg-danger-bg px-2 py-0.5 text-xs font-semibold text-danger"
              >
                <AlertTriangle class="h-3 w-3" />
                {{ lead.proposal_warnings.length }} rule
                {{ lead.proposal_warnings.length === 1 ? "violation" : "violations" }}
              </span>
            </div>
            <div v-if="!editing" class="flex items-center gap-2">
              <Button
                variant="secondary"
                size="sm"
                :disabled="regenLoading !== null"
                title="Write a fresh proposal under your rules"
                @click="handleRegenerateProposal"
              >
                <RefreshCw v-if="regenLoading === 'proposal'" class="h-3.5 w-3.5 animate-spin" />
                <Sparkles v-else class="h-3.5 w-3.5" />
                {{
                  regenLoading === "proposal"
                    ? "Writing…"
                    : lead.proposal_text
                      ? "Rewrite"
                      : "Write proposal"
                }}
              </Button>
              <Button
                v-if="lead.proposal_text"
                variant="secondary"
                size="sm"
                :disabled="regenLoading !== null"
                title="Edit the proposal by hand (re-checked against your rules)"
                @click="startEdit"
              >
                <Pencil class="h-3.5 w-3.5" /> Edit
              </Button>
              <Button v-if="lead.proposal_text" variant="secondary" size="sm" @click="handleCopy">
                <Copy class="h-3.5 w-3.5" /> Copy
              </Button>
            </div>
          </div>
          <div
            v-if="lead.proposal_warnings?.length"
            class="mt-3 rounded-md border border-danger-border bg-danger-bg px-4 py-3"
          >
            <p class="text-xs font-semibold text-danger">
              This proposal did not pass every rule — fix by hand or hit Rewrite:
            </p>
            <ul class="mt-1.5 space-y-1">
              <li
                v-for="warning in lead.proposal_warnings"
                :key="warning"
                class="flex gap-1.5 text-xs text-danger/90"
              >
                <span aria-hidden="true">•</span>
                {{ warning }}
              </li>
            </ul>
          </div>
          <!-- Edit mode: by-hand editing, re-linted on save. -->
          <div v-if="editing" class="mt-3">
            <Textarea v-model="draft" rows="14" class="text-sm" />
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
              <span class="text-xs text-text-tertiary">{{ draftWordCount }} words</span>
              <div class="flex items-center gap-2">
                <Button variant="ghost" size="sm" :disabled="savingProposal" @click="cancelEdit">
                  <X class="h-3.5 w-3.5" /> Cancel
                </Button>
                <Button
                  size="sm"
                  :loading="savingProposal"
                  :disabled="!draft.trim() || savingProposal"
                  @click="saveEdit"
                >
                  <Save class="h-3.5 w-3.5" /> Save edit
                </Button>
              </div>
            </div>
          </div>

          <p
            v-else-if="lead.proposal_text"
            ref="proposalRef"
            @mouseup="captureSelection"
            @touchend="captureSelection"
            :class="[
              'mt-3 rounded-md bg-neutral-bg p-4 text-sm whitespace-pre-wrap text-text-primary transition-opacity selection:bg-primary/20',
              regenLoading === 'proposal' && 'animate-pulse opacity-50',
            ]"
          >
            {{ lead.proposal_text }}
          </p>
          <p v-else class="mt-3 rounded-md bg-neutral-bg p-4 text-sm text-text-tertiary">
            No proposal yet — click "Write proposal" to draft one under your rules.
          </p>

          <!-- AI-assisted edit: instruction -> preview (diff + linter) -> accept/discard. -->
          <div v-if="canAiEdit" class="mt-3 rounded-md border border-primary/20 bg-primary/[0.03] p-3">
            <template v-if="aiPreview">
              <div class="flex items-center justify-between gap-2">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-primary">
                  <Wand2 class="h-3.5 w-3.5" /> AI edit preview
                </p>
                <span v-if="aiPreview.model" class="text-[10px] text-text-tertiary">{{ aiPreview.model }}</span>
              </div>
              <p class="mt-2 rounded-md bg-white/70 p-3 text-sm leading-relaxed whitespace-pre-wrap">
                <template v-for="(seg, i) in diffSegments" :key="i">
                  <del
                    v-if="seg.type === 'removed'"
                    class="bg-danger-bg text-danger line-through decoration-danger/50"
                    >{{ seg.text }}</del
                  >
                  <ins
                    v-else-if="seg.type === 'added'"
                    class="bg-success/15 text-success no-underline"
                    >{{ seg.text }}</ins
                  >
                  <span v-else>{{ seg.text }}</span>
                </template>
              </p>
              <div
                v-if="aiPreview.linter_violations?.length"
                class="mt-2 rounded-md border border-danger-border bg-danger-bg px-3 py-2"
              >
                <p class="text-[11px] font-semibold text-danger">
                  This edit breaks {{ aiPreview.linter_violations.length }} rule{{
                    aiPreview.linter_violations.length === 1 ? "" : "s"
                  }}
                  — you can still accept, but fixing first is safer:
                </p>
                <ul class="mt-1 space-y-0.5">
                  <li
                    v-for="w in aiPreview.linter_violations"
                    :key="w"
                    class="text-[11px] text-danger/90"
                  >
                    • {{ w }}
                  </li>
                </ul>
              </div>
              <div class="mt-2 flex items-center justify-end gap-2">
                <Button variant="ghost" size="sm" :disabled="aiEditLoading" @click="discardAiEdit">
                  <X class="h-3.5 w-3.5" /> Discard
                </Button>
                <Button size="sm" :loading="aiEditLoading" @click="acceptAiEdit">
                  <CheckIcon class="h-3.5 w-3.5" /> Accept
                </Button>
              </div>
            </template>

            <template v-else>
              <div
                v-if="selection"
                class="mb-2 flex items-center gap-1.5 rounded-pill border border-primary/30 bg-primary/10 px-2 py-1 text-[11px] text-primary"
              >
                <span class="truncate">Editing: "{{ selection.text.slice(0, 48) }}{{ selection.text.length > 48 ? "…" : "" }}"</span>
                <button type="button" class="shrink-0 hover:text-primary-hover" @click="clearSelection">
                  <X class="h-3 w-3" />
                </button>
              </div>
              <p v-else class="mb-2 text-[11px] text-text-tertiary">
                Highlight text in the proposal to edit just that part — or select nothing to revise
                the whole thing.
              </p>
              <div class="flex items-center gap-2">
                <Input
                  v-model="instruction"
                  placeholder="Tell AI what to change…"
                  class="flex-1"
                  @keyup.enter="runAiEdit"
                />
                <Button
                  size="sm"
                  :loading="aiEditLoading"
                  :disabled="!instruction.trim() || aiEditLoading"
                  @click="runAiEdit"
                >
                  <Wand2 class="h-3.5 w-3.5" /> Ask AI
                </Button>
              </div>
            </template>
          </div>

          <!-- Version history: append-only snapshots, newest first. -->
          <div v-if="lead.proposal_text && !editing" class="mt-4 border-t border-border pt-3">
            <button
              type="button"
              class="inline-flex items-center gap-1.5 text-xs font-semibold text-text-secondary hover:text-primary"
              @click="toggleHistory"
            >
              <History class="h-3.5 w-3.5" />
              {{ showHistory ? "Hide history" : "History" }}
              <span v-if="versions.length" class="text-text-tertiary">({{ versions.length }})</span>
            </button>

            <div v-if="showHistory" class="mt-3 space-y-2">
              <p v-if="versionsLoading" class="text-xs text-text-tertiary">Loading…</p>
              <p v-else-if="!versions.length" class="text-xs text-text-tertiary">
                No saved versions yet — a rewrite or a manual edit adds one.
              </p>
              <div
                v-for="v in versions"
                :key="v.id"
                class="rounded-md border border-border bg-neutral-bg/50"
              >
                <button
                  type="button"
                  class="flex w-full flex-wrap items-center gap-2 px-3 py-2 text-left"
                  @click="openVersionId = openVersionId === v.id ? null : v.id"
                >
                  <span class="text-xs font-semibold text-text-primary">v{{ v.version_number }}</span>
                  <span class="rounded-pill border border-border-strong px-1.5 py-0.5 text-[10px] font-medium text-text-secondary">
                    {{ editTypeLabels[v.edit_type] ?? v.edit_type }}
                  </span>
                  <span
                    v-if="v.is_sent"
                    class="inline-flex items-center gap-1 rounded-pill border border-success/30 bg-success/10 px-1.5 py-0.5 text-[10px] font-semibold text-success"
                  >
                    <Send class="h-2.5 w-2.5" /> Sent
                  </span>
                  <span
                    v-if="v.linter_violation_count"
                    class="inline-flex items-center gap-1 rounded-pill border border-danger/40 bg-danger-bg px-1.5 py-0.5 text-[10px] font-semibold text-danger"
                  >
                    <AlertTriangle class="h-2.5 w-2.5" /> {{ v.linter_violation_count }}
                  </span>
                  <span class="ml-auto text-[10px] text-text-tertiary">
                    {{ v.word_count }}w · {{ relativeTime(v.created_at) }}
                  </span>
                </button>
                <p
                  v-if="openVersionId === v.id"
                  class="whitespace-pre-wrap border-t border-border px-3 py-2 text-xs text-text-secondary"
                >
                  {{ v.body }}
                </p>
              </div>
            </div>
          </div>
        </Card>
      </div>

      <!-- Sticky rail: reference info + actions stay reachable without
           scrolling back up past a long brief/proposal. -->
      <div class="space-y-4 lg:sticky lg:top-20">
        <Card class="p-5">
          <p class="mb-3 text-sm font-semibold text-text-primary">Details</p>
          <dl class="space-y-2.5">
            <div v-for="block in statBlocks" :key="block.label" class="flex items-start justify-between gap-3 text-sm">
              <dt class="flex shrink-0 items-center gap-1.5 text-text-tertiary">
                <component
                  :is="block.icon"
                  :class="block.tone === 'success' ? 'h-3.5 w-3.5 text-success' : 'h-3.5 w-3.5'"
                />
                {{ block.label }}
              </dt>
              <dd class="min-w-0 truncate text-right font-medium text-text-primary">{{ block.value }}</dd>
            </div>
          </dl>
        </Card>

        <Card class="p-5">
          <p class="mb-3 text-sm font-semibold text-text-primary">Actions</p>
          <div class="flex flex-col gap-2">
            <Button
              v-for="action in statusActions"
              :key="action.status"
              :variant="lead.status === action.status ? 'primary' : 'secondary'"
              size="sm"
              class="justify-center"
              :disabled="lead.status === action.status || actionLoading !== null"
              :loading="actionLoading === action.status"
              @click="handleStatus(action.status)"
            >
              {{ action.label }}
            </Button>
          </div>

          <!-- Outcome tracking: what actually happened, independent of the
               status pipeline above. Only meaningful once this was really
               sent (submitted_at set), regardless of current status. -->
          <div v-if="lead.submitted_at" class="mt-4 space-y-2.5 border-t border-border pt-4">
            <button
              type="button"
              :disabled="viewedLoading"
              @click="handleToggleViewed"
              :class="
                cn(
                  'flex w-full items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors disabled:opacity-50',
                  lead.viewed_at
                    ? 'border-success/30 bg-success/10 text-success'
                    : 'border-border-strong text-text-secondary hover:bg-black/5',
                )
              "
            >
              <Eye v-if="lead.viewed_at" class="h-4 w-4" />
              <EyeOff v-else class="h-4 w-4" />
              {{ lead.viewed_at ? "Client viewed this" : "Client viewed this?" }}
            </button>

            <div>
              <label class="mb-1 block text-xs font-medium text-text-tertiary">Outcome</label>
              <select
                :value="lead.outcome ?? ''"
                :disabled="outcomeLoading"
                @change="handleOutcomeChange"
                class="h-9 w-full rounded-md border border-border-strong bg-white px-2.5 text-sm text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
              >
                <option value="">Not set yet</option>
                <option v-for="o in LEAD_OUTCOMES" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </div>
          </div>

          <router-link
            v-if="lead.client_id"
            :to="`/clients/${lead.client_id}`"
            class="mt-4 flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
          >
            <MessageSquare class="h-4 w-4" /> Open client memory
          </router-link>
        </Card>
      </div>
    </div>
  </PageContainer>
</template>
