<script setup>
import { computed, onMounted } from "vue";
import { useRoute } from "vue-router";
import { Toaster } from "vue-sonner";
import NavBar from "@/components/layout/NavBar.vue";
import AiProgressToast from "@/components/ui/AiProgressToast.vue";
import { useBrandingStore } from "@/stores/branding";
import { initLeadAlerts } from "@/stores/leadAlerts";

const route = useRoute();
const showNav = computed(() => route.name !== "login");

onMounted(() => {
  useBrandingStore().fetch();
  initLeadAlerts();
});
</script>

<template>
  <NavBar v-if="showNav" />
  <main class="flex-1">
    <router-view />
  </main>
  <AiProgressToast />
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
