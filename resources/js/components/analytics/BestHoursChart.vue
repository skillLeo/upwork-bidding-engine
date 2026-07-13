<script setup>
import { computed } from "vue";
import { Bar } from "vue-chartjs";
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip } from "chart.js";
import { CHART_CHROME } from "@/lib/chart-colors";

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip);

const props = defineProps({ data: { type: Array, required: true } });

function formatHour(hour) {
  const period = hour < 12 ? "am" : "pm";
  const displayHour = hour % 12 === 0 ? 12 : hour % 12;
  return `${displayHour}${period}`;
}

const chartData = computed(() => ({
  labels: props.data.map((d) => formatHour(d.hour)),
  datasets: [
    {
      data: props.data.map((d) => d.count),
      backgroundColor: "#0A66C2",
      borderRadius: 4,
      maxBarThickness: 18,
    },
  ],
}));

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    x: {
      grid: { display: false },
      ticks: { font: { size: 10 }, color: CHART_CHROME.axis, autoSkip: true, maxTicksLimit: 12 },
      border: { color: CHART_CHROME.grid },
    },
    y: {
      beginAtZero: true,
      ticks: { precision: 0, font: { size: 11 }, color: CHART_CHROME.axis },
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
      callbacks: {
        label: (ctx) => `${ctx.parsed.y} job${ctx.parsed.y === 1 ? "" : "s"} posted`,
      },
    },
  },
};
</script>

<template>
  <div style="height: 220px">
    <Bar :data="chartData" :options="chartOptions" />
  </div>
</template>
