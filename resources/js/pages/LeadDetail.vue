<script setup>
import { ref, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import {
  AlertTriangle,
  ArrowLeft,
  Coins,
  Copy,
  ExternalLink,
  Globe2,
  MessageSquare,
  RefreshCw,
  Sparkles,
  ShieldCheck,
  ShieldOff,
  Star,
  Users,
  Wallet,
} from "@lucide/vue";
import {
  useLead,
  updateLeadStatus,
  rescoreLead,
  regenerateLeadScore,
  regenerateLeadProposal,
} from "@/composables/useLead";
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
import { relativeTime } from "@/lib/utils";
import { apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

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

async function handleRescore() {
  if (!lead.value) return;
  actionLoading.value = "rescore";
  try {
    const updated = await rescoreLead(lead.value.id);
    lead.value = updated;
    toast.success("Rescoring started — check back shortly.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not rescore."));
  } finally {
    actionLoading.value = null;
  }
}

async function handleCopy() {
  if (!lead.value?.proposal_text) return;
  await navigator.clipboard.writeText(lead.value.proposal_text);
  toast.success("Proposal copied to clipboard.");
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
  startAiTask("Re-scoring under your rubric…", 5000);
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

  <PageContainer v-else class="max-w-[760px]">
    <router-link
      to="/leads"
      class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-text-secondary hover:text-primary"
    >
      <ArrowLeft class="h-4 w-4" /> Back to leads
    </router-link>

    <Card class="p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-xl font-semibold text-text-primary">{{ lead.title }}</h1>
          <p class="mt-1 text-xs text-text-tertiary">
            Posted {{ relativeTime(lead.posted_at) }} · External ID {{ lead.external_id }}
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
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

      <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div v-for="block in statBlocks" :key="block.label" class="rounded-md bg-neutral-bg p-3">
          <div class="flex items-center gap-1.5 text-xs font-medium text-text-tertiary">
            <component
              :is="block.icon"
              :class="block.tone === 'success' ? 'h-3.5 w-3.5 text-success' : 'h-3.5 w-3.5'"
            />
            {{ block.label }}
          </div>
          <p class="mt-1 text-sm font-medium text-text-primary">{{ block.value }}</p>
        </div>
      </div>

      <div class="mt-5 border-t border-border pt-5">
        <p class="text-sm font-semibold text-text-primary">Full brief</p>
        <p class="mt-2 text-sm whitespace-pre-wrap text-text-secondary">{{ lead.full_brief }}</p>
      </div>
    </Card>

    <Card v-if="lead.score !== null" class="mt-4 p-6">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <ScoreBadge :score="lead.score" />
          <p class="text-sm font-semibold text-text-primary">Why this score</p>
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
          title="Re-score this lead under your current rubric"
          @click="handleRegenerateScore"
        >
          <RefreshCw :class="['h-3.5 w-3.5', regenLoading === 'score' && 'animate-spin']" />
          {{ regenLoading === "score" ? "Scoring…" : "Re-score" }}
        </Button>
      </div>
      <p
        :class="[
          'mt-2 text-sm text-text-secondary transition-opacity',
          regenLoading === 'score' && 'animate-pulse opacity-50',
        ]"
      >
        {{ lead.score_reason }}
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

    <Card v-if="lead.proposal_text || lead.score !== null" class="mt-4 p-6">
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
        <div class="flex items-center gap-2">
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
      <p
        v-if="lead.proposal_text"
        :class="[
          'mt-3 rounded-md bg-neutral-bg p-4 text-sm whitespace-pre-wrap text-text-primary transition-opacity',
          regenLoading === 'proposal' && 'animate-pulse opacity-50',
        ]"
      >
        {{ lead.proposal_text }}
      </p>
      <p v-else class="mt-3 rounded-md bg-neutral-bg p-4 text-sm text-text-tertiary">
        No proposal yet — click "Write proposal" to draft one under your rules.
      </p>
    </Card>

    <Card class="mt-4 p-6">
      <p class="mb-3 text-sm font-semibold text-text-primary">Actions</p>
      <div class="flex flex-wrap gap-2">
        <Button
          v-for="action in statusActions"
          :key="action.status"
          :variant="lead.status === action.status ? 'primary' : 'secondary'"
          size="sm"
          :disabled="lead.status === action.status || actionLoading !== null"
          :loading="actionLoading === action.status"
          @click="handleStatus(action.status)"
        >
          {{ action.label }}
        </Button>
        <Button
          v-if="auth.isAdmin"
          variant="ghost"
          size="sm"
          :disabled="actionLoading !== null"
          :loading="actionLoading === 'rescore'"
          @click="handleRescore"
        >
          <RefreshCw class="h-3.5 w-3.5" /> Rescore
        </Button>
      </div>

      <router-link
        v-if="lead.client_id"
        :to="`/clients/${lead.client_id}`"
        class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
      >
        <MessageSquare class="h-4 w-4" /> Open client memory
      </router-link>
    </Card>
  </PageContainer>
</template>
