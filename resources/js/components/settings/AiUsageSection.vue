<script setup>
import { ref, computed, onMounted, onUnmounted } from "vue";
import { Wallet, TrendingUp, Calendar, CalendarDays, Target, FileText, RefreshCw } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import StatCard from "@/components/ui/StatCard.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import CostTrendChart from "@/components/settings/CostTrendChart.vue";
import { apiClient } from "@/lib/api-client";

const POLL_INTERVAL_MS = 60_000;

const data = ref(null);
const loading = ref(true);
const refreshing = ref(false);

async function load({ silent = false } = {}) {
  if (!silent) refreshing.value = true;
  try {
    const res = await apiClient.get("/ai-usage");
    data.value = res.data.data;
  } catch {
    // Keep the last-known numbers on screen rather than blanking the panel.
  } finally {
    loading.value = false;
    refreshing.value = false;
  }
}

let pollTimer;
onMounted(() => {
  load({ silent: true });
  pollTimer = setInterval(() => load({ silent: true }), POLL_INTERVAL_MS);
});
onUnmounted(() => clearInterval(pollTimer));

function money(value) {
  if (value === null || value === undefined) return "—";
  return `$${Number(value).toFixed(value < 1 ? 4 : 2)}`;
}

const PURPOSE_LABELS = {
  scoring: "Scoring",
  proposal: "Proposal draft",
  proposal_review: "Proposal review",
  proposal_revision: "Proposal revision",
  proposal_surgical_fix: "Proposal surgical fix",
  test: "Manual test",
};

const PROVIDER_LABELS = {
  anthropic: "Anthropic (Claude)",
  openai: "OpenAI",
};

const PROVIDER_COLOR = {
  anthropic: "bg-[#D97757]",
  openai: "bg-[#10A37F]",
};

const purposeRows = computed(() => {
  if (!data.value) return [];
  const max = Math.max(...data.value.by_purpose.map((r) => r.cost), 0.0001);
  return data.value.by_purpose.map((r) => ({
    ...r,
    label: PURPOSE_LABELS[r.purpose] ?? r.purpose,
    pct: Math.max(4, Math.round((r.cost / max) * 100)),
  }));
});

const providerRows = computed(() => {
  if (!data.value) return [];
  const max = Math.max(...data.value.by_provider.map((r) => r.cost), 0.0001);
  return data.value.by_provider.map((r) => ({
    ...r,
    label: PROVIDER_LABELS[r.provider] ?? r.provider,
    color: PROVIDER_COLOR[r.provider] ?? "bg-primary",
    pct: Math.max(4, Math.round((r.cost / max) * 100)),
  }));
});
</script>

<template>
  <Card>
    <CardHeader>
      <div>
        <CardTitle>AI usage &amp; cost</CardTitle>
        <CardDescription class="mt-1">
          Real spend, computed from every scoring and proposal call's actual token usage —
          nothing estimated.
        </CardDescription>
      </div>
      <Button variant="secondary" size="sm" :loading="refreshing" @click="load()">
        <RefreshCw class="h-3.5 w-3.5" /> Refresh
      </Button>
    </CardHeader>

    <CardContent v-if="loading" class="space-y-4">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <Skeleton v-for="i in 6" :key="i" class="h-20 w-full" />
      </div>
      <Skeleton class="h-56 w-full" />
    </CardContent>

    <CardContent v-else-if="data" class="space-y-6">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <StatCard label="Total spent" :value="money(data.total_spend)" :icon="Wallet" />
        <StatCard label="This month" :value="money(data.spend_this_month)" :icon="CalendarDays" />
        <StatCard label="This week" :value="money(data.spend_this_week)" :icon="Calendar" />
        <StatCard label="Today" :value="money(data.spend_today)" :icon="TrendingUp" />
        <StatCard
          label="Avg / scored lead"
          :value="money(data.avg_cost_per_scored_lead)"
          :icon="Target"
          :hint="`${data.total_calls.toLocaleString()} calls · ${data.success_rate ?? '—'}% success`"
        />
        <StatCard label="Avg / proposal run" :value="money(data.avg_cost_per_proposal)" :icon="FileText" />
      </div>

      <div>
        <p class="mb-2 text-sm font-semibold text-text-primary">Daily spend, last 30 days</p>
        <CostTrendChart :data="data.daily" />
      </div>

      <div class="grid gap-6 sm:grid-cols-2">
        <div>
          <p class="mb-3 text-sm font-semibold text-text-primary">By provider</p>
          <div class="space-y-3">
            <div v-for="row in providerRows" :key="row.provider">
              <div class="mb-1 flex items-center justify-between text-xs">
                <span class="font-medium text-text-secondary">{{ row.label }}</span>
                <span class="text-text-tertiary">{{ money(row.cost) }} · {{ row.calls.toLocaleString() }} calls</span>
              </div>
              <div class="h-2 overflow-hidden rounded-pill bg-neutral-bg">
                <div
                  :class="['h-full rounded-pill transition-all', row.color]"
                  :style="{ width: `${row.pct}%` }"
                />
              </div>
            </div>
            <p v-if="!providerRows.length" class="text-sm text-text-tertiary">No calls logged yet.</p>
          </div>
        </div>

        <div>
          <p class="mb-3 text-sm font-semibold text-text-primary">By purpose</p>
          <div class="space-y-3">
            <div v-for="row in purposeRows" :key="row.purpose">
              <div class="mb-1 flex items-center justify-between text-xs">
                <span class="font-medium text-text-secondary">{{ row.label }}</span>
                <span class="text-text-tertiary">{{ money(row.cost) }} · {{ row.calls.toLocaleString() }} calls</span>
              </div>
              <div class="h-2 overflow-hidden rounded-pill bg-neutral-bg">
                <div class="h-full rounded-pill bg-primary transition-all" :style="{ width: `${row.pct}%` }" />
              </div>
            </div>
            <p v-if="!purposeRows.length" class="text-sm text-text-tertiary">No calls logged yet.</p>
          </div>
        </div>
      </div>
    </CardContent>
  </Card>
</template>
