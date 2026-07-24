<script setup>
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { Bookmark, ChevronRight, Copy, ExternalLink, Loader2, ShieldCheck, Star } from "@lucide/vue";
import StatusPill from "@/components/ui/StatusPill.vue";
import Button from "@/components/ui/Button.vue";
import {
  toggleLeadFavorite,
  updateLeadStatus,
  updateLeadClientView,
  updateLeadOutcome,
  CLIENT_VIEW_STATES,
  reasonsForStatus,
  reasonLabel,
} from "@/composables/useLead";
import { apiErrorMessage } from "@/lib/api-client";
import { cn, scoreTier, compactAge, urgencyTier } from "@/lib/utils";

const router = useRouter();

const props = defineProps({
  lead: { type: Object, required: true },
  activeFilterId: { type: [Number, null], default: null },
  // Skills that match the active saved filter's keywords get highlighted,
  // same idea as Vollna bolding a matched keyword in a job title.
  matchKeywords: { type: Array, default: () => [] },
  selected: { type: Boolean, default: false },
});
const emit = defineEmits(["toggle-select"]);

// Local to this row — nothing coordinates it with siblings, so any number
// of rows can be previewed open at once, same as Vollna's list.
const expanded = ref(false);
const favoriting = ref(false);
const statusLoading = ref(null);

const to = computed(() =>
  props.activeFilterId != null
    ? { path: `/leads/${props.lead.id}`, query: { filter: props.activeFilterId } }
    : { path: `/leads/${props.lead.id}` },
);

const tier = computed(() => scoreTier(props.lead.score));
const age = computed(() => compactAge(props.lead.posted_at));

// Priority is encoded through TYPOGRAPHY WEIGHT, not a colored left-border
// strip (a well-known AI-generated-UI tell): worked leads recede, hot ones
// lean forward. Color stays reserved for the score number and the age chip.
const isDone = computed(() => ["sent", "replied", "won", "archived"].includes(props.lead.status));
const isHot = computed(
  () => props.lead.status === "ready" && (props.lead.score ?? 0) >= 7 && age.value.tier === "fresh",
);

const scoreClass = computed(() =>
  isDone.value
    ? "text-text-tertiary"
    : { success: "text-success", info: "text-info", neutral: "text-text-tertiary" }[tier.value],
);
const titleClass = computed(() =>
  isDone.value
    ? "font-normal text-text-tertiary"
    : isHot.value
      ? "font-semibold text-text-primary"
      : "font-medium text-text-secondary",
);
const ageClass = computed(() =>
  cn(
    age.value.tier === "fresh" && "font-semibold text-text-primary",
    age.value.tier === "normal" && "text-text-secondary",
    age.value.tier === "stale" && "text-text-tertiary",
  ),
);

// Urgency badge: green/amber/red at 60/180 minutes, independent of the
// plain age text above — this is the "act now" signal, not just an age
// readout, so it gets its own tighter bands and its own color.
const urgency = computed(() => urgencyTier(props.lead.posted_at));
const urgencyBadgeClass = computed(
  () =>
    ({
      green: "bg-success-bg text-success",
      amber: "bg-warning-bg text-warning",
      red: "bg-danger-bg text-danger",
    })[urgency.value] ?? "text-text-tertiary",
);

// The pulse ring is the strongest visual affordance on the board, so it's
// reserved for leads that are both worth acting on (score 8+) and still
// actionable (ready, not yet bid or skipped) and still in the golden
// window (under an hour old) — never a decoration on its own.
const showPulse = computed(
  () => props.lead.status === "ready" && (props.lead.score ?? 0) >= 8 && urgency.value === "green",
);

function isMatchedSkill(skill) {
  return props.matchKeywords.some((kw) => skill.toLowerCase().includes(kw.toLowerCase()));
}

async function handleCopyProposal() {
  if (!props.lead.proposal_text) return;
  await navigator.clipboard.writeText(props.lead.proposal_text);
  toast.success("Proposal copied to clipboard.");
}

async function handleToggleFavorite() {
  favoriting.value = true;
  try {
    const updated = await toggleLeadFavorite(props.lead.id);
    props.lead.is_favorite = updated.is_favorite;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update favorite."));
  } finally {
    favoriting.value = false;
  }
}

const quickActions = [
  { status: "sent", label: "Sent" },
  { status: "replied", label: "Replied" },
  { status: "won", label: "Won" },
  { status: "lost", label: "Lost" },
  { status: "archived", label: "Not interested" },
];

// Post-send tracking. Neither field repeats the status pills — status owns
// Replied and Won; these record what only Upwork can tell you.
const viewedLoading = ref(false);
const outcomeLoading = ref(false);

const reasonOptions = computed(() => reasonsForStatus(props.lead.status));

async function handleClientViewChange(event) {
  const value = event.target.value || null;
  viewedLoading.value = true;
  try {
    const updated = await updateLeadClientView(props.lead.id, value);
    props.lead.client_view = updated.client_view;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update."));
  } finally {
    viewedLoading.value = false;
  }
}

async function handleOutcomeChange(event) {
  const value = event.target.value || null;
  outcomeLoading.value = true;
  try {
    const updated = await updateLeadOutcome(props.lead.id, value);
    props.lead.outcome = updated.outcome;
    props.lead.outcome_at = updated.outcome_at;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update outcome."));
  } finally {
    outcomeLoading.value = false;
  }
}

async function handleQuickStatus(status) {
  statusLoading.value = status;
  try {
    const updated = await updateLeadStatus(props.lead.id, status);
    props.lead.status = updated.status;
    toast.success(`Lead marked ${status}.`);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update status."));
  } finally {
    statusLoading.value = null;
  }
}

// The row itself is the one tab stop (score/title/budget/etc are all
// display:contents, and the chevron button is tabindex="-1") so keyboard
// users get Enter-to-open and Space-to-preview without tabbing through
// every cell, and Up/Down jump row to row the way a fast scan needs to.
function handleRowKeydown(event) {
  if (event.key === "Enter") {
    router.push(to.value);
    return;
  }
  if (event.key === " ") {
    event.preventDefault();
    expanded.value = !expanded.value;
    return;
  }
  if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
  event.preventDefault();
  const rows = Array.from(document.querySelectorAll("[data-lead-row]"));
  const index = rows.indexOf(event.currentTarget);
  const next = rows[index + (event.key === "ArrowDown" ? 1 : -1)];
  next?.focus();
}
</script>

<template>
  <div
    data-lead-row
    tabindex="0"
    role="link"
    :aria-label="`Open ${lead.title}, score ${lead.score ?? 'not scored'}`"
    @keydown="handleRowKeydown"
    :class="
      cn(
        'leads-row-grid min-h-9 items-center border-b border-border px-3 text-sm text-text-primary',
        lead.matches_filter === false && 'opacity-60',
        expanded && 'bg-neutral-bg/40',
        showPulse && 'lead-row-pulse',
      )
    "
  >
    <div class="flex h-full items-center justify-center">
      <input
        type="checkbox"
        :checked="selected"
        @change="emit('toggle-select', lead.id)"
        :aria-label="`Select ${lead.title}`"
        class="h-3.5 w-3.5 rounded border-border-strong text-primary focus:ring-2 focus:ring-primary/30"
      />
    </div>

    <button
      type="button"
      tabindex="-1"
      @click="expanded = !expanded"
      :aria-expanded="expanded"
      :aria-label="expanded ? 'Hide quick preview' : 'Show quick preview'"
      class="relative flex h-full items-center justify-center border-l border-border transition-colors hover:bg-black/5"
    >
      <ChevronRight
        class="h-3.5 w-3.5 shrink-0 text-text-tertiary transition-transform"
        :class="expanded && 'rotate-90 text-primary'"
      />
      <Bookmark
        v-if="lead.is_favorite"
        class="absolute top-1 right-1 h-2.5 w-2.5 fill-current text-primary"
        aria-hidden="true"
      />
    </button>

    <router-link :to="to" tabindex="-1" class="contents">
      <span class="font-mono text-sm font-bold tabular-nums" :class="scoreClass">
        <span class="sr-only">Score </span>{{ lead.score ?? "–" }}
      </span>

      <span class="flex min-w-0 items-center gap-1.5 py-2">
        <span class="truncate" :class="titleClass">{{ lead.title }}</span>
        <span
          v-if="lead.matches_filter === false"
          class="shrink-0 rounded-pill bg-danger-bg px-1.5 py-0 text-[10px] font-semibold whitespace-nowrap text-danger"
        >
          Not in filter
        </span>
      </span>

      <span
        class="truncate text-right font-mono text-xs text-text-secondary"
        :title="lead.budget || undefined"
      >
        {{ lead.budget || "—" }}
      </span>

      <span class="text-right font-mono text-sm font-semibold tabular-nums">
        {{ lead.proposal_count }}<span class="sr-only"> proposals</span>
      </span>

      <span class="text-right font-mono text-xs text-text-secondary">
        {{ lead.connects_required ?? "—" }}<span class="sr-only"> connects required</span>
      </span>

      <span :title="age.title">
        <span
          v-if="urgency"
          class="inline-flex rounded-pill px-1.5 py-0.5 font-mono text-[11px] font-semibold tabular-nums"
          :class="urgencyBadgeClass"
        >
          <span class="sr-only">Posted </span>{{ age.label }}
        </span>
        <span v-else class="font-mono text-xs" :class="ageClass">{{ age.label }}</span>
      </span>

      <span class="flex min-w-0 items-center gap-1 truncate text-xs text-text-secondary">
        <ShieldCheck
          v-if="lead.payment_verified"
          class="h-3 w-3 shrink-0 text-success"
          title="Payment verified"
        />
        <span class="truncate">{{ lead.client_country || "—" }}</span>
      </span>

      <span class="flex items-center overflow-hidden">
        <StatusPill :status="lead.status" />
      </span>
    </router-link>
  </div>

  <div
    v-if="expanded"
    class="space-y-3 border-b border-l border-border bg-neutral-bg/40 px-4 py-3 pl-[31px] text-sm"
  >
    <div v-if="lead.skills?.length" class="flex flex-wrap items-center gap-1.5">
      <span
        v-for="skill in lead.skills"
        :key="skill"
        :class="
          cn(
            'rounded-pill border px-2.5 py-0.5 text-xs font-medium',
            isMatchedSkill(skill)
              ? 'border-warning-border bg-warning-bg text-warning'
              : 'border-border-strong text-text-secondary',
          )
        "
      >
        {{ skill }}
      </span>
    </div>

    <p v-if="lead.score_reason" class="text-text-secondary">
      <span class="font-semibold text-text-primary">Why this score —</span>
      {{ lead.score_reason }}
    </p>

    <p v-if="lead.full_brief" class="line-clamp-3 text-text-secondary">
      {{ lead.full_brief }}
    </p>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-text-secondary">
      <span v-if="lead.client_rating != null" class="flex items-center gap-1">
        <Star class="h-3 w-3 fill-current text-warning" />
        {{ lead.client_rating }}
        <template v-if="lead.client_reviews != null">({{ lead.client_reviews }} reviews)</template>
      </span>
      <span v-if="lead.client_hire_rate">Hire rate {{ lead.client_hire_rate }}</span>
      <span v-if="lead.client_spend">Client spend {{ lead.client_spend }}</span>
    </div>

    <div class="flex flex-wrap items-center gap-2 pt-0.5">
      <button
        type="button"
        @click="handleToggleFavorite"
        :disabled="favoriting"
        :class="
          cn(
            'flex items-center gap-1.5 rounded-pill border px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50',
            lead.is_favorite
              ? 'border-primary bg-primary-tint text-primary'
              : 'border-border-strong text-text-secondary hover:bg-black/5',
          )
        "
      >
        <Loader2 v-if="favoriting" class="h-3 w-3 animate-spin" />
        <Bookmark v-else class="h-3 w-3" :class="lead.is_favorite && 'fill-current'" />
        {{ lead.is_favorite ? "Saved" : "Save" }}
      </button>

      <Button
        v-if="lead.proposal_text"
        variant="ghost"
        size="sm"
        @click="handleCopyProposal"
      >
        <Copy class="h-3 w-3" /> Copy proposal
      </Button>

      <Button
        v-for="action in quickActions"
        :key="action.status"
        variant="ghost"
        size="sm"
        :disabled="lead.status === action.status || statusLoading !== null"
        :loading="statusLoading === action.status"
        @click="handleQuickStatus(action.status)"
      >
        Mark {{ action.label }}
      </Button>
    </div>

    <!-- Outcome tracking: independent of status, only meaningful once this
         was really sent (submitted_at set), regardless of current status. -->
    <div v-if="lead.submitted_at" class="flex flex-wrap items-center gap-2 pt-0.5">
      <select
        :value="lead.client_view ?? ''"
        :disabled="viewedLoading"
        @change="handleClientViewChange"
        @click.stop
        class="h-7 rounded-pill border border-border-strong bg-white px-2.5 text-xs font-medium text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
      >
        <option value="">Client: haven't checked</option>
        <option v-for="c in CLIENT_VIEW_STATES" :key="c.value" :value="c.value">Client: {{ c.label }}</option>
      </select>

      <span
        v-if="lead.outcome === 'auto_filtered'"
        class="rounded-pill border border-border px-2.5 py-1 text-xs font-medium text-text-tertiary"
      >
        {{ reasonLabel(lead.outcome) }}
      </span>
      <select
        v-else-if="reasonOptions.length > 0"
        :value="lead.outcome ?? ''"
        :disabled="outcomeLoading"
        @change="handleOutcomeChange"
        @click.stop
        class="h-7 rounded-pill border border-border-strong bg-white px-2.5 text-xs font-medium text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
      >
        <option value="">Reason: not recorded</option>
        <option v-for="o in reasonOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </div>

    <div class="flex items-center gap-4 pt-0.5 text-xs font-medium">
      <a
        v-if="lead.url"
        :href="lead.url"
        target="_blank"
        rel="noopener noreferrer"
        class="flex items-center gap-1 text-text-secondary hover:text-text-primary"
      >
        Open on Upwork <ExternalLink class="h-3 w-3" />
      </a>
      <router-link :to="to" class="flex items-center gap-1 text-primary hover:underline">
        View full details <ChevronRight class="h-3 w-3" />
      </router-link>
    </div>
  </div>
</template>

<style scoped>
/* Reserved for score 8+, ready, under an hour old — the single strongest
   visual affordance on the board, so it stays subtle (a soft ring, no
   flashing) or it gets tuned out and ignored within a week. */
.lead-row-pulse {
  animation: lead-row-pulse-ring 2.5s ease-in-out infinite;
}

@keyframes lead-row-pulse-ring {
  0%,
  100% {
    box-shadow: inset 3px 0 0 0 var(--color-success);
  }
  50% {
    box-shadow: inset 3px 0 0 0 color-mix(in srgb, var(--color-success) 35%, transparent);
  }
}

@media (prefers-reduced-motion: reduce) {
  .lead-row-pulse {
    animation: none;
    box-shadow: inset 3px 0 0 0 var(--color-success);
  }
}
</style>
