<script setup>
import { computed } from "vue";
import { Bar } from "vue-chartjs";
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip } from "chart.js";
import { BarChart3 } from "@lucide/vue";
import { CHART_CHROME, CHART_COLORS } from "@/lib/chart-colors";
import EmptyState from "@/components/ui/EmptyState.vue";

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip);

const props = defineProps({
  rows: { type: Array, required: true },
  lowConfidenceThreshold: { type: Number, required: true },
});

// Same hue as every other "sent"-toned bar on this page (BestJobTypesChart,
// TrendChart) — low-confidence bars are the identical color at lower opacity,
// not a second color, so identity isn't confused with a different series.
const BAR_COLOR = CHART_COLORS.sent;
const BAR_COLOR_MUTED = "rgba(10, 102, 194, 0.3)";

function isLowConfidence(row) {
  return row.sent_count < props.lowConfidenceThreshold;
}

const chartData = computed(() => ({
  labels: props.rows.map((r) => [`Score ${r.score}`, `n=${r.sent_count}`]),
  datasets: [
    {
      data: props.rows.map((r) => r.reply_rate),
      backgroundColor: props.rows.map((r) => (isLowConfidence(r) ? BAR_COLOR_MUTED : BAR_COLOR)),
      borderRadius: 4,
      maxBarThickness: 40,
    },
  ],
}));

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    x: {
      grid: { display: false },
      ticks: { font: { size: 11 }, color: CHART_CHROME.axis },
      border: { color: CHART_CHROME.grid },
    },
    y: {
      beginAtZero: true,
      max: 100,
      ticks: {
        precision: 0,
        font: { size: 11 },
        color: CHART_CHROME.axis,
        callback: (v) => `${v}%`,
      },
      grid: { color: CHART_CHROME.grid },
    },
  },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: "#fff",
      titleColor: CHART_CHROME.textPrimary,
      bodyColor: CHART_CHROME.textPrimary,
      borderColor: CHART_CHROME.border,
      borderWidth: 1,
      cornerRadius: 8,
      padding: 10,
      callbacks: {
        label: (ctx) => {
          const row = props.rows[ctx.dataIndex];
          const base = `${row.reply_rate}% reply rate (n=${row.sent_count})`;
          return isLowConfidence(row) ? `${base} — not enough data yet` : base;
        },
        afterLabel: (ctx) => {
          const row = props.rows[ctx.dataIndex];
          return `${row.win_count} won of ${row.sent_count} sent · ${row.win_rate}% win rate`;
        },
      },
    },
  },
}));
</script>

<template>
  <EmptyState v-if="rows.length === 0" :icon="BarChart3" title="Not enough sent leads yet to calibrate scores" />
  <div v-else style="height: 240px">
    <Bar :data="chartData" :options="chartOptions" />
  </div>
</template>
