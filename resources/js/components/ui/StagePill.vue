<script setup>
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";

const props = defineProps({ stage: { type: String, required: true } });

const stageLabels = {
  new: "New",
  talking: "Talking",
  negotiating: "Negotiating",
  closing: "Closing",
  won: "Won",
  lost: "Lost",
};

const stageTones = {
  new: "neutral",
  talking: "info",
  negotiating: "warning",
  closing: "warning",
  won: "success",
  lost: "danger",
};

const label = computed(() => stageLabels[props.stage] ?? props.stage);
const tone = computed(() => stageTones[props.stage] ?? "neutral");
</script>

<template>
  <Badge :tone="tone">{{ label }}</Badge>
</template>
