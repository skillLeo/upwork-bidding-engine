<script setup>
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";

const props = defineProps({ status: { type: String, required: true } });

const statusLabels = {
  new: "New",
  scoring: "Scoring",
  ready: "Ready",
  sent: "Sent",
  replied: "Replied",
  won: "Won",
  archived: "Archived",
};

const statusTones = {
  new: "neutral",
  scoring: "warning",
  ready: "info",
  sent: "info",
  replied: "warning",
  won: "success",
  archived: "neutral",
};

const label = computed(() => statusLabels[props.status] ?? props.status);
const tone = computed(() => statusTones[props.status] ?? "neutral");
</script>

<template>
  <Badge :tone="tone">{{ label }}</Badge>
</template>
