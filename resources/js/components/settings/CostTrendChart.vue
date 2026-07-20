<script setup>
import { computed } from "vue";
import { Line } from "vue-chartjs";
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Filler,
  Tooltip,
} from "chart.js";
import { format } from "date-fns";
import { CHART_CHROME, CHART_COLORS } from "@/lib/chart-colors";

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip);

const props = defineProps({ data: { type: Array, required: true } });

const chartData = computed(() => ({
  labels: props.data.map((d) => format(new Date(`${d.date}T00:00:00`), "MMM d")),
  datasets: [
    {
      label: "Daily spend",
      data: props.data.map((d) => d.cost),
      borderColor: CHART_COLORS.cost,
      backgroundColor: `${CHART_COLORS.cost}22`,
      fill: true,
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: CHART_COLORS.cost,
      tension: 0.35,
    },
  ],
}));

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: "index", intersect: false },
  scales: {
    x: {
      grid: { display: false },
      ticks: { font: { size: 11 }, color: CHART_CHROME.axis, maxTicksLimit: 8 },
      border: { color: CHART_CHROME.grid },
    },
    y: {
      beginAtZero: true,
      ticks: {
        font: { size: 11 },
        color: CHART_CHROME.axis,
        callback: (value) => `$${value}`,
      },
      grid: { color: CHART_CHROME.grid },
      border: { display: false },
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
      titleFont: { size: 12, weight: 600 },
      bodyFont: { size: 12 },
      callbacks: {
        label: (ctx) => `$${ctx.parsed.y.toFixed(4)}`,
      },
    },
  },
};
</script>

<template>
  <div style="height: 220px">
    <Line :data="chartData" :options="chartOptions" />
  </div>
</template>
