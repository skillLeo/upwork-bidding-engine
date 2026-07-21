<script setup>
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { ShieldCheck, Loader2 } from "@lucide/vue";
import StatusPill from "@/components/ui/StatusPill.vue";
import { updateLeadStatus } from "@/composables/useLead";
import { apiErrorMessage } from "@/lib/api-client";
import { cn, scoreTier, compactAge, urgencyTier } from "@/lib/utils";

const router = useRouter();

const props = defineProps({
  lead: { type: Object, required: true },
  activeFilterId: { type: [Number, null], default: null },
});

const to = computed(() =>
  props.activeFilterId != null
    ? { path: `/leads/${props.lead.id}`, query: { filter: props.activeFilterId } }
    : { path: `/leads/${props.lead.id}` },
);

const tier = computed(() => scoreTier(props.lead.score));
const scoreBadgeClass = computed(
  () =>
    ({
      success: "bg-success-bg text-success",
      info: "bg-info-bg text-info",
      neutral: "bg-neutral-bg text-text-tertiary",
    })[tier.value],
);

const age = computed(() => compactAge(props.lead.posted_at));

// Same weight-based priority encoding as the desktop row: worked leads recede,
// hot ones lean forward. No colored border strip here either.
const isDone = computed(() => ["sent", "replied", "won", "archived"].includes(props.lead.status));
const isHot = computed(
  () => props.lead.status === "ready" && (props.lead.score ?? 0) >= 7 && age.value.tier === "fresh",
);
const titleClass = computed(() =>
  isDone.value
    ? "font-normal text-text-tertiary"
    : isHot.value
      ? "font-semibold text-text-primary"
      : "font-medium text-text-secondary",
);

const urgency = computed(() => urgencyTier(props.lead.posted_at));
const urgencyBadgeClass = computed(
  () =>
    ({
      green: "bg-success-bg text-success",
      amber: "bg-warning-bg text-warning",
      red: "bg-danger-bg text-danger",
    })[urgency.value] ?? "bg-neutral-bg text-text-tertiary",
);

const showPulse = computed(
  () => props.lead.status === "ready" && (props.lead.score ?? 0) >= 8 && urgency.value === "green",
);

const skipping = ref(false);
async function handleSkip() {
  skipping.value = true;
  try {
    const updated = await updateLeadStatus(props.lead.id, "archived");
    props.lead.status = updated.status;
    toast.success("Lead skipped.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not skip this lead."));
  } finally {
    skipping.value = false;
  }
}

function handleOpen() {
  router.push(to.value);
}
</script>

<template>
  <div
    :class="
      cn(
        'space-y-2.5 rounded-lg border border-border bg-surface p-3.5 active:bg-black/[0.02]',
        lead.matches_filter === false && 'opacity-60',
        showPulse && 'lead-card-pulse',
      )
    "
  >
    <div class="flex items-start justify-between gap-2">
      <span
        :class="cn('flex h-7 min-w-7 shrink-0 items-center justify-center rounded-md px-1.5 font-mono text-sm font-bold tabular-nums', scoreBadgeClass)"
      >
        {{ lead.score ?? "–" }}
      </span>

      <span
        v-if="urgency"
        :title="age.title"
        :class="cn('rounded-pill px-2 py-0.5 font-mono text-[11px] font-semibold tabular-nums', urgencyBadgeClass)"
      >
        {{ age.label }}
      </span>
      <span v-else class="font-mono text-xs text-text-tertiary">{{ age.label }}</span>
    </div>

    <button type="button" class="block w-full text-left" @click="handleOpen">
      <p class="line-clamp-2 text-sm" :class="titleClass">{{ lead.title }}</p>
    </button>

    <div class="flex flex-wrap items-center gap-1.5 text-xs text-text-secondary">
      <span class="rounded-pill border border-border-strong px-2 py-0.5 font-mono">{{ lead.budget || "—" }}</span>
      <span class="rounded-pill border border-border-strong px-2 py-0.5">{{ lead.proposal_count }} proposals</span>
      <span class="flex items-center gap-1 rounded-pill border border-border-strong px-2 py-0.5">
        <ShieldCheck v-if="lead.payment_verified" class="h-3 w-3 shrink-0 text-success" />
        {{ lead.client_country || "—" }}
      </span>
      <StatusPill :status="lead.status" />
    </div>

    <p v-if="lead.score_reason" class="line-clamp-1 text-xs text-text-tertiary">
      {{ lead.score_reason }}
    </p>

    <div class="flex items-center gap-2 pt-1">
      <button
        type="button"
        @click="handleOpen"
        class="h-9 flex-1 rounded-md bg-primary text-sm font-semibold text-white transition-colors active:bg-primary/90"
      >
        Open
      </button>
      <button
        type="button"
        @click="handleSkip"
        :disabled="skipping || lead.status === 'archived'"
        class="flex h-9 flex-1 items-center justify-center gap-1.5 rounded-md border border-border-strong text-sm font-medium text-text-secondary transition-colors active:bg-black/5 disabled:opacity-50"
      >
        <Loader2 v-if="skipping" class="h-3.5 w-3.5 animate-spin" />
        {{ lead.status === "archived" ? "Skipped" : "Skip" }}
      </button>
    </div>
  </div>
</template>

<style scoped>
/* Same pulse rule as the desktop row: score 8+, ready, under an hour old. */
.lead-card-pulse {
  animation: lead-card-pulse-ring 2.5s ease-in-out infinite;
}

@keyframes lead-card-pulse-ring {
  0%,
  100% {
    box-shadow: 0 0 0 1.5px var(--color-success);
  }
  50% {
    box-shadow: 0 0 0 1.5px color-mix(in srgb, var(--color-success) 35%, transparent);
  }
}

@media (prefers-reduced-motion: reduce) {
  .lead-card-pulse {
    animation: none;
    box-shadow: 0 0 0 1.5px var(--color-success);
  }
}
</style>
