<script setup>
import { computed, onMounted } from "vue";
import { useRoute } from "vue-router";
import { Toaster } from "vue-sonner";
import NavBar from "@/components/layout/NavBar.vue";
import { useBrandingStore } from "@/stores/branding";

const route = useRoute();
const showNav = computed(() => route.name !== "login");

onMounted(() => useBrandingStore().fetch());
</script>

<template>
  <NavBar v-if="showNav" />
  <main class="flex-1">
    <router-view />
  </main>
  <Toaster
    position="top-right"
    :toast-options="{
      style: {
        borderRadius: '8px',
        border: '1px solid var(--color-border)',
        boxShadow: 'var(--shadow-popover)',
        fontSize: '14px',
      },
    }"
  />
</template>
