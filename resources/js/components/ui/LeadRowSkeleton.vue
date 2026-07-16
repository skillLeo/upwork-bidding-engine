<script setup>
import Skeleton from "@/components/ui/Skeleton.vue";
</script>

<template>
  <div class="leads-row-grid min-h-9 items-center border-b border-border px-3">
    <span class="h-full bg-transparent" />
    <Skeleton class="h-3.5 w-5" />
    <Skeleton class="h-3.5 w-2/3" />
    <Skeleton class="ml-auto h-3.5 w-12" />
    <Skeleton class="ml-auto h-3.5 w-6" />
    <Skeleton class="ml-auto h-3.5 w-5" />
    <Skeleton class="h-3.5 w-8" />
    <Skeleton class="h-3.5 w-16" />
    <Skeleton class="h-4 w-14 rounded-pill" />
  </div>
</template>
