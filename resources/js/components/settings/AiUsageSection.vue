<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from "vue";
import {
  Wallet,
  TrendingUp,
  Calendar,
  CalendarDays,
  Target,
  FileText,
  RefreshCw,
  AlertTriangle,
  Info,
  Zap,
} from "@lucide/vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import StatCard from "@/components/ui/StatCard.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import CostTrendChart from "@/components/settings/CostTrendChart.vue";
import { apiClient } from "@/lib/api-client";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

const POLL_INTERVAL_MS = 60_000;

const data = ref(null);
const loading = ref(true);
const refreshing = ref(false);
const providerFilter = ref("all");

const FILTERS = [
  { id: "all", label: "All providers" },
  { id: "anthropic", label: "Anthropic" },
  { id: "openai", label: "OpenAI" },
];

async function load({ silent = false } = {}) {
  if (!silent) refreshing.value = true;
  try {
    const params = providerFilter.value !== "all" ? { provider: providerFilter.value } : {};
    const res = await apiClient.get("/ai-usage", { params });
    data.value = res.data.data;
    syncFundedInputs();
  } catch {
    // Keep the last-known numbers on screen rather than blanking the panel.
  } finally {
    loading.value = false;
    refreshing.value = false;
  }
}

watch(providerFilter, () => load({ silent: true }));

let pollTimer;
onMounted(() => {
  load({ silent: true });
  pollTimer = setInterval(() => load({ silent: true }), POLL_INTERVAL_MS);
});
onUnmounted(() => clearInterval(pollTimer));

function money(value) {
  if (value === null || value === undefined) return "—";
  return `$${Number(value).toFixed(Math.abs(value) < 1 ? 4 : 2)}`;
}

// --- Balances: operator-entered funded amount minus real ledger spend ---
// Neither provider exposes live remaining balance to a normal API key
// (confirmed directly against both APIs), so this is the honest
// alternative: you tell us what you funded, we compute what's left from
// real, logged spend, every time this loads.
const fundedInputs = reactive({ anthropic: "", openai: "" });
const savingBalance = ref(null);

function syncFundedInputs() {
  if (!data.value) return;
  for (const b of data.value.balances) {
    fundedInputs[b.provider] = String(b.funded ?? 0);
  }
}

async function saveFunded(provider) {
  const value = Number(fundedInputs[provider]);
  if (Number.isNaN(value) || value < 0) {
    toast.error("Enter a valid dollar amount.");
    return;
  }
  savingBalance.value = provider;
  try {
    await saveSettings({ [`${provider}_funded_total`]: value });
    toast.success(`${provider === "anthropic" ? "Anthropic" : "OpenAI"} balance tracking updated.`);
    await load({ silent: true });
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save."));
  } finally {
    savingBalance.value = null;
  }
}

const PROVIDER_META = {
  anthropic: { label: "Anthropic (Claude)", dot: "bg-[#D97757]", bar: "bg-[#D97757]" },
  openai: { label: "OpenAI", dot: "bg-[#10A37F]", bar: "bg-[#10A37F]" },
};

function balanceHealth(b) {
  if (!b.funded) return "unset";
  if (b.pct_remaining === null) return "unset";
  if (b.pct_remaining <= 10) return "critical";
  if (b.pct_remaining <= 30) return "low";
  return "healthy";
}

const HEALTH_STYLES = {
  healthy: { bar: "", ring: "border-border", text: "text-success" },
  low: { bar: "bg-warning", ring: "border-warning-border", text: "text-warning" },
  critical: { bar: "bg-danger", ring: "border-danger-border", text: "text-danger" },
  unset: { bar: "bg-text-tertiary/40", ring: "border-border", text: "text-text-tertiary" },
};

// --- Breakdowns for the currently-selected provider filter ---
const PURPOSE_LABELS = {
  scoring: "Scoring",
  proposal: "Proposal draft",
  proposal_review: "Proposal review",
  proposal_revision: "Proposal revision",
  proposal_surgical_fix: "Proposal surgical fix",
  test: "Manual test",
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

function hitRateLabel(window) {
  const w = data.value?.cache_efficiency?.[window];
  if (!w || w.hit_rate_pct === null) return "—";
  return `${w.hit_rate_pct}%`;
}

function hitRateHint(window) {
  const w = data.value?.cache_efficiency?.[window];
  if (!w || w.total_input_tokens === 0) return "no calls in this window yet";
  return `${w.cached_tokens.toLocaleString()} of ${w.total_input_tokens.toLocaleString()} input tokens`;
}

const providerRows = computed(() => {
  if (!data.value) return [];
  const max = Math.max(...data.value.by_provider.map((r) => r.cost), 0.0001);
  return data.value.by_provider.map((r) => ({
    ...r,
    label: PROVIDER_META[r.provider]?.label ?? r.provider,
    color: PROVIDER_META[r.provider]?.bar ?? "bg-primary",
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
      <!-- Provider filter — scopes everything below except the balance
           cards and the always-both provider comparison. -->
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="f in FILTERS"
          :key="f.id"
          type="button"
          @click="providerFilter = f.id"
          :class="[
            'rounded-pill px-3 py-1.5 text-xs font-semibold transition-colors',
            providerFilter === f.id
              ? 'bg-primary text-white'
              : 'bg-neutral-bg text-text-secondary hover:bg-black/5',
          ]"
        >
          {{ f.label }}
        </button>
      </div>

      <!-- Balance & safety: the "can I keep bidding safely" answer. -->
      <div>
        <div class="mb-2 flex items-center gap-1.5">
          <p class="text-sm font-semibold text-text-primary">Balance &amp; safety</p>
        </div>
        <div class="mb-3 flex items-start gap-1.5 rounded-md bg-neutral-bg/70 px-3 py-2 text-xs text-text-tertiary">
          <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
          <span>
            Neither provider shares live balance with a normal API key. Enter what you've funded
            on each one's billing page below — "remaining" is that minus your real, logged spend.
          </span>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div
            v-for="b in data.balances"
            :key="b.provider"
            :class="['rounded-md border p-4', HEALTH_STYLES[balanceHealth(b)].ring]"
          >
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-1.5">
                <span :class="['h-2.5 w-2.5 rounded-full', PROVIDER_META[b.provider].dot]" />
                <p class="text-sm font-semibold text-text-primary">{{ PROVIDER_META[b.provider].label }}</p>
              </div>
              <AlertTriangle
                v-if="['low', 'critical'].includes(balanceHealth(b))"
                :class="['h-4 w-4', HEALTH_STYLES[balanceHealth(b)].text]"
              />
            </div>

            <div class="mt-2 flex items-baseline gap-2">
              <p :class="['text-2xl font-semibold', HEALTH_STYLES[balanceHealth(b)].text]">
                {{ b.funded ? money(b.remaining) : "—" }}
              </p>
              <p class="text-xs text-text-tertiary">
                {{ b.funded ? `remaining of ${money(b.funded)}` : "no funded amount set" }}
              </p>
            </div>

            <div v-if="b.funded" class="mt-2 h-2 overflow-hidden rounded-pill bg-neutral-bg">
              <div
                :class="['h-full rounded-pill transition-all', HEALTH_STYLES[balanceHealth(b)].bar || PROVIDER_META[b.provider].bar]"
                :style="{ width: `${b.pct_remaining}%` }"
              />
            </div>

            <p v-if="b.funded" class="mt-1.5 text-xs text-text-tertiary">
              spent {{ money(b.spent) }} ({{ (100 - b.pct_remaining).toFixed(1) }}%) · burning
              {{ money(b.burn_rate_per_day) }}/day ·
              <span :class="b.days_remaining !== null && b.days_remaining < 14 ? 'font-semibold text-warning' : ''">
                {{ b.days_remaining !== null ? `~${b.days_remaining} days left at this pace` : "pace unknown yet" }}
              </span>
            </p>

            <div class="mt-3 flex items-end gap-2">
              <div class="flex-1">
                <Label class="text-xs">Amount funded ($)</Label>
                <Input
                  v-model="fundedInputs[b.provider]"
                  type="number"
                  min="0"
                  step="0.01"
                  placeholder="e.g. 20"
                />
              </div>
              <Button
                size="sm"
                variant="secondary"
                :loading="savingBalance === b.provider"
                @click="saveFunded(b.provider)"
              >
                Update
              </Button>
            </div>
          </div>
        </div>
      </div>

      <!-- Cache efficiency: proof the prompt-caching in AnthropicProvider /
           OpenAiProvider is actually engaging, in real measured numbers
           rather than the code comment's word for it. -->
      <div>
        <div class="mb-2 flex items-center gap-1.5">
          <p class="text-sm font-semibold text-text-primary">Prompt cache efficiency</p>
        </div>
        <div class="mb-3 flex items-start gap-1.5 rounded-md bg-neutral-bg/70 px-3 py-2 text-xs text-text-tertiary">
          <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
          <span>
            Your rubric and writing rules are sent as a cached block on every call — repeat reads
            of that block bill at a fraction of normal input price. This is the share of input
            tokens actually served from that cache.
          </span>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <StatCard label="Last 24h" :value="hitRateLabel('last_24h')" :icon="Zap" :hint="hitRateHint('last_24h')" />
          <StatCard label="Last 30 days" :value="hitRateLabel('last_30d')" :icon="Zap" :hint="hitRateHint('last_30d')" />
        </div>
      </div>

      <!-- Spend stats, scoped to the provider filter above. -->
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
        <p class="mb-2 text-sm font-semibold text-text-primary">
          Daily spend, last 30 days
          <span v-if="providerFilter !== 'all'" class="font-normal text-text-tertiary">
            — {{ FILTERS.find((f) => f.id === providerFilter)?.label }} only
          </span>
        </p>
        <CostTrendChart :data="data.daily" />
      </div>

      <div class="grid gap-6 sm:grid-cols-2">
        <div>
          <p class="mb-1 text-sm font-semibold text-text-primary">By provider</p>
          <p class="mb-3 text-xs text-text-tertiary">Always both, regardless of the filter above.</p>
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
          <p class="mb-1 text-sm font-semibold text-text-primary">By purpose</p>
          <p class="mb-3 text-xs text-text-tertiary">
            {{ providerFilter === "all" ? "All providers." : FILTERS.find((f) => f.id === providerFilter)?.label + " only." }}
          </p>
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

      <div class="flex items-start gap-1.5 rounded-md bg-neutral-bg/70 px-3 py-2 text-xs text-text-tertiary">
        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <span>
          Client-reply drafts ("Draft reply" in Client Memory) run through OpenClaw's own Claude
          subscription, not these API keys — that spend isn't included in any number on this page.
        </span>
      </div>
    </CardContent>
  </Card>
</template>
