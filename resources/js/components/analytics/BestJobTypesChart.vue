<script setup>
import { computed } from "vue";
import { Bar } from "vue-chartjs";
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip } from "chart.js";
import { BarChart3 } from "@lucide/vue";
import { CHART_CHROME } from "@/lib/chart-colors";
import EmptyState from "@/components/ui/EmptyState.vue";

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip);

const props = defineProps({ data: { type: Array, required: true } });

const chartData = computed(() => ({
  labels: props.data.map((d) => d.keyword),
  datasets: [
    {
      data: props.data.map((d) => d.count),
      backgroundColor: "#0A66C2",
      borderRadius: 4,
      barThickness: 16,
    },
  ],
}));

const chartOptions = {
  indexAxis: "y",
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    x: {
      beginAtZero: true,
      ticks: { precision: 0, font: { size: 11 }, color: CHART_CHROME.axis },
      grid: { color: CHART_CHROME.grid },
    },
    y: {
      ticks: { font: { size: 12 }, color: CHART_CHROME.textPrimary },
      grid: { display: false },
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
      callbacks: {
        label: (ctx) => `${ctx.parsed.x} lead${ctx.parsed.x === 1 ? "" : "s"} matching brief`,
      },
    },
  },
};

const height = computed(() => Math.max(180, props.data.length * 38));
</script>

<template>
  <EmptyState v-if="data.length === 0" :icon="BarChart3" title="Not enough scored leads yet" />
  <div v-else :style="{ height: `${height}px` }">
    <Bar :data="chartData" :options="chartOptions" />
  </div>
</template>
