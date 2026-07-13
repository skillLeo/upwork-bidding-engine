<script setup>
import Skeleton from "@/components/ui/Skeleton.vue";
</script>

<template>
  <div class="rounded-card bg-surface p-5 shadow-card">
    <div class="flex items-start justify-between gap-4">
      <div class="flex-1 space-y-2">
        <Skeleton class="h-4 w-2/3" />
        <Skeleton class="h-3 w-1/3" />
      </div>
      <Skeleton class="h-6 w-14 rounded-pill" />
    </div>
    <div class="mt-4 flex gap-2">
      <Skeleton class="h-5 w-20 rounded-pill" />
      <Skeleton class="h-5 w-24 rounded-pill" />
      <Skeleton class="h-5 w-16 rounded-pill" />
    </div>
  </div>
</template>
