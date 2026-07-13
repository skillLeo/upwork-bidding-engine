<script setup>
import { computed } from "vue";
import { Line } from "vue-chartjs";
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Legend,
} from "chart.js";
import { format } from "date-fns";
import { CHART_CHROME, CHART_COLORS } from "@/lib/chart-colors";

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const props = defineProps({ data: { type: Array, required: true } });

const chartData = computed(() => {
  const labels = props.data.map((d) => format(new Date(`${d.date}T00:00:00`), "MMM d"));
  const series = [
    { key: "received", name: "Received", color: CHART_COLORS.received },
    { key: "sent", name: "Sent", color: CHART_COLORS.sent },
    { key: "replied", name: "Replied", color: CHART_COLORS.replied },
    { key: "won", name: "Won", color: CHART_COLORS.won },
  ];
  return {
    labels,
    datasets: series.map((s) => ({
      label: s.name,
      data: props.data.map((d) => d[s.key]),
      borderColor: s.color,
      backgroundColor: s.color,
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 4,
      tension: 0.35,
    })),
  };
});

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: "index", intersect: false },
  scales: {
    x: {
      grid: { display: false },
      ticks: { font: { size: 11 }, color: CHART_CHROME.axis },
      border: { color: CHART_CHROME.grid },
    },
    y: {
      beginAtZero: true,
      ticks: { precision: 0, font: { size: 11 }, color: CHART_CHROME.axis },
      grid: { color: CHART_CHROME.grid },
      border: { display: false },
    },
  },
  plugins: {
    legend: {
      position: "bottom",
      labels: { boxWidth: 14, font: { size: 12 }, color: CHART_CHROME.textSecondary },
    },
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
    },
  },
};
</script>

<template>
  <div style="height: 280px">
    <Line :data="chartData" :options="chartOptions" />
  </div>
</template>
