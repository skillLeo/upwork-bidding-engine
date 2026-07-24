<script setup>
import { computed } from "vue";
import { Activity, Award, Gauge, Send, Sparkles, TicketPercent, Zap } from "@lucide/vue";
import { useAnalytics } from "@/composables/useAnalytics";
import PageContainer from "@/components/layout/PageContainer.vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import StatCard from "@/components/ui/StatCard.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import EmptyState from "@/components/ui/EmptyState.vue";
import TrendChart from "@/components/analytics/TrendChart.vue";
import BestJobTypesChart from "@/components/analytics/BestJobTypesChart.vue";
import BestHoursChart from "@/components/analytics/BestHoursChart.vue";
import ScoreCalibrationChart from "@/components/analytics/ScoreCalibrationChart.vue";
import { relativeTime } from "@/lib/utils";

const { analytics, isLoading } = useAnalytics();

const summary = computed(() => analytics.value?.summary);
const trend = computed(() => analytics.value?.trend ?? []);
const bestJobTypes = computed(() => analytics.value?.best_job_types ?? []);
const bestHours = computed(() => analytics.value?.best_hours ?? []);
const recentActivity = computed(() => analytics.value?.recent_activity ?? []);

const calibrationRows = computed(() => analytics.value?.score_calibration?.rows ?? []);
const calibrationThreshold = computed(() => analytics.value?.score_calibration?.low_confidence_threshold ?? 5);

// Reply rate, corrected: a large share of Upwork jobs never hire anyone at
// all, and the plain (raw) rate counts every one of those as a personal
// loss. Contested excludes only CONFIRMED dead ends (closed_no_hire,
// expired, unknown) - a lead with no outcome recorded yet still counts,
// since we don't yet know it's a dead end.
const replyRateRaw = computed(() => summary.value?.reply_rate_raw ?? { n: 0, rate: null });
const replyRateContested = computed(() => summary.value?.reply_rate_contested ?? { n: 0, rate: null });

const speed = computed(() => analytics.value?.speed ?? { n: 0, median_minutes: null, p90_minutes: null });
// `recorded` is the denominator that matters. Leads where the client-side
// state was never filled in are counted in not_recorded and excluded from
// the buckets — treating them as "not opened" would make a partly-filled
// field read as a catastrophic title problem.
const dyingProposals = computed(
  () =>
    analytics.value?.dying_proposals ?? {
      not_opened: 0,
      opened_no_reply: 0,
      shortlisted_no_reply: 0,
      replied: 0,
      not_recorded: 0,
      recorded: 0,
      total_sent: 0,
    },
);

const dyingBuckets = computed(() => [
  {
    key: "not_opened",
    label: "Not opened",
    value: dyingProposals.value.not_opened,
    hint: "The title, opening line, or profile is losing them",
    tone: "border-border",
  },
  {
    key: "opened_no_reply",
    label: "Opened, no reply",
    value: dyingProposals.value.opened_no_reply,
    hint: "The letter body and the closing question are losing them",
    tone: "border-border",
  },
  {
    key: "shortlisted_no_reply",
    label: "Shortlisted, no reply",
    value: dyingProposals.value.shortlisted_no_reply,
    hint: "You were in the running — usually price, portfolio depth, or no follow-up",
    tone: "border-warning/30 bg-warning/5",
  },
  {
    key: "replied",
    label: "Replied or won",
    value: dyingProposals.value.replied,
    hint: "It landed",
    tone: "border-success/30 bg-success/5",
  },
]);

function formatMinutes(mins) {
  if (mins == null) return "—";
  if (mins < 60) return `${mins}m`;
  return `${(mins / 60).toFixed(1)}h`;
}

// Aggregates two score tiers (9-10 vs 7-8) into one plain-English sentence.
// This is a readability layer only - the chart itself stays per-score, per
// the spec: bucketing the chart would hide whether the rubric's granularity
// is doing real work.
const calibrationTakeaway = computed(() => {
  const rows = calibrationRows.value;
  const threshold = calibrationThreshold.value;

  const aggregate = (scores) => {
    const matched = rows.filter((r) => scores.includes(r.score));
    const sentCount = matched.reduce((sum, r) => sum + r.sent_count, 0);
    const replyCount = matched.reduce((sum, r) => sum + r.reply_count, 0);
    return { sentCount, rate: sentCount > 0 ? (replyCount / sentCount) * 100 : 0 };
  };

  const high = aggregate([9, 10]);
  const mid = aggregate([7, 8]);

  if (high.sentCount < threshold || mid.sentCount < threshold) {
    return "Not enough bids yet to compare tiers.";
  }

  if (high.rate === 0 && mid.rate === 0) {
    return "Scores 9–10 and 7–8 haven't landed a reply yet — too early to tell them apart.";
  }

  if (mid.rate === 0) {
    return `Scores 9–10 are converting at ${high.rate.toFixed(0)}% — scores 7–8 haven't landed a reply yet.`;
  }

  const multiplier = high.rate / mid.rate;
  if (Math.abs(multiplier - 1) < 0.15) {
    return "Scores 9–10 and 7–8 are converting at about the same rate.";
  }

  return multiplier > 1
    ? `Scores 9–10 are converting at ${multiplier.toFixed(1)}× the rate of 7–8.`
    : `Scores 7–8 are converting at ${(1 / multiplier).toFixed(1)}× the rate of 9–10.`;
});
</script>

<template>
  <PageContainer v-if="isLoading || !analytics" class="max-w-[1600px] space-y-4">
    <Skeleton class="h-7 w-40" />
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <Skeleton v-for="i in 6" :key="i" class="h-24 w-full" />
    </div>
    <Skeleton class="h-72 w-full" />
  </PageContainer>

  <PageContainer v-else class="max-w-[1600px] space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-text-primary">Analytics</h1>
      <p class="text-sm text-text-secondary">Last 14 days of pipeline activity.</p>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <StatCard label="Total leads" :value="summary.total_leads" :icon="Activity" />
      <StatCard label="Proposals sent" :value="summary.proposals_sent" :icon="Send" />
      <StatCard
        label="Reply rate (contested)"
        :value="replyRateContested.rate != null ? `${replyRateContested.rate}%` : 'Not enough data'"
        :icon="Sparkles"
        :trend="replyRateContested.rate != null && replyRateContested.rate >= 30 ? 'up' : 'neutral'"
        :hint="
          replyRateRaw.rate != null
            ? `Raw ${replyRateRaw.rate}% (n=${replyRateRaw.n}) counts every no-hire job as a loss — contested (n=${replyRateContested.n}) doesn't`
            : `n=${replyRateRaw.n} — need more sent leads`
        "
      />
      <StatCard
        label="Win rate"
        :value="`${summary.win_rate}%`"
        :icon="Award"
        :trend="summary.win_rate >= 15 ? 'up' : 'neutral'"
      />
      <StatCard label="Avg score" :value="summary.avg_score || '—'" :icon="Zap" />
      <StatCard
        label="Est. Connects spent"
        :value="summary.estimated_connects_spent"
        :icon="TicketPercent"
        hint="~4 per proposal, estimated"
      />
    </div>

    <!-- Speed: post-to-submit latency. The rubric treats freshness as the
         top win factor; this is the first time it's actually measured. -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <StatCard
        label="Median time to submit"
        :value="speed.median_minutes != null ? formatMinutes(speed.median_minutes) : 'Not enough data'"
        :icon="Gauge"
        :hint="`n=${speed.n} sent in the last 30 days`"
      />
      <StatCard
        label="P90 time to submit"
        :value="speed.p90_minutes != null ? formatMinutes(speed.p90_minutes) : 'Not enough data'"
        :icon="Gauge"
        hint="9 in 10 proposals go out faster than this"
      />
    </div>

    <Card>
      <CardHeader>
        <CardTitle>Where proposals are dying</CardTitle>
      </CardHeader>
      <CardContent>
        <EmptyState v-if="dyingProposals.total_sent === 0" title="No sent proposals yet" />
        <EmptyState
          v-else-if="dyingProposals.recorded === 0"
          title="Nothing recorded yet"
          :description="`${dyingProposals.not_recorded} sent ${dyingProposals.not_recorded === 1 ? 'proposal has' : 'proposals have'} no client-side state yet. Open a lead and set &quot;On Upwork, the client&quot; to start filling this in.`"
        />
        <template v-else>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="b in dyingBuckets" :key="b.key" :class="['rounded-md border p-4', b.tone]">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-tertiary">{{ b.label }}</p>
              <p class="mt-2 text-2xl font-semibold text-text-primary">{{ b.value }}</p>
              <p class="mt-1 text-xs text-text-tertiary">{{ b.hint }}</p>
            </div>
          </div>
          <p class="mt-3 text-xs text-text-tertiary">
            Based on {{ dyingProposals.recorded }} of {{ dyingProposals.total_sent }} sent
            {{ dyingProposals.total_sent === 1 ? "proposal" : "proposals" }}.
            <template v-if="dyingProposals.not_recorded > 0">
              {{ dyingProposals.not_recorded }} still
              {{ dyingProposals.not_recorded === 1 ? "has" : "have" }} no client-side state recorded and
              {{ dyingProposals.not_recorded === 1 ? "is" : "are" }} excluded — they are not counted as
              unopened. Upwork shows this on your proposals list; it can't be read automatically.
            </template>
          </p>
        </template>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Score calibration</CardTitle>
      </CardHeader>
      <CardContent>
        <p class="mb-3 text-sm text-text-secondary">{{ calibrationTakeaway }}</p>
        <ScoreCalibrationChart :rows="calibrationRows" :low-confidence-threshold="calibrationThreshold" />
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Pipeline trend</CardTitle>
      </CardHeader>
      <CardContent>
        <TrendChart :data="trend" />
      </CardContent>
    </Card>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle>Best job types</CardTitle>
        </CardHeader>
        <CardContent>
          <BestJobTypesChart :data="bestJobTypes" />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Best hours to catch new posts</CardTitle>
        </CardHeader>
        <CardContent>
          <BestHoursChart :data="bestHours" />
        </CardContent>
      </Card>
    </div>

    <Card>
      <CardHeader>
        <CardTitle>Recent activity</CardTitle>
      </CardHeader>
      <CardContent class="p-0">
        <EmptyState v-if="recentActivity.length === 0" title="No activity yet" />
        <ul v-else class="divide-y divide-border">
          <li
            v-for="entry in recentActivity"
            :key="entry.id"
            class="flex items-center justify-between gap-3 px-5 py-3"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-text-primary">
                {{ entry.type.replace(/_/g, " ") }}
              </p>
              <p v-if="entry.subject_type" class="text-xs text-text-tertiary">
                {{ entry.subject_type }} #{{ entry.subject_id }}
              </p>
            </div>
            <span class="shrink-0 text-xs text-text-tertiary">{{ relativeTime(entry.created_at) }}</span>
          </li>
        </ul>
      </CardContent>
    </Card>
  </PageContainer>
</template>
