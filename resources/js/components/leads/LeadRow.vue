<script setup>
import { computed } from "vue";
import { ShieldCheck } from "@lucide/vue";
import StatusPill from "@/components/ui/StatusPill.vue";
import { cn, scoreTier, compactAge } from "@/lib/utils";

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
const age = computed(() => compactAge(props.lead.posted_at));

const railClass = computed(
  () =>
    ({ success: "bg-success", info: "bg-info", neutral: "bg-neutral" })[tier.value],
);
const scoreClass = computed(
  () => ({ success: "text-success", info: "text-info", neutral: "text-text-tertiary" })[tier.value],
);
const ageClass = computed(() =>
  cn(
    age.value.tier === "fresh" && "font-semibold text-text-primary",
    age.value.tier === "normal" && "text-text-secondary",
    age.value.tier === "stale" && "text-text-tertiary",
  ),
);
</script>

<template>
  <router-link
    :to="to"
    :class="
      cn(
        'leads-row-grid min-h-9 items-center border-b border-border px-3 text-sm text-text-primary transition-colors hover:bg-black/[0.035]',
        lead.matches_filter === false && 'opacity-60',
      )
    "
  >
    <span :class="cn('h-full', railClass)" />

    <span class="font-mono text-sm font-bold tabular-nums" :class="scoreClass">
      <span class="sr-only">Score </span>{{ lead.score ?? "–" }}
    </span>

    <span class="flex min-w-0 items-center gap-1.5">
      <span class="truncate font-medium">{{ lead.title }}</span>
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

    <span class="font-mono text-xs" :class="ageClass" :title="age.title">
      <span class="sr-only">Posted </span>{{ age.label }}
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
</template>
